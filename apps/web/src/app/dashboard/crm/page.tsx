export default function CrmPage() {
  return (
    <div className="page-container">
      <header className="page-header">
        <div>
          <h1 className="page-title">CRM & Sales Pipeline</h1>
          <p className="page-description">
            Manage your leads, opportunities, and quotes. Convert accepted quotes to invoices.
          </p>
        </div>
        <div className="page-actions">
          <button className="btn btn-primary">New Opportunity</button>
        </div>
      </header>
      <div className="card">
        <p className="text-tertiary">CRM module loaded successfully. Further UI development required to hook into backend endpoints.</p>
      </div>
    </div>
  );
}
