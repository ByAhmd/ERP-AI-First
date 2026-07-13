"use client";

import Link from "next/link";

export default function ReportsIndex() {
  const reports = [
    {
      title: "Trial Balance",
      description: "A summary of all account balances to ensure debits equal credits.",
      href: "/dashboard/reports/trial-balance",
      icon: "⚖️"
    },
    {
      title: "Income Statement (P&L)",
      description: "Shows your company's revenue and expenses over a specific period.",
      href: "/dashboard/reports/income-statement",
      icon: "📉"
    },
    {
      title: "Balance Sheet",
      description: "A snapshot of your assets, liabilities, and equity at a specific point in time.",
      href: "/dashboard/reports/balance-sheet",
      icon: "📑"
    }
  ];

  return (
    <div className="animate-fade-in">
      <div style={{ marginBottom: "2rem" }}>
        <h1 className="heading-1 mb-2">Financial Reports</h1>
        <p className="text-secondary">Generate and view your core financial statements.</p>
      </div>

      <div style={{ display: "grid", gridTemplateColumns: "repeat(auto-fit, minmax(300px, 1fr))", gap: "1.5rem" }}>
        {reports.map((report) => (
          <Link
            key={report.href}
            href={report.href}
            className="glass-panel"
            style={{
              padding: "1.5rem",
              textDecoration: "none",
              display: "flex",
              flexDirection: "column",
              gap: "1rem",
              transition: "all 0.2s",
            }}
            onMouseEnter={(e) => {
              (e.currentTarget as HTMLElement).style.background = "rgba(59,130,246,0.1)";
              (e.currentTarget as HTMLElement).style.borderColor = "rgba(59,130,246,0.3)";
            }}
            onMouseLeave={(e) => {
              (e.currentTarget as HTMLElement).style.background = "var(--bg-card)";
              (e.currentTarget as HTMLElement).style.borderColor = "var(--glass-border)";
            }}
          >
            <div style={{ display: "flex", alignItems: "center", gap: "1rem" }}>
              <span style={{ fontSize: "2rem" }}>{report.icon}</span>
              <h2 style={{ fontSize: "1.25rem", fontWeight: 600, color: "var(--text-primary)" }}>{report.title}</h2>
            </div>
            <p style={{ color: "var(--text-secondary)", fontSize: "0.875rem", lineHeight: 1.5 }}>
              {report.description}
            </p>
            <div style={{ marginTop: "auto", color: "var(--accent-primary)", fontSize: "0.875rem", fontWeight: 500 }}>
              View Report →
            </div>
          </Link>
        ))}
      </div>
    </div>
  );
}
