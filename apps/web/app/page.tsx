import Link from "next/link";
import type { ReactNode } from "react";
import {
  ArrowRight, Award, BarChart3, BookOpen, CalendarCheck, CheckCircle2, ClipboardCheck, Clock, GraduationCap, Layers, Mail,
  MapPin, MessageCircle, Phone, Send, ShieldCheck, Users, Video, Wallet, type LucideIcon,
} from "lucide-react";
import { API_URL, publicCourses, publicNews, publicSettings } from "@/lib/api";
import { fmtDate } from "@/lib/format";
import { bool, list, obj, siteName, str, timezone } from "@/lib/settings";
import { ButtonLink, SafeImage } from "@/components/ui";
import ThemeToggle from "@/components/ThemeToggle";
import type { Metadata } from "next";
import LeadForm from "./LeadForm";
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

// Faqat bosh sahifa: kanonik manzil va ijtimoiy tarmoq havolasi (ichki sahifalar root layout'dan meros olmasin).
export const metadata: Metadata = { alternates: { canonical: "/" }, openGraph: { url: "/" } };

export default async function Home() {
  const [st, courses, ok, news] = await Promise.all([publicSettings(), publicCourses(), apiStatus(), publicNews()]);
  const name = siteName(st);
  const logo = str(st, "site.logo_url");
  const features = list<{ title: string; text: string; icon?: string }>(st, "site.features").filter((f) => f?.title);
  const steps = list<{ title: string; text: string }>(st, "site.steps").filter((f) => f?.title);
  const stats = list<{ value: string; label: string }>(st, "site.stats").filter((x) => x?.value);
  const socials = list<{ label: string; url: string }>(st, "site.social_links").filter((x) => x?.url);
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
  // Ariza formasi: sozlama o'chirilmagan bo'lsa (API ishlamasa ham forma ko'rinadi — zaxira yo'l bor).
  const leadForm = bool(st, "leads.form_enabled", true);
  const leadTitle = str(st, "leads.form_title", "Bepul sinov darsiga yoziling");
  const login = str(st, "site.nav_login", "Kirish");
  const footer = str(st, "site.footer_text", "© {year} {name}").replace("{year}", String(new Date().getFullYear())).replace("{name}", name);

  // Qidiruv tizimlari uchun tuzilgan ma'lumot (schema.org): markaz va uning kurslari.
  const base = str(st, "site.url", "https://tisu.uzstudents.uz").replace(/\/$/, "");
  const jsonLd = {
    "@context": "https://schema.org",
    "@type": "EducationalOrganization",
    name, url: base, description: str(st, "site.description") || undefined, logo: logo || undefined,
    telephone: phone || undefined, email: email || undefined,
    address: address ? { "@type": "PostalAddress", streetAddress: address, addressCountry: "UZ" } : undefined,
    sameAs: socials.map((x) => x.url),
    hasCourse: courses.map((c) => ({ "@type": "Course", name: c.title, description: c.description || c.title, provider: { "@type": "EducationalOrganization", name } })),
  };

  return (
    <div className={s.page}>
      <script type="application/ld+json" dangerouslySetInnerHTML={{ __html: JSON.stringify(jsonLd).replace(/</g, "\\u003c") }} />
      <a href="#hero" className="skip-link">{name}</a>
      <header className={s.header}>
        <div className={`${s.container} ${s.headerInner}`}>
          <Link href="/" className={s.brand}>
            <span className={s.mark} aria-hidden><SafeImage src={logo} fallback={name.slice(0, 1)} /></span>
            <span>{name}</span>
          </Link>
          <nav className={s.nav} aria-label={name}>
            {features.length > 0 && featuresTitle && <a href="#features">{featuresTitle}</a>}
            {courses.length > 0 && coursesTitle && <a href="#courses">{coursesTitle}</a>}
            {leadForm && <a href="#ariza">{obj<Record<string, string>>(st, "leads.form_labels").nav || "Ariza"}</a>}
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
              <SafeImage src={heroImage} fallback={(
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
              )} />
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
                    <div className={s.cover} aria-hidden><SafeImage src={c.cover_url} fallback={<GraduationCap />} /></div>
                    <div className={s.courseBody}>
                      <h3>{c.title}</h3>
                      {c.description && <p>{c.description}</p>}
                      <div className={s.courseMeta}>
                        <span>{c.duration_months} oy · {c.modules_count} modul</span>
                        {leadForm && <a href={`#ariza?course=${c.id}`} className={s.courseApply}>{obj<Record<string, string>>(st, "leads.form_labels").course_apply || "Yozilish"}<ArrowRight aria-hidden /></a>}
                      </div>
                    </div>
                  </article>
                ))}
              </div>
            </div>
          </section>
        )}

        {news.length > 0 && (
          <section id="news" className={s.section}>
            <div className={s.container}>
              <div className={s.sectionHead}><h2>{str(st, "news.title", "Yangiliklar")}</h2></div>
              <div className={s.news}>
                {news.map((n) => (
                  <article className={s.newsItem} key={n.id}>
                    {n.image_url && <img src={n.image_url} alt="" className={s.newsImg} loading="lazy" />}
                    <div className={s.newsBody}>
                      <time className={s.newsDate} dateTime={n.published_at}>{fmtDate(n.published_at, timezone(st))}</time>
                      <h3>{n.pinned ? "📌 " : ""}{n.title}</h3>
                      <p>{n.body}</p>
                    </div>
                  </article>
                ))}
              </div>
            </div>
          </section>
        )}

        {leadForm && (
          <section id="ariza" className={`${s.section} ${s.sectionAlt}`}>
            <div className={`${s.container} ${s.leadWrap}`}>
              <div className={s.sectionHead}>
                <h2>{leadTitle}</h2>
                {str(st, "leads.form_text") && <p>{str(st, "leads.form_text")}</p>}
              </div>
              <LeadForm
                labels={obj<Record<string, string>>(st, "leads.form_labels")}
                consentText={str(st, "leads.consent_text", "Ma'lumotlarim ariza bo'yicha bog'lanish uchun ishlatilishiga roziman.")}
                consentRequired={bool(st, "leads.consent_required", true)}
                successText={str(st, "leads.success_text", "Rahmat! Arizangiz qabul qilindi.")}
                courses={courses.map((c) => ({ id: c.id, title: c.title }))}
              />
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
                  const inner = <><span className={s.contactIcon} aria-hidden><Icon /></span><span className={s.contactValue}>{c.value.includes("@") && !c.value.startsWith("@") ? <>{c.value.split("@")[0]}@<wbr />{c.value.split("@").slice(1).join("@")}</> : c.value}</span></>;
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
