"""Public settings (GET /v1/public/settings), cached on disk so the UI has texts instantly and offline.

Business texts and values come from here; the defaults passed by callers are only
used before the first successful fetch or when the server does not define the key.
"""

import json
import os

from gi.repository import GLib

from . import const


class PublicSettings:
    def __init__(self, api):
        self.api = api
        self.values = {}
        self._listeners = []
        self._cache_path = os.path.join(GLib.get_user_cache_dir(), const.APP_ID, "public-settings.json")
        self._load_cache()

    def connect_changed(self, fn):
        self._listeners.append(fn)

    def _load_cache(self):
        try:
            with open(self._cache_path, encoding="utf-8") as f:
                cached = json.load(f)
            if cached.get("api") == self.api.base_url:
                self.values = cached.get("settings") or {}
        except (OSError, ValueError):
            pass

    def refresh(self, callback=None):
        def done(data, err):
            if data and isinstance(data.get("settings"), dict):
                self.values = data["settings"]
                try:
                    os.makedirs(os.path.dirname(self._cache_path), exist_ok=True)
                    with open(self._cache_path, "w", encoding="utf-8") as f:
                        json.dump({"api": self.api.base_url, "settings": self.values}, f, ensure_ascii=False)
                except OSError:
                    pass
                for fn in self._listeners:
                    fn()
            if callback:
                callback(err)

        self.api.get("public/settings", done)

    # ── Accessors ────────────────────────────────────────────
    def get(self, key, default=None):
        v = self.values.get(key)
        return default if v is None or v == "" else v

    def int(self, key, default):
        try:
            return int(self.values.get(key, default))
        except (TypeError, ValueError):
            return default

    def text(self, key, default, **vars_):
        """Short UI text from ``miniapp.texts`` (shared with the Telegram Mini App)."""
        texts = self.values.get("miniapp.texts")
        s = texts.get(key) if isinstance(texts, dict) else None
        s = s or default
        for k, v in vars_.items():
            s = s.replace("{" + k + "}", str(v))
        return s

    def nav_label(self, path, default):
        labels = self.values.get("ui.nav_labels")
        return (labels.get(path) if isinstance(labels, dict) else None) or default

    def status_label(self, status):
        labels = self.values.get("attendance.status_labels")
        defaults = {"PRESENT": "keldi", "LATE": "kechikdi", "ABSENT": "kelmadi", "EXCUSED": "sababli"}
        return (labels.get(status) if isinstance(labels, dict) else None) or defaults.get(status, status)

    def role_label(self, role):
        labels = self.values.get("telegram.role_labels")
        defaults = {"SUPER_ADMIN": "Super-admin", "ADMIN": "Administrator", "TEACHER": "O'qituvchi",
                    "STUDENT": "O'quvchi", "PARENT": "Ota-ona"}
        return (labels.get(role) if isinstance(labels, dict) else None) or defaults.get(role, role)

    @property
    def site_name(self):
        return self.get("site.name", const.APP_NAME)

    @property
    def web_url(self):
        return str(self.get("site.url", const.DEFAULT_WEB_URL)).rstrip("/")

    @property
    def timezone(self):
        return self.get("app.timezone", "Asia/Tashkent")

    @property
    def refresh_seconds(self):
        return max(10, self.int("ui.refresh_seconds", 20))
