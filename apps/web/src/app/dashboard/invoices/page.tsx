"use client";

import { useState } from "react";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { useRouter } from "next/navigation";
import { ApiClient } from "../../../lib/api-client";
import toast from "react-hot-toast";
import { useLanguage } from "../../../components/LanguageProvider";

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
  total: number;
  taxTotal: number;
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
  const router = useRouter();
  const queryClient = useQueryClient();
  const { t, locale } = useLanguage();
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
      toast.success(t("invoices.form.success"));
    },
    onError: (err: any) => {
      toast.error(err.message || t("invoices.form.error"));
    },
  });

  const approveMutation = useMutation({
    mutationFn: (id: string) => ApiClient.patch(`/business/invoices/${id}/approve`),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["invoices"] });
      toast.success(t("invoices.approve.success"));
    },
    onError: (err: any) => {
      toast.error(err.message || t("invoices.approve.error"));
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
          <h1 className="heading-1 mb-2">{t("invoices.title")}</h1>
          <p className="text-secondary">{t("invoices.subtitle")}</p>
        </div>
        <button onClick={() => setShowForm(!showForm)} className="btn-primary">
          {showForm ? t("common.cancel") : t("invoices.newInvoice")}
        </button>
      </div>

      {showForm && (
        <div className="glass-panel p-6 mb-8 animate-fade-in">
          <h2 className="heading-2 mb-6">{t("invoices.form.title")}</h2>
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
                <label className="block text-sm font-medium text-secondary mb-1">{t("invoices.form.type")}</label>
                <select
                  value={formData.type}
                  onChange={(e) => setFormData({ ...formData, type: e.target.value })}
                  className="form-input"
                  style={{ backgroundColor: "rgba(15,23,42,0.9)" }}
                  required
                >
                  <option value="SalesInvoice">{t("invoices.type.sales")}</option>
                  <option value="PurchaseInvoice">{t("invoices.type.purchase")}</option>
                  <option value="CreditNote">{t("invoices.type.creditNote")}</option>
                  <option value="DebitNote">{t("invoices.type.debitNote")}</option>
                </select>
              </div>

              <div>
                <label className="block text-sm font-medium text-secondary mb-1">
                  {t("invoices.form.contact")}
                </label>
                <select
                  value={formData.contactId}
                  onChange={(e) => setFormData({ ...formData, contactId: e.target.value })}
                  className="form-input"
                  style={{ backgroundColor: "rgba(15,23,42,0.9)" }}
                  required
                >
                  <option value="">{t("common.select")}</option>
                  {(contacts ?? []).map((c) => (
                    <option key={c.id} value={c.id}>
                      {c.name} ({c.type})
                    </option>
                  ))}
                </select>
              </div>

              <div>
                <label className="block text-sm font-medium text-secondary mb-1">{t("invoices.form.issueDate")}</label>
                <input
                  type="date"
                  required
                  value={formData.issueDate}
                  onChange={(e) => setFormData({ ...formData, issueDate: e.target.value })}
                  className="form-input"
                />
              </div>

              <div>
                <label className="block text-sm font-medium text-secondary mb-1">{t("invoices.form.dueDate")}</label>
                <input
                  type="date"
                  value={formData.dueDate}
                  onChange={(e) => setFormData({ ...formData, dueDate: e.target.value })}
                  className="form-input"
                />
              </div>

              <div style={{ gridColumn: "1 / -1" }}>
                <label className="block text-sm font-medium text-secondary mb-1">{t("invoices.form.notes")}</label>
                <input
                  type="text"
                  value={formData.notes}
                  onChange={(e) => setFormData({ ...formData, notes: e.target.value })}
                  className="form-input"
                  placeholder={t("invoices.form.notesPh")}
                />
              </div>
            </div>

            <h3 className="heading-2 text-lg mb-4">{t("invoices.form.lineItems")}</h3>
            <div className="mb-6 space-y-4">
              {lines.map((line, index) => (
                <div key={index} style={{ display: "flex", gap: "1rem", alignItems: "flex-end" }}>
                  <div style={{ flex: 2 }}>
                    <label className="block text-xs text-secondary mb-1">{t("invoices.form.lineDesc")}</label>
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
                    <label className="block text-xs text-secondary mb-1">{t("invoices.form.lineAccount")}</label>
                    <select
                      required
                      value={line.accountId}
                      onChange={(e) => handleLineChange(index, "accountId", e.target.value)}
                      className="form-input"
                      style={{ backgroundColor: "rgba(15,23,42,0.9)" }}
                    >
                      <option value="">{t("common.select")}</option>
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
                    <label className="block text-xs text-secondary mb-1">{t("invoices.form.lineQty")}</label>
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
                    <label className="block text-xs text-secondary mb-1">{t("invoices.form.linePrice")}</label>
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
                    <label className="block text-xs text-secondary mb-1">{t("invoices.form.lineTax")}</label>
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
                {t("invoices.form.addLine")}
              </button>
            </div>

            <div style={{ display: "flex", justifyContent: "flex-end", marginBottom: "2rem" }}>
              <div style={{ width: "300px", background: "rgba(0,0,0,0.2)", padding: "1rem", borderRadius: "0.5rem" }}>
                <div style={{ display: "flex", justifyContent: "space-between", marginBottom: "0.5rem" }}>
                  <span className="text-secondary">{t("invoices.form.subtotal")}</span>
                  <span>{subTotal.toFixed(2)}</span>
                </div>
                <div style={{ display: "flex", justifyContent: "space-between", marginBottom: "0.5rem" }}>
                  <span className="text-secondary">{t("invoices.form.tax")}</span>
                  <span>{taxTotal.toFixed(2)}</span>
                </div>
                <div style={{ display: "flex", justifyContent: "space-between", fontWeight: "bold", borderTop: "1px solid rgba(255,255,255,0.1)", paddingTop: "0.5rem" }}>
                  <span>{t("invoices.form.total")}</span>
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
                {t("common.cancel")}
              </button>
              <button
                type="submit"
                disabled={createMutation.isPending}
                className="btn-primary"
              >
                {createMutation.isPending ? t("invoices.form.submitting") : t("invoices.form.submit")}
              </button>
            </div>
          </form>
        </div>
      )}

      <div className="glass-panel overflow-hidden">
        {isLoading ? (
          <div className="p-12 text-center text-secondary">{t("invoices.loading")}</div>
        ) : !invoices || invoices.length === 0 ? (
          <div className="p-12 text-center">
            <h3 className="heading-2 mb-2">{t("invoices.noInvoices")}</h3>
            <p className="text-secondary" style={{ maxWidth: "28rem", margin: "0 auto 1.5rem" }}>
              {t("invoices.createFirst")}
            </p>
          </div>
        ) : (
          <table className="data-table">
            <thead>
              <tr>
                <th>{t("invoices.number")}</th>
                <th>{t("invoices.type")}</th>
                <th>{t("invoices.contact")}</th>
                <th>{t("invoices.date")}</th>
                <th>{t("invoices.dueDate")}</th>
                <th style={{ textAlign: "right" }}>{t("invoices.amount")}</th>
                <th>{t("invoices.status")}</th>
                <th>{t("invoices.actions")}</th>
              </tr>
            </thead>
            <tbody>
              {invoices.map((inv) => {
                const sc = statusColor[inv.status] ?? statusColor.Draft;
                // Backend returns strings for Decimals, so parseFloat them
                const totalAmount = parseFloat(inv.total as any);
                return (
                  <tr key={inv.id}>
                    <td style={{ fontWeight: 600 }}>{inv.invoiceNumber}</td>
                    <td className="text-secondary">
                      {inv.type === 'SalesInvoice' ? t('invoices.type.sales') : 
                       inv.type === 'PurchaseInvoice' ? t('invoices.type.purchase') : 
                       inv.type === 'CreditNote' ? t('invoices.type.creditNote') : 
                       inv.type === 'DebitNote' ? t('invoices.type.debitNote') : inv.type.replace(/([A-Z])/g, " $1").trim()}
                    </td>
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
                        {inv.status === 'Draft' ? t('status.draft') : 
                         inv.status === 'Approved' ? t('status.approved') : 
                         inv.status === 'PendingApproval' ? t('status.pendingApproval') : 
                         inv.status === 'Paid' ? t('status.paid') : 
                         inv.status === 'Cancelled' ? t('status.cancelled') : inv.status}
                      </span>
                    </td>
                    <td>
                      <div style={{ display: 'flex', gap: '0.5rem' }}>
                        <button
                          onClick={() => router.push(`/dashboard/invoices/${inv.id}`)}
                          style={{
                            padding: "0.25rem 0.75rem",
                            borderRadius: "0.375rem",
                            fontSize: "0.75rem",
                            fontWeight: 600,
                            background: "rgba(255,255,255,0.1)",
                            border: "1px solid rgba(255,255,255,0.2)",
                            color: "var(--text-primary)",
                            cursor: "pointer",
                          }}
                        >
                          {t("common.details")}
                        </button>
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
                            {t("common.approve")}
                          </button>
                        )}
                      </div>
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
