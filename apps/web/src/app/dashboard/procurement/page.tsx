"use client";

import { useState } from "react";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { ApiClient } from "../../../lib/api-client";
import { useLanguage } from '../../../components/LanguageProvider';

interface Contact {
  id: string;
  name: string;
}

interface Item {
  id: string;
  name: string;
  code: string;
}

interface Warehouse {
  id: string;
  name: string;
}

interface PurchaseOrderLine {
  id?: string;
  itemId: string;
  description: string;
  quantity: number;
  unitPrice: number;
}

interface PurchaseOrder {
  id: string;
  poNumber: string;
  contact: Contact;
  status: string;
  issueDate: string;
  deliveryDate: string;
  totalAmount: number;
  notes: string;
  lines: PurchaseOrderLine[];
}

export default function ProcurementPage() {
  const { t, locale } = useLanguage();
  const queryClient = useQueryClient();
  const [showForm, setShowForm] = useState(false);
  const [showReceiveModal, setShowReceiveModal] = useState<string | null>(null);
  
  const [formData, setFormData] = useState({
    contactId: "",
    issueDate: new Date().toISOString().split('T')[0],
    deliveryDate: "",
    notes: "",
  });
  
  const [lines, setLines] = useState<PurchaseOrderLine[]>([
    { itemId: "", description: "", quantity: 1, unitPrice: 0 }
  ]);
  
  const [receiveWarehouseId, setReceiveWarehouseId] = useState("");

  const { data: pos = [], isLoading } = useQuery({
    queryKey: ["purchase-orders"],
    queryFn: () => ApiClient.get<PurchaseOrder[]>("/business/procurement/purchase-orders").catch(() => []),
  });

  const { data: contacts = [] } = useQuery({
    queryKey: ["contacts-suppliers"],
    queryFn: () => ApiClient.get<Contact[]>("/business/contacts").then(res => res.filter((c: any) => c.type === 'Supplier')),
  });

  const { data: items = [] } = useQuery({
    queryKey: ["items"],
    queryFn: () => ApiClient.get<Item[]>("/accounting/inventory/items").catch(() => []),
  });
  
  const { data: warehouses = [] } = useQuery({
    queryKey: ["warehouses"],
    queryFn: () => ApiClient.get<Warehouse[]>("/accounting/inventory/warehouses").catch(() => []),
  });

  const createMutation = useMutation({
    mutationFn: (data: any) => ApiClient.post("/business/procurement/purchase-orders", data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["purchase-orders"] });
      setShowForm(false);
      setFormData({ contactId: "", issueDate: new Date().toISOString().split('T')[0], deliveryDate: "", notes: "" });
      setLines([{ itemId: "", description: "", quantity: 1, unitPrice: 0 }]);
    },
    onError: (err: any) => {
      alert(err.message || "Failed to create PO");
    },
  });

  const receiveMutation = useMutation({
    mutationFn: ({ id, warehouseId }: { id: string, warehouseId: string }) => 
      ApiClient.post(`/business/procurement/purchase-orders/${id}/receive`, { warehouseId }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["purchase-orders"] });
      setShowReceiveModal(null);
      setReceiveWarehouseId("");
    },
    onError: (err: any) => {
      alert(err.message || "Failed to receive goods");
    },
  });

  const billMutation = useMutation({
    mutationFn: (id: string) => ApiClient.post(`/business/procurement/purchase-orders/${id}/bill`, {}),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["purchase-orders"] });
    },
    onError: (err: any) => {
      alert(err.message || "Failed to generate bill");
    },
  });

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    createMutation.mutate({
      ...formData,
      lines: lines.map(l => ({
        ...l,
        quantity: Number(l.quantity),
        unitPrice: Number(l.unitPrice)
      }))
    });
  };

  const getStatusColor = (status: string) => {
    switch(status) {
      case 'Draft': return { bg: 'rgba(156,163,175,0.15)', color: '#9ca3af' };
      case 'Sent': return { bg: 'rgba(59,130,246,0.15)', color: '#60a5fa' };
      case 'PartiallyReceived': return { bg: 'rgba(245,158,11,0.15)', color: '#fbbf24' };
      case 'Received': return { bg: 'rgba(16,185,129,0.15)', color: '#34d399' };
      case 'Billed': return { bg: 'rgba(168,85,247,0.15)', color: '#c084fc' };
      default: return { bg: 'transparent', color: 'inherit' };
    }
  };

  return (
    <div className="page-container">
      <header className="page-header">
        <div>
          <h1 className="page-title">{t('procurement.title')}</h1>
          <p className="page-description">{t('procurement.subtitle')}</p>
        </div>
        <div className="page-actions">
          <button className="btn btn-primary" onClick={() => setShowForm(true)}>
            {t('procurement.newPO')}
          </button>
        </div>
      </header>

      {showForm && (
        <div className="modal-backdrop">
          <div className="modal-content" style={{ maxWidth: "800px" }}>
            <div className="modal-header">
              <h2>Create Purchase Order</h2>
              <button className="btn-close" onClick={() => setShowForm(false)}>×</button>
            </div>
            <form onSubmit={handleSubmit} className="modal-body">
              <div className="form-row">
                <div className="form-group">
                  <label>{t('procurement.vendor')} *</label>
                  <select className="form-input"
                    required
                    value={formData.contactId}
                    onChange={(e) => setFormData({ ...formData, contactId: e.target.value })}
                  >
                    <option value="">Select Supplier...</option>
                    {contacts.map((c: Contact) => (
                      <option key={c.id} value={c.id}>{c.name}</option>
                    ))}
                  </select>
                </div>
                <div className="form-group">
                  <label>Issue Date *</label>
                  <input className="form-input"
                    type="date"
                    required
                    value={formData.issueDate}
                    onChange={(e) => setFormData({ ...formData, issueDate: e.target.value })}
                  />
                </div>
                <div className="form-group">
                  <label>Delivery Date</label>
                  <input className="form-input"
                    type="date"
                    value={formData.deliveryDate}
                    onChange={(e) => setFormData({ ...formData, deliveryDate: e.target.value })}
                  />
                </div>
              </div>
              
              <div style={{ marginTop: '2rem', marginBottom: '1rem' }}>
                <h3 style={{ fontSize: '1.1rem', borderBottom: '1px solid rgba(255,255,255,0.1)', paddingBottom: '0.5rem' }}>Items</h3>
                {lines.map((line, idx) => (
                  <div key={idx} style={{ display: 'flex', gap: '1rem', marginTop: '1rem', alignItems: 'end' }}>
                    <div className="form-group" style={{ flex: 2, marginBottom: 0 }}>
                      <label>Item *</label>
                      <select className="form-input"
                        required
                        value={line.itemId}
                        onChange={(e) => {
                          const newLines = [...lines];
                          newLines[idx].itemId = e.target.value;
                          setLines(newLines);
                        }}
                      >
                        <option value="">Select Item...</option>
                        {items.map((it: Item) => (
                          <option key={it.id} value={it.id}>{it.name} ({it.code})</option>
                        ))}
                      </select>
                    </div>
                    <div className="form-group" style={{ flex: 3, marginBottom: 0 }}>
                      <label>Description</label>
                      <input className="form-input"
                        value={line.description}
                        onChange={(e) => {
                          const newLines = [...lines];
                          newLines[idx].description = e.target.value;
                          setLines(newLines);
                        }}
                        placeholder="Item description"
                      />
                    </div>
                    <div className="form-group" style={{ flex: 1, marginBottom: 0 }}>
                      <label>Qty *</label>
                      <input className="form-input"
                        type="number"
                        min="1"
                        required
                        value={line.quantity}
                        onChange={(e) => {
                          const newLines = [...lines];
                          newLines[idx].quantity = e.target.value as any;
                          setLines(newLines);
                        }}
                      />
                    </div>
                    <div className="form-group" style={{ flex: 1, marginBottom: 0 }}>
                      <label>Price *</label>
                      <input className="form-input"
                        type="number"
                        min="0"
                        step="0.01"
                        required
                        value={line.unitPrice}
                        onChange={(e) => {
                          const newLines = [...lines];
                          newLines[idx].unitPrice = e.target.value as any;
                          setLines(newLines);
                        }}
                      />
                    </div>
                    <button 
                      type="button" 
                      className="btn" 
                      style={{ padding: '0.5rem' }}
                      onClick={() => setLines(lines.filter((_, i) => i !== idx))}
                    >
                      🗑️
                    </button>
                  </div>
                ))}
                
                <button 
                  type="button" 
                  className="btn" 
                  style={{ marginTop: '1rem', border: '1px dashed rgba(255,255,255,0.3)' }}
                  onClick={() => setLines([...lines, { itemId: "", description: "", quantity: 1, unitPrice: 0 }])}
                >
                  + Add Line Item
                </button>
              </div>

              <div className="form-group">
                <label>Notes</label>
                <textarea className="form-input"
                  value={formData.notes}
                  onChange={(e) => setFormData({ ...formData, notes: e.target.value })}
                  placeholder="Terms & Conditions, instructions..."
                />
              </div>

              <div className="modal-actions">
                <button type="button" className="btn btn-secondary" onClick={() => setShowForm(false)}>
                  {t('common.cancel')}
                </button>
                <button type="submit" className="btn btn-primary" disabled={createMutation.isPending || lines.length === 0}>
                  {createMutation.isPending ? "Creating..." : t('common.create')}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {showReceiveModal && (
        <div className="modal-backdrop">
          <div className="modal-content" style={{ maxWidth: "400px" }}>
            <div className="modal-header">
              <h2>Receive Goods (GRN)</h2>
              <button className="btn-close" onClick={() => setShowReceiveModal(null)}>×</button>
            </div>
            <div className="modal-body">
              <p style={{ marginBottom: '1rem', color: 'var(--text-secondary)' }}>
                Select the warehouse where these items are being received. This will automatically update inventory stock.
              </p>
              <div className="form-group">
                <label>Warehouse *</label>
                <select className="form-input"
                  value={receiveWarehouseId}
                  onChange={(e) => setReceiveWarehouseId(e.target.value)}
                >
                  <option value="">Select Warehouse...</option>
                  {warehouses.map((w: Warehouse) => (
                    <option key={w.id} value={w.id}>{w.name}</option>
                  ))}
                </select>
              </div>
              
              <div className="modal-actions">
                <button className="btn btn-secondary" onClick={() => setShowReceiveModal(null)}>{t('common.cancel')}</button>
                <button 
                  className="btn btn-primary" 
                  onClick={() => receiveMutation.mutate({ id: showReceiveModal, warehouseId: receiveWarehouseId })}
                  disabled={!receiveWarehouseId || receiveMutation.isPending}
                >
                  Confirm Receipt
                </button>
              </div>
            </div>
          </div>
        </div>
      )}

      <div className="card">
        {isLoading ? (
          <p className="text-tertiary">{t('procurement.loading')}</p>
        ) : pos.length === 0 ? (
          <div style={{ textAlign: "center", padding: "3rem" }}>
            <p className="text-tertiary" style={{ marginBottom: "1rem" }}>{t('procurement.noPOs')}</p>
            <button className="btn btn-primary" onClick={() => setShowForm(true)}>Create your first PO</button>
          </div>
        ) : (
          <div className="table-responsive">
            <table className="data-table">
              <thead>
                <tr>
                  <th>{t('procurement.poNumber')}</th>
                  <th>{t('procurement.vendor')}</th>
                  <th>{t('common.date')}</th>
                  <th>{t('procurement.status')}</th>
                  <th style={{ textAlign: "right" }}>{t('procurement.total')}</th>
                  <th style={{ textAlign: "center" }}>{t('procurement.actions')}</th>
                </tr>
              </thead>
              <tbody>
                {pos.map((po: PurchaseOrder) => (
                  <tr key={po.id}>
                    <td style={{ fontWeight: 500, fontFamily: "monospace" }}>{po.poNumber}</td>
                    <td>{po.contact?.name || 'Unknown'}</td>
                    <td>{new Date(po.issueDate).toLocaleDateString()}</td>
                    <td>
                      <span style={{
                        background: getStatusColor(po.status).bg,
                        color: getStatusColor(po.status).color,
                        padding: '4px 8px',
                        borderRadius: '4px',
                        fontSize: '0.85rem',
                        fontWeight: 600
                      }}>
                        {po.status}
                      </span>
                    </td>
                    <td style={{ textAlign: "right", fontFamily: "monospace" }}>
                      {Number(po.totalAmount).toLocaleString(undefined, { minimumFractionDigits: 2 })}
                    </td>
                    <td style={{ textAlign: "center" }}>
                      {po.status === 'Sent' && (
                        <button 
                          className="btn" 
                          style={{ fontSize: '0.85rem', padding: '0.25rem 0.5rem', background: 'rgba(16,185,129,0.2)', color: '#34d399', border: '1px solid rgba(16,185,129,0.3)' }}
                          onClick={() => setShowReceiveModal(po.id)}
                        >
                          {t('procurement.receive')}
                        </button>
                      )}
                      {po.status === 'Received' && (
                        <button 
                          className="btn" 
                          style={{ fontSize: '0.85rem', padding: '0.25rem 0.5rem', background: 'rgba(168,85,247,0.2)', color: '#c084fc', border: '1px solid rgba(168,85,247,0.3)' }}
                          onClick={() => billMutation.mutate(po.id)}
                          disabled={billMutation.isPending}
                        >
                          Generate Bill
                        </button>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>
    </div>
  );
}
