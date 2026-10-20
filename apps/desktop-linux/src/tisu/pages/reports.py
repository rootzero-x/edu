"""Reports (report.view): overview, attendance by group, students at risk, quizzes; CSV export."""

from gi.repository import Adw, Gio, GLib, Gtk

from .. import fmt
from ..widgets import Page, group, join, pill, prefs_page, row, stat_card

STATUSES = ["PRESENT", "LATE", "ABSENT", "EXCUSED"]


class DateRow(Adw.ActionRow):
    """Action row with a calendar popover; ``value`` is a GLib.DateTime (local midnight)."""

    def __init__(self, title, value, on_change):
        super().__init__(title=title)
        self.value = value
        self.on_change = on_change
        self.calendar = Gtk.Calendar()
        self.button = Gtk.MenuButton(valign=Gtk.Align.CENTER, popover=Gtk.Popover(child=self.calendar))
        self.button.add_css_class("flat")
        self.add_suffix(self.button)
        self.set_activatable_widget(self.button)
        self._sync()
        self.calendar.connect("day-selected", self._picked)  # after _sync: setting the date emits it too

    def _sync(self):
        self.button.set_label(self.value.format("%d.%m.%Y"))
        if hasattr(self.calendar, "set_date"):
            self.calendar.set_date(self.value)
        else:
            self.calendar.select_day(self.value)

    def _picked(self, cal):
        d = cal.get_date()
        self.value = GLib.DateTime.new_local(d.get_year(), d.get_month(), d.get_day_of_month(), 0, 0, 0)
        self.button.set_label(self.value.format("%d.%m.%Y"))
        self.button.popdown()
        self.on_change()


class ReportsPage(Page):
    def __init__(self, win):
        super().__init__(win, win.public.nav_label("/dashboard/reports", "Hisobotlar"))
        days = win.public.int("reports.default_days", 30)
        today = GLib.DateTime.new_now_local()
        today = GLib.DateTime.new_local(today.get_year(), today.get_month(), today.get_day_of_month(), 0, 0, 0)
        self.groups = []
        self.filters = group("Davr va filtr")
        self.from_row = DateRow("Boshlanish", today.add_days(-(days - 1)), self.reload)
        self.to_row = DateRow("Tugash", today, self.reload)
        self.group_row = Adw.ComboRow(title="Guruh", model=Gtk.StringList.new(["Barcha guruhlar"]))
        self.group_row.connect("notify::selected", lambda *_: self._loaded and self.reload())
        for r in (self.from_row, self.to_row, self.group_row):
            self.filters.add(r)
        self.csv_button = Gtk.Button(icon_name="document-save-symbolic", tooltip_text="Davomatni CSV (Excel) ga yuklab olish")
        self.csv_button.connect("clicked", lambda *_: self.export_csv())
        self.header.pack_start(self.csv_button)
        self._content_page = None
        self._load_groups()

    def _load_groups(self):
        def done(data, err):
            if err or not data:
                return
            self.groups = [g for g in data.get("data") or []]
            names = ["Barcha guruhlar"] + [g["name"] for g in self.groups]
            selected = self.group_row.get_selected()
            self.group_row.set_model(Gtk.StringList.new(names))
            self.group_row.set_selected(selected if selected < len(names) else 0)

        self.api.get("groups", done)

    def query(self):
        q = {"from": self.from_row.value.format("%Y-%m-%d"), "to": self.to_row.value.format("%Y-%m-%d")}
        i = self.group_row.get_selected()
        if 0 < i <= len(self.groups):
            q["group_id"] = self.groups[i - 1]["id"]
        return q

    def fetch(self, done):
        if self.from_row.value.compare(self.to_row.value) > 0:
            self.win.toast("Boshlanish sanasi tugash sanasidan keyin bo'lishi mumkin emas.")
        q = self.query()
        cb = join(done, ["overview", "attendance", "?quizzes"])
        self.api.get("reports/overview", cb("overview"), query=q)
        self.api.get("reports/attendance", cb("attendance"), query=q)
        self.api.get("reports/quizzes", cb("?quizzes"), query=q)

    def render(self, data):
        s = self.settings
        ov = data["overview"]
        att = data["attendance"]
        total = att.get("total") or {}

        if self._content_page is not None:
            self._content_page.remove(self.filters)

        flow = Gtk.FlowBox(selection_mode=Gtk.SelectionMode.NONE, column_spacing=12, row_spacing=12,
                           min_children_per_line=2, max_children_per_line=4, homogeneous=True)
        for title, value, icon in [
            (s.text("attendance_rate", "Davomat"), fmt.percent(total.get("rate")), "object-select-symbolic"),
            ("Darslar", ov.get("sessions"), "x-office-calendar-symbolic"),
            ("O'quvchilar", ov.get("students"), "avatar-default-symbolic"),
            ("Guruhlar", ov.get("groups"), "tisu-people-symbolic"),
            ("Yangi o'quvchilar", ov.get("new_students"), "list-add-symbolic"),
            ("Sertifikatlar", ov.get("certificates"), "tisu-award-symbolic"),
            ("Belgilanmagan darslar", total.get("unmarked_sessions"), "alarm-symbolic"),
        ]:
            flow.append(stat_card(title, value if value is not None else "—", icon))
        summary = group(f"{fmt.date(att['period']['from'])} — {fmt.date(att['period']['to'])}")
        summary.add(flow)
        counts = total.get("counts") or {}
        summary.set_description(" · ".join(f"{s.status_label(k)}: {counts.get(k, 0)}" for k in STATUSES))

        by_group = group("Guruhlar bo'yicha davomat")
        for g in att.get("by_group") or []:
            by_group.add(row(g["group"], " · ".join(x for x in [g.get("course"), g.get("teacher"), f"{g.get('sessions')} dars"] if x),
                             icon="tisu-people-symbolic", suffix=_rate_pill(g.get("rate"))))
        if not att.get("by_group"):
            by_group.add(row("Bu davrda davomat ma'lumoti yo'q.", icon="dialog-information-symbolic"))

        risk = group(f"Xavf ostidagi o'quvchilar (davomat < {att.get('risk_threshold')}%)")
        for st in att.get("risk") or []:
            c = st.get("counts") or {}
            sub = " · ".join(x for x in [st.get("phone"), ", ".join(st.get("groups") or []),
                                          f"{s.status_label('ABSENT')} {c.get('ABSENT', 0)}"] if x)
            risk.add(row(st["name"], sub, icon="avatar-default-symbolic", suffix=_rate_pill(st.get("rate"))))
        if not att.get("risk"):
            risk.add(row("Xavf ostida o'quvchi yo'q.", icon="object-select-symbolic"))

        quizzes = group(s.text("quizzes", "Testlar"))
        qd = (data.get("quizzes") or {}).get("data") or []
        for q in qd:
            quizzes.add(row(q["title"], f"{q.get('course')} · {q.get('students')} o'quvchi · {q.get('attempts')} urinish · o'rtacha {q.get('avg_percent')}%",
                            icon="tisu-quiz-symbolic", suffix=_rate_pill(q.get("pass_rate"), "o'tdi ")))
        if not qd:
            quizzes.add(row("Bu davrda test topshirilmagan.", icon="dialog-information-symbolic"))

        page = prefs_page(self.filters, summary, by_group, risk, quizzes)
        self._content_page = page
        return page

    # ── CSV ──────────────────────────────────────────────────
    def export_csv(self):
        q = self.query()
        default = f"davomat_{q['from'].replace('-', '')}_{q['to'].replace('-', '')}.csv"
        dialog = Gtk.FileDialog(title="CSV faylni saqlash", initial_name=default)
        f = Gtk.FileFilter(name="CSV (Excel)")
        f.add_pattern("*.csv")
        filters = Gio.ListStore.new(Gtk.FileFilter)
        filters.append(f)
        dialog.set_filters(filters)

        def chosen(dlg, result):
            try:
                file = dlg.save_finish(result)
            except GLib.Error:
                return  # cancelled
            self.csv_button.set_sensitive(False)
            self.api.get("reports/attendance.csv", lambda data, err: self._write_csv(file, data, err), query=q, raw=True)

        dialog.save(self.win, None, chosen)

    def _write_csv(self, file, data, err):
        if err:
            self.csv_button.set_sensitive(True)
            self.win.toast(err.message, timeout=5)
            return

        def written(f, result, _d):
            self.csv_button.set_sensitive(True)
            try:
                f.replace_contents_finish(result)
            except GLib.Error as e:
                self.win.toast(f"Faylni yozib bo'lmadi: {e.message}", timeout=5)
                return
            toast = Adw.Toast(title=GLib.markup_escape_text(f"Saqlandi: {f.get_basename()}"), button_label="Ochish", timeout=5)
            toast.connect("button-clicked", lambda *_: Gtk.FileLauncher.new(f).launch(self.win, None, None, None))
            self.win.toasts.add_toast(toast)

        file.replace_contents_bytes_async(GLib.Bytes.new(data["bytes"]), None, False,
                                          Gio.FileCreateFlags.REPLACE_DESTINATION, None, written, None)


def _rate_pill(rate, prefix=""):
    if rate is None:
        return pill("—", "dim")
    css = "success" if rate >= 80 else "warning" if rate >= 60 else "error"
    return pill(prefix + fmt.percent(rate), css)

