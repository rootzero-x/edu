"""Groups, group detail, lesson list and the attendance sheet."""

from gi.repository import Adw, GLib, Gtk

from .. import fmt
from ..widgets import Page, group, join, label, link_row, pill, prefs_page, row, status_css, text_button

STATUSES = ["PRESENT", "LATE", "ABSENT", "EXCUSED"]


def schedule_text(schedules):
    if not schedules:
        return None
    by_time = {}
    for sch in sorted(schedules, key=lambda x: (x["weekday"], x["start_time"])):
        by_time.setdefault((sch["start_time"], sch["end_time"]), []).append(fmt.weekday(sch["weekday"]))
    return "; ".join(f"{', '.join(days)} {a}–{b}" for (a, b), days in by_time.items())


class GroupsPage(Page):
    def __init__(self, win):
        super().__init__(win, win.public.nav_label("/dashboard/groups", "Guruhlar"))

    def fetch(self, done):
        self.api.get("groups", done)

    def render(self, data):
        groups = data.get("data") or []
        if not groups:
            self.show_empty("tisu-people-symbolic", "Guruhlar yo'q", "Siz hali birorta guruhga biriktirilmagansiz.")
            return None
        active = group("Faol guruhlar")
        inactive = group("Yakunlangan / nofaol")
        students_word = self.settings.text("students", "o'quvchi")
        for g in groups:
            parts = [(g.get("course") or {}).get("title"), fmt.name(g.get("teacher")),
                     f"{g.get('students_count', 0)} {students_word}", schedule_text(g.get("schedules"))]
            target = active if g.get("active") else inactive
            target.add(row(g["name"], " · ".join(p for p in parts if p and p != "—"), icon="tisu-people-symbolic",
                           on_activate=lambda g=g: self.push(GroupPage(self.win, g["id"], g["name"]))))
        return prefs_page(active if active.get_first_child() else None,
                          inactive if any(not g.get("active") for g in groups) else None)


class GroupPage(Page):
    def __init__(self, win, group_id, name):
        super().__init__(win, name)
        self.group_id = group_id

    def fetch(self, done):
        today = fmt.now()
        cb = join(done, ["group", "sessions", "?report"])
        self.api.get(f"groups/{self.group_id}", cb("group"))
        self.api.get(f"groups/{self.group_id}/sessions", cb("sessions"), query={
            "from": today.add_days(-45).format("%Y-%m-%d"), "to": today.add_days(21).format("%Y-%m-%d")})
        self.api.get(f"groups/{self.group_id}/attendance", cb("?report"))

    def render(self, data):
        s = self.settings
        g = data["group"]["group"]
        self.set_title(g["name"])

        info = group("Ma'lumot")
        teacher = g.get("teacher") or {}
        info.add(row(s.text("teacher", "O'qituvchi"), fmt.name(teacher) + (f" · {teacher['phone']}" if teacher.get("phone") else "")))
        if g.get("course"):
            info.add(row("Kurs", g["course"]["title"]))
        if g.get("branch"):
            info.add(row("Filial", g["branch"]["name"]))
        if g.get("room"):
            info.add(row(s.text("room", "Xona"), g["room"]))
        dates = fmt.date(g.get("start_date")) + (f" — {fmt.date(g['end_date'])}" if g.get("end_date") else "")
        info.add(row("Muddati", dates))
        sch = schedule_text(g.get("schedules"))
        if sch:
            info.add(row(s.text("weekly", "Haftalik jadval"), sch))
        if self.win.has("group.manage"):
            info.add(link_row("Sertifikatlar va boshqaruv", "Saytdagi boshqaruv panelida ochiladi",
                              lambda: self.win.open_web(f"/dashboard/groups/{g['id']}"), icon="tisu-award-symbolic"))

        sessions = data["sessions"].get("data") or []
        past = [x for x in sessions if fmt.is_past(x["starts_at"])]
        upcoming = [x for x in sessions if not fmt.is_past(x["starts_at"])]
        all_button = text_button("Barchasi", lambda: self.push(AllSessionsPage(self.win, g)), css=("flat",))
        students_count = len(g.get("students") or [])
        soon = group(s.text("upcoming", "Yaqin darslar"), suffix=all_button)
        for x in upcoming[:3]:
            soon.add(self._session_row(x, g, students_count))
        if not upcoming:
            soon.add(row(s.text("schedule_empty", "Yaqin kunlarda dars yo'q."), icon="x-office-calendar-symbolic"))
        recent = group("O'tgan darslar")
        for x in list(reversed(past))[:8]:
            recent.add(self._session_row(x, g, students_count))

        students = group(f"O'quvchilar ({students_count})" if self.win.has("attendance.mark") else s.text("attendance_rate", "Davomat"))
        phones = {st["id"]: st.get("phone") for st in g.get("students") or []}
        report = (data.get("report") or {}).get("data") or []
        if report:
            for r in report:
                c = r.get("counts", {})
                sub = " · ".join(f"{s.status_label(k)} {c.get(k, 0)}" for k in STATUSES)
                if phones.get(r["student_id"]):
                    sub = f"{phones[r['student_id']]} · {sub}"
                rate = r.get("rate")
                students.add(row(r["name"], sub, icon="avatar-default-symbolic",
                                 suffix=pill(fmt.percent(rate), "success" if (rate or 0) >= 80 else "warning" if (rate or 0) >= 60 else "error")))
        else:
            for st in g.get("students") or []:
                students.add(row(fmt.name(st), st.get("phone"), icon="avatar-default-symbolic"))
            if not g.get("students"):
                students.add(row(s.text("sheet_empty", "Guruhda o'quvchi yo'q."), icon="avatar-default-symbolic"))
        return prefs_page(info, soon, recent if past else None, students)

    def _session_row(self, x, g, students_count):
        return session_row(self, x, g["name"], students_count, on_saved=self.reload)


def session_row(page, x, group_name, students_count, on_saved=None):
    s = page.settings
    day = fmt.day_label(x["starts_at"], s.text("today", "Bugun"), s.text("tomorrow", "Ertaga"))
    if "." not in day:  # "Bugun" / "Ertaga" → add the date
        day = f"{day} ({fmt.parse(x['starts_at']).format('%d.%m')})"
    title = f"{day} · {fmt.time(x['starts_at'])}–{fmt.time(x['ends_at'])}"
    if x.get("cancelled"):
        suffix = pill(s.text("cancelled", "bekor qilindi"), "error")
    elif fmt.is_past(x["starts_at"]):
        marked = x.get("marked_count", 0)
        full = students_count and marked >= students_count
        suffix = pill(f"{marked}/{students_count}" if students_count else str(marked), "success" if full else "warning" if page.win.has("attendance.mark") else "dim")
    else:
        suffix = None
    sub = x.get("topic")
    return row(title, sub, icon="x-office-calendar-symbolic", suffix=suffix,
               on_activate=lambda: page.push(SessionSheetPage(page.win, x["id"], group_name, on_saved=on_saved)))


class AllSessionsPage(Page):
    def __init__(self, win, g):
        super().__init__(win, f"{g['name']} — darslar")
        self.g = g

    def fetch(self, done):
        self.api.get(f"groups/{self.g['id']}/sessions", done)

    def render(self, data):
        sessions = data.get("data") or []
        if not sessions:
            self.show_empty("x-office-calendar-symbolic", "Darslar yo'q", "Bu guruh uchun hali darslar yaratilmagan.")
            return None
        n = len(self.g.get("students") or [])
        months = {}
        for x in reversed(sessions):
            dt = fmt.parse(x["starts_at"])
            key = (dt.get_year(), dt.get_month())
            months.setdefault(key, []).append(x)
        groups_ = []
        for (y, m), items in months.items():
            gr = group(f"{fmt.MONTHS[m - 1].capitalize()} {y}")
            for x in items:
                gr.add(session_row(self, x, self.g["name"], n, on_saved=self.reload))
            groups_.append(gr)
        return prefs_page(*groups_)


class SessionSheetPage(Page):
    """Attendance sheet of one lesson. Edit rights, deadline and reason rules come from the API (``edit``)."""

    def __init__(self, win, session_id, group_name, on_saved=None):
        super().__init__(win, group_name)
        self.session_id = session_id
        self.on_saved = on_saved
        self.title_widget = Adw.WindowTitle(title=group_name)
        self.header.set_title_widget(self.title_widget)
        self.save_button = Gtk.Button(label=win.public.text("sheet_save", "Saqlash"))
        self.save_button.add_css_class("suggested-action")
        self.save_button.connect("clicked", lambda *_: self.save())
        self.save_button.set_visible(False)
        self.header.pack_end(self.save_button)
        self._history_button = Gtk.Button(icon_name="document-open-recent-symbolic", tooltip_text="O'zgarishlar tarixi")
        self._history_button.connect("clicked", lambda *_: self.show_history())
        self._history_button.set_visible(False)
        self.header.pack_end(self._history_button)
        self.rows = []
        self.sheet = None
        self._split_handler = win.split.connect("notify::collapsed", lambda *_: self._apply_orientation())
        self.connect("hidden", self._on_hidden)

    def _on_hidden(self, *_):
        if self._split_handler and self.get_parent() is None:
            self.win.split.disconnect(self._split_handler)
            self._split_handler = 0

    def fetch(self, done):
        self.api.get(f"sessions/{self.session_id}/attendance", done)

    def render(self, data):
        s = self.settings
        self.sheet = data
        sess = data["session"]
        edit = data.get("edit") or {}
        self.can_edit = bool(edit.get("can_edit"))
        self.reason_required = bool(edit.get("reason_required"))
        self.title_widget.set_title(data["group"]["name"])
        self.title_widget.set_subtitle(f"{fmt.date(sess['starts_at'])} · {fmt.time(sess['starts_at'])}–{fmt.time(sess['ends_at'])}")
        self.save_button.set_visible(self.can_edit)
        self.save_button.set_sensitive(False)
        self._history_button.set_visible(self.win.has("attendance.mark"))

        groups_ = []
        notes = []
        if sess.get("cancelled"):
            notes.append(("dialog-warning-symbolic", s.text("cancelled", "bekor qilindi").capitalize()))
        if edit.get("message") and not self.can_edit:
            notes.append(("dialog-password-symbolic", edit["message"]))
        if self.can_edit and edit.get("deadline"):
            notes.append(("alarm-symbolic", f"O'qituvchi {fmt.datetime(edit['deadline'])} gacha o'zgartira oladi."))
        if self.can_edit and self.reason_required:
            notes.append(("document-edit-symbolic", "Bu dars eski — saqlashda o'zgartirish sababini yozish kerak."))
        if notes:
            ng = group()
            for icon, text in notes:
                r = Adw.ActionRow(title=GLib.markup_escape_text(text), title_lines=0)
                r.add_prefix(Gtk.Image.new_from_icon_name(icon))
                ng.add(r)
            groups_.append(ng)

        topic_group = group()
        if self.can_edit:
            self.topic = Adw.EntryRow(title=s.text("sheet_topic", "Dars mavzusi"), text=sess.get("topic") or "")
            self.topic.set_show_apply_button(True)
            self.topic.connect("apply", lambda *_: self.save_topic())
            topic_group.add(self.topic)
            groups_.append(topic_group)
        elif sess.get("topic"):
            topic_group.add(row(s.text("sheet_topic", "Dars mavzusi"), sess["topic"], icon="text-x-generic-symbolic"))
            groups_.append(topic_group)

        rows = data.get("rows") or []
        self.students_group = group(None, suffix=text_button(s.text("sheet_all_present", "Qolganlar keldi"), self.all_present,
                                                             css=("flat",)) if self.can_edit and rows else None)
        self.rows = []
        if not rows:
            self.students_group.add(row(s.text("sheet_empty", "Guruhda o'quvchi yo'q."), icon="avatar-default-symbolic"))
        for r in rows:
            self.students_group.add(self._student_row(r))
        self._update_counts()
        groups_.append(self.students_group)
        self._apply_orientation()
        return prefs_page(*groups_)

    def _student_row(self, r):
        s = self.settings
        lb_row = Gtk.ListBoxRow(activatable=False)
        box = Gtk.Box(spacing=12, margin_top=8, margin_bottom=8, margin_start=12, margin_end=12)
        info = Gtk.Box(orientation=Gtk.Orientation.VERTICAL, hexpand=True, valign=Gtk.Align.CENTER)
        info.append(label(r["name"]))
        sub = label("", css=("caption", "dim-label"))
        info.append(sub)
        box.append(info)
        entry = {"data": r, "status": r.get("status"), "orig": r.get("status"), "sub": sub, "box": box, "toggles": {}}
        if self.can_edit:
            toggles = Gtk.Box(valign=Gtk.Align.CENTER, halign=Gtk.Align.START)
            toggles.add_css_class("linked")
            first = None
            for st in STATUSES:
                t = Gtk.ToggleButton(label=s.status_label(st), tooltip_text=s.status_label(st))
                t.add_css_class("tisu-status")
                t.add_css_class(status_css(st))
                if first is None:
                    first = t
                else:
                    t.set_group(first)
                t.set_active(st == r.get("status"))
                t.connect("toggled", self._on_toggle, entry, st)
                toggles.append(t)
                entry["toggles"][st] = t
            box.append(toggles)
        else:
            entry["pill"] = pill(s.status_label(r["status"]) if r.get("status") else "—", status_css(r.get("status")))
            box.append(entry["pill"])
        self._update_sub(entry)
        lb_row.set_child(box)
        self.rows.append(entry)
        return lb_row

    def _apply_orientation(self):
        narrow = self.win.split.get_collapsed()
        for e in self.rows:
            e["box"].set_orientation(Gtk.Orientation.VERTICAL if narrow and self.can_edit else Gtk.Orientation.HORIZONTAL)

    def _on_toggle(self, button, entry, st):
        if button.get_active():
            entry["status"] = st
            self._update_sub(entry)
            self._update_counts()

    def _update_sub(self, e):
        r = e["data"]
        bits = []
        if e["status"]:
            bits.append(self.settings.status_label(e["status"]))
        if r.get("arrived_at"):
            bits.append(f"kelgan: {fmt.time(r['arrived_at'])}")
        if r.get("note"):
            bits.append(r["note"])
        if e["status"] != e["orig"]:
            bits.append("o'zgartirildi")
        e["sub"].set_text(" · ".join(bits) or "belgilanmagan")

    def _update_counts(self):
        marked = sum(1 for e in self.rows if e["status"])
        self.students_group.set_title(GLib.markup_escape_text(
            self.settings.text("sheet_marked", "Belgilangan: {marked}/{total}", marked=marked, total=len(self.rows))))
        dirty = any(e["status"] != e["orig"] for e in self.rows)
        self.save_button.set_sensitive(dirty)

    def all_present(self):
        for e in self.rows:
            if not e["status"]:
                e["toggles"]["PRESENT"].set_active(True)

    # ── Saving ───────────────────────────────────────────────
    def save(self):
        items = [{"student_id": e["data"]["student_id"], "status": e["status"], "note": e["data"].get("note")}
                 for e in self.rows if e["status"]]
        if not items:
            self.win.toast("Hech kim belgilanmagan.")
            return
        if self.reason_required:
            self._ask_reason(lambda reason: self._send(items, reason))
        else:
            self._send(items, None)

    def _ask_reason(self, then):
        s = self.settings
        d = Adw.AlertDialog(heading=s.text("sheet_reason", "O'zgartirish sababi"),
                            body="Bu dars eski. O'zgarish tarixda sabab bilan saqlanadi.")
        entry = Gtk.Entry(placeholder_text=s.text("sheet_reason_hint", "Masalan: ma'lumotnoma keltirdi"), activates_default=True)
        d.set_extra_child(entry)
        d.add_response("cancel", "Bekor qilish")
        d.add_response("save", s.text("sheet_save", "Saqlash"))
        d.set_response_appearance("save", Adw.ResponseAppearance.SUGGESTED)
        d.set_default_response("save")
        d.set_close_response("cancel")
        d.set_response_enabled("save", False)
        entry.connect("changed", lambda e: d.set_response_enabled("save", bool(e.get_text().strip())))

        def response(_d, r):
            if r == "save" and entry.get_text().strip():
                then(entry.get_text().strip())

        d.connect("response", response)
        d.present(self.win)
        entry.grab_focus()

    def _send(self, items, reason):
        self.save_button.set_sensitive(False)
        body = {"items": items}
        if reason:
            body["reason"] = reason

        def done(data, err):
            if err:
                self.save_button.set_sensitive(True)
                self.win.toast(err.message, timeout=5)
                return
            self.win.toast(self.settings.text("sheet_saved", "Saqlandi"))
            self.reload()
            if self.on_saved:
                self.on_saved()

        self.api.put(f"sessions/{self.session_id}/attendance", body, done)

    def save_topic(self):
        text = self.topic.get_text().strip()

        def done(_data, err):
            self.win.toast(err.message if err else "Mavzu saqlandi")

        self.api.patch(f"sessions/{self.session_id}", {"topic": text or None}, done)

    def show_history(self):
        dialog = Adw.Dialog(title="O'zgarishlar tarixi", content_width=480, content_height=520)
        tb = Adw.ToolbarView()
        tb.add_top_bar(Adw.HeaderBar())
        stack = Gtk.Stack()
        from ..widgets import spinner

        stack.add_named(spinner(), "loading")
        tb.set_content(stack)
        dialog.set_child(tb)
        dialog.present(self.win)

        def done(data, err):
            if err:
                stack.add_named(Adw.StatusPage(icon_name="dialog-error-symbolic", title="Xatolik", description=err.message), "x")
                stack.set_visible_child_name("x")
                return
            entries = data.get("data") or []
            if not entries:
                stack.add_named(Adw.StatusPage(icon_name="document-open-recent-symbolic", title="Tarix bo'sh",
                                               description="Bu dars davomati hali o'zgartirilmagan."), "x")
                stack.set_visible_child_name("x")
                return
            groups_ = []
            for h in entries:
                gr = group(f"{fmt.datetime(h['at'])} · {h.get('by') or '—'}", f"Sabab: {h['reason']}" if h.get("reason") else None)
                for c in h.get("changes") or []:
                    frm = self.settings.status_label(c["from"]) if c.get("from") else "—"
                    gr.add(row(c.get("name", ""), f"{frm} → {self.settings.status_label(c['to'])}"))
                groups_.append(gr)
            stack.add_named(prefs_page(*groups_), "x")
            stack.set_visible_child_name("x")

        self.api.get(f"sessions/{self.session_id}/attendance/history", done)
