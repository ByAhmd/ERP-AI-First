"use client";

import { useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { ApiClient } from "../../../../lib/api-client";

export default function BalanceSheetReport() {
  const [asOfDate, setAsOfDate] = useState("");

  const { data, isLoading, error } = useQuery({
    queryKey: ["balance-sheet", asOfDate],
    queryFn: () => ApiClient.get(`/reports/balance-sheet${asOfDate ? `?asOfDate=${asOfDate}` : ""}`),
  });

  return (
    <div className="animate-fade-in">
      <div className="flex justify-between items-center mb-8">
        <div>
          <h1 className="heading-1 mb-2">Balance Sheet</h1>
          <p className="text-secondary">Assets, Liabilities, and Equity snapshot</p>
        </div>
        <div style={{ display: "flex", gap: "1rem", alignItems: "center" }}>
          <div style={{ display: "flex", flexDirection: "column" }}>
            <label className="text-xs text-secondary mb-1">As of Date</label>
            <input type="date" className="form-input" value={asOfDate} onChange={(e) => setAsOfDate(e.target.value)} />
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
          <div className="p-12 text-center text-error">Failed to load balance sheet.</div>
        ) : !data ? (
          <div className="p-12 text-center text-secondary">No data available.</div>
        ) : (
          <div style={{ padding: "2rem" }}>
            <h2 style={{ fontSize: "1.25rem", fontWeight: 600, borderBottom: "1px solid rgba(255,255,255,0.1)", paddingBottom: "0.5rem", marginBottom: "1rem", color: "#60a5fa" }}>
              Assets
            </h2>
            <div style={{ display: "flex", flexDirection: "column", gap: "0.5rem", marginBottom: "2rem" }}>
              {data.assets.length === 0 ? <span className="text-secondary">No assets recorded.</span> : null}
              {data.assets.map((a: any) => (
                <div key={a.id} style={{ display: "flex", justifyContent: "space-between" }}>
                  <span>{a.code} - {a.name}</span>
                  <span style={{ fontFamily: "monospace" }}>{parseFloat(a.balance).toLocaleString("en-SA", { minimumFractionDigits: 2 })}</span>
                </div>
              ))}
              <div style={{ display: "flex", justifyContent: "space-between", fontWeight: "bold", borderTop: "1px dashed rgba(255,255,255,0.2)", paddingTop: "0.5rem", marginTop: "0.5rem" }}>
                <span>Total Assets</span>
                <span style={{ fontFamily: "monospace", color: "#60a5fa" }}>{parseFloat(data.totalAssets).toLocaleString("en-SA", { minimumFractionDigits: 2 })}</span>
              </div>
            </div>

            <h2 style={{ fontSize: "1.25rem", fontWeight: 600, borderBottom: "1px solid rgba(255,255,255,0.1)", paddingBottom: "0.5rem", marginBottom: "1rem", color: "#f87171" }}>
              Liabilities
            </h2>
            <div style={{ display: "flex", flexDirection: "column", gap: "0.5rem", marginBottom: "2rem" }}>
              {data.liabilities.length === 0 ? <span className="text-secondary">No liabilities recorded.</span> : null}
              {data.liabilities.map((l: any) => (
                <div key={l.id} style={{ display: "flex", justifyContent: "space-between" }}>
                  <span>{l.code} - {l.name}</span>
                  <span style={{ fontFamily: "monospace" }}>{parseFloat(l.balance).toLocaleString("en-SA", { minimumFractionDigits: 2 })}</span>
                </div>
              ))}
              <div style={{ display: "flex", justifyContent: "space-between", fontWeight: "bold", borderTop: "1px dashed rgba(255,255,255,0.2)", paddingTop: "0.5rem", marginTop: "0.5rem" }}>
                <span>Total Liabilities</span>
                <span style={{ fontFamily: "monospace", color: "#f87171" }}>{parseFloat(data.totalLiabilities).toLocaleString("en-SA", { minimumFractionDigits: 2 })}</span>
              </div>
            </div>

            <h2 style={{ fontSize: "1.25rem", fontWeight: 600, borderBottom: "1px solid rgba(255,255,255,0.1)", paddingBottom: "0.5rem", marginBottom: "1rem", color: "#a78bfa" }}>
              Equity
            </h2>
            <div style={{ display: "flex", flexDirection: "column", gap: "0.5rem", marginBottom: "2rem" }}>
              {data.equity.length === 0 ? <span className="text-secondary">No equity recorded.</span> : null}
              {data.equity.map((eq: any) => (
                <div key={eq.id} style={{ display: "flex", justifyContent: "space-between" }}>
                  <span>{eq.code} - {eq.name}</span>
                  <span style={{ fontFamily: "monospace" }}>{parseFloat(eq.balance).toLocaleString("en-SA", { minimumFractionDigits: 2 })}</span>
                </div>
              ))}
              <div style={{ display: "flex", justifyContent: "space-between", fontWeight: "bold", borderTop: "1px dashed rgba(255,255,255,0.2)", paddingTop: "0.5rem", marginTop: "0.5rem" }}>
                <span>Total Equity</span>
                <span style={{ fontFamily: "monospace", color: "#a78bfa" }}>{parseFloat(data.totalEquity).toLocaleString("en-SA", { minimumFractionDigits: 2 })}</span>
              </div>
            </div>

            <div style={{ display: "flex", justifyContent: "space-between", fontWeight: "bold", borderTop: "2px solid rgba(255,255,255,0.2)", paddingTop: "1rem", marginTop: "1rem", fontSize: "1.25rem" }}>
              <span>Total Liabilities & Equity</span>
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
