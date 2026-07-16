"use client";

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { ApiClient } from "../../lib/api-client";
import { useLanguage } from "../../components/LanguageProvider";
import toast from "react-hot-toast";

interface Tenant {
  id: string;
  name: string;
  commercialRegNo?: string;
  vatRegistrationNo?: string;
}

export default function WorkspacesPage() {
  const router = useRouter();
  const { t, isRTL } = useLanguage();
  const [tenants, setTenants] = useState<Tenant[]>([]);
  const [loading, setLoading] = useState(true);
  const [showCreateModal, setShowCreateModal] = useState(false);
  const [newTenantName, setNewTenantName] = useState("");
  const [creating, setCreating] = useState(false);

  useEffect(() => {
    fetchTenants();
  }, []);

  const fetchTenants = async () => {
    try {
      setLoading(true);
      const data = await ApiClient.get<Tenant[]>("/tenants");
      setTenants(data || []);
    } catch (err: any) {
      toast.error("Failed to load workspaces: " + err.message);
    } finally {
      setLoading(false);
    }
  };

  const handleSelectWorkspace = (tenantId: string) => {
    ApiClient.setActiveTenantId(tenantId);
    router.push("/dashboard");
  };

  const handleCreateWorkspace = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!newTenantName) return;
    
    setCreating(true);
    try {
      const tenant = await ApiClient.post<Tenant>("/tenants", {
        name: newTenantName,
      });
      toast.success("Workspace created successfully");
      setShowCreateModal(false);
      setNewTenantName("");
      handleSelectWorkspace(tenant.id);
    } catch (err: any) {
      toast.error("Failed to create workspace: " + err.message);
    } finally {
      setCreating(false);
    }
  };

  return (
    <div
      dir={isRTL ? "rtl" : "ltr"}
      style={{
        minHeight: '100vh',
        backgroundColor: 'var(--bg-primary)',
        color: 'var(--text-primary)',
        display: 'flex',
        flexDirection: 'column',
        alignItems: 'center',
        padding: '3rem 1rem'
      }}
    >
      <div style={{ width: '100%', maxWidth: '1000px' }}>
        <div style={{ textAlign: 'center', marginBottom: '3rem' }} className="animate-fade-in">
          <h1 className="heading-1">{t('workspaces.title') || 'Customer Workspaces'}</h1>
          <p style={{ color: 'var(--text-secondary)', fontSize: '1.125rem' }}>
            {t('workspaces.subtitle') || 'Select a customer workspace to manage their accounts and operations.'}
          </p>
        </div>

        {loading ? (
          <div style={{ textAlign: 'center', color: 'var(--text-tertiary)', padding: '3rem 0' }}>Loading workspaces...</div>
        ) : (
          <div style={{
            display: 'grid',
            gridTemplateColumns: 'repeat(auto-fill, minmax(300px, 1fr))',
            gap: '1.5rem',
            animation: 'fadeIn 0.4s ease-out 0.1s both'
          }}>
            {/* Create New Card */}
            <button
              onClick={() => setShowCreateModal(true)}
              style={{
                display: 'flex',
                flexDirection: 'column',
                alignItems: 'center',
                justifyContent: 'center',
                padding: '2rem',
                borderRadius: 'var(--radius-lg)',
                border: '2px dashed var(--glass-border)',
                backgroundColor: 'rgba(255,255,255,0.02)',
                cursor: 'pointer',
                transition: 'all 0.2s'
              }}
              onMouseEnter={(e) => {
                e.currentTarget.style.backgroundColor = 'rgba(255,255,255,0.05)';
                e.currentTarget.style.borderColor = 'var(--accent-primary)';
              }}
              onMouseLeave={(e) => {
                e.currentTarget.style.backgroundColor = 'rgba(255,255,255,0.02)';
                e.currentTarget.style.borderColor = 'var(--glass-border)';
              }}
            >
              <div style={{
                width: '4rem',
                height: '4rem',
                borderRadius: '50%',
                backgroundColor: 'rgba(255,255,255,0.05)',
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'center',
                marginBottom: '1rem'
              }}>
                <span style={{ fontSize: '2rem', color: 'var(--text-secondary)' }}>+</span>
              </div>
              <h3 style={{ fontSize: '1.125rem', fontWeight: 600, color: 'var(--text-primary)' }}>
                {t('workspaces.createNew') || 'Create New Customer'}
              </h3>
            </button>

            {/* Existing Tenants */}
            {tenants.map(tenant => (
              <button
                key={tenant.id}
                onClick={() => handleSelectWorkspace(tenant.id)}
                className="glass-panel"
                style={{
                  display: 'flex',
                  flexDirection: 'column',
                  alignItems: 'flex-start',
                  padding: '2rem',
                  cursor: 'pointer',
                  textAlign: isRTL ? 'right' : 'left',
                  transition: 'transform 0.2s, box-shadow 0.2s',
                  position: 'relative',
                  overflow: 'hidden'
                }}
                onMouseEnter={(e) => {
                  e.currentTarget.style.transform = 'translateY(-4px)';
                  e.currentTarget.style.boxShadow = '0 12px 40px rgba(0,0,0,0.4)';
                  const arrow = e.currentTarget.querySelector('.enter-arrow') as HTMLElement;
                  if (arrow) arrow.style.opacity = '1';
                }}
                onMouseLeave={(e) => {
                  e.currentTarget.style.transform = 'translateY(0)';
                  e.currentTarget.style.boxShadow = 'var(--glass-shadow)';
                  const arrow = e.currentTarget.querySelector('.enter-arrow') as HTMLElement;
                  if (arrow) arrow.style.opacity = '0';
                }}
              >
                <div style={{
                  width: '3rem',
                  height: '3rem',
                  borderRadius: 'var(--radius-md)',
                  backgroundColor: 'var(--accent-primary)',
                  display: 'flex',
                  alignItems: 'center',
                  justifyContent: 'center',
                  marginBottom: '1.5rem',
                  color: 'white',
                  fontWeight: 'bold',
                  fontSize: '1.25rem'
                }}>
                  {tenant.name.substring(0, 2).toUpperCase()}
                </div>
                <h3 style={{ fontSize: '1.25rem', fontWeight: 700, marginBottom: '0.5rem', color: 'white' }}>
                  {tenant.name}
                </h3>
                {tenant.commercialRegNo && (
                  <p style={{ fontSize: '0.875rem', color: 'var(--text-secondary)' }}>CR: {tenant.commercialRegNo}</p>
                )}
                {tenant.vatRegistrationNo && (
                  <p style={{ fontSize: '0.875rem', color: 'var(--text-secondary)' }}>VAT: {tenant.vatRegistrationNo}</p>
                )}
                
                <div 
                  className="enter-arrow"
                  style={{
                    marginTop: '1.5rem',
                    color: 'var(--accent-primary)',
                    fontSize: '0.875rem',
                    fontWeight: 600,
                    opacity: 0,
                    transition: 'opacity 0.2s',
                    display: 'flex',
                    alignItems: 'center',
                    gap: '0.5rem'
                  }}
                >
                  {t('workspaces.enter') || 'Enter Workspace'} {isRTL ? '←' : '→'}
                </div>
              </button>
            ))}
          </div>
        )}
      </div>

      {/* Create Modal */}
      {showCreateModal && (
        <div className="modal-backdrop">
          <div className="modal-content" style={{ maxWidth: '400px' }}>
            <div className="modal-header">
              <h2>{t('workspaces.createTitle') || 'New Customer Workspace'}</h2>
              <button className="btn-close" onClick={() => setShowCreateModal(false)}>&times;</button>
            </div>
            
            <form onSubmit={handleCreateWorkspace}>
              <div className="modal-body">
                <p style={{ color: 'var(--text-secondary)', fontSize: '0.875rem', marginBottom: '1.5rem' }}>
                  {t('workspaces.createDesc') || 'This will create an isolated environment with a strictly mapped chart of accounts.'}
                </p>
                
                <div className="form-group">
                  <label>{t('workspaces.customerName') || 'Customer Name'}</label>
                  <input
                    type="text"
                    required
                    value={newTenantName}
                    onChange={(e) => setNewTenantName(e.target.value)}
                    className="form-input"
                    placeholder="e.g. Acme Corp"
                  />
                </div>
              </div>
              
              <div className="modal-actions">
                <button
                  type="button"
                  className="btn-secondary"
                  onClick={() => setShowCreateModal(false)}
                >
                  {t('common.cancel') || 'Cancel'}
                </button>
                <button
                  type="submit"
                  className="btn-primary"
                  disabled={creating}
                >
                  {creating ? (t('common.loading') || 'Creating...') : (t('common.create') || 'Create')}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  );
}
