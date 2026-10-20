"""Notifications centre: inbox (everyone) and the Telegram delivery report (user.manage)."""

from gi.repository import GLib, Gtk

from .. import fmt
from ..csvexport import save_csv
from ..widgets import Page, group, icon_button, join, pill, prefs_page, row, stat_card, text_button
from .reports import DateRow

ROLES = ["STUDENT", "TEACHER", "ADMIN", "SUPER_ADMIN"]


def delivery_label(s, d):
    labels = s.get("notifications.delivery_labels", {})
    defaults = {"sent": "yuborildi", "failed": "xato", "not_linked": "Telegram ulanmagan", "blocked": "botni bloklagan",
                "skipped": "o'tkazib yuborildi", "not_configured": "bot sozlanmagan"}
    return (labels.get(d) if isinstance(labels, dict) else None) or defaults.get(d, d)


class NotificationsPage(Page):
    def __init__(self, win):
        super().__init__(win, win.public.nav_label("/dashboard/notifications", "Bildirishnomalar"))
        self.items = []
        self.page_no = 1
        self.pages = 1
        self.only_unread = Gtk.ToggleButton(label="Faqat o'qilmaganlar")
        self.only_unread.connect("toggled", lambda *_: self.reload())
        self.header.pack_start(self.only_unread)
        self.header.pack_start(icon_button("object-select-symbolic", "Hammasini o'qildi deb belgilash", self.read_all, css=()))
        if win.has("user.manage"):
            self.header.pack_start(icon_button("tisu-chart-symbolic", "Yetkazish hisoboti",
                                               lambda: self.push(DeliveryReportPage(self.win)), css=()))

    def fetch(self, done):
        self.page_no = 1
        self.api.get("notifications", done, query={"unread": 1 if self.only_unread.get_active() else None, "page": 1})

    def render(self, data):
        self.items = list(data.get("data") or [])
        meta = data.get("meta") or {}
        self.pages = meta.get("pages", 1)
        self.win.app.set_inbox_unread(int(meta.get("unread") or 0), meta.get("news_unread"))
        if not self.items:
            self.show_empty("tisu-bell-symbolic", "Bildirishnomalar yo'q" if not self.only_unread.get_active() else "Hammasi o'qilgan")
            return None
        return self._build()

    def _build(self):
        s = self.settings
        g = group()
        for n in self.items:
            suffix = [pill(n.get("label") or n.get("type") or "", "dim")]
            d = n.get("delivery")
            if d and d != "sent":
                suffix.append(pill("Telegram: " + delivery_label(s, d), "error" if d in ("failed", "blocked") else "warning"))
            r = row(n.get("text") or "", fmt.datetime(n.get("created_at")), icon="mail-unread-symbolic" if not n.get("read") else "tisu-bell-symbolic",
                    suffix=suffix, on_activate=lambda n=n: self.open_item(n))
            r.set_title_lines(3)
            if not n.get("read"):
                r.add_css_class("tisu-unread")
            g.add(r)
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

        self.api.get("notifications", done, query={"unread": 1 if self.only_unread.get_active() else None, "page": self.page_no + 1})

    def open_item(self, n):
        if not n.get("read"):
            n["read"] = True
            self.api.post("notifications/read", {"ids": [n["id"]]},
                          lambda d, e: d and self.win.app.set_inbox_unread(int(d.get("unread") or 0), d.get("news_unread")))
        if n.get("url"):
            self.win.navigate(n["url"])
        else:
            self.set_content(self._build())

    def read_all(self):
        def ok(d, err):
            if err:
                self.win.toast(err.message)
                return
            self.win.app.set_inbox_unread(int(d.get("unread") or 0), d.get("news_unread"))
            self.win.toast("Hammasi o'qildi")
            self.reload()

        self.api.post("notifications/read", {"all": True}, ok)


class DeliveryReportPage(Page):
    def __init__(self, win):
        super().__init__(win, "Yetkazish hisoboti")
        today = GLib.DateTime.new_now_local()
        today = GLib.DateTime.new_local(today.get_year(), today.get_month(), today.get_day_of_month(), 0, 0, 0)
        self.filters = group("Davr")
        self.from_row = DateRow("Boshlanish", today.add_days(-29), self.reload)
        self.to_row = DateRow("Tugash", today, self.reload)
        self.filters.add(self.from_row)
        self.filters.add(self.to_row)
        self._page = None
        self.role = None
        self.reason = None

    def fetch(self, done):
        q = {"from": self.from_row.value.format("%Y-%m-%d"), "to": self.to_row.value.format("%Y-%m-%d")}
        cb = join(done, ["report", "unreachable"])
        self.api.get("notifications/report", cb("report"), query=q)
        self.api.get("notifications/unreachable", cb("unreachable"), query={"role": self.role, "reason": self.reason})

    def render(self, data):
        s = self.settings
        if self._page is not None:
            self._page.remove(self.filters)
        r = data["report"]
        t = r.get("total") or {}
        flow = Gtk.FlowBox(selection_mode=Gtk.SelectionMode.NONE, column_spacing=12, row_spacing=12,
                           min_children_per_line=2, max_children_per_line=4, homogeneous=True)
        flow.append(stat_card("Yetkazildi", fmt.percent(t.get("delivered_percent")), "object-select-symbolic", "success"))
        for x in r.get("reach") or []:
            flow.append(stat_card(f"Telegramda: {s.role_label(x['role'])}", f"{fmt.percent(x.get('percent'))} ({x.get('reachable')}/{x.get('total')})",
                                  "tisu-telegram-symbolic"))
        sg = group(None, " · ".join(f"{delivery_label(s, k)}: {v}" for k, v in (t.get("counts") or {}).items()))
        sg.add(flow)
        kg = group("Turlari bo'yicha")
        for k in r.get("by_kind") or []:
            kg.add(row(k.get("label") or k.get("kind"), " · ".join(f"{delivery_label(s, a)} {b}" for a, b in (k.get("counts") or {}).items()),
                       suffix=pill(fmt.percent(k.get("delivered_percent")), "success" if (k.get("delivered_percent") or 0) >= 80 else "warning")))

        un = data["unreachable"]
        role_opts = [(None, "Barcha rollar")] + [(x, s.role_label(x)) for x in ROLES]
        reason_opts = [(None, "Barcha sabablar"), ("not_linked", delivery_label(s, "not_linked")), ("blocked", delivery_label(s, "blocked"))]
        bar = Gtk.Box(spacing=6)
        for opts, attr in ((role_opts, "role"), (reason_opts, "reason")):
            dd = Gtk.DropDown(model=Gtk.StringList.new([o[1] for o in opts]))
            vals = [o[0] for o in opts]
            dd.set_selected(vals.index(getattr(self, attr)) if getattr(self, attr) in vals else 0)
            dd.connect("notify::selected", lambda d, *_, a=attr, o=opts: (setattr(self, a, o[d.get_selected()][0]), self.reload()))
            bar.append(dd)
        csv = icon_button("document-save-symbolic", "CSV ga yuklab olish", lambda: save_csv(
            self.win, self.api, "notifications/unreachable.csv", {"role": self.role, "reason": self.reason},
            f"xabar_yetmaydiganlar_{fmt.now().format('%Y%m%d')}.csv"), css=())
        bar.append(csv)
        ug = group(f"Xabar yetmaydiganlar ({(un.get('meta') or {}).get('total', 0)})", suffix=bar)
        for u in un.get("data") or []:
            ug.add(row(u.get("name"), " · ".join(x for x in [u.get("phone"), s.role_label(u.get("role")), ", ".join(u.get("groups") or [])] if x),
                       icon="avatar-default-symbolic", suffix=pill(delivery_label(s, u.get("reason")), "warning"),
                       on_activate=lambda u=u: self._profile(u)))
        if not un.get("data"):
            ug.add(row("Hamma Telegramga ulangan.", icon="object-select-symbolic"))
        page = prefs_page(self.filters, sg, kg, ug)
        self._page = page
        return page

    def _profile(self, u):
        from .users import UserPage

        self.push(UserPage(self.win, {"id": u["id"], "first_name": u.get("name"), "role": u.get("role")}))

