export default function ProcurementPage() {
  return (
    <div className="page-container">
      <header className="page-header">
        <div>
          <h1 className="page-title">Procurement</h1>
          <p className="page-description">
            Manage Purchase Orders (POs) and Goods Receipt Notes (GRNs).
          </p>
        </div>
        <div className="page-actions">
          <button className="btn btn-primary">New Purchase Order</button>
        </div>
      </header>
      <div className="card">
        <p className="text-tertiary">Procurement module loaded successfully. Further UI development required to hook into backend endpoints.</p>
      </div>
    </div>
  );
}
