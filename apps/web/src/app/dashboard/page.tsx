"use client";

import { useQuery } from "@tanstack/react-query";
import Link from "next/link";
import { ApiClient } from "../../lib/api-client";

interface TrialBalanceEntry {
  accountId: string;
  accountCode: string;
  accountName: string;
  type: string;
  debit: number;
  credit: number;
  balance: number;
}

export default function DashboardOverview() {
  const { data, isLoading, error } = useQuery({
    queryKey: ["trial-balance"],
    queryFn: () => ApiClient.get<TrialBalanceEntry[]>("/accounting/gl/trial-balance"),
  });

  const summary = (data || []).reduce(
    (acc, entry) => {
      if (entry.type === "Revenue") acc.revenue += entry.credit - entry.debit;
      if (entry.type === "Expense") acc.expenses += entry.debit - entry.credit;
      if (
        entry.type === "Asset" &&
        (entry.accountName.toLowerCase().includes("cash") ||
          entry.accountName.toLowerCase().includes("bank"))
      ) {
        acc.cashBalance += entry.debit - entry.credit;
      }
      return acc;
    },
    { revenue: 0, expenses: 0, cashBalance: 0 }
  );

  const netIncome = summary.revenue - summary.expenses;

  const cards = [
    {
      label: "Total Revenue",
      value: summary.revenue,
      color: "#34d399",
      bg: "rgba(16,185,129,0.1)",
      icon: "📈",
    },
    {
      label: "Net Income",
      value: netIncome,
      color: netIncome >= 0 ? "#60a5fa" : "#f87171",
      bg: netIncome >= 0 ? "rgba(59,130,246,0.1)" : "rgba(239,68,68,0.1)",
      icon: "💰",
    },
    {
      label: "Cash & Bank",
      value: summary.cashBalance,
      color: "#a78bfa",
      bg: "rgba(139,92,246,0.1)",
      icon: "🏦",
    },
  ];

  const quickLinks = [
    { href: "/dashboard/accounting/journal-entries", label: "Post Journal Entry", icon: "📝" },
    { href: "/dashboard/accounting/coa", label: "Chart of Accounts", icon: "📂" },
    { href: "/dashboard/accounting/periods", label: "Accounting Periods", icon: "📅" },
    { href: "/dashboard/invoices", label: "Create Invoice", icon: "🧾" },
    { href: "/dashboard/contacts", label: "Manage Contacts", icon: "👥" },
    { href: "/dashboard/ledger", label: "General Ledger", icon: "📒" },
  ];

  return (
    <div className="animate-fade-in">
      <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", marginBottom: "2rem" }}>
        <h1 className="heading-1" style={{ marginBottom: 0 }}>Financial Overview</h1>
        <div
          style={{
            fontSize: "0.875rem",
            color: "var(--text-secondary)",
            background: "rgba(255,255,255,0.05)",
            padding: "0.5rem 1rem",
            borderRadius: "0.5rem",
          }}
        >
          {new Date().toLocaleDateString("en-SA", { month: "long", year: "numeric" })}
        </div>
      </div>

      {/* KPI Cards */}
      {isLoading ? (
        <div
          style={{
            display: "grid",
            gridTemplateColumns: "repeat(3, 1fr)",
            gap: "1.5rem",
            marginBottom: "2rem",
          }}
        >
          {[1, 2, 3].map((i) => (
            <div key={i} className="glass-panel p-6" style={{ minHeight: "7rem", opacity: 0.5 }} />
          ))}
        </div>
      ) : error ? (
        <div
          className="glass-panel p-6 mb-8"
          style={{ background: "rgba(239,68,68,0.1)", borderColor: "rgba(239,68,68,0.2)" }}
        >
          <p style={{ color: "var(--error)" }}>
            Could not load financial data. Make sure you have an active company selected and journal entries posted.
          </p>
        </div>
      ) : (
        <div
          style={{
            display: "grid",
            gridTemplateColumns: "repeat(3, 1fr)",
            gap: "1.5rem",
            marginBottom: "2rem",
          }}
        >
          {cards.map((card) => (
            <div
              key={card.label}
              className="glass-panel p-6"
              style={{ borderColor: "rgba(255,255,255,0.1)" }}
            >
              <div style={{ display: "flex", justifyContent: "space-between", alignItems: "flex-start", marginBottom: "1rem" }}>
                <h3 style={{ fontSize: "0.875rem", fontWeight: 500, color: "var(--text-secondary)" }}>
                  {card.label}
                </h3>
                <span style={{ fontSize: "1.5rem" }}>{card.icon}</span>
              </div>
              <div
                style={{ fontSize: "1.75rem", fontWeight: 700, color: card.color, fontFamily: "monospace" }}
              >
                SAR {Math.abs(card.value).toLocaleString("en-SA", { minimumFractionDigits: 2 })}
              </div>
              {card.label === "Net Income" && netIncome < 0 && (
                <div style={{ fontSize: "0.75rem", color: "var(--error)", marginTop: "0.25rem" }}>Net Loss</div>
              )}
            </div>
          ))}
        </div>
      )}

      {/* Quick Actions */}
      <div style={{ display: "grid", gridTemplateColumns: "2fr 1fr", gap: "1.5rem" }}>
        <div className="glass-panel p-6">
          <h2 style={{ fontSize: "1.125rem", fontWeight: 600, marginBottom: "1.25rem" }}>
            Quick Actions
          </h2>
          <div style={{ display: "grid", gridTemplateColumns: "repeat(2, 1fr)", gap: "0.75rem" }}>
            {quickLinks.map((link) => (
              <Link
                key={link.href}
                href={link.href}
                style={{
                  display: "flex",
                  alignItems: "center",
                  gap: "0.75rem",
                  padding: "0.875rem 1rem",
                  borderRadius: "0.5rem",
                  background: "rgba(255,255,255,0.03)",
                  border: "1px solid var(--glass-border)",
                  color: "var(--text-primary)",
                  fontWeight: 500,
                  fontSize: "0.875rem",
                  transition: "all 0.2s",
                  textDecoration: "none",
                }}
                onMouseEnter={(e) => {
                  (e.currentTarget as HTMLElement).style.background = "rgba(59,130,246,0.1)";
                  (e.currentTarget as HTMLElement).style.borderColor = "rgba(59,130,246,0.3)";
                }}
                onMouseLeave={(e) => {
                  (e.currentTarget as HTMLElement).style.background = "rgba(255,255,255,0.03)";
                  (e.currentTarget as HTMLElement).style.borderColor = "var(--glass-border)";
                }}
              >
                <span style={{ fontSize: "1.25rem" }}>{link.icon}</span>
                {link.label}
              </Link>
            ))}
          </div>
        </div>

        <div className="glass-panel p-6">
          <h2 style={{ fontSize: "1.125rem", fontWeight: 600, marginBottom: "1.25rem" }}>
            Account Summary
          </h2>
          {isLoading ? (
            <div className="text-secondary">Loading...</div>
          ) : !data || data.length === 0 ? (
            <div className="text-secondary" style={{ fontSize: "0.875rem" }}>
              No accounts with activity. Post journal entries to see your account summary.
            </div>
          ) : (
            <div style={{ display: "flex", flexDirection: "column", gap: "0.75rem" }}>
              {["Asset", "Liability", "Equity", "Revenue", "Expense"].map((type) => {
                const typeEntries = data.filter((e) => e.type === type);
                const total = typeEntries.reduce((s, e) => s + Math.abs(e.balance), 0);
                if (total === 0) return null;
                return (
                  <div
                    key={type}
                    style={{
                      display: "flex",
                      justifyContent: "space-between",
                      alignItems: "center",
                      padding: "0.5rem 0",
                      borderBottom: "1px solid rgba(255,255,255,0.05)",
                    }}
                  >
                    <span style={{ fontSize: "0.875rem", color: "var(--text-secondary)" }}>{type}</span>
                    <span style={{ fontSize: "0.875rem", fontWeight: 600, fontFamily: "monospace" }}>
                      SAR {total.toLocaleString("en-SA", { minimumFractionDigits: 0 })}
                    </span>
                  </div>
                );
              })}
            </div>
          )}
        </div>
      </div>
    </div>
  );
}
