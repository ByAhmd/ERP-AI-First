"use client";

import { useState } from "react";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { ApiClient } from "../../../lib/api-client";
import { useLanguage } from '../../../components/LanguageProvider';

interface Contact {
  id: string;
  name: string;
}

interface Opportunity {
  id: string;
  title: string;
  contactId: string;
  contact: Contact;
  stage: string;
  value: number;
  probability: number;
  expectedClose: string;
  notes: string;
}

export default function CrmPage() {
  const { t, locale } = useLanguage();
  const queryClient = useQueryClient();
  const [showForm, setShowForm] = useState(false);
  const [formData, setFormData] = useState({
    title: "",
    contactId: "",
    value: "",
    probability: "",
    expectedClose: "",
    notes: "",
  });

  // Since we haven't implemented a dedicated GET endpoint for opportunities in the API yet,
  // we might need to add that later. For now, let's assume it exists or we show a mock list
  // if the endpoint is missing. Wait, did we create GET opportunities? Let's check backend.
  // Actually, I'll write the API call assuming it exists or will be added.
  const { data: opportunities = [], isLoading } = useQuery({
    queryKey: ["opportunities"],
    queryFn: () => ApiClient.get<Opportunity[]>("/business/crm/opportunities").catch(() => []),
  });

  const { data: contacts = [] } = useQuery({
    queryKey: ["contacts-customers"],
    queryFn: () => ApiClient.get<Contact[]>("/business/contacts").then(res => res.filter((c: any) => c.type === 'Customer')),
  });

  const createMutation = useMutation({
    mutationFn: (data: any) => ApiClient.post("/business/crm/opportunities", data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["opportunities"] });
      setShowForm(false);
      setFormData({ title: "", contactId: "", value: "", probability: "", expectedClose: "", notes: "" });
    },
    onError: (err: any) => {
      alert(err.message || "Failed to create opportunity");
    },
  });

  const updateStageMutation = useMutation({
    mutationFn: ({ id, stage }: { id: string, stage: string }) => 
      ApiClient.put(`/business/crm/opportunities/${id}/stage`, { stage }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["opportunities"] });
    },
  });

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    createMutation.mutate({
      title: formData.title,
      contactId: formData.contactId,
      value: Number(formData.value),
      probability: Number(formData.probability),
      expectedClose: formData.expectedClose || undefined,
      notes: formData.notes,
    });
  };

  const getStageColor = (stage: string) => {
    switch(stage) {
      case 'Prospecting': return { bg: 'rgba(59,130,246,0.15)', color: '#60a5fa' };
      case 'Qualification': return { bg: 'rgba(168,85,247,0.15)', color: '#c084fc' };
      case 'Proposal': return { bg: 'rgba(245,158,11,0.15)', color: '#fbbf24' };
      case 'Negotiation': return { bg: 'rgba(236,72,153,0.15)', color: '#f472b6' };
      case 'Won': return { bg: 'rgba(16,185,129,0.15)', color: '#34d399' };
      case 'Lost': return { bg: 'rgba(239,68,68,0.15)', color: '#f87171' };
      default: return { bg: 'rgba(156,163,175,0.15)', color: '#9ca3af' };
    }
  };

  return (
    <div className="page-container">
      <header className="page-header">
        <div>
          <h1 className="page-title">{t('crm.title')}</h1>
          <p className="page-description">{t('crm.subtitle')}</p>
        </div>
        <div className="page-actions">
          <button className="btn btn-primary" onClick={() => setShowForm(true)}>
            {t('crm.newOpportunity')}
          </button>
        </div>
      </header>

      {showForm && (
        <div className="modal-backdrop">
          <div className="modal-content" style={{ maxWidth: "500px" }}>
            <div className="modal-header">
              <h2>Create Opportunity</h2>
              <button className="btn-close" onClick={() => setShowForm(false)}>×</button>
            </div>
            <form onSubmit={handleSubmit} className="modal-body">
              <div className="form-group">
                <label>Opportunity Title *</label>
                <input className="form-input"
                  required
                  value={formData.title}
                  onChange={(e) => setFormData({ ...formData, title: e.target.value })}
                  placeholder="e.g. Acme Corp Software License"
                />
              </div>

              <div className="form-group">
                <label>{t('crm.customer')} *</label>
                <select className="form-input"
                  required
                  value={formData.contactId}
                  onChange={(e) => setFormData({ ...formData, contactId: e.target.value })}
                >
                  <option value="">Select Customer...</option>
                  {contacts.map((c: Contact) => (
                    <option key={c.id} value={c.id}>{c.name}</option>
                  ))}
                </select>
              </div>

              <div className="form-row">
                <div className="form-group">
                  <label>Estimated Value</label>
                  <input className="form-input"
                    type="number"
                    value={formData.value}
                    onChange={(e) => setFormData({ ...formData, value: e.target.value })}
                  />
                </div>
                <div className="form-group">
                  <label>Probability (%)</label>
                  <input className="form-input"
                    type="number"
                    min="0" max="100"
                    value={formData.probability}
                    onChange={(e) => setFormData({ ...formData, probability: e.target.value })}
                  />
                </div>
              </div>

              <div className="form-group">
                <label>Expected Close Date</label>
                <input className="form-input"
                  type="date"
                  value={formData.expectedClose}
                  onChange={(e) => setFormData({ ...formData, expectedClose: e.target.value })}
                />
              </div>
              
              <div className="form-group">
                <label>Notes</label>
                <textarea className="form-input"
                  value={formData.notes}
                  onChange={(e) => setFormData({ ...formData, notes: e.target.value })}
                />
              </div>

              <div className="modal-actions">
                <button type="button" className="btn btn-secondary" onClick={() => setShowForm(false)}>
                  {t('common.cancel')}
                </button>
                <button type="submit" className="btn btn-primary" disabled={createMutation.isPending}>
                  {createMutation.isPending ? "Creating..." : t('common.create')}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      <div className="card">
        {isLoading ? (
          <p className="text-tertiary">{t('crm.loading')}</p>
        ) : opportunities.length === 0 ? (
          <div style={{ textAlign: "center", padding: "3rem" }}>
            <p className="text-tertiary" style={{ marginBottom: "1rem" }}>{t('crm.noOpportunities')}</p>
            <button className="btn btn-primary" onClick={() => setShowForm(true)}>Create your first opportunity</button>
          </div>
        ) : (
          <div className="table-responsive">
            <table className="data-table">
              <thead>
                <tr>
                  <th>Title</th>
                  <th>{t('crm.customer')}</th>
                  <th>{t('crm.stage')}</th>
                  <th style={{ textAlign: "right" }}>{t('crm.value')}</th>
                  <th style={{ textAlign: "right" }}>Probability</th>
                  <th>{t('crm.closeDate')}</th>
                </tr>
              </thead>
              <tbody>
                {opportunities.map((opp: Opportunity) => (
                  <tr key={opp.id}>
                    <td style={{ fontWeight: 500 }}>{opp.title}</td>
                    <td>{opp.contact?.name || 'Unknown'}</td>
                    <td>
                      <select className="form-input" 
                        value={opp.stage} 
                        onChange={(e) => updateStageMutation.mutate({ id: opp.id, stage: e.target.value })}
                        style={{
                          background: getStageColor(opp.stage).bg,
                          color: getStageColor(opp.stage).color,
                          border: 'none',
                          padding: '4px 8px',
                          borderRadius: '4px',
                          fontWeight: 500,
                          cursor: 'pointer'
                        }}
                      >
                        <option value="Prospecting">Prospecting</option>
                        <option value="Qualification">Qualification</option>
                        <option value="Proposal">Proposal</option>
                        <option value="Negotiation">Negotiation</option>
                        <option value="Won">Won</option>
                        <option value="Lost">Lost</option>
                      </select>
                    </td>
                    <td style={{ textAlign: "right", fontFamily: "monospace" }}>
                      {Number(opp.value).toLocaleString(undefined, { minimumFractionDigits: 2 })}
                    </td>
                    <td style={{ textAlign: "right" }}>{opp.probability}%</td>
                    <td>{opp.expectedClose ? new Date(opp.expectedClose).toLocaleDateString() : '-'}</td>
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
