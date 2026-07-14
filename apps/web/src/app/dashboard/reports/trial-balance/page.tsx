"use client";

import { useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { ApiClient } from "../../../../lib/api-client";
import { useLanguage } from '../../../../components/LanguageProvider';

export default function TrialBalanceReport() {
  const { t } = useLanguage();
  const [asOfDate, setAsOfDate] = useState("");

  const { data, isLoading, error } = useQuery({
    queryKey: ["trial-balance", asOfDate],
    queryFn: () => ApiClient.get<any>(`/accounting/gl/trial-balance${asOfDate ? `?endDate=${asOfDate}` : ""}`),
  });

  return (
    <div className="animate-fade-in">
      <div className="flex justify-between items-center mb-8">
        <div>
          <h1 className="heading-1 mb-2">{t('reports.trialBalance')}</h1>
          <p className="text-secondary">{t('reports.trialBalance.subtitle')}</p>
        </div>
        <div style={{ display: "flex", gap: "1rem", alignItems: "center" }}>
          <label className="text-sm text-secondary">{t('reports.endDate')}:</label>
          <input
            type="date"
            className="form-input"
            value={asOfDate}
            onChange={(e) => setAsOfDate(e.target.value)}
          />
          <button className="btn-primary" onClick={() => window.print()}>
            {t('common.print')}
          </button>
        </div>
      </div>

      <div className="glass-panel overflow-hidden">
        {isLoading ? (
          <div className="p-12 text-center text-secondary">{t('reports.loading')}</div>
        ) : error ? (
          <div className="p-12 text-center text-error">{t('common.failedLoad')}</div>
        ) : !data || !data.items || data.items.length === 0 ? (
          <div className="p-12 text-center text-secondary">{t('reports.noData')}</div>
        ) : (
          <table className="data-table">
            <thead>
              <tr>
                <th>{t('reports.account')} Code</th>
                <th>{t('reports.account')} Name</th>
                <th>{t('common.type')}</th>
                <th style={{ textAlign: "right" }}>{t('reports.debit')}</th>
                <th style={{ textAlign: "right" }}>{t('reports.credit')}</th>
              </tr>
            </thead>
            <tbody>
              {data.items.map((item: any) => (
                <tr key={item.id}>
                  <td>{item.code}</td>
                  <td>{item.name}</td>
                  <td className="text-secondary">{item.type}</td>
                  <td style={{ textAlign: "right", fontFamily: "monospace" }}>
                    {item.balanceType === "Debit" ? parseFloat(item.balance).toLocaleString("en-SA", { minimumFractionDigits: 2 }) : "-"}
                  </td>
                  <td style={{ textAlign: "right", fontFamily: "monospace" }}>
                    {item.balanceType === "Credit" ? parseFloat(item.balance).toLocaleString("en-SA", { minimumFractionDigits: 2 }) : "-"}
                  </td>
                </tr>
              ))}
              {/* Totals Row */}
              <tr style={{ background: "rgba(255,255,255,0.02)", fontWeight: "bold" }}>
                <td colSpan={3} style={{ textAlign: "right" }}>{t('reports.total')}:</td>
                <td style={{ textAlign: "right", fontFamily: "monospace", color: data.totals.isBalanced ? "#34d399" : "#f87171" }}>
                  {data.totals.debit.toLocaleString("en-SA", { minimumFractionDigits: 2 })}
                </td>
                <td style={{ textAlign: "right", fontFamily: "monospace", color: data.totals.isBalanced ? "#34d399" : "#f87171" }}>
                  {data.totals.credit.toLocaleString("en-SA", { minimumFractionDigits: 2 })}
                </td>
              </tr>
            </tbody>
          </table>
        )}
      </div>
    </div>
  );
}
