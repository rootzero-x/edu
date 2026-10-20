"""Reusable dialogs: a declarative form (create/edit), confirmation, text prompt and item picker.

A form field is a dict: ``{"name", "label", "type", "value", ...}`` with types
``entry`` · ``password`` · ``text`` (multi-line) · ``int`` (min/max/step) · ``switch`` ·
``choice`` (``options``: list of ``(value, label)``) · ``date`` (YYYY-MM-DD) · ``info`` (read-only) ·
``widget`` (custom editor: ``widget`` + ``get``) · ``section`` (starts a titled group).
Optional keys: ``hint`` (subtitle), ``required``, ``visible_if`` (callable(values) → bool).

``on_submit(values, done)`` performs the API call and calls ``done(err)``; on error the dialog
stays open and the server's field errors are shown next to the fields.
"""

from gi.repository import Adw, GLib, Gtk

from .api import ApiError
from .widgets import label, spinner


class FormDialog(Adw.Dialog):
    def __init__(self, win, title, fields, on_submit, submit_label="Saqlash", width=520, destructive=None):
        super().__init__(title=title, content_width=width)
        self.win = win
        self.fields = fields
        self.on_submit = on_submit
        self.widgets = {}
        self.rows = {}

        tb = Adw.ToolbarView()
        header = Adw.HeaderBar(show_end_title_buttons=False, show_start_title_buttons=False)
        cancel = Gtk.Button(label="Bekor qilish")
        cancel.connect("clicked", lambda *_: self.close())
        header.pack_start(cancel)
        self.submit = Gtk.Button(label=submit_label)
        self.submit.add_css_class("suggested-action")
        self.submit.connect("clicked", lambda *_: self._submit())
        header.pack_end(self.submit)
        tb.add_top_bar(header)

        page = Adw.PreferencesPage()
        self.error = label("", css=("error",), xalign=0.0)
        self.error.set_visible(False)
        eg = Adw.PreferencesGroup()
        eg.add(self.error)
        page.add(eg)
        grp = Adw.PreferencesGroup()
        for f in fields:
            if f.get("type") == "section":
                grp = Adw.PreferencesGroup(title=GLib.markup_escape_text(f["label"]))
                if f.get("hint"):
                    grp.set_description(GLib.markup_escape_text(f["hint"]))
                page.add(grp)
                continue
            row = self._make(f)
            self.rows[f["name"]] = row
            grp.add(row)
        if grp.get_parent() is None:
            page.add(grp)
        if destructive:
            dg = Adw.PreferencesGroup()
            b = Gtk.Button(label=destructive[0], halign=Gtk.Align.CENTER)
            b.add_css_class("pill")
            b.add_css_class("destructive-action")
            b.connect("clicked", lambda *_: destructive[1](self))
            dg.add(b)
            page.add(dg)
        self.stack = Gtk.Stack()
        self.stack.add_named(page, "form")
        self.stack.add_named(spinner(), "busy")
        tb.set_content(self.stack)
        self.set_child(tb)
        self._update_visibility()

    # ── Field widgets ────────────────────────────────────────
    def _make(self, f):
        t = f.get("type", "entry")
        title = GLib.markup_escape_text(f["label"])
        v = f.get("value")
        if t in ("entry", "date"):
            r = Adw.EntryRow(title=title, text="" if v is None else str(v))
            if t == "date":
                r.set_input_purpose(Gtk.InputPurpose.DIGITS)
            if f.get("purpose") == "phone":
                r.set_input_purpose(Gtk.InputPurpose.PHONE)
            r.connect("changed", lambda *_: self._update_visibility())
            self.widgets[f["name"]] = r
        elif t == "password":
            r = Adw.PasswordEntryRow(title=title)
            self.widgets[f["name"]] = r
        elif t == "int":
            adj = Gtk.Adjustment(lower=f.get("min", 0), upper=f.get("max", 10 ** 9), step_increment=f.get("step", 1),
                                 page_increment=f.get("step", 1) * 10, value=int(v or 0))
            r = Adw.SpinRow(title=title, adjustment=adj, digits=0)
            self.widgets[f["name"]] = r
        elif t == "switch":
            r = Adw.SwitchRow(title=title, active=bool(v))
            r.connect("notify::active", lambda *_: self._update_visibility())
            self.widgets[f["name"]] = r
        elif t == "choice":
            opts = f.get("options") or []
            r = Adw.ComboRow(title=f["label"], use_markup=False, model=Gtk.StringList.new([o[1] for o in opts]))
            values = [o[0] for o in opts]
            if v in values:
                r.set_selected(values.index(v))
            r.connect("notify::selected", lambda *_: self._update_visibility())
            self.widgets[f["name"]] = r
        elif t == "text":
            r = Adw.PreferencesRow(activatable=False)
            box = Gtk.Box(orientation=Gtk.Orientation.VERTICAL, spacing=6, margin_top=8, margin_bottom=8,
                          margin_start=12, margin_end=12)
            box.append(label(f["label"], css=("caption", "dim-label")))
            tv = Gtk.TextView(wrap_mode=Gtk.WrapMode.WORD_CHAR, accepts_tab=False, top_margin=6, bottom_margin=6,
                              left_margin=6, right_margin=6)
            tv.add_css_class("tisu-textarea")
            if f.get("monospace"):
                tv.set_monospace(True)
            tv.get_buffer().set_text("" if v is None else str(v))
            sw = Gtk.ScrolledWindow(child=tv, min_content_height=f.get("height", 120), hscrollbar_policy=Gtk.PolicyType.NEVER)
            sw.add_css_class("card")
            box.append(sw)
            r.set_child(box)
            self.widgets[f["name"]] = tv
        elif t == "widget":  # custom editor: f["widget"] + f["get"]() → value
            r = Adw.PreferencesRow(activatable=False, child=f["widget"])
            self.widgets[f["name"]] = f
        elif t == "info":
            r = Adw.ActionRow(title=title, subtitle=GLib.markup_escape_text(str(v or "")), subtitle_selectable=True)
        else:
            raise ValueError(t)
        if f.get("hint") and hasattr(r, "set_subtitle") and t not in ("entry", "date", "password", "info"):
            r.set_subtitle(GLib.markup_escape_text(f["hint"]))
        elif f.get("hint") and t in ("entry", "date", "password"):
            r.set_tooltip_text(f["hint"])
        return r

    def values(self, strict=False):
        out = {}
        for f in self.fields:
            name, t = f.get("name"), f.get("type", "entry")
            w = self.widgets.get(name)
            if w is None:
                continue
            if t in ("entry", "date", "password"):
                out[name] = w.get_text().strip() if t != "password" else w.get_text()
            elif t == "int":
                out[name] = int(w.get_value())
            elif t == "switch":
                out[name] = w.get_active()
            elif t == "choice":
                opts = f.get("options") or []
                i = w.get_selected()
                out[name] = opts[i][0] if 0 <= i < len(opts) else None
            elif t == "widget":
                try:
                    out[name] = w["get"]()
                except ValueError:
                    if strict:
                        raise
                    out[name] = None
            elif t == "text":
                buf = w.get_buffer()
                out[name] = buf.get_text(buf.get_start_iter(), buf.get_end_iter(), False)
        return out

    def _update_visibility(self):
        vals = self.values()
        for f in self.fields:
            cond = f.get("visible_if")
            if cond and f.get("name") in self.rows:
                self.rows[f["name"]].set_visible(bool(cond(vals)))

    # ── Submit ───────────────────────────────────────────────
    def _submit(self):
        try:
            vals = self.values(strict=True)
        except ValueError as e:
            self._show_error(str(e))
            return
        for f in self.fields:
            row = self.rows.get(f.get("name"))
            if row is not None:
                row.remove_css_class("error")
        missing = [f for f in self.fields if f.get("required") and self.rows.get(f["name"]) is not None
                   and self.rows[f["name"]].get_visible() and vals.get(f["name"]) in ("", None)]
        if missing:
            for f in missing:
                self.rows[f["name"]].add_css_class("error")
            self._show_error(f"«{missing[0]['label']}» to'ldirilishi kerak.")
            return
        self._show_error(None)
        self.submit.set_sensitive(False)
        self.stack.set_visible_child_name("busy")
        self.on_submit(vals, self._done)

    def _done(self, err=None):
        self.submit.set_sensitive(True)
        self.stack.set_visible_child_name("form")
        if err is None:
            self.close()
            return
        if isinstance(err, ApiError):
            for name in err.field_errors:
                base = name.split(".")[0]
                if base in self.rows:
                    self.rows[base].add_css_class("error")
            self._show_error(err.message)
        else:
            self._show_error(str(err))

    def _show_error(self, text):
        self.error.set_text(text or "")
        self.error.set_visible(bool(text))


def form(win, title, fields, on_submit, **kw):
    d = FormDialog(win, title, fields, on_submit, **kw)
    d.present(win)
    return d


def confirm(win, heading, body, action_label, on_yes, destructive=True):
    d = Adw.AlertDialog(heading=heading, body=body or "")
    d.add_response("cancel", "Bekor qilish")
    d.add_response("ok", action_label)
    d.set_response_appearance("ok", Adw.ResponseAppearance.DESTRUCTIVE if destructive else Adw.ResponseAppearance.SUGGESTED)
    d.set_default_response("cancel" if destructive else "ok")
    d.set_close_response("cancel")
    d.connect("response", lambda _d, r: r == "ok" and on_yes())
    d.present(win)


def prompt(win, heading, body, placeholder, action_label, on_text, min_len=1, destructive=False):
    """Ask for one line of text (e.g. a void reason)."""
    d = Adw.AlertDialog(heading=heading, body=body or "")
    entry = Gtk.Entry(placeholder_text=placeholder, activates_default=True)
    d.set_extra_child(entry)
    d.add_response("cancel", "Bekor qilish")
    d.add_response("ok", action_label)
    d.set_response_appearance("ok", Adw.ResponseAppearance.DESTRUCTIVE if destructive else Adw.ResponseAppearance.SUGGESTED)
    d.set_default_response("ok")
    d.set_close_response("cancel")
    d.set_response_enabled("ok", False)
    entry.connect("changed", lambda e: d.set_response_enabled("ok", len(e.get_text().strip()) >= min_len))
    d.connect("response", lambda _d, r: r == "ok" and on_text(entry.get_text().strip()))
    d.present(win)
    entry.grab_focus()


class PickerDialog(Adw.Dialog):
    """Search-as-you-type list. ``search(query, done)`` → done(list of (id, title, subtitle))."""

    def __init__(self, win, title, search, on_pick, placeholder="Qidirish…", suffix_icon="list-add-symbolic"):
        super().__init__(title=title, content_width=460, content_height=560)
        self.suffix_icon = suffix_icon
        self.search_fn = search
        self.on_pick = on_pick
        self._gen = 0
        self._timer = 0
        tb = Adw.ToolbarView()
        tb.add_top_bar(Adw.HeaderBar())
        self.entry = Gtk.SearchEntry(placeholder_text=placeholder, hexpand=True)
        self.entry.connect("search-changed", lambda *_: self._schedule())
        bar = Gtk.Box(margin_start=12, margin_end=12, margin_bottom=6)
        bar.append(self.entry)
        tb.add_top_bar(bar)
        self.list = Gtk.ListBox(selection_mode=Gtk.SelectionMode.NONE, margin_start=12, margin_end=12, margin_bottom=12,
                                valign=Gtk.Align.START)
        self.list.add_css_class("boxed-list")
        self.list.connect("row-activated", self._activated)
        self.stack = Gtk.Stack()
        self.stack.add_named(Gtk.ScrolledWindow(child=self.list, hscrollbar_policy=Gtk.PolicyType.NEVER), "list")
        self.stack.add_named(spinner(), "busy")
        self.empty = Adw.StatusPage(icon_name="edit-find-symbolic", title="Topilmadi")
        self.stack.add_named(self.empty, "empty")
        tb.set_content(self.stack)
        self.set_child(tb)
        self._run()

    def _schedule(self):
        if self._timer:
            GLib.source_remove(self._timer)
        self._timer = GLib.timeout_add(300, self._run)

    def _run(self):
        self._timer = 0
        self._gen += 1
        gen = self._gen
        self.stack.set_visible_child_name("busy")

        def done(items, err=None):
            if gen != self._gen:
                return
            while (c := self.list.get_first_child()) is not None:
                self.list.remove(c)
            if err:
                self.empty.set_title("Xatolik")
                self.empty.set_description(GLib.markup_escape_text(err.message))
                self.stack.set_visible_child_name("empty")
                return
            if not items:
                self.empty.set_title("Topilmadi")
                self.empty.set_description(None)
                self.stack.set_visible_child_name("empty")
                return
            for item_id, title, sub in items:
                r = Adw.ActionRow(title=GLib.markup_escape_text(title), activatable=True)
                if sub:
                    r.set_subtitle(GLib.markup_escape_text(sub))
                r.add_suffix(Gtk.Image.new_from_icon_name(self.suffix_icon))
                r.item_id = item_id
                r.item_title = title
                self.list.append(r)
            self.stack.set_visible_child_name("list")

        self.search_fn(self.entry.get_text().strip(), done)
        return False

    def _activated(self, _lb, row):
        self.close()
        self.on_pick(row.item_id, row.item_title)


def pick(win, title, search, on_pick, **kw):
    d = PickerDialog(win, title, search, on_pick, **kw)
    d.present(win)
    return d
