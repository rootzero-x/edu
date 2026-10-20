"""Groups, group detail, lesson list and the attendance sheet."""

from gi.repository import Adw, GLib, Gtk

from .. import fmt
from ..forms import confirm, form, pick
from ..schedule import ScheduleEditor
from ..widgets import (Page, done_toast, group, icon_button, join, label, menu_button, pill, prefs_page, row, status_css,
                       text_button)
from .certificates import CertificateDetailPage, revoke_certificate
from .users import ImportPage, enrollment_label, search_students

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
        if win.has("group.manage"):
            self.header.pack_start(icon_button("list-add-symbolic", "Yangi guruh", lambda: group_form(self, None, self._created),
                                               css=()))

    def _created(self, g):
        self.reload()
        if g:
            self.push(GroupPage(self.win, g["id"], g["name"], on_changed=self.reload))

    def fetch(self, done):
        self.api.get("groups", done)

    def render(self, data):
        groups = data.get("data") or []
        if not groups:
            if self.win.has("group.manage"):
                self.show_status("tisu-people-symbolic", "Guruhlar yo'q", "Birinchi guruhni yarating.", "Yangi guruh",
                                 lambda: group_form(self, None, self._created))
            else:
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
                           on_activate=lambda g=g: self.push(GroupPage(self.win, g["id"], g["name"], on_changed=self.reload))))
        return prefs_page(active if any(g.get("active") for g in groups) else None,
                          inactive if any(not g.get("active") for g in groups) else None)


def group_form(page, g, on_done):
    """Create (g=None) or edit a group, then (re)generate its lessons from the schedule."""
    win, api = page.win, page.api

    def open_with(courses, teachers, rooms):
        cur = g or {}
        teacher_opts = [(t["id"], fmt.name(t)) for t in teachers]
        if cur.get("teacher") and cur["teacher"]["id"] not in [t[0] for t in teacher_opts]:
            teacher_opts.insert(0, (cur["teacher"]["id"], fmt.name(cur["teacher"])))
        editor = ScheduleEditor(cur.get("schedules"), min_rows=0 if g else 1)
        fields = [{"name": "name", "label": "Guruh nomi", "value": cur.get("name"), "required": True}]
        if g is None:
            fields.append({"name": "course_id", "label": "Kurs", "type": "choice",
                           "options": [(c["id"], c["title"]) for c in courses]})
        fields += [
            {"name": "teacher_id", "label": "O'qituvchi", "type": "choice", "options": teacher_opts,
             "value": (cur.get("teacher") or {}).get("id") or cur.get("teacher_id")},
            {"name": "room_id", "label": "Xona", "type": "choice", "options": [(None, "Xonasiz")] + [(r["id"], r["name"] + (
                f" ({r['capacity']} o'rin)" if r.get("capacity") else "")) for r in rooms], "value": cur.get("room_id")},
        ]
        if g is None:
            fields.append({"name": "start_date", "label": "Boshlanish sanasi (YYYY-MM-DD)", "type": "date",
                           "value": fmt.now().format("%Y-%m-%d"), "required": True})
        fields += [
            {"name": "end_date", "label": "Tugash sanasi (YYYY-MM-DD, ixtiyoriy)", "type": "date",
             "value": (cur.get("end_date") or "")[:10]},
            {"name": "schedules", "label": "Haftalik jadval", "type": "widget", "widget": editor, "get": editor.value},
        ]

        def submit(v, done):
            if not v.get("teacher_id"):
                done(ValueError("O'qituvchini tanlang."))
                return
            body = {"name": v["name"], "teacher_id": v["teacher_id"], "room_id": v.get("room_id"),
                    "end_date": v["end_date"] or None, "schedules": v["schedules"]}
            if g is None:
                if not v.get("course_id"):
                    done(ValueError("Kursni tanlang."))
                    return
                body.update(course_id=v["course_id"], start_date=v["start_date"])

            def saved(data, err):
                if err:
                    done(err)
                    return
                grp = data.get("group") or {}

                def generated(gen, gerr):
                    done(None)
                    created = (gen or {}).get("created")
                    win.toast("Saqlandi" + (f". Yangi darslar: {created}" if created else "") if not gerr
                              else f"Saqlandi, lekin darslar yaratilmadi: {gerr.message}", timeout=5)
                    on_done(grp)

                api.post(f"groups/{grp.get('id', (g or {}).get('id'))}/sessions/generate", {}, generated)

            if g is None:
                api.post("groups", body, saved)
            else:
                api.patch(f"groups/{g['id']}", body, saved)

        form(win, "Guruhni tahrirlash" if g else "Yangi guruh", fields, submit,
             submit_label="Saqlash" if g else "Yaratish", width=560)

    cb = join(lambda res, err: win.toast(err.message) if err else open_with((res["courses"] or {}).get("data") or [],
                                                                            (res["teachers"] or {}).get("data") or [],
                                                                            [r for r in (res.get("rooms") or {}).get("data") or [] if r.get("active", True)]),
              ["courses", "teachers", "?rooms"])
    api.get("courses", cb("courses"))
    api.get("groups/teachers", cb("teachers"))
    api.get("rooms", cb("?rooms"))


class GroupPage(Page):
    def __init__(self, win, group_id, name, on_changed=None):
        super().__init__(win, name)
        self.group_id = group_id
        self.on_changed = on_changed

    def changed(self, *_):
        self.reload()
        if self.on_changed:
            self.on_changed()

    def fetch(self, done):
        today = fmt.now()
        staff = self.win.has("group.manage") or self.win.has("attendance.mark")
        cb = join(done, ["group", "sessions", "?report"] + (["?members"] if staff else []))
        self.api.get(f"groups/{self.group_id}", cb("group"))
        if staff:
            self.api.get(f"groups/{self.group_id}/members", cb("?members"))
        self.api.get(f"groups/{self.group_id}/sessions", cb("sessions"), query={
            "from": today.add_days(-45).format("%Y-%m-%d"), "to": today.add_days(21).format("%Y-%m-%d")})
        self.api.get(f"groups/{self.group_id}/attendance", cb("?report"))

    def render(self, data):
        s = self.settings
        g = self.group = data["group"]["group"]
        manage = self.win.has("group.manage")
        self.set_title(g["name"])

        info = group("Ma'lumot")
        teacher = g.get("teacher") or {}
        info.add(row(s.text("teacher", "O'qituvchi"), fmt.name(teacher) + (f" · {teacher['phone']}" if teacher.get("phone") else "")))
        if g.get("course"):
            info.add(row("Kurs", g["course"]["title"]))
        if g.get("room"):
            info.add(row(s.text("room", "Xona"), g["room"]))
        dates = fmt.date(g.get("start_date")) + (f" — {fmt.date(g['end_date'])}" if g.get("end_date") else "")
        info.add(row("Muddati", dates))
        sch = schedule_text(g.get("schedules"))
        if sch:
            info.add(row(s.text("weekly", "Haftalik jadval"), sch))
        if not g.get("active", True):
            info.add(row("Guruh yopilgan", None, icon="dialog-information-symbolic"))
        if self.settings.get("homework.enabled", True) is not False:
            info.add(row("Uyga vazifalar", "Berilgan vazifalar" + (" va baholash" if self.win.has("attendance.mark") else ""),
                         icon="tisu-quiz-symbolic", on_activate=self._homework))
        groups_ = [info]

        if manage:
            mg = group("Boshqarish")
            mg.add(row("Guruhni tahrirlash", "Nomi, o'qituvchi, xona, tugash sanasi, jadval", icon="document-edit-symbolic",
                       on_activate=lambda: group_form(self, g, lambda _g: self.changed())))
            mg.add(row("Darslarni jadvaldan yaratish", "Yetishmayotgan darslar qo'shiladi (dublikat bo'lmaydi)",
                       icon="x-office-calendar-symbolic", on_activate=self.generate))
            mg.add(row("Sertifikatlar", "Shartlarni tekshirish, berish va bekor qilish", icon="tisu-award-symbolic",
                       on_activate=lambda: self.push(GroupCertificatesPage(self.win, g))))
            mg.add(row("O'quvchilarni import qilish", "Excel/CSV fayldan shu guruhga", icon="document-open-symbolic",
                       on_activate=lambda: self.push(ImportPage(self.win, on_done=self.changed, group_id=g["id"]))))
            mg.add(row("Kurs yakunlandi — hammasi bitirdi", "Faol o'quvchilar «bitirgan» deb belgilanadi", icon="tisu-award-symbolic",
                       on_activate=self.graduate_all))
            mg.add(row("Guruhni yopish" if g.get("active", True) else "Guruhni qayta ochish", None,
                       icon="action-unavailable-symbolic" if g.get("active", True) else "object-select-symbolic",
                       on_activate=self.toggle_active))
            groups_.append(mg)

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
        groups_.append(soon)
        if past:
            recent = group("O'tgan darslar")
            for x in list(reversed(past))[:8]:
                recent.add(self._session_row(x, g, students_count))
            groups_.append(recent)

        add = text_button("O'quvchi qo'shish", self.add_member, css=("flat",), icon="list-add-symbolic") if manage else None
        students = group(f"O'quvchilar ({students_count})" if self.win.has("attendance.mark") else s.text("attendance_rate", "Davomat"),
                         suffix=add)
        phones = {st["id"]: st.get("phone") for st in g.get("students") or []}
        members = {m["id"]: m for m in ((data.get("members") or {}).get("current") or [])}
        self.members = members
        report = (data.get("report") or {}).get("data") or []
        people = report or [{"student_id": st["id"], "name": fmt.name(st)} for st in g.get("students") or []]
        for r in people:
            c = r.get("counts")
            sub = " · ".join(f"{s.status_label(k)} {c.get(k, 0)}" for k in STATUSES) if c else None
            if phones.get(r["student_id"]):
                sub = f"{phones[r['student_id']]} · {sub}" if sub else phones[r["student_id"]]
            m = members.get(r["student_id"]) or {}
            suffix = []
            if m.get("status") == "FROZEN":
                suffix.append(pill(f"muzlatilgan · {fmt.date(m.get('frozen_to'))} gacha", "accent"))
            if "rate" in r:
                rate = r.get("rate")
                suffix.append(pill(fmt.percent(rate), "success" if (rate or 0) >= 80 else "warning" if (rate or 0) >= 60 else "error"))
            if manage:
                frozen = m.get("status") == "FROZEN"
                suffix.append(menu_button([
                    ("Profil", lambda r=r: self.open_profile(r["student_id"], r["name"])),
                    ("Muzlatishni bekor qilish" if frozen else "Muzlatish", (lambda r=r: self.unfreeze(r)) if frozen else (lambda r=r: self.freeze(r))),
                    ("Boshqa guruhga o'tkazish", lambda r=r: self.transfer(r)),
                    ("Bitirdi", lambda r=r: self.graduate(r)),
                    ("A'zolik tarixi", lambda r=r: self.history(r)),
                    ("Guruhdan chiqarish", lambda r=r: self.remove_member(r), True),
                ]))
                students.add(row(r["name"], sub, icon="avatar-default-symbolic", suffix=suffix))
            else:
                students.add(row(r["name"], sub, icon="avatar-default-symbolic", suffix=suffix,
                                 on_activate=(lambda r=r: self.open_profile(r["student_id"], r["name"])) if self.win.has("attendance.mark") else None))
        if not people:
            students.add(row(s.text("sheet_empty", "Guruhda o'quvchi yo'q."), icon="avatar-default-symbolic"))
        former = (data.get("members") or {}).get("former") or []
        if former:
            fg = group("Sobiq a'zolar")
            for f in former:
                st_ = f.get("status")
                fg.add(row(fmt.name(f), " · ".join(x for x in [f.get("phone"), f.get("reason"), fmt.date(f.get("until") or f.get("left_at"))] if x and x != "—"),
                           icon="avatar-default-symbolic", suffix=pill(enrollment_label(s, st_), "dim"),
                           on_activate=lambda f=f: self.open_profile(f["id"], fmt.name(f))))
            groups_.append(students)
            groups_.append(fg)
            return prefs_page(*groups_)
        groups_.append(students)
        return prefs_page(*groups_)

    def _session_row(self, x, g, students_count):
        return session_row(self, x, g["name"], students_count, on_saved=self.reload)

    # ── Management ───────────────────────────────────────────
    def _homework(self):
        from .homework import HomeworkPage

        self.push(HomeworkPage(self.win, group_id=self.group_id))

    def generate(self):
        def ok(data, err):
            if err:
                self.win.toast(err.message, timeout=6)
                return
            n = data.get("created", 0)
            self.win.toast(f"Yangi darslar: {n}" + (f" ({fmt.date(data['from'])} — {fmt.date(data['to'])})" if data.get("from") else ""))
            self.changed()

        self.api.post(f"groups/{self.group_id}/sessions/generate", {}, ok)

    def toggle_active(self):
        active = self.group.get("active", True)
        do = lambda: self.api.patch(f"groups/{self.group_id}", {"active": not active},  # noqa: E731
                                    done_toast(self.win, "Guruh yopildi" if active else "Guruh qayta ochildi", self.changed))
        if active:
            confirm(self.win, "Guruh yopilsinmi?", "Yopilgan guruh uchun yangi darslar va to'lov hisoblanmaydi.", "Yopish", do)
        else:
            do()

    def add_member(self):
        existing = {st["id"] for st in self.group.get("students") or []}

        def picked(student_id, title):
            self.api.post(f"groups/{self.group_id}/members", {"student_id": student_id},
                          done_toast(self.win, f"{title} guruhga qo'shildi", self.changed))

        pick(self.win, "O'quvchi qo'shish", lambda q, done: search_students(self.api, q, done, exclude=existing), picked,
             placeholder="O'quvchi ismi yoki telefoni")

    def _reasons(self, key):
        v = self.settings.get(key, [])
        return [(x, x) for x in v] if isinstance(v, list) and v else [("Boshqa", "Boshqa")]

    def _member_post(self, r, action, body, ok_text, done):
        def cb(data, err):
            done(err)
            if not err:
                extra = f" (qaytarildi: {fmt.money(data['refunded'], self.settings.get('billing.currency', 'so‘m'))})" if (data or {}).get("refunded") else ""
                self.win.toast(ok_text + extra)
                self.changed()

        self.api.post(f"groups/{self.group_id}/members/{r['student_id']}/{action}", body, cb)

    def open_profile(self, uid, name):
        from .users import UserPage

        self.push(UserPage(self.win, {"id": uid, "first_name": name, "role": "STUDENT"}))

    def remove_member(self, r):
        today = fmt.now().format("%Y-%m-%d")
        form(self.win, f"Guruhdan chiqarish — {r['name']}", [
            {"name": "reason", "label": "Sabab", "type": "choice", "options": self._reasons("enrollment.leave_reasons")},
            {"name": "date", "label": "Sana (YYYY-MM-DD)", "type": "date", "value": today},
            {"name": "note", "label": "Izoh (ixtiyoriy)"},
        ], lambda v, done: self._member_post(r, "leave", {"reason": v["reason"], "date": v["date"] or None, "note": v["note"] or None},
                                             "Guruhdan chiqarildi (davomat tarixi saqlanadi)", done), submit_label="Chiqarish")

    def freeze(self, r):
        today = fmt.now()
        form(self.win, f"Muzlatish — {r['name']}", [
            {"name": "from", "label": "Boshlanish (YYYY-MM-DD)", "type": "date", "value": today.format("%Y-%m-%d"), "required": True},
            {"name": "to", "label": "Tugash (YYYY-MM-DD)", "type": "date", "value": today.add_days(13).format("%Y-%m-%d"), "required": True,
             "hint": f"Ko'pi bilan {self.settings.int('enrollment.max_freeze_days', 90)} kun"},
            {"name": "reason", "label": "Sabab", "type": "choice", "options": self._reasons("enrollment.freeze_reasons")},
            {"name": "note", "label": "Izoh (ixtiyoriy)"},
        ], lambda v, done: self._member_post(r, "freeze", {"from": v["from"], "to": v["to"], "reason": v["reason"], "note": v["note"] or None},
                                             "Muzlatildi — bu kunlar uchun to'lov hisoblanmaydi", done), submit_label="Muzlatish")

    def unfreeze(self, r):
        self._member_post(r, "unfreeze", {}, "Muzlatish bekor qilindi", lambda err: err and self.win.toast(err.message, timeout=6))

    def transfer(self, r):
        def open_with(data):
            others = [x for x in (data or {}).get("data") or [] if x.get("active") and x["id"] != self.group_id]
            if not others:
                self.win.toast("O'tkazish uchun boshqa faol guruh yo'q.")
                return
            form(self.win, f"Boshqa guruhga o'tkazish — {r['name']}", [
                {"name": "to_group_id", "label": "Yangi guruh", "type": "choice",
                 "options": [(x["id"], x["name"] + (f" · {x['course']['title']}" if x.get("course") else "")) for x in others]},
                {"name": "date", "label": "Sana (YYYY-MM-DD)", "type": "date", "value": fmt.now().format("%Y-%m-%d")},
                {"name": "reason", "label": "Sabab (ixtiyoriy)"},
            ], lambda v, done: self._member_post(r, "transfer", {"to_group_id": v["to_group_id"], "date": v["date"] or None,
                                                                 "reason": v["reason"] or None}, "O'tkazildi", done), submit_label="O'tkazish")

        self.api.get("groups", lambda d, e: self.win.toast(e.message) if e else open_with(d))

    def graduate(self, r):
        confirm(self.win, "Bitirdi deb belgilansinmi?", f"{r['name']} kursni tugatgan sifatida guruhdan chiqadi.", "Belgilash",
                lambda: self._member_post(r, "graduate", {}, "Bitirgan deb belgilandi", lambda err: err and self.win.toast(err.message)),
                destructive=False)

    def graduate_all(self):
        confirm(self.win, "Hammasi bitirdimi?", "Guruhdagi barcha faol o'quvchilar «bitirgan» deb belgilanadi.", "Belgilash",
                lambda: self.api.post(f"groups/{self.group_id}/graduate", {},
                                      done_toast(self.win, None, lambda d: (self.win.toast(f"Bitirganlar: {d.get('graduated', 0)}"), self.changed()))),
                destructive=False)

    def history(self, r):
        self.push(MemberHistoryPage(self.win, self.group_id, r))


class MemberHistoryPage(Page):
    def __init__(self, win, group_id, r):
        super().__init__(win, f"{r['name']} — a'zolik tarixi")
        self.group_id, self.r = group_id, r

    def fetch(self, done):
        self.api.get(f"groups/{self.group_id}/members/{self.r['student_id']}/history", done)

    def render(self, data):
        items = data.get("data") or []
        if not items:
            self.show_empty("document-open-recent-symbolic", "Tarix bo'sh")
            return None
        g = group()
        for t in items:
            period = fmt.date(t.get("starts_on")) + (f" — {fmt.date(t['ends_on'])}" if t.get("ends_on") else "")
            bits = [period, t.get("reason"), t.get("note"), f"→ {t['transfer_group']}" if t.get("transfer_group") else None,
                    t.get("created_by")]
            g.add(row(enrollment_label(self.settings, t.get("status")).capitalize(), " · ".join(b for b in bits if b),
                      icon="document-open-recent-symbolic"))
        return prefs_page(g)


BLOCKERS = {
    "NO_ATTENDANCE_DATA": "davomat ma'lumoti yetarli emas",
    "LOW_ATTENDANCE": "davomat past",
    "QUIZ_NOT_PASSED": "yakuniy testdan o'tmagan",
}


class GroupCertificatesPage(Page):
    def __init__(self, win, g):
        super().__init__(win, f"{g['name']} — sertifikatlar")
        self.g = g
        self.issue_button = Gtk.Button(label="Sertifikat berish", sensitive=False)
        self.issue_button.add_css_class("suggested-action")
        self.issue_button.connect("clicked", lambda *_: self.issue())
        self.header.pack_end(self.issue_button)
        self.rows_data = []

    def fetch(self, done):
        self.api.get(f"groups/{self.g['id']}/certificates", done)

    def render(self, data):
        self.rows_data = data.get("data") or []
        ready = [r for r in self.rows_data if not r.get("certificate") and not r.get("blockers")]
        self.issue_button.set_sensitive(bool(ready))
        self.issue_button.set_label(f"Sertifikat berish ({len(ready)})" if ready else "Sertifikat berish")
        if not self.rows_data:
            self.show_empty("tisu-award-symbolic", "Guruhda o'quvchi yo'q")
            return None
        g = group(None, f"Tayyor: {len(ready)} · berilgan: {sum(1 for r in self.rows_data if r.get('certificate'))}")
        for r in self.rows_data:
            bits = [f"davomat {fmt.percent(r.get('attendance_rate'))}", f"{r.get('marked_sessions', 0)} dars belgilangan",
                    "yakuniy test: o'tdi" if r.get("final_quiz_passed") else "yakuniy test: o'tmagan"]
            if r.get("blockers"):
                bits.append("Sabab: " + ", ".join(BLOCKERS.get(b, b) for b in r["blockers"]))
            if r.get("certificate"):
                code = r["certificate"]
                suffix = [pill(code, "success"), menu_button([
                    ("Ko'rish", lambda c=code: self.push(CertificateDetailPage(self.win, c))),
                    ("Bekor qilish", lambda c=code: revoke_certificate(self, c, self.reload), True),
                ])]
            elif r.get("blockers"):
                suffix = [pill("shart bajarilmagan", "warning")]
            else:
                suffix = [pill("tayyor", "accent")]
            g.add(row(r["name"], " · ".join(bits), icon="avatar-default-symbolic", suffix=suffix))
        return prefs_page(g)

    def issue(self):
        ready = [r for r in self.rows_data if not r.get("certificate") and not r.get("blockers")]

        def yes():
            def ok(data, err):
                if err:
                    self.win.toast(err.message, timeout=6)
                    return
                self.win.toast(f"Berildi: {len(data.get('issued') or [])} ta sertifikat")
                self.reload()

            self.api.post(f"groups/{self.g['id']}/certificates", {}, ok)

        confirm(self.win, "Sertifikatlar berilsinmi?", f"{len(ready)} ta o'quvchiga sertifikat beriladi va Telegram orqali xabar yuboriladi.",
                "Berish", yes, destructive=False)


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
        if self.win.has("attendance.mark"):
            if getattr(self, "_menu", None) is not None:
                self.header.remove(self._menu)
            cancelled = bool(sess.get("cancelled"))
            self._menu = menu_button([("Darsni tiklash" if cancelled else "Darsni bekor qilish",
                                       lambda: self.set_cancelled(not cancelled), not cancelled)], tooltip="Dars amallari")
            self.header.pack_end(self._menu)

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
        page = prefs_page(*groups_)
        if not sess.get("cancelled"):
            self._load_extras(page, data)
        return page

    # ── Marks and homework of this lesson (Sprint 3) ────────
    def _load_extras(self, page, sheet):
        s = self.settings
        if s.get("marks.session_marks", True) is not False:
            self.api.get(f"sessions/{self.session_id}/marks", lambda d, e: d and self._marks_group(page, d))
        if s.get("homework.enabled", True) is not False:
            gid = sheet["group"]["id"]
            self.api.get("assignments", lambda d, e: d and self._homework_group(page, d, gid), query={"group_id": gid, "status": "all", "limit": 100})

    def _marks_group(self, page, d):
        rows_ = d.get("rows") or []
        can = bool(d.get("can_edit"))
        if not d.get("enabled", True) or not rows_ or (not can and all(r.get("score") is None for r in rows_)):
            return
        sc = d.get("scale") or {"min": 1, "max": 5}
        lo, hi = int(sc.get("min", 1)), int(sc.get("max", 5))
        self.mark_rows = []
        save = Gtk.Button(label="Saqlash", sensitive=False, valign=Gtk.Align.CENTER)
        save.add_css_class("suggested-action")
        save.connect("clicked", lambda *_: self._save_marks())
        self.marks_save = save
        g = group("Baholar", f"Shkala: {lo}–{hi}", suffix=save if can else None)
        for r in rows_:
            if can:
                values = ["—"] + [str(v) for v in range(lo, hi + 1)]
                dd = Gtk.DropDown(model=Gtk.StringList.new(values), valign=Gtk.Align.CENTER)
                if r.get("score") is not None:
                    dd.set_selected(int(r["score"]) - lo + 1)
                note = Gtk.Entry(text=r.get("note") or "", placeholder_text="Izoh", width_chars=14, valign=Gtk.Align.CENTER, max_length=255)
                e = {"r": r, "dd": dd, "note": note, "lo": lo, "orig": (r.get("score"), r.get("note") or "")}
                dd.connect("notify::selected", lambda *_: self._marks_dirty())
                note.connect("changed", lambda *_: self._marks_dirty())
                self.mark_rows.append(e)
                g.add(row(r["name"], None, icon="avatar-default-symbolic", suffix=[note, dd]))
            else:
                g.add(row(r["name"], r.get("note"), icon="avatar-default-symbolic",
                          suffix=pill(f"{r['score']}/{hi}" if r.get("score") is not None else "—", "accent")))
        page.add(g)

    def _mark_value(self, e):
        i = e["dd"].get_selected()
        return None if i == 0 else e["lo"] + i - 1

    def _marks_dirty(self):
        n = sum(1 for e in self.mark_rows if (self._mark_value(e), e["note"].get_text().strip()) != e["orig"])
        self.marks_save.set_sensitive(n > 0)
        self.marks_save.set_label(f"Saqlash ({n})" if n else "Saqlash")

    def _save_marks(self):
        items = [{"student_id": e["r"]["student_id"], "score": self._mark_value(e), "note": e["note"].get_text().strip() or None}
                 for e in self.mark_rows if (self._mark_value(e), e["note"].get_text().strip()) != e["orig"]]

        def ok(_d, err):
            if err:
                self.win.toast(err.message, timeout=6)
                return
            self.win.toast(f"Baholar saqlandi ({len(items)})")
            self.reload()

        self.api.put(f"sessions/{self.session_id}/marks", {"items": items}, ok)

    def _homework_group(self, page, d, gid):
        from .homework import AssignmentPage, assignment_form

        items = [a for a in d.get("data") or [] if a.get("session_id") == self.session_id]
        teacher = d.get("mode") == "teacher"
        if not items and not teacher:
            return
        add = None
        if teacher:
            add = Gtk.Button(icon_name="list-add-symbolic", tooltip_text="Shu darsga vazifa berish", valign=Gtk.Align.CENTER)
            add.add_css_class("flat")
            add.connect("clicked", lambda *_: assignment_form(self, group_id=gid, session_id=self.session_id,
                                                              on_done=lambda _a: self.reload()))
        g = group("Uyga vazifa", suffix=add)
        for a in items:
            g.add(row(a["title"], f"muddat: {fmt.datetime(a['due_at'])}" if a.get("due_at") else None, icon="tisu-quiz-symbolic",
                      on_activate=lambda a=a: self.push(AssignmentPage(self.win, a["id"], on_changed=self.reload))))
        if not items:
            g.add(row("Bu darsga vazifa berilmagan.", icon="dialog-information-symbolic"))
        page.add(g)

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

    def set_cancelled(self, cancelled):
        def do():
            def ok(_d, err):
                if err:
                    self.win.toast(err.message, timeout=6)
                    return
                self.win.toast("Dars bekor qilindi" if cancelled else "Dars tiklandi")
                self.reload()
                if self.on_saved:
                    self.on_saved()

            self.api.patch(f"sessions/{self.session_id}", {"cancelled": cancelled}, ok)

        if cancelled:
            confirm(self.win, "Dars bekor qilinsinmi?", "Bekor qilingan dars davomat va hisobotlarda hisobga olinmaydi.",
                    "Bekor qilish", do)
        else:
            do()

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
