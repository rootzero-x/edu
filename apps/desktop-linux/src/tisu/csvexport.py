"""Save a file (CSV report, import template …) from the API to a place chosen with the system file dialog."""

from gi.repository import Adw, Gio, GLib, Gtk


def save_csv(win, api, path, query, default_name, button=None):
    save_file(win, api, path, query, default_name, button, "CSV (Excel)", "*.csv")


def save_file(win, api, path, query, default_name, button=None, filter_name=None, pattern=None):
    dialog = Gtk.FileDialog(title="Faylni saqlash", initial_name=default_name)
    f = Gtk.FileFilter(name=filter_name or "Fayl")
    f.add_pattern(pattern or "*")
    filters = Gio.ListStore.new(Gtk.FileFilter)
    filters.append(f)
    dialog.set_filters(filters)

    def busy(b):
        if button is not None:
            button.set_sensitive(not b)

    def chosen(dlg, result):
        try:
            file = dlg.save_finish(result)
        except GLib.Error:
            return  # cancelled
        busy(True)

        def got(data, err):
            if err:
                busy(False)
                win.toast(err.message, timeout=5)
                return

            def written(fl, res, _d):
                busy(False)
                try:
                    fl.replace_contents_finish(res)
                except GLib.Error as e:
                    win.toast(f"Faylni yozib bo'lmadi: {e.message}", timeout=5)
                    return
                toast = Adw.Toast(title=GLib.markup_escape_text(f"Saqlandi: {fl.get_basename()}"), button_label="Ochish", timeout=5)
                toast.connect("button-clicked", lambda *_: Gtk.FileLauncher.new(fl).launch(win, None, None, None))
                win.toasts.add_toast(toast)

            file.replace_contents_bytes_async(GLib.Bytes.new(data["bytes"]), None, False,
                                              Gio.FileCreateFlags.REPLACE_DESTINATION, None, written, None)

        api.get(path, got, query=query, raw=True)

    dialog.save(win, None, chosen)
