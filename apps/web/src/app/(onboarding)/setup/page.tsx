"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { ApiClient } from "../../../lib/api-client";

interface Tenant {
  id: string;
  name: string;
}

export default function SetupPage() {
  const router = useRouter();
  const [error, setError] = useState("");
  const [loading, setLoading] = useState(false);

  const handleSubmit = async (e: React.FormEvent<HTMLFormElement>) => {
    e.preventDefault();
    setError("");
    setLoading(true);

    const formData = new FormData(e.currentTarget);
    const name = formData.get("name") as string;
    const commercialRegNo = formData.get("cr") as string;
    const vatRegistrationNo = formData.get("vat") as string;

    try {
      const tenant = await ApiClient.post<Tenant>("/tenants", {
        name,
        commercialRegNo: commercialRegNo || undefined,
        vatRegistrationNo: vatRegistrationNo || undefined,
      });

      // Store the new tenant as the active tenant
      ApiClient.setActiveTenantId(tenant.id);

      // Redirect to Chart of Accounts setup (first step of accounting setup)
      router.push("/dashboard/accounting/coa");
    } catch (err: any) {
      setError(err.message || "Failed to create company. Please try again.");
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="min-h-screen flex items-center justify-center p-4">
      <div className="w-full max-w-md animate-fade-in">
        <div className="glass-panel p-8">
          <div className="text-center mb-8">
            <h1 className="heading-1 mb-2">Create Company</h1>
            <p className="text-secondary">Set up your first tenant to get started</p>
          </div>

          {error && (
            <div className="mb-6 p-4 bg-[rgba(239,68,68,0.1)] text-[#ef4444] border border-[rgba(239,68,68,0.2)] rounded-md text-sm">
              {error}
            </div>
          )}

          <form onSubmit={handleSubmit} className="flex flex-col gap-6">
            <div>
              <label htmlFor="name" className="block text-sm font-medium text-secondary mb-2">
                Company Name <span className="text-[#ef4444]">*</span>
              </label>
              <input
                id="name"
                name="name"
                type="text"
                required
                className="form-input"
                placeholder="e.g. Acme Corp"
              />
            </div>

            <div>
              <label htmlFor="cr" className="block text-sm font-medium text-secondary mb-2">
                Commercial Registration No.
              </label>
              <input
                id="cr"
                name="cr"
                type="text"
                className="form-input"
                placeholder="Optional"
              />
            </div>

            <div>
              <label htmlFor="vat" className="block text-sm font-medium text-secondary mb-2">
                VAT Registration No.
              </label>
              <input
                id="vat"
                name="vat"
                type="text"
                className="form-input"
                placeholder="Optional"
              />
            </div>

            <button
              type="submit"
              disabled={loading}
              className="btn-primary w-full flex justify-center py-3 mt-2"
            >
              {loading ? "Creating..." : "Create Company"}
            </button>
          </form>
        </div>
      </div>
    </div>
  );
}
