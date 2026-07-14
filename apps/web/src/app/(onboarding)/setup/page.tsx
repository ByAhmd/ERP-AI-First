"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { ApiClient } from "../../../lib/api-client";
import { useLanguage } from '../../../components/LanguageProvider';

interface Tenant {
  id: string;
  name: string;
}

export default function SetupPage() {
  const router = useRouter();
  const { t, isRTL } = useLanguage();
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
      setError(err.message || t('setup.error'));
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="min-h-screen flex items-center justify-center p-4" dir={isRTL ? "rtl" : "ltr"}>
      <div className="w-full max-w-md animate-fade-in">
        <div className="glass-panel p-8">
          <div className="text-center mb-8">
            <h1 className="heading-1 mb-2">{t('setup.title')}</h1>
            <p className="text-secondary">{t('setup.subtitle')}</p>
          </div>

          {error && (
            <div className="mb-6 p-4 bg-[rgba(239,68,68,0.1)] text-[#ef4444] border border-[rgba(239,68,68,0.2)] rounded-md text-sm">
              {error}
            </div>
          )}

          <form onSubmit={handleSubmit} className="flex flex-col gap-6">
            <div>
              <label htmlFor="name" className="block text-sm font-medium text-secondary mb-2">
                {t('setup.companyName')} <span className="text-[#ef4444]">*</span>
              </label>
              <input
                id="name"
                name="name"
                type="text"
                required
                className="form-input"
                placeholder={t('setup.companyNamePh')}
              />
            </div>

            <div>
              <label htmlFor="cr" className="block text-sm font-medium text-secondary mb-2">
                {t('setup.crn')}
              </label>
              <input
                id="cr"
                name="cr"
                type="text"
                className="form-input"
                placeholder={t('common.optional')}
              />
            </div>

            <div>
              <label htmlFor="vat" className="block text-sm font-medium text-secondary mb-2">
                {t('setup.vatNo')}
              </label>
              <input
                id="vat"
                name="vat"
                type="text"
                className="form-input"
                placeholder={t('common.optional')}
              />
            </div>

            <button
              type="submit"
              disabled={loading}
              className="btn-primary w-full flex justify-center py-3 mt-2"
            >
              {loading ? t('setup.submitting') : t('setup.submit')}
            </button>
          </form>
        </div>
      </div>
    </div>
  );
}
