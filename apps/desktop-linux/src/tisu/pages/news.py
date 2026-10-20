"""News and announcements with unread markers; opening one marks it read."""

from gi.repository import Adw, Gtk

from .. import fmt
from gi.repository import Gdk, GLib

from ..forms import confirm, form
from ..widgets import Page, done_toast, group, icon_button, label, menu_button, pill, prefs_page, row, text_button


def _excerpt(text, n=160):
    text = " ".join((text or "").split())
    return text if len(text) <= n else text[: n - 1] + "…"


def audience_text(s, a):
    if a.get("audience") == "GROUP" and a.get("group"):
        return a["group"]["name"]
    if a.get("audience") == "ROLE" and a.get("audience_role"):
        return s.role_label(a["audience_role"])
    return None


class NewsPage(Page):
    def __init__(self, win):
        super().__init__(win, win.public.get("news.title", win.public.nav_label("/dashboard/news", "Yangiliklar")))
        self.items = []
        self.page_no = 1
        self.pages = 1
        if win.has("announcement.manage"):
            b = Gtk.Button(icon_name="list-add-symbolic", tooltip_text="Yangi e'lon")
            b.connect("clicked", lambda *_: announcement_form(self, None, self.reload))
            self.header.pack_start(b)
            m = Gtk.Button(icon_name="document-edit-symbolic", tooltip_text="E'lonlarni boshqarish (qoralamalar ham)")
            m.connect("clicked", lambda *_: self.push(ManageNewsPage(self.win, on_changed=self.reload)))
            self.header.pack_start(m)

    def fetch(self, done):
        self.page_no = 1
        self.api.get("announcements", done, query={"page": 1})

    def render(self, data):
        self.items = list(data.get("data") or [])
        meta = data.get("meta") or {}
        self.pages = meta.get("pages", 1)
        self.win.app.set_unread(int(meta.get("unread") or 0))
        if not self.items:
            self.show_empty("tisu-bell-symbolic", self.settings.text("news_empty", "Hozircha yangiliklar yo'q."))
            return None
        return self._build()

    def _build(self):
        unread = sum(1 for a in self.items if not a.get("read"))
        g = group(None, f"{unread} ta o'qilmagan" if unread else None)
        for a in self.items:
            g.add(self._row(a))
        more = None
        if self.page_no < self.pages:
            more = group()
            b = text_button("Ko'proq ko'rsatish", self.load_more, css=("pill",))
            b.set_halign(Gtk.Align.CENTER)
            more.add(b)
        return prefs_page(g, more)

    def _row(self, a):
        s = self.settings
        suffix = []
        if a.get("pinned"):
            suffix.append(pill(s.text("news_pinned", "Muhim"), "warning"))
        if not a.get("read"):
            suffix.append(pill(s.text("news_new", "Yangi"), "accent"))
        meta = " · ".join(x for x in [fmt.datetime(a.get("published_at")), audience_text(s, a), a.get("author")] if x)
        r = row(a["title"], f"{meta}\n{_excerpt(a.get('body'))}", icon="mail-unread-symbolic" if not a.get("read") else "tisu-bell-symbolic",
                suffix=suffix, on_activate=lambda: self.push(NewsDetailPage(self.win, a, on_read=self._mark_read)))
        r.set_subtitle_lines(3)
        if not a.get("read"):
            r.add_css_class("tisu-unread")
        return r

    def _mark_read(self, ann_id):
        for a in self.items:
            if a["id"] == ann_id:
                a["read"] = True
        self.set_content(self._build())

    def load_more(self):
        def done(data, err):
            if err:
                self.win.toast(err.message)
                return
            self.page_no += 1
            self.items.extend(data.get("data") or [])
            self.set_content(self._build())

        self.api.get("announcements", done, query={"page": self.page_no + 1})


class NewsDetailPage(Page):
    """Shows one announcement. Opened from the list (full object) or a notification (only id → fetched)."""

    def __init__(self, win, ann, on_read=None):
        super().__init__(win, ann.get("title") or win.public.get("news.title", "Yangiliklar"))
        self.ann = ann
        self.on_read = on_read

    def fetch(self, done):
        if self.ann.get("title"):
            done({"ann": self.ann}, None)
            return

        # Opened from a notification: find it on the first pages of the feed.
        def got(data, err):
            if err:
                done(None, err)
                return
            for a in data.get("data") or []:
                if a["id"] == self.ann["id"]:
                    self.ann = a
                    done({"ann": a}, None)
                    return
            done({"ann": None}, None)

        self.api.get("announcements", got, query={"page": 1})

    def render(self, data):
        a = data.get("ann")
        if not a:
            self.show_empty("tisu-bell-symbolic", "E'lon topilmadi")
            return None
        s = self.settings
        self.set_title(a["title"])
        if not a.get("read"):
            self.api.post(f"announcements/{a['id']}/read", {}, self._read_done)

        box = Gtk.Box(orientation=Gtk.Orientation.VERTICAL, spacing=12, margin_top=24, margin_bottom=24,
                      margin_start=12, margin_end=12)
        tags = Gtk.Box(spacing=6)
        if a.get("pinned"):
            tags.append(pill(s.text("news_pinned", "Muhim"), "warning"))
        aud = audience_text(s, a)
        if aud:
            tags.append(pill(aud, "dim"))
        if tags.get_first_child():
            box.append(tags)
        box.append(label(a["title"], css=("title-2",), selectable=True))
        meta = " · ".join(x for x in [fmt.datetime(a.get("published_at")), a.get("author")] if x)
        box.append(label(meta, css=("dim-label",)))
        body = label(a.get("body") or "", selectable=True)
        body.add_css_class("tisu-body")
        box.append(body)
        if a.get("image_url"):
            pic = Gtk.Picture(can_shrink=True, content_fit=Gtk.ContentFit.CONTAIN, height_request=0)
            pic.add_css_class("card")
            pic.set_visible(False)
            box.insert_child_after(pic, tags if tags.get_first_child() else box.get_first_child())
            load_picture(self.api, a["image_url"], pic)
        clamp = Adw.Clamp(maximum_size=720, child=box)
        return Gtk.ScrolledWindow(child=clamp, hscrollbar_policy=Gtk.PolicyType.NEVER)

    def _read_done(self, data, err):
        if err or not data:
            return
        self.ann["read"] = True
        self.win.app.set_unread(int(data.get("unread") or 0))
        if self.on_read:
            self.on_read(self.ann["id"])



def load_picture(api, url, picture, max_height=360):
    """Download an image and show it inline (hidden when it cannot be loaded)."""

    def got(data):
        if not data:
            return
        try:
            tex = Gdk.Texture.new_from_bytes(GLib.Bytes.new(data))
        except GLib.Error:
            return
        picture.set_paintable(tex)
        picture.set_size_request(-1, min(max_height, tex.get_height()))
        picture.set_visible(True)

    api.fetch_bytes(url, got)


AUDIENCES = [("ALL", "Hammaga"), ("ROLE", "Rol bo'yicha"), ("GROUP", "Guruhga")]
AUDIENCE_ROLES = ["STUDENT", "TEACHER", "ADMIN"]


def announcement_form(page, ann, on_done):
    """Create or edit an announcement. Teachers (non-admins) may only target their own groups."""
    win = page.win
    is_admin = win.role in ("ADMIN", "SUPER_ADMIN")

    def open_with(groups):
        a = ann or {}
        me = (win.user or {}).get("id")
        own = [g for g in groups if is_admin or g.get("teacher_id") == me]
        audiences = AUDIENCES if is_admin else [x for x in AUDIENCES if x[0] == "GROUP"]
        default_aud = a.get("audience") or ("ALL" if is_admin else "GROUP")
        published = bool(a.get("published_at"))
        fields = [
            {"name": "title", "label": "Sarlavha", "value": a.get("title"), "required": True},
            {"name": "body", "label": "Matn", "type": "text", "value": a.get("body"), "height": 180, "required": True},
            {"name": "audience", "label": "Kimga", "type": "choice", "options": audiences, "value": default_aud},
            {"name": "audience_role", "label": "Rol", "type": "choice",
             "options": [(r, win.public.role_label(r)) for r in AUDIENCE_ROLES], "value": a.get("audience_role") or "STUDENT",
             "visible_if": lambda v: v.get("audience") == "ROLE"},
            {"name": "group_id", "label": "Guruh", "type": "choice", "options": [(g["id"], g["name"]) for g in own],
             "value": (a.get("group") or {}).get("id"), "visible_if": lambda v: v.get("audience") == "GROUP"},
            {"name": "image_url", "label": "Rasm manzili (https://…, ixtiyoriy)", "value": a.get("image_url")},
            {"name": "pinned", "label": "Muhim (tepada turadi)", "type": "switch", "value": a.get("pinned")},
            {"name": "notify_telegram", "label": "Telegram orqali xabar yuborish", "type": "switch",
             "value": a.get("notify_telegram", True),
             "hint": "Bu e'lon bo'yicha xabar allaqachon yuborilgan — qayta yuborilmaydi." if a.get("notified_at") else None},
            {"name": "is_public", "label": "Saytning bosh sahifasida ham ko'rsatish", "type": "switch", "value": a.get("is_public"),
             "visible_if": lambda v: v.get("audience") == "ALL"},
        ]
        if not published:
            fields.append({"name": "publish", "label": "Darhol e'lon qilish", "type": "switch", "value": True,
                           "hint": "O'chirilsa — qoralama sifatida saqlanadi"})

        def submit(v, done):
            if v["audience"] == "GROUP" and not v.get("group_id"):
                done(ValueError("Guruhni tanlang."))
                return
            body = {"title": v["title"], "body": v["body"], "image_url": v["image_url"] or None, "audience": v["audience"],
                    "audience_role": v["audience_role"] if v["audience"] == "ROLE" else None,
                    "group_id": v["group_id"] if v["audience"] == "GROUP" else None,
                    "is_public": bool(v.get("is_public")) and v["audience"] == "ALL",
                    "pinned": v["pinned"], "notify_telegram": v["notify_telegram"]}

            def finished(data, err):
                done(err)
                if not err:
                    n = (data or {}).get("notified")
                    win.toast(f"E'lon e'lon qilindi. Telegram: {n} ta qabul qiluvchi" if n else "Saqlandi")
                    on_done()

            if ann is None:
                page.api.post("announcements", dict(body, publish=bool(v.get("publish"))), finished)
            else:
                def patched(data, err):
                    if err or published or not v.get("publish"):
                        finished(data, err)
                        return
                    page.api.post(f"announcements/{ann['id']}/publish", {"published": True}, finished)

                page.api.patch(f"announcements/{ann['id']}", body, patched)

        form(win, "E'lonni tahrirlash" if ann else "Yangi e'lon", fields, submit, width=620)

    page.api.get("groups", lambda data, err: (win.toast(err.message) if err else open_with((data or {}).get("data") or [])))


class ManageNewsPage(Page):
    def __init__(self, win, on_changed=None):
        super().__init__(win, "E'lonlarni boshqarish")
        self.on_changed = on_changed
        self.items = []
        self.page_no = 1
        self.pages = 1
        self.header.pack_start(icon_button("list-add-symbolic", "Yangi e'lon", lambda: announcement_form(self, None, self.changed), css=()))

    def changed(self):
        self.reload()
        if self.on_changed:
            self.on_changed()

    def fetch(self, done):
        self.page_no = 1
        self.api.get("announcements", done, query={"scope": "manage", "page": 1})

    def render(self, data):
        self.items = list(data.get("data") or [])
        self.pages = (data.get("meta") or {}).get("pages", 1)
        if not self.items:
            self.show_status("tisu-bell-symbolic", "E'lonlar yo'q", "Birinchi e'lonni yarating.", "Yangi e'lon",
                             lambda: announcement_form(self, None, self.changed))
            return None
        return self._build()

    def _build(self):
        s = self.settings
        g = group(None, "Siz yaratgan e'lonlar" if self.win.role not in ("ADMIN", "SUPER_ADMIN") else "Barcha e'lonlar")
        for a in self.items:
            published = bool(a.get("published_at"))
            suffix = [pill("e'lon qilingan" if published else "qoralama", "success" if published else "dim")]
            if a.get("is_public"):
                suffix.append(pill("bosh sahifada", "accent"))
            suffix.append(menu_button([
                ("Yashirish" if published else "E'lon qilish", lambda a=a: self.toggle(a)),
                ("Tahrirlash", lambda a=a: announcement_form(self, a, self.changed)),
                ("O'chirish", lambda a=a: self.delete(a), True),
            ]))
            meta = " · ".join(x for x in [fmt.datetime(a.get("published_at")) if published else None,
                                          audience_text(s, a) or "Hammaga", a.get("author")] if x)
            g.add(row(a["title"], meta, icon="tisu-bell-symbolic", suffix=suffix, chevron=False,
                      on_activate=lambda a=a: announcement_form(self, a, self.changed)))
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

        self.api.get("announcements", done, query={"scope": "manage", "page": self.page_no + 1})

    def toggle(self, a):
        publish = not a.get("published_at")

        def ok(data, err):
            if err:
                self.win.toast(err.message, timeout=6)
                return
            n = data.get("notified") or 0
            self.win.toast(("E'lon qilindi" + (f". Telegram: {n} ta qabul qiluvchi" if n else "")) if publish else "Yashirildi")
            self.changed()

        self.api.post(f"announcements/{a['id']}/publish", {"published": publish}, ok)

    def delete(self, a):
        confirm(self.win, "E'lon o'chirilsinmi?", a["title"], "O'chirish",
                lambda: self.api.delete(f"announcements/{a['id']}", done_toast(self.win, "E'lon o'chirildi", lambda _d: self.changed())))
