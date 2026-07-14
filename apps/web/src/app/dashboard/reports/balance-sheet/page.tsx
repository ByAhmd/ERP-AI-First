"use client";

import { useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { ApiClient } from "../../../../lib/api-client";
import { useLanguage } from '../../../../components/LanguageProvider';

export default function BalanceSheetReport() {
  const { t } = useLanguage();
  const [asOfDate, setAsOfDate] = useState("");

  const { data, isLoading, error } = useQuery({
    queryKey: ["balance-sheet", asOfDate],
    queryFn: () => ApiClient.get<any>(`/reports/balance-sheet${asOfDate ? `?asOfDate=${asOfDate}` : ""}`),
  });

  return (
    <div className="animate-fade-in">
      <div className="flex justify-between items-center mb-8">
        <div>
          <h1 className="heading-1 mb-2">{t('reports.balanceSheet')}</h1>
          <p className="text-secondary">{t('reports.balanceSheet.subtitle')}</p>
        </div>
        <div style={{ display: "flex", gap: "1rem", alignItems: "center" }}>
          <div style={{ display: "flex", flexDirection: "column" }}>
            <label className="text-xs text-secondary mb-1">{t('reports.asOf')}</label>
            <input type="date" className="form-input" value={asOfDate} onChange={(e) => setAsOfDate(e.target.value)} />
          </div>
          <button className="btn-primary" style={{ marginTop: "1rem" }} onClick={() => window.print()}>
            {t('common.print')}
          </button>
        </div>
      </div>

      <div className="glass-panel overflow-hidden">
        {isLoading ? (
          <div className="p-12 text-center text-secondary">{t('reports.loading')}</div>
        ) : error ? (
          <div className="p-12 text-center text-error">{t('common.failedLoad')}</div>
        ) : !data ? (
          <div className="p-12 text-center text-secondary">{t('reports.noData')}</div>
        ) : (
          <div style={{ padding: "2rem" }}>
            <h2 style={{ fontSize: "1.25rem", fontWeight: 600, borderBottom: "1px solid rgba(255,255,255,0.1)", paddingBottom: "0.5rem", marginBottom: "1rem", color: "#60a5fa" }}>
              {t('reports.assets')}
            </h2>
            <div style={{ display: "flex", flexDirection: "column", gap: "0.5rem", marginBottom: "2rem" }}>
              {data.assets.length === 0 ? <span className="text-secondary">{t('reports.noData')}</span> : null}
              {data.assets.map((a: any) => (
                <div key={a.id} style={{ display: "flex", justifyContent: "space-between" }}>
                  <span>{a.code} - {a.name}</span>
                  <span style={{ fontFamily: "monospace" }}>{parseFloat(a.balance).toLocaleString("en-SA", { minimumFractionDigits: 2 })}</span>
                </div>
              ))}
              <div style={{ display: "flex", justifyContent: "space-between", fontWeight: "bold", borderTop: "1px dashed rgba(255,255,255,0.2)", paddingTop: "0.5rem", marginTop: "0.5rem" }}>
                <span>{t('reports.totalAssets')}</span>
                <span style={{ fontFamily: "monospace", color: "#60a5fa" }}>{parseFloat(data.totalAssets).toLocaleString("en-SA", { minimumFractionDigits: 2 })}</span>
              </div>
            </div>

            <h2 style={{ fontSize: "1.25rem", fontWeight: 600, borderBottom: "1px solid rgba(255,255,255,0.1)", paddingBottom: "0.5rem", marginBottom: "1rem", color: "#f87171" }}>
              {t('reports.liabilities')}
            </h2>
            <div style={{ display: "flex", flexDirection: "column", gap: "0.5rem", marginBottom: "2rem" }}>
              {data.liabilities.length === 0 ? <span className="text-secondary">{t('reports.noData')}</span> : null}
              {data.liabilities.map((l: any) => (
                <div key={l.id} style={{ display: "flex", justifyContent: "space-between" }}>
                  <span>{l.code} - {l.name}</span>
                  <span style={{ fontFamily: "monospace" }}>{parseFloat(l.balance).toLocaleString("en-SA", { minimumFractionDigits: 2 })}</span>
                </div>
              ))}
              <div style={{ display: "flex", justifyContent: "space-between", fontWeight: "bold", borderTop: "1px dashed rgba(255,255,255,0.2)", paddingTop: "0.5rem", marginTop: "0.5rem" }}>
                <span>{t('reports.totalLiabilities')}</span>
                <span style={{ fontFamily: "monospace", color: "#f87171" }}>{parseFloat(data.totalLiabilities).toLocaleString("en-SA", { minimumFractionDigits: 2 })}</span>
              </div>
            </div>

            <h2 style={{ fontSize: "1.25rem", fontWeight: 600, borderBottom: "1px solid rgba(255,255,255,0.1)", paddingBottom: "0.5rem", marginBottom: "1rem", color: "#a78bfa" }}>
              {t('reports.equity')}
            </h2>
            <div style={{ display: "flex", flexDirection: "column", gap: "0.5rem", marginBottom: "2rem" }}>
              {data.equity.length === 0 ? <span className="text-secondary">{t('reports.noData')}</span> : null}
              {data.equity.map((eq: any) => (
                <div key={eq.id} style={{ display: "flex", justifyContent: "space-between" }}>
                  <span>{eq.code} - {eq.name}</span>
                  <span style={{ fontFamily: "monospace" }}>{parseFloat(eq.balance).toLocaleString("en-SA", { minimumFractionDigits: 2 })}</span>
                </div>
              ))}
              <div style={{ display: "flex", justifyContent: "space-between", fontWeight: "bold", borderTop: "1px dashed rgba(255,255,255,0.2)", paddingTop: "0.5rem", marginTop: "0.5rem" }}>
                <span>{t('reports.totalEquity')}</span>
                <span style={{ fontFamily: "monospace", color: "#a78bfa" }}>{parseFloat(data.totalEquity).toLocaleString("en-SA", { minimumFractionDigits: 2 })}</span>
              </div>
            </div>

            <div style={{ display: "flex", justifyContent: "space-between", fontWeight: "bold", borderTop: "2px solid rgba(255,255,255,0.2)", paddingTop: "1rem", marginTop: "1rem", fontSize: "1.25rem" }}>
              <span>{t('reports.totalLiabilitiesEquity')}</span>
              <span style={{ fontFamily: "monospace", color: (parseFloat(data.totalAssets) === (parseFloat(data.totalLiabilities) + parseFloat(data.totalEquity))) ? "#34d399" : "#f87171" }}>
                SAR {(parseFloat(data.totalLiabilities) + parseFloat(data.totalEquity)).toLocaleString("en-SA", { minimumFractionDigits: 2 })}
              </span>
            </div>
            {parseFloat(data.totalAssets) !== (parseFloat(data.totalLiabilities) + parseFloat(data.totalEquity)) && (
              <div style={{ color: "#f87171", textAlign: "right", fontSize: "0.875rem", marginTop: "0.5rem" }}>
                Warning: Balance Sheet is out of balance by {Math.abs(parseFloat(data.totalAssets) - (parseFloat(data.totalLiabilities) + parseFloat(data.totalEquity))).toFixed(2)}
              </div>
            )}
          </div>
        )}
      </div>
    </div>
  );
}
