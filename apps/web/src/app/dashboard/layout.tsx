"use client";

import Link from "next/link";
import { usePathname, useRouter } from "next/navigation";
import { useEffect, useState } from "react";
import { ApiClient } from "../../lib/api-client";

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
  const [user, setUser] = useState<UserProfile | null>(null);
  const [tenants, setTenants] = useState<Tenant[]>([]);
  const [activeTenant, setActiveTenant] = useState<Tenant | null>(null);
  const [showCompanySwitcher, setShowCompanySwitcher] = useState(false);

  useEffect(() => {
    const init = async () => {
      try {
        // Fetch current user profile
        const profile = await ApiClient.get<UserProfile>("/auth/me");
        setUser(profile);

        // Fetch all tenants this user belongs to
        const tenantList = await ApiClient.get<Tenant[]>("/tenants");
        setTenants(tenantList);

        // Find which one is active
        const activeId = ApiClient.getActiveTenantId();
        const found = tenantList.find((t) => t.id === activeId) ?? tenantList[0] ?? null;
        if (found) {
          setActiveTenant(found);
          // Make sure localStorage is set
          ApiClient.setActiveTenantId(found.id);
        }
      } catch {
        // Session expired — redirect to login
        router.push("/login");
      }
    };

    init();
  }, [router]);

  const handleLogout = async () => {
    try {
      await fetch("/api/v1/auth/logout", { method: "POST" });
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
    // Reload to refresh all data with the new tenant context
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
          <h1 className="sidebar-title">ERP AI</h1>
        </div>

        <nav className="sidebar-nav">
          <div className="nav-section-label">Overview</div>
          <Link
            href="/dashboard"
            className={`nav-item ${isActive("/dashboard") ? "active" : ""}`}
          >
            <span>📊</span> Dashboard
          </Link>

          <div className="nav-section-label">Accounting</div>
          <Link
            href="/dashboard/accounting/coa"
            className={`nav-item ${isActive("/dashboard/accounting/coa") ? "active" : ""}`}
          >
            <span>📂</span> Chart of Accounts
          </Link>
          <Link
            href="/dashboard/accounting/periods"
            className={`nav-item ${isActive("/dashboard/accounting/periods") ? "active" : ""}`}
          >
            <span>📅</span> Accounting Periods
          </Link>
          <Link
            href="/dashboard/accounting/journal-entries"
            className={`nav-item ${isActive("/dashboard/accounting/journal-entries") ? "active" : ""}`}
          >
            <span>📝</span> Journal Entries
          </Link>
          <Link
            href="/dashboard/accounting/fixed-assets"
            className={`nav-item ${isActive("/dashboard/accounting/fixed-assets") ? "active" : ""}`}
          >
            <span>🏢</span> Fixed Assets
          </Link>
          <Link
            href="/dashboard/accounting/reconciliation"
            className={`nav-item ${isActive("/dashboard/accounting/reconciliation") ? "active" : ""}`}
          >
            <span>🏦</span> Bank Recon
          </Link>
          <Link
            href="/dashboard/ledger"
            className={`nav-item ${isActive("/dashboard/ledger") ? "active" : ""}`}
          >
            <span>📒</span> General Ledger
          </Link>

          <div className="nav-section-label">Business</div>
          <Link
            href="/dashboard/invoices"
            className={`nav-item ${isActive("/dashboard/invoices") ? "active" : ""}`}
          >
            <span>🧾</span> Invoices
          </Link>
          <Link
            href="/dashboard/payments"
            className={`nav-item ${isActive("/dashboard/payments") ? "active" : ""}`}
          >
            <span>💸</span> Payments
          </Link>
          <Link
            href="/dashboard/payroll"
            className={`nav-item ${isActive("/dashboard/payroll") ? "active" : ""}`}
          >
            <span>💳</span> Payroll
          </Link>
          <Link
            href="/dashboard/inventory"
            className={`nav-item ${isActive("/dashboard/inventory") ? "active" : ""}`}
          >
            <span>📦</span> Inventory
          </Link>
          <Link
            href="/dashboard/contacts"
            className={`nav-item ${isActive("/dashboard/contacts") ? "active" : ""}`}
          >
            <span>👥</span> Contacts
          </Link>

          <div className="nav-section-label">Reports & Compliance</div>
          <Link
            href="/dashboard/reports"
            className={`nav-item ${isActive("/dashboard/reports") ? "active" : ""}`}
          >
            <span>📈</span> Reports
          </Link>
          <Link
            href="/dashboard/compliance"
            className={`nav-item ${isActive("/dashboard/compliance") ? "active" : ""}`}
          >
            <span>⚖️</span> Compliance
          </Link>

          <div className="nav-section-label">System</div>
          <Link
            href="/dashboard/users"
            className={`nav-item ${isActive("/dashboard/users") ? "active" : ""}`}
          >
            <span>👤</span> Users
          </Link>
          <Link
            href="/dashboard/settings"
            className={`nav-item ${isActive("/dashboard/settings") ? "active" : ""}`}
          >
            <span>⚙️</span> Settings
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
                  textAlign: "left",
                  fontSize: "0.875rem",
                }}
              >
                <div style={{ fontSize: "0.75rem", color: "var(--text-tertiary)", marginBottom: "2px" }}>
                  Active Company
                </div>
                <div style={{ fontWeight: 600 }}>{activeTenant?.name ?? "Loading..."}</div>
                {tenants.length > 1 && (
                  <div style={{ fontSize: "0.75rem", color: "var(--text-tertiary)", marginTop: "2px" }}>
                    ↕ Switch company
                  </div>
                )}
              </button>

              {showCompanySwitcher && tenants.length > 1 && (
                <div
                  style={{
                    position: "absolute",
                    bottom: "100%",
                    left: 0,
                    right: 0,
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
                        background: tenant.id === activeTenant?.id ? "rgba(99,102,241,0.2)" : "transparent",
                        border: "none",
                        color: "var(--text-primary)",
                        cursor: "pointer",
                        textAlign: "left",
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
                    }}
                  >
                    + Add New Company
                  </Link>
                </div>
              )}
            </div>
          )}

          {/* User Profile */}
          <div className="user-profile">
            <div className="user-avatar">
              {user?.fullName?.charAt(0)?.toUpperCase() ?? user?.email?.charAt(0)?.toUpperCase() ?? "?"}
            </div>
            <div style={{ overflow: "hidden" }}>
              <div className="user-name" style={{ overflow: "hidden", textOverflow: "ellipsis", whiteSpace: "nowrap" }}>
                {user?.fullName ?? user?.email ?? "Loading..."}
              </div>
              <div className="user-role" style={{ overflow: "hidden", textOverflow: "ellipsis", whiteSpace: "nowrap" }}>
                {user?.email ?? ""}
              </div>
            </div>
          </div>
          <button className="btn-logout" onClick={handleLogout}>
            Log Out
          </button>
        </div>
      </aside>

      {/* Main Content */}
      <main className="main-content">
        {/* Top Header */}
        <header className="top-header">
          <div className="header-brand">
            <h2 className="company-name">{activeTenant?.name ?? "Loading..."}</h2>
            <span className="tenant-badge">Active Tenant</span>
          </div>
        </header>

        {/* Page Content */}
        <div className="page-content">
          {children}
        </div>
      </main>
    </div>
  );
}
