"""Users (user.manage): search, role filter, create, edit, password, block."""

from gi.repository import GLib, Gtk

from .. import fmt
from ..forms import confirm, form
from ..widgets import Page, group, icon_button, pill, prefs_page, row, text_button

ROLES = ["STUDENT", "TEACHER", "ADMIN", "SUPER_ADMIN"]


def role_options(win, include_all=False):
    roles = [r for r in ROLES if r != "SUPER_ADMIN" or win.role == "SUPER_ADMIN"]
    opts = [(r, win.public.role_label(r)) for r in roles]
    return ([("", "Barcha rollar")] + opts) if include_all else opts


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
        add = icon_button("list-add-symbolic", "Yangi foydalanuvchi", self.create, css=())
        self.header.pack_start(add)

        bar = Gtk.Box(spacing=6, margin_start=12, margin_end=12, margin_bottom=6)
        self.search = Gtk.SearchEntry(placeholder_text="Ism yoki telefon bo'yicha qidirish", hexpand=True)
        self.search.connect("search-changed", lambda *_: self._debounce())
        self.roles = role_options(win, include_all=True)
        self.role_dd = Gtk.DropDown(model=Gtk.StringList.new([r[1] for r in self.roles]))
        self.role_dd.connect("notify::selected", lambda *_: self._loaded and self.reload())
        bar.append(self.search)
        bar.append(self.role_dd)
        self.toolbar.add_top_bar(bar)
        self._timer = 0

    def _debounce(self):
        if self._timer:
            GLib.source_remove(self._timer)
        self._timer = GLib.timeout_add(350, lambda: (setattr(self, "_timer", 0), self.reload()) and False)

    def query(self, page=1):
        role = self.roles[self.role_dd.get_selected()][0]
        return {"q": self.search.get_text().strip() or None, "role": role or None, "page": page}

    def fetch(self, done):
        self.page_no = 1
        self.api.get("users", done, query=self.query())

    def render(self, data):
        self.items = list(data.get("data") or [])
        meta = data.get("meta") or {}
        self.pages, self.total = meta.get("pages", 1), meta.get("total", len(self.items))
        if not self.items:
            self.show_empty("tisu-people-symbolic", "Foydalanuvchi topilmadi", "Qidiruv yoki filtrni o'zgartiring.")
            return None
        return self._build()

    def _build(self):
        g = group(None, f"Jami: {self.total}")
        me = (self.win.user or {}).get("id")
        for u in self.items:
            suffix = [pill(self.settings.role_label(u["role"]), "dim")]
            if u["id"] == me:
                suffix.insert(0, pill("Siz", "accent"))
            if not u.get("active", True):
                suffix.append(pill("bloklangan", "error"))
            g.add(row(fmt.name(u), u.get("phone"), icon="avatar-default-symbolic", suffix=suffix,
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
    def __init__(self, win, user, on_changed=None):
        super().__init__(win, fmt.name(user))
        self.user = user
        self.on_changed = on_changed

    def fetch(self, done):
        done({}, None)

    def can_edit(self):
        u, me = self.user, self.win.user or {}
        return u["id"] != me.get("id") and (u["role"] != "SUPER_ADMIN" or me.get("role") == "SUPER_ADMIN")

    def render(self, data):
        u = self.user
        s = self.settings
        self.set_title(fmt.name(u))
        info = group("Ma'lumot")
        info.add(row("Telefon", u.get("phone") or "—", icon="phone-symbolic"))
        info.add(row("Rol", s.role_label(u["role"]), icon="avatar-default-symbolic"))
        info.add(row("Holati", "Faol" if u.get("active", True) else "Bloklangan", icon="dialog-password-symbolic"))

        groups_ = [info]
        if self.can_edit():
            acts = group("Amallar")
            acts.add(row("Tahrirlash", "Ism, familiya, telefon va rol", icon="document-edit-symbolic", on_activate=self.edit))
            acts.add(row("Parol o'rnatish", "Barcha qurilmalardagi sessiyalar yopiladi", icon="dialog-password-symbolic",
                         on_activate=self.set_password))
            active = u.get("active", True)
            acts.add(row("Bloklash" if active else "Faollashtirish",
                         "Foydalanuvchi tizimga kira olmaydi" if active else "Kirishga yana ruxsat beriladi",
                         icon="action-unavailable-symbolic" if active else "object-select-symbolic", on_activate=self.toggle_active))
            groups_.append(acts)
        elif u["id"] == (self.win.user or {}).get("id"):
            note = group(None, "O'z akkauntingizni «Profil» bo'limida tahrirlaysiz.")
            groups_.append(note)

        if u["role"] == "STUDENT" and self.win.has("billing.manage"):
            from .billing import StudentBillingPage

            bg = group()
            bg.add(row("To'lovlar va balans", None, icon="tisu-wallet-symbolic",
                       on_activate=lambda: self.push(StudentBillingPage(self.win, u["id"], fmt.name(u)))))
            groups_.append(bg)

        return prefs_page(*groups_)

    def _patch(self, body, done=None, toast="Saqlandi"):
        def ok(data, err):
            if done:
                done(err)
            elif err:
                self.win.toast(err.message, timeout=5)
            if not err:
                self.user = data.get("user") or dict(self.user, **body)
                self.win.toast(toast)
                self.reload()
                if self.on_changed:
                    self.on_changed()

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
