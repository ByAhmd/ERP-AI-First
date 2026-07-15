"use client";

import Link from "next/link";
import { usePathname, useRouter } from "next/navigation";
import { useEffect, useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { ApiClient } from "../../lib/api-client";
import { useLanguage } from "../../components/LanguageProvider";

interface UserProfile {
  id: string;
  email: string;
  fullName?: string;
}

interface Tenant {
  id: string;
  name: string;
}

export default function DashboardLayout({
  children,
}: {
  children: React.ReactNode;
}) {
  const router = useRouter();
  const pathname = usePathname();
  const { t, toggleLanguage, locale, isRTL } = useLanguage();
  const [user, setUser] = useState<UserProfile | null>(null);
  const [tenants, setTenants] = useState<Tenant[]>([]);
  const [activeTenant, setActiveTenant] = useState<Tenant | null>(null);
  const [showCompanySwitcher, setShowCompanySwitcher] = useState(false);

  // Poll for pending approvals every 10 seconds
  const { data: pendingApprovals } = useQuery({
    queryKey: ["pending-approvals"],
    queryFn: () => ApiClient.get<any[]>("/business/approvals/pending"),
    refetchInterval: 10000,
    enabled: !!activeTenant,
  });

  const pendingApprovalsCount = pendingApprovals?.length || 0;

  useEffect(() => {
    const init = async () => {
      try {
        const profile = await ApiClient.get<UserProfile>("/auth/me");
        setUser(profile);

        const tenantList = await ApiClient.get<Tenant[]>("/tenants");
        setTenants(tenantList);

        const activeId = ApiClient.getActiveTenantId();
        const found = tenantList.find((t) => t.id === activeId) ?? tenantList[0] ?? null;
        if (found) {
          setActiveTenant(found);
          ApiClient.setActiveTenantId(found.id);
        }
      } catch {
        router.push("/login");
      }
    };
    init();
  }, [router]);

  const handleLogout = async () => {
    try {
      await fetch("/api/v1/auth/logout", { 
        method: "POST",
        headers: { "bypass-tunnel-reminder": "true" }
      });
    } catch {
      // ignore
    }
    ApiClient.clearActiveTenantId();
    router.push("/login");
  };

  const handleSwitchTenant = (tenant: Tenant) => {
    ApiClient.setActiveTenantId(tenant.id);
    setActiveTenant(tenant);
    setShowCompanySwitcher(false);
    window.location.href = "/dashboard";
  };

  const isActive = (href: string) => {
    if (href === "/dashboard") return pathname === "/dashboard";
    return pathname.startsWith(href);
  };

  return (
    <div className="layout-container">
      {/* Sidebar */}
      <aside className="sidebar">
        <div className="sidebar-header">
          <h1 className="sidebar-title">{t("app.name")}</h1>
        </div>

        <nav className="sidebar-nav">
          <div className="nav-section-label">{t("nav.overview")}</div>
          <Link
            href="/dashboard"
            className={`nav-item ${isActive("/dashboard") ? "active" : ""}`}
          >
            <span>📊</span> {t("nav.dashboard")}
          </Link>

          <div className="nav-section-label">{t("nav.accounting")}</div>
          <Link
            href="/dashboard/accounting/coa"
            className={`nav-item ${isActive("/dashboard/accounting/coa") ? "active" : ""}`}
          >
            <span>📂</span> {t("nav.coa")}
          </Link>
          <Link
            href="/dashboard/accounting/periods"
            className={`nav-item ${isActive("/dashboard/accounting/periods") ? "active" : ""}`}
          >
            <span>📅</span> {t("nav.periods")}
          </Link>
          <Link
            href="/dashboard/accounting/journal-entries"
            className={`nav-item ${isActive("/dashboard/accounting/journal-entries") ? "active" : ""}`}
          >
            <span>📝</span> {t("nav.journalEntries")}
          </Link>
          <Link
            href="/dashboard/accounting/fixed-assets"
            className={`nav-item ${isActive("/dashboard/accounting/fixed-assets") ? "active" : ""}`}
          >
            <span>🏢</span> {t("nav.fixedAssets")}
          </Link>
          <Link
            href="/dashboard/accounting/reconciliation"
            className={`nav-item ${isActive("/dashboard/accounting/reconciliation") ? "active" : ""}`}
          >
            <span>🏦</span> {t("nav.bankRecon")}
          </Link>
          <Link
            href="/dashboard/ledger"
            className={`nav-item ${isActive("/dashboard/ledger") ? "active" : ""}`}
          >
            <span>📒</span> {t("nav.generalLedger")}
          </Link>

          <div className="nav-section-label">{t("nav.business")}</div>
          <Link
            href="/dashboard/approvals"
            className={`nav-item ${isActive("/dashboard/approvals") ? "active" : ""}`}
            style={{ display: "flex", justifyContent: "space-between", alignItems: "center" }}
          >
            <div style={{ display: "flex", alignItems: "center", gap: "0.625rem", flexDirection: isRTL ? "row-reverse" : "row" }}>
              <span>🔔</span> {t("nav.approvals")}
            </div>
            {pendingApprovalsCount > 0 && (
              <span
                style={{
                  background: "var(--error)",
                  color: "white",
                  fontSize: "0.7rem",
                  fontWeight: "bold",
                  padding: "2px 6px",
                  borderRadius: "10px",
                }}
              >
                {pendingApprovalsCount}
              </span>
            )}
          </Link>
          <Link
            href="/dashboard/crm"
            className={`nav-item ${isActive("/dashboard/crm") ? "active" : ""}`}
          >
            <span>🤝</span> {t("nav.crm")}
          </Link>
          <Link
            href="/dashboard/procurement"
            className={`nav-item ${isActive("/dashboard/procurement") ? "active" : ""}`}
          >
            <span>🛒</span> {t("nav.procurement")}
          </Link>
          <Link
            href="/dashboard/invoices"
            className={`nav-item ${isActive("/dashboard/invoices") ? "active" : ""}`}
          >
            <span>🧾</span> {t("nav.invoices")}
          </Link>
          <Link
            href="/dashboard/payments"
            className={`nav-item ${isActive("/dashboard/payments") ? "active" : ""}`}
          >
            <span>💸</span> {t("nav.payments")}
          </Link>
          <Link
            href="/dashboard/payroll"
            className={`nav-item ${isActive("/dashboard/payroll") ? "active" : ""}`}
          >
            <span>💳</span> {t("nav.payroll")}
          </Link>
          <Link
            href="/dashboard/hr"
            className={`nav-item ${isActive("/dashboard/hr") ? "active" : ""}`}
          >
            <span>🧑‍🤝‍🧑</span> {t("nav.hr")}
          </Link>
          <Link
            href="/dashboard/inventory"
            className={`nav-item ${isActive("/dashboard/inventory") ? "active" : ""}`}
          >
            <span>📦</span> {t("nav.inventory")}
          </Link>
          <Link
            href="/dashboard/contacts"
            className={`nav-item ${isActive("/dashboard/contacts") ? "active" : ""}`}
          >
            <span>👥</span> {t("nav.contacts")}
          </Link>

          <div className="nav-section-label">{t("nav.reportsCompliance")}</div>
          <Link
            href="/dashboard/reports"
            className={`nav-item ${isActive("/dashboard/reports") ? "active" : ""}`}
          >
            <span>📈</span> {t("nav.reports")}
          </Link>
          <Link
            href="/dashboard/compliance"
            className={`nav-item ${isActive("/dashboard/compliance") ? "active" : ""}`}
          >
            <span>⚖️</span> {t("nav.compliance")}
          </Link>

          <div className="nav-section-label">{t("nav.system")}</div>
          <Link
            href="/dashboard/users"
            className={`nav-item ${isActive("/dashboard/users") ? "active" : ""}`}
          >
            <span>👤</span> {t("nav.users")}
          </Link>
          <Link
            href="/dashboard/settings"
            className={`nav-item ${isActive("/dashboard/settings") ? "active" : ""}`}
          >
            <span>⚙️</span> {t("nav.settings")}
          </Link>
        </nav>

        <div className="sidebar-footer">
          {/* Company Switcher */}
          {tenants.length > 0 && (
            <div style={{ marginBottom: "1rem", position: "relative" }}>
              <button
                onClick={() => setShowCompanySwitcher(!showCompanySwitcher)}
                style={{
                  width: "100%",
                  background: "rgba(255,255,255,0.05)",
                  border: "1px solid rgba(255,255,255,0.1)",
                  borderRadius: "0.5rem",
                  padding: "0.5rem 0.75rem",
                  color: "var(--text-primary)",
                  cursor: "pointer",
                  textAlign: isRTL ? "right" : "left",
                  fontSize: "0.875rem",
                }}
              >
                <div style={{ fontSize: "0.75rem", color: "var(--text-tertiary)", marginBottom: "2px" }}>
                  {t("common.activeTenant")}
                </div>
                <div style={{ fontWeight: 600 }}>{activeTenant?.name ?? t("common.loading")}</div>
                {tenants.length > 1 && (
                  <div style={{ fontSize: "0.75rem", color: "var(--text-tertiary)", marginTop: "2px" }}>
                    {t("common.switchCompany")}
                  </div>
                )}
              </button>

              {showCompanySwitcher && tenants.length > 1 && (
                <div
                  style={{
                    position: "absolute",
                    bottom: "100%",
                    left: isRTL ? "auto" : 0,
                    right: isRTL ? 0 : "auto",
                    width: "100%",
                    background: "var(--bg-card)",
                    border: "1px solid rgba(255,255,255,0.1)",
                    borderRadius: "0.5rem",
                    marginBottom: "0.5rem",
                    overflow: "hidden",
                    zIndex: 100,
                  }}
                >
                  {tenants.map((tenant) => (
                    <button
                      key={tenant.id}
                      onClick={() => handleSwitchTenant(tenant)}
                      style={{
                        display: "block",
                        width: "100%",
                        padding: "0.75rem 1rem",
                        background:
                          tenant.id === activeTenant?.id
                            ? "rgba(99,102,241,0.2)"
                            : "transparent",
                        border: "none",
                        color: "var(--text-primary)",
                        cursor: "pointer",
                        textAlign: isRTL ? "right" : "left",
                        fontSize: "0.875rem",
                      }}
                    >
                      {tenant.name}
                      {tenant.id === activeTenant?.id && " ✓"}
                    </button>
                  ))}
                  <Link
                    href="/setup"
                    onClick={() => setShowCompanySwitcher(false)}
                    style={{
                      display: "block",
                      padding: "0.75rem 1rem",
                      borderTop: "1px solid rgba(255,255,255,0.1)",
                      color: "var(--accent-primary)",
                      fontSize: "0.875rem",
                      textDecoration: "none",
                      textAlign: isRTL ? "right" : "left",
                    }}
                  >
                    {t("common.addNewCompany")}
                  </Link>
                </div>
              )}
            </div>
          )}

          {/* User Profile */}
          <div className="user-profile">
            <div className="user-avatar">
              {user?.fullName?.charAt(0)?.toUpperCase() ??
                user?.email?.charAt(0)?.toUpperCase() ??
                "?"}
            </div>
            <div style={{ overflow: "hidden" }}>
              <div
                className="user-name"
                style={{
                  overflow: "hidden",
                  textOverflow: "ellipsis",
                  whiteSpace: "nowrap",
                }}
              >
                {user?.fullName ?? user?.email ?? t("common.loading")}
              </div>
              <div
                className="user-role"
                style={{
                  overflow: "hidden",
                  textOverflow: "ellipsis",
                  whiteSpace: "nowrap",
                }}
              >
                {user?.email ?? ""}
              </div>
            </div>
          </div>
          <button className="btn-logout" onClick={handleLogout}>
            {t("common.logout")}
          </button>
        </div>
      </aside>

      {/* Main Content */}
      <main className="main-content">
        {/* Top Header */}
        <header className="top-header">
          <div className="header-brand">
            <h2 className="company-name">{activeTenant?.name ?? t("common.loading")}</h2>
            <span className="tenant-badge">{t("header.activeTenant")}</span>
          </div>
          {/* Language Toggle */}
          <button
            className="lang-toggle"
            onClick={toggleLanguage}
            title={locale === "en" ? "Switch to Arabic" : "التبديل إلى الإنجليزية"}
          >
            🌐 {locale === "en" ? "العربية" : "English"}
          </button>
        </header>

        {/* Page Content */}
        <div className="page-content">{children}</div>
      </main>
    </div>
  );
}
