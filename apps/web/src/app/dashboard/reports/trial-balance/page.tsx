"use client";

import { useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { ApiClient } from "../../../../lib/api-client";

export default function TrialBalanceReport() {
  const [asOfDate, setAsOfDate] = useState("");

  const { data, isLoading, error } = useQuery({
    queryKey: ["trial-balance", asOfDate],
    queryFn: () => ApiClient.get(`/accounting/gl/trial-balance${asOfDate ? `?endDate=${asOfDate}` : ""}`),
  });

  return (
    <div className="animate-fade-in">
      <div className="flex justify-between items-center mb-8">
        <div>
          <h1 className="heading-1 mb-2">Trial Balance</h1>
          <p className="text-secondary">Summary of all account balances</p>
        </div>
        <div style={{ display: "flex", gap: "1rem", alignItems: "center" }}>
          <label className="text-sm text-secondary">As of Date:</label>
          <input
            type="date"
            className="form-input"
            value={asOfDate}
            onChange={(e) => setAsOfDate(e.target.value)}
          />
          <button className="btn-primary" onClick={() => window.print()}>
            Print
          </button>
        </div>
      </div>

      <div className="glass-panel overflow-hidden">
        {isLoading ? (
          <div className="p-12 text-center text-secondary">Loading report...</div>
        ) : error ? (
          <div className="p-12 text-center text-error">Failed to load trial balance.</div>
        ) : !data || !data.items || data.items.length === 0 ? (
          <div className="p-12 text-center text-secondary">No transactions found.</div>
        ) : (
          <table className="data-table">
            <thead>
              <tr>
                <th>Account Code</th>
                <th>Account Name</th>
                <th>Type</th>
                <th style={{ textAlign: "right" }}>Debit</th>
                <th style={{ textAlign: "right" }}>Credit</th>
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
                <td colSpan={3} style={{ textAlign: "right" }}>Totals:</td>
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
