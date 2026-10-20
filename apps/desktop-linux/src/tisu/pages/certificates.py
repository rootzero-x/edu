"""Certificates: own (student), children's (parent) or all (group.manage). Each opens its public verify page."""

from gi.repository import Gdk

from .. import fmt
from ..widgets import Page, group, icon_button, prefs_page, row


class CertificatesPage(Page):
    def __init__(self, win):
        super().__init__(win, win.public.nav_label("/dashboard/certificates", "Sertifikatlar"))

    def fetch(self, done):
        self.api.get("certificates", done)

    def url_for(self, code):
        base = str(self.settings.get("certificate.public_base_url", self.settings.web_url + "/c/"))
        return base.rstrip("/") + "/" + code

    def render(self, data):
        items = data.get("data") or []
        if not items:
            self.show_empty("tisu-award-symbolic", self.settings.text("certificates_empty", "Hozircha sertifikat yo'q."))
            return None
        own_id = (self.win.user or {}).get("id")
        show_names = any(c.get("student_id") != own_id for c in items)
        g = group(None, f"Jami: {len(items)}")
        for c in items:
            person = fmt.name(c)
            title = f"{c['course']} — {person}" if show_names else c["course"]
            copy = icon_button("edit-copy-symbolic", "Havolani nusxalash", lambda c=c: self._copy(c["code"]))
            g.add(row(title, f"{c['code']} · {c.get('group') or ''} · {fmt.date(c.get('issued_at'))}",
                      icon="tisu-award-symbolic", suffix=[copy],
                      on_activate=lambda c=c: self.win.open_uri(self.url_for(c["code"])), chevron=False))
        hint = group()
        hint.set_description("Sertifikat sahifasi brauzerda ochiladi (QR-kod va chop etish o'sha yerda).")
        return prefs_page(g, hint)

    def _copy(self, code):
        Gdk.Display.get_default().get_clipboard().set(self.url_for(code))
        self.win.toast("Havola nusxalandi")

