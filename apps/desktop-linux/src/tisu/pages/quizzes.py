"""Quiz overview, taking a quiz (with time limit) and results."""

from gi.repository import Adw, GLib, Gtk

from .. import fmt
from ..widgets import Page, group, label, link_row, pill, prefs_page, row


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
    def __init__(self, win, quiz_id, title):
        super().__init__(win, title)
        self.quiz_id = quiz_id

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
        elif open_attempt or used < (q.get("max_attempts") or 0):
            b = Gtk.Button(label=s.text("quiz_continue", "Davom ettirish") if open_attempt else s.text("quiz_start", "Testni boshlash"),
                           halign=Gtk.Align.CENTER)
            b.add_css_class("pill")
            b.add_css_class("suggested-action")
            b.connect("clicked", lambda *_: self.start(b))
            actions.add(b)
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
        info = group(q["title"], (q.get("course") or {}).get("title"))
        info.add(row(quiz_subtitle(s, dict(q, questions_count=len(q.get("questions") or []))), None, icon="tisu-quiz-symbolic"))
        info.add(row(f"Urinishlar limiti: {q.get('max_attempts')}", "Yakuniy test" if q.get("is_final") else None,
                     icon="view-list-bullet-symbolic"))
        if not q.get("published"):
            info.add(row("Yashirin (o'quvchilarga ko'rinmaydi)", None, icon="dialog-information-symbolic"))
        acts = group()
        acts.add(row("Natijalar", "Topshirgan o'quvchilar", icon="tisu-chart-symbolic",
                     on_activate=lambda: self.push(QuizResultsPage(self.win, q))))
        acts.add(link_row(s.text("open_on_web", "Saytda ochish"), s.text("quiz_manage_on_web", "Testni tahrirlash — saytda."),
                          lambda: self.win.open_web(f"/dashboard/quizzes/{q['id']}/edit"), icon="document-edit-symbolic"))
        qs = group(f"Savollar ({len(q.get('questions') or [])})")
        for i, question in enumerate(q.get("questions") or [], 1):
            opts = question.get("options") or []
            correct = [o["text"] for o in opts if o.get("is_correct")] or question.get("accepted_answers") or []
            qs.add(row(f"{i}. {question['text']}", "To'g'ri: " + ", ".join(map(str, correct)) if correct else None))
        return prefs_page(info, acts, qs)

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
        status.add_css_class("success" if passed else "warning")

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
