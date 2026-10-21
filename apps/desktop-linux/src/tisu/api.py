"""Asynchronous REST client for the TISU API (libsoup3 on the GLib main loop — no threads).

Every call takes ``callback(data, error)``; exactly one of them is not ``None``.
The returned ``Gio.Cancellable`` lets a page drop a request it no longer needs.
"""

import json
from urllib.parse import urlencode

import gi

gi.require_version("Soup", "3.0")
from gi.repository import Gio, GLib, Soup  # noqa: E402

from . import const  # noqa: E402


CHUNK_SIZE = 1536 * 1024  # same as apps/web/lib/upload.ts — the host caps one request at 2 MB
UPLOAD_TIMEOUT = 120  # seconds per chunk


EXTERNAL_SCHEMES = ("https", "http", "tg", "tel")


def external_allowed(uri):
    """Only web, Telegram and phone links may leave the app (no file:, smb:, javascript:, custom handlers…)."""
    uri = (uri or "").strip()
    if ":" not in uri:
        return False
    return uri.split(":", 1)[0].lower() in EXTERNAL_SCHEMES


def chunk_plan(size, chunk=CHUNK_SIZE):
    """[(start, end), …] byte ranges; a single range for small (or empty) files."""
    if size <= chunk:
        return [(0, size)]
    return [(i, min(i + chunk, size)) for i in range(0, size, chunk)]


def new_upload_id():
    """8–64 chars of [A-Za-z0-9_-], as RestrictUploadTicket requires."""
    import uuid

    return uuid.uuid4().hex


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
    if status == 429:
        return "Juda ko'p so'rov. Birozdan keyin urinib ko'ring."
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
        # Uploads and file downloads may legitimately take longer than an API call.
        self.upload_session = Soup.Session()
        self.upload_session.set_timeout(UPLOAD_TIMEOUT)
        self.upload_session.set_user_agent(f"TISU-Edu-Linux/{const.VERSION} ")

    # ── Public helpers ───────────────────────────────────────
    def get(self, path, callback, query=None, raw=False):
        return self.request("GET", path, callback, query=query, raw=raw)

    def post(self, path, body, callback):
        return self.request("POST", path, callback, body=body)

    def put(self, path, body, callback):
        return self.request("PUT", path, callback, body=body)

    def patch(self, path, body, callback):
        return self.request("PATCH", path, callback, body=body)

    def delete(self, path, callback, body=None):
        return self.request("DELETE", path, callback, body=body)

    # ── Uploads: one-time ticket, chunked (the host accepts ≤ 2 MB per request) ─────────
    def upload(self, path, filename, content, callback, fields=None, mime="application/octet-stream", progress=None):
        """Upload ``content`` (bytes) to an upload ``path`` (e.g. ``assignments/5/files``).

        Mirrors apps/web/lib/upload.ts ``sendWithTicket``: POST /uploads/ticket {path} → multipart POSTs to the
        returned ``url`` with ``Authorization: Bearer <ticket token>``. Files over CHUNK_SIZE go in sequential
        chunks (field ``chunk`` + X-Upload-Id / X-Chunk-Index / X-Chunk-Total, ``filename`` and the other fields
        only on the last one); the server answers 202 for intermediate chunks.
        """

        def got_ticket(ticket, err):
            if err:
                callback(None, err)
                return
            if not ticket or not ticket.get("url") or not ticket.get("token"):
                callback(None, ApiError(ApiError.HTTP, "Yuklash chiptasi olinmadi."))
                return
            self._send_chunks(ticket, filename, content, fields or {}, mime, callback, progress)

        self.post("uploads/ticket", {"path": path.lstrip("/")}, got_ticket)

    def _send_chunks(self, ticket, filename, content, fields, mime, callback, progress):
        plan = chunk_plan(len(content))
        upload_id = new_upload_id()

        def send(i):
            start, end = plan[i]
            last = i == len(plan) - 1
            mp = Soup.Multipart.new("multipart/form-data")
            if len(plan) == 1:
                mp.append_form_file("file", filename, mime, GLib.Bytes.new(content))
            else:
                mp.append_form_file("chunk", "blob", "application/octet-stream", GLib.Bytes.new(content[start:end]))
            if last:
                if len(plan) > 1:
                    mp.append_form_string("filename", filename)
                for k, v in fields.items():
                    if v is not None:
                        mp.append_form_string(k, str(v))
            msg = Soup.Message.new_from_multipart(ticket["url"], mp)
            if msg is None:
                callback(None, ApiError(ApiError.HTTP, "Yuklash manzili noto'g'ri."))
                return
            h = msg.get_request_headers()
            h.append("Accept", "application/json")
            h.append("Authorization", f"Bearer {ticket['token']}")
            if len(plan) > 1:
                h.append("X-Upload-Id", upload_id)
                h.append("X-Chunk-Index", str(i))
                h.append("X-Chunk-Total", str(len(plan)))

            def done(session, result, _d):
                try:
                    gbytes = session.send_and_read_finish(result)
                except GLib.Error:
                    callback(None, ApiError(ApiError.NETWORK, "Tarmoq bilan aloqa yo'q yoki yuklash juda uzoq davom etdi."))
                    return
                status = msg.get_status()
                try:
                    data = json.loads(gbytes.get_data().decode("utf-8")) if gbytes and gbytes.get_size() else {}
                except (ValueError, UnicodeDecodeError):
                    data = {}
                if status == 413:
                    callback(None, ApiError(ApiError.VALIDATION, "Fayl juda katta.", status, data))
                    return
                if not 200 <= status < 300:
                    callback(None, self._error(status, data))
                    return
                if progress:
                    progress(i + 1, len(plan))
                if last:
                    callback(data, None)
                else:
                    send(i + 1)

            self.upload_session.send_and_read_async(msg, GLib.PRIORITY_DEFAULT, None, done, None)

        send(0)

    # ── Downloads: streamed to a file, size checked from Content-Length first ───────────
    def download_to(self, absolute_url, gfile, callback, max_bytes=2 * 1024 ** 3):
        """Stream a GET straight into ``gfile`` (no full buffering). callback(ok: bool, error_message|None)."""
        msg = Soup.Message.new("GET", absolute_url) if absolute_url else None
        if msg is None:
            GLib.idle_add(lambda: callback(False, "Noto'g'ri manzil.") and False)
            return

        def sent(session, result, _d):
            try:
                stream = session.send_finish(result)
            except GLib.Error as e:
                callback(False, f"Yuklab bo'lmadi: {e.message}")
                return
            if not 200 <= msg.get_status() < 300:
                stream.close_async(GLib.PRIORITY_LOW, None, None, None)
                callback(False, f"Server javobi: {msg.get_status()}")
                return
            length = msg.get_response_headers().get_content_length()
            if length and length > max_bytes:
                stream.close_async(GLib.PRIORITY_LOW, None, None, None)
                callback(False, "Fayl juda katta.")
                return

            def opened(f, res, _d2):
                try:
                    out = f.replace_finish(res)
                except GLib.Error as e:
                    callback(False, f"Faylni yozib bo'lmadi: {e.message}")
                    return

                def spliced(o, r, _d3):
                    try:
                        o.splice_finish(r)
                    except GLib.Error as e:
                        callback(False, f"Faylni yozib bo'lmadi: {e.message}")
                        return
                    callback(True, None)

                flags = Gio.OutputStreamSpliceFlags.CLOSE_SOURCE | Gio.OutputStreamSpliceFlags.CLOSE_TARGET
                out.splice_async(stream, flags, GLib.PRIORITY_LOW, None, spliced, None)

            gfile.replace_async(None, False, Gio.FileCreateFlags.REPLACE_DESTINATION, GLib.PRIORITY_LOW, None, opened, None)

        self.upload_session.send_async(msg, GLib.PRIORITY_LOW, None, sent, None)

    def fetch_bytes(self, absolute_url, callback, max_bytes=15 * 1024 * 1024):
        """GET a small resource (e.g. an announcement image) → callback(bytes|None). No auth header is sent."""
        msg = Soup.Message.new("GET", absolute_url) if absolute_url else None
        if msg is None:
            GLib.idle_add(lambda: callback(None) and False)
            return

        def done(session, result, _d):
            try:
                data = session.send_and_read_finish(result).get_data()
            except GLib.Error:
                callback(None)
                return
            ok = 200 <= msg.get_status() < 300 and len(data) <= max_bytes
            callback(data if ok else None)

        self.session.send_and_read_async(msg, GLib.PRIORITY_LOW, None, done, None)

    def url(self, path, query=None):
        url = f"{self.base_url}/v1/{path.lstrip('/')}"
        if query:
            clean = {k: v for k, v in query.items() if v is not None and v != ""}
            if clean:
                url += "?" + urlencode(clean)
        return url

    # ── Core ─────────────────────────────────────────────────
    def request(self, method, path, callback, body=None, query=None, raw=False, retried=False):
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
            if status == 429 and method == "GET" and not retried:
                # Rate limited: retry a read once after the server's Retry-After (capped), silently.
                try:
                    wait = int(msg.get_response_headers().get_one("Retry-After") or 5)
                except ValueError:
                    wait = 5
                GLib.timeout_add_seconds(min(max(wait, 1), 15), lambda: self.request(
                    method, path, callback, body=body, query=query, raw=raw, retried=True) and False)
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
