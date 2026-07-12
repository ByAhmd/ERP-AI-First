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
      // Step 1: Login (sets httpOnly accessToken + refreshToken cookies)
      const res = await fetch("/api/v1/auth/login", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ email, password }),
      });

      if (!res.ok) {
        const data = await res.json();
        throw new Error(data.message || "Login failed");
      }

      // Step 2: Fetch the list of tenants for this user
      const tenants = await ApiClient.get<Tenant[]>("/tenants");

      if (!tenants || tenants.length === 0) {
        // No tenants yet — redirect to company setup
        router.push("/setup");
        return;
      }

      // Step 3: Auto-select the first (or only) tenant and store in localStorage
      ApiClient.setActiveTenantId(tenants[0].id);

      // Step 4: Go to the dashboard
      router.push("/dashboard");
    } catch (err: any) {
      setError(err.message);
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="min-h-screen flex items-center justify-center p-4">
      <div className="w-full max-w-md animate-fade-in">
        <div className="glass-panel p-8">
          <div className="text-center mb-8">
            <h1 className="heading-1 mb-2">ERP AI</h1>
            <h2 className="heading-2 mb-2">Welcome Back</h2>
            <p className="text-secondary">Sign in to your ERP AI account</p>
          </div>

          {error && (
            <div className="mb-6 p-4 rounded-md bg-[rgba(239,68,68,0.1)] border border-[rgba(239,68,68,0.2)] text-[#ef4444] text-sm">
              {error}
            </div>
          )}

          <form onSubmit={handleLogin} className="flex flex-col gap-5">
            <div>
              <label className="block text-sm font-medium text-secondary mb-2">
                Email Address
              </label>
              <input
                type="email"
                required
                value={email}
                onChange={(e) => setEmail(e.target.value)}
                className="form-input"
                placeholder="admin@erp-ai.local"
              />
            </div>

            <div>
              <label className="block text-sm font-medium text-secondary mb-2">
                Password
              </label>
              <input
                type="password"
                required
                value={password}
                onChange={(e) => setPassword(e.target.value)}
                className="form-input"
                placeholder="••••••••"
              />
            </div>

            <button
              type="submit"
              disabled={loading}
              className="btn-primary w-full py-3 mt-2"
            >
              {loading ? "Signing in..." : "Sign In"}
            </button>
          </form>
        </div>
      </div>
    </div>
  );
}
