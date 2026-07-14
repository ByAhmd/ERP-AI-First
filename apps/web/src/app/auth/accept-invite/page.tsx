"use client";

import { useState, Suspense } from "react";
import { useRouter, useSearchParams } from "next/navigation";
import { Lock, Eye, EyeOff, CheckCircle, AlertCircle } from "lucide-react";
import Link from "next/link";
import { useLanguage } from "../../../components/LanguageProvider";

function AcceptInviteForm() {
  const router = useRouter();
  const searchParams = useSearchParams();
  const token = searchParams.get("token");
  const { t, isRTL } = useLanguage();

  const [password, setPassword] = useState("");
  const [confirmPassword, setConfirmPassword] = useState("");
  const [showPassword, setShowPassword] = useState(false);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [success, setSuccess] = useState(false);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!token) {
      setError(t("auth.acceptInvite.invalidToken"));
      return;
    }
    if (password !== confirmPassword) {
      setError(t("auth.acceptInvite.mismatch"));
      return;
    }
    if (password.length < 8) {
      setError(t("auth.acceptInvite.minLength"));
      return;
    }

    setLoading(true);
    setError(null);

    try {
      const res = await fetch("http://localhost:3001/api/auth/accept-invite", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ token, password }),
      });

      if (!res.ok) {
        const data = await res.json();
        throw new Error(data.message || "Failed to accept invite");
      }

      setSuccess(true);
      setTimeout(() => {
        router.push("/dashboard");
      }, 2000);
    } catch (err: any) {
      setError(err.message);
    } finally {
      setLoading(false);
    }
  };

  if (!token) {
    return (
      <div className="text-center">
        <div className="bg-red-50 text-red-600 p-4 rounded-lg flex items-center justify-center gap-2 mb-6">
          <AlertCircle className="w-5 h-5" />
          <p>{t("auth.acceptInvite.invalidToken")}</p>
        </div>
        <Link href="/login" className="text-blue-600 hover:underline">
          {t("auth.acceptInvite.returnLogin")}
        </Link>
      </div>
    );
  }

  if (success) {
    return (
      <div className="text-center">
        <div className="bg-green-50 text-green-700 p-6 rounded-lg flex flex-col items-center justify-center gap-3">
          <CheckCircle className="w-12 h-12 text-green-500" />
          <h2 className="text-xl font-semibold">{t("auth.acceptInvite.success.title")}</h2>
          <p>{t("auth.acceptInvite.success.message")}</p>
        </div>
      </div>
    );
  }

  return (
    <form onSubmit={handleSubmit} className="space-y-4">
      {error && (
        <div className="p-3 bg-red-50 text-red-600 border border-red-200 rounded text-sm flex items-start gap-2">
          <AlertCircle className="w-4 h-4 mt-0.5 shrink-0" />
          <span>{error}</span>
        </div>
      )}

      <div>
        <label className="block text-sm font-medium text-gray-700 mb-1">
          {t("auth.acceptInvite.newPassword")}
        </label>
        <div className="relative">
          <div className="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-gray-400">
            <Lock className="w-5 h-5" />
          </div>
          <input
            type={showPassword ? "text" : "password"}
            value={password}
            onChange={(e) => setPassword(e.target.value)}
            className="w-full pl-10 pr-10 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-600 focus:border-blue-600"
            placeholder="••••••••"
            required
          />
          <button
            type="button"
            onClick={() => setShowPassword(!showPassword)}
            className="absolute inset-y-0 right-0 pr-3 flex items-center text-gray-400 hover:text-gray-600"
          >
            {showPassword ? <EyeOff className="w-5 h-5" /> : <Eye className="w-5 h-5" />}
          </button>
        </div>
      </div>

      <div>
        <label className="block text-sm font-medium text-gray-700 mb-1">
          {t("auth.acceptInvite.confirmPassword")}
        </label>
        <div className="relative">
          <div className="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-gray-400">
            <Lock className="w-5 h-5" />
          </div>
          <input
            type={showPassword ? "text" : "password"}
            value={confirmPassword}
            onChange={(e) => setConfirmPassword(e.target.value)}
            className="w-full pl-10 pr-10 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-600 focus:border-blue-600"
            placeholder="••••••••"
            required
          />
        </div>
      </div>

      <button
        type="submit"
        disabled={loading}
        className="w-full py-2 px-4 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
      >
        {loading ? t("auth.acceptInvite.submitting") : t("auth.acceptInvite.submit")}
      </button>
    </form>
  );
}

export default function AcceptInvitePage() {
  const { t, isRTL, toggleLanguage, locale } = useLanguage();

  return (
    <div
      className="min-h-screen bg-gray-50 flex flex-col justify-center py-12 sm:px-6 lg:px-8"
      dir={isRTL ? "rtl" : "ltr"}
      style={{ position: "relative" }}
    >
      <div className="sm:mx-auto sm:w-full sm:max-w-md">
        <div className="text-center">
          <div className="w-12 h-12 bg-blue-600 rounded-xl mx-auto flex items-center justify-center shadow-lg">
            <span className="text-white font-bold text-2xl">E</span>
          </div>
          <h2 className="mt-6 text-3xl font-extrabold text-gray-900">
            {t("auth.acceptInvite.title")}
          </h2>
          <p className="mt-2 text-sm text-gray-600">
            {t("auth.acceptInvite.subtitle")}
          </p>
        </div>

        <div className="mt-8 sm:mx-auto sm:w-full sm:max-w-md">
          <div className="bg-white py-8 px-4 shadow-sm border border-gray-100 sm:rounded-xl sm:px-10">
            <Suspense fallback={<div className="text-center text-gray-500">{t("common.loading")}</div>}>
              <AcceptInviteForm />
            </Suspense>
          </div>
        </div>
      </div>
      
      {/* Language Toggle - top right / top left in RTL */}
      <button
        className="lang-toggle"
        onClick={toggleLanguage}
        style={{
          position: "absolute",
          top: "1.5rem",
          [isRTL ? "left" : "right"]: "1.5rem",
          zIndex: 20,
        }}
        title={locale === "en" ? "Switch to Arabic" : "التبديل إلى الإنجليزية"}
      >
        🌐 {locale === "en" ? "العربية" : "English"}
      </button>
    </div>
  );
}
