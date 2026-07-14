"use client";

import { useState, useEffect } from "react";
import { useQuery } from "@tanstack/react-query";
import { ApiClient } from "../../../lib/api-client";
import { useLanguage } from '../../../components/LanguageProvider';

interface Tenant {
  id: string;
  name: string;
  commercialRegNo: string | null;
  vatRegistrationNo: string | null;
  currency: string;
}

export default function SettingsPage() {
  const { t } = useLanguage();
  const [activeTab, setActiveTab] = useState<"Company" | "Financial" | "Integrations" | "Compliance">("Company");

  const tabs = [
    { key: "Company", label: t('settings.company') },
    { key: "Financial", label: t('settings.financial') },
    { key: "Compliance", label: t('settings.compliance') },
    { key: "Integrations", label: t('settings.integrations') },
  ] as const;

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
      alert(t('settings.company.success'));
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
          <h1 className="heading-1 mb-2">{t('settings.title')}</h1>
          <p className="text-secondary">{t('settings.subtitle')}</p>
        </div>
      </div>

      <div className="flex gap-2 mb-6" style={{ borderBottom: "1px solid rgba(255,255,255,0.1)", paddingBottom: "1rem" }}>
        {tabs.map((tab) => (
          <button
            key={tab.key}
            onClick={() => setActiveTab(tab.key as any)}
            style={{
              padding: "0.5rem 1rem",
              borderRadius: "0.375rem",
              fontSize: "0.875rem",
              fontWeight: 600,
              border: "none",
              background: activeTab === tab.key ? "var(--accent-primary)" : "transparent",
              color: activeTab === tab.key ? "#fff" : "var(--text-secondary)",
              cursor: "pointer",
              transition: "all 0.2s",
            }}
          >
            {tab.label}
          </button>
        ))}
      </div>

      <div className="glass-panel p-6 animate-fade-in" style={{ maxWidth: "800px" }}>
        {isLoading && <div className="text-secondary p-4">{t('common.loading')}</div>}
        {!isLoading && activeTab === "Company" && (
          <form onSubmit={handleSave}>
            <h2 className="heading-2 mb-6">{t('settings.company.title')}</h2>
            
            <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr", gap: "1.5rem", marginBottom: "2rem" }}>
              <div>
                <label className="block text-sm font-medium text-secondary mb-1">{t('settings.company.name')}</label>
                <input
                  type="text"
                  value={companySettings.name}
                  onChange={(e) => setCompanySettings({...companySettings, name: e.target.value})}
                  className="form-input"
                  required
                />
              </div>

              <div>
                <label className="block text-sm font-medium text-secondary mb-1">{t('settings.company.currency')}</label>
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
                <label className="block text-sm font-medium text-secondary mb-1">{t('settings.company.vatNo')}</label>
                <input
                  type="text"
                  value={companySettings.vatRegistrationNo}
                  onChange={(e) => setCompanySettings({...companySettings, vatRegistrationNo: e.target.value})}
                  className="form-input"
                />
              </div>

              <div>
                <label className="block text-sm font-medium text-secondary mb-1">{t('settings.company.crn')}</label>
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
                {isSaving ? t('common.saving') : t('common.save')}
              </button>
            </div>
          </form>
        )}

        {!isLoading && activeTab === "Financial" && (
          <div>
            <h2 className="heading-2 mb-6">{t('settings.financial.title')}</h2>
            <p className="text-secondary mb-8">{t('settings.financial.subtitle')}</p>
            
            <div className="mb-6 p-4 rounded-lg" style={{ background: "rgba(255,255,255,0.05)" }}>
              <div className="flex justify-between items-center mb-2">
                <span className="font-bold">{t('settings.financial.fiscalYear')}</span>
                <button className="text-blue-400 text-sm">{t('common.edit')}</button>
              </div>
              <p className="text-sm text-secondary">{t('settings.financial.fiscalYearValue')}</p>
            </div>

            <div className="mb-6 p-4 rounded-lg" style={{ background: "rgba(255,255,255,0.05)" }}>
              <div className="flex justify-between items-center mb-2">
                <span className="font-bold">{t('settings.financial.coa')}</span>
              </div>
              <p className="text-sm text-secondary">{t('settings.financial.coaValue')}</p>
            </div>
          </div>
        )}

        {!isLoading && activeTab === "Compliance" && (
          <div>
            <h2 className="heading-2 mb-6">{t('settings.compliance.title')}</h2>
            <p className="text-secondary mb-8">{t('settings.compliance.subtitle')}</p>
            
            <div className="mb-6 p-4 rounded-lg" style={{ background: "rgba(255,255,255,0.05)" }}>
              <div className="flex justify-between items-center mb-4">
                <span className="font-bold">{t('settings.compliance.vatConfig')}</span>
              </div>
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label className="block text-sm font-medium text-secondary mb-1">{t('settings.compliance.vatRate')}</label>
                  <input type="text" value="15%" disabled className="form-input opacity-50" />
                </div>
                <div>
                  <label className="block text-sm font-medium text-secondary mb-1">{t('settings.compliance.filingFreq')}</label>
                  <select className="form-input" style={{ backgroundColor: "rgba(15,23,42,0.9)" }}>
                    <option>{t('settings.compliance.monthly')}</option>
                    <option>{t('settings.compliance.quarterly')}</option>
                  </select>
                </div>
              </div>
            </div>

            <div className="mb-6 p-4 rounded-lg" style={{ background: "rgba(255,255,255,0.05)" }}>
              <div className="flex justify-between items-center mb-4">
                <span className="font-bold">{t('settings.compliance.zakatConfig')}</span>
              </div>
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label className="block text-sm font-medium text-secondary mb-1">{t('settings.compliance.zakatRate')}</label>
                  <input type="text" value="2.5%" disabled className="form-input opacity-50" />
                </div>
                <div>
                  <label className="block text-sm font-medium text-secondary mb-1">{t('settings.compliance.zakatBasis')}</label>
                  <select className="form-input" style={{ backgroundColor: "rgba(15,23,42,0.9)" }}>
                    <option>{t('settings.compliance.hijri')}</option>
                    <option>{t('settings.compliance.gregorian')}</option>
                  </select>
                </div>
              </div>
              <div className="mt-4">
                <button className="btn-primary" onClick={() => alert(t('settings.company.success'))}>{t('settings.compliance.save')}</button>
              </div>
            </div>
          </div>
        )}

        {!isLoading && activeTab === "Integrations" && (
          <div>
            <h2 className="heading-2 mb-6">{t('settings.integrations.title')}</h2>
            <p className="text-secondary mb-8">{t('settings.integrations.subtitle')}</p>

            <div className="flex items-center justify-between p-4 mb-4 rounded-lg" style={{ background: "rgba(255,255,255,0.05)", border: "1px solid rgba(16,185,129,0.3)" }}>
              <div>
                <h3 className="font-bold">{t('settings.integrations.zatca')}</h3>
                <p className="text-sm text-secondary">{t('settings.integrations.zatcaDesc')}</p>
              </div>
              <span style={{ color: "#10b981", fontWeight: 600, fontSize: "0.875rem" }}>{t('settings.integrations.zatcaStatus')}</span>
            </div>

            <div className="flex items-center justify-between p-4 mb-4 rounded-lg" style={{ background: "rgba(255,255,255,0.05)", border: "1px solid rgba(255,255,255,0.1)" }}>
              <div>
                <h3 className="font-bold">{t('settings.integrations.muqeem')}</h3>
                <p className="text-sm text-secondary">{t('settings.integrations.muqeemDesc')}</p>
              </div>
              <span style={{ color: "var(--text-tertiary)", fontWeight: 600, fontSize: "0.875rem" }}>{t('settings.integrations.inactive')}</span>
            </div>
          </div>
        )}
      </div>
    </div>
  );
}
