"""Shared building blocks: a loading/error-aware navigation page and small row helpers."""

from gi.repository import Adw, GLib, GObject, Gtk

from .api import ApiError


def spinner(size=32):
    if hasattr(Adw, "Spinner"):  # libadwaita ≥ 1.6
        sp = Adw.Spinner()
    else:
        sp = Gtk.Spinner(spinning=True)
    sp.set_size_request(size, size)
    sp.set_halign(Gtk.Align.CENTER)
    sp.set_valign(Gtk.Align.CENTER)
    return sp


def label(text, css=(), xalign=0.0, wrap=True, selectable=False, markup=False):
    lb = Gtk.Label(xalign=xalign, wrap=wrap, selectable=selectable)
    lb.set_wrap_mode(2)  # Pango.WrapMode.WORD_CHAR
    if markup:
        lb.set_markup(text)
    else:
        lb.set_text(text or "")
    for c in css:
        lb.add_css_class(c)
    return lb


def pill(text, css="accent"):
    lb = Gtk.Label(label=text, valign=Gtk.Align.CENTER)
    lb.add_css_class("tisu-pill")
    if css:
        lb.add_css_class(css)
    return lb


def status_css(status):
    return {"PRESENT": "success", "LATE": "warning", "ABSENT": "error", "EXCUSED": "accent"}.get(status, "dim-label")


def row(title, subtitle=None, icon=None, on_activate=None, suffix=None, chevron=None, markup=False):
    r = Adw.ActionRow(title=GLib.markup_escape_text(title or "") if not markup else title, use_markup=True)
    if subtitle:
        r.set_subtitle(GLib.markup_escape_text(str(subtitle)))
    if icon:
        r.add_prefix(Gtk.Image.new_from_icon_name(icon))
    if suffix is not None:
        for w in suffix if isinstance(suffix, (list, tuple)) else [suffix]:
            r.add_suffix(w)
    if on_activate:
        r.set_activatable(True)
        r.connect("activated", lambda *_: on_activate())
        if chevron is not False:
            r.add_suffix(Gtk.Image.new_from_icon_name("go-next-symbolic"))
    return r


def link_row(title, subtitle, uri_fn, icon=None):
    r = row(title, subtitle, icon=icon, on_activate=uri_fn, chevron=False)
    r.add_suffix(Gtk.Image.new_from_icon_name("tisu-external-link-symbolic"))
    return r


def group(title=None, description=None, suffix=None):
    g = Adw.PreferencesGroup()
    if title:
        g.set_title(GLib.markup_escape_text(title))
    if description:
        g.set_description(GLib.markup_escape_text(description))
    if suffix is not None:
        g.set_header_suffix(suffix)
    return g


def stat_card(title, value, icon=None, css=None):
    box = Gtk.Box(orientation=Gtk.Orientation.VERTICAL, spacing=4)
    box.add_css_class("card")
    box.add_css_class("tisu-stat")
    top = Gtk.Box(spacing=6)
    if icon:
        img = Gtk.Image.new_from_icon_name(icon)
        img.add_css_class("dim-label")
        top.append(img)
    t = label(title, css=("caption", "dim-label"), wrap=False)
    t.set_ellipsize(3)
    top.append(t)
    box.append(top)
    v = label(str(value), css=("title-1",) + ((css,) if css else ()), wrap=False)
    box.append(v)
    return box


def icon_button(icon, tooltip, on_click, css=("flat",)):
    b = Gtk.Button(icon_name=icon, tooltip_text=tooltip, valign=Gtk.Align.CENTER)
    for c in css:
        b.add_css_class(c)
    b.connect("clicked", lambda *_: on_click())
    return b


def text_button(text, on_click, css=(), icon=None):
    b = Gtk.Button(valign=Gtk.Align.CENTER)
    if icon:
        b.set_child(Adw.ButtonContent(icon_name=icon, label=text, use_underline=True))
    else:
        b.set_label(text)
    for c in css:
        b.add_css_class(c)
    b.connect("clicked", lambda *_: on_click())
    return b


def prefs_page(*groups):
    page = Adw.PreferencesPage()
    for g in groups:
        if g is not None:
            page.add(g)
    return page


class Page(Adw.NavigationPage):
    """A navigation page with header bar, refresh button and loading / content / error states.

    Subclasses implement ``fetch(done)`` (start API calls, then ``done(data, err)``)
    and ``render(data)`` returning the content widget.
    """

    refreshable = True

    def __init__(self, win, title, tag=None):
        super().__init__(title=title)
        if tag:
            self.set_tag(tag)
        self.win = win
        self.api = win.api
        self.settings = win.public
        self._loaded = False
        self._cancellable = None
        self._generation = 0

        self.toolbar = Adw.ToolbarView()
        self.header = Adw.HeaderBar()
        self.toolbar.add_top_bar(self.header)
        if self.refreshable:
            self.refresh_button = icon_button("view-refresh-symbolic", "Yangilash (Ctrl+R)", self.reload, css=())
            self.header.pack_end(self.refresh_button)

        self.stack = Gtk.Stack(transition_type=Gtk.StackTransitionType.CROSSFADE)
        self.stack.add_named(spinner(), "loading")
        self.status = Adw.StatusPage()
        self.stack.add_named(self.status, "status")
        self.toolbar.set_content(self.stack)
        self.set_child(self.toolbar)
        self.connect("showing", self._on_showing)

    # ── Lifecycle ────────────────────────────────────────────
    def _on_showing(self, *_):
        if not self._loaded:
            self.reload()

    def reload(self):
        self._loaded = True
        self._generation += 1
        gen = self._generation
        if self.stack.get_visible_child_name() != "content":
            self.stack.set_visible_child_name("loading")

        def done(data, err):
            if gen != self._generation:
                return  # a newer reload superseded this one
            if err is not None:
                self.show_error(err)
                return
            try:
                widget = self.render(data)
            except Exception as e:  # never leave the user on an endless spinner
                import traceback

                traceback.print_exc()
                self.show_error(ApiError(ApiError.HTTP, f"Ma'lumotni ko'rsatib bo'lmadi: {e}"))
                return
            if widget is not None:
                self.set_content(widget)

        self.fetch(done)

    def fetch(self, done):
        done({}, None)

    def render(self, data):
        return None

    # ── States ───────────────────────────────────────────────
    def set_content(self, widget):
        old = self.stack.get_child_by_name("content")
        scroll = None
        if old is not None:
            sw = _find_scrolled(old)
            scroll = sw.get_vadjustment().get_value() if sw else None
            self.stack.remove(old)
        self.stack.add_named(widget, "content")
        self.stack.set_visible_child_name("content")
        if scroll:
            # Keep the scroll position across refreshes (auto-refresh must not jump to the top).
            sw = _find_scrolled(widget)
            if sw:
                adj = sw.get_vadjustment()
                handler = {}

                def restore(*_):
                    if adj.get_upper() - adj.get_page_size() >= scroll:
                        adj.set_value(scroll)
                        adj.disconnect(handler["id"])

                handler["id"] = adj.connect("changed", restore)

    def show_status(self, icon, title, description=None, button=None, on_button=None):
        self.status.set_icon_name(icon)
        self.status.set_title(title)
        self.status.set_description(GLib.markup_escape_text(description) if description else None)
        if button and on_button:
            b = Gtk.Button(label=button, halign=Gtk.Align.CENTER)
            b.add_css_class("pill")
            b.add_css_class("suggested-action")
            b.connect("clicked", lambda *_: on_button())
            self.status.set_child(b)
        else:
            self.status.set_child(None)
        self.stack.set_visible_child_name("status")

    def show_empty(self, icon, title, description=None):
        self.show_status(icon, title, description)

    def show_error(self, err):
        if err.kind == ApiError.UNAUTHORIZED:
            return  # the window switches to the login screen
        if err.kind == ApiError.ACCESS_DENIED:
            d = err.data
            url = d.get("contact_url")
            self.show_status("dialog-password-symbolic", d.get("title") or "Ruxsat yo'q", d.get("message") or err.message,
                             d.get("button") if url else None, (lambda: self.win.open_uri(url)) if url else None)
            return
        if err.kind == ApiError.NETWORK:
            self.show_status("network-offline-symbolic", "Aloqa yo'q", err.message, "Qayta urinish", self.reload)
            return
        if err.kind == ApiError.NOT_FOUND:
            self.show_status("edit-find-symbolic", "Topilmadi", err.message)
            return
        if err.kind == ApiError.FORBIDDEN:
            self.show_status("dialog-password-symbolic", "Ruxsat yo'q", err.message)
            return
        self.show_status("dialog-error-symbolic", "Xatolik yuz berdi", err.message, "Qayta urinish", self.reload)

    def push(self, page):
        nav = self.get_ancestor(Adw.NavigationView)
        if nav is not None:
            nav.push(page)


def _find_scrolled(widget):
    if isinstance(widget, Gtk.ScrolledWindow):
        return widget
    child = widget.get_first_child()
    while child is not None:
        found = _find_scrolled(child)
        if found:
            return found
        child = child.get_next_sibling()
    return None


def join(callbacks_done, keys):
    """Run several API calls in parallel; ``callbacks_done(results, first_error)`` once all finished.

    Usage: ``cb = join(done, ["a", "b"]); api.get(x, cb("a")); api.get(y, cb("b"))``
    An optional key (prefix ``?``) does not fail the whole page on error.
    """
    results = {}
    state = {"left": len(keys), "err": None}

    def make(key):
        optional = key.startswith("?")
        name = key.lstrip("?")

        def cb(data, err):
            if err is not None and not optional and state["err"] is None:
                state["err"] = err
            results[name] = data
            state["left"] -= 1
            if state["left"] == 0:
                callbacks_done(results if state["err"] is None else None, state["err"])

        return cb

    return make


class Signal(GObject.Object):
    """Tiny app-wide event bus (e.g. unread count changed)."""

    __gsignals__ = {
        "unread-changed": (GObject.SignalFlags.RUN_FIRST, None, (int,)),
        "inbox-changed": (GObject.SignalFlags.RUN_FIRST, None, (int,)),
        "user-changed": (GObject.SignalFlags.RUN_FIRST, None, ()),
    }


def menu_button(items, tooltip="Amallar", icon="view-more-symbolic"):
    """A flat "⋮" button with a popover of actions: ``items`` = [(label, callback, destructive?), ...]."""
    mb = Gtk.MenuButton(icon_name=icon, tooltip_text=tooltip, valign=Gtk.Align.CENTER)
    mb.add_css_class("flat")
    box = Gtk.Box(orientation=Gtk.Orientation.VERTICAL)
    pop = Gtk.Popover(child=box)
    pop.add_css_class("menu")
    for item in items:
        text, cb = item[0], item[1]
        b = Gtk.Button(label=text)
        b.add_css_class("flat")
        b.get_child().set_xalign(0)
        if len(item) > 2 and item[2]:
            b.add_css_class("error")
        b.connect("clicked", lambda _b, cb=cb: (pop.popdown(), cb()))
        box.append(b)
    mb.set_popover(pop)
    return mb


def done_toast(win, ok_text, then=None):
    """API callback that shows a toast and runs ``then()`` on success."""

    def cb(data, err):
        if err:
            win.toast(err.message, timeout=6)
            return
        if ok_text:
            win.toast(ok_text)
        if then:
            then(data)

    return cb
