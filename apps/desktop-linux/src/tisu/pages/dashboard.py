"""Overview: greeting, role-aware stats, today's lessons and lessons waiting for attendance."""

from gi.repository import Gtk, GLib

from .. import fmt
from ..widgets import Page, group, join, label, pill, prefs_page, row, stat_card
from .groups import SessionSheetPage


class DashboardPage(Page):
    def __init__(self, win):
        super().__init__(win, win.public.nav_label("/dashboard", "Bosh sahifa"))
        self._timer = 0
        # Live like the web overview, but only while the page is on screen.
        self.connect("map", self._start_timer)
        self.connect("unmap", self._stop_timer)

    def _start_timer(self, *_):
        if not self._timer:
            self._timer = GLib.timeout_add_seconds(self.settings.refresh_seconds, self._auto_refresh)

    def _stop_timer(self, *_):
        if self._timer:
            GLib.source_remove(self._timer)
            self._timer = 0

    def _auto_refresh(self):
        if self.stack.get_visible_child_name() == "content" and self.api.token:
            self.reload()
        return True

    def fetch(self, done):
        keys = ["summary"] + (["?unmarked"] if self.win.has("attendance.mark") else [])
        cb = join(done, keys)
        self.api.get("dashboard/summary", cb("summary"))
        if "?unmarked" in keys:
            self.api.get("attendance/unmarked", cb("?unmarked"))

    def render(self, data):
        s = self.settings
        summary = data["summary"]
        stats = summary.get("stats", {})
        user = self.win.user or {}

        hello = group()
        head = Gtk.Box(orientation=Gtk.Orientation.VERTICAL, spacing=4)
        head.append(label(s.text("hello", "Salom, {name}!", name=user.get("first_name") or ""), css=("title-1",)))
        head.append(label(fmt.long_date(fmt.now()), css=("dim-label",)))
        hello.add(head)

        flow = Gtk.FlowBox(selection_mode=Gtk.SelectionMode.NONE, column_spacing=12, row_spacing=12,
                           min_children_per_line=2, max_children_per_line=4, homogeneous=True)
        cards = [
            ("today_sessions", s.text("today_title", "Bugungi darslar"), "x-office-calendar-symbolic", None),
            ("attendance_rate", f"{s.text('attendance_rate', 'Davomat')} ({stats.get('attendance_days', 30)} kun)",
             "object-select-symbolic", "percent"),
            ("courses", s.nav_label("/dashboard/courses", "Kurslar"), "tisu-book-symbolic", None),
            ("groups", s.nav_label("/dashboard/groups", "Guruhlar"), "tisu-people-symbolic", None),
            ("students", "O'quvchilar", "avatar-default-symbolic", None),
            ("unmarked_sessions", "Belgilanmagan darslar", "alarm-symbolic", "warn"),
        ]
        for key, title, icon, kind in cards:
            v = stats.get(key)
            if v is None:
                continue
            css = None
            if kind == "percent":
                v = fmt.percent(v)
            elif kind == "warn":
                css = "warning" if v else "success"
            flow.append(stat_card(title, v, icon, css))
        stats_group = group()
        stats_group.add(flow)

        today = group(s.text("today_title", "Bugungi darslar"))
        sessions = summary.get("today") or []
        if not sessions:
            today.add(row(s.text("today_empty", "Bugun dars yo'q."), icon="x-office-calendar-symbolic"))
        for t in sessions:
            title = f"{fmt.time(t['starts_at'])}–{fmt.time(t['ends_at'])} · {t['group_name']}"
            parts = [t.get("course_title"), t.get("topic"),
                     f"{s.text('room', 'Xona')} {t['room']}" if t.get("room") else None, t.get("teacher")]
            if t.get("cancelled"):
                suffix = pill(s.text("cancelled", "bekor qilindi"), "error")
            elif self.win.has("attendance.mark"):
                done_ = t.get("marked_count", 0) >= t.get("students_count", 0) > 0
                suffix = pill(f"{t.get('marked_count', 0)}/{t.get('students_count', 0)}", "success" if done_ else "dim")
            else:
                suffix = None
            today.add(row(title, " · ".join(p for p in parts if p), on_activate=self._open_session(t["id"], t["group_name"]),
                          suffix=suffix))

        result = [hello, stats_group, today]
        unmarked = (data.get("unmarked") or {}).get("data") if data.get("unmarked") else None
        if unmarked:
            g = group("Davomati belgilanmagan darslar",
                      "O'tgan, lekin davomati to'liq kiritilmagan darslar.")
            for u in unmarked[:15]:
                g.add(row(f"{fmt.day_label(u['starts_at'], s.text('today', 'Bugun'))} {fmt.time(u['starts_at'])} · {u['group']['name']}",
                          None, icon="alarm-symbolic", suffix=pill(f"{u['marked']}/{u['members']}", "warning"),
                          on_activate=self._open_session(u["id"], u["group"]["name"])))
            result.append(g)
        return prefs_page(*result)

    def _open_session(self, session_id, group_name):
        return lambda: self.push(SessionSheetPage(self.win, session_id, group_name, on_saved=self.reload))
