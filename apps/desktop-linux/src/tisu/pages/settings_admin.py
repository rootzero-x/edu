"""Platform settings (settings.manage): groups, search, typed editors, unsaved draft, reset to default."""

import json
import re

from gi.repository import Adw, GLib, Gtk

from ..forms import FormDialog, confirm
from ..widgets import Page, group, icon_button, label, pill, prefs_page, row

ROLE_COLUMNS = ["ADMIN", "TEACHER", "STUDENT", "PARENT"]


def _preview(v, n=80):
    if isinstance(v, bool):
        return "ha" if v else "yo'q"
    if isinstance(v, (list, dict)):
        s = json.dumps(v, ensure_ascii=False)
    else:
        s = str(v)
    s = " ".join(s.split())
    return (s[: n - 1] + "…") if len(s) > n else (s or "—")


class SettingsPage(Page):
    def __init__(self, win):
        super().__init__(win, win.public.nav_label("/dashboard/settings", "Sozlamalar"))
        self.items = []
        self.by_key = {}
        self.groups = {}
        self.permissions = []
        self.draft = {}
        self.save_buttons = []
        self._add_save_buttons(self.header)

        bar = Gtk.Box(margin_start=12, margin_end=12, margin_bottom=6)
        self.search = Gtk.SearchEntry(placeholder_text="Sozlamani nomi yoki kaliti bo'yicha qidirish", hexpand=True)
        self.search.connect("search-changed", lambda *_: self.items and self.set_content(self._build()))
        bar.append(self.search)
        self.toolbar.add_top_bar(bar)

    # ── Save / discard (shared by sub-pages) ─────────────────
    def _add_save_buttons(self, header):
        save = Gtk.Button(label="Saqlash", sensitive=bool(self.draft))
        save.add_css_class("suggested-action")
        save.connect("clicked", lambda *_: self.save())
        discard = Gtk.Button(icon_name="edit-undo-symbolic", tooltip_text="O'zgarishlarni bekor qilish", sensitive=bool(self.draft))
        discard.connect("clicked", lambda *_: self.discard())
        header.pack_end(save)
        header.pack_end(discard)
        self.save_buttons += [save, discard]

    def _dirty_changed(self):
        for b in self.save_buttons:
            b.set_sensitive(bool(self.draft))
        n = len(self.draft)
        for b in self.save_buttons[::2]:
            b.set_label(f"Saqlash ({n})" if n else "Saqlash")

    def value(self, key):
        return self.draft[key] if key in self.draft else self.by_key[key]["value"]

    def set_value(self, key, v):
        if v == self.by_key[key]["value"]:
            self.draft.pop(key, None)
        else:
            self.draft[key] = v
        self._dirty_changed()

    def save(self):
        if not self.draft:
            return
        values = dict(self.draft)

        def done(data, err):
            if err:
                self.win.toast(err.message, timeout=6)
                return
            for k, v in (data.get("values") or values).items():
                if k in self.by_key:
                    self.by_key[k]["value"] = v
            self.draft.clear()
            self._dirty_changed()
            self.win.toast(f"{len(values)} ta sozlama saqlandi")
            self.win.public.refresh()  # public texts may have changed
            self.refresh_views()

        self.api.patch("settings", {"values": values}, done)

    def discard(self):
        self.draft.clear()
        self._dirty_changed()
        self.refresh_views()
        self.win.toast("O'zgarishlar bekor qilindi")

    def refresh_views(self):
        self.set_content(self._build())
        nav = self.get_ancestor(Adw.NavigationView)
        page = nav.get_visible_page() if nav else None
        if isinstance(page, SettingsGroupPage):
            page.rebuild()

    def reset(self, key):
        item = self.by_key[key]

        def yes():
            def done(data, err):
                if err:
                    self.win.toast(err.message, timeout=5)
                    return
                item["value"] = data.get("value", item["default"])
                self.draft.pop(key, None)
                self._dirty_changed()
                self.win.toast("Standart qiymat tiklandi")
                self.win.public.refresh()
                self.refresh_views()

            self.api.delete(f"settings/{GLib.uri_escape_string(key, None, False)}", done)

        confirm(self.win, "Standart qiymat tiklansinmi?", f"«{item['label']}» — {_preview(item['default'])}", "Tiklash", yes)

    # ── Loading ──────────────────────────────────────────────
    def fetch(self, done):
        self.api.get("settings", done)

    def render(self, data):
        self.items = data.get("data") or []
        self.by_key = {i["key"]: i for i in self.items}
        self.groups = data.get("groups") or {}
        self.permissions = data.get("permissions") or []
        self.draft = {k: v for k, v in self.draft.items() if k in self.by_key}
        self._dirty_changed()
        return self._build()

    def ordered_groups(self):
        present = []
        for it in self.items:
            if it["group"] not in present:
                present.append(it["group"])
        order = list(self.groups)
        return sorted(present, key=lambda g: order.index(g) if g in order else len(order))

    def _build(self):
        q = self.search.get_text().strip().lower()
        if q:
            found = [i for i in self.items if q in i["key"].lower() or q in (i.get("label") or "").lower()]
            g = group(f"Natijalar: {len(found)}")
            for it in found[:200]:
                g.add(self.item_row(it, show_group=True))
            if not found:
                g.add(row("Hech narsa topilmadi.", icon="edit-find-symbolic"))
            return prefs_page(g)
        g = group(None, "Barcha qiymatlar serverda saqlanadi va sayt, bot, Mini App hamda ilovaga birdek ta'sir qiladi.")
        for name in self.ordered_groups():
            items = [i for i in self.items if i["group"] == name]
            dirty = sum(1 for i in items if i["key"] in self.draft)
            suffix = [pill(f"{dirty} saqlanmagan", "warning")] if dirty else [pill(str(len(items)), "dim")]
            g.add(row(self.groups.get(name, name), None, icon="preferences-system-symbolic", suffix=suffix,
                      on_activate=lambda n=name: self.push(SettingsGroupPage(self, n))))
        return prefs_page(g)

    # ── Item rows & editors ──────────────────────────────────
    def item_row(self, it, show_group=False):
        key, t = it["key"], it["type"]
        v = self.value(key)
        title = GLib.markup_escape_text(it.get("label") or key)
        badges = []
        if key in self.draft:
            badges.append("saqlanmagan")
        elif v != it["default"]:
            badges.append("o'zgartirilgan")
        if it.get("public"):
            badges.append("ochiq")
        if show_group:
            badges.append(self.groups.get(it["group"], it["group"]))
        sub = GLib.markup_escape_text(" · ".join([key] + badges))

        reset = icon_button("document-revert-symbolic", "Standart qiymatni tiklash", lambda: self.reset(key))
        reset.set_sensitive(v != it["default"] or key in self.draft)

        if t == "bool":
            r = Adw.SwitchRow(title=title, subtitle=sub, active=bool(v), title_lines=0)
            r.connect("notify::active", lambda w, *_: self.set_value(key, w.get_active()))
        elif t == "int":
            lo = it.get("min") if it.get("min") is not None else -(10 ** 12)
            hi = it.get("max") if it.get("max") is not None else 10 ** 12
            adj = Gtk.Adjustment(lower=lo, upper=hi, step_increment=1, page_increment=10, value=int(v or 0))
            r = Adw.SpinRow(title=title, subtitle=sub, adjustment=adj, digits=0, title_lines=0)
            r.connect("notify::value", lambda w, *_: self.set_value(key, int(w.get_value())))
        elif t == "string":
            r = Adw.EntryRow(title=title, text=str(v or ""), show_apply_button=False)
            r.set_tooltip_text(key)
            r.connect("changed", lambda w: self.set_value(key, w.get_text()))
            if re.search(r"(_color|\.color)$", key):
                btn = Gtk.ColorDialogButton(dialog=Gtk.ColorDialog(with_alpha=False), valign=Gtk.Align.CENTER)
                from gi.repository import Gdk

                rgba = Gdk.RGBA()
                if rgba.parse(str(v or "#000000")):
                    btn.set_rgba(rgba)

                def picked(b, *_):
                    c = b.get_rgba()
                    r.set_text("#{:02x}{:02x}{:02x}".format(round(c.red * 255), round(c.green * 255), round(c.blue * 255)))

                btn.connect("notify::rgba", picked)
                r.add_suffix(btn)
        else:
            r = Adw.ActionRow(title=title, subtitle=GLib.markup_escape_text(_preview(v)) + "\n" + sub,
                              activatable=True, title_lines=0, subtitle_lines=3)
            r.add_suffix(Gtk.Image.new_from_icon_name("document-edit-symbolic"))
            r.connect("activated", lambda *_: self.edit_complex(it))
        r.add_suffix(reset)
        return r

    def edit_complex(self, it):
        key, t = it["key"], it["type"]
        v = self.value(key)
        if key == "roles.permissions":
            PermissionsDialog(self, it).present(self.win)
            return
        if t == "text":
            fields = [{"name": "v", "label": it["label"], "type": "text", "value": v or "", "height": 220}]
            parse = lambda vals: vals["v"]  # noqa: E731
        elif t == "list" and all(isinstance(x, str) for x in (v or it["default"] or [])) and not _looks_structured(it):
            fields = [{"name": "v", "label": f"{it['label']} — har bir qator alohida element", "type": "text",
                       "value": "\n".join(v or []), "height": 220}]
            parse = lambda vals: [ln.strip() for ln in vals["v"].splitlines() if ln.strip()]  # noqa: E731
        elif t == "object" and isinstance(v, dict) and v and all(isinstance(x, str) for x in v.values()):
            fields = [{"type": "section", "label": it["label"], "hint": key}]
            fields += [{"name": "k:" + k, "label": k, "type": "text" if len(x) > 60 or "\n" in x else "entry", "value": x}
                       for k, x in v.items()]
            parse = lambda vals: {k[2:]: x for k, x in vals.items() if k.startswith("k:")}  # noqa: E731
        else:
            fields = [{"name": "v", "label": f"{it['label']} (JSON)", "type": "text", "monospace": True, "height": 320,
                       "value": json.dumps(v, ensure_ascii=False, indent=2)}]

            def parse(vals):
                try:
                    out = json.loads(vals["v"])
                except ValueError as e:
                    raise ValueError(f"JSON noto'g'ri: {e}")
                if t == "list" and not isinstance(out, list):
                    raise ValueError("Ro'yxat ([...]) bo'lishi kerak.")
                if t == "object" and not isinstance(out, dict):
                    raise ValueError("Obyekt ({...}) bo'lishi kerak.")
                return out

        def submit(vals, done):
            try:
                self.set_value(key, parse(vals))
            except ValueError as e:
                done(e)
                return
            done(None)
            self.refresh_views()

        FormDialog(self.win, it["label"], fields, submit, submit_label="Qo'llash", width=640).present(self.win)


def _looks_structured(it):
    return bool(re.search(r"\[\{.*\}\]", it.get("label") or ""))


class SettingsGroupPage(Page):
    refreshable = False

    def __init__(self, root, group_name):
        super().__init__(root.win, root.groups.get(group_name, group_name))
        self.root = root
        self.group_name = group_name
        root._add_save_buttons(self.header)
        root._dirty_changed()
        self.rebuild()
        self.connect("hidden", lambda *_: root.set_content(root._build()))

    def rebuild(self):
        items = [i for i in self.root.items if i["group"] == self.group_name]
        g = group(None, f"{len(items)} ta sozlama. O'zgarishlar «Saqlash» bosilganda serverga yuboriladi.")
        for it in items:
            g.add(self.root.item_row(it))
        self.set_content(prefs_page(g))


class PermissionsDialog(Adw.Dialog):
    """Role × permission checkbox matrix for ``roles.permissions``."""

    def __init__(self, root, it):
        super().__init__(title=it["label"], content_width=640, content_height=620)
        self.root = root
        self.key = it["key"]
        value = root.value(self.key) or {}
        self.checks = {}
        tb = Adw.ToolbarView()
        header = Adw.HeaderBar(show_end_title_buttons=False, show_start_title_buttons=False)
        cancel = Gtk.Button(label="Bekor qilish")
        cancel.connect("clicked", lambda *_: self.close())
        apply = Gtk.Button(label="Qo'llash")
        apply.add_css_class("suggested-action")
        apply.connect("clicked", lambda *_: self.apply())
        header.pack_start(cancel)
        header.pack_end(apply)
        tb.add_top_bar(header)

        grid = Gtk.Grid(column_spacing=18, row_spacing=6, margin_top=12, margin_bottom=12, margin_start=18, margin_end=18)
        grid.attach(label("Huquq", css=("heading",)), 0, 0, 1, 1)
        for c, role in enumerate(ROLE_COLUMNS, 1):
            grid.attach(label(root.win.public.role_label(role), css=("heading",), xalign=0.5, wrap=False), c, 0, 1, 1)
        for r_i, perm in enumerate(root.permissions, 1):
            grid.attach(label(perm, css=("monospace",)), 0, r_i, 1, 1)
            for c, role in enumerate(ROLE_COLUMNS, 1):
                cb = Gtk.CheckButton(active=perm in (value.get(role) or []), halign=Gtk.Align.CENTER)
                self.checks[(role, perm)] = cb
                grid.attach(cb, c, r_i, 1, 1)
        note = label("Super-admin har doim barcha huquqlarga ega.", css=("dim-label", "caption"))
        note.set_margin_start(18)
        box = Gtk.Box(orientation=Gtk.Orientation.VERTICAL)
        box.append(grid)
        box.append(note)
        tb.set_content(Gtk.ScrolledWindow(child=Adw.Clamp(child=box, maximum_size=620)))
        self.set_child(tb)

    def apply(self):
        value = {role: [p for p in self.root.permissions if self.checks[(role, p)].get_active()] for role in ROLE_COLUMNS}
        old = self.root.value(self.key) or {}
        for role, perms in old.items():  # keep roles we don't show (if any)
            value.setdefault(role, perms)
        self.root.set_value(self.key, value)
        self.root.refresh_views()
        self.close()
