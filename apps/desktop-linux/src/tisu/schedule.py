"""Weekly schedule editor for groups: rows of weekday + start/end time ("HH:MM")."""

import re

from gi.repository import Gtk

from . import fmt

_TIME = re.compile(r"^([01]\d|2[0-3]):[0-5]\d$")


class ScheduleEditor(Gtk.Box):
    def __init__(self, schedules=None, min_rows=1):
        super().__init__(orientation=Gtk.Orientation.VERTICAL, spacing=6, margin_top=10, margin_bottom=10,
                         margin_start=12, margin_end=12)
        self.min_rows = min_rows
        title = Gtk.Label(label="Haftalik jadval", xalign=0)
        title.add_css_class("heading")
        self.append(title)
        self.rows_box = Gtk.Box(orientation=Gtk.Orientation.VERTICAL, spacing=6)
        self.append(self.rows_box)
        add = Gtk.Button(halign=Gtk.Align.START)
        add.set_child(Gtk.Label(label="+ Kun qo'shish"))
        add.add_css_class("flat")
        add.connect("clicked", lambda *_: self.add_row())
        self.append(add)
        self.rows = []
        for sch in schedules or []:
            self.add_row(sch["weekday"], sch["start_time"][:5], sch["end_time"][:5])
        while len(self.rows) < min_rows:
            self.add_row()

    def add_row(self, weekday=None, start=None, end=None):
        last = self.rows[-1] if self.rows else None
        if weekday is None:
            weekday = min(7, (last["day"].get_selected() + 1 + 2)) if last else 1
            start = last["start"].get_text() if last else "14:00"
            end = last["end"].get_text() if last else "15:30"
        box = Gtk.Box(spacing=6)
        day = Gtk.DropDown(model=Gtk.StringList.new([fmt.weekday(i) for i in range(1, 8)]))
        day.set_selected(int(weekday) - 1)
        s = Gtk.Entry(text=start, max_width_chars=5, width_chars=5, placeholder_text="09:00", input_purpose=Gtk.InputPurpose.DIGITS)
        e = Gtk.Entry(text=end, max_width_chars=5, width_chars=5, placeholder_text="10:30", input_purpose=Gtk.InputPurpose.DIGITS)
        rm = Gtk.Button(icon_name="list-remove-symbolic", tooltip_text="Olib tashlash")
        rm.add_css_class("flat")
        entry = {"box": box, "day": day, "start": s, "end": e}
        rm.connect("clicked", lambda *_: self.remove_row(entry))
        for w in (day, s, Gtk.Label(label="–"), e, rm):
            box.append(w)
        self.rows_box.append(box)
        self.rows.append(entry)

    def remove_row(self, entry):
        if len(self.rows) <= self.min_rows:
            return
        self.rows.remove(entry)
        self.rows_box.remove(entry["box"])

    def value(self):
        """List of {weekday, start_time, end_time}; raises ValueError on a bad time."""
        out = []
        for r in self.rows:
            a, b = r["start"].get_text().strip(), r["end"].get_text().strip()
            for w, v in ((r["start"], a), (r["end"], b)):
                w.remove_css_class("error")
                if not _TIME.match(v):
                    w.add_css_class("error")
                    raise ValueError(f"Vaqt «SS:DD» ko'rinishida bo'lsin (masalan 09:00), «{v}» emas.")
            if b <= a:
                r["end"].add_css_class("error")
                raise ValueError("Dars tugash vaqti boshlanishidan keyin bo'lishi kerak.")
            out.append({"weekday": r["day"].get_selected() + 1, "start_time": a, "end_time": b})
        return out
