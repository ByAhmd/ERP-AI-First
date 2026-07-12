"use client";

import { useState } from "react";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { ApiClient } from "../../../lib/api-client";

interface Contact {
  id: string;
  name: string;
  type: "Customer" | "Supplier" | "Employee";
  email?: string;
  phone?: string;
  taxId?: string;
  commercialRegNo?: string;
  createdAt: string;
  receivableAccountId?: string;
  payableAccountId?: string;
}

interface Account {
  id: string;
  code: string;
  name: string;
  type: string;
}

const typeColor: Record<string, { bg: string; color: string }> = {
  Customer: { bg: "rgba(59,130,246,0.15)", color: "#60a5fa" },
  Supplier: { bg: "rgba(139,92,246,0.15)", color: "#a78bfa" },
  Employee: { bg: "rgba(16,185,129,0.15)", color: "#34d399" },
};

export default function ContactsPage() {
  const queryClient = useQueryClient();
  const [showForm, setShowForm] = useState(false);
  const [filterType, setFilterType] = useState<string>("all");
  const [formData, setFormData] = useState({
    name: "",
    type: "Customer",
    email: "",
    phone: "",
    taxId: "",
    commercialRegNo: "",
    receivableAccountId: "",
    payableAccountId: "",
  });

  const { data: contacts, isLoading } = useQuery({
    queryKey: ["contacts"],
    queryFn: () => ApiClient.get<Contact[]>("/business/contacts"),
  });

  const { data: accounts } = useQuery({
    queryKey: ["accounts"],
    queryFn: () => ApiClient.get<Account[]>("/accounting/chart-of-accounts"),
  });

  const createMutation = useMutation({
    mutationFn: (data: any) => ApiClient.post("/business/contacts", data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["contacts"] });
      setShowForm(false);
      setFormData({ 
        name: "", 
        type: "Customer", 
        email: "", 
        phone: "", 
        taxId: "", 
        commercialRegNo: "",
        receivableAccountId: "",
        payableAccountId: "",
      });
    },
    onError: (err: any) => {
      alert(err.message || "Failed to create contact");
    },
  });

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    createMutation.mutate({
      name: formData.name,
      type: formData.type,
      email: formData.email || undefined,
      phone: formData.phone || undefined,
      vatRegistrationNo: formData.taxId || undefined, // Backend uses vatRegistrationNo
      commercialRegNo: formData.commercialRegNo || undefined,
      receivableAccountId: formData.receivableAccountId || undefined,
      payableAccountId: formData.payableAccountId || undefined,
    });
  };

  const filtered = (contacts ?? []).filter(
    (c) => filterType === "all" || c.type === filterType
  );

  return (
    <div className="animate-fade-in">
      <div className="flex justify-between items-center mb-8">
        <div>
          <h1 className="heading-1 mb-2">Contacts</h1>
          <p className="text-secondary">Manage customers, suppliers, and employees</p>
        </div>
        <button onClick={() => setShowForm(!showForm)} className="btn-primary">
          {showForm ? "Cancel" : "+ New Contact"}
        </button>
      </div>

      {showForm && (
        <div className="glass-panel p-6 mb-8 animate-fade-in">
          <h2 className="heading-2 mb-6">Create Contact</h2>
          <form onSubmit={handleSubmit}>
            <div
              style={{ display: "grid", gridTemplateColumns: "repeat(2, 1fr)", gap: "1rem", marginBottom: "1.5rem" }}
            >
              <div>
                <label className="block text-sm font-medium text-secondary mb-1">
                  Name <span style={{ color: "var(--error)" }}>*</span>
                </label>
                <input
                  type="text"
                  required
                  value={formData.name}
                  onChange={(e) => setFormData({ ...formData, name: e.target.value })}
                  className="form-input"
                  placeholder="e.g. Acme Supplies Ltd."
                />
              </div>

              <div>
                <label className="block text-sm font-medium text-secondary mb-1">Type</label>
                <select
                  value={formData.type}
                  onChange={(e) => setFormData({ ...formData, type: e.target.value })}
                  className="form-input"
                  style={{ backgroundColor: "rgba(15,23,42,0.9)" }}
                >
                  <option value="Customer">Customer</option>
                  <option value="Supplier">Supplier</option>
                  <option value="Employee">Employee</option>
                </select>
              </div>

              <div>
                <label className="block text-sm font-medium text-secondary mb-1">Email</label>
                <input
                  type="email"
                  value={formData.email}
                  onChange={(e) => setFormData({ ...formData, email: e.target.value })}
                  className="form-input"
                  placeholder="contact@example.com"
                />
              </div>

              <div>
                <label className="block text-sm font-medium text-secondary mb-1">Phone</label>
                <input
                  type="text"
                  value={formData.phone}
                  onChange={(e) => setFormData({ ...formData, phone: e.target.value })}
                  className="form-input"
                  placeholder="+966 5X XXX XXXX"
                />
              </div>

              <div>
                <label className="block text-sm font-medium text-secondary mb-1">Tax ID (VAT)</label>
                <input
                  type="text"
                  value={formData.taxId}
                  onChange={(e) => setFormData({ ...formData, taxId: e.target.value })}
                  className="form-input"
                  placeholder="Optional"
                />
              </div>

              <div>
                <label className="block text-sm font-medium text-secondary mb-1">
                  Commercial Reg. No.
                </label>
                <input
                  type="text"
                  value={formData.commercialRegNo}
                  onChange={(e) => setFormData({ ...formData, commercialRegNo: e.target.value })}
                  className="form-input"
                  placeholder="Optional"
                />
              </div>

              <div>
                <label className="block text-sm font-medium text-secondary mb-1">
                  Accounts Receivable Account (Optional)
                </label>
                <select
                  value={formData.receivableAccountId}
                  onChange={e => setFormData({...formData, receivableAccountId: e.target.value})}
                  className="form-input"
                  style={{ backgroundColor: 'rgba(15,23,42,0.9)' }}
                  disabled={!accounts || accounts.filter((a: any) => a.type === 'Asset' && (!a.children || a.children.length === 0)).length === 0}
                >
                  <option value="">Select an account...</option>
                  {(accounts ?? []).filter((a: any) => a.type === 'Asset' && (!a.children || a.children.length === 0)).map(a => (
                    <option key={a.id} value={a.id}>{a.code} - {a.name}</option>
                  ))}
                </select>
                {(!accounts || accounts.filter((a: any) => a.type === 'Asset' && (!a.children || a.children.length === 0)).length === 0) && (
                  <p className="text-xs text-error mt-1">No Asset posting accounts found (Seed Chart of Accounts first)</p>
                )}
              </div>

              <div>
                <label className="block text-sm font-medium text-secondary mb-1">
                  Accounts Payable Account (Optional)
                </label>
                <select
                  value={formData.payableAccountId}
                  onChange={e => setFormData({...formData, payableAccountId: e.target.value})}
                  className="form-input"
                  style={{ backgroundColor: 'rgba(15,23,42,0.9)' }}
                  disabled={!accounts || accounts.filter((a: any) => a.type === 'Liability' && (!a.children || a.children.length === 0)).length === 0}
                >
                  <option value="">Select an account...</option>
                  {(accounts ?? []).filter((a: any) => a.type === 'Liability' && (!a.children || a.children.length === 0)).map(a => (
                    <option key={a.id} value={a.id}>{a.code} - {a.name}</option>
                  ))}
                </select>
                {(!accounts || accounts.filter((a: any) => a.type === 'Liability' && (!a.children || a.children.length === 0)).length === 0) && (
                  <p className="text-xs text-error mt-1">No Liability posting accounts found (Seed Chart of Accounts first)</p>
                )}
              </div>
            </div>

            <div className="flex justify-end gap-4">
              <button type="button" onClick={() => setShowForm(false)} className="btn-secondary">
                Cancel
              </button>
              <button type="submit" disabled={createMutation.isPending} className="btn-primary">
                {createMutation.isPending ? "Creating..." : "Create Contact"}
              </button>
            </div>
          </form>
        </div>
      )}

      {/* Filter */}
      <div className="flex gap-2 mb-6">
        {["all", "Customer", "Supplier", "Employee"].map((type) => (
          <button
            key={type}
            onClick={() => setFilterType(type)}
            style={{
              padding: "0.375rem 0.875rem",
              borderRadius: "9999px",
              fontSize: "0.875rem",
              fontWeight: 500,
              border: "1px solid",
              borderColor: filterType === type ? "var(--accent-primary)" : "var(--glass-border)",
              background: filterType === type ? "rgba(59,130,246,0.15)" : "transparent",
              color: filterType === type ? "var(--accent-primary)" : "var(--text-secondary)",
              cursor: "pointer",
              transition: "all 0.2s",
            }}
          >
            {type === "all" ? "All" : type + "s"}
          </button>
        ))}
      </div>

      <div className="glass-panel overflow-hidden">
        {isLoading ? (
          <div className="p-12 text-center text-secondary">Loading contacts...</div>
        ) : filtered.length === 0 ? (
          <div className="p-12 text-center">
            <h3 className="heading-2 mb-2">No contacts found</h3>
            <p className="text-secondary" style={{ maxWidth: "28rem", margin: "0 auto" }}>
              Add your first customer or supplier to start issuing invoices.
            </p>
          </div>
        ) : (
          <table className="data-table">
            <thead>
              <tr>
                <th>Name</th>
                <th>Type</th>
                <th>Email</th>
                <th>Phone</th>
                <th>Tax ID</th>
                <th>Added</th>
              </tr>
            </thead>
            <tbody>
              {filtered.map((contact) => {
                const tc = typeColor[contact.type] ?? typeColor.Customer;
                return (
                  <tr key={contact.id}>
                    <td style={{ fontWeight: 600 }}>{contact.name}</td>
                    <td>
                      <span
                        style={{
                          padding: "0.2rem 0.6rem",
                          borderRadius: "0.25rem",
                          fontSize: "0.75rem",
                          fontWeight: 600,
                          background: tc.bg,
                          color: tc.color,
                        }}
                      >
                        {contact.type}
                      </span>
                    </td>
                    <td className="text-secondary">{contact.email ?? "—"}</td>
                    <td className="text-secondary">{contact.phone ?? "—"}</td>
                    <td className="text-secondary">{contact.taxId ?? "—"}</td>
                    <td className="text-secondary">
                      {new Date(contact.createdAt).toLocaleDateString("en-SA")}
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
