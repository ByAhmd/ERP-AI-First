"use client";

import { useQuery } from "@tanstack/react-query";
import Link from "next/link";
import { ApiClient } from "../../lib/api-client";
import { useLanguage } from "../../components/LanguageProvider";
import {
  AreaChart,
  Area,
  XAxis,
  YAxis,
  CartesianGrid,
  Tooltip,
  ResponsiveContainer,
  Legend,
} from "recharts";

interface DashboardKpiResponse {
  metrics: {
    monthlyRevenue: number;
    monthlyExpenses: number;
    monthlyProfit: number;
    allTimeRevenue: number;
    allTimeProfit: number;
    openReceivables: number;
    openPayables: number;
    headcount: number;
    crmPipelineValue: number;
    inventoryValuation: number;
  };
  trendData: Array<{
    name: string;
    revenue: number;
    expenses: number;
    profit: number;
  }>;
}

export default function DashboardOverview() {
  const { t, locale } = useLanguage();
  const { data, isLoading, error } = useQuery({
    queryKey: ["dashboard-kpis"],
    queryFn: () =>
      ApiClient.get<DashboardKpiResponse>("/reports/dashboard-kpis"),
  });

  const m = data?.metrics || {
    monthlyRevenue: 0,
    monthlyExpenses: 0,
    monthlyProfit: 0,
    allTimeRevenue: 0,
    allTimeProfit: 0,
    openReceivables: 0,
    openPayables: 0,
    headcount: 0,
    crmPipelineValue: 0,
    inventoryValuation: 0,
  };

  const cards = [
    {
      label: t("dashboard.monthlyRevenue"),
      value: m.monthlyRevenue,
      color: "#34d399",
      bg: "rgba(16,185,129,0.1)",
      icon: "📈",
      format: "currency",
    },
    {
      label: t("dashboard.monthlyProfit"),
      value: m.monthlyProfit,
      color: m.monthlyProfit >= 0 ? "#60a5fa" : "#f87171",
      bg:
        m.monthlyProfit >= 0
          ? "rgba(59,130,246,0.1)"
          : "rgba(239,68,68,0.1)",
      icon: "💰",
      format: "currency",
    },
    {
      label: t("dashboard.crmPipeline"),
      value: m.crmPipelineValue,
      color: "#38bdf8",
      bg: "rgba(56,189,248,0.1)",
      icon: "🤝",
      format: "currency",
    },
    {
      label: t("dashboard.inventoryValuation"),
      value: m.inventoryValuation,
      color: "#facc15",
      bg: "rgba(250,204,21,0.1)",
      icon: "📦",
      format: "currency",
    },
    {
      label: t("dashboard.openReceivables"),
      value: m.openReceivables,
      color: "#a78bfa",
      bg: "rgba(139,92,246,0.1)",
      icon: "📥",
      format: "currency",
    },
    {
      label: t("dashboard.openPayables"),
      value: m.openPayables,
      color: "#fb923c",
      bg: "rgba(251,146,60,0.1)",
      icon: "📤",
      format: "currency",
    },
    {
      label: t("dashboard.headcount"),
      value: m.headcount,
      color: "#f472b6",
      bg: "rgba(244,114,182,0.1)",
      icon: "👥",
      format: "number",
    },
  ];

  const quickLinks = [
    {
      href: "/dashboard/accounting/journal-entries",
      label: t("dashboard.postJe"),
      icon: "📝",
    },
    { href: "/dashboard/invoices", label: t("dashboard.createInvoice"), icon: "🧾" },
    {
      href: "/dashboard/accounting/reconciliation",
      label: t("dashboard.bankRecon"),
      icon: "🏦",
    },
    {
      href: "/dashboard/reports/trial-balance",
      label: t("dashboard.trialBalance"),
      icon: "⚖️",
    },
    {
      href: "/dashboard/reports/income-statement",
      label: t("dashboard.incomeStatement"),
      icon: "📉",
    },
    {
      href: "/dashboard/reports/balance-sheet",
      label: t("dashboard.balanceSheet"),
      icon: "📑",
    },
  ];

  const dateLocale = locale === "ar" ? "ar-SA" : "en-SA";

  return (
    <div className="animate-fade-in">
      <div
        style={{
          display: "flex",
          justifyContent: "space-between",
          alignItems: "center",
          marginBottom: "2rem",
        }}
      >
        <h1 className="heading-1" style={{ marginBottom: 0 }}>
          {t("dashboard.title")}
        </h1>
        <div
          style={{
            fontSize: "0.875rem",
            color: "var(--text-secondary)",
            background: "rgba(255,255,255,0.05)",
            padding: "0.5rem 1rem",
            borderRadius: "0.5rem",
          }}
        >
          {new Date().toLocaleDateString(dateLocale, {
            month: "long",
            year: "numeric",
          })}
        </div>
      </div>

      {/* KPI Cards */}
      {isLoading ? (
        <div
          style={{
            display: "grid",
            gridTemplateColumns: "repeat(auto-fit, minmax(250px, 1fr))",
            gap: "1.5rem",
            marginBottom: "2rem",
          }}
        >
          {[1, 2, 3, 4, 5, 6, 7].map((i) => (
            <div
              key={i}
              className="glass-panel p-6"
              style={{ minHeight: "7rem", opacity: 0.5 }}
            />
          ))}
        </div>
      ) : error ? (
        <div
          className="glass-panel p-6 mb-8"
          style={{
            background: "rgba(239,68,68,0.1)",
            borderColor: "rgba(239,68,68,0.2)",
          }}
        >
          <p style={{ color: "var(--error)" }}>{t("dashboard.failedKpis")}</p>
        </div>
      ) : (
        <div
          style={{
            display: "grid",
            gridTemplateColumns: "repeat(auto-fit, minmax(250px, 1fr))",
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
              <div
                style={{
                  display: "flex",
                  justifyContent: "space-between",
                  alignItems: "flex-start",
                  marginBottom: "1rem",
                }}
              >
                <h3
                  style={{
                    fontSize: "0.875rem",
                    fontWeight: 500,
                    color: "var(--text-secondary)",
                  }}
                >
                  {card.label}
                </h3>
                <span style={{ fontSize: "1.5rem" }}>{card.icon}</span>
              </div>
              <div
                style={{
                  fontSize: "1.75rem",
                  fontWeight: 700,
                  color: card.color,
                  fontFamily: "monospace",
                }}
              >
                {card.format === "currency"
                  ? `${t("common.currency")} ${Math.abs(card.value).toLocaleString(dateLocale, { minimumFractionDigits: 2 })}`
                  : card.value.toLocaleString(dateLocale)}
              </div>
            </div>
          ))}
        </div>
      )}

      {/* Charts & Actions */}
      <div style={{ display: "grid", gridTemplateColumns: "2fr 1fr", gap: "1.5rem" }}>
        <div className="glass-panel p-6">
          <h2 style={{ fontSize: "1.125rem", fontWeight: 600, marginBottom: "1.5rem" }}>
            {t("dashboard.sixMonthTrend")}
          </h2>

          <div style={{ height: 300, width: "100%" }}>
            {isLoading ? (
              <div className="text-secondary flex items-center justify-center h-full">
                {t("common.loadingChart")}
              </div>
            ) : (
              <ResponsiveContainer width="100%" height="100%">
                <AreaChart
                  data={data?.trendData}
                  margin={{ top: 10, right: 30, left: 0, bottom: 0 }}
                >
                  <defs>
                    <linearGradient id="colorRev" x1="0" y1="0" x2="0" y2="1">
                      <stop offset="5%" stopColor="#34d399" stopOpacity={0.3} />
                      <stop offset="95%" stopColor="#34d399" stopOpacity={0} />
                    </linearGradient>
                    <linearGradient id="colorExp" x1="0" y1="0" x2="0" y2="1">
                      <stop offset="5%" stopColor="#f87171" stopOpacity={0.3} />
                      <stop offset="95%" stopColor="#f87171" stopOpacity={0} />
                    </linearGradient>
                  </defs>
                  <XAxis dataKey="name" stroke="#94a3b8" />
                  <YAxis
                    stroke="#94a3b8"
                    tickFormatter={(value) => `${value / 1000}k`}
                  />
                  <CartesianGrid
                    strokeDasharray="3 3"
                    stroke="#334155"
                    vertical={false}
                  />
                  <Tooltip
                    contentStyle={{
                      backgroundColor: "rgba(15,23,42,0.9)",
                      border: "1px solid rgba(255,255,255,0.1)",
                      borderRadius: "8px",
                    }}
                    itemStyle={{ color: "#fff" }}
                  />
                  <Legend />
                  <Area
                    type="monotone"
                    dataKey="revenue"
                    name={t("dashboard.revenue")}
                    stroke="#34d399"
                    fillOpacity={1}
                    fill="url(#colorRev)"
                  />
                  <Area
                    type="monotone"
                    dataKey="expenses"
                    name={t("dashboard.expenses")}
                    stroke="#f87171"
                    fillOpacity={1}
                    fill="url(#colorExp)"
                  />
                </AreaChart>
              </ResponsiveContainer>
            )}
          </div>
        </div>

        <div className="glass-panel p-6">
          <h2 style={{ fontSize: "1.125rem", fontWeight: 600, marginBottom: "1.25rem" }}>
            {t("dashboard.quickLinks")}
          </h2>
          <div style={{ display: "grid", gridTemplateColumns: "1fr", gap: "0.75rem" }}>
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
              >
                <span style={{ fontSize: "1.25rem" }}>{link.icon}</span>
                {link.label}
              </Link>
            ))}
          </div>
        </div>
      </div>
    </div>
  );
}
