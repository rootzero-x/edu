"""Quiz overview, taking a quiz (with time limit) and results."""

from gi.repository import Adw, GLib, Gtk

from .. import fmt
from ..forms import confirm, form
from ..widgets import Page, done_toast, group, label, menu_button, pill, prefs_page, row, text_button


QUESTION_TYPES = [("SINGLE", "Bitta javob"), ("MULTIPLE", "Bir nechta javob"), ("TEXT", "Matnli javob")]


def quiz_subtitle(s, q):
    parts = []
    n = q.get("questions_count")
    if n is not None:
        parts.append(s.text("quiz_questions", "{n} ta savol", n=n))
    if q.get("time_limit_sec"):
        parts.append(s.text("quiz_time", "{min} daqiqa", min=round(q["time_limit_sec"] / 60)))
    else:
        parts.append(s.text("quiz_no_time", "Vaqt cheklanmagan"))
    if q.get("pass_percent") is not None:
        parts.append(s.text("quiz_pass", "O'tish: {percent}%", percent=q["pass_percent"]))
    return " · ".join(parts)


class QuizPage(Page):
    def __init__(self, win, quiz_id, title, on_changed=None):
        super().__init__(win, title)
        self.quiz_id = quiz_id
        self.on_changed = on_changed

    def changed(self, *_):
        self.reload()
        if self.on_changed:
            self.on_changed()

    def fetch(self, done):
        self.api.get(f"quizzes/{self.quiz_id}", done)

    def render(self, data):
        s = self.settings
        q = data["quiz"]
        self.set_title(q["title"])
        if "questions" in q:
            return self._render_manager(q, data)

        attempts = data.get("attempts") or []
        info = group(q["title"], (q.get("course") or {}).get("title"))
        info.add(row(quiz_subtitle(s, q), None, icon="tisu-quiz-symbolic"))
        used = len(attempts)
        info.add(row(s.text("quiz_attempts", "Urinishlar: {used}/{max}", used=used, max=q.get("max_attempts")), None,
                     icon="view-list-bullet-symbolic"))
        best = max((a.get("percent") or 0 for a in attempts if a.get("submitted_at")), default=None)
        if best is not None:
            info.add(row(s.text("quiz_best", "Eng yaxshi natija: {percent}%", percent=f"{best:.0f}"), None, icon="starred-symbolic"))

        actions = group()
        open_attempt = next((a for a in attempts if not a.get("submitted_at")), None)
        can_take = data.get("can_take")
        if not can_take:
            actions.add(row(s.text("quiz_not_allowed", "Bu test sizning guruhingiz uchun emas."), None, icon="dialog-information-symbolic"))
        elif data.get("can_start", bool(open_attempt or used < (q.get("max_attempts") or 0))):
            b = Gtk.Button(label=s.text("quiz_continue", "Davom ettirish") if open_attempt else s.text("quiz_start", "Testni boshlash"),
                           halign=Gtk.Align.CENTER)
            b.add_css_class("pill")
            b.add_css_class("suggested-action")
            b.connect("clicked", lambda *_: self.start(b))
            actions.add(b)
        elif any(a.get("passed") for a in attempts):
            actions.add(row("Siz bu testdan o'tgansiz.", None, icon="object-select-symbolic"))
        else:
            actions.add(row("Urinishlar tugagan.", None, icon="dialog-information-symbolic"))

        hist = group("Urinishlar") if attempts else None
        for a in attempts:
            if a.get("submitted_at"):
                sub = f"{a.get('score')}/{a.get('max_score')} · {fmt.datetime(a['submitted_at'])}"
                p = pill(fmt.percent(a.get("percent")), "success" if a.get("passed") else "error")
            else:
                sub = f"Boshlangan: {fmt.datetime(a.get('started_at'))}"
                p = pill("davom etmoqda", "warning")
            hist.add(row(f"#{a['id']}", sub, suffix=p))
        return prefs_page(info, actions, hist)

    def _render_manager(self, q, data):
        s = self.settings
        self.quiz = q
        edit = bool(data.get("can_edit"))
        questions = q.get("questions") or []
        info = group(q["title"], (q.get("course") or {}).get("title"))
        info.add(row(quiz_subtitle(s, dict(q, questions_count=len(questions))), None, icon="tisu-quiz-symbolic"))
        info.add(row(f"Urinishlar limiti: {q.get('max_attempts')}",
                     " · ".join(x for x in ["Yakuniy test" if q.get("is_final") else None,
                                            "Savollar aralashtiriladi" if q.get("shuffle") else None] if x) or None,
                     icon="view-list-bullet-symbolic"))
        acts = group()
        if edit:
            pub = Adw.SwitchRow(title="E'lon qilingan", subtitle="O'chirilsa, test o'quvchilarga ko'rinmaydi",
                                active=bool(q.get("published")))
            pub.connect("notify::active", lambda w, *_: self.api.patch(
                f"quizzes/{q['id']}", {"published": w.get_active()},
                done_toast(self.win, "Test e'lon qilindi" if w.get_active() else "Test yashirildi", self.changed)))
            acts.add(pub)
            acts.add(row("Test sozlamalari", "Nomi, o'tish bali, urinishlar, vaqt", icon="document-edit-symbolic",
                         on_activate=self.edit_settings))
        elif not q.get("published"):
            info.add(row("Yashirin (o'quvchilarga ko'rinmaydi)", None, icon="dialog-information-symbolic"))
        acts.add(row("Natijalar", "Topshirgan o'quvchilar", icon="tisu-chart-symbolic",
                     on_activate=lambda: self.push(QuizResultsPage(self.win, q))))
        if edit:
            acts.add(row("Testni o'chirish", "Topshirganlar bo'lsa o'chirilmaydi — yashiring", icon="edit-delete-symbolic",
                         on_activate=self.delete_quiz))

        total = sum(int(x.get("points") or 1) for x in questions)
        add = text_button("Savol qo'shish", self.add_question, css=("flat",), icon="list-add-symbolic") if edit else None
        qs = group(f"Savollar ({len(questions)})", f"Jami ball: {total}" if questions else None, suffix=add)
        types = dict(QUESTION_TYPES)
        for i, question in enumerate(questions, 1):
            opts = question.get("options") or []
            correct = [o["text"] for o in opts if o.get("is_correct")] or question.get("accepted_answers") or []
            sub = f"{types.get(question['type'], question['type'])} · {question.get('points', 1)} ball"
            if correct:
                sub += " · To'g'ri: " + ", ".join(map(str, correct))
            suffix = menu_button([
                ("Tahrirlash", lambda qq=question: self.question_form(qq)),
                ("O'chirish", lambda qq=question: self.delete_question(qq), True),
            ]) if edit else None
            r = row(f"{i}. {question['text']}", sub, suffix=suffix)
            r.set_title_lines(3)
            qs.add(r)
        if not questions:
            qs.add(row("Savollar hali qo'shilmagan.", icon="dialog-information-symbolic"))
        return prefs_page(info, acts, qs)

    # ── Editing (author / admin) ─────────────────────────────
    def edit_settings(self):
        q = self.quiz

        def submit(v, done):
            self.api.patch(f"quizzes/{q['id']}", {
                "title": v["title"], "pass_percent": v["pass_percent"], "max_attempts": v["max_attempts"],
                "time_limit_sec": v["minutes"] * 60 if v["minutes"] else None,
                "is_final": v["is_final"], "shuffle": v["shuffle"]}, lambda _d, err: (done(err), err is None and self.changed()))

        form(self.win, "Test sozlamalari", [
            {"name": "title", "label": "Test nomi", "value": q.get("title"), "required": True},
            {"name": "pass_percent", "label": "O'tish bali (%)", "type": "int", "min": 1, "max": 100, "value": q.get("pass_percent") or 60},
            {"name": "max_attempts", "label": "Urinishlar soni", "type": "int", "min": 1, "max": 100, "value": q.get("max_attempts") or 1},
            {"name": "minutes", "label": "Vaqt chegarasi (daqiqa, 0 — cheklanmagan)", "type": "int", "min": 0, "max": 1440,
             "value": round((q.get("time_limit_sec") or 0) / 60)},
            {"name": "is_final", "label": "Yakuniy test (sertifikat sharti)", "type": "switch", "value": q.get("is_final")},
            {"name": "shuffle", "label": "Savol va variantlarni aralashtirish", "type": "switch", "value": q.get("shuffle")},
        ], submit)

    def delete_quiz(self):
        q = self.quiz

        def ok(_d, err):
            if err:
                self.win.toast(err.message, timeout=6)
                return
            self.win.toast("Test o'chirildi")
            if self.on_changed:
                self.on_changed()
            self.get_ancestor(Adw.NavigationView).pop()

        confirm(self.win, "Test o'chirilsinmi?", f"«{q['title']}» va uning savollari o'chiriladi.", "O'chirish",
                lambda: self.api.delete(f"quizzes/{q['id']}", ok))

    def add_question(self):
        self.question_form(None)

    def question_form(self, question):
        existing = question or {}
        qtype = existing.get("type")
        if question:
            if qtype == "TEXT":
                answers = "\n".join(existing.get("accepted_answers") or [])
            else:
                answers = "\n".join(("*" if o.get("is_correct") else "") + o["text"] for o in existing.get("options") or [])
        else:
            answers = "*\n"

        def submit(v, done):
            t = qtype or v["type"]
            lines = [ln.strip() for ln in v["answers"].splitlines() if ln.strip()]
            body = {"text": v["text"].strip(), "points": v["points"]}
            if not question:
                body["type"] = t
            if t == "TEXT":
                if not lines:
                    done(ValueError("Kamida bitta qabul qilinadigan javob yozing."))
                    return
                body["accepted_answers"] = lines
            else:
                opts = [{"text": ln.lstrip("*").strip(), "is_correct": ln.startswith("*")} for ln in lines if ln.lstrip("*").strip()]
                correct = sum(o["is_correct"] for o in opts)
                if not 2 <= len(opts) <= 12:
                    done(ValueError("Variantlar soni 2 dan 12 gacha bo'lishi kerak."))
                    return
                if correct == 0:
                    done(ValueError("To'g'ri variant oldiga * qo'ying."))
                    return
                if t == "SINGLE" and correct != 1:
                    done(ValueError("Bitta javobli savolda faqat bitta to'g'ri variant bo'ladi."))
                    return
                body["options"] = opts
            def cb(data, err):
                done(err)
                if err is None:
                    if (data or {}).get("versioned"):
                        self.win.toast("Test topshirilgan: savol yangi versiya sifatida saqlandi, eski javoblar o'zgarmaydi.", timeout=6)
                    self.changed()

            if question:
                self.api.patch(f"questions/{existing['id']}", body, cb)
            else:
                self.api.post(f"quizzes/{self.quiz['id']}/questions", body, cb)

        fields = []
        if question:
            fields.append({"name": "_t", "label": "Turi", "type": "info", "value": dict(QUESTION_TYPES).get(qtype, qtype)})
        else:
            fields.append({"name": "type", "label": "Turi", "type": "choice", "options": QUESTION_TYPES, "value": "SINGLE"})
        fields += [
            {"name": "text", "label": "Savol matni", "type": "text", "value": existing.get("text"), "required": True, "height": 100},
            {"name": "points", "label": "Ball", "type": "int", "min": 1, "max": 100, "value": existing.get("points") or 1},
            {"name": "answers", "label": "Variantlar: har biri alohida qatorda, to'g'risi oldiga * (matnli savolda — "
                                         "qabul qilinadigan javoblar)", "type": "text", "value": answers, "height": 160},
        ]
        form(self.win, "Savolni tahrirlash" if question else "Savol qo'shish", fields, submit,
             submit_label="Saqlash" if question else "Qo'shish", width=600)

    def delete_question(self, question):
        confirm(self.win, "Savol o'chirilsinmi?", question["text"][:200], "O'chirish",
                lambda: self.api.delete(f"questions/{question['id']}", done_toast(
                    self.win, None, lambda d: (self.win.toast("Savol arxivlandi (topshirilgan javoblar tarixi saqlanadi)"
                                                              if (d or {}).get("archived") else "Savol o'chirildi"), self.changed()))))

    def start(self, button):
        button.set_sensitive(False)

        def done(data, err):
            button.set_sensitive(True)
            if err:
                self.win.toast(err.message, timeout=5)
                return
            self.push(AttemptPage(self.win, data["attempt"], on_finished=self.reload))

        self.api.post(f"quizzes/{self.quiz_id}/attempts", {}, done)


class AttemptPage(Page):
    refreshable = False

    def __init__(self, win, attempt, on_finished=None):
        super().__init__(win, attempt["quiz"]["title"])
        self.attempt = attempt
        self.on_finished = on_finished
        self.answers = {}  # question id -> callable returning list[str]
        self.submitted = False
        self._timer = 0
        self.timer_label = Gtk.Label()
        self.timer_label.add_css_class("numeric")
        self.timer_label.add_css_class("heading")
        self.header.pack_end(self.timer_label)
        self.set_content(self._build())
        deadline = fmt.parse(attempt.get("deadline")) if attempt.get("deadline") else None
        self.deadline = deadline
        if deadline:
            self._tick()
            self._timer = GLib.timeout_add_seconds(1, self._tick)
        self.connect("hidden", lambda *_: self._stop())

    def _stop(self):
        if self._timer:
            GLib.source_remove(self._timer)
            self._timer = 0

    def _tick(self):
        left = self.deadline.difference(fmt.now()) // 1_000_000
        self.timer_label.set_text(self.settings.text("quiz_time_left", "Qolgan vaqt: {time}", time=fmt.duration(left)))
        if left <= 60:
            self.timer_label.add_css_class("error")
        if left <= 0 and not self.submitted:
            self._timer = 0
            self.submit(confirm=False)
            return False
        return True

    def _build(self):
        s = self.settings
        questions = self.attempt.get("questions") or []
        groups_ = []
        total = len(questions)
        for i, q in enumerate(questions, 1):
            g = group(s.text("question", "Savol {n}/{total}", n=i, total=total),
                      (f"{q.get('points', 1)} ball" + (" · " + s.text("quiz_multiple_hint", "Bir nechta javob tanlash mumkin")
                                                      if q["type"] == "MULTIPLE" else "")))
            text_row = Gtk.ListBoxRow(activatable=False)
            lb = label(q["text"], css=("heading",), selectable=True)
            for m in ("top", "bottom", "start", "end"):
                getattr(lb, f"set_margin_{m}")(12)
            text_row.set_child(lb)
            g.add(text_row)
            qid = str(q["id"])
            if q["type"] == "TEXT":
                entry = Adw.EntryRow(title=s.text("quiz_text_answer", "Javobingiz"))
                g.add(entry)
                self.answers[qid] = lambda e=entry: [e.get_text().strip()] if e.get_text().strip() else []
            else:
                checks = []
                first = None
                for o in q.get("options") or []:
                    cb = Gtk.CheckButton(valign=Gtk.Align.CENTER)
                    if q["type"] == "SINGLE":
                        if first is None:
                            first = cb
                        else:
                            cb.set_group(first)
                    r = Adw.ActionRow(title=GLib.markup_escape_text(o["text"]), title_lines=0, activatable_widget=cb)
                    r.add_prefix(cb)
                    g.add(r)
                    checks.append((str(o["id"]), cb))
                self.answers[qid] = lambda checks=checks: [oid for oid, cb in checks if cb.get_active()]
            groups_.append(g)

        submit = Gtk.Button(label=s.text("quiz_submit", "Topshirish"), halign=Gtk.Align.CENTER, margin_top=12)
        submit.add_css_class("pill")
        submit.add_css_class("suggested-action")
        submit.connect("clicked", lambda *_: self.submit())
        self.submit_button = submit
        sg = group()
        sg.add(submit)
        groups_.append(sg)
        return prefs_page(*groups_)

    def _collect(self):
        return {qid: get() for qid, get in self.answers.items()}

    def submit(self, confirm=True):
        if self.submitted:
            return
        answers = self._collect()
        if confirm:
            s = self.settings
            empty = sum(1 for v in answers.values() if not v)
            body = (s.text("quiz_unanswered", "{n} ta savolga javob berilmagan. Baribir topshirasizmi?", n=empty) if empty
                    else s.text("quiz_confirm_submit", "Javoblarni topshirasizmi?"))
            d = Adw.AlertDialog(heading=s.text("quiz_submit", "Topshirish"), body=body)
            d.add_response("cancel", "Bekor qilish")
            d.add_response("submit", s.text("quiz_submit", "Topshirish"))
            d.set_response_appearance("submit", Adw.ResponseAppearance.SUGGESTED)
            d.set_close_response("cancel")
            d.connect("response", lambda _d, r: r == "submit" and self._send(self._collect()))
            d.present(self.win)
            return
        self._send(answers)

    def _send(self, answers):
        if self.submitted:
            return
        self.submitted = True
        self.submit_button.set_sensitive(False)
        self._stop()
        self.stack.set_visible_child_name("loading")

        def done(data, err):
            if err:
                self.submitted = False
                self.submit_button.set_sensitive(True)
                self.stack.set_visible_child_name("content")
                self.win.toast(err.message, timeout=5)
                return
            self.timer_label.set_visible(False)
            self.set_content(self._result_view(data))
            if self.on_finished:
                self.on_finished()

        self.api.post(f"attempts/{self.attempt['id']}/submit", {"answers": answers}, done)

    def _result_view(self, data):
        s = self.settings
        res = data.get("result") or {}
        passed = res.get("passed")
        status = Adw.StatusPage(
            icon_name="object-select-symbolic" if passed else "dialog-warning-symbolic",
            title=s.text("quiz_result", "Natija: {score}/{max} ({percent}%)",
                         score=res.get("score"), max=res.get("max_score"), percent=f"{res.get('percent', 0):.0f}"),
        )
        desc = s.text("quiz_passed", "Tabriklaymiz, testdan o'tdingiz!") if passed else s.text("quiz_failed", "Afsuski, o'tish bali yetmadi.")
        if res.get("time_expired"):
            desc = s.text("quiz_time_expired", "Vaqt tugagan edi — javoblar hisobga olinmadi.")
        status.set_description(GLib.markup_escape_text(desc))

        box = Gtk.Box(orientation=Gtk.Orientation.VERTICAL, spacing=24)
        back = Gtk.Button(label=s.text("quiz_back", "Test sahifasiga qaytish"), halign=Gtk.Align.CENTER)
        back.add_css_class("pill")
        back.connect("clicked", lambda *_: self.get_ancestor(Adw.NavigationView).pop())
        box.append(back)

        review = data.get("review")
        if review:
            questions = {str(q["id"]): q for q in self.attempt.get("questions") or []}
            given = self._collect()
            rg = group("Javoblar tahlili")
            for i, rv in enumerate(review, 1):
                q = questions.get(str(rv["question_id"]), {})
                opts = {str(o["id"]): o["text"] for o in q.get("options") or []}
                correct = ", ".join(opts.get(str(c), str(c)) for c in rv.get("correct") or [])
                mine = ", ".join(opts.get(str(c), str(c)) for c in given.get(str(rv["question_id"]), [])) or "—"
                ok = rv.get("earned", 0) > 0
                rg.add(row(f"{i}. {q.get('text', '')}", f"Sizning javobingiz: {mine} · To'g'ri: {correct}",
                           suffix=pill(s.text("correct", "To'g'ri") if ok else s.text("wrong", "Noto'g'ri"), "success" if ok else "error")))
            clamp = Adw.Clamp(maximum_size=720, child=rg)
            box.append(clamp)
        status.set_child(box)
        return status


class QuizResultsPage(Page):
    def __init__(self, win, quiz):
        super().__init__(win, f"{quiz['title']} — natijalar")
        self.quiz = quiz

    def fetch(self, done):
        self.api.get(f"quizzes/{self.quiz['id']}/results", done)

    def render(self, data):
        rows = data.get("data") or []
        if not rows:
            self.show_empty("tisu-chart-symbolic", "Natijalar yo'q", "Bu testni hali hech kim topshirmagan.")
            return None
        passed = sum(1 for r in rows if r.get("passed"))
        g = group(f"Topshirganlar: {len(rows)}", f"O'tganlar: {passed}")
        for r in rows:
            g.add(row(fmt.name(r), f"{r.get('score')}/{r.get('max_score')} · {fmt.datetime(r.get('submitted_at'))}"
                      + (f" · {r['phone']}" if r.get("phone") else ""),
                      icon="avatar-default-symbolic",
                      suffix=pill(fmt.percent(r.get("percent")), "success" if r.get("passed") else "error")))
        return prefs_page(g)
