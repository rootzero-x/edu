"""Application entry point: actions, shortcuts, About dialog, announcement polling and notifications."""

import os
import sys

import gi

gi.require_version("Gtk", "4.0")
gi.require_version("Adw", "1")
gi.require_version("Soup", "3.0")

from gi.repository import Adw, Gdk, Gio, GLib, Gtk  # noqa: E402

from . import const, fmt, prefs  # noqa: E402
from .api import ApiClient  # noqa: E402
from .public_settings import PublicSettings  # noqa: E402
from .widgets import Signal  # noqa: E402

SHORTCUTS = [
    ("Umumiy", [
        ("Qidirish", ["<Control>k"]),
        ("Yangilash", ["<Control>r", "F5"]),
        ("Asosiy menyu", ["F10"]),
        ("Klaviatura yorliqlari", ["<Control>question"]),
        ("Chiqish (ilovadan)", ["<Control>q"]),
    ]),
    ("Navigatsiya", [
        ("Bo'limlar 1–9", ["<Control>1"]),
        ("Orqaga", ["<Alt>Left", "Escape"]),
    ]),
]


class TisuApplication(Adw.Application):
    def __init__(self):
        flags = getattr(Gio.ApplicationFlags, "DEFAULT_FLAGS", Gio.ApplicationFlags.FLAGS_NONE)
        super().__init__(application_id=const.APP_ID, flags=flags)
        GLib.set_application_name(const.APP_NAME)
        self.prefs = prefs.load()
        self.api = ApiClient(prefs.api_url(self.prefs))
        self.public = PublicSettings(self.api)
        self.bus = Signal()
        self.window = None
        self._poll_id = 0
        self._unread = None
        self._held = False
        self.api.on_unauthorized = lambda: self.window and self.window.session_expired()
        self.public.connect_changed(lambda: fmt.configure(self.public))
        fmt.configure(self.public)

    # ── Startup ──────────────────────────────────────────────
    def do_startup(self):
        Adw.Application.do_startup(self)
        display = Gdk.Display.get_default()
        theme = Gtk.IconTheme.get_for_display(display)
        theme.add_search_path(os.path.join(const.PKGDATADIR, "icons", "ui"))
        theme.add_search_path(os.path.join(const.PKGDATADIR, "icons"))
        css = Gtk.CssProvider()
        css_path = os.path.join(const.PKGDATADIR, "style.css")
        if os.path.exists(css_path):
            css.load_from_path(css_path)
            Gtk.StyleContext.add_provider_for_display(display, css, Gtk.STYLE_PROVIDER_PRIORITY_APPLICATION)

        self._action("quit", lambda *_: self.quit(), ["<Control>q"])
        self._action("about", lambda *_: self.show_about())
        self._action("shortcuts", lambda *_: self.show_shortcuts(), ["<Control>question"])
        self.add_action(self.prefs.create_action("run-in-background"))
        open_ann = Gio.SimpleAction.new("open-announcement", GLib.VariantType.new("x"))
        open_ann.connect("activate", lambda _a, p: self._open_announcement(p.get_int64()))
        self.add_action(open_ann)
        self.public.refresh()

    def _action(self, name, cb, accels=None):
        a = Gio.SimpleAction.new(name, None)
        a.connect("activate", cb)
        self.add_action(a)
        if accels:
            self.set_accels_for_action(f"app.{name}", accels)

    def do_activate(self):
        if self.window is None:
            from .window import TisuWindow

            self.window = TisuWindow(self)
            self._window_actions(self.window)
            self.window.start()
        self.release_background()
        self.window.present()

    def _window_actions(self, win):
        def add(name, cb, accels=None):
            a = Gio.SimpleAction.new(name, None)
            a.connect("activate", cb)
            win.add_action(a)
            if accels:
                self.set_accels_for_action(f"win.{name}", accels)

        add("refresh", lambda *_: win.refresh(), ["<Control>r", "F5"])
        add("logout", lambda *_: self.confirm_logout())
        add("search", lambda *_: win.open_search(), ["<Control>k", "<Control>f"])
        sec = Gio.SimpleAction.new("section", GLib.VariantType.new("i"))
        sec.connect("activate", lambda _a, p: win.section_index(p.get_int32()))
        win.add_action(sec)
        for i in range(9):
            self.set_accels_for_action(f"win.section({i})", [f"<Control>{i + 1}"])

    def confirm_logout(self):
        d = Adw.AlertDialog(heading="Chiqasizmi?", body="Bu kompyuterdagi sessiya yopiladi va token o'chiriladi.")
        d.add_response("cancel", "Bekor qilish")
        d.add_response("logout", "Chiqish")
        d.set_response_appearance("logout", Adw.ResponseAppearance.DESTRUCTIVE)
        d.set_default_response("cancel")
        d.set_close_response("cancel")
        d.connect("response", lambda _d, r: r == "logout" and self.window.logout())
        d.present(self.window)

    # ── Background mode ──────────────────────────────────────
    def hold_in_background(self):
        if not self._held:
            self.hold()
            self._held = True

    def release_background(self):
        if self._held:
            self._held = False
            self.release()

    # ── Dialogs ──────────────────────────────────────────────
    def show_about(self):
        s = self.public
        about = Adw.AboutDialog(
            application_name=s.site_name,
            application_icon=const.APP_ID,
            developer_name=s.site_name,
            version=const.VERSION,
            website=s.web_url,
            issue_url=const.ISSUE_URL,
            comments=s.get("site.description", s.get("site.tagline", "")),
            copyright=s.get("site.footer_text", ""),
            license_type=Gtk.License.MIT_X11,
        )
        contacts = []
        if s.get("site.contact_phone"):
            contacts.append(f"Telefon: {s.get('site.contact_phone')}")
        if s.get("site.contact_telegram"):
            contacts.append(f"Telegram: {s.get('site.contact_telegram')}")
        if s.get("site.contact_email"):
            contacts.append(f"E-pochta: {s.get('site.contact_email')}")
        if contacts:
            about.set_support_url(s.get("course.contact_url", s.web_url))
            about.set_release_notes("<p>" + GLib.markup_escape_text(" · ".join(contacts)) + "</p>")
        about.present(self.window)

    def show_shortcuts(self):
        if hasattr(Adw, "ShortcutsDialog"):  # libadwaita ≥ 1.8
            dlg = Adw.ShortcutsDialog()
            for title, items in SHORTCUTS:
                sec = Adw.ShortcutsSection(title=title)
                for name, accels in items:
                    sec.add(Adw.ShortcutsItem(title=name, accelerator=" ".join(accels)))
                dlg.add(sec)
            dlg.present(self.window)
            return
        groups = ""
        for title, items in SHORTCUTS:
            rows = "".join(
                f'<child><object class="GtkShortcutsShortcut"><property name="title">{GLib.markup_escape_text(n)}</property>'
                f'<property name="accelerator">{GLib.markup_escape_text(" ".join(a))}</property></object></child>'
                for n, a in items)
            groups += f'<child><object class="GtkShortcutsGroup"><property name="title">{GLib.markup_escape_text(title)}</property>{rows}</object></child>'
        xml = ('<interface><object class="GtkShortcutsWindow" id="w"><property name="modal">1</property>'
               f'<child><object class="GtkShortcutsSection"><property name="section-name">main</property>{groups}</object></child>'
               '</object></interface>')
        win = Gtk.Builder.new_from_string(xml, -1).get_object("w")
        win.set_transient_for(self.window)
        win.present()

    # ── Announcements: unread badge + desktop notifications ──
    def start_polling(self):
        self.stop_polling()
        self._unread = None
        self._poll_unread()
        self._poll_id = GLib.timeout_add_seconds(self.public.refresh_seconds, self._poll_unread)

    def stop_polling(self):
        if self._poll_id:
            GLib.source_remove(self._poll_id)
            self._poll_id = 0

    def _poll_unread(self):
        if not self.api.token:
            return True

        def done(data, err):
            if err or data is None:
                return
            n = int(data.get("unread") or 0)
            previous = self._unread
            self._unread = n
            self.bus.emit("unread-changed", n)
            if n > 0 and (previous is None or n > previous):
                self._notify_new()

        self.api.get("announcements/unread", done)
        return True

    def set_unread(self, n):
        self._unread = n
        self.bus.emit("unread-changed", n)

    def _notify_new(self):
        def done(data, err):
            if err or not data:
                return
            seen = set(prefs.get_notified(self.prefs))
            first_run = not seen
            fresh = [a for a in data.get("data", []) if not a.get("read") and a.get("id") not in seen]
            if not fresh:
                return
            prefs.set_notified(self.prefs, list(seen) + [a["id"] for a in fresh])
            if first_run:
                return  # don't flood on the very first sign-in; the badge shows them
            for a in fresh[:3]:
                n = Gio.Notification.new(a.get("title") or self.public.get("news.title", "Yangi e'lon"))
                body = (a.get("body") or "").strip().replace("\n", " ")
                n.set_body(body[:200] + ("…" if len(body) > 200 else ""))
                n.set_icon(Gio.ThemedIcon.new(const.APP_ID))
                n.set_default_action(f"app.open-announcement({int(a['id'])})")
                self.send_notification(f"announcement-{a['id']}", n)
            if len(fresh) > 3:
                n = Gio.Notification.new(self.public.get("news.title", "Yangiliklar"))
                n.set_body(f"Yana {len(fresh) - 3} ta yangi e'lon")
                n.set_icon(Gio.ThemedIcon.new(const.APP_ID))
                self.send_notification("announcement-more", n)

        self.api.get("announcements", done, query={"page": 1})

    def _open_announcement(self, ann_id):
        self.activate()
        if self.window:
            self.window.open_announcement(ann_id)


def main(argv=None):
    app = TisuApplication()
    return app.run(argv if argv is not None else sys.argv)


if __name__ == "__main__":
    sys.exit(main())
