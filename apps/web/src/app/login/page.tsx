"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { ApiClient } from "../../lib/api-client";

interface Tenant {
  id: string;
  name: string;
}

export default function LoginPage() {
  const router = useRouter();
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState("");

  const handleLogin = async (e: React.FormEvent) => {
    e.preventDefault();
    setLoading(true);
    setError("");

    try {
      const res = await fetch("/api/v1/auth/login", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ email, password }),
      });

      let data;
      try {
        data = await res.json();
      } catch (parseError) {
        throw new Error("Server error: Unable to connect to the backend. Please ensure the API is running.");
      }

      if (!res.ok) {
        throw new Error(data?.message || "Login failed");
      }

      const tenants = await ApiClient.get<Tenant[]>("/tenants");

      if (!tenants || tenants.length === 0) {
        router.push("/setup");
        return;
      }

      ApiClient.setActiveTenantId(tenants[0].id);
      router.push("/dashboard");
    } catch (err: any) {
      setError(err.message);
    } finally {
      setLoading(false);
    }
  };

  return (
    <div style={{ position: "relative", display: "flex", minHeight: "100vh", alignItems: "center", justifyContent: "center", backgroundColor: "#0f172a", padding: "1rem", overflow: "hidden" }}>
      
      {/* Video Background with highly reliable Poster Image */}
      <video
        autoPlay
        loop
        muted
        playsInline
        poster="https://images.unsplash.com/photo-1554224155-8d04cb21cd6c?auto=format&fit=crop&w=1920&q=80"
        style={{ 
          position: "absolute", 
          top: 0, left: 0, right: 0, bottom: 0, 
          width: "100%", height: "100%", 
          objectFit: "cover", 
          zIndex: 0, 
          filter: "brightness(0.35)",
          backgroundColor: "#0f172a" 
        }}
      >
        <source src="/assets/videos/login-bg.mp4" type="video/mp4" />
      </video>

      {/* Login Card */}
      <div className="animate-fade-in" style={{ position: "relative", zIndex: 10, width: "100%", maxWidth: "28rem" }}>
        <div 
          style={{ 
            background: "rgba(15, 23, 42, 0.75)", 
            backdropFilter: "blur(20px)",
            WebkitBackdropFilter: "blur(20px)", 
            border: "1px solid rgba(255, 255, 255, 0.1)", 
            boxShadow: "0 25px 50px -12px rgba(0, 0, 0, 0.5)",
            borderRadius: "1rem",
            padding: "2rem"
          }}
        >
          <div style={{ textAlign: "center", marginBottom: "2rem" }}>
            <h1 style={{ fontSize: "2.25rem", fontWeight: 800, color: "#ffffff", marginBottom: "0.5rem", letterSpacing: "-0.025em" }}>ERP AI</h1>
            <p style={{ fontSize: "0.875rem", fontWeight: 500, color: "#60a5fa", textTransform: "uppercase", letterSpacing: "0.1em", marginBottom: "1.5rem" }}>Corporate OS</p>
            <h2 style={{ fontSize: "1.5rem", fontWeight: 600, color: "#ffffff", marginBottom: "0.5rem" }}>Welcome Back</h2>
            <p style={{ color: "#94a3b8" }}>Sign in to your ERP AI account</p>
          </div>

          {error && (
            <div style={{ marginBottom: "1.5rem", padding: "1rem", borderRadius: "0.5rem", backgroundColor: "rgba(239, 68, 68, 0.2)", border: "1px solid rgba(239, 68, 68, 0.3)", color: "#fecaca", fontSize: "0.875rem", fontWeight: 500, textAlign: "center" }}>
              {error}
            </div>
          )}

          <form onSubmit={handleLogin} style={{ display: "flex", flexDirection: "column", gap: "1.25rem" }}>
            <div>
              <label style={{ display: "block", fontSize: "0.875rem", fontWeight: 500, color: "#cbd5e1", marginBottom: "0.5rem" }}>
                Email Address
              </label>
              <input
                type="email"
                required
                value={email}
                onChange={(e) => setEmail(e.target.value)}
                style={{ width: "100%", borderRadius: "0.5rem", padding: "0.75rem 1rem", color: "#ffffff", backgroundColor: "rgba(0,0,0,0.4)", border: "1px solid rgba(255,255,255,0.1)", outline: "none" }}
                placeholder="admin@erp-ai.local"
              />
            </div>

            <div>
              <label style={{ display: "block", fontSize: "0.875rem", fontWeight: 500, color: "#cbd5e1", marginBottom: "0.5rem" }}>
                Password
              </label>
              <input
                type="password"
                required
                value={password}
                onChange={(e) => setPassword(e.target.value)}
                style={{ width: "100%", borderRadius: "0.5rem", padding: "0.75rem 1rem", color: "#ffffff", backgroundColor: "rgba(0,0,0,0.4)", border: "1px solid rgba(255,255,255,0.1)", outline: "none" }}
                placeholder="••••••••"
              />
            </div>

            <button
              type="submit"
              disabled={loading}
              style={{ width: "100%", borderRadius: "0.5rem", padding: "0.75rem 1rem", marginTop: "1rem", color: "#ffffff", fontWeight: 700, cursor: loading ? "not-allowed" : "pointer", opacity: loading ? 0.5 : 1, background: "linear-gradient(135deg, #2563eb 0%, #4f46e5 100%)", border: "none", boxShadow: "0 4px 14px 0 rgba(79, 70, 229, 0.4)" }}
            >
              {loading ? "Signing in..." : "Sign In"}
            </button>
          </form>
        </div>
      </div>
    </div>
  );
}
