"""Local app preferences (GSettings, with an in-memory fallback when the schema is not installed)."""

import os

from gi.repository import Gio

from . import const


class _MemorySettings:
    _DEFAULTS = {
        "window-width": 1100,
        "window-height": 760,
        "window-maximized": False,
        "api-url": "",
        "run-in-background": False,
        "notified-announcements": [],
        "last-phone": "",
        "setup-dismissed": False,
    }

    def __init__(self):
        self._values = dict(self._DEFAULTS)

    def get_int(self, k):
        return self._values[k]

    def set_int(self, k, v):
        self._values[k] = v

    def get_boolean(self, k):
        return self._values[k]

    def set_boolean(self, k, v):
        self._values[k] = v

    def get_string(self, k):
        return self._values[k]

    def set_string(self, k, v):
        self._values[k] = v

    def get_value(self, k):
        return self._values[k]

    def create_action(self, key):
        from gi.repository import GLib

        action = Gio.SimpleAction.new_stateful(key, None, GLib.Variant("b", self._values[key]))

        def toggled(a, _p):
            new = not a.get_state().get_boolean()
            a.set_state(GLib.Variant("b", new))
            self._values[key] = new

        action.connect("activate", toggled)
        return action


def load():
    source = Gio.SettingsSchemaSource.get_default()
    if source is not None and source.lookup(const.APP_ID, True) is not None:
        return Gio.Settings.new(const.APP_ID)
    return _MemorySettings()


def get_notified(settings):
    v = settings.get_value("notified-announcements")
    return list(v.unpack() if hasattr(v, "unpack") else v)


def set_notified(settings, ids):
    ids = list(ids)[-300:]
    if isinstance(settings, _MemorySettings):
        settings._values["notified-announcements"] = ids
        return
    from gi.repository import GLib

    settings.set_value("notified-announcements", GLib.Variant("ax", ids))


def api_url(settings):
    return (os.environ.get("TISU_API_URL") or settings.get_string("api-url") or const.DEFAULT_API_URL).rstrip("/")
