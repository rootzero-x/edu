"""Leads / CRM (lead.manage): list with status tabs and filters, lead card with calls, notes,
status changes, trial lesson, conversion to a student, and the funnel report."""

from gi.repository import GLib, Gtk

from .. import fmt
from ..forms import confirm, form
from ..widgets import Page, done_toast, group, icon_button, join, pill, prefs_page, row, stat_card, text_button
from .reports import DateRow

STATUSES = ["NEW", "CONTACTED", "TRIAL", "ENROLLED", "LOST"]
OPEN = ["NEW", "CONTACTED", "TRIAL"]
STATUS_CSS = {"NEW": "accent", "CONTACTED": "accent", "TRIAL": "warning", "ENROLLED": "success", "LOST": "error"}
HIDDEN_MANUAL_SOURCES = {"site", "bot", "import"}


def status_label(s, st):
    labels = s.get("leads.status_labels", {})
    defaults = {"NEW": "Yangi", "CONTACTED": "Bog'lanildi", "TRIAL": "Sinov darsi", "ENROLLED": "Yozildi", "LOST": "Rad etdi"}
    return (labels.get(st) if isinstance(labels, dict) else None) or defaults.get(st, st)


def sources(s):
    v = s.get("leads.sources", {})
    return v if isinstance(v, dict) and v else {"phone": "Telefon qo'ng'irog'i", "walk_in": "O'zi keldi", "other": "Boshqa"}


def lost_reasons(s):
    v = s.get("leads.lost_reasons", [])
    return v if isinstance(v, list) and v else ["Boshqa"]


def lead_form(page, lead, on_done):
    """Create (lead=None) or edit a lead. Options (owners, courses) come from /leads/options."""
    s = page.settings

    def open_with(opts):
        cur = lead or {}
        src = [(k, v) for k, v in sources(s).items() if k not in HIDDEN_MANUAL_SOURCES or k == cur.get("source")]
        courses = [(None, "—")] + [(c["id"], c["title"]) for c in opts.get("courses") or []]
        owners = [(None, "—")] + [(o["id"], o["name"]) for o in opts.get("data") or []]
        fields = [
            {"name": "name", "label": "Ism", "value": cur.get("name"), "required": True},
            {"name": "phone", "label": "Telefon", "value": cur.get("phone") or "+998", "required": True, "purpose": "phone"},
            {"name": "source", "label": "Manba", "type": "choice", "options": src, "value": cur.get("source") or "phone"},
            {"name": "course_id", "label": "Qiziqqan kurs", "type": "choice", "options": courses, "value": (cur.get("course") or {}).get("id")},
            {"name": "owner_id", "label": "Mas'ul", "type": "choice", "options": owners,
             "value": (cur.get("owner") or {}).get("id") or (None if lead else (page.win.user or {}).get("id"))},
            {"name": "next_contact_on", "label": "Keyingi aloqa (YYYY-MM-DD)", "type": "date",
             "value": cur.get("next_contact_on") or ("" if lead else fmt.now().format("%Y-%m-%d"))},
            {"name": "notes", "label": "Izoh", "type": "text", "value": cur.get("notes"), "height": 100},
        ]
        if lead is None:
            fields.append({"name": "comment", "label": "Arizadagi izoh (ixtiyoriy)", "type": "text", "height": 70})

        def submit(v, done):
            body = {k: (v.get(k) if v.get(k) not in ("",) else None) for k in
                    ("name", "phone", "source", "course_id", "owner_id", "next_contact_on", "notes", "comment") if k in v}

            def ok(data, err):
                if err and err.data.get("existing_lead_id"):
                    done(err)
                    page.win.toast("Bu raqam bilan ochiq ariza bor — u ochildi.")
                    page.push(LeadPage(page.win, err.data["existing_lead_id"], on_changed=on_done))
                    return
                done(err)
                if not err:
                    on_done((data or {}).get("lead"))

            if lead is None:
                page.api.post("leads", body, ok)
            else:
                page.api.patch(f"leads/{lead['id']}", body, ok)

        form(page.win, "Arizani tahrirlash" if lead else "Yangi ariza", fields, submit,
             submit_label="Saqlash" if lead else "Qo'shish", width=560)

    page.api.get("leads/options", lambda d, e: page.win.toast(e.message) if e else open_with(d or {}))


class LeadsPage(Page):
    VIEWS = [("open", "Ochiq")] + [(st, None) for st in STATUSES] + [("all", "Barchasi")]

    def __init__(self, win):
        super().__init__(win, win.public.nav_label("/dashboard/leads", "Arizalar"))
        self.items = []
        self.page_no = 1
        self.pages = 1
        self.counts = {}
        self.header.pack_start(icon_button("list-add-symbolic", "Yangi ariza", lambda: lead_form(self, None, self._created), css=()))
        self.header.pack_start(icon_button("tisu-chart-symbolic", "Voronka (konversiya)",
                                           lambda: self.push(LeadsFunnelPage(self.win)), css=()))
        bar = Gtk.Box(spacing=6, margin_start=12, margin_end=12, margin_bottom=6)
        self.search = Gtk.SearchEntry(placeholder_text="Ism yoki telefon", hexpand=True)
        self.search.connect("search-changed", lambda *_: self._debounce())
        self.view_dd = Gtk.DropDown(model=Gtk.StringList.new(self._view_labels()))
        self.src_keys = [None] + list(sources(win.public))
        self.src_dd = Gtk.DropDown(model=Gtk.StringList.new(["Barcha manbalar"] + list(sources(win.public).values())))
        self.due = Gtk.ToggleButton(label="Bugun qo'ng'iroq", tooltip_text="Bugun yoki muddati o'tgan aloqa sanasi")
        for w in (self.view_dd, self.src_dd):
            w.connect("notify::selected", lambda *_: self._loaded and self.reload())
        self.due.connect("toggled", lambda *_: self.reload())
        for w in (self.search, self.view_dd, self.src_dd, self.due):
            bar.append(w)
        self.toolbar.add_top_bar(bar)
        self._timer = 0

    def _view_labels(self):
        out = []
        for key, lbl in self.VIEWS:
            text = lbl or status_label(self.settings, key)
            n = sum(self.counts.get(x, 0) for x in OPEN) if key == "open" else self.counts.get(key) if key in STATUSES else None
            out.append(f"{text} · {n}" if n else text)
        return out

    def _debounce(self):
        if self._timer:
            GLib.source_remove(self._timer)
        self._timer = GLib.timeout_add(350, lambda: (setattr(self, "_timer", 0), self.reload()) and False)

    def _created(self, lead):
        self.reload()
        if lead:
            self.push(LeadPage(self.win, lead["id"], on_changed=self.reload))

    def query(self, page=1):
        key = self.VIEWS[self.view_dd.get_selected()][0]
        q = {"q": self.search.get_text().strip() or None, "page": page,
             "status": ",".join(OPEN) if key == "open" else (None if key == "all" else key),
             "source": self.src_keys[self.src_dd.get_selected()]}
        if self.due.get_active():
            q["due"] = "today"
        return q

    def fetch(self, done):
        self.page_no = 1
        self.api.get("leads", done, query=self.query())

    def render(self, data):
        self.items = list(data.get("data") or [])
        self.pages = (data.get("meta") or {}).get("pages", 1)
        self.counts = data.get("counts") or {}
        self.due_today = data.get("due_today") or 0
        sel = self.view_dd.get_selected()
        self.view_dd.set_model(Gtk.StringList.new(self._view_labels()))
        self.view_dd.set_selected(sel)
        self.due.set_label(f"Bugun qo'ng'iroq · {self.due_today}" if self.due_today else "Bugun qo'ng'iroq")
        if not self.items:
            self.show_status("tisu-people-symbolic", "Arizalar yo'q", "Filtrni o'zgartiring yoki yangi ariza qo'shing.",
                             "Yangi ariza", lambda: lead_form(self, None, self._created))
            return None
        return self._build()

    def _build(self):
        s = self.settings
        g = group()
        today = fmt.now().format("%Y-%m-%d")
        for ld in self.items:
            bits = [ld.get("phone"), sources(s).get(ld.get("source"), ld.get("source")), (ld.get("course") or {}).get("title")]
            nc = ld.get("next_contact_on")
            if nc and ld.get("status") in OPEN:
                bits.append(("⚠ " if nc <= today else "") + f"aloqa: {fmt.date(nc)}")
            suffix = [pill(status_label(s, ld["status"]), STATUS_CSS.get(ld["status"], "dim"))]
            g.add(row(ld["name"], " · ".join(b for b in bits if b), icon="avatar-default-symbolic", suffix=suffix,
                      on_activate=lambda ld=ld: self.push(LeadPage(self.win, ld["id"], on_changed=self.reload))))
        more = None
        if self.page_no < self.pages:
            more = group()
            b = text_button("Ko'proq ko'rsatish", self.load_more, css=("pill",))
            b.set_halign(Gtk.Align.CENTER)
            more.add(b)
        return prefs_page(g, more)

    def load_more(self):
        def done(data, err):
            if err:
                self.win.toast(err.message)
                return
            self.page_no += 1
            self.items.extend(data.get("data") or [])
            self.set_content(self._build())

        self.api.get("leads", done, query=self.query(self.page_no + 1))


class LeadPage(Page):
    def __init__(self, win, lead_id, on_changed=None):
        super().__init__(win, "Ariza")
        self.lead_id = lead_id
        self.on_changed = on_changed

    def fetch(self, done):
        self.api.get(f"leads/{self.lead_id}", done)

    def changed(self, *_):
        self.reload()
        if self.on_changed:
            self.on_changed()

    def render(self, data):
        s = self.settings
        ld = self.lead = data["lead"]
        self.set_title(ld["name"])
        st = ld["status"]

        info = group(ld["name"], None, suffix=pill(status_label(s, st), STATUS_CSS.get(st, "dim")))
        info.add(row("Telefon", ld.get("phone"), icon="phone-symbolic"))
        info.add(row("Manba", sources(s).get(ld.get("source"), ld.get("source")), icon="send-to-symbolic"))
        if ld.get("course"):
            info.add(row("Kurs", ld["course"]["title"], icon="tisu-book-symbolic"))
        if ld.get("owner"):
            info.add(row("Mas'ul", ld["owner"]["name"], icon="avatar-default-symbolic"))
        if ld.get("next_contact_on"):
            info.add(row("Keyingi aloqa", fmt.date(ld["next_contact_on"]), icon="alarm-symbolic"))
        tr = ld.get("trial")
        if tr:
            info.add(row("Sinov darsi", f"{tr.get('group_name')} · {fmt.datetime(tr.get('starts_at'))}"
                         + (" · bekor qilingan" if tr.get("cancelled") else ""), icon="x-office-calendar-symbolic"))
        if ld.get("lost_reason"):
            info.add(row("Rad etish sababi", ld["lost_reason"], icon="dialog-information-symbolic"))
        for key, title in (("comment", "Arizadagi izoh"), ("notes", "Izoh")):
            if ld.get(key):
                r = row(title, ld[key], icon="text-x-generic-symbolic")
                r.set_subtitle_lines(0)
                info.add(r)
        if data.get("existing_user"):
            u = data["existing_user"]
            info.add(row("Shu raqamli foydalanuvchi bor", f"{u.get('name')} · {s.role_label(u.get('role'))}",
                         icon="dialog-warning-symbolic", on_activate=lambda: self._open_user(u["id"])))
        if ld.get("student_id"):
            info.add(row("O'quvchi profili", None, icon="avatar-default-symbolic", on_activate=lambda: self._open_user(ld["student_id"])))

        acts = group("Amallar")
        if st == "NEW":
            acts.add(row(status_label(s, "CONTACTED"), "Bog'lanildi deb belgilash", icon="phone-symbolic",
                         on_activate=lambda: self.set_status("CONTACTED")))
        if st in OPEN:
            acts.add(row(status_label(s, "TRIAL"), "Sinov darsiga yozish", icon="x-office-calendar-symbolic", on_activate=self.trial))
            acts.add(row("O'quvchiga aylantirish", "Akkaunt ochish va guruhga qo'shish", icon="object-select-symbolic",
                         on_activate=self.convert))
            acts.add(row(status_label(s, "LOST"), "Sababini ko'rsatib yopish", icon="action-unavailable-symbolic", on_activate=self.lost))
        elif st == "LOST":
            acts.add(row("Qayta ochish", None, icon="edit-undo-symbolic", on_activate=lambda: self.set_status("CONTACTED")))
        acts.add(row("Tahrirlash", None, icon="document-edit-symbolic", on_activate=lambda: lead_form(self, ld, lambda _l: self.changed())))
        acts.add(row("Arizani o'chirish", None, icon="edit-delete-symbolic", on_activate=self.delete))

        note = group("Qo'ng'iroq yoki izoh")
        self.note_view = Gtk.TextView(wrap_mode=Gtk.WrapMode.WORD_CHAR, top_margin=8, bottom_margin=8, left_margin=8, right_margin=8)
        sw = Gtk.ScrolledWindow(child=self.note_view, min_content_height=70, hscrollbar_policy=Gtk.PolicyType.NEVER)
        sw.add_css_class("card")
        btns = Gtk.Box(spacing=6, halign=Gtk.Align.END, margin_top=6)
        btns.append(text_button("Izoh qo'shish", lambda: self.add_activity("NOTE"), css=("pill",)))
        btns.append(text_button("Qo'ng'iroq yozish", lambda: self.add_activity("CALL"), css=("pill", "suggested-action")))
        nb = Gtk.Box(orientation=Gtk.Orientation.VERTICAL)
        nb.append(sw)
        nb.append(btns)
        note.add(nb)

        hist = group("Tarix")
        for a in data.get("activities") or []:
            icon = {"CALL": "phone-symbolic", "NOTE": "text-x-generic-symbolic", "STATUS": "object-select-symbolic"}.get(
                a.get("type"), "dialog-information-symbolic")
            text = a.get("text") or self._describe(a)
            r = row(text or a.get("type"), " · ".join(x for x in [fmt.datetime(a.get("created_at")), a.get("user")] if x), icon=icon)
            r.set_title_lines(0)
            hist.add(r)
        others = data.get("other_leads") or []
        og = None
        if others:
            og = group("Shu raqamdan boshqa arizalar")
            for o in others:
                og.add(row(f"#{o['id']} · {status_label(s, o['status'])}", fmt.datetime(o.get("created_at")),
                           on_activate=lambda o=o: self.push(LeadPage(self.win, o["id"], on_changed=self.on_changed))))
        return prefs_page(info, acts, note, hist, og)

    def _describe(self, a):
        d = a.get("data") or {}
        s = self.settings
        if a.get("type") == "STATUS":
            return f"{status_label(s, d.get('from')) if d.get('from') else '—'} → {status_label(s, d.get('to'))}" + (
                f" ({d['lost_reason']})" if d.get("lost_reason") else "")
        ev = {"created": "Ariza yaratildi", "duplicate": "Takroriy ariza", "updated": "Ma'lumot o'zgartirildi"}
        return ev.get(d.get("event"), "")

    def _open_user(self, uid):
        from .users import UserPage

        self.push(UserPage(self.win, {"id": uid, "first_name": "", "role": "STUDENT"}, on_changed=None, load=True))

    # ── Actions ──────────────────────────────────────────────
    def set_status(self, status, extra=None, done=None):
        body = dict({"status": status}, **(extra or {}))

        def ok(data, err):
            if done:
                done(err)
            elif err:
                self.win.toast(err.message, timeout=6)
            if not err:
                self.win.toast(f"Holat: {status_label(self.settings, status)}")
                self.changed()

        self.api.post(f"leads/{self.lead_id}/status", body, ok)

    def lost(self):
        form(self.win, "Rad etdi", [
            {"name": "lost_reason", "label": "Sabab", "type": "choice", "options": [(r, r) for r in lost_reasons(self.settings)]},
            {"name": "note", "label": "Izoh (ixtiyoriy)", "type": "text", "height": 70},
        ], lambda v, done: self.set_status("LOST", {"lost_reason": v["lost_reason"], "note": v["note"] or None}, done),
            submit_label="Yopish")

    def trial(self):
        course = (self.lead.get("course") or {}).get("id")

        def open_with(data):
            sessions = data.get("data") or []
            if not sessions:
                self.win.toast("Yaqin 14 kunda mos dars topilmadi.")
                return
            opts = [(x["id"], f"{fmt.day_label(x['starts_at'])} {fmt.time(x['starts_at'])} · {x['group_name']}"
                     + (f" · {x['teacher']}" if x.get("teacher") else "")) for x in sessions]
            form(self.win, "Sinov darsi", [
                {"name": "trial_session_id", "label": "Dars", "type": "choice", "options": opts},
                {"name": "note", "label": "Izoh (ixtiyoriy)"},
            ], lambda v, done: self.set_status("TRIAL", {"trial_session_id": v["trial_session_id"], "note": v["note"] or None}, done),
                submit_label="Yozish")

        self.api.get("leads/trial-sessions", lambda d, e: self.win.toast(e.message) if e else open_with(d or {}),
                     query={"course_id": course})

    def convert(self):
        ld = self.lead
        parts = (ld.get("name") or "").split(" ", 1)

        def open_with(data):
            groups_ = [g for g in data.get("data") or [] if g.get("active")]
            cid = (ld.get("course") or {}).get("id")
            groups_.sort(key=lambda g: (g.get("course_id") != cid, g["name"]))
            opts = [(None, "Hozircha guruhsiz")] + [(g["id"], g["name"] + (f" · {g['course']['title']}" if g.get("course") else ""))
                                                   for g in groups_]
            default_group = ld.get("group", {}).get("id") if ld.get("group") else (groups_[0]["id"] if groups_ and groups_[0].get("course_id") == cid else None)

            def submit(v, done):
                body = {"first_name": v["first_name"], "last_name": v["last_name"] or None, "phone": v["phone"]}
                if v.get("group_id"):
                    body.update(group_id=v["group_id"], start_date=v["start_date"] or None)

                def ok(res, err):
                    done(err)
                    if not err:
                        joined = {"added": "guruhga qo'shildi", "rejoined": "guruhga qayta qo'shildi",
                                  "already": "allaqachon guruhda"}.get(res.get("joined"))
                        self.win.toast(("Yangi o'quvchi yaratildi" if res.get("created") else "Mavjud o'quvchiga bog'landi")
                                       + (f", {joined}" if joined else ""), timeout=5)
                        self.changed()

                self.api.post(f"leads/{self.lead_id}/convert", body, ok)

            form(self.win, "O'quvchiga aylantirish", [
                {"name": "first_name", "label": "Ism", "value": parts[0], "required": True},
                {"name": "last_name", "label": "Familiya", "value": parts[1] if len(parts) > 1 else ""},
                {"name": "phone", "label": "Telefon", "value": ld.get("phone"), "required": True, "purpose": "phone"},
                {"name": "group_id", "label": "Guruh", "type": "choice", "options": opts, "value": default_group},
                {"name": "start_date", "label": "Boshlash sanasi (YYYY-MM-DD)", "type": "date", "value": fmt.now().format("%Y-%m-%d"),
                 "visible_if": lambda v: bool(v.get("group_id"))},
            ], submit, submit_label="Aylantirish")

        self.api.get("groups", lambda d, e: self.win.toast(e.message) if e else open_with(d or {}))

    def add_activity(self, kind):
        buf = self.note_view.get_buffer()
        text = buf.get_text(buf.get_start_iter(), buf.get_end_iter(), False).strip()
        if not text:
            self.win.toast("Avval matnni yozing.")
            return
        self.api.post(f"leads/{self.lead_id}/activities", {"type": kind, "text": text},
                      done_toast(self.win, "Qo'ng'iroq yozildi" if kind == "CALL" else "Izoh qo'shildi", self.changed))

    def delete(self):
        def ok(_d, err):
            if err:
                self.win.toast(err.message, timeout=6)
                return
            self.win.toast("Ariza o'chirildi")
            if self.on_changed:
                self.on_changed()
            from gi.repository import Adw

            self.get_ancestor(Adw.NavigationView).pop()

        confirm(self.win, "Ariza o'chirilsinmi?", self.lead["name"], "O'chirish", lambda: self.api.delete(f"leads/{self.lead_id}", ok))


class LeadsFunnelPage(Page):
    def __init__(self, win):
        super().__init__(win, "Voronka")
        today = GLib.DateTime.new_now_local()
        today = GLib.DateTime.new_local(today.get_year(), today.get_month(), today.get_day_of_month(), 0, 0, 0)
        self.filters = group("Davr")
        self.from_row = DateRow("Boshlanish", today.add_days(-29), self.reload)
        self.to_row = DateRow("Tugash", today, self.reload)
        self.filters.add(self.from_row)
        self.filters.add(self.to_row)
        self._page = None

    def fetch(self, done):
        cb = join(done, ["stats"])
        self.api.get("leads/stats", cb("stats"), query={"from": self.from_row.value.format("%Y-%m-%d"),
                                                         "to": self.to_row.value.format("%Y-%m-%d")})

    def render(self, data):
        s = self.settings
        st = data["stats"]
        if self._page is not None:
            self._page.remove(self.filters)
        t = st.get("total") or {}
        flow = Gtk.FlowBox(selection_mode=Gtk.SelectionMode.NONE, column_spacing=12, row_spacing=12,
                           min_children_per_line=2, max_children_per_line=4, homogeneous=True)
        flow.append(stat_card("Arizalar", t.get("total", 0), "tisu-people-symbolic"))
        flow.append(stat_card(status_label(s, "TRIAL"), t.get("trial", 0), "x-office-calendar-symbolic"))
        flow.append(stat_card(status_label(s, "ENROLLED"), t.get("enrolled", 0), "object-select-symbolic", "success"))
        flow.append(stat_card("Konversiya", fmt.percent(t.get("conversion")), "tisu-chart-symbolic"))
        sg = group(f"{fmt.date(st.get('from'))} — {fmt.date(st.get('to'))}",
                   f"{status_label(s, 'CONTACTED')}: {t.get('contacted', 0)} · {status_label(s, 'LOST')}: {t.get('lost', 0)}")
        sg.add(flow)

        def table(title, rows_, name):
            g = group(title)
            for r in rows_:
                g.add(row(name(r), f"jami {r['total']} · sinov {r['trial']} · yozildi {r['enrolled']} · rad {r['lost']}",
                          suffix=pill(fmt.percent(r.get("conversion")), "success" if (r.get("conversion") or 0) >= 20 else "dim")))
            if not rows_:
                g.add(row("Ma'lumot yo'q.", icon="dialog-information-symbolic"))
            return g

        src = table("Manbalar bo'yicha", st.get("by_source") or [], lambda r: sources(s).get(r.get("source"), r.get("source") or "—"))
        crs = table("Kurslar bo'yicha", st.get("by_course") or [], lambda r: r.get("course_title") or "Kurs tanlanmagan")
        lr = group("Rad etish sabablari")
        for x in st.get("lost_reasons") or []:
            lr.add(row(x.get("reason") or "—", None, suffix=pill(str(x.get("count", 0)), "error")))
        if not st.get("lost_reasons"):
            lr.add(row("Ma'lumot yo'q.", icon="dialog-information-symbolic"))
        page = prefs_page(self.filters, sg, src, crs, lr)
        self._page = page
        return page

