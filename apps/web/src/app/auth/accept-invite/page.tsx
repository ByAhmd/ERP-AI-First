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
      setError(t("auth.acceptInvite.invalidToken") || "Invalid token");
      return;
    }
    if (password !== confirmPassword) {
      setError(t("auth.acceptInvite.mismatch") || "Passwords do not match");
      return;
    }
    if (password.length < 8) {
      setError(t("auth.acceptInvite.minLength") || "Password must be at least 8 characters");
      return;
    }

    setLoading(true);
    setError(null);

    try {
      const res = await fetch("/api/v1/auth/accept-invite", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ token, password }),
      });

      if (!res.ok) {
        const data = await res.json();
        let errorMessage = data.message || "Failed to accept invite";
        if (Array.isArray(data.message)) {
          errorMessage = data.message.join(", ");
        } else if (typeof data.message === "object") {
          errorMessage = JSON.stringify(data.message);
        } else if (data.error && typeof data.message === "undefined") {
          errorMessage = data.error;
        }
        throw new Error(errorMessage);
      }

      setSuccess(true);
      setTimeout(() => {
        router.push("/workspaces");
      }, 2000);
    } catch (err: any) {
      setError(err.message);
    } finally {
      setLoading(false);
    }
  };

  if (!token) {
    return (
      <div style={{ textAlign: 'center' }}>
        <div style={{ 
          backgroundColor: 'rgba(239, 68, 68, 0.1)', 
          color: 'var(--error)', 
          padding: '1rem', 
          borderRadius: 'var(--radius-md)', 
          display: 'flex', 
          alignItems: 'center', 
          justifyContent: 'center', 
          gap: '0.5rem', 
          marginBottom: '1.5rem',
          border: '1px solid rgba(239, 68, 68, 0.2)'
        }}>
          <AlertCircle style={{ width: '1.25rem', height: '1.25rem' }} />
          <p>{t("auth.acceptInvite.invalidToken") || "Invalid or missing invitation link."}</p>
        </div>
        <Link href="/login" style={{ color: 'var(--accent-primary)', textDecoration: 'none' }}>
          {t("auth.acceptInvite.returnLogin") || "Return to Login"}
        </Link>
      </div>
    );
  }

  if (success) {
    return (
      <div style={{ textAlign: 'center' }}>
        <div style={{ 
          backgroundColor: 'rgba(34, 197, 94, 0.1)', 
          color: 'var(--success)', 
          padding: '1.5rem', 
          borderRadius: 'var(--radius-md)', 
          display: 'flex', 
          flexDirection: 'column',
          alignItems: 'center', 
          justifyContent: 'center', 
          gap: '0.75rem',
          border: '1px solid rgba(34, 197, 94, 0.2)'
        }}>
          <CheckCircle style={{ width: '3rem', height: '3rem' }} />
          <h2 style={{ fontSize: '1.25rem', fontWeight: 600 }}>{t("auth.acceptInvite.success.title") || "Success!"}</h2>
          <p>{t("auth.acceptInvite.success.message") || "Your account is activated."}</p>
        </div>
      </div>
    );
  }

  return (
    <form onSubmit={handleSubmit} style={{ display: 'flex', flexDirection: 'column', gap: '1rem' }}>
      {error && (
        <div style={{ 
          padding: '0.75rem', 
          backgroundColor: 'rgba(239, 68, 68, 0.1)', 
          color: 'var(--error)', 
          border: '1px solid rgba(239, 68, 68, 0.2)', 
          borderRadius: 'var(--radius-sm)', 
          fontSize: '0.875rem', 
          display: 'flex', 
          alignItems: 'flex-start', 
          gap: '0.5rem' 
        }}>
          <AlertCircle style={{ width: '1rem', height: '1rem', marginTop: '0.125rem', flexShrink: 0 }} />
          <span>{error}</span>
        </div>
      )}

      <div className="form-group">
        <label>{t("auth.acceptInvite.newPassword") || "New Password"}</label>
        <div style={{ position: 'relative' }}>
          <div style={{ position: 'absolute', insetBlock: 0, left: 0, paddingLeft: '0.75rem', display: 'flex', alignItems: 'center', pointerEvents: 'none', color: 'var(--text-tertiary)' }}>
            <Lock style={{ width: '1.25rem', height: '1.25rem' }} />
          </div>
          <input
            type={showPassword ? "text" : "password"}
            value={password}
            onChange={(e) => setPassword(e.target.value)}
            className="form-input"
            style={{ paddingLeft: '2.5rem', paddingRight: '2.5rem' }}
            placeholder="••••••••"
            required
          />
          <button
            type="button"
            onClick={() => setShowPassword(!showPassword)}
            style={{ position: 'absolute', insetBlock: 0, right: 0, paddingRight: '0.75rem', display: 'flex', alignItems: 'center', color: 'var(--text-tertiary)', background: 'none', border: 'none', cursor: 'pointer' }}
          >
            {showPassword ? <EyeOff style={{ width: '1.25rem', height: '1.25rem' }} /> : <Eye style={{ width: '1.25rem', height: '1.25rem' }} />}
          </button>
        </div>
      </div>

      <div className="form-group">
        <label>{t("auth.acceptInvite.confirmPassword") || "Confirm Password"}</label>
        <div style={{ position: 'relative' }}>
          <div style={{ position: 'absolute', insetBlock: 0, left: 0, paddingLeft: '0.75rem', display: 'flex', alignItems: 'center', pointerEvents: 'none', color: 'var(--text-tertiary)' }}>
            <Lock style={{ width: '1.25rem', height: '1.25rem' }} />
          </div>
          <input
            type={showPassword ? "text" : "password"}
            value={confirmPassword}
            onChange={(e) => setConfirmPassword(e.target.value)}
            className="form-input"
            style={{ paddingLeft: '2.5rem', paddingRight: '2.5rem' }}
            placeholder="••••••••"
            required
          />
        </div>
      </div>

      <button
        type="submit"
        disabled={loading}
        className="btn-primary"
        style={{ width: '100%', marginTop: '0.5rem' }}
      >
        {loading ? (t("auth.acceptInvite.submitting") || "Setting up...") : (t("auth.acceptInvite.submit") || "Activate Account")}
      </button>
    </form>
  );
}

export default function AcceptInvitePage() {
  const { t, isRTL, toggleLanguage, locale } = useLanguage();

  return (
    <div
      dir={isRTL ? "rtl" : "ltr"}
      style={{
        minHeight: "100vh",
        backgroundColor: "var(--bg-primary)",
        color: "var(--text-primary)",
        display: "flex",
        flexDirection: "column",
        justifyContent: "center",
        alignItems: "center",
        padding: "3rem 1rem",
        position: "relative"
      }}
    >
      <div style={{ width: "100%", maxWidth: "400px" }}>
        <div style={{ textAlign: "center", marginBottom: "2rem" }}>
          <div style={{
            width: "3rem",
            height: "3rem",
            backgroundColor: "var(--accent-primary)",
            borderRadius: "var(--radius-md)",
            margin: "0 auto 1.5rem auto",
            display: "flex",
            alignItems: "center",
            justifyContent: "center",
            boxShadow: "0 4px 12px rgba(99, 102, 241, 0.3)"
          }}>
            <span style={{ color: "white", fontWeight: "bold", fontSize: "1.5rem" }}>E</span>
          </div>
          <h2 className="heading-1" style={{ marginBottom: "0.5rem" }}>
            {t("auth.acceptInvite.title") || "Welcome to ERP AI"}
          </h2>
          <p style={{ color: "var(--text-secondary)", fontSize: "0.875rem" }}>
            {t("auth.acceptInvite.subtitle") || "Set your password to activate your account"}
          </p>
        </div>

        <div className="glass-panel" style={{ padding: "2rem" }}>
          <Suspense fallback={<div style={{ textAlign: "center", color: "var(--text-tertiary)" }}>{t("common.loading") || "Loading..."}</div>}>
            <AcceptInviteForm />
          </Suspense>
        </div>
      </div>
      
      {/* Language Toggle */}
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
