"""Audit log (audit.view): who changed what, with filters and per-field diffs."""

from gi.repository import Adw, GLib, Gtk

from .. import fmt
from ..widgets import Page, group, pill, prefs_page, row, text_button
from .reports import DateRow


def _val(v):
    if v is None or v == "":
        return "—"
    if v is True:
        return "ha"
    if v is False:
        return "yo'q"
    return str(v)


class AuditPage(Page):
    def __init__(self, win):
        super().__init__(win, win.public.nav_label("/dashboard/audit", "Audit jurnali"))
        self.facets = None
        self.items = []
        self.page_no = 1
        self.pages = 1
        self.sel = {"actor_id": None, "action": None, "entity": None}
        today = GLib.DateTime.new_now_local()
        today = GLib.DateTime.new_local(today.get_year(), today.get_month(), today.get_day_of_month(), 0, 0, 0)
        self.filters = group("Filtr")
        self.from_row = DateRow("Dan", today.add_days(-6), self.reload)
        self.to_row = DateRow("Gacha", today, self.reload)
        self.dd_box = Gtk.Box(spacing=6, margin_top=6)
        self.filters.add(self.from_row)
        self.filters.add(self.to_row)
        self.filters.add(self.dd_box)
        self._page = None
        self.api.get("audit/facets", self._got_facets)

    def _got_facets(self, data, err):
        if err or not data:
            return
        self.facets = data
        while (c := self.dd_box.get_first_child()) is not None:
            self.dd_box.remove(c)
        al, el = data.get("action_labels") or {}, data.get("entity_labels") or {}
        prefixes = sorted({a.split(".")[0] for a in data.get("actions") or []})
        actions = [(None, "Barcha amallar")]
        for p in prefixes:
            actions.append((p, f"«{el.get(p, p)}» — hammasi"))
            actions += [(a, "   " + al.get(a, a)) for a in data.get("actions") or [] if a.split(".")[0] == p]
        opts = {
            "actor_id": [(None, "Hamma")] + [(a["id"], f"{a['name']} ({self.settings.role_label(a.get('role'))})") for a in data.get("actors") or []],
            "action": actions,
            "entity": [(None, "Barcha obyektlar")] + [(e, el.get(e, e)) for e in data.get("entities") or []],
        }
        for key, items in opts.items():
            dd = Gtk.DropDown(model=Gtk.StringList.new([i[1] for i in items]), hexpand=True)
            dd.connect("notify::selected", lambda d, *_, k=key, it=items: (self.sel.__setitem__(k, it[d.get_selected()][0]), self.reload()))
            self.dd_box.append(dd)

    def query(self, page=1):
        q = {"from": self.from_row.value.format("%Y-%m-%d"), "to": self.to_row.value.format("%Y-%m-%d"), "page": page}
        q.update({k: v for k, v in self.sel.items() if v})
        return q

    def fetch(self, done):
        self.page_no = 1
        self.api.get("audit", done, query=self.query())

    def render(self, data):
        self.items = list(data.get("data") or [])
        meta = data.get("meta") or {}
        self.pages, self.total = meta.get("pages", 1), meta.get("total", 0)
        return self._build()

    def _build(self):
        s = self.settings
        if self._page is not None:
            self._page.remove(self.filters)
        g = group(f"Yozuvlar: {self.total}")
        for e in self.items:
            actor = e.get("actor") or {}
            who = f"{actor.get('name')} · {s.role_label(actor.get('role'))}" if actor else "Tizim"
            target = " ".join(x for x in [e.get("entity_label") or e.get("entity"), e.get("entity_name") or (f"#{e['entity_id']}" if e.get("entity_id") else None)] if x)
            exp = Adw.ExpanderRow(title=GLib.markup_escape_text(f"{e.get('action_label') or e.get('action')}"),
                                  subtitle=GLib.markup_escape_text(f"{fmt.datetime(e.get('created_at'))} · {who} → {target}"))
            diff = e.get("diff") or []
            if diff:
                exp.add_suffix(pill(f"{len(diff)} o'zgarish", "dim"))
            for d in diff:
                r = row(str(d.get("field")), f"{_val(d.get('from'))}  →  {_val(d.get('to'))}")
                r.set_subtitle_lines(0)
                r.set_subtitle_selectable(True)
                exp.add_row(r)
            if e.get("note"):
                exp.add_row(row("Sabab", e["note"]))
            exp.add_row(row("Amal kodi", " · ".join(x for x in [e.get("action"), f"IP {e['ip']}" if e.get("ip") else None] if x)))
            g.add(exp)
        if not self.items:
            g.add(row("Bu filtr bo'yicha yozuv yo'q.", icon="edit-find-symbolic"))
        more = None
        if self.page_no < self.pages:
            more = group()
            b = text_button("Ko'proq ko'rsatish", self.load_more, css=("pill",))
            b.set_halign(Gtk.Align.CENTER)
            more.add(b)
        page = prefs_page(self.filters, g, more)
        self._page = page
        return page

    def load_more(self):
        def done(data, err):
            if err:
                self.win.toast(err.message)
                return
            self.page_no += 1
            self.items.extend(data.get("data") or [])
            self.set_content(self._build())

        self.api.get("audit", done, query=self.query(self.page_no + 1))


class SettingHistoryPage(Page):
    def __init__(self, win, key, label_):
        super().__init__(win, f"Tarix — {label_}")
        self.key = key

    def fetch(self, done):
        self.api.get(f"settings/{GLib.uri_escape_string(self.key, None, False)}/history", done)

    def render(self, data):
        items = data.get("data") or []
        if not items:
            self.show_empty("document-open-recent-symbolic", "Tarix bo'sh", "Bu sozlama hali o'zgartirilmagan.")
            return None
        g = group(None, self.key)
        import json

        for h in items:
            actor = (h.get("actor") or {}).get("name") or "Tizim"
            fr = json.dumps(h.get("from"), ensure_ascii=False) if isinstance(h.get("from"), (dict, list)) else _val(h.get("from"))
            to = json.dumps(h.get("to"), ensure_ascii=False) if isinstance(h.get("to"), (dict, list)) else _val(h.get("to"))
            r = row(f"{fmt.datetime(h.get('created_at'))} · {actor}", f"{fr}  →  {to}",
                    suffix=pill("standartga qaytarildi", "accent") if h.get("reset") else None)
            r.set_subtitle_lines(0)
            r.set_subtitle_selectable(True)
            g.add(r)
        return prefs_page(g)
