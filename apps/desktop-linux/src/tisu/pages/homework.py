"""Homework: assignments (teachers create, attach files, grade; students submit with text, link and
files), "my marks" for students."""

from gi.repository import Adw, GLib, Gtk

from .. import fmt
from ..files import human_size, open_file, pick_and_upload, save_file_as
from ..forms import confirm, form
from ..widgets import Page, done_toast, group, icon_button, label, menu_button, pill, prefs_page, row, text_button

STATUS_CSS = {"PENDING": "warning", "SUBMITTED": "accent", "LATE": "warning", "GRADED": "success", "MISSED": "error"}


def hw_status(s, st):
    labels = s.get("homework.status_labels", {})
    defaults = {"PENDING": "Topshirilmagan", "SUBMITTED": "Topshirildi", "LATE": "Kechikib topshirildi",
                "GRADED": "Baholandi", "MISSED": "Muddati o'tgan"}
    return (labels.get(st) if isinstance(labels, dict) else None) or defaults.get(st, st)


def _due_text(value):
    """'YYYY-MM-DD HH:MM' in school time for the form."""
    dt = fmt.parse(value) if value else None
    return dt.format("%Y-%m-%d %H:%M") if dt else ""


def _due_iso(text):
    text = (text or "").strip()
    if not text:
        return None
    if len(text) == 10:
        text += " 23:59"
    dt = GLib.DateTime.new_from_iso8601(text.replace(" ", "T") + ":00", fmt.tz()) if len(text) == 16 else None
    if dt is None:
        raise ValueError("Muddat «YYYY-MM-DD SS:DD» ko'rinishida bo'lsin, masalan 2026-10-25 18:00.")
    return dt.to_utc().format("%Y-%m-%dT%H:%M:%SZ")


def assignment_form(page, assignment=None, group_id=None, session_id=None, on_done=None):
    """Create (in ``group_id`` or a chosen group) or edit an assignment."""
    win, api, s = page.win, page.api, page.settings
    a = assignment or {}

    def open_with(groups_):
        fields = []
        if assignment is None and group_id is None:
            if not groups_:
                win.toast("Vazifa berish uchun sizda guruh yo'q.")
                return
            fields.append({"name": "group_id", "label": "Guruh", "type": "choice", "options": [(g["id"], g["name"]) for g in groups_]})
        fields += [
            {"name": "title", "label": "Sarlavha", "value": a.get("title"), "required": True},
            {"name": "body", "label": "Topshiriq matni", "type": "text", "value": a.get("body"), "height": 160},
            {"name": "due", "label": "Muddat (YYYY-MM-DD SS:DD, ixtiyoriy)", "value": _due_text(a.get("due_at"))},
            {"name": "max_score", "label": f"Maksimal ball (0 — {s.int('marks.scale_max', 5)} dan foydalanish)", "type": "int",
             "min": 0, "max": 1000, "value": a.get("max_score") if assignment else 0},
        ]
        if assignment is None:
            fields.append({"name": "notify", "label": "O'quvchilarga Telegram orqali xabar", "type": "switch", "value": True})

        def submit(v, done):
            try:
                due = _due_iso(v["due"])
            except ValueError as e:
                done(e)
                return
            body = {"title": v["title"], "body": v["body"] or None, "due_at": due, "max_score": v["max_score"] or None}
            if assignment is None:
                body["notify"] = v["notify"]
                if session_id:
                    body["session_id"] = session_id
                gid = group_id or v.get("group_id")

                def created(data, err):
                    done(err)
                    if not err:
                        win.toast("Vazifa berildi")
                        if on_done:
                            on_done(data.get("assignment"))

                api.post(f"groups/{gid}/assignments", body, created)
            else:
                api.patch(f"assignments/{a['id']}", body, lambda d, err: (done(err), err is None and on_done and on_done(d.get("assignment"))))

        form(win, "Vazifani tahrirlash" if assignment else "Uyga vazifa berish", fields, submit,
             submit_label="Saqlash" if assignment else "Berish", width=600)

    if assignment is None and group_id is None:
        me = (win.user or {}).get("id")

        def got(d, err):
            gs = [g for g in (d or {}).get("data") or [] if g.get("active") and (win.has("group.manage") or g.get("teacher_id") == me)]
            open_with(gs)

        api.get("groups", got)
    else:
        open_with([])


def files_group(page, files, can_delete=False, on_deleted=None, title="Fayllar"):
    g = group(title) if files else None
    for f in files or []:
        actions = [("Ochish", lambda f=f: open_file(page.win, page.api, f["id"])),
                   ("Saqlash…", lambda f=f: save_file_as(page.win, page.api, f["id"], f.get("name") or f.get("original_name")))]
        if can_delete:
            actions.append(("O'chirish", lambda f=f: confirm(page.win, "Fayl o'chirilsinmi?", f.get("name"), "O'chirish",
                                                             lambda: page.api.delete(f"files/{f['id']}", done_toast(page.win, "Fayl o'chirildi", on_deleted))), True))
        g.add(row(f.get("name") or f.get("original_name") or "fayl", human_size(f.get("size")), icon="folder-documents-symbolic",
                  suffix=menu_button(actions), on_activate=lambda f=f: open_file(page.win, page.api, f["id"]), chevron=False))
    return g


class HomeworkPage(Page):
    TABS = [("open", "Joriy"), ("all", "Hammasi"), ("marks", "Baholarim")]

    def __init__(self, win, group_id=None):
        super().__init__(win, win.public.nav_label("/dashboard/homework", "Uyga vazifa"))
        self.group_id = group_id
        self.mode = None
        bar = Gtk.Box(spacing=6, margin_start=12, margin_end=12, margin_bottom=6)
        self.tab_dd = Gtk.DropDown(model=Gtk.StringList.new([t[1] for t in self.TABS]), hexpand=True)
        self.groups = []
        self.group_dd = Gtk.DropDown(model=Gtk.StringList.new(["Barcha guruhlar"]))
        for dd in (self.tab_dd, self.group_dd):
            dd.connect("notify::selected", lambda *_: self._loaded and self.reload())
        bar.append(self.tab_dd)
        bar.append(self.group_dd)
        self.toolbar.add_top_bar(bar)
        self.add_btn = icon_button("list-add-symbolic", "Vazifa berish", lambda: assignment_form(
            self, group_id=self.group_id, on_done=lambda a: (self.reload(), a and self.push(AssignmentPage(self.win, a["id"], on_changed=self.reload)))), css=())
        self.add_btn.set_visible(False)
        self.header.pack_start(self.add_btn)
        self.api.get("groups", self._got_groups)

    def _got_groups(self, data, err):
        self.groups = [g for g in (data or {}).get("data") or [] if g.get("active")]
        self.group_dd.set_model(Gtk.StringList.new(["Barcha guruhlar"] + [g["name"] for g in self.groups]))
        if self.group_id:
            ids = [g["id"] for g in self.groups]
            if self.group_id in ids:
                self.group_dd.set_selected(ids.index(self.group_id) + 1)
        self.group_dd.set_visible(len(self.groups) > 1)

    def _gid(self):
        i = self.group_dd.get_selected()
        return self.groups[i - 1]["id"] if 0 < i <= len(self.groups) else self.group_id

    def fetch(self, done):
        tab = self.TABS[self.tab_dd.get_selected()][0]
        if tab == "marks":
            self.api.get("marks/me", lambda d, e: done(dict(d or {}, _marks=True), e), query={"group_id": self._gid()})
        else:
            self.api.get("assignments", done, query={"group_id": self._gid(), "status": tab, "limit": 200})

    def render(self, data):
        s = self.settings
        if data.get("_marks"):
            return self._marks(data)
        self.mode = data.get("mode")
        self.add_btn.set_visible(self.mode == "teacher")
        items = data.get("data") or []
        if not items:
            self.show_status("tisu-quiz-symbolic", "Vazifalar yo'q",
                             "Yangi vazifa berish uchun «+» tugmasini bosing." if self.mode == "teacher" else "Hozircha vazifa berilmagan.",
                             "Vazifa berish" if self.mode == "teacher" else None,
                             (lambda: self.add_btn.emit("clicked")) if self.mode == "teacher" else None)
            return None
        g = group()
        now = fmt.now()
        for a in items:
            bits = [a.get("group_name")]
            due = fmt.parse(a.get("due_at")) if a.get("due_at") else None
            if due:
                overdue = due.compare(now) < 0
                bits.append(("⚠ " if overdue and (a.get("my") or {}).get("status") in ("PENDING", "MISSED") else "") + f"muddat: {fmt.datetime(due)}")
            if a.get("files"):
                bits.append(f"📎 {len(a['files'])}")
            suffix = []
            if a.get("stats"):
                st = a["stats"]
                suffix.append(pill(f"{st.get('submitted', 0)}/{st.get('total', 0)}", "dim"))
                ungraded = st.get("submitted", 0) - st.get("graded", 0)
                suffix.append(pill(f"{ungraded} ta baholanmagan", "warning") if ungraded > 0 else pill("baholangan", "success"))
            elif a.get("my"):
                my = a["my"]
                suffix.append(pill(hw_status(s, my.get("status")), STATUS_CSS.get(my.get("status"), "dim")))
                if my.get("score") is not None:
                    suffix.append(pill(f"{my['score']}/{a.get('max_score')}", "success"))
            g.add(row(a["title"], " · ".join(b for b in bits if b), icon="tisu-quiz-symbolic", suffix=suffix,
                      on_activate=lambda a=a: self.push(AssignmentPage(self.win, a["id"], on_changed=self.reload))))
        return prefs_page(g)

    def _marks(self, data):
        rows_ = data.get("data") or []
        if not rows_:
            self.show_empty("starred-symbolic", "Baholar yo'q", "Darsdagi va uyga vazifa baholari shu yerda ko'rinadi.")
            return None
        avg = data.get("average_percent")
        g = group("Baholarim", f"O'rtacha: {fmt.percent(avg)}" if avg is not None else None)
        for m in rows_:
            kind = "Dars" if m.get("type") == "session" else "Uyga vazifa"
            pct = m.get("percent")
            g.add(row(m.get("title") or kind, " · ".join(x for x in [kind, m.get("group_name"), fmt.date(m.get("date")), m.get("note")] if x),
                      icon="starred-symbolic", suffix=pill(f"{m.get('score')}/{m.get('max')}",
                                                          "success" if (pct or 0) >= 80 else "warning" if (pct or 0) >= 50 else "error"),
                      on_activate=(lambda m=m: self.push(AssignmentPage(self.win, m["assignment_id"]))) if m.get("assignment_id") else None))
        return prefs_page(g)


class AssignmentPage(Page):
    def __init__(self, win, assignment_id, on_changed=None):
        super().__init__(win, "Uyga vazifa")
        self.assignment_id = assignment_id
        self.on_changed = on_changed
        self.grade_rows = []

    def fetch(self, done):
        self.api.get(f"assignments/{self.assignment_id}", done)

    def changed(self, *_):
        self.reload()
        if self.on_changed:
            self.on_changed()

    def render(self, data):
        a = self.a = data["assignment"]
        self.scale = data.get("scale") or {}
        manage = bool(a.get("can_manage"))
        self.set_title(a["title"])
        out = []

        top = group(a["title"], a.get("group_name"))
        if manage:
            top.set_header_suffix(menu_button([
                ("Tahrirlash", lambda: assignment_form(self, assignment=a, on_done=lambda _a: self.changed())),
                ("Fayl biriktirish", lambda: pick_and_upload(self.win, self.api, f"assignments/{a['id']}/files",
                                                             lambda _d: self.changed())),
                ("O'chirish", self.delete, True),
            ]))
        if a.get("body"):
            lb = label(a["body"], selectable=True)
            for m in ("top", "bottom", "start", "end"):
                getattr(lb, f"set_margin_{m}")(12)
            top.add(Gtk.ListBoxRow(activatable=False, child=lb))
        info = []
        if a.get("due_at"):
            info.append(("Muddat", fmt.datetime(a["due_at"]), "alarm-symbolic"))
        info.append(("Maksimal ball", str(a.get("max_score")), "starred-symbolic"))
        if a.get("session_starts_at"):
            info.append(("Dars", fmt.datetime(a["session_starts_at"]), "x-office-calendar-symbolic"))
        if a.get("lesson_title"):
            info.append(("Mavzu", a["lesson_title"], "text-x-generic-symbolic"))
        if a.get("author"):
            info.append(("Muallif", a["author"], "avatar-default-symbolic"))
        st = a.get("stats")
        if st:
            info.append(("Topshirganlar", f"{st.get('submitted', 0)}/{st.get('total', 0)} · baholangan {st.get('graded', 0)} · "
                                          f"kechikkan {st.get('late', 0)}", "view-list-bullet-symbolic"))
        for t, v, ic in info:
            top.add(row(t, v, icon=ic))
        out.append(top)
        fg = files_group(self, a.get("files"), can_delete=manage, on_deleted=lambda _d: self.changed())
        if fg:
            out.append(fg)

        if manage:
            out += self._grading(data)
        elif a.get("my") is not None:
            out += self._mine(a["my"])
        return prefs_page(*out)

    # ── Teacher: grading ─────────────────────────────────────
    def _grading(self, data):
        s = self.settings
        a = self.a
        subs = a.get("submissions") or []
        self.grade_rows = []
        save = text_button("Saqlash", self.save_grades, css=("suggested-action",))
        save.set_sensitive(False)
        self.save_btn = save
        g = group("Baholash", "O'zgargan qatorlar «Saqlash» bosilganda yuboriladi. Bo'sh ball — baho o'chiriladi.", suffix=save)
        for sub in subs:
            exp = Adw.ExpanderRow(title=GLib.markup_escape_text(sub["name"] + (" (guruhdan chiqqan)" if sub.get("left_group") else "")),
                                  subtitle=GLib.markup_escape_text(" · ".join(x for x in [
                                      hw_status(s, sub.get("status")), fmt.datetime(sub["submitted_at"]) if sub.get("submitted_at") else None] if x)))
            score_txt = "" if sub.get("score") is None else str(sub["score"])
            entry = Gtk.Entry(text=score_txt, width_chars=4, max_width_chars=4, valign=Gtk.Align.CENTER,
                              placeholder_text=f"0–{a.get('max_score')}", input_purpose=Gtk.InputPurpose.DIGITS)
            exp.add_suffix(entry)
            exp.add_suffix(pill(hw_status(s, sub.get("status")), STATUS_CSS.get(sub.get("status"), "dim")))
            if sub.get("body"):
                body = label(sub["body"], selectable=True)
                for m in ("top", "bottom", "start", "end"):
                    getattr(body, f"set_margin_{m}")(12)
                exp.add_row(Gtk.ListBoxRow(activatable=False, child=body))
            if sub.get("link_url"):
                from ..webview import open_in_app

                exp.add_row(row("Havola", sub["link_url"], icon="tisu-external-link-symbolic",
                                on_activate=lambda u=sub["link_url"]: open_in_app(self, "Javob havolasi", u)))
            for f in sub.get("files") or []:
                exp.add_row(row(f.get("name"), human_size(f.get("size")), icon="folder-documents-symbolic",
                                on_activate=lambda f=f: open_file(self.win, self.api, f["id"]), chevron=False))
            fb = Adw.EntryRow(title="Izoh (o'quvchiga ko'rinadi)", text=sub.get("feedback") or "")
            exp.add_row(fb)
            entry_ = {"sub": sub, "score": entry, "feedback": fb, "orig": (score_txt, sub.get("feedback") or "")}
            entry.connect("changed", lambda *_: self._dirty())
            fb.connect("changed", lambda *_: self._dirty())
            self.grade_rows.append(entry_)
            g.add(exp)
        if not subs:
            g.add(row("Guruhda o'quvchi yo'q.", icon="dialog-information-symbolic"))
        return [g]

    def _dirty_items(self):
        out = []
        for e in self.grade_rows:
            score, fb = e["score"].get_text().strip(), e["feedback"].get_text().strip()
            if (score, fb) != e["orig"]:
                out.append((e, score, fb))
        return out

    def _dirty(self):
        n = len(self._dirty_items())
        self.save_btn.set_sensitive(n > 0)
        self.save_btn.set_label(f"Saqlash ({n})" if n else "Saqlash")

    def save_grades(self):
        mx = int(self.a.get("max_score") or 0)
        items = []
        for e, score, fb in self._dirty_items():
            if score and not score.isdigit() or (score and not 0 <= int(score) <= mx):
                e["score"].add_css_class("error")
                self.win.toast(f"{e['sub']['name']}: ball 0 dan {mx} gacha bo'lishi kerak.")
                return
            e["score"].remove_css_class("error")
            items.append({"student_id": e["sub"]["student_id"], "score": int(score) if score else None, "feedback": fb or None})
        self.api.put(f"assignments/{self.a['id']}/grades", {"items": items},
                     done_toast(self.win, f"Saqlandi: {len(items)} ta", lambda _d: self.changed()))

    # ── Student: my answer ───────────────────────────────────
    def _mine(self, my):
        s = self.settings
        out = []
        res = group("Natija", None, suffix=pill(hw_status(s, my.get("status")), STATUS_CSS.get(my.get("status"), "dim")))
        if my.get("score") is not None:
            res.add(row("Ball", f"{my['score']}/{self.a.get('max_score')}", icon="starred-symbolic"))
        if my.get("feedback"):
            r = row("O'qituvchi izohi", my["feedback"], icon="text-x-generic-symbolic")
            r.set_subtitle_lines(0)
            res.add(r)
        if my.get("graded_at"):
            res.add(row("Baholangan", fmt.datetime(my["graded_at"]), icon="x-office-calendar-symbolic"))
        if my.get("submitted_at"):
            res.add(row("Topshirilgan", fmt.datetime(my["submitted_at"]), icon="object-select-symbolic"))
        out.append(res)

        can = bool(my.get("can_submit"))
        g = group("Mening javobim", None if can else "Javobni hozir o'zgartirib bo'lmaydi.")
        self.answer = Adw.EntryRow(title="Havola (ixtiyoriy, https://…)", text=my.get("link_url") or "", editable=can)
        tv = Gtk.TextView(wrap_mode=Gtk.WrapMode.WORD_CHAR, editable=can, top_margin=8, bottom_margin=8, left_margin=8, right_margin=8)
        tv.get_buffer().set_text(my.get("body") or "")
        self.answer_text = tv
        sw = Gtk.ScrolledWindow(child=tv, min_content_height=120, hscrollbar_policy=Gtk.PolicyType.NEVER)
        sw.add_css_class("card")
        g.add(sw)
        g.add(self.answer)
        out.append(g)
        mf = files_group(self, my.get("files"), can_delete=can and not my.get("graded_at"), on_deleted=lambda _d: self.changed(),
                         title="Biriktirilgan fayllar")
        if mf:
            out.append(mf)
        if can:
            btns = Gtk.Box(spacing=12, halign=Gtk.Align.CENTER)
            if s.get("uploads.student_files", True) is not False:
                btns.append(text_button("Fayl biriktirish", lambda: pick_and_upload(
                    self.win, self.api, f"assignments/{self.a['id']}/submission/files", lambda _d: self.changed()), css=("pill",)))
            btns.append(text_button("Qayta topshirish" if my.get("submitted_at") else "Topshirish", self.submit,
                                    css=("pill", "suggested-action")))
            bg = group()
            bg.add(btns)
            out.append(bg)
        return out

    def submit(self):
        buf = self.answer_text.get_buffer()
        body = buf.get_text(buf.get_start_iter(), buf.get_end_iter(), False).strip()
        link = self.answer.get_text().strip()
        self.api.put(f"assignments/{self.a['id']}/submission", {"body": body or None, "link_url": link or None},
                     done_toast(self.win, "Javob topshirildi", lambda _d: self.changed()))

    def delete(self):
        def ok(_d, err):
            if err:
                self.win.toast(err.message, timeout=6)
                return
            self.win.toast("Vazifa o'chirildi")
            if self.on_changed:
                self.on_changed()
            self.get_ancestor(Adw.NavigationView).pop()

        confirm(self.win, "Vazifa o'chirilsinmi?", "Topshirilgan yoki baholangan vazifani o'chirib bo'lmaydi.", "O'chirish",
                lambda: self.api.delete(f"assignments/{self.a['id']}", ok))
