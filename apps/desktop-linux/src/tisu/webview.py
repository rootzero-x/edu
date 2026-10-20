"""In-app viewer for lesson materials (videos, links, files) using WebKitGTK 6 when available.

Telegram links (t.me) still go to the Telegram app — that is their purpose. Without WebKit
(``gir1.2-webkit-6.0`` not installed) the system browser is used as a fallback.
"""

import gi

from gi.repository import Adw, Gtk

try:
    gi.require_version("WebKit", "6.0")
    from gi.repository import WebKit  # noqa: E402
except (ValueError, ImportError):
    WebKit = None

from .widgets import Page  # noqa: E402


def available():
    return WebKit is not None


def is_external(uri):
    """Links that must leave the app (Telegram, mail, phone)."""
    u = (uri or "").lower()
    return u.startswith(("tg:", "mailto:", "tel:")) or "://t.me/" in u or "://telegram.me/" in u


def embed_url(uri):
    """YouTube watch/short links → embeddable player URL (plays inside the app)."""
    from urllib.parse import parse_qs, urlparse

    p = urlparse(uri)
    host = (p.hostname or "").lower()
    vid = None
    if host.endswith("youtube.com") and p.path == "/watch":
        vid = (parse_qs(p.query).get("v") or [None])[0]
    elif host == "youtu.be":
        vid = p.path.strip("/") or None
    elif host.endswith("youtube.com") and p.path.startswith("/shorts/"):
        vid = p.path.split("/")[2]
    return f"https://www.youtube-nocookie.com/embed/{vid}?autoplay=1" if vid else uri


class WebPage(Page):
    refreshable = False

    def __init__(self, win, title, uri):
        super().__init__(win, title)
        self.uri = uri
        self.title_widget = Adw.WindowTitle(title=title, subtitle=uri)
        self.header.set_title_widget(self.title_widget)
        self.view = WebKit.WebView()
        settings = self.view.get_settings()
        settings.set_enable_developer_extras(False)
        settings.set_media_playback_requires_user_gesture(False)
        self.view.connect("decide-policy", self._policy)
        self.view.connect("notify::estimated-load-progress", self._progress)
        self.view.connect("notify::title", lambda v, *_: v.get_title() and self.title_widget.set_title(v.get_title()))
        self.view.connect("load-failed", self._failed)

        for icon, tip, cb in (("go-previous-symbolic", "Orqaga", lambda: self.view.go_back()),
                              ("go-next-symbolic", "Oldinga", lambda: self.view.go_forward()),
                              ("view-refresh-symbolic", "Qayta yuklash", lambda: self.view.reload())):
            b = Gtk.Button(icon_name=icon, tooltip_text=tip)
            b.connect("clicked", lambda *_, cb=cb: cb())
            self.header.pack_end(b) if icon == "view-refresh-symbolic" else self.header.pack_start(b)

        self.bar = Gtk.ProgressBar()
        self.bar.add_css_class("osd")
        overlay = Gtk.Overlay(child=self.view)
        self.bar.set_valign(Gtk.Align.START)
        overlay.add_overlay(self.bar)
        self.set_content(overlay)
        self.view.load_uri(embed_url(uri))
        self.connect("hidden", lambda *_: self.get_parent() is None and self.view.try_close())

    def _progress(self, view, *_):
        p = view.get_estimated_load_progress()
        self.bar.set_fraction(p)
        self.bar.set_visible(p < 1.0)

    def _failed(self, _view, _event, uri, error):
        try:
            if error.matches(WebKit.network_error_quark(), WebKit.NetworkError.CANCELLED):
                return False  # a navigation we cancelled ourselves (e.g. a t.me link)
        except AttributeError:
            pass
        self.show_status("network-offline-symbolic", "Sahifani ochib bo'lmadi", f"{uri}\n{error.message}",
                         "Qayta urinish", lambda: (self.stack.set_visible_child_name("content"), self.view.reload()))
        return True

    def _policy(self, _view, decision, kind):
        if kind in (WebKit.PolicyDecisionType.NAVIGATION_ACTION, WebKit.PolicyDecisionType.NEW_WINDOW_ACTION):
            uri = decision.get_navigation_action().get_request().get_uri()
            if is_external(uri):
                decision.ignore()
                self.win.open_external(uri)
                return True
            if kind == WebKit.PolicyDecisionType.NEW_WINDOW_ACTION:
                decision.ignore()
                self.view.load_uri(uri)  # keep popups in the same in-app view
                return True
        return False


def open_in_app(page, title, uri):
    """Push an in-app web view onto ``page``'s navigation stack (browser fallback without WebKit)."""
    if not uri:
        return
    if is_external(uri) or not available():
        page.win.open_external(uri)
        return
    page.push(WebPage(page.win, title, uri))

