"""Calendar of lessons (week / month) with teacher, room and group filters; rooms management."""

from gi.repository import GLib, Gtk

from .. import fmt
from ..forms import confirm, form
from ..widgets import Page, done_toast, group, icon_button, menu_button, pill, prefs_page, row, text_button
from .groups import SessionSheetPage


def _day(dt):
    return GLib.DateTime.new(fmt.tz(), dt.get_year(), dt.get_month(), dt.get_day_of_month(), 0, 0, 0)


class CalendarPage(Page):
    def __init__(self, win):
        super().__init__(win, win.public.nav_label("/dashboard/calendar", "Taqvim"))
        self.view = "month" if win.public.get("calendar.default_view") == "month" else "week"
        self.anchor = _day(fmt.now())
        self.filters = {"teacher_id": None, "room_id": None, "group_id": None}
        self.filter_opts = {}
        self.selected_day = _day(fmt.now())

        bar = Gtk.Box(spacing=6, margin_start=12, margin_end=12, margin_bottom=6)
        prev = icon_button("go-previous-symbolic", "Oldingi", lambda: self.shift(-1), css=())
        nxt = icon_button("go-next-symbolic", "Keyingi", lambda: self.shift(1), css=())
        today = text_button("Bugun", self.today)
        self.title_lbl = Gtk.Label(hexpand=True, xalign=0)
        self.title_lbl.add_css_class("heading")
        nav = Gtk.Box()
        nav.add_css_class("linked")
        nav.append(prev)
        nav.append(nxt)
        self.view_dd = Gtk.DropDown(model=Gtk.StringList.new(["Hafta", "Oy"]))
        self.view_dd.set_selected(1 if self.view == "month" else 0)
        self.view_dd.connect("notify::selected", lambda *_: self.set_view("month" if self.view_dd.get_selected() == 1 else "week"))
        self.filter_btn = Gtk.MenuButton(icon_name="view-list-bullet-symbolic", tooltip_text="Filtrlar")
        for w in (nav, today, self.title_lbl, self.view_dd, self.filter_btn):
            bar.append(w)
        self.toolbar.add_top_bar(bar)
        if win.has("group.manage"):
            self.header.pack_start(icon_button("go-home-symbolic", "Xonalar", lambda: self.push(RoomsPage(self.win)), css=()))

    # ── Period ───────────────────────────────────────────────
    def period(self):
        a = self.anchor
        if self.view == "week":
            start = a.add_days(-(a.get_day_of_week() - 1))
            return start, start.add_days(6)
        start = GLib.DateTime.new(fmt.tz(), a.get_year(), a.get_month(), 1, 0, 0, 0)
        return start, start.add_months(1).add_days(-1)

    def shift(self, n):
        self.anchor = self.anchor.add_weeks(n) if self.view == "week" else self.anchor.add_months(n)
        self.reload()

    def today(self):
        self.anchor = _day(fmt.now())
        self.selected_day = self.anchor
        self.reload()

    def set_view(self, view):
        if view != self.view:
            self.view = view
            self.reload()

    def fetch(self, done):
        a, b = self.period()
        if self.view == "week":
            self.title_lbl.set_text(f"{a.get_day_of_month()}-{fmt.MONTHS[a.get_month() - 1]} — {b.get_day_of_month()}-{fmt.MONTHS[b.get_month() - 1]} {b.get_year()}")
        else:
            self.title_lbl.set_text(f"{fmt.MONTHS[a.get_month() - 1].capitalize()} {a.get_year()}")
        q = {"from": a.format("%Y-%m-%d"), "to": b.format("%Y-%m-%d")}
        q.update({k: v for k, v in self.filters.items() if v})
        self.api.get("calendar", done, query=q)

    def render(self, data):
        self.filter_opts = data.get("filters") or {}
        self._build_filters()
        sessions = data.get("data") or []
        by_day = {}
        for x in sessions:
            dt = fmt.parse(x["starts_at"])
            by_day.setdefault(dt.format("%Y-%m-%d"), []).append(x)
        self.by_day = by_day
        return self._week(by_day) if self.view == "week" else self._month(by_day)

    def _build_filters(self):
        box = Gtk.Box(orientation=Gtk.Orientation.VERTICAL, spacing=6, margin_top=6, margin_bottom=6, margin_start=6, margin_end=6)
        for key, title, opts_key in (("teacher_id", "O'qituvchi", "teachers"), ("room_id", "Xona", "rooms"), ("group_id", "Guruh", "groups")):
            opts = self.filter_opts.get(opts_key) or []
            if len(opts) < (1 if key == "room_id" else 2):
                continue
            items = [(None, f"Barcha: {title.lower()}")] + [(o["id"], o["name"]) for o in opts]
            dd = Gtk.DropDown(model=Gtk.StringList.new([i[1] for i in items]))
            cur = self.filters.get(key)
            if cur in [i[0] for i in items]:
                dd.set_selected([i[0] for i in items].index(cur))
            dd.connect("notify::selected", lambda d, *_, k=key, it=items: (self.filters.__setitem__(k, it[d.get_selected()][0]), self.reload()))
            box.append(Gtk.Label(label=title, xalign=0, css_classes=["caption-heading"]))
            box.append(dd)
        if not box.get_first_child():
            box.append(Gtk.Label(label="Filtr uchun ma'lumot yo'q"))
        self.filter_btn.set_popover(Gtk.Popover(child=box))
        active = any(self.filters.values())
        self.filter_btn.set_icon_name("edit-find-symbolic" if active else "view-list-bullet-symbolic")

    def _session_row(self, x):
        s = self.settings
        bits = [x.get("course_title"), x.get("room"), x.get("teacher"), x.get("topic")]
        suffix = []
        if x.get("homework_count"):
            suffix.append(pill(f"vazifa {x['homework_count']}", "accent"))
        if x.get("cancelled"):
            suffix.append(pill(s.text("cancelled", "bekor qilindi"), "error"))
        elif x.get("marked_count") is not None and fmt.is_past(x["starts_at"]):
            full = x["marked_count"] >= (x.get("students_count") or 0) > 0
            suffix.append(pill(f"{x['marked_count']}/{x.get('students_count', 0)}", "success" if full else "warning"))
        return row(f"{fmt.time(x['starts_at'])}–{fmt.time(x['ends_at'])} · {x['group_name']}", " · ".join(b for b in bits if b),
                   icon="x-office-calendar-symbolic", suffix=suffix,
                   on_activate=lambda: self.push(SessionSheetPage(self.win, x["id"], x["group_name"], on_saved=self.reload)))

    def _week(self, by_day):
        a, _b = self.period()
        groups_ = []
        today = fmt.now().format("%Y-%m-%d")
        for i in range(7):
            d = a.add_days(i)
            key = d.format("%Y-%m-%d")
            items = by_day.get(key, [])
            title = f"{fmt.weekday(d.get_day_of_week())}, {d.get_day_of_month()}-{fmt.MONTHS[d.get_month() - 1]}"
            g = group(title + (" · bugun" if key == today else ""), f"{len(items)} ta dars" if items else None)
            for x in items:
                g.add(self._session_row(x))
            if not items:
                g.add(row("Dars yo'q", None))
            groups_.append(g)
        return prefs_page(*groups_)

    def _month(self, by_day):
        cal = Gtk.Calendar(show_heading=False, halign=Gtk.Align.CENTER)
        a, _b = self.period()
        sel = self.selected_day if (self.selected_day.get_year(), self.selected_day.get_month()) == (a.get_year(), a.get_month()) else a
        if hasattr(cal, "set_date"):
            cal.set_date(sel)
        else:
            cal.select_day(sel)
        for key in by_day:
            cal.mark_day(int(key[8:10]))
        self.day_group = group()
        self._fill_day(sel)
        cal.connect("day-selected", lambda c: self._picked(c))
        cg = group(None, "Dars bor kunlar belgilangan. Kunni tanlang.")
        cg.add(cal)
        return prefs_page(cg, self.day_group)

    def _picked(self, cal):
        d = cal.get_date()
        self.selected_day = GLib.DateTime.new(fmt.tz(), d.get_year(), d.get_month(), d.get_day_of_month(), 0, 0, 0)
        self._fill_day(self.selected_day)

    def _fill_day(self, d):
        g = self.day_group
        for r in getattr(self, "_day_rows", []):
            g.remove(r)
        self._day_rows = []
        items = self.by_day.get(d.format("%Y-%m-%d"), [])
        g.set_title(GLib.markup_escape_text(fmt.long_date(d)))
        for x in items:
            r = self._session_row(x)
            g.add(r)
            self._day_rows.append(r)
        if not items:
            r = row("Bu kunda dars yo'q", None)
            g.add(r)
            self._day_rows.append(r)


class RoomsPage(Page):
    def __init__(self, win):
        super().__init__(win, "Xonalar")
        self.header.pack_start(icon_button("list-add-symbolic", "Yangi xona", lambda: self.edit(None), css=()))

    def fetch(self, done):
        self.api.get("rooms", done, query={"all": 1})

    def render(self, data):
        rooms = data.get("data") or []
        if not rooms:
            self.show_status("go-home-symbolic", "Xonalar yo'q", "Guruhlarga xona biriktirish uchun xona qo'shing.", "Yangi xona",
                             lambda: self.edit(None))
            return None
        g = group(None, "Guruh jadvalida bir xonada bir vaqtda ikki dars qo'yilmaydi.")
        for r in rooms:
            sub = " · ".join(x for x in [f"{r['capacity']} o'rin" if r.get("capacity") else None, r.get("note"),
                                          f"{r.get('groups_count', 0)} ta faol guruh"] if x)
            sw = Gtk.Switch(active=bool(r.get("active")), valign=Gtk.Align.CENTER, tooltip_text="Faol")
            sw.connect("notify::active", lambda w, *_, r=r: self.api.patch(f"rooms/{r['id']}", {"active": w.get_active()},
                                                                         done_toast(self.win, None)))
            g.add(row(r["name"], sub, icon="go-home-symbolic", suffix=[sw, menu_button([
                ("Tahrirlash", lambda r=r: self.edit(r)), ("O'chirish", lambda r=r: self.delete(r), True)])]))
        return prefs_page(g)

    def edit(self, r):
        cur = r or {}

        def submit(v, done):
            body = {"name": v["name"], "capacity": v["capacity"] or None, "note": v["note"] or None}
            cb = lambda _d, err: (done(err), err is None and self.reload())  # noqa: E731
            if r:
                self.api.patch(f"rooms/{r['id']}", body, cb)
            else:
                self.api.post("rooms", body, cb)

        form(self.win, "Xonani tahrirlash" if r else "Yangi xona", [
            {"name": "name", "label": "Nomi", "value": cur.get("name"), "required": True},
            {"name": "capacity", "label": "Sig'imi (0 — ko'rsatilmagan)", "type": "int", "min": 0, "max": 1000, "value": cur.get("capacity") or 0},
            {"name": "note", "label": "Izoh", "value": cur.get("note")},
        ], submit, submit_label="Saqlash" if r else "Qo'shish")

    def delete(self, r):
        confirm(self.win, "Xona o'chirilsinmi?", f"«{r['name']}». Guruhlar ishlatayotgan xonani o'chirib bo'lmaydi — o'chirib qo'ying (nofaol).",
                "O'chirish", lambda: self.api.delete(f"rooms/{r['id']}", done_toast(self.win, "Xona o'chirildi", lambda _d: self.reload())))

