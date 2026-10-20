"""KPI (report.view): monthly trends with drill-down, teacher hours, leave reasons, homework report."""

from gi.repository import GLib, Gtk

from .. import fmt
from ..widgets import Page, group, join, pill, prefs_page, row, stat_card
from .reports import DateRow

MONTH_CHOICES = [(3, "3 oy"), (6, "6 oy"), (12, "12 oy")]


def _month_name(m):
    y, mo = m.split("-")
    return f"{fmt.MONTHS[int(mo) - 1].capitalize()} {y}"


def _delta(cur, prev, suffix=""):
    if cur is None or prev is None:
        return ""
    d = round(cur - prev, 1)
    return f" ({'+' if d > 0 else ''}{d}{suffix})" if d else ""


class KpiPage(Page):
    def __init__(self, win):
        super().__init__(win, "Ko'rsatkichlar (KPI)")
        self.months = 6
        self.group_id = None
        self.groups = []
        bar = Gtk.Box(spacing=6, margin_start=12, margin_end=12, margin_bottom=6)
        self.months_dd = Gtk.DropDown(model=Gtk.StringList.new([m[1] for m in MONTH_CHOICES]))
        self.months_dd.set_selected(1)
        self.months_dd.connect("notify::selected", lambda d, *_: (setattr(self, "months", MONTH_CHOICES[d.get_selected()][0]), self.reload()))
        self.group_dd = Gtk.DropDown(model=Gtk.StringList.new(["Barcha guruhlar"]), hexpand=True)
        self.group_dd.connect("notify::selected", lambda d, *_: self._loaded and (setattr(
            self, "group_id", self.groups[d.get_selected() - 1]["id"] if d.get_selected() > 0 else None), self.reload()))
        bar.append(self.months_dd)
        bar.append(self.group_dd)
        self.toolbar.add_top_bar(bar)
        hw = Gtk.Button(icon_name="tisu-quiz-symbolic", tooltip_text="Uyga vazifa hisoboti")
        hw.connect("clicked", lambda *_: self.push(HomeworkReportPage(self.win)))
        self.header.pack_start(hw)
        self.api.get("groups", lambda d, e: self._got_groups(d))

    def _got_groups(self, d):
        self.groups = [g for g in (d or {}).get("data") or [] if g.get("active")]
        self.group_dd.set_model(Gtk.StringList.new(["Barcha guruhlar"] + [g["name"] for g in self.groups]))

    def query(self):
        return {"months": self.months, "group_id": self.group_id}

    def fetch(self, done):
        self.api.get("reports/kpi", done, query=self.query())

    def render(self, d):
        s = self.settings
        series = d.get("series") or []
        cur = series[-1] if series else {}
        prev = series[-2] if len(series) > 1 else {}
        t = d.get("targets") or {}
        flow = Gtk.FlowBox(selection_mode=Gtk.SelectionMode.NONE, column_spacing=12, row_spacing=12,
                           min_children_per_line=2, max_children_per_line=4, homogeneous=True)
        cards = [
            ("Faol o'quvchilar", f"{cur.get('active_students', 0)}{_delta(cur.get('active_students'), prev.get('active_students'))}", "avatar-default-symbolic", None),
            ("Yangi / ketgan", f"{cur.get('new_students', 0)} / {cur.get('left_students', 0)}", "tisu-people-symbolic", None),
            ("Davomat", fmt.percent(cur.get("attendance_rate")) + _delta(cur.get("attendance_rate"), prev.get("attendance_rate"), "%"),
             "object-select-symbolic", "success" if (cur.get("attendance_rate") or 0) >= (t.get("attendance") or 85) else "warning"),
            ("Testdan o'tish", fmt.percent(cur.get("quiz_pass_rate")), "tisu-quiz-symbolic",
             "success" if (cur.get("quiz_pass_rate") or 0) >= (t.get("quiz_pass") or 70) else "warning"),
            ("Uyga vazifa topshirish", fmt.percent(cur.get("homework_rate")), "document-edit-symbolic", None),
            ("O'qituvchi soatlari", str(cur.get("teacher_hours") or 0), "alarm-symbolic", None),
            ("Telegram qamrovi", fmt.percent(d.get("telegram_reach_percent")), "tisu-telegram-symbolic", None),
        ]
        money = d.get("money")
        if money:
            ms = (money.get("series") or [{}])[-1]
            cur_ = money.get("currency") or s.get("billing.currency", "so'm")
            cards.append(("Tushum (shu oy)", fmt.money(ms.get("income", 0), cur_), "tisu-wallet-symbolic", "success"))
            cards.append(("Umumiy qarz", fmt.money(money.get("debt_total", 0), cur_), "alarm-symbolic", "error" if money.get("debt_total") else None))
        for title, v, icon, css in cards:
            flow.append(stat_card(title, v, icon, css))
        head = group(f"Joriy oy: {_month_name(cur['month'])}" + (" (davom etmoqda)" if cur.get("partial") else "") if cur else None,
                     f"Maqsad: davomat {t.get('attendance', 85)}%, testdan o'tish {t.get('quiz_pass', 70)}%")
        head.add(flow)

        mg = group("Oylar bo'yicha", "Oyni bosing — yangi, ketgan va faol o'quvchilar ro'yxati.")
        for m in reversed(series):
            bits = [f"faol {m.get('active_students', 0)}", f"yangi {m.get('new_students', 0)}", f"ketgan {m.get('left_students', 0)}",
                    f"davomat {fmt.percent(m.get('attendance_rate'))}", f"test {fmt.percent(m.get('quiz_pass_rate'))}",
                    f"vazifa {fmt.percent(m.get('homework_rate'))}", f"{m.get('sessions', 0)} dars", f"{m.get('teacher_hours') or 0} soat"]
            if m.get("churn_percent") is not None:
                bits.append(f"churn {fmt.percent(m['churn_percent'])}")
            r = row(_month_name(m["month"]), " · ".join(bits), icon="x-office-calendar-symbolic",
                    on_activate=lambda m=m: self.push(KpiStudentsPage(self.win, m["month"], self.query())))
            r.set_subtitle_lines(3)
            mg.add(r)
        tg = group("O'qituvchi soatlari")
        months = d.get("months") or []
        for tch in d.get("teachers") or []:
            hrs = tch.get("hours") or {}
            tg.add(row(tch.get("name"), " · ".join(f"{_month_name(mo)[:3]} {hrs.get(mo, 0)}" for mo in months[-6:]),
                       icon="avatar-default-symbolic", suffix=pill(f"{hrs.get(months[-1], 0) if months else 0} soat", "dim")))
        lr = group("Ketish sabablari (oxirgi oy)")
        for x in d.get("leave_reasons") or []:
            lr.add(row(x.get("reason") or "Sabab ko'rsatilmagan", None, suffix=pill(str(x.get("count", 0)), "warning")))
        if not d.get("leave_reasons"):
            lr.add(row("Ma'lumot yo'q.", icon="dialog-information-symbolic"))
        return prefs_page(head, mg, tg if d.get("teachers") else None, lr)


class KpiStudentsPage(Page):
    TYPES = [("new", "Yangi"), ("left", "Ketgan"), ("active", "Faol")]

    def __init__(self, win, month, query):
        super().__init__(win, _month_name(month))
        self.month, self.base = month, dict(query)
        self.type_dd = Gtk.DropDown(model=Gtk.StringList.new([t[1] for t in self.TYPES]))
        self.type_dd.connect("notify::selected", lambda *_: self.reload())
        self.header.pack_end(self.type_dd)

    def fetch(self, done):
        q = dict(self.base, type=self.TYPES[self.type_dd.get_selected()][0], month=self.month)
        q.pop("months", None)
        self.api.get("reports/kpi/students", done, query=q)

    def render(self, d):
        rows_ = d.get("data") or []
        if not rows_:
            self.show_empty("tisu-people-symbolic", "Ro'yxat bo'sh")
            return None
        g = group(None, f"{len(rows_)} ta")
        for r in rows_:
            bits = [r.get("phone"), r.get("group"), f"qo'shilgan {fmt.date(r['joined_at'])}" if r.get("joined_at") else None,
                    f"ketgan {fmt.date(r['left_at'])}" if r.get("left_at") else None, r.get("reason")]
            g.add(row(r.get("name"), " · ".join(b for b in bits if b), icon="avatar-default-symbolic",
                      on_activate=lambda r=r: self._profile(r)))
        return prefs_page(g)

    def _profile(self, r):
        from .users import UserPage

        self.push(UserPage(self.win, {"id": r["student_id"], "first_name": r.get("name"), "role": "STUDENT"}))


class HomeworkReportPage(Page):
    def __init__(self, win):
        super().__init__(win, "Uyga vazifa hisoboti")
        today = GLib.DateTime.new_now_local()
        today = GLib.DateTime.new_local(today.get_year(), today.get_month(), today.get_day_of_month(), 0, 0, 0)
        days = win.public.int("reports.default_days", 30)
        self.filters = group("Davr")
        self.from_row = DateRow("Boshlanish", today.add_days(-(days - 1)), self.reload)
        self.to_row = DateRow("Tugash", today, self.reload)
        self.filters.add(self.from_row)
        self.filters.add(self.to_row)
        self._page = None

    def fetch(self, done):
        cb = join(done, ["r"])
        self.api.get("reports/homework", cb("r"), query={"from": self.from_row.value.format("%Y-%m-%d"),
                                                          "to": self.to_row.value.format("%Y-%m-%d")})

    def render(self, data):
        d = data["r"]
        if self._page is not None:
            self._page.remove(self.filters)
        g = group("Guruhlar bo'yicha", "Topshirish foizi pastdan yuqoriga")
        for r in d.get("data") or []:
            bits = [r.get("teacher"), f"{r.get('assignments', 0)} vazifa", f"topshirildi {r.get('submitted', 0)}/{r.get('expected', 0)}",
                    f"kechikkan {r.get('late', 0)}", f"o'rtacha ball {fmt.percent(r.get('avg_homework_percent'))}",
                    f"dars baholari {r.get('session_marks', 0)} (o'rtacha {fmt.percent(r.get('avg_mark_percent'))})"]
            rate = r.get("submission_rate")
            from .homework import HomeworkPage

            rw = row(r.get("group"), " · ".join(b for b in bits if b), icon="tisu-people-symbolic",
                     suffix=pill(fmt.percent(rate), "success" if (rate or 0) >= 80 else "warning" if (rate or 0) >= 50 else "error"),
                     on_activate=lambda r=r: self.push(HomeworkPage(self.win, group_id=r["group_id"])))
            rw.set_subtitle_lines(3)
            g.add(rw)
        if not d.get("data"):
            g.add(row("Bu davrda vazifa berilmagan.", icon="dialog-information-symbolic"))
        page = prefs_page(self.filters, g)
        self._page = page
        return page
