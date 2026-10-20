/** @type {import('next').NextConfig} */
const common = [
  { key: "X-Content-Type-Options", value: "nosniff" },
  { key: "Referrer-Policy", value: "strict-origin-when-cross-origin" },
  { key: "Permissions-Policy", value: "camera=(), microphone=(), geolocation=()" },
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
        headers: [...common, { key: "Content-Security-Policy", value: "frame-ancestors 'self' https://web.telegram.org https://*.telegram.org" }],
      },
      {
        source: "/((?!tg(?:/|$)).*)",
        headers: [...common, { key: "X-Frame-Options", value: "DENY" }, { key: "Content-Security-Policy", value: "frame-ancestors 'none'" }],
      },
    ];
  },
};
export default nextConfig;
