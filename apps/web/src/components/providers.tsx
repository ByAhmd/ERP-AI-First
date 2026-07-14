"use client";

import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { useState } from "react";
import { Toaster } from "react-hot-toast";
import { useLanguage } from "./LanguageProvider";

function ToasterWithRTL() {
  const { isRTL } = useLanguage();
  return (
    <Toaster
      position={isRTL ? "top-left" : "top-right"}
      toastOptions={{
        style: {
          background: "rgba(15, 23, 42, 0.95)",
          color: "#fff",
          border: "1px solid var(--glass-border)",
          backdropFilter: "blur(12px)",
          direction: isRTL ? "rtl" : "ltr",
          fontFamily: isRTL ? "'Noto Kufi Arabic', sans-serif" : "'Outfit', sans-serif",
        },
      }}
    />
  );
}

export function Providers({ children }: { children: React.ReactNode }) {
  const [queryClient] = useState(
    () =>
      new QueryClient({
        defaultOptions: {
          queries: {
            staleTime: 60 * 1000, // 1 minute
            refetchOnWindowFocus: false,
          },
        },
      })
  );

  return (
    <QueryClientProvider client={queryClient}>
      <ToasterWithRTL />
      {children}
    </QueryClientProvider>
  );
}
