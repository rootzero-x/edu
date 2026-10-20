"""Courses → modules → lessons (+ materials) and the course's quizzes."""

from gi.repository import Adw, GLib, Gtk

from ..widgets import Page, group, join, label, link_row, pill, prefs_page, row
from .quizzes import QuizPage, quiz_subtitle

MATERIAL_ICONS = {
    "VIDEO": "video-x-generic-symbolic",
    "FILE": "folder-documents-symbolic",
    "LINK": "tisu-external-link-symbolic",
    "TEXT": "text-x-generic-symbolic",
}


def _short(text, n=140):
    text = " ".join((text or "").split())
    return text if len(text) <= n else text[: n - 1] + "…"


class CoursesPage(Page):
    def __init__(self, win):
        super().__init__(win, win.public.nav_label("/dashboard/courses", "Kurslar"))

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
                    on_activate=lambda c=c: self.push(CoursePage(self.win, c["id"], c["title"])))
            (mine if granted else others).add(r)
        has_mine = any((c.get("access") or {}).get("granted", True) for c in courses)
        has_others = any(not (c.get("access") or {}).get("granted", True) for c in courses)
        if not has_mine:
            mine.add(row(self.settings.text("courses_empty", "Siz hali guruhga qo'shilmagansiz."), icon="dialog-information-symbolic"))
        return prefs_page(mine, others if has_others else None)


class CoursePage(Page):
    def __init__(self, win, course_id, title):
        super().__init__(win, title)
        self.course_id = course_id

    def fetch(self, done):
        cb = join(done, ["course", "?quizzes"])
        self.api.get(f"courses/{self.course_id}", cb("course"))
        self.api.get(f"courses/{self.course_id}/quizzes", cb("?quizzes"))

    def render(self, data):
        s = self.settings
        resp = data["course"]
        c = resp["course"]
        access = resp.get("access") or {"granted": True}
        self.set_title(c["title"])
        modules = c.get("modules") or []

        if not access.get("granted") and not modules:
            # Outline hidden for non-enrolled users: show the API's access-denied message as the page.
            url = access.get("contact_url")
            self.show_status("dialog-password-symbolic", access.get("title") or "Ruxsat yo'q", access.get("message"),
                             access.get("button") if url else None, (lambda: self.win.open_uri(url)) if url else None)
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
                b.connect("clicked", lambda *_: self.win.open_uri(access["contact_url"]))
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
        if resp.get("can_edit"):
            about.add(link_row("Kursni tahrirlash", "Saytdagi boshqaruv panelida",
                               lambda: self.win.open_web(f"/dashboard/courses/{c['id']}"), icon="document-edit-symbolic"))
        groups_.append(about)

        mg = group(s.text("modules", "Modullar"))
        if not modules:
            mg.add(row(s.text("lessons_empty", "Darslar hali qo'shilmagan."), icon="tisu-book-symbolic"))
        for i, m in enumerate(modules):
            lessons = m.get("lessons") or []
            exp = Adw.ExpanderRow(title=GLib.markup_escape_text(m["title"]), subtitle=f"{len(lessons)} ta dars", expanded=i == 0)
            if not lessons:
                exp.add_row(row(s.text("lessons_empty", "Darslar hali qo'shilmagan.")))
            for les in lessons:
                suffix = None if les.get("published", True) else pill("yashirin", "dim")
                exp.add_row(row(les["title"], None, suffix=suffix, icon="text-x-generic-symbolic",
                                on_activate=lambda les=les: self.push(LessonPage(self.win, les["id"], les["title"]))))
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
                           on_activate=lambda q=q: self.push(QuizPage(self.win, q["id"], q["title"]))))
            groups_.append(qg)
        return prefs_page(*groups_)


def _text_row(text):
    lb = label(text, selectable=True)
    lb.set_margin_top(12)
    lb.set_margin_bottom(12)
    lb.set_margin_start(12)
    lb.set_margin_end(12)
    r = Gtk.ListBoxRow(activatable=False, child=lb)
    return r


class LessonPage(Page):
    def __init__(self, win, lesson_id, title):
        super().__init__(win, title)
        self.lesson_id = lesson_id
        self.title_widget = Adw.WindowTitle(title=title)
        self.header.set_title_widget(self.title_widget)

    def fetch(self, done):
        self.api.get(f"lessons/{self.lesson_id}", done)

    def render(self, data):
        s = self.settings
        les = data["lesson"]
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
        if les.get("body"):
            g = group()
            g.add(_text_row(les["body"]))
            groups_.append(g)
        materials = les.get("materials") or []
        if materials:
            mg = group(s.text("materials", "Materiallar"))
            for m in sorted(materials, key=lambda m: m.get("position") or 0):
                icon = MATERIAL_ICONS.get(m.get("type"), "text-x-generic-symbolic")
                if m.get("type") == "TEXT":
                    exp = Adw.ExpanderRow(title=GLib.markup_escape_text(m["title"]))
                    exp.add_prefix(Gtk.Image.new_from_icon_name(icon))
                    exp.add_row(_text_row(m.get("content") or ""))
                    mg.add(exp)
                elif m.get("url"):
                    mg.add(link_row(m["title"], m["url"], lambda m=m: self.win.open_uri(m["url"]), icon=icon))
            groups_.append(mg)
        if not groups_:
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
        if data.get("can_edit"):
            eg = group()
            eg.add(link_row("Darsni tahrirlash", "Saytdagi boshqaruv panelida",
                            lambda: self.win.open_web(f"/dashboard/lessons/{les['id']}"), icon="document-edit-symbolic"))
            groups_.append(eg)
        return prefs_page(*groups_)

    def go(self, lesson_id):
        if lesson_id:
            self.lesson_id = lesson_id
            self.stack.set_visible_child_name("loading")
            self.reload()
