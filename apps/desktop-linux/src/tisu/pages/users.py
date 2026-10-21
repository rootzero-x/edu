"""Users (user.manage): search, views (requests, Telegram), create, edit, password, block, approve /
reject registrations, duplicates & merge, student import; and the user profile (360° view)."""

import base64
import os

from gi.repository import Adw, Gio, GLib, Gtk

from .. import fmt
from ..csvexport import save_file
from ..forms import confirm, form
from ..widgets import Page, done_toast, group, icon_button, menu_button, pill, prefs_page, row, status_css, text_button

ROLES = ["STUDENT", "TEACHER", "ADMIN", "SUPER_ADMIN"]
VIEWS = [("all", "Hammasi", {}), ("pending", "Arizalar", {"pending": 1}), ("tg_none", "Telegram ulanmagan", {"telegram": "none"}),
         ("tg_blocked", "Botni bloklagan", {"telegram": "blocked"})]
ENROLLMENT_CSS = {"ACTIVE": "success", "FROZEN": "accent", "LEFT": "dim", "GRADUATED": "success", "TRANSFERRED": "dim"}


def role_options(win, include_all=False):
    roles = [r for r in ROLES if r != "SUPER_ADMIN" or win.role == "SUPER_ADMIN"]
    opts = [(r, win.public.role_label(r)) for r in roles]
    return ([("", "Barcha rollar")] + opts) if include_all else opts


def enrollment_label(s, st):
    labels = s.get("enrollment.status_labels", {})
    defaults = {"ACTIVE": "o'qiydi", "FROZEN": "muzlatilgan", "LEFT": "chiqib ketgan", "GRADUATED": "bitirgan",
                "TRANSFERRED": "ko'chirilgan"}
    return (labels.get(st) if isinstance(labels, dict) else None) or defaults.get(st, st)


def search_students(api, query, done, exclude=()):
    def got(data, err):
        if err:
            done([], err)
            return
        done([(u["id"], fmt.name(u), u.get("phone")) for u in data.get("data") or [] if u["id"] not in exclude])

    api.get("users", got, query={"role": "STUDENT", "active": 1, "q": query or None, "per_page": 30})


class UsersPage(Page):
    def __init__(self, win):
        super().__init__(win, win.public.nav_label("/dashboard/users", "Foydalanuvchilar"))
        self.items = []
        self.page_no = 1
        self.pages = 1
        self.total = 0
        self.pending = 0
        self.header.pack_start(icon_button("list-add-symbolic", "Yangi foydalanuvchi", self.create, css=()))
        self.header.pack_start(menu_button([
            ("O'quvchilarni import qilish (Excel/CSV)", lambda: self.push(ImportPage(self.win, on_done=self.reload))),
            ("Takroriy akkauntlar", lambda: self.push(DuplicatesPage(self.win, on_changed=self.reload))),
        ], tooltip="Qo'shimcha"))

        bar = Gtk.Box(spacing=6, margin_start=12, margin_end=12, margin_bottom=6)
        self.search = Gtk.SearchEntry(placeholder_text="Ism yoki telefon bo'yicha qidirish", hexpand=True)
        self.search.connect("search-changed", lambda *_: self._debounce())
        self.view_dd = Gtk.DropDown(model=Gtk.StringList.new([v[1] for v in VIEWS]))
        self.roles = role_options(win, include_all=True)
        self.role_dd = Gtk.DropDown(model=Gtk.StringList.new([r[1] for r in self.roles]))
        for dd in (self.view_dd, self.role_dd):
            dd.connect("notify::selected", lambda *_: self._loaded and self.reload())
        for w in (self.search, self.view_dd, self.role_dd):
            bar.append(w)
        self.toolbar.add_top_bar(bar)
        self._timer = 0

    def show_view(self, key, role=None):
        """Open a view programmatically (e.g. from the setup checklist or system status)."""
        keys = [v[0] for v in VIEWS]
        if key in keys:
            self.view_dd.set_selected(keys.index(key))
        if role:
            vals = [r[0] for r in self.roles]
            if role in vals:
                self.role_dd.set_selected(vals.index(role))

    def _debounce(self):
        if self._timer:
            GLib.source_remove(self._timer)
        self._timer = GLib.timeout_add(350, lambda: (setattr(self, "_timer", 0), self.reload()) and False)

    def query(self, page=1):
        role = self.roles[self.role_dd.get_selected()][0]
        q = {"q": self.search.get_text().strip() or None, "role": role or None, "page": page}
        q.update(VIEWS[self.view_dd.get_selected()][2])
        return q

    def fetch(self, done):
        self.page_no = 1
        self.api.get("users", done, query=self.query())

    def render(self, data):
        self.items = list(data.get("data") or [])
        meta = data.get("meta") or {}
        self.pages, self.total = meta.get("pages", 1), meta.get("total", len(self.items))
        self.pending = meta.get("pending") or 0
        sel = self.view_dd.get_selected()
        self.view_dd.set_model(Gtk.StringList.new([f"{v[1]} · {self.pending}" if v[0] == "pending" and self.pending else v[1] for v in VIEWS]))
        self.view_dd.set_selected(sel)
        if not self.items:
            self.show_empty("tisu-people-symbolic", "Foydalanuvchi topilmadi", "Qidiruv yoki filtrni o'zgartiring.")
            return None
        return self._build()

    def _build(self):
        g = group(None, f"Jami: {self.total}" + (f" · ko'rib chiqilmagan arizalar: {self.pending}" if self.pending else ""))
        me = (self.win.user or {}).get("id")
        for u in self.items:
            suffix = [pill(self.settings.role_label(u["role"]), "dim")]
            if u["id"] == me:
                suffix.insert(0, pill("Siz", "accent"))
            if u.get("pending"):
                suffix.append(pill("ariza", "warning"))
            elif not u.get("active", True):
                suffix.append(pill("bloklangan", "error"))
            tg = u.get("telegram") or {}
            if tg.get("blocked"):
                suffix.append(pill("botni bloklagan", "warning"))
            sub = u.get("phone") or ""
            if tg.get("linked") and tg.get("username"):
                sub += f" · @{tg['username']}"
            g.add(row(fmt.name(u), sub, icon="avatar-default-symbolic", suffix=suffix,
                      on_activate=lambda u=u: self.push(UserPage(self.win, u, on_changed=self.reload))))
        more = None
        if self.page_no < self.pages:
            more = group()
            b = text_button("Ko'proq ko'rsatish", self.load_more, css=("pill",))
            b.set_halign(Gtk.Align.CENTER)
            more.add(b)
        return prefs_page(g, more)

    def load_more(self):
        def done(data, err):
            if err:
                self.win.toast(err.message)
                return
            self.page_no += 1
            self.items.extend(data.get("data") or [])
            self.set_content(self._build())

        self.api.get("users", done, query=self.query(self.page_no + 1))

    def create(self):
        role = self.roles[self.role_dd.get_selected()][0] or "STUDENT"
        min_pw = self.settings.int("auth.min_password_length", 10)

        def submit(v, done):
            body = {"phone": v["phone"], "first_name": v["first_name"], "last_name": v["last_name"] or None, "role": v["role"]}
            if v["password"]:
                body["password"] = v["password"]

            def ok(data, err):
                done(err)
                if not err:
                    self.win.toast("Foydalanuvchi qo'shildi")
                    self.reload()

            self.api.post("users", body, ok)

        form(self.win, "Yangi foydalanuvchi", [
            {"name": "phone", "label": "Telefon (+998XXXXXXXXX)", "value": "+998", "required": True, "purpose": "phone"},
            {"name": "first_name", "label": "Ism", "required": True},
            {"name": "last_name", "label": "Familiya"},
            {"name": "role", "label": "Rol", "type": "choice", "options": role_options(self.win), "value": role},
            {"name": "password", "label": f"Parol (ixtiyoriy, kamida {min_pw} belgi)", "type": "password",
             "hint": "Bo'sh qoldirilsa, foydalanuvchi Telegram orqali kiradi."},
        ], submit, submit_label="Qo'shish")


class UserPage(Page):
    """A user's profile: contacts, groups and enrollment history, attendance, quizzes, certificates, balance."""

    def __init__(self, win, user, on_changed=None, load=False):
        super().__init__(win, fmt.name(user) if user.get("first_name") else "Profil")
        self.user = user
        self.on_changed = on_changed

    def fetch(self, done):
        def got(data, err):
            if err and err.kind in ("forbidden", "not_found") and self.user.get("phone"):
                done({"user": self.user, "can_manage": self.win.has("user.manage")}, None)  # fall back to the list row
                return
            done(data, err)

        self.api.get(f"users/{self.user['id']}/profile", got)

    def can_edit(self):
        u, me = self.user, self.win.user or {}
        return (self.win.has("user.manage") and u["id"] != me.get("id")
                and (u.get("role") != "SUPER_ADMIN" or me.get("role") == "SUPER_ADMIN"))

    def changed(self, *_):
        self.reload()
        if self.on_changed:
            self.on_changed()

    def render(self, data):
        s = self.settings
        u = self.user = dict(self.user, **(data.get("user") or {}))
        self.set_title(fmt.name(u))
        info = group(fmt.name(u), s.role_label(u.get("role")))
        info.add(row("Telefon", u.get("phone") or "—", icon="phone-symbolic"))
        status = "Ariza (tasdiqlanmagan)" if u.get("pending") else ("Faol" if u.get("active", True) else "Bloklangan")
        info.add(row("Holati", status, icon="dialog-password-symbolic"))
        tg = u.get("telegram")
        if isinstance(tg, dict):
            tg = tg.get("linked")
        info.add(row("Telegram", "Ulangan" if tg else "Ulanmagan", icon="tisu-telegram-symbolic"))
        if u.get("created_at"):
            info.add(row("Ro'yxatdan o'tgan", fmt.date(u["created_at"]), icon="x-office-calendar-symbolic"))
        groups_ = [info]

        if u.get("pending") and self.win.has("user.manage"):
            pg = group("Ro'yxatdan o'tish arizasi", "Tasdiqlansa, foydalanuvchiga Telegram orqali xabar boradi.")
            pg.add(row("O'quvchi sifatida tasdiqlash", None, icon="object-select-symbolic", on_activate=lambda: self.approve("STUDENT")))
            pg.add(row("O'qituvchi sifatida tasdiqlash", None, icon="object-select-symbolic", on_activate=lambda: self.approve("TEACHER")))
            pg.add(row("Rad etish", None, icon="action-unavailable-symbolic", on_activate=self.reject))
            groups_.append(pg)

        if self.can_edit():
            acts = group("Amallar")
            acts.add(row("Tahrirlash", "Ism, familiya, telefon va rol", icon="document-edit-symbolic", on_activate=self.edit))
            acts.add(row("Parol o'rnatish", "Barcha qurilmalardagi sessiyalar yopiladi", icon="dialog-password-symbolic",
                         on_activate=self.set_password))
            if not u.get("pending"):
                active = u.get("active", True)
                acts.add(row("Bloklash" if active else "Faollashtirish",
                             "Foydalanuvchi tizimga kira olmaydi" if active else "Kirishga yana ruxsat beriladi",
                             icon="action-unavailable-symbolic" if active else "object-select-symbolic", on_activate=self.toggle_active))
            groups_.append(acts)

        ov = data.get("overview")
        if ov:
            groups_ += self._overview(ov)
        timeline = data.get("timeline") or []
        if timeline:
            tg_ = group("A'zolik tarixi")
            for t in timeline:
                period = fmt.date(t.get("starts_on")) + (f" — {fmt.date(t['ends_on'])}" if t.get("ends_on") else "")
                bits = [t.get("course_title"), period, t.get("reason"), t.get("note"),
                        f"→ {t['transfer_group']}" if t.get("transfer_group") else None]
                tg_.add(row(t.get("group_name") or "—", " · ".join(b for b in bits if b), icon="tisu-people-symbolic",
                            suffix=pill(enrollment_label(s, t.get("status")), ENROLLMENT_CSS.get(t.get("status"), "dim"))))
            groups_.append(tg_)
        leads = data.get("leads") or []
        if leads:
            from .leads import LeadPage, sources, status_label

            lg = group("Arizalar (CRM)")
            for ld in leads:
                lg.add(row(f"#{ld['id']} · {status_label(s, ld['status'])}",
                           f"{sources(s).get(ld.get('source'), ld.get('source'))} · {fmt.date(ld.get('created_at'))}",
                           on_activate=lambda ld=ld: self.push(LeadPage(self.win, ld["id"]))))
            groups_.append(lg)
        return prefs_page(*groups_)

    def _overview(self, ov):
        s = self.settings
        out = []
        gg = group("Guruhlari")
        for g in ov.get("groups") or []:
            sched = ", ".join(f"{fmt.weekday(x['weekday'])} {x['start']}" for x in g.get("schedule") or [])
            bits = [g.get("course_title"), g.get("teacher"), sched]
            if g.get("next_session"):
                bits.append(f"keyingi dars: {fmt.day_label(g['next_session'])} {fmt.time(g['next_session'])}")
            if g.get("status") == "FROZEN" and g.get("frozen_until"):
                bits.append(f"{fmt.date(g['frozen_until'])} gacha muzlatilgan")
            from .groups import GroupPage

            gg.add(row(g["name"], " · ".join(b for b in bits if b), icon="tisu-people-symbolic",
                       suffix=pill(enrollment_label(s, g.get("status")), ENROLLMENT_CSS.get(g.get("status"), "dim")),
                       on_activate=lambda g=g: self.push(GroupPage(self.win, g["id"], g["name"]))))
        if not ov.get("groups"):
            gg.add(row("Guruhga qo'shilmagan.", icon="dialog-information-symbolic"))
        out.append(gg)

        att = ov.get("attendance") or {}
        if att:
            c = att.get("counts") or {}
            ag = group(f"{s.text('attendance_rate', 'Davomat')} ({att.get('days', 30)} kun)",
                       " · ".join(f"{s.status_label(k)} {c.get(k, 0)}" for k in ("PRESENT", "LATE", "ABSENT", "EXCUSED")),
                       suffix=pill(fmt.percent(att.get("rate")), "success" if (att.get("rate") or 0) >= 80 else "warning"))
            for r in (att.get("recent") or [])[:8]:
                ag.add(row(f"{fmt.date(r['starts_at'])} {fmt.time(r['starts_at'])} · {r.get('group_name')}", r.get("topic") or r.get("note"),
                           suffix=pill(s.status_label(r["status"]) if r.get("status") else "—", status_css(r.get("status")))))
            out.append(ag)
        if ov.get("quizzes"):
            qg = group(s.text("quizzes", "Testlar"))
            for q in ov["quizzes"]:
                qg.add(row(q["title"], f"{q.get('course_title')} · {q.get('score')}/{q.get('max_score')} · {fmt.date(q.get('submitted_at'))}",
                           icon="tisu-quiz-symbolic", suffix=pill(fmt.percent(q.get("percent")), "success" if q.get("passed") else "error")))
            out.append(qg)
        if ov.get("certificates"):
            from .certificates import CertificateDetailPage

            cg = group("Sertifikatlar")
            for c in ov["certificates"]:
                cg.add(row(c.get("course_title"), f"{c['code']} · {fmt.date(c.get('issued_at'))}", icon="tisu-award-symbolic",
                           on_activate=lambda c=c: self.push(CertificateDetailPage(self.win, c["code"]))))
            out.append(cg)
        bal = ov.get("balance")
        if bal:
            from .billing import StudentBillingPage

            b = int(bal.get("balance") or 0)
            bg = group("To'lovlar")
            bg.add(row("Balans", "Qarz" if b < 0 else "Qarz yo'q", icon="tisu-wallet-symbolic",
                       suffix=pill(fmt.money(b, bal.get("currency") or s.get("billing.currency", "so'm")),
                                   "error" if b < 0 else "success" if b > 0 else "dim"),
                       on_activate=(lambda: self.push(StudentBillingPage(self.win, self.user["id"], fmt.name(self.user))))
                       if self.win.has("billing.manage") else None))
            out.append(bg)
        return out

    # ── Actions ──────────────────────────────────────────────
    def _patch(self, body, done=None, toast="Saqlandi"):
        def ok(data, err):
            if done:
                done(err)
            elif err:
                self.win.toast(err.message, timeout=5)
            if not err:
                self.user = dict(self.user, **(data.get("user") or body))
                self.win.toast(toast)
                self.changed()

        self.api.patch(f"users/{self.user['id']}", body, ok)

    def edit(self):
        u = self.user

        def submit(v, done):
            body = {"first_name": v["first_name"], "last_name": v["last_name"] or None, "phone": v["phone"]}
            if v["role"] != u["role"]:
                body["role"] = v["role"]
            self._patch(body, done)

        form(self.win, "Foydalanuvchini tahrirlash", [
            {"name": "first_name", "label": "Ism", "value": u.get("first_name"), "required": True},
            {"name": "last_name", "label": "Familiya", "value": u.get("last_name")},
            {"name": "phone", "label": "Telefon (+998XXXXXXXXX)", "value": u.get("phone"), "required": True, "purpose": "phone"},
            {"name": "role", "label": "Rol", "type": "choice", "options": role_options(self.win), "value": u["role"],
             "hint": "Rol o'zgarsa foydalanuvchi qayta kirishi kerak bo'ladi."},
        ], submit)

    def set_password(self):
        form(self.win, "Parol o'rnatish", [
            {"name": "password", "label": f"Yangi parol (kamida {self.settings.int('auth.min_password_length', 10)} belgi)",
             "type": "password", "required": True},
        ], lambda v, done: self._patch({"password": v["password"]}, done, toast="Parol o'rnatildi"))

    def toggle_active(self):
        active = self.user.get("active", True)
        if active:
            confirm(self.win, "Bloklansinmi?", f"{fmt.name(self.user)} tizimga kira olmaydi, barcha sessiyalari yopiladi.",
                    "Bloklash", lambda: self._patch({"active": False}, toast="Bloklandi"))
        else:
            self._patch({"active": True}, toast="Faollashtirildi")

    def approve(self, role):
        self.api.post(f"users/{self.user['id']}/approve", {"role": role},
                      done_toast(self.win, f"Tasdiqlandi: {self.settings.role_label(role)}", self.changed))

    def reject(self):
        confirm(self.win, "Ariza rad etilsinmi?", f"{fmt.name(self.user)} akkaunti faol bo'lmaydi.", "Rad etish",
                lambda: self.api.post(f"users/{self.user['id']}/reject", {}, done_toast(self.win, "Ariza rad etildi", self.changed)))


class DuplicatesPage(Page):
    def __init__(self, win, on_changed=None):
        super().__init__(win, "Takroriy akkauntlar")
        self.on_changed = on_changed

    def fetch(self, done):
        self.api.get("users/duplicates", done)

    def render(self, data):
        s = self.settings
        out = []
        for key, title, hint in (("phone_groups", "Bir xil telefon", "Telefon raqami bir xil (formati boshqacha) akkauntlar"),
                                 ("name_groups", "Bir xil ism", "Ism-familiyasi bir xil akkauntlar — tekshirib ko'ring")):
            for grp in data.get(key) or []:
                users = grp.get("users") or []
                main = next((u for u in users if u.get("history")), users[0] if users else None)
                g = group(f"{title}: {grp.get('phone') or grp.get('name')}", hint)
                for u in users:
                    sub = f"{u.get('phone')} · {s.role_label(u.get('role'))} · {fmt.date(u.get('created_at'))}"
                    sub += " · tarixi bor" if u.get("history") else " · tarixi yo'q"
                    suffix = []
                    if u is main:
                        suffix.append(pill("asosiy", "accent"))
                    elif not u.get("history") and main:
                        suffix.append(text_button(f"#{main['id']} ga qo'shish", lambda u=u, m=main: self.merge(u, m), css=("flat",)))
                    g.add(row(f"#{u['id']} {u.get('name')}", sub, icon="avatar-default-symbolic", suffix=suffix))
                out.append(g)
        nc = data.get("non_canonical") or []
        if nc:
            out.append(group(None, f"Telefon formati nostandart akkauntlar: {len(nc)} ta (masalan {nc[0].get('phone')} → "
                                    f"{nc[0].get('normalized')}). Ularni tahrirlab, to'g'ri formatga keltiring."))
        if not out:
            self.show_empty("object-select-symbolic", "Takroriy akkauntlar yo'q")
            return None
        return prefs_page(*out)

    def merge(self, src, into):
        def yes():
            def ok(_d, err):
                if err:
                    self.win.toast(err.message, timeout=6)
                    return
                self.win.toast("Akkauntlar birlashtirildi")
                self.reload()
                if self.on_changed:
                    self.on_changed()

            self.api.post(f"users/{src['id']}/merge", {"into_id": into["id"]}, ok)

        confirm(self.win, "Akkauntlar birlashtirilsinmi?",
                f"#{src['id']} {src.get('name')} → #{into['id']} {into.get('name')}. Telegram bog'lanishi ko'chiriladi, "
                f"#{src['id']} arxivlanadi.", "Birlashtirish", yes)


class ImportPage(Page):
    """Excel/CSV import of students: choose file → preview (validation per row) → commit."""

    refreshable = False

    def __init__(self, win, on_done=None, group_id=None):
        super().__init__(win, "O'quvchilarni import qilish")
        self.on_done = on_done
        self.preset_group = group_id
        self.groups = []
        self.preview = None
        self.only_errors = False
        self.skip_invalid = False
        self.api.get("groups", self._got_groups)
        self.set_content(self._intro())

    def _got_groups(self, data, err):
        self.groups = [g for g in (data or {}).get("data") or [] if g.get("active")]
        if self.preview is None:
            self.set_content(self._intro())

    def _intro(self):
        s = self.settings
        tg = group("1. Shablon", "Ustunlar: Ism, Familiya, Telefon, Guruh, Qo'shilgan sana. Birinchi qator — sarlavha.")
        for fmt_, name, pattern in (("xlsx", "Excel shablon (.xlsx)", "*.xlsx"), ("csv", "CSV shablon (.csv)", "*.csv")):
            tg.add(row(name, None, icon="document-save-symbolic", on_activate=lambda f=fmt_, n=name, p=pattern: save_file(
                self.win, self.api, "imports/students/template", {"format": f}, f"oquvchilar-import.{f}", None, n, p)))
        og = group("2. Sozlamalar")
        self.group_row = Adw.ComboRow(title="Guruh ustuni bo'sh bo'lsa", use_markup=False,
                                      model=Gtk.StringList.new(["Guruhga qo'shmaslik"] + [g["name"] for g in self.groups]))
        ids = [g["id"] for g in self.groups]
        if self.preset_group in ids:
            self.group_row.set_selected(ids.index(self.preset_group) + 1)
        self.date_row = Adw.EntryRow(title="Qo'shilgan sana (bo'sh bo'lsa — bugun), YYYY-MM-DD")
        og.add(self.group_row)
        og.add(self.date_row)
        fg = group("3. Fayl")
        b = text_button("Faylni tanlash…", self.choose_file, css=("pill", "suggested-action"), icon="document-open-symbolic")
        b.set_halign(Gtk.Align.CENTER)
        fg.add(b)
        fg.set_description(f"Excel (.xlsx) yoki CSV, {s.int('import.max_rows', 2000)} qatorgacha.")
        return prefs_page(tg, og, fg)

    def choose_file(self):
        dialog = Gtk.FileDialog(title="Import fayli")
        f = Gtk.FileFilter(name="Excel yoki CSV")
        for p in ("*.xlsx", "*.csv", "*.txt"):
            f.add_pattern(p)
        filters = Gio.ListStore.new(Gtk.FileFilter)
        filters.append(f)
        dialog.set_filters(filters)

        def chosen(dlg, res):
            try:
                file = dlg.open_finish(res)
            except GLib.Error:
                return
            file.load_contents_async(None, lambda fl, r, _d: self._loaded_file(fl, r), None)

        dialog.open(self.win, None, chosen)

    def _loaded_file(self, file, res):
        try:
            ok, content, _etag = file.load_contents_finish(res)
        except GLib.Error as e:
            self.win.toast(f"Faylni o'qib bo'lmadi: {e.message}")
            return
        i = self.group_row.get_selected()
        body = {"filename": os.path.basename(file.get_path() or file.get_basename() or "import.csv"),
                "content": base64.b64encode(content).decode("ascii"),
                "group_id": self.groups[i - 1]["id"] if 0 < i <= len(self.groups) else None,
                "start_date": self.date_row.get_text().strip() or None}
        self.stack.set_visible_child_name("loading")

        def done(data, err):
            if err:
                self.stack.set_visible_child_name("content")
                self.win.toast(err.message, timeout=6)
                return
            self.preview = data
            self.set_content(self._preview_view())

        self.api.post("imports/students/preview", body, done)

    def _preview_view(self, result=None):
        d = self.preview
        sm = d.get("summary") or {}
        head = group("Natija" if result else "Tekshiruv")
        if result:
            head.set_description(f"Yaratildi: {result.get('students_created', 0)} · yangilandi: {result.get('students_updated', 0)} · "
                                 f"guruhga qo'shildi: {result.get('memberships', 0)} · o'tkazib yuborildi: {result.get('skipped', 0)}")
        else:
            head.set_description(f"Qatorlar: {sm.get('rows', 0)} · to'g'ri: {sm.get('valid', 0)} · xato: {sm.get('errors', 0)} · "
                                 f"yangi o'quvchi: {sm.get('new_students', 0)} · mavjud: {sm.get('existing_students', 0)} · "
                                 f"guruhga qo'shiladi: {sm.get('memberships', 0)}")
        if d.get("unknown_columns"):
            head.add(row("Tanilmagan ustunlar e'tiborsiz qoldiriladi", ", ".join(d["unknown_columns"]), icon="dialog-warning-symbolic"))
        groups_ = [head]
        if not result:
            ctl = group()
            only = Adw.SwitchRow(title="Faqat xato qatorlar", active=self.only_errors)
            only.connect("notify::active", lambda w, *_: (setattr(self, "only_errors", w.get_active()), self.set_content(self._preview_view())))
            ctl.add(only)
            errors = sm.get("errors", 0)
            if errors:
                skip = Adw.SwitchRow(title="Xato qatorlarni tashlab, qolganini import qilish", active=self.skip_invalid)
                skip.connect("notify::active", lambda w, *_: (setattr(self, "skip_invalid", w.get_active()),
                                                              self.commit_btn.set_sensitive(sm.get("valid", 0) > 0 and w.get_active())))
                ctl.add(skip)
            self.commit_btn = text_button(f"{sm.get('valid', 0)} ta qatorni import qilish", self.commit, css=("pill", "suggested-action"))
            self.commit_btn.set_halign(Gtk.Align.CENTER)
            self.commit_btn.set_sensitive(sm.get("valid", 0) > 0 and (errors == 0 or self.skip_invalid))
            again = text_button("Boshqa fayl", lambda: (setattr(self, "preview", None), self.set_content(self._intro())), css=("pill",))
            again.set_halign(Gtk.Align.CENTER)
            box = Gtk.Box(spacing=12, halign=Gtk.Align.CENTER)
            box.append(again)
            box.append(self.commit_btn)
            ctl.add(box)
            groups_.append(ctl)
        rg = group("Qatorlar")
        shown = 0
        for r in d.get("rows") or []:
            if self.only_errors and r.get("status") != "error":
                continue
            shown += 1
            if shown > 300:
                rg.add(row(f"… yana {len(d['rows']) - 300} ta qator", None))
                break
            name = " ".join(x for x in [r.get("first_name"), r.get("last_name")] if x) or "—"
            bits = [r.get("phone") or r.get("phone_raw"), r.get("group_name"), fmt.date(r["start_date"]) if r.get("start_date") else None]
            bits += r.get("errors") or []
            bits += r.get("warnings") or []
            if r.get("status") == "error":
                p = pill("xato", "error")
            else:
                p = pill({"new": "yangi", "existing": "mavjud"}.get(r.get("action"), r.get("action") or "ok"), "success")
            rw = row(f"{r.get('line')}. {name}", " · ".join(str(b) for b in bits if b), suffix=p)
            rw.set_subtitle_lines(3)
            rg.add(rw)
        groups_.append(rg)
        return prefs_page(*groups_)

    def commit(self):
        body = {"token": self.preview["token"]}
        if self.skip_invalid:
            body["skip_invalid"] = True
        self.stack.set_visible_child_name("loading")

        def done(data, err):
            if err:
                self.stack.set_visible_child_name("content")
                self.win.toast(err.message, timeout=6)
                return
            self.preview = data
            self.set_content(self._preview_view(result=data.get("result") or {}))
            self.win.toast("Import yakunlandi")
            if self.on_done:
                self.on_done()

        self.api.post("imports/students/commit", body, done)

