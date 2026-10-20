"""News and announcements with unread markers; opening one marks it read."""

from gi.repository import Adw, Gtk

from .. import fmt
from ..widgets import Page, group, label, link_row, pill, prefs_page, row, text_button


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
            b = Gtk.Button(icon_name="list-add-symbolic", tooltip_text="Yangi e'lon (saytda)")
            b.connect("clicked", lambda *_: win.open_web("/dashboard/news"))
            self.header.pack_start(b)

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
            g = group()
            g.add(link_row("Rasm", a["image_url"], lambda: self.win.open_uri(a["image_url"]), icon="image-x-generic-symbolic"))
            box.append(g)
        clamp = Adw.Clamp(maximum_size=720, child=box)
        return Gtk.ScrolledWindow(child=clamp, hscrollbar_policy=Gtk.PolicyType.NEVER)

    def _read_done(self, data, err):
        if err or not data:
            return
        self.ann["read"] = True
        self.win.app.set_unread(int(data.get("unread") or 0))
        if self.on_read:
            self.on_read(self.ann["id"])

