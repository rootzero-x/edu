"""Billing: balances & debtors, income report, payments / adjustments / voiding (billing.manage);
own balance for students and parents."""

import uuid

from gi.repository import GLib, Gtk

from .. import fmt
from ..csvexport import save_csv
from ..forms import form, prompt
from ..widgets import Page, group, icon_button, join, pill, prefs_page, row, stat_card, text_button
from .reports import DateRow

STATUS_FILTERS = [("all", "Hammasi"), ("debt", "Qarzdorlar"), ("credit", "Oldindan to'laganlar"), ("zero", "Balans 0")]
SORTS = [("", "Avval qarzdorlar"), ("-balance", "Avval ko'p to'laganlar"), ("name", "Ism bo'yicha"),
         ("last_payment", "Oxirgi to'lov (eski)")]


def currency(s):
    return s.get("billing.currency", "so'm")


def money(s, v):
    return fmt.money(v, currency(s))


def method_options(s):
    labels = s.get("billing.method_labels", {"CASH": "Naqd", "CARD": "Karta", "TRANSFER": "O'tkazma"})
    return list(labels.items()) if isinstance(labels, dict) else [("CASH", "Naqd")]


def type_label(s, t):
    labels = s.get("billing.type_labels", {})
    defaults = {"CHARGE": "Hisoblash", "PAYMENT": "To'lov", "ADJUSTMENT": "Tuzatish"}
    return (labels.get(t) if isinstance(labels, dict) else None) or defaults.get(t, t)


def balance_pill(s, balance):
    return pill(money(s, balance), "error" if balance < 0 else "success" if balance > 0 else "dim")


def _parse_amount(text):
    digits = "".join(ch for ch in (text or "") if ch.isdigit())
    return int(digits) if digits else 0


def payment_dialog(page, student_id, name, on_done):
    s = page.settings
    max_pay = s.int("billing.max_payment", 20_000_000)
    request_id = uuid.uuid4().hex  # the server de-duplicates double submits with it
    today = fmt.now().format("%Y-%m-%d")

    def submit(v, done):
        amount = _parse_amount(v["amount"])
        if amount <= 0 or amount > max_pay:
            done(ValueError(f"Summa 1 dan {money(s, max_pay)} gacha bo'lishi kerak."))
            return

        def ok(data, err):
            done(err)
            if not err:
                page.win.toast(f"To'lov qabul qilindi: {money(s, amount)}. Balans: {money(s, data.get('balance', 0))}", timeout=5)
                on_done()

        page.api.post(f"billing/students/{student_id}/payments", {
            "amount": amount, "method": v["method"], "date": v["date"], "note": v["note"] or None, "request_id": request_id}, ok)

    form(page.win, f"To'lov qabul qilish — {name}", [
        {"name": "amount", "label": f"Summa ({currency(s)})", "required": True},
        {"name": "method", "label": "To'lov usuli", "type": "choice", "options": method_options(s)},
        {"name": "date", "label": "Sana (YYYY-MM-DD)", "type": "date", "value": today, "required": True,
         "hint": f"{s.int('billing.max_backdate_days', 31)} kungacha orqaga yozish mumkin"},
        {"name": "note", "label": "Izoh (ixtiyoriy)"},
    ], submit, submit_label="Qabul qilish")


def adjustment_dialog(page, student_id, name, on_done):
    s = page.settings
    max_pay = s.int("billing.max_payment", 20_000_000)

    def submit(v, done):
        amount = _parse_amount(v["amount"])
        if amount <= 0 or amount > max_pay:
            done(ValueError(f"Summa 1 dan {money(s, max_pay)} gacha bo'lishi kerak."))
            return
        if len(v["reason"]) < 3:
            done(ValueError("Sababni yozing (kamida 3 belgi)."))
            return
        signed = amount if v["direction"] == "+" else -amount

        def ok(data, err):
            done(err)
            if not err:
                page.win.toast(f"Tuzatish kiritildi. Balans: {money(s, data.get('balance', 0))}", timeout=5)
                on_done()

        page.api.post(f"billing/students/{student_id}/adjustments",
                      {"amount": signed, "reason": v["reason"], "date": v["date"]}, ok)

    form(page.win, f"Tuzatish — {name}", [
        {"name": "direction", "label": "Yo'nalish", "type": "choice",
         "options": [("+", "Balansni oshirish (chegirma, qaytarish)"), ("-", "Balansni kamaytirish (qo'shimcha to'lov)")]},
        {"name": "amount", "label": f"Summa ({currency(s)})", "required": True},
        {"name": "reason", "label": "Sabab", "required": True},
        {"name": "date", "label": "Sana (YYYY-MM-DD)", "type": "date", "value": fmt.now().format("%Y-%m-%d"), "required": True},
    ], submit, submit_label="Kiritish")


class BillingPage(Page):
    def __init__(self, win):
        super().__init__(win, win.public.nav_label("/dashboard/billing", "To'lovlar"))
        self.manage = win.has("billing.manage")
        self.items = []
        self.page_no = 1
        self.pages = 1
        if self.manage:
            self.report_btn = icon_button("tisu-chart-symbolic", "Tushum hisoboti",
                                          lambda: self.push(BillingReportPage(self.win)), css=())
            self.header.pack_start(self.report_btn)
            self.csv_btn = icon_button("document-save-symbolic", "Balanslarni CSV ga yuklab olish", self.export, css=())
            self.header.pack_start(self.csv_btn)
            bar = Gtk.Box(spacing=6, margin_start=12, margin_end=12, margin_bottom=6)
            self.search = Gtk.SearchEntry(placeholder_text="O'quvchi ismi yoki telefoni", hexpand=True)
            self.search.connect("search-changed", lambda *_: self._debounce())
            self.status_dd = Gtk.DropDown(model=Gtk.StringList.new([x[1] for x in STATUS_FILTERS]))
            self.sort_dd = Gtk.DropDown(model=Gtk.StringList.new([x[1] for x in SORTS]))
            self.groups = []
            self.group_dd = Gtk.DropDown(model=Gtk.StringList.new(["Barcha guruhlar"]))
            for dd in (self.status_dd, self.sort_dd, self.group_dd):
                dd.connect("notify::selected", lambda *_: self._loaded and self.reload())
            bar.append(self.search)
            for w in (self.status_dd, self.group_dd, self.sort_dd):
                bar.append(w)
            self.toolbar.add_top_bar(bar)
            self._timer = 0
            self._load_groups()

    def _load_groups(self):
        def done(data, err):
            if data:
                self.groups = data.get("data") or []
                self.group_dd.set_model(Gtk.StringList.new(["Barcha guruhlar"] + [g["name"] for g in self.groups]))

        self.api.get("groups", done)

    def _debounce(self):
        if self._timer:
            GLib.source_remove(self._timer)
        self._timer = GLib.timeout_add(350, lambda: (setattr(self, "_timer", 0), self.reload()) and False)

    def query(self, page=1):
        q = {"q": self.search.get_text().strip() or None, "page": page}
        st = STATUS_FILTERS[self.status_dd.get_selected()][0]
        if st != "all":
            q["status"] = st
        so = SORTS[self.sort_dd.get_selected()][0]
        if so:
            q["sort"] = so
        gi_ = self.group_dd.get_selected()
        if 0 < gi_ <= len(self.groups):
            q["group_id"] = self.groups[gi_ - 1]["id"]
        return q

    def fetch(self, done):
        if self.manage:
            self.page_no = 1
            self.api.get("billing/balances", done, query=self.query())
        else:
            self.api.get("billing/me", done)

    def render(self, data):
        return self._render_manager(data) if self.manage else self._render_own(data)

    # ── Manager: balances ────────────────────────────────────
    def _render_manager(self, data):
        self.items = list(data.get("data") or [])
        meta = data.get("meta") or {}
        self.pages = meta.get("pages", 1)
        self.summary = data.get("summary") or {}
        return self._build()

    def _build(self):
        s = self.settings
        sm = self.summary
        flow = Gtk.FlowBox(selection_mode=Gtk.SelectionMode.NONE, column_spacing=12, row_spacing=12,
                           min_children_per_line=2, max_children_per_line=4, homogeneous=True)
        flow.append(stat_card("O'quvchilar", sm.get("students", 0), "avatar-default-symbolic"))
        flow.append(stat_card("Qarzdorlar", sm.get("debtors", 0), "alarm-symbolic", "error" if sm.get("debtors") else None))
        flow.append(stat_card("Umumiy qarz", money(s, sm.get("total_debt", 0)), "tisu-wallet-symbolic", "error" if sm.get("total_debt") else None))
        flow.append(stat_card("Oldindan to'langan", money(s, sm.get("total_credit", 0)), "object-select-symbolic"))
        head = group()
        head.add(flow)

        g = group("Balanslar")
        if not self.items:
            g.add(row("Hech kim topilmadi.", icon="edit-find-symbolic"))
        for r in self.items:
            sub = " · ".join(x for x in [r.get("phone"), ", ".join(r.get("groups") or []),
                                          f"oxirgi to'lov: {fmt.date(r['last_payment_date'])}" if r.get("last_payment_date") else "to'lov yo'q"] if x)
            pay = text_button("To'lov", lambda r=r: payment_dialog(self, r["student_id"], fmt.name(r), self.reload), css=("flat",))
            suffix = [balance_pill(s, r["balance"]), pay]
            if not r.get("active", True):
                suffix.insert(0, pill("bloklangan", "dim"))
            g.add(row(fmt.name(r), sub, icon="avatar-default-symbolic", suffix=suffix,
                      on_activate=lambda r=r: self.push(StudentBillingPage(self.win, r["student_id"], fmt.name(r), on_changed=self.reload))))
        more = None
        if self.page_no < self.pages:
            more = group()
            b = text_button("Ko'proq ko'rsatish", self.load_more, css=("pill",))
            b.set_halign(Gtk.Align.CENTER)
            more.add(b)
        return prefs_page(head, g, more)

    def load_more(self):
        def done(data, err):
            if err:
                self.win.toast(err.message)
                return
            self.page_no += 1
            self.items.extend(data.get("data") or [])
            self.set_content(self._build())

        self.api.get("billing/balances", done, query=self.query(self.page_no + 1))

    def export(self):
        q = {k: v for k, v in self.query().items() if k != "page"}
        save_csv(self.win, self.api, "billing/balances.csv", q, f"balanslar_{fmt.now().format('%Y%m%d')}.csv", self.csv_btn)

    # ── Student / parent: own balance ────────────────────────
    def _render_own(self, data):
        s = self.settings
        if data.get("enabled") is False:
            self.show_empty("tisu-wallet-symbolic", "To'lovlar tizimi o'chirilgan")
            return None
        students = data.get("students") or []
        if not students:
            self.show_empty("tisu-wallet-symbolic", "To'lov ma'lumoti yo'q")
            return None
        groups_ = []
        for st in students:
            person = st["student"]
            g = group(fmt.name(person) if len(students) > 1 or self.win.role == "PARENT" else "Mening balansim")
            bal = st.get("balance", 0)
            g.add(row("Balans", "Qarz" if bal < 0 else "Ortiqcha to'lov" if bal > 0 else "Qarz yo'q", icon="tisu-wallet-symbolic",
                      suffix=balance_pill(s, bal)))
            g.add(row("Shu oy hisoblangan", money(s, st.get("month_charged", 0)), icon="x-office-calendar-symbolic"))
            lp = st.get("last_payment")
            if lp:
                g.add(row("Oxirgi to'lov", f"{money(s, lp['amount'])} · {fmt.date(lp['date'])}", icon="object-select-symbolic"))
            g.add(row("Batafsil", "Oylar kesimida va barcha yozuvlar", icon="view-list-bullet-symbolic",
                      on_activate=lambda p=person: self.push(StudentBillingPage(self.win, p["id"], fmt.name(p)))))
            groups_.append(g)
            if st.get("recent"):
                rg = group("So'nggi yozuvlar")
                for e in st["recent"]:
                    rg.add(entry_row(self, e))
                groups_.append(rg)
        return prefs_page(*groups_)


def entry_row(page, e, on_void=None):
    s = page.settings
    t = type_label(s, e["type"])
    methods = dict(method_options(s))
    bits = [fmt.date(e["entry_date"])]
    if e.get("method"):
        bits.append(methods.get(e["method"], e["method"]))
    if e.get("group"):
        bits.append(e["group"])
    if e.get("note"):
        bits.append(e["note"])
    if e.get("voided"):
        bits.append("bekor qilingan" + (f": {e['void_reason']}" if e.get("void_reason") else ""))
    amount = e["amount"]
    css = "dim" if e.get("voided") else ("success" if amount > 0 else "error" if amount < 0 else "dim")
    suffix = [pill(("+" if amount > 0 else "") + money(s, amount), css)]
    if on_void and e["type"] != "CHARGE" and not e.get("voided"):
        suffix.append(icon_button("edit-delete-symbolic", "Bekor qilish", lambda: on_void(e)))
    r = row(t, " · ".join(bits), icon={"PAYMENT": "object-select-symbolic", "ADJUSTMENT": "document-edit-symbolic"}.get(
        e["type"], "x-office-calendar-symbolic"), suffix=suffix)
    if e.get("voided"):
        r.add_css_class("dim-label")
    return r


class StudentBillingPage(Page):
    TYPES = [("", "Barcha yozuvlar"), ("MANUAL", "To'lov va tuzatishlar"), ("CHARGE", "Kunlik hisoblashlar")]

    def __init__(self, win, student_id, name, on_changed=None):
        super().__init__(win, name)
        self.student_id = student_id
        self.name = name
        self.on_changed = on_changed
        self.entries = []
        self.page_no = 1
        self.pages = 1
        self.type_dd = Gtk.DropDown(model=Gtk.StringList.new([t[1] for t in self.TYPES]))
        self.type_dd.connect("notify::selected", lambda *_: self._loaded and self.reload())

    def query(self, page=1):
        t = self.TYPES[self.type_dd.get_selected()][0]
        return {"type": t or None, "page": page}

    def fetch(self, done):
        self.page_no = 1
        self.api.get(f"billing/students/{self.student_id}", done, query=self.query())

    def changed(self):
        self.reload()
        if self.on_changed:
            self.on_changed()

    def render(self, data):
        self.data = data
        self.entries = list(data.get("entries") or [])
        self.pages = (data.get("meta") or {}).get("pages", 1)
        return self._build()

    def _build(self):
        s, d = self.settings, self.data
        self.set_title(fmt.name(d.get("student")) or self.name)
        bal = d.get("balance", 0)
        flow = Gtk.FlowBox(selection_mode=Gtk.SelectionMode.NONE, column_spacing=12, row_spacing=12,
                           min_children_per_line=2, max_children_per_line=4, homogeneous=True)
        flow.append(stat_card("Balans", money(s, bal), "tisu-wallet-symbolic", "error" if bal < 0 else "success" if bal > 0 else None))
        tot = d.get("totals") or {}
        flow.append(stat_card("Jami to'langan", money(s, tot.get("paid", 0)), "object-select-symbolic"))
        flow.append(stat_card("Jami hisoblangan", money(s, tot.get("charged", 0)), "x-office-calendar-symbolic"))
        flow.append(stat_card("Oylik narx", money(s, d.get("monthly_fee", 0)), "tisu-book-symbolic"))
        head = group(None, (d.get("student") or {}).get("phone"))
        head.add(flow)
        groups_ = [head]

        if d.get("can_manage"):
            acts = Gtk.Box(spacing=12, halign=Gtk.Align.CENTER, margin_top=6)
            pay = text_button("To'lov qabul qilish", lambda: payment_dialog(self, self.student_id, self.name, self.changed),
                              css=("pill", "suggested-action"))
            adj = text_button("Tuzatish", lambda: adjustment_dialog(self, self.student_id, self.name, self.changed), css=("pill",))
            acts.append(pay)
            acts.append(adj)
            ag = group()
            ag.add(acts)
            groups_.append(ag)

        if d.get("groups"):
            gg = group("Guruhlar")
            for g in d["groups"]:
                sub = f"qo'shilgan: {fmt.date(g.get('joined_at'))}"
                if g.get("daily_charge"):
                    sub += f" · kuniga {money(s, g['daily_charge'])}"
                gg.add(row(g["name"], sub, icon="tisu-people-symbolic", suffix=None if g.get("active") else pill("yopiq", "dim")))
            groups_.append(gg)

        if d.get("months"):
            mg = group("Oylar kesimida")
            for m in d["months"]:
                y, mo = m["month"].split("-")
                mg.add(row(f"{fmt.MONTHS[int(mo) - 1].capitalize()} {y}",
                           f"hisoblandi {money(s, m['charged'])} · to'landi {money(s, m['paid'])}"
                           + (f" · tuzatish {money(s, m['adjusted'])}" if m.get("adjusted") else "")))
            groups_.append(mg)

        if getattr(self, "_entries_group", None) is not None:
            self._entries_group.set_header_suffix(None)  # the filter dropdown moves to the rebuilt group
        eg = self._entries_group = group("Yozuvlar", suffix=self.type_dd)
        if not self.entries:
            eg.add(row("Yozuvlar yo'q.", icon="dialog-information-symbolic"))
        for e in self.entries:
            eg.add(entry_row(self, e, on_void=self.void if d.get("can_manage") else None))
        groups_.append(eg)
        if self.page_no < self.pages:
            more = group()
            b = text_button("Ko'proq ko'rsatish", self.load_more, css=("pill",))
            b.set_halign(Gtk.Align.CENTER)
            more.add(b)
            groups_.append(more)
        return prefs_page(*groups_)

    def load_more(self):
        def done(data, err):
            if err:
                self.win.toast(err.message)
                return
            self.page_no += 1
            self.entries.extend(data.get("entries") or [])
            self.set_content(self._build())

        self.api.get(f"billing/students/{self.student_id}", done, query=self.query(self.page_no + 1))

    def void(self, e):
        s = self.settings

        def reason(text):
            def ok(data, err):
                self.win.toast(err.message if err else f"Bekor qilindi. Balans: {money(s, (data or {}).get('balance', 0))}",
                               timeout=5)
                if not err:
                    self.changed()

            self.api.post(f"billing/entries/{e['id']}/void", {"reason": text}, ok)

        prompt(self.win, "Yozuv bekor qilinsinmi?",
               f"{type_label(s, e['type'])} {money(s, e['amount'])} · {fmt.date(e['entry_date'])}. Yozuv o'chirilmaydi, "
               "«bekor qilingan» deb belgilanadi.", "Bekor qilish sababi", "Bekor qilish", reason, min_len=3, destructive=True)


class BillingReportPage(Page):
    def __init__(self, win):
        super().__init__(win, "Tushum hisoboti")
        today = GLib.DateTime.new_now_local()
        start = GLib.DateTime.new_local(today.get_year(), today.get_month(), 1, 0, 0, 0)
        today = GLib.DateTime.new_local(today.get_year(), today.get_month(), today.get_day_of_month(), 0, 0, 0)
        self.filters = group("Davr")
        self.from_row = DateRow("Boshlanish", start, self.reload)
        self.to_row = DateRow("Tugash", today, self.reload)
        self.filters.add(self.from_row)
        self.filters.add(self.to_row)
        self.csv_btn = icon_button("document-save-symbolic", "To'lov va tuzatishlarni CSV ga yuklab olish", self.export, css=())
        self.header.pack_start(self.csv_btn)
        self._page = None

    def query(self):
        return {"from": self.from_row.value.format("%Y-%m-%d"), "to": self.to_row.value.format("%Y-%m-%d")}

    def fetch(self, done):
        cb = join(done, ["report"])
        self.api.get("billing/report", cb("report"), query=self.query())

    def render(self, data):
        s = self.settings
        r = data["report"]
        if self._page is not None:
            self._page.remove(self.filters)
        flow = Gtk.FlowBox(selection_mode=Gtk.SelectionMode.NONE, column_spacing=12, row_spacing=12,
                           min_children_per_line=2, max_children_per_line=4, homogeneous=True)
        flow.append(stat_card("Tushum", money(s, r.get("income", 0)), "object-select-symbolic", "success"))
        flow.append(stat_card("To'lovlar soni", r.get("payments_count", 0), "view-list-bullet-symbolic"))
        flow.append(stat_card("Hisoblangan", money(s, r.get("charged", 0)), "x-office-calendar-symbolic"))
        flow.append(stat_card("Tuzatishlar", money(s, r.get("adjusted", 0)), "document-edit-symbolic"))
        flow.append(stat_card("Umumiy qarz", money(s, r.get("total_debt", 0)), "alarm-symbolic", "error" if r.get("total_debt") else None))
        flow.append(stat_card("Qarzdorlar", r.get("debtors", 0), "avatar-default-symbolic"))
        per = r.get("period") or {}
        sg = group(f"{fmt.date(per.get('from'))} — {fmt.date(per.get('to'))}")
        sg.add(flow)
        v = r.get("voided") or {}
        if v.get("count"):
            sg.set_description(f"Bekor qilingan to'lovlar: {v['count']} ta, {money(s, v.get('amount', 0))}")
        methods = dict(method_options(s))
        mg = group("To'lov usullari bo'yicha")
        for m in r.get("by_method") or []:
            mg.add(row(methods.get(m["method"], m["method"] or "—"), f"{m['count']} ta to'lov", suffix=pill(money(s, m["amount"]), "success")))
        if not r.get("by_method"):
            mg.add(row("Bu davrda to'lov yo'q.", icon="dialog-information-symbolic"))
        dg = group("Kunlar bo'yicha")
        for x in reversed(r.get("by_day") or []):
            dg.add(row(fmt.date(x["date"]), f"{x['count']} ta to'lov", suffix=pill(money(s, x["amount"]), "success")))
        page = prefs_page(self.filters, sg, mg, dg if r.get("by_day") else None)
        self._page = page
        return page

    def export(self):
        q = self.query()
        save_csv(self.win, self.api, "billing/entries.csv", q,
                 f"tolovlar_{q['from'].replace('-', '')}_{q['to'].replace('-', '')}.csv", self.csv_btn)

