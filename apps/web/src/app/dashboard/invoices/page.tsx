"use client";

import { useState } from "react";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { ApiClient } from "../../../lib/api-client";
import toast from "react-hot-toast";

interface Contact {
  id: string;
  name: string;
  type: string;
}

interface Account {
  id: string;
  code: string;
  name: string;
  type: string;
}

interface InvoiceLine {
  description: string;
  quantity: number;
  unitPrice: number;
  taxRate: number;
  accountId: string;
}

interface Invoice {
  id: string;
  invoiceNumber: string;
  type: string;
  status: string;
  invoiceDate: string;
  dueDate?: string;
  totalAmount: number;
  taxAmount: number;
  contact?: Contact;
}

const statusColor: Record<string, { bg: string; color: string }> = {
  Draft: { bg: "rgba(100,116,139,0.15)", color: "#94a3b8" },
  PendingApproval: { bg: "rgba(245,158,11,0.15)", color: "#fbbf24" },
  Approved: { bg: "rgba(59,130,246,0.15)", color: "#60a5fa" },
  Paid: { bg: "rgba(16,185,129,0.15)", color: "#34d399" },
  Cancelled: { bg: "rgba(239,68,68,0.15)", color: "#f87171" },
};

export default function InvoicesPage() {
  const queryClient = useQueryClient();
  const [showForm, setShowForm] = useState(false);
  const [formData, setFormData] = useState({
    type: "SalesInvoice",
    issueDate: new Date().toISOString().split("T")[0],
    dueDate: "",
    contactId: "",
    notes: "",
  });

  const [lines, setLines] = useState<InvoiceLine[]>([
    { description: "", quantity: 1, unitPrice: 0, taxRate: 0, accountId: "" },
  ]);

  const { data: invoices, isLoading } = useQuery({
    queryKey: ["invoices"],
    queryFn: () => ApiClient.get<Invoice[]>("/business/invoices"),
  });

  const { data: contacts } = useQuery({
    queryKey: ["contacts"],
    queryFn: () => ApiClient.get<Contact[]>("/business/contacts"),
  });

  const { data: accounts } = useQuery({
    queryKey: ["accounts"],
    queryFn: () => ApiClient.get<Account[]>("/accounting/chart-of-accounts"),
  });

  const createMutation = useMutation({
    mutationFn: (data: any) => ApiClient.post("/business/invoices", data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["invoices"] });
      setShowForm(false);
      setFormData({
        type: "SalesInvoice",
        issueDate: new Date().toISOString().split("T")[0],
        dueDate: "",
        contactId: "",
        notes: "",
      });
      setLines([{ description: "", quantity: 1, unitPrice: 0, taxRate: 0, accountId: "" }]);
      toast.success("Invoice created successfully");
    },
    onError: (err: any) => {
      toast.error(err.message || "Failed to create invoice");
    },
  });

  const approveMutation = useMutation({
    mutationFn: (id: string) => ApiClient.patch(`/business/invoices/${id}/approve`),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["invoices"] });
      toast.success("Invoice approved successfully");
    },
    onError: (err: any) => {
      toast.error(err.message || "Failed to approve invoice");
    },
  });

  const handleLineChange = (index: number, field: keyof InvoiceLine, value: any) => {
    const newLines = [...lines];
    newLines[index] = { ...newLines[index], [field]: value };
    setLines(newLines);
  };

  const addLine = () => {
    setLines([...lines, { description: "", quantity: 1, unitPrice: 0, taxRate: 0, accountId: "" }]);
  };

  const removeLine = (index: number) => {
    if (lines.length > 1) {
      setLines(lines.filter((_, i) => i !== index));
    }
  };

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    createMutation.mutate({
      type: formData.type,
      issueDate: new Date(formData.issueDate).toISOString(),
      dueDate: formData.dueDate ? new Date(formData.dueDate).toISOString() : undefined,
      contactId: formData.contactId || undefined,
      notes: formData.notes || undefined,
      lines: lines.map(line => ({
        description: line.description,
        quantity: Number(line.quantity),
        unitPrice: Number(line.unitPrice),
        taxRate: Number(line.taxRate),
        accountId: line.accountId,
      }))
    });
  };

  // Calculate totals
  const subTotal = lines.reduce((sum, line) => sum + (line.quantity * line.unitPrice), 0);
  const taxTotal = lines.reduce((sum, line) => sum + (line.quantity * line.unitPrice * (line.taxRate / 100)), 0);
  const grandTotal = subTotal + taxTotal;

  return (
    <div className="animate-fade-in">
      <div className="flex justify-between items-center mb-8">
        <div>
          <h1 className="heading-1 mb-2">Invoices</h1>
          <p className="text-secondary">Manage sales and purchase invoices</p>
        </div>
        <button onClick={() => setShowForm(!showForm)} className="btn-primary">
          {showForm ? "Cancel" : "+ New Invoice"}
        </button>
      </div>

      {showForm && (
        <div className="glass-panel p-6 mb-8 animate-fade-in">
          <h2 className="heading-2 mb-6">Create Invoice</h2>
          <form onSubmit={handleSubmit}>
            <div
              style={{
                display: "grid",
                gridTemplateColumns: "repeat(2, 1fr)",
                gap: "1rem",
                marginBottom: "2rem",
              }}
            >
              <div>
                <label className="block text-sm font-medium text-secondary mb-1">Type</label>
                <select
                  value={formData.type}
                  onChange={(e) => setFormData({ ...formData, type: e.target.value })}
                  className="form-input"
                  style={{ backgroundColor: "rgba(15,23,42,0.9)" }}
                  required
                >
                  <option value="SalesInvoice">Sales Invoice</option>
                  <option value="PurchaseInvoice">Purchase Invoice</option>
                  <option value="CreditNote">Credit Note</option>
                  <option value="DebitNote">Debit Note</option>
                </select>
              </div>

              <div>
                <label className="block text-sm font-medium text-secondary mb-1">
                  Contact (Customer/Supplier)
                </label>
                <select
                  value={formData.contactId}
                  onChange={(e) => setFormData({ ...formData, contactId: e.target.value })}
                  className="form-input"
                  style={{ backgroundColor: "rgba(15,23,42,0.9)" }}
                  required
                >
                  <option value="">— Select Contact —</option>
                  {(contacts ?? []).map((c) => (
                    <option key={c.id} value={c.id}>
                      {c.name} ({c.type})
                    </option>
                  ))}
                </select>
              </div>

              <div>
                <label className="block text-sm font-medium text-secondary mb-1">Issue Date</label>
                <input
                  type="date"
                  required
                  value={formData.issueDate}
                  onChange={(e) => setFormData({ ...formData, issueDate: e.target.value })}
                  className="form-input"
                />
              </div>

              <div>
                <label className="block text-sm font-medium text-secondary mb-1">Due Date</label>
                <input
                  type="date"
                  value={formData.dueDate}
                  onChange={(e) => setFormData({ ...formData, dueDate: e.target.value })}
                  className="form-input"
                />
              </div>

              <div style={{ gridColumn: "1 / -1" }}>
                <label className="block text-sm font-medium text-secondary mb-1">Notes</label>
                <input
                  type="text"
                  value={formData.notes}
                  onChange={(e) => setFormData({ ...formData, notes: e.target.value })}
                  className="form-input"
                  placeholder="Optional notes..."
                />
              </div>
            </div>

            <h3 className="heading-2 text-lg mb-4">Line Items</h3>
            <div className="mb-6 space-y-4">
              {lines.map((line, index) => (
                <div key={index} style={{ display: "flex", gap: "1rem", alignItems: "flex-end" }}>
                  <div style={{ flex: 2 }}>
                    <label className="block text-xs text-secondary mb-1">Description</label>
                    <input
                      type="text"
                      required
                      value={line.description}
                      onChange={(e) => handleLineChange(index, "description", e.target.value)}
                      className="form-input"
                      placeholder="Item description"
                    />
                  </div>
                  <div style={{ flex: 1 }}>
                    <label className="block text-xs text-secondary mb-1">Account</label>
                    <select
                      required
                      value={line.accountId}
                      onChange={(e) => handleLineChange(index, "accountId", e.target.value)}
                      className="form-input"
                      style={{ backgroundColor: "rgba(15,23,42,0.9)" }}
                    >
                      <option value="">Select...</option>
                      {(accounts ?? [])
                        .filter((a: any) => (a.type === "Revenue" || a.type === "Expense") && (!a.children || a.children.length === 0))
                        .map((a) => (
                          <option key={a.id} value={a.id}>
                            {a.code} - {a.name}
                          </option>
                        ))}
                    </select>
                  </div>
                  <div style={{ width: "80px" }}>
                    <label className="block text-xs text-secondary mb-1">Qty</label>
                    <input
                      type="number"
                      required
                      min="1"
                      value={line.quantity}
                      onChange={(e) => handleLineChange(index, "quantity", e.target.value)}
                      className="form-input"
                    />
                  </div>
                  <div style={{ width: "100px" }}>
                    <label className="block text-xs text-secondary mb-1">Price</label>
                    <input
                      type="number"
                      required
                      min="0"
                      step="0.01"
                      value={line.unitPrice}
                      onChange={(e) => handleLineChange(index, "unitPrice", e.target.value)}
                      className="form-input"
                    />
                  </div>
                  <div style={{ width: "80px" }}>
                    <label className="block text-xs text-secondary mb-1">Tax %</label>
                    <input
                      type="number"
                      min="0"
                      step="1"
                      value={line.taxRate}
                      onChange={(e) => handleLineChange(index, "taxRate", e.target.value)}
                      className="form-input"
                    />
                  </div>
                  <button
                    type="button"
                    onClick={() => removeLine(index)}
                    disabled={lines.length === 1}
                    style={{
                      padding: "0.5rem",
                      background: "rgba(239,68,68,0.1)",
                      color: "#ef4444",
                      borderRadius: "0.375rem",
                      marginBottom: "2px",
                      opacity: lines.length === 1 ? 0.5 : 1,
                      cursor: lines.length === 1 ? "not-allowed" : "pointer"
                    }}
                  >
                    ✕
                  </button>
                </div>
              ))}
              
              <button
                type="button"
                onClick={addLine}
                style={{ fontSize: "0.875rem", color: "var(--accent-primary)", marginTop: "0.5rem" }}
              >
                + Add Line Item
              </button>
            </div>

            <div style={{ display: "flex", justifyContent: "flex-end", marginBottom: "2rem" }}>
              <div style={{ width: "300px", background: "rgba(0,0,0,0.2)", padding: "1rem", borderRadius: "0.5rem" }}>
                <div style={{ display: "flex", justifyContent: "space-between", marginBottom: "0.5rem" }}>
                  <span className="text-secondary">Subtotal:</span>
                  <span>{subTotal.toFixed(2)}</span>
                </div>
                <div style={{ display: "flex", justifyContent: "space-between", marginBottom: "0.5rem" }}>
                  <span className="text-secondary">Tax:</span>
                  <span>{taxTotal.toFixed(2)}</span>
                </div>
                <div style={{ display: "flex", justifyContent: "space-between", fontWeight: "bold", borderTop: "1px solid rgba(255,255,255,0.1)", paddingTop: "0.5rem" }}>
                  <span>Total (SAR):</span>
                  <span style={{ color: "var(--accent-primary)" }}>{grandTotal.toFixed(2)}</span>
                </div>
              </div>
            </div>

            <div className="flex justify-end gap-4">
              <button
                type="button"
                onClick={() => setShowForm(false)}
                className="btn-secondary"
              >
                Cancel
              </button>
              <button
                type="submit"
                disabled={createMutation.isPending}
                className="btn-primary"
              >
                {createMutation.isPending ? "Creating..." : "Create Invoice"}
              </button>
            </div>
          </form>
        </div>
      )}

      <div className="glass-panel overflow-hidden">
        {isLoading ? (
          <div className="p-12 text-center text-secondary">Loading invoices...</div>
        ) : !invoices || invoices.length === 0 ? (
          <div className="p-12 text-center">
            <h3 className="heading-2 mb-2">No invoices yet</h3>
            <p className="text-secondary" style={{ maxWidth: "28rem", margin: "0 auto 1.5rem" }}>
              Create your first invoice by clicking the "New Invoice" button above.
            </p>
          </div>
        ) : (
          <table className="data-table">
            <thead>
              <tr>
                <th>Invoice No.</th>
                <th>Type</th>
                <th>Contact</th>
                <th>Date</th>
                <th>Due Date</th>
                <th style={{ textAlign: "right" }}>Amount (SAR)</th>
                <th>Status</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              {invoices.map((inv) => {
                const sc = statusColor[inv.status] ?? statusColor.Draft;
                // Backend returns strings for Decimals, so parseFloat them
                const totalAmount = parseFloat(inv.totalAmount as any);
                return (
                  <tr key={inv.id}>
                    <td style={{ fontWeight: 600 }}>{inv.invoiceNumber}</td>
                    <td className="text-secondary">{inv.type.replace(/([A-Z])/g, " $1").trim()}</td>
                    <td>{inv.contact?.name ?? "—"}</td>
                    <td className="text-secondary">
                      {new Date(inv.invoiceDate || (inv as any).issueDate).toLocaleDateString("en-SA")}
                    </td>
                    <td className="text-secondary">
                      {inv.dueDate
                        ? new Date(inv.dueDate).toLocaleDateString("en-SA")
                        : "—"}
                    </td>
                    <td style={{ textAlign: "right", fontFamily: "monospace", fontWeight: 600 }}>
                      {totalAmount.toLocaleString("en-SA", { minimumFractionDigits: 2 })}
                    </td>
                    <td>
                      <span
                        style={{
                          padding: "0.2rem 0.6rem",
                          borderRadius: "0.25rem",
                          fontSize: "0.75rem",
                          fontWeight: 600,
                          background: sc.bg,
                          color: sc.color,
                        }}
                      >
                        {inv.status}
                      </span>
                    </td>
                    <td>
                      {inv.status === "Draft" && (
                        <button
                          onClick={() => approveMutation.mutate(inv.id)}
                          disabled={approveMutation.isPending}
                          style={{
                            padding: "0.25rem 0.75rem",
                            borderRadius: "0.375rem",
                            fontSize: "0.75rem",
                            fontWeight: 600,
                            background: "rgba(59,130,246,0.15)",
                            border: "1px solid rgba(59,130,246,0.3)",
                            color: "#60a5fa",
                            cursor: "pointer",
                          }}
                        >
                          Approve
                        </button>
                      )}
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        )}
      </div>
    </div>
  );
}
