"""Sign-in: Telegram deep-link (with polling) or phone + password."""

import re

from gi.repository import Adw, Gdk, GLib, Gtk

from .. import const
from ..widgets import label, spinner


class LoginView(Adw.Bin):
    def __init__(self, win, on_logged_in):
        super().__init__()
        self.win = win
        self.api = win.api
        self.s = win.public
        self.on_logged_in = on_logged_in
        self._poll_id = 0
        self._tick_id = 0
        self._tg = None

        toolbar = Adw.ToolbarView()
        header = Adw.HeaderBar()
        header.add_css_class("flat")
        header.set_title_widget(Gtk.Label())  # keep the header empty, the page has its own title
        header.pack_end(win.make_primary_menu_button(logged_in=False))
        toolbar.add_top_bar(header)

        self.stack = Gtk.Stack(transition_type=Gtk.StackTransitionType.SLIDE_LEFT_RIGHT)
        self.stack.add_named(self._build_form(), "form")
        self.stack.add_named(self._build_waiting(), "telegram")
        toolbar.set_content(self.stack)
        self.set_child(toolbar)

        self.s.connect_changed(self.update_texts)
        self.update_texts()

    # ── UI ───────────────────────────────────────────────────
    def _build_form(self):
        box = Gtk.Box(orientation=Gtk.Orientation.VERTICAL, spacing=18, margin_top=12, margin_bottom=24,
                      margin_start=12, margin_end=12)

        icon = Gtk.Image.new_from_icon_name(const.APP_ID)
        icon.set_pixel_size(96)
        icon.add_css_class("icon-dropshadow")
        box.append(icon)

        self.title = label("", css=("title-1",), xalign=0.5)
        self.subtitle = label("", css=("dim-label",), xalign=0.5)
        head = Gtk.Box(orientation=Gtk.Orientation.VERTICAL, spacing=6)
        head.append(self.title)
        head.append(self.subtitle)
        box.append(head)

        self.tg_button = Gtk.Button(halign=Gtk.Align.CENTER)
        self.tg_button.add_css_class("pill")
        self.tg_button.add_css_class("suggested-action")
        self.tg_button_content = Adw.ButtonContent(icon_name="tisu-telegram-symbolic", label="")
        self.tg_button.set_child(self.tg_button_content)
        self.tg_button.connect("clicked", lambda *_: self.start_telegram())
        box.append(self.tg_button)

        sep = Gtk.Box(spacing=12, margin_top=6)
        for _ in range(2):
            s = Gtk.Separator(hexpand=True, valign=Gtk.Align.CENTER)
            sep.append(s)
        sep.insert_child_after(label("yoki", css=("dim-label", "caption"), xalign=0.5, wrap=False), sep.get_first_child())
        box.append(sep)

        self.pw_group = Adw.PreferencesGroup()
        self.phone = Adw.EntryRow(title="Telefon raqami", input_purpose=Gtk.InputPurpose.PHONE)
        self.phone.set_text(self.win.prefs.get_string("last-phone") or "+998")
        self.password = Adw.PasswordEntryRow(title="Parol")
        self.phone.connect("entry-activated", lambda *_: self.password.grab_focus())
        self.password.connect("entry-activated", lambda *_: self.login_password())
        for e in (self.phone, self.password):
            e.connect("changed", lambda *_: self._set_error(None))
        self.pw_group.add(self.phone)
        self.pw_group.add(self.password)
        box.append(self.pw_group)

        self.error = label("", css=("error",), xalign=0.5)
        self.error.set_visible(False)
        box.append(self.error)

        self.login_button = Gtk.Button(halign=Gtk.Align.CENTER)
        self.login_button.add_css_class("pill")
        self.login_button.connect("clicked", lambda *_: self.login_password())
        box.append(self.login_button)

        self.register_group = Adw.PreferencesGroup(margin_top=12)
        self.register_row = Adw.ActionRow(activatable=True)
        self.register_row.add_prefix(Gtk.Image.new_from_icon_name("tisu-people-symbolic"))
        self.register_row.add_suffix(Gtk.Image.new_from_icon_name("tisu-external-link-symbolic"))
        self.register_row.connect("activated", lambda *_: self.open_register())
        self.register_group.add(self.register_row)
        box.append(self.register_group)

        clamp = Adw.Clamp(maximum_size=420, child=box, valign=Gtk.Align.CENTER)
        return Gtk.ScrolledWindow(child=clamp, hscrollbar_policy=Gtk.PolicyType.NEVER)

    def _build_waiting(self):
        self.wait_page = Adw.StatusPage(icon_name="tisu-telegram-symbolic")
        box = Gtk.Box(orientation=Gtk.Orientation.VERTICAL, spacing=12, halign=Gtk.Align.CENTER)
        box.append(spinner(32))
        self.wait_countdown = label("", css=("dim-label", "numeric"), xalign=0.5)
        box.append(self.wait_countdown)

        buttons = Gtk.Box(spacing=12, halign=Gtk.Align.CENTER, margin_top=12)
        reopen = Gtk.Button(label="Telegram'ni qayta ochish")
        reopen.add_css_class("pill")
        reopen.add_css_class("suggested-action")
        reopen.connect("clicked", lambda *_: self._tg and self.win.open_uri(self._tg["bot_url"]))
        copy = Gtk.Button(icon_name="edit-copy-symbolic", tooltip_text="Havolani nusxalash")
        copy.add_css_class("circular")
        copy.connect("clicked", lambda *_: self._copy_link())
        buttons.append(reopen)
        buttons.append(copy)
        box.append(buttons)

        cancel = Gtk.Button(label="Bekor qilish", halign=Gtk.Align.CENTER)
        cancel.add_css_class("pill")
        cancel.connect("clicked", lambda *_: self.cancel_telegram())
        box.append(cancel)
        self.wait_page.set_child(box)
        return self.wait_page

    def update_texts(self):
        s = self.s
        self.title.set_text(s.get("login.title", "Xush kelibsiz"))
        self.subtitle.set_text(s.get("login.subtitle", "Telegram orqali bir zumda kiring — parol shart emas."))
        self.tg_button_content.set_label(s.get("login.telegram_button", "Telegram orqali kirish"))
        self.pw_group.set_title(GLib.markup_escape_text(s.get("login.password_title", "Telefon va parol bilan kirish")))
        self.pw_group.set_description(GLib.markup_escape_text(s.get("login.subtitle_password", "")) or None)
        self.login_button.set_label(s.get("site.nav_login", "Kirish"))
        self.register_row.set_title(GLib.markup_escape_text(s.get("login.register_title", "Ro'yxatdan o'tish")))
        self.register_row.set_subtitle(GLib.markup_escape_text(s.get("login.register_text", "")))
        self.wait_page.set_title(s.get("login.telegram_button", "Telegram orqali kirish"))
        self.wait_page.set_description(GLib.markup_escape_text(
            s.get("login.waiting_text", "Telegram'da botni oching, «Start» ni bosing va kirishni tasdiqlang.")))

    def reset(self):
        self.cancel_telegram()
        self.password.set_text("")
        self._set_error(None)
        self._busy(False)

    def _set_error(self, text):
        self.error.set_text(text or "")
        self.error.set_visible(bool(text))

    def _busy(self, busy):
        for w in (self.login_button, self.tg_button, self.phone, self.password):
            w.set_sensitive(not busy)

    # ── Phone + password ─────────────────────────────────────
    def login_password(self):
        phone = re.sub(r"[\s\-()]", "", self.phone.get_text())
        password = self.password.get_text()
        if not phone or not password:
            self._set_error("Telefon raqami va parolni kiriting.")
            return
        self._busy(True)
        self._set_error(None)

        def done(data, err):
            self._busy(False)
            if err:
                self._set_error(err.message)
                self.password.grab_focus()
                return
            self.win.prefs.set_string("last-phone", phone)
            self.password.set_text("")
            self.on_logged_in(data["token"], data.get("user"))

        self.api.post("auth/login", {"phone": phone, "password": password, "device": "linux-desktop"}, done)

    # ── Telegram deep-link ───────────────────────────────────
    def start_telegram(self):
        self._busy(True)
        self._set_error(None)

        def done(data, err):
            self._busy(False)
            if err:
                self._set_error(err.message)
                return
            self._tg = dict(data, left=int(data.get("expires_in") or 300))
            self.stack.set_visible_child_name("telegram")
            self.win.open_uri(data["bot_url"])
            self._update_countdown()
            self._tick_id = GLib.timeout_add_seconds(1, self._tick)
            self._poll_id = GLib.timeout_add_seconds(2, self._poll)

        self.api.post("auth/telegram/start", {}, done)

    def _tick(self):
        if not self._tg:
            return False
        self._tg["left"] -= 1
        self._update_countdown()
        if self._tg["left"] <= 0:
            self._expired()
            return False
        return True

    def _update_countdown(self):
        left = max(0, self._tg["left"]) if self._tg else 0
        self.wait_countdown.set_text(f"Havola {left // 60}:{left % 60:02d} davomida amal qiladi")

    def _poll(self):
        tg = self._tg
        if not tg:
            return False

        def done(data, err):
            if self._tg is not tg:
                return  # cancelled or restarted meanwhile
            if err:
                if err.status == 404:
                    self._expired()
                return  # transient error: keep polling
            status = data.get("status")
            if status == "ok" and data.get("token"):
                self._stop_timers()
                self._tg = None
                self.stack.set_visible_child_name("form")
                self.win.toast(self.s.get("login.success_text", "Tasdiqlandi!"))
                self.on_logged_in(data["token"], data.get("user"))
            elif status == "expired":
                self._expired()

        self.api.post("auth/telegram/poll", {"code": tg["code"], "poll_secret": tg["poll_secret"]}, done)
        return True

    def _expired(self):
        self.cancel_telegram()
        self._set_error("Havola muddati tugadi. Qaytadan urinib ko'ring.")

    def _stop_timers(self):
        for attr in ("_poll_id", "_tick_id"):
            sid = getattr(self, attr)
            if sid:
                GLib.source_remove(sid)
                setattr(self, attr, 0)

    def cancel_telegram(self):
        self._stop_timers()
        self._tg = None
        self.stack.set_visible_child_name("form")

    def _copy_link(self):
        if self._tg:
            Gdk.Display.get_default().get_clipboard().set(self._tg["bot_url"])
            self.win.toast("Havola nusxalandi")

    def open_register(self):
        bot = str(self.s.get("telegram.bot_username", "")).lstrip("@")
        if bot:
            self.win.open_uri(f"https://t.me/{bot}")
        else:
            self.win.open_uri(self.s.web_url + "/login")
