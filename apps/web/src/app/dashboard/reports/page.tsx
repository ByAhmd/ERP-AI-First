"use client";

import { useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { ApiClient } from "../../../lib/api-client";

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

export default function ReportsPage() {
  const [activeTab, setActiveTab] = useState<"TB" | "PNL" | "BS">("TB");
  const [startDate, setStartDate] = useState<string>("");
  const [endDate, setEndDate] = useState<string>("");

  const { data: response, isLoading, error } = useQuery({
    queryKey: ["reports", "trial-balance", startDate, endDate],
    queryFn: () => {
      const params = new URLSearchParams();
      if (startDate) params.append("startDate", new Date(startDate).toISOString());
      if (endDate) params.append("endDate", new Date(endDate).toISOString());
      const queryStr = params.toString() ? `?${params.toString()}` : "";
      return ApiClient.get<TrialBalanceResponse>(`/accounting/gl/trial-balance${queryStr}`);
    },
  });

  const entries = response?.items ?? [];

  // P&L Calculations
  const revenueAccounts = entries.filter((e) => e.type === "Revenue");
  const expenseAccounts = entries.filter((e) => e.type === "Expense");
  
  const totalRevenue = revenueAccounts.reduce((sum, e) => sum + Math.abs(parseFloat(e.balance)), 0);
  const totalExpense = expenseAccounts.reduce((sum, e) => sum + Math.abs(parseFloat(e.balance)), 0);
  const netIncome = totalRevenue - totalExpense;

  // Balance Sheet Calculations
  const assetAccounts = entries.filter((e) => e.type === "Asset");
  const liabilityAccounts = entries.filter((e) => e.type === "Liability");
  const equityAccounts = entries.filter((e) => e.type === "Equity");

  const totalAssets = assetAccounts.reduce((sum, e) => sum + Math.abs(parseFloat(e.balance)), 0);
  const totalLiabilities = liabilityAccounts.reduce((sum, e) => sum + Math.abs(parseFloat(e.balance)), 0);
  const totalEquityBeforeIncome = equityAccounts.reduce((sum, e) => sum + Math.abs(parseFloat(e.balance)), 0);
  const totalEquity = totalEquityBeforeIncome + netIncome;

  return (
    <div className="animate-fade-in">
      <div className="flex justify-between items-center mb-8">
        <div>
          <h1 className="heading-1 mb-2">Financial Reports</h1>
          <p className="text-secondary">View and export your company's financial statements</p>
        </div>
      </div>

      <div className="glass-panel p-4 mb-6 flex gap-4 items-end" style={{ flexWrap: "wrap" }}>
        <div>
          <label className="block text-sm font-medium text-secondary mb-1">Start Date</label>
          <input
            type="date"
            value={startDate}
            onChange={(e) => setStartDate(e.target.value)}
            className="form-input"
            style={{ minWidth: "150px" }}
          />
        </div>
        <div>
          <label className="block text-sm font-medium text-secondary mb-1">End Date</label>
          <input
            type="date"
            value={endDate}
            onChange={(e) => setEndDate(e.target.value)}
            className="form-input"
            style={{ minWidth: "150px" }}
          />
        </div>
        <div style={{ flexGrow: 1 }} />
        <div className="flex gap-2">
          {["TB", "PNL", "BS"].map((tab) => (
            <button
              key={tab}
              onClick={() => setActiveTab(tab as any)}
              style={{
                padding: "0.5rem 1rem",
                borderRadius: "0.375rem",
                fontSize: "0.875rem",
                fontWeight: 600,
                border: "none",
                background: activeTab === tab ? "var(--accent-primary)" : "rgba(255,255,255,0.05)",
                color: activeTab === tab ? "#fff" : "var(--text-secondary)",
                cursor: "pointer",
                transition: "all 0.2s",
              }}
            >
              {tab === "TB" && "Trial Balance"}
              {tab === "PNL" && "Profit & Loss"}
              {tab === "BS" && "Balance Sheet"}
            </button>
          ))}
        </div>
      </div>

      <div className="glass-panel overflow-hidden p-6">
        {isLoading ? (
          <div className="p-12 text-center text-secondary">Generating report...</div>
        ) : error ? (
          <div className="p-12 text-center" style={{ color: "var(--error)" }}>
            <h3 className="heading-2 mb-2">Failed to load report</h3>
          </div>
        ) : entries.length === 0 ? (
          <div className="p-12 text-center text-secondary">
            No data available for the selected period.
          </div>
        ) : (
          <>
            {activeTab === "TB" && (
              <div>
                <h2 className="heading-2 mb-6 text-center">Trial Balance</h2>
                <table className="data-table" style={{ width: "100%", borderCollapse: "collapse" }}>
                  <thead>
                    <tr>
                      <th style={{ textAlign: "left" }}>Account</th>
                      <th style={{ textAlign: "right" }}>Debit</th>
                      <th style={{ textAlign: "right" }}>Credit</th>
                    </tr>
                  </thead>
                  <tbody>
                    {entries.map((entry) => {
                      const debit = parseFloat(entry.totalDebit);
                      const credit = parseFloat(entry.totalCredit);
                      return (
                        <tr key={entry.id}>
                          <td>{entry.code} - {entry.name}</td>
                          <td style={{ textAlign: "right", fontFamily: "monospace" }}>
                            {debit > 0 ? debit.toLocaleString("en-SA", { minimumFractionDigits: 2 }) : "—"}
                          </td>
                          <td style={{ textAlign: "right", fontFamily: "monospace" }}>
                            {credit > 0 ? credit.toLocaleString("en-SA", { minimumFractionDigits: 2 }) : "—"}
                          </td>
                        </tr>
                      );
                    })}
                  </tbody>
                  {response?.totals && (
                    <tfoot>
                      <tr style={{ background: "rgba(255,255,255,0.04)", fontWeight: 700 }}>
                        <td style={{ padding: "1rem 1.5rem" }}>Totals</td>
                        <td style={{ textAlign: "right", padding: "1rem 1.5rem", fontFamily: "monospace" }}>
                          {parseFloat(response.totals.debit).toLocaleString("en-SA", { minimumFractionDigits: 2 })}
                        </td>
                        <td style={{ textAlign: "right", padding: "1rem 1.5rem", fontFamily: "monospace" }}>
                          {parseFloat(response.totals.credit).toLocaleString("en-SA", { minimumFractionDigits: 2 })}
                        </td>
                      </tr>
                    </tfoot>
                  )}
                </table>
              </div>
            )}

            {activeTab === "PNL" && (
              <div style={{ maxWidth: "800px", margin: "0 auto" }}>
                <h2 className="heading-2 mb-2 text-center">Income Statement</h2>
                <p className="text-center text-secondary mb-8">
                  For the period {startDate || "start of time"} to {endDate || "present"}
                </p>

                <div className="mb-6">
                  <h3 className="text-lg font-bold mb-3 pb-2" style={{ borderBottom: "1px solid rgba(255,255,255,0.1)" }}>Revenue</h3>
                  {revenueAccounts.map(acc => (
                    <div key={acc.id} className="flex justify-between py-2 text-secondary">
                      <span>{acc.name}</span>
                      <span style={{ fontFamily: "monospace" }}>{Math.abs(parseFloat(acc.balance)).toLocaleString("en-SA", { minimumFractionDigits: 2 })}</span>
                    </div>
                  ))}
                  <div className="flex justify-between py-3 mt-2 font-bold" style={{ borderTop: "1px dashed rgba(255,255,255,0.1)" }}>
                    <span>Total Revenue</span>
                    <span style={{ fontFamily: "monospace" }}>{totalRevenue.toLocaleString("en-SA", { minimumFractionDigits: 2 })}</span>
                  </div>
                </div>

                <div className="mb-8">
                  <h3 className="text-lg font-bold mb-3 pb-2" style={{ borderBottom: "1px solid rgba(255,255,255,0.1)" }}>Operating Expenses</h3>
                  {expenseAccounts.map(acc => (
                    <div key={acc.id} className="flex justify-between py-2 text-secondary">
                      <span>{acc.name}</span>
                      <span style={{ fontFamily: "monospace" }}>{Math.abs(parseFloat(acc.balance)).toLocaleString("en-SA", { minimumFractionDigits: 2 })}</span>
                    </div>
                  ))}
                  <div className="flex justify-between py-3 mt-2 font-bold" style={{ borderTop: "1px dashed rgba(255,255,255,0.1)" }}>
                    <span>Total Expenses</span>
                    <span style={{ fontFamily: "monospace" }}>{totalExpense.toLocaleString("en-SA", { minimumFractionDigits: 2 })}</span>
                  </div>
                </div>

                <div className="flex justify-between py-4 px-4 font-bold text-lg" style={{ background: netIncome >= 0 ? "rgba(16,185,129,0.15)" : "rgba(239,68,68,0.15)", color: netIncome >= 0 ? "#34d399" : "#f87171", borderRadius: "0.5rem" }}>
                  <span>Net Income</span>
                  <span style={{ fontFamily: "monospace" }}>{netIncome.toLocaleString("en-SA", { minimumFractionDigits: 2 })}</span>
                </div>
              </div>
            )}

            {activeTab === "BS" && (
              <div style={{ maxWidth: "800px", margin: "0 auto" }}>
                <h2 className="heading-2 mb-2 text-center">Balance Sheet</h2>
                <p className="text-center text-secondary mb-8">
                  As of {endDate || new Date().toISOString().split("T")[0]}
                </p>

                <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr", gap: "3rem" }}>
                  <div>
                    <h3 className="text-lg font-bold mb-3 pb-2" style={{ borderBottom: "1px solid rgba(255,255,255,0.1)", color: "#60a5fa" }}>Assets</h3>
                    {assetAccounts.map(acc => (
                      <div key={acc.id} className="flex justify-between py-2 text-secondary">
                        <span>{acc.name}</span>
                        <span style={{ fontFamily: "monospace" }}>{Math.abs(parseFloat(acc.balance)).toLocaleString("en-SA", { minimumFractionDigits: 2 })}</span>
                      </div>
                    ))}
                    <div className="flex justify-between py-3 mt-2 font-bold" style={{ borderTop: "1px dashed rgba(255,255,255,0.1)" }}>
                      <span>Total Assets</span>
                      <span style={{ fontFamily: "monospace", color: "#60a5fa" }}>{totalAssets.toLocaleString("en-SA", { minimumFractionDigits: 2 })}</span>
                    </div>
                  </div>

                  <div>
                    <div className="mb-8">
                      <h3 className="text-lg font-bold mb-3 pb-2" style={{ borderBottom: "1px solid rgba(255,255,255,0.1)", color: "#f87171" }}>Liabilities</h3>
                      {liabilityAccounts.map(acc => (
                        <div key={acc.id} className="flex justify-between py-2 text-secondary">
                          <span>{acc.name}</span>
                          <span style={{ fontFamily: "monospace" }}>{Math.abs(parseFloat(acc.balance)).toLocaleString("en-SA", { minimumFractionDigits: 2 })}</span>
                        </div>
                      ))}
                      <div className="flex justify-between py-3 mt-2 font-bold" style={{ borderTop: "1px dashed rgba(255,255,255,0.1)" }}>
                        <span>Total Liabilities</span>
                        <span style={{ fontFamily: "monospace", color: "#f87171" }}>{totalLiabilities.toLocaleString("en-SA", { minimumFractionDigits: 2 })}</span>
                      </div>
                    </div>

                    <div>
                      <h3 className="text-lg font-bold mb-3 pb-2" style={{ borderBottom: "1px solid rgba(255,255,255,0.1)", color: "#a78bfa" }}>Equity</h3>
                      {equityAccounts.map(acc => (
                        <div key={acc.id} className="flex justify-between py-2 text-secondary">
                          <span>{acc.name}</span>
                          <span style={{ fontFamily: "monospace" }}>{Math.abs(parseFloat(acc.balance)).toLocaleString("en-SA", { minimumFractionDigits: 2 })}</span>
                        </div>
                      ))}
                      <div className="flex justify-between py-2 text-secondary">
                        <span>Current Year Net Income</span>
                        <span style={{ fontFamily: "monospace", color: netIncome >= 0 ? "#34d399" : "#f87171" }}>
                          {netIncome.toLocaleString("en-SA", { minimumFractionDigits: 2 })}
                        </span>
                      </div>
                      <div className="flex justify-between py-3 mt-2 font-bold" style={{ borderTop: "1px dashed rgba(255,255,255,0.1)" }}>
                        <span>Total Equity</span>
                        <span style={{ fontFamily: "monospace", color: "#a78bfa" }}>{totalEquity.toLocaleString("en-SA", { minimumFractionDigits: 2 })}</span>
                      </div>
                    </div>
                  </div>
                </div>

                <div className="flex justify-between py-4 px-4 font-bold text-lg mt-8" style={{ background: "rgba(255,255,255,0.05)", borderRadius: "0.5rem" }}>
                  <span>Total Liabilities & Equity</span>
                  <span style={{ fontFamily: "monospace" }}>{(totalLiabilities + totalEquity).toLocaleString("en-SA", { minimumFractionDigits: 2 })}</span>
                </div>
              </div>
            )}
          </>
        )}
      </div>
    </div>
  );
}
