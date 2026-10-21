"""Files from the API (lesson materials, homework): pick & upload, open with the local viewer, save as."""

import mimetypes
import os
import re

from gi.repository import Gio, GLib, Gtk

from . import const


def human_size(n):
    n = float(n or 0)
    for unit in ("B", "KB", "MB", "GB"):
        if n < 1024 or unit == "GB":
            return f"{n:.0f} {unit}" if unit == "B" else f"{n:.1f} {unit}"
        n /= 1024


def _safe(name):
    return re.sub(r"[^\w.\- ()]+", "_", name or "fayl").strip() or "fayl"


def cache_dir():
    return os.path.join(GLib.get_user_cache_dir(), const.APP_ID, "files")


def clear_cache():
    """Delete downloaded files (on logout / session expiry) — they may contain other people's work."""
    import shutil

    shutil.rmtree(cache_dir(), ignore_errors=True)


def _link(win, api, file_id, then):
    """Signed short-lived link for a stored file → then(url, name)."""

    def got(data, err):
        if err:
            win.toast(err.message, timeout=5)
            return
        then(data["url"], data.get("name") or f"fayl-{file_id}")

    api.get(f"files/{file_id}/link", got)


def open_file(win, api, file_id):
    """Stream to the cache and open with the default local application (no browser)."""
    win.toast("Fayl yuklanmoqda…", timeout=2)

    def got(url, name):
        folder = os.path.join(cache_dir(), str(file_id))
        os.makedirs(folder, mode=0o700, exist_ok=True)
        target = Gio.File.new_for_path(os.path.join(folder, _safe(name)))

        def done(ok, err):
            if not ok:
                win.toast(err or "Faylni yuklab bo'lmadi.", timeout=5)
                return
            Gtk.FileLauncher.new(target).launch(win, None, None, None)

        api.download_to(url, target, done)

    _link(win, api, file_id, got)


def save_file_as(win, api, file_id, name):
    dialog = Gtk.FileDialog(title="Faylni saqlash", initial_name=_safe(name))

    def chosen(dlg, res):
        try:
            target = dlg.save_finish(res)
        except GLib.Error:
            return

        def got(url, _name):
            api.download_to(url, target, lambda ok, err: win.toast(
                f"Saqlandi: {target.get_basename()}" if ok else (err or "Faylni saqlab bo'lmadi."), timeout=4))

        _link(win, api, file_id, got)

    dialog.save(win, None, chosen)


def pick_and_upload(win, api, path, on_uploaded, fields=None, multiple=True):
    """File chooser (checked against public upload settings) → multipart upload, one file at a time."""
    s = win.public
    if s.get("uploads.enabled", True) is False:
        win.toast("Fayl yuklash o'chirilgan.")
        return
    allowed = [t.lower().lstrip(".") for t in (s.get("uploads.allowed_types") or [])]
    max_mb = min(64, max(1, s.int("uploads.max_mb", 20)))
    dialog = Gtk.FileDialog(title="Fayl tanlash")
    if allowed:
        f = Gtk.FileFilter(name="Ruxsat etilgan fayllar")
        for ext in allowed:
            f.add_pattern(f"*.{ext}")
            f.add_pattern(f"*.{ext.upper()}")
        store = Gio.ListStore.new(Gtk.FileFilter)
        store.append(f)
        dialog.set_filters(store)

    def upload_one(gfile, rest):
        name = gfile.get_basename() or "fayl"
        ext = name.rsplit(".", 1)[-1].lower() if "." in name else ""
        if allowed and ext not in allowed:
            win.toast(f"«{name}»: bu turdagi fayl yuklanmaydi ({', '.join(allowed)}).", timeout=6)
            return next_file(rest)

        def info_done(f, res, _d):
            # Size is checked before reading, so a huge file is never loaded into memory.
            try:
                size = f.query_info_finish(res).get_size()
            except GLib.Error as e:
                win.toast(f"«{name}»ni o'qib bo'lmadi: {e.message}")
                return next_file(rest)
            if size <= 0:
                win.toast(f"«{name}» bo'sh fayl.")
                return next_file(rest)
            if size > max_mb * 1024 * 1024:
                win.toast(f"«{name}» juda katta (ko'pi bilan {max_mb} MB).", timeout=6)
                return next_file(rest)
            f.load_contents_async(None, loaded, None)

        def loaded(fl, res, _d):
            try:
                _ok, content, _etag = fl.load_contents_finish(res)
            except GLib.Error as e:
                win.toast(f"«{name}»ni o'qib bo'lmadi: {e.message}")
                return next_file(rest)
            mime = mimetypes.guess_type(name)[0] or "application/octet-stream"
            win.toast(f"«{name}» yuklanmoqda…", timeout=2)

            def done(data, err):
                if err:
                    win.toast(f"«{name}»: {err.message}", timeout=6)
                else:
                    on_uploaded(data)
                next_file(rest)

            api.upload(path, name, content, done, fields=fields, mime=mime)

        gfile.query_info_async("standard::size", Gio.FileQueryInfoFlags.NONE, GLib.PRIORITY_DEFAULT, None, info_done, None)

    def next_file(rest):
        if rest:
            upload_one(rest[0], rest[1:])

    def chosen(dlg, res):
        try:
            if multiple:
                model = dlg.open_multiple_finish(res)
                files = [model.get_item(i) for i in range(model.get_n_items())]
            else:
                files = [dlg.open_finish(res)]
        except GLib.Error:
            return
        next_file(files)

    if multiple:
        dialog.open_multiple(win, None, chosen)
    else:
        dialog.open(win, None, chosen)
