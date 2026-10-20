"""Asynchronous REST client for the TISU API (libsoup3 on the GLib main loop — no threads).

Every call takes ``callback(data, error)``; exactly one of them is not ``None``.
The returned ``Gio.Cancellable`` lets a page drop a request it no longer needs.
"""

import json
from urllib.parse import urlencode

from gi.repository import Gio, GLib, Soup

from . import const


class ApiError(Exception):
    NETWORK = "network"
    HTTP = "http"
    UNAUTHORIZED = "unauthorized"
    FORBIDDEN = "forbidden"
    NOT_FOUND = "not_found"
    VALIDATION = "validation"
    ACCESS_DENIED = "access_denied"  # COURSE_ACCESS_DENIED payload

    def __init__(self, kind, message, status=0, data=None):
        super().__init__(message)
        self.kind = kind
        self.status = status
        self.data = data or {}
        self.message = message

    @property
    def field_errors(self):
        errors = self.data.get("errors") if isinstance(self.data, dict) else None
        return errors if isinstance(errors, dict) else {}


def _message_from(status, data):
    if isinstance(data, dict):
        errors = data.get("errors")
        if isinstance(errors, dict):
            for msgs in errors.values():
                if isinstance(msgs, list) and msgs:
                    return str(msgs[0])
        if data.get("message"):
            return str(data["message"])
    if status == 403:
        return "Ruxsat yo'q."
    if status == 404:
        return "Topilmadi."
    if status == 429:
        return "Juda ko'p so'rov. Birozdan keyin urinib ko'ring."
    if status >= 500:
        return "Serverda xatolik. Birozdan keyin urinib ko'ring."
    return f"Xatolik ({status})."


class ApiClient:
    def __init__(self, base_url):
        self.base_url = base_url.rstrip("/")
        self.token = None
        self.on_unauthorized = None  # callable(), set by the application
        self.session = Soup.Session()
        self.session.set_timeout(30)
        self.session.set_user_agent(f"TISU-Edu-Linux/{const.VERSION} ")

    # ── Public helpers ───────────────────────────────────────
    def get(self, path, callback, query=None, raw=False):
        return self.request("GET", path, callback, query=query, raw=raw)

    def post(self, path, body, callback):
        return self.request("POST", path, callback, body=body)

    def put(self, path, body, callback):
        return self.request("PUT", path, callback, body=body)

    def patch(self, path, body, callback):
        return self.request("PATCH", path, callback, body=body)

    def url(self, path, query=None):
        url = f"{self.base_url}/v1/{path.lstrip('/')}"
        if query:
            clean = {k: v for k, v in query.items() if v is not None and v != ""}
            if clean:
                url += "?" + urlencode(clean)
        return url

    # ── Core ─────────────────────────────────────────────────
    def request(self, method, path, callback, body=None, query=None, raw=False):
        msg = Soup.Message.new(method, self.url(path, query))
        if msg is None:
            GLib.idle_add(lambda: callback(None, ApiError(ApiError.NETWORK, "Noto'g'ri manzil.")) and False)
            return None
        headers = msg.get_request_headers()
        headers.append("Accept", "text/csv" if raw else "application/json")
        headers.append("Accept-Language", "uz")
        if self.token:
            headers.append("Authorization", f"Bearer {self.token}")
        if body is not None:
            payload = json.dumps(body).encode("utf-8")
            msg.set_request_body_from_bytes("application/json", GLib.Bytes.new(payload))

        cancellable = Gio.Cancellable()

        def done(session, result, _data):
            try:
                gbytes = session.send_and_read_finish(result)
            except GLib.Error as e:
                if e.matches(Gio.io_error_quark(), Gio.IOErrorEnum.CANCELLED):
                    return
                callback(None, ApiError(ApiError.NETWORK, "Server bilan aloqa yo'q. Internetni tekshiring."))
                return
            status = msg.get_status()
            content = gbytes.get_data() if gbytes else b""
            if raw and 200 <= status < 300:
                disposition = msg.get_response_headers().get_one("Content-Disposition") or ""
                callback({"bytes": content, "disposition": disposition}, None)
                return
            try:
                data = json.loads(content.decode("utf-8")) if content else {}
            except (ValueError, UnicodeDecodeError):
                data = {}
            if 200 <= status < 300:
                callback(data, None)
                return
            callback(None, self._error(status, data))

        self.session.send_and_read_async(msg, GLib.PRIORITY_DEFAULT, cancellable, done, None)
        return cancellable

    def _error(self, status, data):
        if status == 401:
            if self.token and self.on_unauthorized:
                GLib.idle_add(lambda: self.on_unauthorized() and False)
            return ApiError(ApiError.UNAUTHORIZED, "Sessiya muddati tugadi. Qaytadan kiring.", status, data)
        if status == 403 and isinstance(data, dict) and data.get("code") == "COURSE_ACCESS_DENIED":
            return ApiError(ApiError.ACCESS_DENIED, data.get("message") or "Ruxsat yo'q.", status, data)
        kind = {403: ApiError.FORBIDDEN, 404: ApiError.NOT_FOUND, 422: ApiError.VALIDATION}.get(status, ApiError.HTTP)
        return ApiError(kind, _message_from(status, data), status, data)
