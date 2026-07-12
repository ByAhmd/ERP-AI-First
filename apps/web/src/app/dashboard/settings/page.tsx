"use client";

import { useState, useEffect } from "react";
import { useQuery } from "@tanstack/react-query";
import { ApiClient } from "../../../lib/api-client";

interface Tenant {
  id: string;
  name: string;
  commercialRegNo: string | null;
  vatRegistrationNo: string | null;
  currency: string;
}

export default function SettingsPage() {
  const [activeTab, setActiveTab] = useState<"Company" | "Financial" | "Integrations">("Company");

  const { data: tenants, isLoading } = useQuery({
    queryKey: ["tenants"],
    queryFn: () => ApiClient.get<Tenant[]>("/tenants"),
  });

  const activeTenantId = ApiClient.getActiveTenantId();
  const activeTenant = tenants?.find((t) => t.id === activeTenantId);

  const [companySettings, setCompanySettings] = useState({
    name: "",
    vatRegistrationNo: "",
    commercialRegNo: "",
    currency: "SAR",
  });

  const [isSaving, setIsSaving] = useState(false);

  useEffect(() => {
    if (activeTenant) {
      setCompanySettings({
        name: activeTenant.name || "",
        vatRegistrationNo: activeTenant.vatRegistrationNo || "",
        commercialRegNo: activeTenant.commercialRegNo || "",
        currency: activeTenant.currency || "SAR",
      });
    }
  }, [activeTenant]);

  const handleSave = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!activeTenantId) return;
    try {
      setIsSaving(true);
      await ApiClient.patch(`/tenants/${activeTenantId}`, companySettings);
      alert("Settings saved successfully. Changes applied.");
      window.location.reload(); // Refresh to update layout headers
    } catch (err: any) {
      alert(err.message || "Failed to save settings");
    } finally {
      setIsSaving(false);
    }
  };

  return (
    <div className="animate-fade-in">
      <div className="flex justify-between items-center mb-8">
        <div>
          <h1 className="heading-1 mb-2">Settings</h1>
          <p className="text-secondary">Manage company profile, defaults, and integrations</p>
        </div>
      </div>

      <div className="flex gap-2 mb-6" style={{ borderBottom: "1px solid rgba(255,255,255,0.1)", paddingBottom: "1rem" }}>
        {["Company", "Financial", "Integrations"].map((tab) => (
          <button
            key={tab}
            onClick={() => setActiveTab(tab as any)}
            style={{
              padding: "0.5rem 1rem",
              borderRadius: "0.375rem",
              fontSize: "0.875rem",
              fontWeight: 600,
              border: "none",
              background: activeTab === tab ? "var(--accent-primary)" : "transparent",
              color: activeTab === tab ? "#fff" : "var(--text-secondary)",
              cursor: "pointer",
              transition: "all 0.2s",
            }}
          >
            {tab} Profile
          </button>
        ))}
      </div>

      <div className="glass-panel p-6 animate-fade-in" style={{ maxWidth: "800px" }}>
        {isLoading && <div className="text-secondary p-4">Loading settings...</div>}
        {!isLoading && activeTab === "Company" && (
          <form onSubmit={handleSave}>
            <h2 className="heading-2 mb-6">Company Information</h2>
            
            <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr", gap: "1.5rem", marginBottom: "2rem" }}>
              <div>
                <label className="block text-sm font-medium text-secondary mb-1">Company Name</label>
                <input
                  type="text"
                  value={companySettings.name}
                  onChange={(e) => setCompanySettings({...companySettings, name: e.target.value})}
                  className="form-input"
                  required
                />
              </div>

              <div>
                <label className="block text-sm font-medium text-secondary mb-1">Base Currency</label>
                <select
                  value={companySettings.currency}
                  onChange={(e) => setCompanySettings({...companySettings, currency: e.target.value})}
                  className="form-input"
                  style={{ backgroundColor: "rgba(15,23,42,0.9)" }}
                >
                  <option value="SAR">Saudi Riyal (SAR)</option>
                  <option value="USD">US Dollar (USD)</option>
                </select>
              </div>

              <div>
                <label className="block text-sm font-medium text-secondary mb-1">VAT Number (Tax ID)</label>
                <input
                  type="text"
                  value={companySettings.vatRegistrationNo}
                  onChange={(e) => setCompanySettings({...companySettings, vatRegistrationNo: e.target.value})}
                  className="form-input"
                />
              </div>

              <div>
                <label className="block text-sm font-medium text-secondary mb-1">Commercial Registration No.</label>
                <input
                  type="text"
                  value={companySettings.commercialRegNo}
                  onChange={(e) => setCompanySettings({...companySettings, commercialRegNo: e.target.value})}
                  className="form-input"
                />
              </div>
            </div>

            <div className="flex justify-end">
              <button type="submit" className="btn-primary" disabled={isSaving}>
                {isSaving ? "Saving..." : "Save Changes"}
              </button>
            </div>
          </form>
        )}

        {!isLoading && activeTab === "Financial" && (
          <div>
            <h2 className="heading-2 mb-6">Financial & Accounting Settings</h2>
            <p className="text-secondary mb-8">System defaults configured globally for this company.</p>
            
            <div className="mb-6 p-4 rounded-lg" style={{ background: "rgba(255,255,255,0.05)" }}>
              <div className="flex justify-between items-center mb-2">
                <span className="font-bold">Fiscal Year</span>
              </div>
              <p className="text-sm text-secondary">January 1 to December 31</p>
            </div>

            <div className="mb-6 p-4 rounded-lg" style={{ background: "rgba(255,255,255,0.05)" }}>
              <div className="flex justify-between items-center mb-2">
                <span className="font-bold">Default Tax Rate</span>
              </div>
              <p className="text-sm text-secondary">15% Standard Rate (KSA VAT)</p>
            </div>
          </div>
        )}

        {!isLoading && activeTab === "Integrations" && (
          <div>
            <h2 className="heading-2 mb-6">Connected Integrations</h2>
            <p className="text-secondary mb-8">Manage third-party connections and API statuses.</p>

            <div className="flex items-center justify-between p-4 mb-4 rounded-lg" style={{ background: "rgba(255,255,255,0.05)", border: "1px solid rgba(16,185,129,0.3)" }}>
              <div>
                <h3 className="font-bold">ZATCA e-Invoicing (FATOORA)</h3>
                <p className="text-sm text-secondary">Phase 2 B2B/B2C Clearance and Reporting</p>
              </div>
              <span style={{ color: "#10b981", fontWeight: 600, fontSize: "0.875rem" }}>✓ Active (Background Push)</span>
            </div>

            <div className="flex items-center justify-between p-4 mb-4 rounded-lg" style={{ background: "rgba(255,255,255,0.05)", border: "1px solid rgba(255,255,255,0.1)" }}>
              <div>
                <h3 className="font-bold">Muqeem API</h3>
                <p className="text-sm text-secondary">Employee profile and residency sync</p>
              </div>
              <span style={{ color: "var(--text-tertiary)", fontWeight: 600, fontSize: "0.875rem" }}>Inactive</span>
            </div>
          </div>
        )}
      </div>
    </div>
  );
}
