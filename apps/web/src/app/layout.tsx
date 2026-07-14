import type { Metadata } from "next";
import { Providers } from "../components/providers";
import { LanguageProvider } from "../components/LanguageProvider";
import "./globals.css";

export const metadata: Metadata = {
  title: "ERP AI | نظام إدارة الموارد",
  description: "Enterprise Accounting & Intelligence Platform | نظام المحاسبة والذكاء المؤسسي",
};

export default function RootLayout({
  children,
}: Readonly<{
  children: React.ReactNode;
}>) {
  return (
    <html lang="en" dir="ltr" suppressHydrationWarning>
      <body>
        <LanguageProvider>
          <Providers>
            {children}
          </Providers>
        </LanguageProvider>
      </body>
    </html>
  );
}
