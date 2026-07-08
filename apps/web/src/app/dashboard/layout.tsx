import Link from "next/link";

export default function DashboardLayout({
  children,
}: {
  children: React.ReactNode;
}) {
  return (
    <div className="layout-container">
      {/* Sidebar */}
      <aside className="sidebar">
        <div className="sidebar-header">
          <h1 className="sidebar-title">ERP AI</h1>
        </div>
        
        <nav className="sidebar-nav">
          <Link href="/dashboard" className="nav-item active">
            Overview
          </Link>
          <Link href="/dashboard/ledger" className="nav-item">
            General Ledger
          </Link>
          <Link href="/dashboard/invoices" className="nav-item">
            Invoices
          </Link>
        </nav>

        <div className="sidebar-footer">
          <div className="user-profile">
            <div className="user-avatar">A</div>
            <div>
              <div className="user-name">Admin User</div>
              <div className="user-role">Finance Role</div>
            </div>
          </div>
          <button className="btn-logout">
            Log Out
          </button>
        </div>
      </aside>

      {/* Main Content */}
      <main className="main-content">
        {/* Top Header */}
        <header className="top-header">
          <div className="header-brand">
            <h2 className="company-name">Acme Corp Ltd.</h2>
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
