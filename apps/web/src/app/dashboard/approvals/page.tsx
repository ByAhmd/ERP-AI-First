"use client";

import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { useState } from "react";
import { ApiClient } from "../../../lib/api-client";
import { useLanguage } from '../../../components/LanguageProvider';

interface ApprovalRequest {
  id: string;
  entityType: string;
  entityId: string;
  status: string;
  createdAt: string;
  requestedBy: {
    fullName: string;
    email: string;
  };
}

export default function ApprovalsInbox() {
  const queryClient = useQueryClient();
  const { t } = useLanguage();
  const [selectedRequest, setSelectedRequest] = useState<ApprovalRequest | null>(null);
  const [comments, setComments] = useState("");

  const { data: approvals, isLoading, error } = useQuery({
    queryKey: ["pending-approvals"],
    queryFn: () => ApiClient.get<ApprovalRequest[]>("/business/approvals/pending"),
  });

  const { data: detailsData, isLoading: isLoadingDetails } = useQuery({
    queryKey: ["approval-details", selectedRequest?.id],
    queryFn: () => ApiClient.get<any>(`/business/approvals/${selectedRequest?.id}/details`),
    enabled: !!selectedRequest,
  });

  const approveMutation = useMutation({
    mutationFn: (id: string) => ApiClient.post(`/business/approvals/${id}/approve`, { comments }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["pending-approvals"] });
      setSelectedRequest(null);
      setComments("");
    },
  });

  const rejectMutation = useMutation({
    mutationFn: (id: string) => ApiClient.post(`/business/approvals/${id}/reject`, { comments }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["pending-approvals"] });
      setSelectedRequest(null);
      setComments("");
    },
  });

  const handleApprove = () => {
    if (selectedRequest) approveMutation.mutate(selectedRequest.id);
  };

  const handleReject = () => {
    if (selectedRequest) rejectMutation.mutate(selectedRequest.id);
  };

  if (isLoading) return <div className="p-8 text-secondary">{t('approvals.loading')}</div>;
  if (error) return <div className="p-8 text-error">{t('common.error')}</div>;

  return (
    <div className="animate-fade-in" style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '2rem' }}>
      <div>
        <h1 className="heading-1">{t('approvals.title')}</h1>
        <p className="text-secondary mb-6">{t('approvals.subtitle')}</p>

        {(!approvals || approvals.length === 0) && (
          <div className="glass-panel p-8 text-center text-secondary">
            <span style={{ fontSize: '2rem', display: 'block', marginBottom: '1rem' }}>🎉</span>
            {t('approvals.noPending')}
          </div>
        )}

        <div style={{ display: 'flex', flexDirection: 'column', gap: '1rem' }}>
          {approvals?.map((req) => (
            <div 
              key={req.id} 
              className="glass-panel p-4"
              style={{ 
                cursor: 'pointer',
                borderColor: selectedRequest?.id === req.id ? 'var(--primary)' : 'var(--glass-border)'
              }}
              onClick={() => setSelectedRequest(req)}
            >
              <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: '0.5rem' }}>
                <strong style={{ color: 'var(--text-primary)' }}>{req.entityType}</strong>
                <span style={{ fontSize: '0.8rem', color: 'var(--text-tertiary)' }}>
                  {new Date(req.createdAt).toLocaleDateString()}
                </span>
              </div>
              <div style={{ fontSize: '0.875rem', color: 'var(--text-secondary)' }}>
                {t('approvals.requestedBy')}: {req.requestedBy?.fullName || req.requestedBy?.email || 'Unknown'}
              </div>
            </div>
          ))}
        </div>
      </div>

      <div>
        {selectedRequest ? (
          <div className="glass-panel p-6" style={{ position: 'sticky', top: '2rem' }}>
            <h2 className="heading-2">{t('approvals.pending')}</h2>
            
            <div style={{ marginBottom: '1.5rem', padding: '1rem', background: 'rgba(255,255,255,0.03)', borderRadius: '0.5rem' }}>
              <div style={{ marginBottom: '0.5rem' }}>
                <span style={{ color: 'var(--text-secondary)', width: '100px', display: 'inline-block' }}>{t('approvals.entityType')}:</span>
                <strong style={{ color: 'var(--text-primary)' }}>{selectedRequest.entityType}</strong>
              </div>
              <div style={{ marginBottom: '0.5rem' }}>
                <span style={{ color: 'var(--text-secondary)', width: '100px', display: 'inline-block' }}>Entity ID:</span>
                <span style={{ fontFamily: 'monospace', color: 'var(--text-primary)' }}>{selectedRequest.entityId}</span>
              </div>
              <div style={{ marginBottom: '0.5rem' }}>
                <span style={{ color: 'var(--text-secondary)', width: '100px', display: 'inline-block' }}>{t('approvals.requestedBy')}:</span>
                <span style={{ color: 'var(--text-primary)' }}>{selectedRequest.requestedBy?.fullName || selectedRequest.requestedBy?.email}</span>
              </div>
              <div style={{ marginBottom: '1rem' }}>
                <span style={{ color: 'var(--text-secondary)', width: '100px', display: 'inline-block' }}>{t('approvals.date')}:</span>
                <span style={{ color: 'var(--text-primary)' }}>{new Date(selectedRequest.createdAt).toLocaleString()}</span>
              </div>

              {isLoadingDetails && (
                <div style={{ marginTop: '1rem', color: 'var(--text-secondary)', fontSize: '0.875rem' }}>
                  {t('common.loading')}
                </div>
              )}

              {detailsData?.details && (
                <div style={{ marginTop: '1rem', paddingTop: '1rem', borderTop: '1px solid rgba(255,255,255,0.1)' }}>
                  <h3 style={{ fontSize: '0.875rem', textTransform: 'uppercase', color: 'var(--text-tertiary)', marginBottom: '0.5rem', letterSpacing: '0.05em' }}>
                    Request Details
                  </h3>
                  
                  {Object.entries(detailsData.details).map(([key, value]) => {
                    // Format keys like 'totalAmount' to 'Total Amount'
                    const formattedKey = key.replace(/([A-Z])/g, ' $1').replace(/^./, str => str.toUpperCase());
                    
                    return (
                      <div key={key} style={{ display: 'flex', justifyContent: 'space-between', marginBottom: '0.5rem', fontSize: '0.875rem' }}>
                        <span style={{ color: 'var(--text-secondary)' }}>{formattedKey}:</span>
                        <strong style={{ color: 'var(--text-primary)' }}>
                          {typeof value === 'number' ? new Intl.NumberFormat('en-US').format(value) : String(value || 'N/A')}
                        </strong>
                      </div>
                    );
                  })}
                </div>
              )}
            </div>

            <div style={{ marginBottom: '2rem' }}>
              <label style={{ display: 'block', marginBottom: '0.5rem', color: 'var(--text-secondary)' }}>
                {t('approvals.comments')}
              </label>
              <textarea 
                className="form-input" 
                rows={4} 
                placeholder="Enter a reason for approval/rejection..."
                value={comments}
                onChange={(e) => setComments(e.target.value)}
                style={{ width: '100%' }}
              />
            </div>

            <div style={{ display: 'flex', gap: '1rem' }}>
              <button 
                className="btn btn-primary" 
                style={{ flex: 1, background: '#10b981', color: '#fff', border: 'none' }}
                onClick={handleApprove}
                disabled={approveMutation.isPending || rejectMutation.isPending}
              >
                {approveMutation.isPending ? t('common.loading') : t('approvals.approve')}
              </button>
              <button 
                className="btn btn-secondary" 
                style={{ flex: 1, color: '#ef4444', borderColor: 'rgba(239,68,68,0.3)', background: 'rgba(239,68,68,0.05)' }}
                onClick={handleReject}
                disabled={approveMutation.isPending || rejectMutation.isPending}
              >
                {rejectMutation.isPending ? t('common.loading') : t('approvals.reject')}
              </button>
            </div>
          </div>
        ) : (
          <div className="glass-panel p-8 text-center text-secondary" style={{ height: '100%', display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
            Select a request from the list to review details.
          </div>
        )}
      </div>
    </div>
  );
}
