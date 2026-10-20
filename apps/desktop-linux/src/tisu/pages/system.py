"""Dashboard cards for administrators: system status (cron, backup, Telegram) and the setup checklist."""

from .. import fmt
from ..widgets import group, icon_button, pill, row

WARNINGS = {
    "cron_never": "Cron hali bir marta ham ishlamagan: serverda «* * * * * php artisan schedule:run» qo'yilganini tekshiring.",
    "cron_stale": "Cron to'xtagan: eslatmalar, zaxira va to'lov hisoblari ishlamayapti.",
    "job_overdue": "Ba'zi avtomatik vazifalar kechikmoqda.",
    "backup_unencrypted": "Zaxira shifrlanmagan va serverdan tashqariga yuborilmayapti: .env ga BACKUP_ENCRYPTION_KEY qo'shing.",
    "teacher_course_manage": "O'qituvchilarda «Barcha kurslarni boshqarish» huquqi yoqilgan (eski sozlama). "
                             "Sozlamalar → Rollar huquqlarida «O'z kurslarini yaratish» ga almashtiring.",
}

SETUP_DEFAULTS = {
    "title": "Platformani sozlashni yakunlang",
    "text": "Har bir band haqiqiy ma'lumotdan belgilanadi. Hammasi tayyor bo'lgach ro'yxat yashiriladi.",
    "site": "Markaz nomi va aloqa ma'lumotlari", "bot": "Telegram botni ulang va admin chatini qo'shing",
    "telegram": "O'z Telegramingizni ulang", "billing": "Kurs narxini tasdiqlang", "course": "Birinchi kursni yarating",
    "teacher": "O'qituvchi qo'shing", "group": "Guruh oching", "schedule": "Guruh jadvalini kiriting",
    "students": "O'quvchilarni qo'shing yoki import qiling", "dismiss": "Yashirish",
}


def status_group(win, st):
    g = group("Tizim holati", "Cron, zaxira va Telegram xabarlari")
    for w in st.get("warnings") or []:
        code = w if isinstance(w, str) else w.get("code")
        r = row(WARNINGS.get(code, str(code)), None, icon="dialog-warning-symbolic")
        r.set_title_lines(0)
        r.add_css_class("warning")
        g.add(r)
    c = st.get("scheduler") or {}
    jobs = st.get("jobs") or []
    overdue = [j["job"] for j in jobs if j.get("overdue")]
    if c.get("last_tick"):
        sub = f"oxirgi ishga tushish: {fmt.datetime(c['last_tick'])}"
    else:
        sub = "hali ishlamagan"
    if overdue:
        sub += " · kechikmoqda: " + ", ".join(overdue)
    g.add(row("Cron (avtomatik vazifalar)", sub, icon="alarm-symbolic",
              suffix=pill("to'xtagan" if c.get("stale") else ("kechikish" if overdue else "ishlayapti"),
                          "error" if c.get("stale") else "warning" if overdue else "success")))
    b = st.get("backup") or {}
    if b.get("at"):
        sub = f"{fmt.datetime(b['at'])}" + (f" · {round((b.get('size') or 0) / 1048576, 1)} MB" if b.get("size") else "")
        sub += " · shifrlangan" if b.get("encrypted") else " · shifrlanmagan"
        ok = b.get("ok")
        g.add(row("Zaxira nusxa", sub + (f" · {b['error']}" if b.get("error") else ""), icon="document-save-symbolic",
                  suffix=pill("tayyor" if ok else "xato", "success" if ok else "error")))
    else:
        g.add(row("Zaxira nusxa", "hali olinmagan", icon="document-save-symbolic", suffix=pill("yo'q", "warning")))
    t = st.get("telegram") or {}
    warn = (t.get("failed_24h") or 0) > 0 or (t.get("pending") or 0) > 200
    g.add(row("Telegram xabarlari", f"navbatda {t.get('pending', 0)} · 24 soatda yuborildi {t.get('sent_24h', 0)} · "
                                   f"xato {t.get('failed_24h', 0)} · botni bloklagan {t.get('blocked_chats', 0)}",
              icon="tisu-telegram-symbolic", suffix=pill("e'tibor" if warn else "yaxshi", "warning" if warn else "success"),
              on_activate=lambda: win.navigate("/dashboard/users?view=tg_blocked")))
    u = st.get("users") or {}
    g.add(row("Bot orqali arizalar", f"ko'rib chiqilmagan: {u.get('pending', 0)} · Telegram ulanmagan: {u.get('no_telegram', 0)}",
              icon="tisu-people-symbolic", suffix=pill(str(u.get("pending", 0)), "warning" if u.get("pending") else "success"),
              on_activate=lambda: win.navigate("/dashboard/users?view=pending")))
    return g


def setup_group(win, setup, on_dismiss):
    labels = dict(SETUP_DEFAULTS)
    custom = win.public.get("ui.setup_labels", {})
    if isinstance(custom, dict):
        labels.update({k: v for k, v in custom.items() if v})
    steps = setup.get("steps") or []
    dismiss = icon_button("window-close-symbolic", labels.get("dismiss", "Yashirish"), on_dismiss)
    g = group(f"{labels['title']} — {setup.get('done', 0)}/{setup.get('total', len(steps))}", labels.get("text"), suffix=dismiss)
    for st in steps:
        done = st.get("done")
        g.add(row(labels.get(st["key"], st["key"]), None, icon="object-select-symbolic" if done else "radio-symbolic",
                  suffix=pill("tayyor", "success") if done else None,
                  on_activate=None if done else (lambda st=st: win.navigate(st.get("href") or "/dashboard"))))
    return g
