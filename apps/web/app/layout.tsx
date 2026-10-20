import type { Metadata } from "next";
import "./globals.css";

export const metadata: Metadata = {
  title: "TISU Edu",
  description: "TISU o'quv platformasi: kurslar, testlar, guruhlar va davomat",
};

export default function RootLayout({ children }: { children: React.ReactNode }) {
  return (
    <html lang="uz">
      <body>{children}</body>
    </html>
  );
}
