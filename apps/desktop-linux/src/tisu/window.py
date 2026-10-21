"""Main window: login screen, or a sidebar of role-aware sections next to their navigation stacks."""

from gi.repository import Adw, Gio, GLib, Gtk

from . import const, fmt, secret_store
from .files import clear_cache
from .api import ApiError, external_allowed
from .pages.certificates import CertificatesPage
from .pages.courses import CoursesPage
from .pages.dashboard import DashboardPage
from .pages.groups import GroupsPage
from .pages.leads import LeadsPage
from .pages.login import LoginView
from .pages.news import NewsDetailPage, NewsPage
from .pages.profile import ProfilePage
from .pages.audit import AuditPage
from .pages.billing import BillingPage
from .pages.calendar import CalendarPage
from .pages.homework import HomeworkPage
from .pages.notifications import NotificationsPage
from .pages.reports import ReportsPage
from .pages.settings_admin import SettingsPage
from .pages.users import UsersPage
from .widgets import pill, spinner


class Section:
    def __init__(self, key, path, default_label, icon, factory=None, perm=None, any_perm=None, own=False, admin=False, setting=None):
        self.key = key
        self.path = path
        self.default_label = default_label
        self.icon = icon
        self.factory = factory  # callable(win) -> Page
        self.perm = perm
        self.any_perm = any_perm
        self.own = own  # also visible to students (their own data)
        self.admin = admin  # listed under the "Boshqaruv" header
        self.setting = setting  # public boolean setting that switches the section off


SECTIONS = [
    Section("dashboard", "/dashboard", "Bosh sahifa", "go-home-symbolic", DashboardPage),
    Section("calendar", "/dashboard/calendar", "Taqvim", "x-office-calendar-symbolic", CalendarPage),
    Section("homework", "/dashboard/homework", "Uyga vazifa", "tisu-quiz-symbolic", HomeworkPage, setting="homework.enabled"),
    Section("courses", "/dashboard/courses", "Kurslar", "tisu-book-symbolic", CoursesPage, perm="course.view"),
    Section("groups", "/dashboard/groups", "Guruhlar", "tisu-people-symbolic", GroupsPage),
    Section("news", "/dashboard/news", "Yangiliklar", "tisu-bell-symbolic", NewsPage),
    Section("certificates", "/dashboard/certificates", "Sertifikatlar", "tisu-award-symbolic", CertificatesPage),
    Section("reports", "/dashboard/reports", "Hisobotlar", "tisu-chart-symbolic", ReportsPage, perm="report.view"),
    # Managers see all balances; students see their own (when billing is enabled).
    Section("billing", "/dashboard/billing", "To'lovlar", "tisu-wallet-symbolic", BillingPage, perm="billing.manage",
            any_perm=None, own=True),
    Section("leads", "/dashboard/leads", "Arizalar", "send-to-symbolic", LeadsPage, perm="lead.manage"),
    Section("notifications", "/dashboard/notifications", "Bildirishnomalar", "mail-unread-symbolic", NotificationsPage,
            setting="notifications.inbox_enabled"),
    Section("profile", "/dashboard/profile", "Profil", "avatar-default-symbolic", ProfilePage),
    Section("users", "/dashboard/users", "Foydalanuvchilar", "tisu-people-symbolic", UsersPage, perm="user.manage", admin=True),
    Section("audit", "/dashboard/audit", "Audit jurnali", "document-open-recent-symbolic", AuditPage, perm="audit.view", admin=True),
    Section("settings", "/dashboard/settings", "Sozlamalar", "preferences-system-symbolic", SettingsPage,
            perm="settings.manage", admin=True),
]


class TisuWindow(Adw.ApplicationWindow):
    def __init__(self, app):
        super().__init__(application=app, title=const.APP_NAME, icon_name=const.APP_ID)
        self.app = app
        self.api = app.api
        self.public = app.public
        self.prefs = app.prefs
        self.bus = app.bus
        self.user = None
        self._nav_views = {}
        self._rows = {}
        self._badges = {}
        self._current = None

        self.set_size_request(360, 480)
        self.set_default_size(self.prefs.get_int("window-width"), self.prefs.get_int("window-height"))
        if self.prefs.get_boolean("window-maximized"):
            self.maximize()
        self.connect("close-request", self._on_close_request)

        self.toasts = Adw.ToastOverlay()
        self.root = Gtk.Stack(transition_type=Gtk.StackTransitionType.CROSSFADE)
        boot = Adw.ToolbarView()
        hb = Adw.HeaderBar()
        hb.add_css_class("flat")
        boot.add_top_bar(hb)
        boot.set_content(spinner(48))
        self.root.add_named(boot, "boot")
        self.login = LoginView(self, self._on_logged_in)
        self.root.add_named(self.login, "login")
        self.root.add_named(self._build_main(), "main")

        outer = Gtk.Box(orientation=Gtk.Orientation.VERTICAL)
        self.offline_banner = Adw.Banner(title="Internet aloqasi yo'q — ma'lumotlar yangilanmaydi")
        outer.append(self.offline_banner)
        self.root.set_vexpand(True)
        outer.append(self.root)
        self.toasts.set_child(outer)
        self.set_content(self.toasts)

        bp = Adw.Breakpoint.new(Adw.BreakpointCondition.parse("max-width: 640sp"))
        bp.add_setter(self.split, "collapsed", True)
        self.add_breakpoint(bp)

        monitor = Gio.NetworkMonitor.get_default()
        monitor.connect("notify::network-available", self._on_network)
        self._on_network(monitor)

        self.bus.connect("unread-changed", lambda _b, n: self._set_badge("news", n))
        self.bus.connect("inbox-changed", lambda _b, n: self._set_badge("notifications", n))
        self.public.connect_changed(self._apply_labels)

    # ── Layout ───────────────────────────────────────────────
    def _build_main(self):
        self.split = Adw.NavigationSplitView(min_sidebar_width=220, max_sidebar_width=280)

        sidebar_tb = Adw.ToolbarView()
        sb_header = Adw.HeaderBar()
        self.sidebar_title = Adw.WindowTitle(title=const.APP_NAME)
        sb_header.set_title_widget(self.sidebar_title)
        sb_header.pack_end(self.make_primary_menu_button(logged_in=True))
        search = Gtk.Button(icon_name="edit-find-symbolic", tooltip_text="Qidirish (Ctrl+K)")
        search.connect("clicked", lambda *_: self.open_search())
        sb_header.pack_start(search)
        sidebar_tb.add_top_bar(sb_header)
        self.listbox = Gtk.ListBox(selection_mode=Gtk.SelectionMode.SINGLE)
        self.listbox.add_css_class("navigation-sidebar")
        self.listbox.connect("row-activated", self._on_row_activated)
        sidebar_tb.set_content(Gtk.ScrolledWindow(child=self.listbox, hscrollbar_policy=Gtk.PolicyType.NEVER))
        self.split.set_sidebar(Adw.NavigationPage(title=const.APP_NAME, child=sidebar_tb, tag="sidebar"))

        self.content_stack = Gtk.Stack(transition_type=Gtk.StackTransitionType.CROSSFADE)
        self.content_page = Adw.NavigationPage(title=const.APP_NAME, child=self.content_stack, tag="content")
        self.split.set_content(self.content_page)
        return self.split

    def make_primary_menu_button(self, logged_in):
        menu = Gio.Menu()
        if logged_in:
            s1 = Gio.Menu()
            s1.append("Yangilash", "win.refresh")
            s1.append("Fonda ishlash (bildirishnomalar)", "app.run-in-background")
            menu.append_section(None, s1)
        s2 = Gio.Menu()
        s2.append("Klaviatura yorliqlari", "app.shortcuts")
        s2.append(f"{const.APP_NAME} haqida", "app.about")
        menu.append_section(None, s2)
        if logged_in:
            s3 = Gio.Menu()
            s3.append("Chiqish", "win.logout")
            menu.append_section(None, s3)
        b = Gtk.MenuButton(icon_name="open-menu-symbolic", menu_model=menu, tooltip_text="Asosiy menyu", primary=True)
        return b

    def _visible_sections(self):
        def visible(s):
            if s.setting and self.public.get(s.setting, True) is False:
                return False
            if s.own and self.role == "STUDENT":
                return self.public.get("billing.enabled", True) is not False
            return self.has(s.perm)

        return [s for s in SECTIONS if visible(s)]

    def _build_sidebar(self):
        while (child := self.listbox.get_first_child()) is not None:
            self.listbox.remove(child)
        for name in list(self._nav_views):
            self.content_stack.remove(self._nav_views.pop(name))
        self._rows.clear()
        self._badges.clear()

        admin_header_added = False
        for sec in self._visible_sections():
            if sec.admin and not admin_header_added:
                admin_header_added = True
                hdr = Gtk.Label(label="Boshqaruv", xalign=0, margin_start=12, margin_top=12, margin_bottom=4)
                hdr.add_css_class("caption-heading")
                hdr.add_css_class("dim-label")
                r = Gtk.ListBoxRow(activatable=False, selectable=False, child=hdr)
                self.listbox.append(r)
            box = Gtk.Box(spacing=12, margin_top=6, margin_bottom=6, margin_start=6, margin_end=6)
            box.append(Gtk.Image.new_from_icon_name(sec.icon))
            lbl = Gtk.Label(label=self.public.nav_label(sec.path, sec.default_label), xalign=0, hexpand=True)
            lbl.set_ellipsize(3)
            box.append(lbl)
            badge = pill("", "accent")
            badge.add_css_class("tisu-badge")
            badge.set_visible(False)
            box.append(badge)
            row = Gtk.ListBoxRow(child=box)
            row.section = sec
            row.label = lbl
            self.listbox.append(row)
            self._rows[sec.key] = row
            self._badges[sec.key] = badge

    def _apply_labels(self):
        name = self.public.site_name
        self.sidebar_title.set_title(name)
        self.set_title(name)
        for key, row in self._rows.items():
            row.label.set_text(self.public.nav_label(row.section.path, row.section.default_label))
        if self._current:
            sec = self._rows[self._current].section
            self.content_page.set_title(self.public.nav_label(sec.path, sec.default_label))

    def _set_badge(self, key, n):
        badge = self._badges.get(key)
        if badge is not None:
            badge.set_text(str(n) if n < 100 else "99+")
            badge.set_visible(n > 0)

    def _on_row_activated(self, _lb, row):
        sec = getattr(row, "section", None)
        if sec is None:
            return
        self.show_section(sec.key)

    def show_section(self, key, reset=False):
        row = self._rows.get(key)
        if row is None:
            return False
        sec = row.section
        nav = self._nav_views.get(key)
        if nav is None:
            nav = Adw.NavigationView()
            root = sec.factory(self)
            root.set_tag("root")
            nav.add(root)
            self._nav_views[key] = nav
            self.content_stack.add_named(nav, key)
        elif reset:
            nav.pop_to_tag("root")
        self._current = key
        self.content_stack.set_visible_child_name(key)
        self.content_page.set_title(self.public.nav_label(sec.path, sec.default_label))
        self.listbox.select_row(row)
        self.split.set_show_content(True)
        return True

    def current_page(self):
        nav = self._nav_views.get(self._current)
        return nav.get_visible_page() if nav else None

    def section_index(self, i):
        secs = self._visible_sections()
        if 0 <= i < len(secs):
            self.show_section(secs[i].key)

    # ── Session ──────────────────────────────────────────────
    def start(self):
        """Restore the token from the keyring, or show the login screen."""

        def found(token, err):
            if err:
                self.toast("Kalitlar xizmati (Secret Service) mavjud emas — har safar qayta kirishingiz kerak bo'ladi.")
            if token:
                self.api.token = token
                self._load_me(initial=True)
            else:
                self.show_login()

        secret_store.lookup(self.api.base_url, found)

    def _load_me(self, initial=False):
        def done(data, err):
            if err:
                if err.kind == ApiError.UNAUTHORIZED:
                    return  # handled by on_unauthorized → login
                if err.kind == ApiError.NETWORK and initial:
                    self._show_offline_boot(err.message)
                    return
                self.toast(err.message)
                return
            self.set_user(data["user"])

        self.api.get("auth/me", done)

    def _show_offline_boot(self, message):
        page = Adw.StatusPage(icon_name="network-offline-symbolic", title="Aloqa yo'q", description=message)
        b = Gtk.Button(label="Qayta urinish", halign=Gtk.Align.CENTER)
        b.add_css_class("pill")
        b.add_css_class("suggested-action")
        b.connect("clicked", lambda *_: (self.root.set_visible_child_name("boot"), self._load_me(initial=True)))
        page.set_child(b)
        tb = Adw.ToolbarView()
        tb.add_top_bar(Adw.HeaderBar())
        tb.set_content(page)
        old = self.root.get_child_by_name("offline")
        if old:
            self.root.remove(old)
        self.root.add_named(tb, "offline")
        self.root.set_visible_child_name("offline")

    def _on_logged_in(self, token, user):
        self.api.token = token

        def stored(err):
            if err:
                self.toast("Token kalitlar xizmatida saqlanmadi — keyingi safar qayta kiring.")

        secret_store.store(self.api.base_url, token, stored)
        self._load_me()  # /auth/me adds telegram link + has_password
        if user:
            self.set_user(user)

    def set_user(self, user):
        first = self.user is None or self.user.get("id") != user.get("id")
        self.user = user
        if first:
            self._build_sidebar()
            self._apply_labels()
            self.root.set_visible_child_name("main")
            self.show_section("dashboard")
            self.app.start_polling()
        self.sidebar_title.set_subtitle(f"{fmt.name(user)} · {self.public.role_label(user.get('role'))}")
        self.bus.emit("user-changed")

    def has(self, perm):
        if perm is None:
            return True
        return bool(self.user) and perm in (self.user.get("permissions") or [])

    @property
    def role(self):
        return (self.user or {}).get("role")

    def show_login(self, message=None):
        self.user = None
        self.app.stop_polling()
        self.login.reset()
        self.root.set_visible_child_name("login")
        if message:
            self.toast(message)

    def logout(self, server=True):
        token = self.api.token
        if server and token:
            self.api.post("auth/logout", {}, lambda *_: None)
        self.api.token = None
        secret_store.clear(self.api.base_url)
        clear_cache()
        self._build_sidebar_empty()
        self.show_login()

    def session_expired(self):
        if self.api.token is None:
            return
        self.api.token = None
        secret_store.clear(self.api.base_url)
        clear_cache()
        self._build_sidebar_empty()
        self.show_login("Sessiya muddati tugadi. Qaytadan kiring.")

    def _build_sidebar_empty(self):
        self.user = None
        self._current = None
        while (child := self.listbox.get_first_child()) is not None:
            self.listbox.remove(child)
        for name in list(self._nav_views):
            self.content_stack.remove(self._nav_views.pop(name))

    # ── Helpers used by pages ────────────────────────────────
    def toast(self, text, timeout=3):
        t = Adw.Toast(title=GLib.markup_escape_text(text), timeout=timeout)
        self.toasts.add_toast(t)

    def open_uri(self, uri):
        """Only for links that must leave the app (Telegram, phone, mail). Content opens in-app."""
        self.open_external(uri)

    def open_external(self, uri):
        if not uri:
            return
        if not external_allowed(uri):
            self.toast("Bu havolani ochib bo'lmaydi.")
            return
        Gtk.UriLauncher.new(uri).launch(self, None, self._launched, None)

    def _launched(self, launcher, result, _data):
        try:
            launcher.launch_finish(result)
        except GLib.Error as e:
            if not e.matches(Gtk.dialog_error_quark(), Gtk.DialogError.DISMISSED):
                self.toast(f"Brauzerni ochib bo'lmadi: {e.message}")

    def open_announcement(self, ann_id):
        """From a desktop notification."""
        self.present()
        if self.user and self.show_section("news"):
            nav = self._nav_views["news"]
            nav.push(NewsDetailPage(self, {"id": ann_id}))

    # ── Navigation by web-style paths (setup checklist, system status) ───
    def navigate(self, href):
        from urllib.parse import parse_qs, urlparse

        u = urlparse(href or "")
        q = {k: v[0] for k, v in parse_qs(u.query).items()}
        parts = u.path.rstrip("/").split("/")
        if len(parts) == 4 and parts[2] in ("homework", "sessions", "groups", "users", "courses", "lessons") and parts[3].isdigit():
            ident = int(parts[3])
            sect = {"homework": "homework", "sessions": "calendar", "groups": "groups", "users": "users", "courses": "courses",
                    "lessons": "courses"}[parts[2]]
            if not self.show_section(sect, reset=True) and not self.show_section("dashboard", reset=True):
                return
            root = self.current_page()
            from .pages.courses import CoursePage, LessonPage
            from .pages.groups import GroupPage, SessionSheetPage
            from .pages.homework import AssignmentPage
            from .pages.users import UserPage

            page = {"homework": lambda: AssignmentPage(self, ident), "sessions": lambda: SessionSheetPage(self, ident, "Dars"),
                    "groups": lambda: GroupPage(self, ident, "Guruh"), "users": lambda: UserPage(self, {"id": ident, "first_name": ""}),
                    "courses": lambda: CoursePage(self, ident, "Kurs"), "lessons": lambda: LessonPage(self, ident, "Dars")}[parts[2]]()
            root.get_ancestor(Adw.NavigationView).push(page)
            return
        if u.path.startswith("/c/"):
            from .pages.certificates import CertificateDetailPage

            self.show_section("certificates", reset=True)
            self.current_page().get_ancestor(Adw.NavigationView).push(CertificateDetailPage(self, u.path[3:]))
            return
        key = next((s.key for s in SECTIONS if s.path == u.path), None)
        if u.path == "/dashboard":
            key = "dashboard"
        if not key or not self.show_section(key, reset=True):
            return
        root = self._nav_views[key].find_page("root")
        if key == "settings" and q.get("group"):
            root.open_group(q["group"])
        elif key == "users":
            if q.get("view") or q.get("role"):
                root.show_view(q.get("view", "all"), q.get("role"))
            if q.get("import"):
                from .pages.users import ImportPage

                root.push(ImportPage(self, on_done=root.reload, group_id=int(q["group"]) if q.get("group") else None))
        elif key == "homework" and q.get("tab") == "marks":
            root.tab_dd.set_selected(2)
        elif key == "leads" and q.get("id"):
            from .pages.leads import LeadPage

            root.push(LeadPage(self, int(q["id"]), on_changed=root.reload))

    # ── Global search (Ctrl+K) ───────────────────────────────
    def open_search(self):
        if not self.user:
            return
        from .forms import pick

        def search(query, done):
            sections = [s for s in self._visible_sections()
                        if query and query.lower() in self.public.nav_label(s.path, s.default_label).lower()]
            nav = [(("section", s.key, None), self.public.nav_label(s.path, s.default_label), "Bo'lim") for s in sections[:4]]
            if len(query) < 2:
                done(nav or [(("section", s.key, None), self.public.nav_label(s.path, s.default_label), "Bo'lim")
                             for s in self._visible_sections()])
                return

            def got(data, err):
                if err:
                    done(nav, err if not nav else None)
                    return
                labels = {"student": "O'quvchi", "user": "Foydalanuvchi", "group": "Guruh", "course": "Kurs",
                          "lead": "Ariza", "certificate": "Sertifikat"}
                order = ["student", "user", "group", "course", "lead", "certificate"]
                hits = sorted(data.get("data") or [], key=lambda h: order.index(h["type"]) if h["type"] in order else 99)
                done([((h["type"], h["id"], h.get("href")), h["title"],
                       h.get("subtitle") if h["type"] in ("student", "user") else
                       " · ".join(x for x in [labels.get(h["type"]), h.get("subtitle")] if x)) for h in hits] + nav)

            self.api.get("search", got, query={"q": query})

        pick(self, "Qidirish", search, lambda item, title: self.open_hit(item, title),
             placeholder="O'quvchi, guruh, kurs, ariza, sertifikat…", suffix_icon="go-next-symbolic")

    def open_hit(self, item, title):
        kind, ident, href = item
        if kind == "section":
            self.show_section(ident)
            return
        page = self.current_page()
        if page is None:
            self.show_section("dashboard")
            page = self.current_page()
        from .pages.certificates import CertificateDetailPage
        from .pages.courses import CoursePage
        from .pages.groups import GroupPage
        from .pages.leads import LeadPage
        from .pages.users import UserPage

        if kind in ("student", "user"):
            new = UserPage(self, {"id": ident, "first_name": title, "role": "STUDENT" if kind == "student" else ""})
        elif kind == "group":
            new = GroupPage(self, ident, title)
        elif kind == "course":
            new = CoursePage(self, ident, title)
        elif kind == "lead":
            new = LeadPage(self, ident)
        elif kind == "certificate":
            code = (href or "").rstrip("/").rsplit("/", 1)[-1] or str(ident)
            new = CertificateDetailPage(self, code)
        else:
            return
        page.get_ancestor(Adw.NavigationView).push(new)
        self.split.set_show_content(True)

    def refresh(self):
        page = self.current_page()
        if page is not None and getattr(page, "refreshable", False):
            page.reload()

    def _on_network(self, monitor, *_):
        self.offline_banner.set_revealed(not monitor.get_network_available())

    def _on_close_request(self, *_):
        if not self.is_maximized():
            w, h = self.get_default_size()
            self.prefs.set_int("window-width", w)
            self.prefs.set_int("window-height", h)
        self.prefs.set_boolean("window-maximized", self.is_maximized())
        if self.user and self.prefs.get_boolean("run-in-background"):
            self.set_visible(False)
            self.app.hold_in_background()
            return True
        return False
