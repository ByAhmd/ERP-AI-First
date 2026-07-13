"use client";

import { useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { ApiClient } from "../../../../lib/api-client";

export default function IncomeStatementReport() {
  const [startDate, setStartDate] = useState("");
  const [endDate, setEndDate] = useState("");

  const queryString = new URLSearchParams();
  if (startDate) queryString.append("startDate", startDate);
  if (endDate) queryString.append("endDate", endDate);

  const { data, isLoading, error } = useQuery({
    queryKey: ["income-statement", startDate, endDate],
    queryFn: () => ApiClient.get(`/reports/pnl?${queryString.toString()}`),
  });

  return (
    <div className="animate-fade-in">
      <div className="flex justify-between items-center mb-8">
        <div>
          <h1 className="heading-1 mb-2">Income Statement</h1>
          <p className="text-secondary">Profit & Loss for the selected period</p>
        </div>
        <div style={{ display: "flex", gap: "1rem", alignItems: "center" }}>
          <div style={{ display: "flex", flexDirection: "column" }}>
            <label className="text-xs text-secondary mb-1">Start Date</label>
            <input type="date" className="form-input" value={startDate} onChange={(e) => setStartDate(e.target.value)} />
          </div>
          <div style={{ display: "flex", flexDirection: "column" }}>
            <label className="text-xs text-secondary mb-1">End Date</label>
            <input type="date" className="form-input" value={endDate} onChange={(e) => setEndDate(e.target.value)} />
          </div>
          <button className="btn-primary" style={{ marginTop: "1rem" }} onClick={() => window.print()}>
            Print
          </button>
        </div>
      </div>

      <div className="glass-panel overflow-hidden">
        {isLoading ? (
          <div className="p-12 text-center text-secondary">Loading report...</div>
        ) : error ? (
          <div className="p-12 text-center text-error">Failed to load income statement.</div>
        ) : !data ? (
          <div className="p-12 text-center text-secondary">No data available.</div>
        ) : (
          <div style={{ padding: "2rem" }}>
            <h2 style={{ fontSize: "1.25rem", fontWeight: 600, borderBottom: "1px solid rgba(255,255,255,0.1)", paddingBottom: "0.5rem", marginBottom: "1rem", color: "#34d399" }}>
              Revenue
            </h2>
            <div style={{ display: "flex", flexDirection: "column", gap: "0.5rem", marginBottom: "2rem" }}>
              {data.revenue.length === 0 ? <span className="text-secondary">No revenue recorded.</span> : null}
              {data.revenue.map((r: any) => (
                <div key={r.id} style={{ display: "flex", justifyContent: "space-between" }}>
                  <span>{r.code} - {r.name}</span>
                  <span style={{ fontFamily: "monospace" }}>{parseFloat(r.balance).toLocaleString("en-SA", { minimumFractionDigits: 2 })}</span>
                </div>
              ))}
              <div style={{ display: "flex", justifyContent: "space-between", fontWeight: "bold", borderTop: "1px dashed rgba(255,255,255,0.2)", paddingTop: "0.5rem", marginTop: "0.5rem" }}>
                <span>Total Revenue</span>
                <span style={{ fontFamily: "monospace", color: "#34d399" }}>{parseFloat(data.totalRevenue).toLocaleString("en-SA", { minimumFractionDigits: 2 })}</span>
              </div>
            </div>

            <h2 style={{ fontSize: "1.25rem", fontWeight: 600, borderBottom: "1px solid rgba(255,255,255,0.1)", paddingBottom: "0.5rem", marginBottom: "1rem", color: "#f87171" }}>
              Expenses
            </h2>
            <div style={{ display: "flex", flexDirection: "column", gap: "0.5rem", marginBottom: "2rem" }}>
              {data.expenses.length === 0 ? <span className="text-secondary">No expenses recorded.</span> : null}
              {data.expenses.map((e: any) => (
                <div key={e.id} style={{ display: "flex", justifyContent: "space-between" }}>
                  <span>{e.code} - {e.name}</span>
                  <span style={{ fontFamily: "monospace" }}>{parseFloat(e.balance).toLocaleString("en-SA", { minimumFractionDigits: 2 })}</span>
                </div>
              ))}
              <div style={{ display: "flex", justifyContent: "space-between", fontWeight: "bold", borderTop: "1px dashed rgba(255,255,255,0.2)", paddingTop: "0.5rem", marginTop: "0.5rem" }}>
                <span>Total Expenses</span>
                <span style={{ fontFamily: "monospace", color: "#f87171" }}>{parseFloat(data.totalExpense).toLocaleString("en-SA", { minimumFractionDigits: 2 })}</span>
              </div>
            </div>

            <div style={{ display: "flex", justifyContent: "space-between", fontWeight: "bold", borderTop: "2px solid rgba(255,255,255,0.2)", paddingTop: "1rem", marginTop: "1rem", fontSize: "1.25rem" }}>
              <span>Net Income</span>
              <span style={{ fontFamily: "monospace", color: parseFloat(data.netIncome) >= 0 ? "#60a5fa" : "#f87171" }}>
                SAR {parseFloat(data.netIncome).toLocaleString("en-SA", { minimumFractionDigits: 2 })}
              </span>
            </div>
          </div>
        )}
      </div>
    </div>
  );
}
