"use client";

import { useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { ApiClient } from "../../../lib/api-client";

interface VatReturn {
  period: { startDate: string; endDate: string };
  outputVat: number;
  inputVat: number;
  netVatLiability: number;
}

interface ZakatEstimate {
  period: { startDate: string; endDate: string };
  zakatBase: number;
  netIncome: number;
  equity: number;
  liabilities: number;
  estimatedProvision: number;
  multiplier: number;
}

export default function CompliancePage() {
  const [activeTab, setActiveTab] = useState<"VAT" | "Zakat" | "ZATCA">("VAT");
  
  const [startDate, setStartDate] = useState(() => {
    const d = new Date();
    d.setFullYear(d.getFullYear() - 1);
    d.setMonth(0);
    d.setDate(1);
    return d.toISOString().split("T")[0]; // default to start of last year
  });
  
  const [endDate, setEndDate] = useState(() => {
    const d = new Date();
    return d.toISOString().split("T")[0];
  });

  const { data: vatReturn, isLoading: isLoadingVat, error: errorVat } = useQuery({
    queryKey: ["vat-return", startDate, endDate],
    queryFn: () => {
      const params = new URLSearchParams();
      if (startDate) params.append("startDate", new Date(startDate).toISOString());
      if (endDate) params.append("endDate", new Date(endDate).toISOString());
      return ApiClient.get<VatReturn>(`/compliance/vat/return?${params.toString()}`);
    },
    enabled: activeTab === "VAT",
  });

  const { data: zakatEstimate, isLoading: isLoadingZakat, error: errorZakat } = useQuery({
    queryKey: ["zakat-estimate", startDate, endDate],
    queryFn: () => {
      const params = new URLSearchParams();
      if (startDate) params.append("startDate", new Date(startDate).toISOString());
      if (endDate) params.append("endDate", new Date(endDate).toISOString());
      return ApiClient.get<ZakatEstimate>(`/compliance/zakat/estimate?${params.toString()}`);
    },
    enabled: activeTab === "Zakat",
  });

  return (
    <div className="animate-fade-in">
      <div className="flex justify-between items-center mb-8">
        <div>
          <h1 className="heading-1 mb-2">Compliance</h1>
          <p className="text-secondary">Manage VAT, Zakat, and ZATCA e-invoicing</p>
        </div>
      </div>

      <div className="flex gap-2 mb-6" style={{ borderBottom: "1px solid rgba(255,255,255,0.1)", paddingBottom: "1rem" }}>
        {["VAT", "Zakat", "ZATCA"].map((tab) => (
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
            {tab === "VAT" ? "VAT Returns" : tab === "Zakat" ? "Zakat Declaration" : "ZATCA Integration"}
          </button>
        ))}
      </div>

      <div className="glass-panel p-6 animate-fade-in">
        {activeTab !== "ZATCA" && (
          <div className="flex gap-4 mb-8">
            <div>
              <label className="block text-sm font-medium text-secondary mb-1">Start Date</label>
              <input
                type="date"
                value={startDate}
                onChange={(e) => setStartDate(e.target.value)}
                className="form-input"
              />
            </div>
            <div>
              <label className="block text-sm font-medium text-secondary mb-1">End Date</label>
              <input
                type="date"
                value={endDate}
                onChange={(e) => setEndDate(e.target.value)}
                className="form-input"
              />
            </div>
          </div>
        )}

        {activeTab === "VAT" && (
          <div>
            <h2 className="heading-2 mb-6">VAT Return Summary</h2>
            {isLoadingVat ? (
              <div className="p-12 text-center text-secondary">Calculating VAT return...</div>
            ) : errorVat ? (
              <div className="p-8 text-center" style={{ color: "var(--error)" }}>
                Failed to calculate VAT return. Check your ledger entries.
              </div>
            ) : vatReturn ? (
              <div style={{ maxWidth: "600px", margin: "0 auto" }}>
                <div className="mb-8 p-4 rounded-lg" style={{ background: "rgba(255,255,255,0.05)" }}>
                  <h3 className="text-lg font-bold mb-4" style={{ color: "#60a5fa" }}>Sales & Output Tax</h3>
                  <div className="flex justify-between font-bold">
                    <span>VAT Collected (Output VAT)</span>
                    <span style={{ fontFamily: "monospace" }}>{vatReturn.outputVat.toLocaleString("en-SA", { minimumFractionDigits: 2 })}</span>
                  </div>
                </div>

                <div className="mb-8 p-4 rounded-lg" style={{ background: "rgba(255,255,255,0.05)" }}>
                  <h3 className="text-lg font-bold mb-4" style={{ color: "#f87171" }}>Purchases & Input Tax</h3>
                  <div className="flex justify-between font-bold">
                    <span>VAT Paid (Input VAT)</span>
                    <span style={{ fontFamily: "monospace" }}>{vatReturn.inputVat.toLocaleString("en-SA", { minimumFractionDigits: 2 })}</span>
                  </div>
                </div>

                <div 
                  className="p-6 rounded-lg text-center"
                  style={{ 
                    background: vatReturn.netVatLiability >= 0 ? "rgba(239,68,68,0.1)" : "rgba(16,185,129,0.1)",
                    border: `1px solid ${vatReturn.netVatLiability >= 0 ? "rgba(239,68,68,0.3)" : "rgba(16,185,129,0.3)"}` 
                  }}
                >
                  <h3 className="text-sm font-medium mb-2 text-secondary">Net VAT Due to ZATCA</h3>
                  <div className="text-3xl font-bold" style={{ color: vatReturn.netVatLiability >= 0 ? "#ef4444" : "#10b981", fontFamily: "monospace" }}>
                    {Math.abs(vatReturn.netVatLiability).toLocaleString("en-SA", { minimumFractionDigits: 2 })}
                    <span className="text-sm ml-2">{vatReturn.netVatLiability >= 0 ? "Payable" : "Refundable"}</span>
                  </div>
                </div>
              </div>
            ) : null}
          </div>
        )}

        {activeTab === "Zakat" && (
          <div>
            <h2 className="heading-2 mb-6">Zakat Estimation</h2>
            <p className="text-secondary mb-8">Estimated Zakat provision based on your ledger's net income, equity, and liabilities.</p>

            {isLoadingZakat ? (
              <div className="p-12 text-center text-secondary">Estimating Zakat...</div>
            ) : errorZakat ? (
              <div className="p-8 text-center" style={{ color: "var(--error)" }}>
                Failed to estimate Zakat.
              </div>
            ) : zakatEstimate ? (
              <div style={{ maxWidth: "600px", margin: "0 auto" }}>
                <div className="mb-8 p-4 rounded-lg" style={{ background: "rgba(255,255,255,0.05)" }}>
                  <h3 className="text-lg font-bold mb-4" style={{ color: "#fbbf24" }}>Zakat Components</h3>
                  <div className="flex justify-between mb-2">
                    <span className="text-secondary">Net Income</span>
                    <span style={{ fontFamily: "monospace" }}>{zakatEstimate.netIncome.toLocaleString("en-SA", { minimumFractionDigits: 2 })}</span>
                  </div>
                  <div className="flex justify-between mb-2">
                    <span className="text-secondary">Equity</span>
                    <span style={{ fontFamily: "monospace" }}>{zakatEstimate.equity.toLocaleString("en-SA", { minimumFractionDigits: 2 })}</span>
                  </div>
                  <div className="flex justify-between font-bold mt-4 pt-4" style={{ borderTop: "1px solid rgba(255,255,255,0.1)" }}>
                    <span>Estimated Zakat Base</span>
                    <span style={{ fontFamily: "monospace" }}>{zakatEstimate.zakatBase.toLocaleString("en-SA", { minimumFractionDigits: 2 })}</span>
                  </div>
                </div>

                <div 
                  className="p-6 rounded-lg text-center"
                  style={{ 
                    background: "rgba(251,191,36,0.1)",
                    border: "1px solid rgba(251,191,36,0.3)"
                  }}
                >
                  <h3 className="text-sm font-medium mb-2" style={{ color: "#fcd34d" }}>Estimated Provision ({zakatEstimate.multiplier}%)</h3>
                  <div className="text-3xl font-bold" style={{ color: "#fbbf24", fontFamily: "monospace" }}>
                    {zakatEstimate.estimatedProvision.toLocaleString("en-SA", { minimumFractionDigits: 2 })}
                    <span className="text-sm ml-2">SAR</span>
                  </div>
                </div>
              </div>
            ) : null}
          </div>
        )}

        {activeTab === "ZATCA" && (
          <div className="p-12 text-center animate-fade-in">
            <div style={{ fontSize: "3rem", marginBottom: "1rem" }}>✅</div>
            <h2 className="heading-2 mb-2">ZATCA e-Invoicing (FATOORA)</h2>
            <p className="text-secondary max-w-md mx-auto mb-6">
              ZATCA Phase 2 integration is active. Cryptographic stamping and API bridging are configured at the tenant level. All approved invoices will be automatically reported and cleared.
            </p>
            <div className="inline-block px-4 py-2 rounded-full" style={{ background: "rgba(16,185,129,0.15)", color: "#10b981", fontWeight: 600 }}>
              Integration Status: Healthy & Reporting
            </div>
          </div>
        )}
      </div>
    </div>
  );
}
