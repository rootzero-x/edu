/** @type {import('next').NextConfig} */
const common = [
  { key: "X-Content-Type-Options", value: "nosniff" },
  { key: "Referrer-Policy", value: "strict-origin-when-cross-origin" },
  { key: "Permissions-Policy", value: "camera=(), microphone=(), geolocation=()" },
  // Brauzer saytni 1 yil davomida faqat HTTPS orqali ochadi (HTTP'da brauzerlar bu sarlavhani e'tiborsiz qoldiradi).
  { key: "Strict-Transport-Security", value: "max-age=31536000; includeSubDomains" },
];

const nextConfig = {
  reactStrictMode: true,
  poweredByHeader: false,
  // Qayta ochilgan sahifa 30 s ichida keshdan darhol ko'rsatiladi (jonli ma'lumot polling bilan yangilanadi).
  experimental: { staleTimes: { dynamic: 30, static: 180 } },
  async headers() {
    return [
      // Mini App Telegram Web ichida (iframe) ochiladi — faqat Telegram domenlariga ruxsat.
      {
        source: "/tg/:path*",
        headers: [...common, { key: "Content-Security-Policy", value: "frame-ancestors 'self' https://web.telegram.org https://*.telegram.org; object-src 'none'; base-uri 'self'" }],
      },
      {
        source: "/((?!tg(?:/|$)).*)",
        headers: [...common, { key: "X-Frame-Options", value: "DENY" }, { key: "Content-Security-Policy", value: "frame-ancestors 'none'; object-src 'none'; base-uri 'self'" }],
      },
    ];
  },
};
export default nextConfig;
