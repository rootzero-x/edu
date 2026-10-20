"""API token in the Secret Service (GNOME Keyring / KWallet / Flatpak secret portal) via libsecret."""

import gi

gi.require_version("Secret", "1")
from gi.repository import Secret  # noqa: E402

from . import const  # noqa: E402

_SCHEMA = Secret.Schema.new(
    const.APP_ID + ".Token",
    Secret.SchemaFlags.NONE,
    {"api": Secret.SchemaAttributeType.STRING},
)


def _attrs(api_url):
    return {"api": api_url}


def lookup(api_url, callback, timeout=8):
    """callback(token_or_None, error_message_or_None) — called exactly once.

    A missing or hung Secret Service must never leave the app on its loading screen,
    so the lookup is abandoned after ``timeout`` seconds.
    """
    from gi.repository import Gio, GLib

    state = {"done": False}
    cancellable = Gio.Cancellable()

    def finish(token, err):
        if not state["done"]:
            state["done"] = True
            callback(token, err)

    def done(_src, result, _data):
        try:
            finish(Secret.password_lookup_finish(result), None)
        except Exception as e:  # GLib.Error: no secret service, locked keyring, cancelled …
            finish(None, str(e))

    def expired():
        cancellable.cancel()
        finish(None, "Kalitlar xizmati javob bermadi")
        return False

    GLib.timeout_add_seconds(timeout, expired)
    Secret.password_lookup(_SCHEMA, _attrs(api_url), cancellable, done, None)


def store(api_url, token, callback=None):
    def done(_src, result, _data):
        try:
            Secret.password_store_finish(result)
            err = None
        except Exception as e:
            err = str(e)
        if callback:
            callback(err)

    Secret.password_store(
        _SCHEMA, _attrs(api_url), Secret.COLLECTION_DEFAULT,
        f"{const.APP_NAME} ({api_url})", token, None, done, None,
    )


def clear(api_url, callback=None):
    def done(_src, result, _data):
        try:
            Secret.password_clear_finish(result)
        except Exception:
            pass
        if callback:
            callback()

    Secret.password_clear(_SCHEMA, _attrs(api_url), None, done, None)
