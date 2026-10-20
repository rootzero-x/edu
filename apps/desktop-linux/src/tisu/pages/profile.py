"""Profile: account info, name editing, Telegram link status, password change, sign out."""

from gi.repository import Adw, Gtk

from .. import fmt
from ..widgets import Page, group, label, link_row, prefs_page, row


class ProfilePage(Page):
    def __init__(self, win):
        super().__init__(win, win.public.nav_label("/dashboard/profile", "Profil"))

    def fetch(self, done):
        self.api.get("auth/me", done)

    def render(self, data):
        s = self.settings
        u = data["user"]
        self.win.set_user(u)

        head = Gtk.Box(orientation=Gtk.Orientation.VERTICAL, spacing=6, margin_bottom=6)
        avatar = Adw.Avatar(size=96, text=fmt.name(u), show_initials=True, halign=Gtk.Align.CENTER)
        head.append(avatar)
        head.append(label(fmt.name(u), css=("title-2",), xalign=0.5))
        head.append(label(s.role_label(u.get("role")), css=("dim-label",), xalign=0.5))
        hg = group()
        hg.add(head)

        info = group("Akkaunt")
        info.add(row("Telefon", u.get("phone") or "—", icon="phone-symbolic"))
        info.add(row(s.text("profile_role", "Rol"), s.role_label(u.get("role")), icon="avatar-default-symbolic"))
        tg = u.get("telegram") or {}
        bot = str(s.get("telegram.bot_username", "")).lstrip("@")
        if tg.get("linked"):
            info.add(row("Telegram", ("@" + tg["username"]) if tg.get("username") else "Ulangan", icon="tisu-telegram-symbolic"))
        elif bot:
            info.add(link_row("Telegram ulanmagan", "Botga o'tib telefon raqamingizni yuboring",
                              lambda: self.win.open_uri(f"https://t.me/{bot}"), icon="tisu-telegram-symbolic"))

        names = group("Ism va familiya", "Telefon raqami va rolni faqat administrator o'zgartiradi.")
        self.first = Adw.EntryRow(title="Ism", text=u.get("first_name") or "", show_apply_button=True)
        self.last = Adw.EntryRow(title="Familiya", text=u.get("last_name") or "", show_apply_button=True)
        for e in (self.first, self.last):
            e.connect("apply", lambda *_: self.save_names())
            names.add(e)

        pw = group("Parol", None if u.get("has_password") else
                   "Siz Telegram orqali ro'yxatdan o'tgansiz — telefon bilan kirish uchun parol o'rnating.")
        self.has_password = bool(u.get("has_password"))
        self.current = Adw.PasswordEntryRow(title="Joriy parol")
        self.new1 = Adw.PasswordEntryRow(title="Yangi parol")
        self.new2 = Adw.PasswordEntryRow(title="Yangi parolni takrorlang")
        if self.has_password:
            pw.add(self.current)
        pw.add(self.new1)
        pw.add(self.new2)
        self.pw_button = Gtk.Button(label="Parolni o'zgartirish" if self.has_password else "Parol o'rnatish",
                                    halign=Gtk.Align.END, margin_top=12)
        self.pw_button.add_css_class("pill")
        self.pw_button.connect("clicked", lambda *_: self.change_password())
        pw_box = Gtk.Box(orientation=Gtk.Orientation.VERTICAL)
        pw_box.append(self.pw_button)
        pw_note = label("Parol o'zgargach boshqa qurilmalardagi sessiyalar yopiladi.", css=("dim-label", "caption"))
        pw_note.set_margin_top(6)
        pw_box.append(pw_note)
        pwg = group()
        pwg.add(pw_box)

        out = group()
        b = Gtk.Button(label=s.text("logout", "Chiqish"), halign=Gtk.Align.CENTER)
        b.add_css_class("pill")
        b.add_css_class("destructive-action")
        b.connect("clicked", lambda *_: self.win.app.confirm_logout())
        out.add(b)
        return prefs_page(hg, info, names, pw, pwg, out)

    def save_names(self):
        first = self.first.get_text().strip()
        if not first:
            self.win.toast("Ism bo'sh bo'lmasin.")
            return

        def done(data, err):
            if err:
                self.win.toast(err.message, timeout=5)
                return
            self.win.set_user(data["user"])
            self.win.toast("Saqlandi")

        self.api.patch("auth/me", {"first_name": first, "last_name": self.last.get_text().strip() or None}, done)

    def change_password(self):
        new = self.new1.get_text()
        if not new:
            self.win.toast("Yangi parolni kiriting.")
            return
        if new != self.new2.get_text():
            self.win.toast("Yangi parollar mos emas.")
            self.new2.add_css_class("error")
            return
        self.new2.remove_css_class("error")
        body = {"new_password": new}
        if self.has_password:
            body["current_password"] = self.current.get_text()
        self.pw_button.set_sensitive(False)

        def done(_data, err):
            self.pw_button.set_sensitive(True)
            if err:
                self.win.toast(err.message, timeout=5)
                return
            for e in (self.current, self.new1, self.new2):
                e.set_text("")
            self.win.toast("Parol o'zgartirildi. Boshqa sessiyalar yopildi.")
            self.reload()

        self.api.post("auth/password", body, done)

