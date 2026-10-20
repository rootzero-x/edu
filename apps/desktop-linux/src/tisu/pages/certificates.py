"""Certificates: own (student), taught groups' (teacher) or all (group.manage).
Each certificate opens in the app (verified via the public endpoint) with its QR code."""

from gi.repository import Adw, Gdk, GLib, Gtk

from .. import fmt
from ..forms import confirm
from ..qr import QrView
from ..widgets import Page, group, icon_button, label, menu_button, prefs_page, row


def public_url(settings, code):
    base = str(settings.get("certificate.public_base_url", settings.web_url + "/c/"))
    return base.rstrip("/") + "/" + code


def copy_link(win, code):
    Gdk.Display.get_default().get_clipboard().set(public_url(win.public, code))
    win.toast("Tekshirish havolasi nusxalandi")


def revoke_certificate(page, code, on_done):
    def yes():
        def ok(_d, err):
            if err:
                page.win.toast(err.message, timeout=6)
                return
            page.win.toast("Sertifikat bekor qilindi")
            on_done()

        page.api.delete(f"certificates/{GLib.uri_escape_string(code, None, False)}", ok)

    confirm(page.win, "Sertifikat bekor qilinsinmi?", f"{code} — tekshirish sahifasi endi «topilmadi» deydi.", "Bekor qilish", yes)


class CertificatesPage(Page):
    def __init__(self, win):
        super().__init__(win, win.public.nav_label("/dashboard/certificates", "Sertifikatlar"))

    def fetch(self, done):
        self.api.get("certificates", done)

    def render(self, data):
        items = data.get("data") or []
        if not items:
            self.show_empty("tisu-award-symbolic", self.settings.text("certificates_empty", "Hozircha sertifikat yo'q."))
            return None
        own_id = (self.win.user or {}).get("id")
        show_names = any(c.get("student_id") != own_id for c in items)
        manage = self.win.has("group.manage")
        g = group(None, f"Jami: {len(items)}")
        for c in items:
            person = fmt.name(c)
            title = f"{c['course']} — {person}" if show_names else c["course"]
            actions = [("Havolani nusxalash", lambda c=c: copy_link(self.win, c["code"]))]
            if manage:
                actions.append(("Bekor qilish", lambda c=c: revoke_certificate(self, c["code"], self.reload), True))
            g.add(row(title, f"{c['code']} · {c.get('group') or ''} · {fmt.date(c.get('issued_at'))}",
                      icon="tisu-award-symbolic", suffix=[menu_button(actions)],
                      on_activate=lambda c=c: self.push(CertificateDetailPage(self.win, c["code"]))))
        return prefs_page(g)


class CertificateDetailPage(Page):
    def __init__(self, win, code):
        super().__init__(win, "Sertifikat")
        self.code = code
        self.header.pack_end(icon_button("edit-copy-symbolic", "Tekshirish havolasini nusxalash",
                                         lambda: copy_link(win, code), css=()))

    def fetch(self, done):
        self.api.get(f"public/certificates/{GLib.uri_escape_string(self.code, None, False)}", done)

    def render(self, data):
        s = self.settings
        c = data.get("certificate") or {}
        body_tpl = s.get("certificate.body", "{name}\n«{course}» kursini muvaffaqiyatli tamomlagani uchun berildi.")
        body = body_tpl.replace("{name}", c.get("name", "")).replace("{course}", c.get("course", ""))

        box = Gtk.Box(orientation=Gtk.Orientation.VERTICAL, spacing=12, margin_top=24, margin_bottom=24,
                      margin_start=24, margin_end=24, halign=Gtk.Align.FILL)
        box.add_css_class("card")
        box.add_css_class("tisu-certificate")
        box.append(label(s.get("certificate.title", "SERTIFIKAT"), css=("title-1",), xalign=0.5))
        box.append(label(s.site_name, css=("dim-label",), xalign=0.5))
        body_label = label(body, css=("title-4",), xalign=0.5)
        body_label.set_justify(Gtk.Justification.CENTER)
        box.append(body_label)
        facts = [f"Berilgan sana: {fmt.date(c.get('issued_at'))}"]
        if c.get("duration_months"):
            facts.append(f"Kurs davomiyligi: {c['duration_months']} oy")
        facts.append(f"Kod: {c.get('code', self.code)}")
        box.append(label(" · ".join(facts), css=("dim-label",), xalign=0.5, selectable=True))
        signer = " ".join(x for x in [s.get("certificate.signer_title", ""), s.get("certificate.signer_name", "")] if x)
        if signer:
            box.append(label(signer, xalign=0.5))
        qr = QrView(size=160)
        qr.set_text(public_url(s, c.get("code", self.code)))
        qr.add_css_class("tisu-qr")
        qr.set_overflow(Gtk.Overflow.HIDDEN)
        box.append(qr)
        ok = label(s.get("certificate.verified_text", "✅ Sertifikat haqiqiy"), css=("success", "heading"), xalign=0.5)
        box.append(ok)
        inner = Gtk.Box(orientation=Gtk.Orientation.VERTICAL, margin_top=12, margin_bottom=12, margin_start=12, margin_end=12)
        inner.append(box)
        return Gtk.ScrolledWindow(child=Adw.Clamp(maximum_size=640, child=inner), hscrollbar_policy=Gtk.PolicyType.NEVER)
