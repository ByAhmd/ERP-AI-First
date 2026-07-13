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

interface Payment {
  id: string;
  paymentNumber: string;
  type: "Incoming" | "Outgoing";
  status: string;
  paymentDate: string;
  amount: string;
  contact?: Contact;
}

const statusColor: Record<string, { bg: string; color: string }> = {
  Draft: { bg: "rgba(100,116,139,0.15)", color: "#94a3b8" },
  Approved: { bg: "rgba(16,185,129,0.15)", color: "#34d399" },
  Cancelled: { bg: "rgba(239,68,68,0.15)", color: "#f87171" },
};

export default function PaymentsPage() {
  const queryClient = useQueryClient();
  const [showForm, setShowForm] = useState(false);
  const [formData, setFormData] = useState({
    type: "Incoming",
    paymentDate: new Date().toISOString().split("T")[0],
    contactId: "",
    amount: "",
    accountId: "",
    notes: "",
    reference: "",
  });

  const { data: payments, isLoading } = useQuery({
    queryKey: ["payments"],
    queryFn: () => ApiClient.get<Payment[]>("/business/payments"),
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
    mutationFn: (data: any) => ApiClient.post("/business/payments", data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["payments"] });
      setShowForm(false);
      setFormData({
        type: "Incoming",
        paymentDate: new Date().toISOString().split("T")[0],
        contactId: "",
        amount: "",
        accountId: "",
        notes: "",
        reference: "",
      });
      toast.success("Payment created successfully");
    },
    onError: (err: any) => {
      toast.error(err.message || "Failed to create payment");
    },
  });

  const approveMutation = useMutation({
    mutationFn: (id: string) => ApiClient.patch(`/business/payments/${id}/approve`),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["payments"] });
      toast.success("Payment approved successfully");
    },
    onError: (err: any) => {
      toast.error(err.message || "Failed to approve payment");
    },
  });

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    createMutation.mutate({
      type: formData.type,
      paymentDate: new Date(formData.paymentDate).toISOString(),
      contactId: formData.contactId || undefined,
      amount: parseFloat(formData.amount),
      accountId: formData.accountId,
      notes: formData.notes || undefined,
      reference: formData.reference || undefined,
    });
  };

  return (
    <div className="animate-fade-in">
      <div className="flex justify-between items-center mb-8">
        <div>
          <h1 className="heading-1 mb-2">Payments</h1>
          <p className="text-secondary">Manage incoming and outgoing payments</p>
        </div>
        <button onClick={() => setShowForm(!showForm)} className="btn-primary">
          {showForm ? "Cancel" : "+ New Payment"}
        </button>
      </div>

      {showForm && (
        <div className="glass-panel p-6 mb-8 animate-fade-in">
          <h2 className="heading-2 mb-6">Create Payment</h2>
          <form onSubmit={handleSubmit}>
            <div
              style={{
                display: "grid",
                gridTemplateColumns: "repeat(2, 1fr)",
                gap: "1rem",
                marginBottom: "1.5rem",
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
                  <option value="Incoming">Incoming (Receipt)</option>
                  <option value="Outgoing">Outgoing (Payment)</option>
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
                <label className="block text-sm font-medium text-secondary mb-1">Payment Date</label>
                <input
                  type="date"
                  required
                  value={formData.paymentDate}
                  onChange={(e) => setFormData({ ...formData, paymentDate: e.target.value })}
                  className="form-input"
                />
              </div>

              <div>
                <label className="block text-sm font-medium text-secondary mb-1">
                  Bank / Cash Account
                </label>
                <select
                  value={formData.accountId}
                  onChange={(e) => setFormData({ ...formData, accountId: e.target.value })}
                  className="form-input"
                  style={{ backgroundColor: "rgba(15,23,42,0.9)" }}
                  required
                >
                  <option value="">— Select Account —</option>
                  {(accounts ?? [])
                    .filter((a: any) => a.type === "Asset" && (!a.children || a.children.length === 0))
                    .map((a: any) => (
                      <option key={a.id} value={a.id}>
                        {a.code} - {a.name}
                      </option>
                    ))}
                </select>
              </div>

              <div>
                <label className="block text-sm font-medium text-secondary mb-1">
                  Amount (SAR)
                </label>
                <input
                  type="number"
                  required
                  min="0"
                  step="0.01"
                  value={formData.amount}
                  onChange={(e) => setFormData({ ...formData, amount: e.target.value })}
                  className="form-input"
                  placeholder="0.00"
                />
              </div>

              <div>
                <label className="block text-sm font-medium text-secondary mb-1">
                  Reference No. (Cheque / Transfer ID)
                </label>
                <input
                  type="text"
                  value={formData.reference}
                  onChange={(e) => setFormData({ ...formData, reference: e.target.value })}
                  className="form-input"
                  placeholder="Optional"
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
                {createMutation.isPending ? "Creating..." : "Create Payment"}
              </button>
            </div>
          </form>
        </div>
      )}

      <div className="glass-panel overflow-hidden">
        {isLoading ? (
          <div className="p-12 text-center text-secondary">Loading payments...</div>
        ) : !payments || payments.length === 0 ? (
          <div className="p-12 text-center">
            <h3 className="heading-2 mb-2">No payments yet</h3>
            <p className="text-secondary" style={{ maxWidth: "28rem", margin: "0 auto 1.5rem" }}>
              Create your first payment by clicking the "New Payment" button above.
            </p>
          </div>
        ) : (
          <table className="data-table">
            <thead>
              <tr>
                <th>Payment No.</th>
                <th>Type</th>
                <th>Contact</th>
                <th>Date</th>
                <th style={{ textAlign: "right" }}>Amount (SAR)</th>
                <th>Status</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              {payments.map((payment) => {
                const sc = statusColor[payment.status] ?? statusColor.Draft;
                const totalAmount = parseFloat(payment.amount as any);
                return (
                  <tr key={payment.id}>
                    <td style={{ fontWeight: 600 }}>{payment.paymentNumber}</td>
                    <td className="text-secondary">{payment.type}</td>
                    <td>{payment.contact?.name ?? "—"}</td>
                    <td className="text-secondary">
                      {new Date(payment.paymentDate).toLocaleDateString("en-SA")}
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
                        {payment.status}
                      </span>
                    </td>
                    <td>
                      {payment.status === "Draft" && (
                        <button
                          onClick={() => approveMutation.mutate(payment.id)}
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
