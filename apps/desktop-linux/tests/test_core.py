"""Display-free unit tests for formatting, settings and API helpers."""

import os
import sys
import tempfile
import unittest

sys.path.insert(0, os.path.join(os.path.dirname(__file__), "..", "src"))
os.environ.setdefault("XDG_CACHE_HOME", tempfile.mkdtemp())

from tisu import fmt  # noqa: E402
from tisu.api import ApiClient, ApiError, _message_from  # noqa: E402
from tisu.public_settings import PublicSettings  # noqa: E402
from tisu.qr import QrCode  # noqa: E402


class FakeApi:
    base_url = "https://api.example.test"


def settings(values):
    s = PublicSettings(FakeApi())
    s.values = values
    return s


class FmtTest(unittest.TestCase):
    def setUp(self):
        fmt.configure(settings({"app.timezone": "Asia/Tashkent"}))

    def test_converts_utc_to_school_timezone(self):
        self.assertEqual(fmt.datetime("2026-09-16T04:00:00.000000Z"), "16.09.2026 09:00")
        self.assertEqual(fmt.time("2026-09-16T04:00:00Z"), "09:00")

    def test_date_only_and_garbage(self):
        self.assertEqual(fmt.date("2026-09-15"), "15.09.2026")
        self.assertEqual(fmt.date("not a date"), "—")
        self.assertEqual(fmt.date(None), "—")

    def test_numbers(self):
        self.assertEqual(fmt.percent(90.0), "90%")
        self.assertEqual(fmt.percent(83.3), "83.3%")
        self.assertEqual(fmt.percent(None), "—")
        self.assertEqual(fmt.duration(65), "01:05")
        self.assertEqual(fmt.duration(3725), "1:02:05")
        self.assertEqual(fmt.duration(-5), "00:00")

    def test_weekdays_from_settings(self):
        fmt.configure(settings({"miniapp.texts": {"weekdays": "Mo,Tu,We,Th,Fr,Sa,Su"}}))
        self.assertEqual(fmt.weekday(1), "Mo")
        self.assertEqual(fmt.weekday(7), "Su")

    def test_names(self):
        self.assertEqual(fmt.name({"first_name": "Aziz", "last_name": None}), "Aziz")
        self.assertEqual(fmt.name(None), "—")


class SettingsTest(unittest.TestCase):
    def test_texts_and_labels_come_from_server(self):
        s = settings({
            "miniapp.texts": {"hello": "Salom, {name}!"},
            "ui.nav_labels": {"/dashboard": "Asosiy"},
            "attendance.status_labels": {"PRESENT": "bor"},
        })
        self.assertEqual(s.text("hello", "x", name="Aziz"), "Salom, Aziz!")
        self.assertEqual(s.text("missing", "Default {n}", n=2), "Default 2")
        self.assertEqual(s.nav_label("/dashboard", "Bosh sahifa"), "Asosiy")
        self.assertEqual(s.status_label("PRESENT"), "bor")
        self.assertEqual(s.status_label("ABSENT"), "kelmadi")

    def test_refresh_seconds_has_a_floor(self):
        self.assertEqual(settings({"ui.refresh_seconds": 1}).refresh_seconds, 10)
        self.assertEqual(settings({}).refresh_seconds, 20)


class ApiTest(unittest.TestCase):
    def test_url_building_skips_empty_query_values(self):
        api = ApiClient("https://api.example.test/")
        self.assertEqual(api.url("/groups", {"from": "2026-01-01", "group_id": None, "x": ""}),
                         "https://api.example.test/v1/groups?from=2026-01-01")

    def test_validation_message_prefers_first_field_error(self):
        self.assertEqual(_message_from(422, {"message": "The given data…", "errors": {"phone": ["Telefon noto'g'ri."]}}),
                         "Telefon noto'g'ri.")
        self.assertEqual(_message_from(403, {"message": "Faqat guruh o'qituvchisi."}), "Faqat guruh o'qituvchisi.")
        self.assertIn("Serverda", _message_from(500, {}))

    def test_course_access_denied_is_its_own_kind(self):
        api = ApiClient("https://api.example.test")
        err = api._error(403, {"code": "COURSE_ACCESS_DENIED", "message": "Yoziling", "title": "Yopiq"})
        self.assertEqual(err.kind, ApiError.ACCESS_DENIED)
        self.assertEqual(err.data["title"], "Yopiq")

    def test_unauthorized_triggers_logout_only_with_token(self):
        api = ApiClient("https://api.example.test")
        calls = []
        api.on_unauthorized = lambda: calls.append(1)
        self.assertEqual(api._error(401, {}).kind, ApiError.UNAUTHORIZED)
        from gi.repository import GLib

        ctx = GLib.MainContext.default()
        while ctx.iteration(False):
            pass
        self.assertEqual(calls, [])  # wrong password on login must not "log out"


class QrTest(unittest.TestCase):
    URL = "https://t.me/edu_tisu_bot?start=login_iCjFvTk7XR25o41HnNe7US1IiCb1HFZX"

    def test_structure(self):
        q = QrCode(self.URL)
        self.assertEqual(q.version, 5)  # 71 bytes, level M
        self.assertEqual(q.size, 37)
        m = q.modules
        # finder pattern corners and the always-dark module
        for x, y in ((0, 0), (36, 0), (0, 36), (6, 6), (30, 6), (6, 30)):
            self.assertTrue(m[y][x])
        self.assertTrue(m[q.size - 8][8])
        # timing pattern alternates
        self.assertEqual([m[6][x] for x in range(8, 29)], [x % 2 == 0 for x in range(8, 29)])

    def test_too_long_is_rejected(self):
        with self.assertRaises(ValueError):
            QrCode("x" * 300)

    def test_matches_reference_encoder_when_available(self):
        try:
            import qrcode
            import qrcode.util as qu
        except ImportError:
            self.skipTest("python3-qrcode not installed")
        for text in (self.URL, "a", "TISU-26-ABCDEFGH", "x" * 150):
            for mask in range(8):
                mine = QrCode(text, mask=mask)
                ref = qrcode.QRCode(version=mine.version, error_correction=qrcode.constants.ERROR_CORRECT_M,
                                    mask_pattern=mask, border=0)
                ref.add_data(qu.QRData(text.encode(), mode=qu.MODE_8BIT_BYTE))
                ref.make(fit=False)
                self.assertEqual([[bool(v) for v in r] for r in ref.get_matrix()], mine.modules, (text, mask))


if __name__ == "__main__":
    unittest.main()
