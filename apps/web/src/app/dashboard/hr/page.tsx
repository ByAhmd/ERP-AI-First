export default function HrPage() {
  return (
    <div className="page-container">
      <header className="page-header">
        <div>
          <h1 className="page-title">Human Resources (HR)</h1>
          <p className="page-description">
            Manage Employee Profiles, Leave Requests, and End of Service Benefits (EOSB).
          </p>
        </div>
        <div className="page-actions">
          <button className="btn btn-primary">New Employee</button>
        </div>
      </header>
      <div className="card">
        <p className="text-tertiary">HR module loaded successfully. Further UI development required to hook into backend endpoints.</p>
      </div>
    </div>
  );
}
