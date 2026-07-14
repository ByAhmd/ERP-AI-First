"use client";

import { useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { ApiClient } from "../../../lib/api-client";
import { useLanguage } from "../../../components/LanguageProvider";

interface TrialBalanceEntry {
  id: string;
  code: string;
  name: string;
  type: string;
  totalDebit: string;
  totalCredit: string;
  balance: string;
}

interface TrialBalanceResponse {
  items: TrialBalanceEntry[];
  totals: {
    debit: string;
    credit: string;
  };
  isBalanced: boolean;
}

export default function GeneralLedgerPage() {
  const { t } = useLanguage();
  const [filterType, setFilterType] = useState<string>("all");
  const [startDate, setStartDate] = useState<string>("");
  const [endDate, setEndDate] = useState<string>("");

  const { data: response, isLoading, error } = useQuery({
    queryKey: ["trial-balance", startDate, endDate],
    queryFn: () => {
      const params = new URLSearchParams();
      if (startDate) params.append("startDate", new Date(startDate).toISOString());
      if (endDate) params.append("endDate", new Date(endDate).toISOString());
      const queryStr = params.toString() ? `?${params.toString()}` : "";
      return ApiClient.get<TrialBalanceResponse>(`/accounting/gl/trial-balance${queryStr}`);
    },
  });

  const { data: transactions, isLoading: loadingTx } = useQuery({
    queryKey: ["gl-transactions"],
    queryFn: () => ApiClient.get<any[]>("/accounting/gl/transactions"),
  });

  const entries = response?.items ?? [];
  const totals = response?.totals;
  const isBalanced = response?.isBalanced ?? true;

  const filtered = entries.filter(
    (e) => filterType === "all" || e.type === filterType
  );

  const accountTypes = ["all", "Asset", "Liability", "Equity", "Revenue", "Expense"];

  const [activeTab, setActiveTab] = useState<"TrialBalance" | "Transactions">("TrialBalance");

  return (
    <div className="animate-fade-in">
      <div className="flex justify-between items-center mb-8">
        <div>
          <h1 className="heading-1 mb-2">{t('ledger.title')}</h1>
          <p className="text-secondary">{t('ledger.subtitle')}</p>
        </div>
        {activeTab === "TrialBalance" && response && (
          <div
            style={{
              padding: "0.5rem 1rem",
              borderRadius: "0.5rem",
              background: isBalanced ? "rgba(16,185,129,0.1)" : "rgba(239,68,68,0.1)",
              border: `1px solid ${isBalanced ? "rgba(16,185,129,0.3)" : "rgba(239,68,68,0.3)"}`,
              color: isBalanced ? "#10b981" : "#ef4444",
              fontSize: "0.875rem",
              fontWeight: 600,
            }}
          >
            {isBalanced ? "✓ Balanced" : "✗ Out of Balance"}
          </div>
        )}
      </div>

      <div className="flex gap-2 mb-6" style={{ borderBottom: "1px solid rgba(255,255,255,0.1)", paddingBottom: "1rem" }}>
        {["TrialBalance", "Transactions"].map((tab) => (
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
            {tab === "TrialBalance" ? "Trial Balance" : "All Transactions"}
          </button>
        ))}
      </div>

      {activeTab === "TrialBalance" && (
        <>
          <div className="glass-panel p-4 mb-6 flex gap-4 items-end" style={{ flexWrap: "wrap" }}>
            <div>
              <label className="block text-sm font-medium text-secondary mb-1">{t('reports.startDate')}</label>
              <input
                type="date"
                value={startDate}
                onChange={(e) => setStartDate(e.target.value)}
                className="form-input"
                style={{ minWidth: "150px" }}
              />
            </div>
            <div>
              <label className="block text-sm font-medium text-secondary mb-1">{t('reports.endDate')}</label>
              <input
                type="date"
                value={endDate}
                onChange={(e) => setEndDate(e.target.value)}
                className="form-input"
                style={{ minWidth: "150px" }}
              />
            </div>
            <div style={{ flexGrow: 1 }} />
            <div className="flex gap-2" style={{ flexWrap: "wrap" }}>
              {accountTypes.map((type) => (
                <button
                  key={type}
                  onClick={() => setFilterType(type)}
                  style={{
                    padding: "0.375rem 0.875rem",
                    borderRadius: "9999px",
                    fontSize: "0.875rem",
                    fontWeight: 500,
                    border: "1px solid",
                    borderColor: filterType === type ? "var(--accent-primary)" : "var(--glass-border)",
                    background: filterType === type ? "rgba(59,130,246,0.15)" : "transparent",
                    color: filterType === type ? "var(--accent-primary)" : "var(--text-secondary)",
                    cursor: "pointer",
                    transition: "all 0.2s",
                  }}
                >
                  {type === "all" ? t('common.all') : type}
                </button>
              ))}
            </div>
          </div>

          <div className="glass-panel overflow-hidden">
            {isLoading ? (
              <div className="p-12 text-center text-secondary">{t('ledger.loading')}</div>
            ) : error ? (
              <div className="p-12 text-center" style={{ color: "var(--error)" }}>
                <h3 className="heading-2 mb-2">{t('common.failedLoad')}</h3>
                <p className="text-secondary">{t('ledger.noData')}</p>
              </div>
            ) : filtered.length === 0 ? (
              <div className="p-12 text-center">
                <h3 className="heading-2 mb-2">{t('ledger.noData')}</h3>
                <p className="text-secondary max-w-sm" style={{ margin: "0 auto" }}>
                  {t('ledger.selectAccount')}
                </p>
              </div>
            ) : (
              <table className="data-table">
                <thead>
                  <tr>
                    <th>Account Code</th>
                    <th>Account Name</th>
                    <th>{t('common.type')}</th>
                    <th style={{ textAlign: "right" }}>{t('ledger.debit')}</th>
                    <th style={{ textAlign: "right" }}>{t('ledger.credit')}</th>
                    <th style={{ textAlign: "right" }}>{t('ledger.balance')}</th>
                  </tr>
                </thead>
                <tbody>
                  {filtered.map((entry) => {
                    const debit = parseFloat(entry.totalDebit);
                    const credit = parseFloat(entry.totalCredit);
                    const balance = parseFloat(entry.balance);

                    return (
                      <tr key={entry.id}>
                        <td style={{ fontFamily: "monospace", fontWeight: 600 }}>{entry.code}</td>
                        <td>{entry.name}</td>
                        <td>
                          <span
                            style={{
                              padding: "0.2rem 0.6rem",
                              borderRadius: "0.25rem",
                              fontSize: "0.75rem",
                              fontWeight: 600,
                              background:
                                entry.type === "Asset" ? "rgba(59,130,246,0.15)" :
                                entry.type === "Liability" ? "rgba(239,68,68,0.15)" :
                                entry.type === "Equity" ? "rgba(139,92,246,0.15)" :
                                entry.type === "Revenue" ? "rgba(16,185,129,0.15)" :
                                "rgba(245,158,11,0.15)",
                              color:
                                entry.type === "Asset" ? "#60a5fa" :
                                entry.type === "Liability" ? "#f87171" :
                                entry.type === "Equity" ? "#a78bfa" :
                                entry.type === "Revenue" ? "#34d399" :
                                "#fbbf24",
                            }}
                          >
                            {entry.type}
                          </span>
                        </td>
                        <td style={{ textAlign: "right", fontFamily: "monospace" }}>
                          {debit > 0 ? debit.toLocaleString("en-SA", { minimumFractionDigits: 2 }) : "—"}
                        </td>
                        <td style={{ textAlign: "right", fontFamily: "monospace" }}>
                          {credit > 0 ? credit.toLocaleString("en-SA", { minimumFractionDigits: 2 }) : "—"}
                        </td>
                        <td
                          style={{
                            textAlign: "right",
                            fontFamily: "monospace",
                            fontWeight: 600,
                            color: balance >= 0 ? "var(--text-primary)" : "var(--error)",
                          }}
                        >
                          {Math.abs(balance).toLocaleString("en-SA", { minimumFractionDigits: 2 })}
                          {balance < 0 ? " Cr" : " Dr"}
                        </td>
                      </tr>
                    );
                  })}
                </tbody>
                {totals && filterType === "all" && (
                  <tfoot>
                    <tr style={{ background: "rgba(255,255,255,0.04)", fontWeight: 700 }}>
                      <td colSpan={3} style={{ padding: "1rem 1.5rem" }}>{t('common.total')}</td>
                      <td style={{ textAlign: "right", padding: "1rem 1.5rem", fontFamily: "monospace" }}>
                        {parseFloat(totals.debit).toLocaleString("en-SA", { minimumFractionDigits: 2 })}
                      </td>
                      <td style={{ textAlign: "right", padding: "1rem 1.5rem", fontFamily: "monospace" }}>
                        {parseFloat(totals.credit).toLocaleString("en-SA", { minimumFractionDigits: 2 })}
                      </td>
                      <td style={{ textAlign: "right", padding: "1rem 1.5rem" }}></td>
                    </tr>
                  </tfoot>
                )}
              </table>
            )}
          </div>
        </>
      )}

      {activeTab === "Transactions" && (
        <div className="glass-panel overflow-hidden">
          {loadingTx ? (
            <div className="p-12 text-center text-secondary">{t('ledger.loading')}</div>
          ) : !transactions || transactions.length === 0 ? (
            <div className="p-12 text-center">
              <h3 className="heading-2 mb-2">{t('ledger.noData')}</h3>
              <p className="text-secondary">{t('ledger.selectAccount')}</p>
            </div>
          ) : (
            <table className="data-table">
              <thead>
                <tr>
                  <th>{t('ledger.date')}</th>
                  <th>{t('ledger.reference')}</th>
                  <th>Account</th>
                  <th>{t('ledger.description')}</th>
                  <th style={{ textAlign: "right" }}>{t('ledger.debit')}</th>
                  <th style={{ textAlign: "right" }}>{t('ledger.credit')}</th>
                </tr>
              </thead>
              <tbody>
                {transactions.map((tx: any) => {
                  const debit = parseFloat(tx.debit);
                  const credit = parseFloat(tx.credit);
                  return (
                    <tr key={tx.id}>
                      <td className="text-secondary">
                        {new Date(tx.journalEntry?.entryDate).toLocaleDateString("en-SA")}
                      </td>
                      <td style={{ fontWeight: 600 }}>{tx.journalEntry?.entryNumber}</td>
                      <td>
                        {tx.account?.code} - {tx.account?.name}
                      </td>
                      <td className="text-secondary">{tx.description || tx.journalEntry?.description}</td>
                      <td style={{ textAlign: "right", fontFamily: "monospace" }}>
                        {debit > 0 ? debit.toLocaleString("en-SA", { minimumFractionDigits: 2 }) : ""}
                      </td>
                      <td style={{ textAlign: "right", fontFamily: "monospace" }}>
                        {credit > 0 ? credit.toLocaleString("en-SA", { minimumFractionDigits: 2 }) : ""}
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          )}
        </div>
      )}
    </div>
  );
}
