import Link from "next/link";
import type { ReactNode } from "react";
import {
  ArrowRight, Award, BarChart3, BookOpen, CalendarCheck, CheckCircle2, ClipboardCheck, Clock, GraduationCap, Layers, Mail,
  MapPin, MessageCircle, Phone, Send, ShieldCheck, Users, Video, Wallet, type LucideIcon,
} from "lucide-react";
import { API_URL, publicCourses, publicSettings } from "@/lib/api";
import { list, siteName, str } from "@/lib/settings";
import { fmtMoney } from "@/lib/format";
import { ButtonLink } from "@/components/ui";
import ThemeToggle from "@/components/ThemeToggle";
import s from "./landing.module.css";

export const revalidate = 60;

/** site.features[].icon uchun ruxsat etilgan nomlar. Ko'rsatilmasa tartib bo'yicha tanlanadi. */
const ICONS: Record<string, LucideIcon> = {
  book: BookOpen, quiz: ClipboardCheck, calendar: CalendarCheck, wallet: Wallet, award: Award, telegram: Send,
  users: Users, chart: BarChart3, video: Video, shield: ShieldCheck, chat: MessageCircle, graduation: GraduationCap, layers: Layers,
};
const FALLBACK_ICONS = [BookOpen, ClipboardCheck, CalendarCheck, Wallet, Award, Send, Users, BarChart3];

async function apiStatus(): Promise<boolean> {
  try {
    const res = await fetch(`${API_URL}/health`, { next: { revalidate: 60 }, signal: AbortSignal.timeout(3000) });
    return res.ok;
  } catch {
    return false;
  }
}

/** "*so'z*" — asosiy rangda ajratib ko'rsatiladi. */
function emphasize(text: string): ReactNode {
  return text.split(/(\*[^*]+\*)/g).map((part, i) => (part.startsWith("*") && part.endsWith("*") ? <em key={i}>{part.slice(1, -1)}</em> : part));
}

function telegramUrl(v: string) {
  if (/^https?:\/\//.test(v)) return v;
  return `https://t.me/${v.replace(/^@/, "")}`;
}

export default async function Home() {
  const [st, courses, ok] = await Promise.all([publicSettings(), publicCourses(), apiStatus()]);
  const name = siteName(st);
  const logo = str(st, "site.logo_url");
  const features = list<{ title: string; text: string; icon?: string }>(st, "site.features").filter((f) => f?.title);
  const steps = list<{ title: string; text: string }>(st, "site.steps").filter((f) => f?.title);
  const stats = list<{ value: string; label: string }>(st, "site.stats").filter((x) => x?.value);
  const socials = list<{ label: string; url: string }>(st, "site.social_links").filter((x) => x?.url);
  const currency = str(st, "site.currency");
  const heroImage = str(st, "site.hero_image_url");

  const phone = str(st, "site.contact_phone");
  const tg = str(st, "site.contact_telegram");
  const email = str(st, "site.contact_email");
  const address = str(st, "site.address");
  const hours = str(st, "site.working_hours");
  const mapUrl = str(st, "site.map_url");
  const contacts: { icon: LucideIcon; value: string; href?: string; label: string }[] = [
    phone && { icon: Phone, value: phone, href: `tel:${phone.replace(/[^\d+]/g, "")}`, label: "phone" },
    tg && { icon: Send, value: tg, href: telegramUrl(tg), label: "telegram" },
    email && { icon: Mail, value: email, href: `mailto:${email}`, label: "email" },
    address && { icon: MapPin, value: address, href: mapUrl || undefined, label: "address" },
    hours && { icon: Clock, value: hours, label: "hours" },
  ].filter(Boolean) as { icon: LucideIcon; value: string; href?: string; label: string }[];

  const featuresTitle = str(st, "site.features_title");
  const coursesTitle = str(st, "site.courses_title");
  const contactTitle = str(st, "site.contact_title");
  const login = str(st, "site.nav_login", "Kirish");
  const footer = str(st, "site.footer_text", "© {year} {name}").replace("{year}", String(new Date().getFullYear())).replace("{name}", name);

  return (
    <div className={s.page}>
      <a href="#hero" className="skip-link">{name}</a>
      <header className={s.header}>
        <div className={`${s.container} ${s.headerInner}`}>
          <Link href="/" className={s.brand}>
            <span className={s.mark} aria-hidden>{logo ? <img src={logo} alt="" /> : name.slice(0, 1)}</span>
            <span>{name}</span>
          </Link>
          <nav className={s.nav} aria-label={name}>
            {features.length > 0 && featuresTitle && <a href="#features">{featuresTitle}</a>}
            {courses.length > 0 && coursesTitle && <a href="#courses">{coursesTitle}</a>}
            {contacts.length > 0 && contactTitle && <a href="#contact">{contactTitle}</a>}
          </nav>
          <div className={s.headerActions}>
            <ThemeToggle />
            <ButtonLink href="/login" size="md">{login}</ButtonLink>
          </div>
        </div>
      </header>

      <main id="hero" style={{ maxWidth: "none", padding: 0 }}>
        <section className={s.hero}>
          <div className={`${s.container} ${s.heroGrid}`}>
            <div>
              {str(st, "site.hero_badge") && (
                <div className={s.badge}><span className={s.pulse} aria-hidden />{str(st, "site.hero_badge")}</div>
              )}
              <h1 className={s.title}>{emphasize(str(st, "site.hero_title", name))}</h1>
              <p className={s.subtitle}>{str(st, "site.hero_subtitle", str(st, "site.tagline"))}</p>
              <div className={s.ctas}>
                <ButtonLink href="/login" variant="telegram" size="xl" icon={<Send />}>{str(st, "site.cta_primary", login)}</ButtonLink>
                {courses.length > 0 && str(st, "site.cta_secondary") && (
                  <ButtonLink href="#courses" variant="secondary" size="xl">{str(st, "site.cta_secondary")}<ArrowRight aria-hidden /></ButtonLink>
                )}
              </div>
              {stats.length > 0 && (
                <dl className={s.stats}>
                  {stats.map((x) => (
                    <div key={x.label + x.value}>
                      <dt className="sr-only">{x.label}</dt>
                      <dd style={{ margin: 0 }}>
                        <div className={s.statValue}>{x.value}</div>
                        <div className={s.statLabel} aria-hidden>{x.label}</div>
                      </dd>
                    </div>
                  ))}
                </dl>
              )}
            </div>
            <div className={s.visual} aria-hidden>
              {heroImage ? <img src={heroImage} alt="" /> : (
                <>
                  <div className={s.mock}>
                    <div className={s.mockBar}><i /><i /><i /></div>
                    <div className={s.mockStats}>
                      {[0, 1, 2].map((i) => <div className={s.mockStat} key={i}><b /><i /></div>)}
                    </div>
                    <div className={s.mockChart}>
                      {[42, 64, 50, 78, 58, 88, 72].map((h, i) => <span key={i} style={{ height: `${h}%` }} />)}
                    </div>
                    {[0, 1].map((i) => <div className={s.mockRow} key={i}><i /><b /><em /></div>)}
                  </div>
                  <div className={`${s.chip} ${s.chipA}`}><span className={s.chipIcon}><CheckCircle2 /></span><div><b /><b /></div></div>
                  <div className={`${s.chip} ${s.chipB}`}><span className={s.chipIcon}><Send /></span><div><b /><b /></div></div>
                </>
              )}
            </div>
          </div>
        </section>

        {features.length > 0 && (
          <section id="features" className={`${s.section} ${s.sectionAlt}`}>
            <div className={s.container}>
              {(featuresTitle || str(st, "site.features_subtitle")) && (
                <div className={s.sectionHead}>
                  {featuresTitle && <h2>{featuresTitle}</h2>}
                  {str(st, "site.features_subtitle") && <p>{str(st, "site.features_subtitle")}</p>}
                </div>
              )}
              <div className={s.features}>
                {features.map((f, i) => {
                  const Icon = (f.icon && ICONS[f.icon]) || FALLBACK_ICONS[i % FALLBACK_ICONS.length];
                  return (
                    <article className={s.feature} key={f.title}>
                      <div className={s.featureIcon} aria-hidden><Icon /></div>
                      <h3>{f.title}</h3>
                      <p>{f.text}</p>
                    </article>
                  );
                })}
              </div>
            </div>
          </section>
        )}

        {steps.length > 0 && (
          <section className={s.section}>
            <div className={s.container}>
              {str(st, "site.steps_title") && <div className={s.sectionHead}><h2>{str(st, "site.steps_title")}</h2></div>}
              <ol className={s.steps} style={{ listStyle: "none", padding: 0, margin: 0 }}>
                {steps.map((x, i) => (
                  <li className={s.step} key={x.title} style={{ margin: 0 }}>
                    <div className={s.stepNum} aria-hidden>{i + 1}</div>
                    <h3>{x.title}</h3>
                    <p>{x.text}</p>
                  </li>
                ))}
              </ol>
            </div>
          </section>
        )}

        {courses.length > 0 && (
          <section id="courses" className={`${s.section} ${s.sectionAlt}`}>
            <div className={s.container}>
              {(coursesTitle || str(st, "site.courses_subtitle")) && (
                <div className={s.sectionHead}>
                  {coursesTitle && <h2>{coursesTitle}</h2>}
                  {str(st, "site.courses_subtitle") && <p>{str(st, "site.courses_subtitle")}</p>}
                </div>
              )}
              <div className={s.courses}>
                {courses.map((c) => (
                  <article className={s.course} key={c.id}>
                    <div className={s.cover} aria-hidden>{c.cover_url ? <img src={c.cover_url} alt="" /> : <GraduationCap />}</div>
                    <div className={s.courseBody}>
                      <h3>{c.title}</h3>
                      {c.description && <p>{c.description}</p>}
                      <div className={s.courseMeta}>
                        <span>{c.duration_months} oy · {c.modules_count} modul</span>
                        {c.monthly_fee > 0 && <span className={s.price}>{fmtMoney(c.monthly_fee, currency)}</span>}
                      </div>
                    </div>
                  </article>
                ))}
              </div>
            </div>
          </section>
        )}

        {str(st, "site.cta_band_title") && (
          <section className={s.section}>
            <div className={s.container}>
              <div className={s.band}>
                <div className={s.bandText}>
                  <h2>{str(st, "site.cta_band_title")}</h2>
                  {str(st, "site.cta_band_text") && <p>{str(st, "site.cta_band_text")}</p>}
                </div>
                <ButtonLink href="/login" size="xl" className={s.bandBtn} icon={<Send />}>{str(st, "site.cta_primary", login)}</ButtonLink>
              </div>
            </div>
          </section>
        )}

        {(contacts.length > 0 || socials.length > 0) && (
          <section id="contact" className={s.section} style={{ paddingTop: 0 }}>
            <div className={s.container}>
              {(contactTitle || str(st, "site.contact_subtitle")) && (
                <div className={s.sectionHead}>
                  {contactTitle && <h2>{contactTitle}</h2>}
                  {str(st, "site.contact_subtitle") && <p>{str(st, "site.contact_subtitle")}</p>}
                </div>
              )}
              <div className={s.contacts}>
                {contacts.map((c) => {
                  const Icon = c.icon;
                  const inner = <><span className={s.contactIcon} aria-hidden><Icon /></span><span className={s.contactValue}>{c.value}</span></>;
                  return c.href ? (
                    <a key={c.label} href={c.href} className={s.contact} target={c.href.startsWith("http") ? "_blank" : undefined} rel="noopener noreferrer">{inner}</a>
                  ) : <div key={c.label} className={s.contact}>{inner}</div>;
                })}
              </div>
              {socials.length > 0 && (
                <div className={s.socials}>
                  {socials.map((x) => (
                    <ButtonLink key={x.url} href={x.url} variant="secondary" target="_blank" rel="noopener noreferrer">{x.label || x.url}</ButtonLink>
                  ))}
                </div>
              )}
            </div>
          </section>
        )}
      </main>

      <footer className={s.footer}>
        <div className={`${s.container} ${s.footerInner}`}>
          <span>{footer}</span>
          <span className={s.status} title="API">
            <span className={s.dot} style={{ background: ok ? "var(--success)" : "var(--danger)" }} aria-hidden />
            API
          </span>
        </div>
      </footer>
    </div>
  );
}
