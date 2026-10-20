"""Date/number formatting in the school's timezone (from public settings), Uzbek style."""

from gi.repository import GLib

MONTHS = ["yanvar", "fevral", "mart", "aprel", "may", "iyun", "iyul", "avgust",
          "sentabr", "oktabr", "noyabr", "dekabr"]
_DEFAULT_WEEKDAYS = "Du,Se,Cho,Pa,Ju,Sha,Ya"

_tz = None
_weekdays = _DEFAULT_WEEKDAYS.split(",")


def configure(settings):
    """Called whenever public settings change."""
    global _tz, _weekdays
    try:
        _tz = GLib.TimeZone.new_identifier(settings.timezone)
    except (TypeError, GLib.Error):
        _tz = None
    if _tz is None:
        _tz = GLib.TimeZone.new_local()
    wd = settings.text("weekdays", _DEFAULT_WEEKDAYS).split(",")
    _weekdays = wd if len(wd) == 7 else _DEFAULT_WEEKDAYS.split(",")


def tz():
    return _tz or GLib.TimeZone.new_local()


def parse(value):
    """ISO-8601 string → GLib.DateTime in school timezone (or None)."""
    if not value:
        return None
    s = str(value).replace(" ", "T", 1)
    if len(s) == 10:  # date only (e.g. a group's start_date)
        s += "T00:00:00"
    try:
        dt = GLib.DateTime.new_from_iso8601(s, tz())
    except TypeError:  # PyGObject raises when the constructor returns NULL
        return None
    return dt.to_timezone(tz()) if dt else None


def now():
    return GLib.DateTime.new_now(tz())


def weekday(n):
    """1=Monday … 7=Sunday"""
    return _weekdays[(int(n) - 1) % 7]


def date(value):
    dt = parse(value) if not isinstance(value, GLib.DateTime) else value
    return dt.format("%d.%m.%Y") if dt else "—"


def time(value):
    dt = parse(value) if not isinstance(value, GLib.DateTime) else value
    return dt.format("%H:%M") if dt else "—"


def datetime(value):
    dt = parse(value) if not isinstance(value, GLib.DateTime) else value
    return dt.format("%d.%m.%Y %H:%M") if dt else "—"


def long_date(value):
    dt = parse(value) if not isinstance(value, GLib.DateTime) else value
    if not dt:
        return "—"
    return f"{weekday(dt.get_day_of_week())}, {dt.get_day_of_month()}-{MONTHS[dt.get_month() - 1]} {dt.get_year()}"


def day_label(value, today_text="Bugun", tomorrow_text="Ertaga"):
    dt = parse(value) if not isinstance(value, GLib.DateTime) else value
    if not dt:
        return "—"
    n = now()
    diff = _ymd(dt) - _ymd(n)
    if diff == 0:
        return today_text
    if diff == 1:
        return tomorrow_text
    return f"{weekday(dt.get_day_of_week())}, {dt.format('%d.%m')}"


def _ymd(dt):
    return GLib.Date.new_dmy(dt.get_day_of_month(), GLib.DateMonth(dt.get_month()), dt.get_year()).get_julian()


def is_past(value):
    dt = parse(value)
    return bool(dt) and dt.compare(now()) <= 0


def percent(v):
    if v is None:
        return "—"
    v = float(v)
    return f"{v:.0f}%" if v == int(v) else f"{v:.1f}%"


def name(obj):
    if not obj:
        return "—"
    if isinstance(obj, str):
        return obj
    return " ".join(p for p in (obj.get("first_name"), obj.get("last_name")) if p) or "—"


def duration(seconds):
    seconds = max(0, int(seconds))
    h, rem = divmod(seconds, 3600)
    m, s = divmod(rem, 60)
    return f"{h}:{m:02d}:{s:02d}" if h else f"{m:02d}:{s:02d}"
