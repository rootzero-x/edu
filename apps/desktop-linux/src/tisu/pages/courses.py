"""Courses → modules → lessons (+ materials) and the course's quizzes; full editing for authors/admins."""

from gi.repository import Adw, GLib, Gtk

from ..files import human_size, open_file, pick_and_upload, save_file_as
from ..forms import confirm, form
from ..webview import open_in_app
from ..widgets import Page, done_toast, group, icon_button, join, label, menu_button, pill, prefs_page, row
from .quizzes import QuizPage, quiz_subtitle

MATERIAL_ICONS = {
    "VIDEO": "video-x-generic-symbolic",
    "FILE": "folder-documents-symbolic",
    "LINK": "tisu-external-link-symbolic",
    "TEXT": "text-x-generic-symbolic",
}
MATERIAL_TYPES = [("VIDEO", "Video"), ("FILE", "Fayl"), ("LINK", "Havola"), ("TEXT", "Matn")]


def _short(text, n=140):
    text = " ".join((text or "").split())
    return text if len(text) <= n else text[: n - 1] + "…"


def material_form(page, lesson_id, material=None, on_done=None):
    m = material or {}
    if m.get("file"):  # an uploaded file: only the title can change
        form(page.win, "Materialni qayta nomlash", [{"name": "title", "label": "Nomi", "value": m.get("title"), "required": True}],
             lambda v, done: page.api.patch(f"materials/{m['id']}", {"title": v["title"]},
                                            lambda _d, err: (done(err), err is None and on_done and on_done())))
        return

    def submit(v, done):
        body = {"type": v["type"], "title": v["title"],
                "url": None if v["type"] == "TEXT" else (v["url"] or None),
                "content": v["content"] if v["type"] == "TEXT" else None}
        cb = lambda _d, err: (done(err), err is None and on_done and on_done())  # noqa: E731
        if material:
            page.api.patch(f"materials/{m['id']}", body, cb)
        else:
            page.api.post(f"lessons/{lesson_id}/materials", body, cb)

    form(page.win, "Materialni tahrirlash" if material else "Material qo'shish", [
        {"name": "type", "label": "Turi", "type": "choice", "options": MATERIAL_TYPES, "value": m.get("type", "VIDEO")},
        {"name": "title", "label": "Nomi", "value": m.get("title"), "required": True},
        {"name": "url", "label": "Manzil (https://…)", "value": m.get("url"), "required": True,
         "visible_if": lambda v: v.get("type") != "TEXT"},
        {"name": "content", "label": "Matn", "type": "text", "value": m.get("content"), "height": 200, "required": True,
         "visible_if": lambda v: v.get("type") == "TEXT"},
    ], submit, submit_label="Saqlash" if material else "Qo'shish")


class CoursesPage(Page):
    def __init__(self, win):
        super().__init__(win, win.public.nav_label("/dashboard/courses", "Kurslar"))
        if win.has("course.manage") or win.has("course.author"):
            self.header.pack_start(icon_button("list-add-symbolic", "Yangi kurs", self.create, css=()))

    def fetch(self, done):
        self.api.get("courses", done)

    def render(self, data):
        courses = data.get("data") or []
        if not courses:
            self.show_empty("tisu-book-symbolic", "Kurslar yo'q", "Hozircha e'lon qilingan kurs yo'q.")
            return None
        manage = self.win.has("course.manage")
        mine = group("Mening kurslarim" if not manage else None)
        others = group("Boshqa kurslar", "Bu kurslarga yozilish uchun administrator bilan bog'laning.")
        for c in courses:
            granted = (c.get("access") or {}).get("granted", True)
            suffix = []
            if not c.get("published", True):
                suffix.append(pill("yashirin", "dim"))
            if not granted:
                lock = Gtk.Image.new_from_icon_name("dialog-password-symbolic")
                lock.add_css_class("dim-label")
                suffix.append(lock)
            sub = _short(c.get("description")) or f"{c.get('modules_count', 0)} ta modul"
            r = row(c["title"], sub, icon="tisu-book-symbolic", suffix=suffix,
                    on_activate=lambda c=c: self.push(CoursePage(self.win, c["id"], c["title"], on_changed=self.reload)))
            (mine if granted else others).add(r)
        has_mine = any((c.get("access") or {}).get("granted", True) for c in courses)
        has_others = any(not (c.get("access") or {}).get("granted", True) for c in courses)
        if not has_mine:
            mine.add(row(self.settings.text("courses_empty", "Siz hali guruhga qo'shilmagansiz."), icon="dialog-information-symbolic"))
        return prefs_page(mine, others if has_others else None)

    def create(self):
        def submit(v, done):
            def ok(data, err):
                done(err)
                if not err:
                    c = data["course"]
                    self.reload()
                    self.push(CoursePage(self.win, c["id"], c["title"], on_changed=self.reload))

            self.api.post("courses", {"title": v["title"], "description": v["description"] or None}, ok)

        form(self.win, "Yangi kurs", [
            {"name": "title", "label": "Kurs nomi", "required": True},
            {"name": "description", "label": "Tavsif", "type": "text"},
        ], submit, submit_label="Yaratish")


class CoursePage(Page):
    def __init__(self, win, course_id, title, on_changed=None):
        super().__init__(win, title)
        self.course_id = course_id
        self.on_changed = on_changed

    def fetch(self, done):
        cb = join(done, ["course", "?quizzes"])
        self.api.get(f"courses/{self.course_id}", cb("course"))
        self.api.get(f"courses/{self.course_id}/quizzes", cb("?quizzes"))

    def changed(self, *_):
        self.reload()
        if self.on_changed:
            self.on_changed()

    def render(self, data):
        s = self.settings
        resp = data["course"]
        c = self.course = resp["course"]
        edit = self.can_edit = bool(resp.get("can_edit"))
        access = resp.get("access") or {"granted": True}
        self.set_title(c["title"])
        modules = c.get("modules") or []

        if not access.get("granted") and not modules:
            # Outline hidden for non-enrolled users: show the API's access-denied message as the page.
            url = access.get("contact_url")
            self.show_status("dialog-password-symbolic", access.get("title") or "Ruxsat yo'q", access.get("message"),
                             access.get("button") if url else None, (lambda: self.win.open_external(url)) if url else None)
            return None

        groups_ = []
        if not access.get("granted"):
            g = group()
            r = Adw.ActionRow(title=GLib.markup_escape_text(access.get("title") or "Ruxsat yo'q"),
                              subtitle=GLib.markup_escape_text(access.get("message") or ""), subtitle_lines=0)
            r.add_prefix(Gtk.Image.new_from_icon_name("dialog-password-symbolic"))
            if access.get("contact_url"):
                b = Gtk.Button(label=access.get("button") or "Bog'lanish", valign=Gtk.Align.CENTER)
                b.add_css_class("suggested-action")
                b.connect("clicked", lambda *_: self.win.open_external(access["contact_url"]))
                r.add_suffix(b)
            g.add(r)
            groups_.append(g)

        about = group()
        if c.get("description"):
            about.add(_text_row(c["description"]))
        facts = []
        if c.get("duration_months"):
            facts.append(f"{c['duration_months']} oy")
        facts.append(f"{len(modules)} ta modul")
        facts.append(f"{sum(len(m.get('lessons') or []) for m in modules)} ta dars")
        about.set_description(" · ".join(facts))
        groups_.append(about)

        if edit:
            mg_ = group("Boshqarish")
            pub = Adw.SwitchRow(title="E'lon qilingan", subtitle="O'chirilsa, kurs o'quvchilarga ko'rinmaydi",
                                active=bool(c.get("published")))
            pub.connect("notify::active", lambda w, *_: self.api.patch(
                f"courses/{c['id']}", {"published": w.get_active()},
                done_toast(self.win, "Kurs e'lon qilindi" if w.get_active() else "Kurs yashirildi", self.changed)))
            mg_.add(pub)
            mg_.add(row("Kursni tahrirlash", "Nomi, tavsifi, muqova va davomiyligi", icon="document-edit-symbolic",
                        on_activate=self.edit_course))
            mg_.add(row("Modul qo'shish", None, icon="list-add-symbolic", on_activate=self.add_module))
            if self.win.has("quiz.manage"):
                mg_.add(row("Test qo'shish", None, icon="tisu-quiz-symbolic", on_activate=self.add_quiz))
            mg_.add(row("Kursni o'chirish", "Guruhlar biriktirilgan kursni o'chirib bo'lmaydi — yashiring",
                        icon="edit-delete-symbolic", on_activate=self.delete_course))
            groups_.append(mg_)

        mg = group(s.text("modules", "Modullar"))
        if not modules:
            mg.add(row(s.text("lessons_empty", "Darslar hali qo'shilmagan."), icon="tisu-book-symbolic"))
        for i, m in enumerate(modules):
            lessons = m.get("lessons") or []
            exp = Adw.ExpanderRow(title=GLib.markup_escape_text(m["title"]), subtitle=f"{len(lessons)} ta dars", expanded=i == 0)
            if edit:
                exp.add_suffix(menu_button([
                    ("Dars qo'shish", lambda m=m: self.add_lesson(m)),
                    ("Nomini o'zgartirish", lambda m=m: self.rename_module(m)),
                    ("Modulni o'chirish", lambda m=m: self.delete_module(m), True),
                ]))
            if not lessons:
                exp.add_row(row(s.text("lessons_empty", "Darslar hali qo'shilmagan.")))
            for les in lessons:
                suffix = None if les.get("published", True) else pill("qoralama", "dim")
                exp.add_row(row(les["title"], None, suffix=suffix, icon="text-x-generic-symbolic",
                                on_activate=lambda les=les: self.push(LessonPage(self.win, les["id"], les["title"],
                                                                                 on_changed=self.changed))))
            mg.add(exp)
        groups_.append(mg)

        quizzes = (data.get("quizzes") or {}).get("data") or []
        if quizzes:
            qg = group(s.text("quizzes", "Testlar"))
            for q in quizzes:
                suffix = []
                if q.get("my_passed"):
                    suffix.append(pill("o'tdi", "success"))
                elif q.get("my_best_percent") is not None:
                    suffix.append(pill(f"{q['my_best_percent']:.0f}%", "warning"))
                if q.get("is_final"):
                    suffix.append(pill("yakuniy", "accent"))
                if q.get("locked"):
                    lock = Gtk.Image.new_from_icon_name("dialog-password-symbolic")
                    lock.add_css_class("dim-label")
                    suffix.append(lock)
                if not q.get("published", True):
                    suffix.append(pill("yashirin", "dim"))
                qg.add(row(q["title"], quiz_subtitle(s, q), icon="tisu-quiz-symbolic", suffix=suffix,
                           on_activate=lambda q=q: self.push(QuizPage(self.win, q["id"], q["title"], on_changed=self.changed))))
            groups_.append(qg)
        return prefs_page(*groups_)

    # ── Editing ──────────────────────────────────────────────
    def edit_course(self):
        c = self.course

        def submit(v, done):
            self.api.patch(f"courses/{c['id']}", {
                "title": v["title"], "description": v["description"] or None, "cover_url": v["cover_url"] or None,
                "duration_months": v["duration_months"]}, lambda _d, err: (done(err), err is None and self.changed()))

        form(self.win, "Kursni tahrirlash", [
            {"name": "title", "label": "Kurs nomi", "value": c.get("title"), "required": True},
            {"name": "description", "label": "Tavsif", "type": "text", "value": c.get("description"), "height": 160},
            {"name": "cover_url", "label": "Muqova rasmi manzili (ixtiyoriy)", "value": c.get("cover_url")},
            {"name": "duration_months", "label": "Davomiyligi (oy)", "type": "int", "min": 1, "max": 60,
             "value": c.get("duration_months") or 1},
        ], submit)

    def delete_course(self):
        c = self.course

        def yes():
            def ok(_d, err):
                if err:
                    self.win.toast(err.message, timeout=6)
                    return
                self.win.toast("Kurs o'chirildi")
                if self.on_changed:
                    self.on_changed()
                self.get_ancestor(Adw.NavigationView).pop()

            self.api.delete(f"courses/{c['id']}", ok)

        confirm(self.win, "Kurs o'chirilsinmi?", f"«{c['title']}» butunlay o'chiriladi: modullar, darslar va materiallar bilan.",
                "O'chirish", yes)

    def add_module(self):
        form(self.win, "Modul qo'shish", [{"name": "title", "label": "Modul nomi", "required": True}],
             lambda v, done: self.api.post(f"courses/{self.course_id}/modules", {"title": v["title"]},
                                           lambda _d, err: (done(err), err is None and self.changed())),
             submit_label="Qo'shish")

    def rename_module(self, m):
        form(self.win, "Modul nomi", [{"name": "title", "label": "Modul nomi", "value": m["title"], "required": True}],
             lambda v, done: self.api.patch(f"modules/{m['id']}", {"title": v["title"]},
                                            lambda _d, err: (done(err), err is None and self.changed())))

    def delete_module(self, m):
        confirm(self.win, "Modul o'chirilsinmi?", f"«{m['title']}» ichidagi barcha darslar ham o'chiriladi.", "O'chirish",
                lambda: self.api.delete(f"modules/{m['id']}", done_toast(self.win, "Modul o'chirildi", self.changed)))

    def add_lesson(self, m):
        def submit(v, done):
            def ok(data, err):
                done(err)
                if not err:
                    self.changed()
                    les = data["lesson"]
                    self.push(LessonPage(self.win, les["id"], les["title"], on_changed=self.changed))

            self.api.post(f"modules/{m['id']}/lessons", {"title": v["title"], "body": v["body"] or None,
                                                         "published": v["published"]}, ok)

        form(self.win, f"Dars qo'shish — {m['title']}", [
            {"name": "title", "label": "Dars nomi", "required": True},
            {"name": "body", "label": "Matn (ixtiyoriy)", "type": "text", "height": 160},
            {"name": "published", "label": "E'lon qilingan", "type": "switch", "value": True},
        ], submit, submit_label="Qo'shish")

    def add_quiz(self):
        def submit(v, done):
            def ok(data, err):
                done(err)
                if not err:
                    self.changed()
                    q = data["quiz"]
                    self.push(QuizPage(self.win, q["id"], q["title"], on_changed=self.changed))

            self.api.post(f"courses/{self.course_id}/quizzes", {"title": v["title"], "is_final": v["is_final"]}, ok)

        form(self.win, "Yangi test", [
            {"name": "title", "label": "Test nomi", "required": True},
            {"name": "is_final", "label": "Yakuniy test (sertifikat sharti)", "type": "switch", "value": False},
        ], submit, submit_label="Yaratish")


def _text_row(text):
    lb = label(text, selectable=True)
    lb.set_margin_top(12)
    lb.set_margin_bottom(12)
    lb.set_margin_start(12)
    lb.set_margin_end(12)
    r = Gtk.ListBoxRow(activatable=False, child=lb)
    return r


class LessonPage(Page):
    def __init__(self, win, lesson_id, title, on_changed=None):
        super().__init__(win, title)
        self.lesson_id = lesson_id
        self.on_changed = on_changed
        self.title_widget = Adw.WindowTitle(title=title)
        self.header.set_title_widget(self.title_widget)

    def fetch(self, done):
        self.api.get(f"lessons/{self.lesson_id}", done)

    def changed(self, *_):
        self.reload()
        if self.on_changed:
            self.on_changed()

    def render(self, data):
        s = self.settings
        les = self.lesson = data["lesson"]
        edit = bool(data.get("can_edit"))
        nav = data.get("nav") or {}
        module = les.get("module") or {}
        course = module.get("course") or {}
        self.set_title(les["title"])
        self.title_widget.set_title(les["title"])
        sub = " · ".join(x for x in [course.get("title"), module.get("title")] if x)
        if nav.get("index"):
            sub = f"{nav['index']}/{nav['total']} · {sub}"
        self.title_widget.set_subtitle(sub)

        groups_ = []
        if edit:
            eg = group("Boshqarish")
            pub = Adw.SwitchRow(title="E'lon qilingan", subtitle="O'chirilsa, dars o'quvchilarga ko'rinmaydi (qoralama)",
                                active=bool(les.get("published")))
            pub.connect("notify::active", lambda w, *_: self.api.patch(
                f"lessons/{les['id']}", {"published": w.get_active()},
                done_toast(self.win, "Dars e'lon qilindi" if w.get_active() else "Dars qoralamaga o'tkazildi", self.changed)))
            eg.add(pub)
            eg.add(row("Darsni tahrirlash", "Nomi va matni", icon="document-edit-symbolic", on_activate=self.edit_lesson))
            eg.add(row("Material qo'shish", "Video, havola yoki matn", icon="list-add-symbolic",
                       on_activate=lambda: material_form(self, les["id"], on_done=self.changed)))
            if self.settings.get("uploads.enabled", True) is not False:
                eg.add(row("Fayl yuklash", f"PDF, Word, Excel, rasm, audio, video · {self.settings.int('uploads.max_mb', 20)} MB gacha",
                           icon="document-open-symbolic", on_activate=lambda: pick_and_upload(
                               self.win, self.api, f"lessons/{les['id']}/materials/upload",
                               lambda _d: (self.win.toast("Fayl yuklandi"), self.changed()))))
            eg.add(row("Darsni o'chirish", None, icon="edit-delete-symbolic", on_activate=self.delete_lesson))
            groups_.append(eg)

        if les.get("body"):
            g = group()
            g.add(_text_row(les["body"]))
            groups_.append(g)
        materials = les.get("materials") or []
        if materials:
            mg = group(s.text("materials", "Materiallar"))
            for m in sorted(materials, key=lambda m: m.get("position") or 0):
                icon = MATERIAL_ICONS.get(m.get("type"), "text-x-generic-symbolic")
                actions = menu_button([
                    ("Tahrirlash", lambda m=m: material_form(self, les["id"], m, on_done=self.changed)),
                    ("O'chirish", lambda m=m: self.delete_material(m), True),
                ]) if edit else None
                if m.get("type") == "TEXT":
                    exp = Adw.ExpanderRow(title=GLib.markup_escape_text(m["title"]))
                    exp.add_prefix(Gtk.Image.new_from_icon_name(icon))
                    if actions:
                        exp.add_suffix(actions)
                    exp.add_row(_text_row(m.get("content") or ""))
                    mg.add(exp)
                elif m.get("file"):
                    f = m["file"]
                    r = row(m["title"], f"{f.get('original_name')} · {human_size(f.get('size'))}", icon=icon, chevron=False,
                            on_activate=lambda f=f: open_file(self.win, self.api, f["id"]))
                    r.add_suffix(icon_button("document-save-symbolic", "Saqlash…",
                                             lambda f=f: save_file_as(self.win, self.api, f["id"], f.get("original_name"))))
                    if actions:
                        r.add_suffix(actions)
                    mg.add(r)
                elif m.get("url"):
                    r = row(m["title"], m["url"], icon=icon, chevron=True,
                            on_activate=lambda m=m: open_in_app(self, m["title"], m["url"]))
                    if actions:
                        r.add_suffix(actions)
                    mg.add(r)
            groups_.append(mg)
        if not les.get("body") and not materials:
            g = group()
            g.add(row(s.text("lessons_empty", "Darslar hali qo'shilmagan."), icon="text-x-generic-symbolic"))
            groups_.append(g)

        navbox = Gtk.Box(spacing=12, homogeneous=True, margin_top=12)
        prev_b = Gtk.Button(child=Adw.ButtonContent(icon_name="go-previous-symbolic", label="Oldingi dars"))
        next_b = Gtk.Button(child=Adw.ButtonContent(icon_name="go-next-symbolic", label="Keyingi dars"))
        next_b.add_css_class("suggested-action")
        for b, target in ((prev_b, nav.get("prev")), (next_b, nav.get("next"))):
            b.add_css_class("pill")
            b.set_sensitive(bool(target))
            b.connect("clicked", lambda _b, t=target: self.go(t))
            navbox.append(b)
        ng = group()
        ng.add(navbox)
        groups_.append(ng)
        return prefs_page(*groups_)

    def go(self, lesson_id):
        if lesson_id:
            self.lesson_id = lesson_id
            self.stack.set_visible_child_name("loading")
            self.reload()

    def edit_lesson(self):
        les = self.lesson
        form(self.win, "Darsni tahrirlash", [
            {"name": "title", "label": "Dars nomi", "value": les.get("title"), "required": True},
            {"name": "body", "label": "Matn", "type": "text", "value": les.get("body"), "height": 260},
        ], lambda v, done: self.api.patch(f"lessons/{les['id']}", {"title": v["title"], "body": v["body"] or None},
                                          lambda _d, err: (done(err), err is None and self.changed())), width=680)

    def delete_lesson(self):
        les = self.lesson

        def ok(_d, err):
            if err:
                self.win.toast(err.message, timeout=6)
                return
            self.win.toast("Dars o'chirildi")
            if self.on_changed:
                self.on_changed()
            self.get_ancestor(Adw.NavigationView).pop()

        confirm(self.win, "Dars o'chirilsinmi?", f"«{les['title']}» va uning materiallari o'chiriladi.", "O'chirish",
                lambda: self.api.delete(f"lessons/{les['id']}", ok))

    def delete_material(self, m):
        confirm(self.win, "Material o'chirilsinmi?", f"«{m['title']}»", "O'chirish",
                lambda: self.api.delete(f"materials/{m['id']}", done_toast(self.win, "Material o'chirildi", self.changed)))

