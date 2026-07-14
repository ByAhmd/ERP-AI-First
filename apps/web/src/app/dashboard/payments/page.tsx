"use client";

import { useState } from "react";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
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
  const { t, locale } = useLanguage();
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
      toast.success(t("payments.form.success"));
    },
    onError: (err: any) => {
      toast.error(err.message || t("payments.form.error"));
    },
  });

  const approveMutation = useMutation({
    mutationFn: (id: string) => ApiClient.patch(`/business/payments/${id}/approve`),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["payments"] });
      toast.success(t("payments.approve.success"));
    },
    onError: (err: any) => {
      toast.error(err.message || t("payments.approve.error"));
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
          <h1 className="heading-1 mb-2">{t("payments.title")}</h1>
          <p className="text-secondary">{t("payments.subtitle")}</p>
        </div>
        <button onClick={() => setShowForm(!showForm)} className="btn-primary">
          {showForm ? t("common.cancel") : t("payments.newPayment")}
        </button>
      </div>

      {showForm && (
        <div className="glass-panel p-6 mb-8 animate-fade-in">
          <h2 className="heading-2 mb-6">{t("payments.form.title")}</h2>
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
                <label className="block text-sm font-medium text-secondary mb-1">{t("payments.form.type")}</label>
                <select
                  value={formData.type}
                  onChange={(e) => setFormData({ ...formData, type: e.target.value })}
                  className="form-input"
                  style={{ backgroundColor: "rgba(15,23,42,0.9)" }}
                  required
                >
                  <option value="Incoming">{t("payments.type.incoming")}</option>
                  <option value="Outgoing">{t("payments.type.outgoing")}</option>
                </select>
              </div>

              <div>
                <label className="block text-sm font-medium text-secondary mb-1">
                  {t("payments.form.contact")}
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
                      {c.name} ({c.type === 'Customer' ? t('contacts.type.customer') : c.type === 'Supplier' ? t('contacts.type.supplier') : t('contacts.type.employee')})
                    </option>
                  ))}
                </select>
              </div>

              <div>
                <label className="block text-sm font-medium text-secondary mb-1">{t("payments.form.date")}</label>
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
                  {t("payments.form.account")}
                </label>
                <select
                  value={formData.accountId}
                  onChange={(e) => setFormData({ ...formData, accountId: e.target.value })}
                  className="form-input"
                  style={{ backgroundColor: "rgba(15,23,42,0.9)" }}
                  required
                >
                  <option value="">{t("common.select")}</option>
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
                  {t("payments.form.amount")}
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
                  {t("payments.form.reference")}
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
                <label className="block text-sm font-medium text-secondary mb-1">{t("payments.form.notes")}</label>
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
                {t("common.cancel")}
              </button>
              <button
                type="submit"
                disabled={createMutation.isPending}
                className="btn-primary"
              >
                {createMutation.isPending ? t("common.saving") : t("payments.form.submit")}
              </button>
            </div>
          </form>
        </div>
      )}

      <div className="glass-panel overflow-hidden">
        {isLoading ? (
          <div className="p-12 text-center text-secondary">{t("payments.loading")}</div>
        ) : !payments || payments.length === 0 ? (
          <div className="p-12 text-center">
            <h3 className="heading-2 mb-2">{t("payments.noPayments")}</h3>
            <p className="text-secondary" style={{ maxWidth: "28rem", margin: "0 auto 1.5rem" }}>
              Create your first payment by clicking the &quot;New Payment&quot; button above.
            </p>
          </div>
        ) : (
          <table className="data-table">
            <thead>
              <tr>
                <th>{t("payments.number")}</th>
                <th>{t("payments.type")}</th>
                <th>{t("payments.contact")}</th>
                <th>{t("payments.date")}</th>
                <th style={{ textAlign: "right" }}>{t("payments.amount")}</th>
                <th>{t("payments.status")}</th>
                <th>{t("payments.actions")}</th>
              </tr>
            </thead>
            <tbody>
              {payments.map((payment) => {
                const sc = statusColor[payment.status] ?? statusColor.Draft;
                const totalAmount = parseFloat(payment.amount as any);
                return (
                  <tr key={payment.id}>
                    <td style={{ fontWeight: 600 }}>{payment.paymentNumber}</td>
                    <td className="text-secondary">{payment.type === 'Incoming' ? t('payments.type.incoming') : t('payments.type.outgoing')}</td>
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
                        {payment.status === 'Draft' ? t('status.draft') : payment.status === 'Approved' ? t('status.approved') : payment.status === 'Cancelled' ? t('status.cancelled') : payment.status}
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
                          {t("payments.approve")}
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
