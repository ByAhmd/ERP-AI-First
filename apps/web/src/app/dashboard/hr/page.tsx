"use client";

import { useState } from "react";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { ApiClient } from "../../../lib/api-client";
import { useLanguage } from '../../../components/LanguageProvider';

interface Contact {
  id: string;
  name: string;
}

interface EmployeeProfile {
  id: string;
  contactId: string;
  contact: Contact;
  gosiNumber: string;
  basicSalary: number;
  housingAllowance: number;
  transportAllowance: number;
  contractSalary: number;
  hireDate?: string;
  createdAt: string;
}

export default function HrPage() {
  const { t, locale } = useLanguage();
  const queryClient = useQueryClient();
  const [activeTab, setActiveTab] = useState('profiles');
  const [showWpsModal, setShowWpsModal] = useState(false);
  const [eosbCalc, setEosbCalc] = useState<any>(null);

  const { data: profiles = [], isLoading } = useQuery({
    queryKey: ["employee-profiles"],
    queryFn: () => ApiClient.get<EmployeeProfile[]>("/business/employee-profiles").catch(() => []),
  });

  const generateSifMutation = useMutation({
    mutationFn: (payrollRunId: string) => ApiClient.get(`/ksa-compliance/wps/sif/${payrollRunId}`),
    onSuccess: (data: any) => {
      // In a real app, this would trigger a file download
      alert("SIF File Generated Successfully!\n\nPreview:\n" + JSON.stringify(data).substring(0, 100) + "...");
      setShowWpsModal(false);
    },
    onError: (err: any) => {
      alert("Failed to generate SIF: " + err.message);
    }
  });

  const calculateEOSB = (profile: EmployeeProfile) => {
    // Calculate actual years of service
    const startDate = new Date(profile.hireDate || profile.createdAt);
    const today = new Date();
    const diffTime = Math.abs(today.getTime() - startDate.getTime());
    const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
    const years = Number((diffDays / 365.25).toFixed(2));

    const grossWage = Number(profile.basicSalary) + Number(profile.housingAllowance) + Number(profile.transportAllowance);
    
    let amount = 0;
    if (years <= 5) {
      amount = (grossWage / 2) * years;
    } else {
      amount = (grossWage / 2) * 5 + grossWage * (years - 5);
    }

    setEosbCalc({
      employee: profile.contact?.name,
      years,
      grossWage,
      amount
    });
  };

  return (
    <div className="page-container">
      <header className="page-header">
        <div>
          <h1 className="page-title">{t('hr.title')}</h1>
          <p className="page-description">{t('hr.subtitle')}</p>
        </div>
        <div className="page-actions">
          <button className="btn btn-primary" onClick={() => setShowWpsModal(true)}>
            {t('payroll.downloadWps')}
          </button>
        </div>
      </header>

      <div style={{ display: 'flex', gap: '1rem', marginBottom: '2rem', borderBottom: '1px solid rgba(255,255,255,0.1)' }}>
        <button 
          onClick={() => setActiveTab('profiles')}
          style={{ 
            background: 'none', border: 'none', padding: '0.75rem 1rem', cursor: 'pointer',
            color: activeTab === 'profiles' ? '#6366f1' : 'var(--text-secondary)',
            borderBottom: activeTab === 'profiles' ? '2px solid #6366f1' : '2px solid transparent',
            fontWeight: activeTab === 'profiles' ? 600 : 400
          }}
        >
          {t('hr.employees')}
        </button>
        <button 
          onClick={() => setActiveTab('leave')}
          style={{ 
            background: 'none', border: 'none', padding: '0.75rem 1rem', cursor: 'pointer',
            color: activeTab === 'leave' ? '#6366f1' : 'var(--text-secondary)',
            borderBottom: activeTab === 'leave' ? '2px solid #6366f1' : '2px solid transparent',
            fontWeight: activeTab === 'leave' ? 600 : 400
          }}
        >
          {t('hr.leaves')}
        </button>
        <button 
          onClick={() => setActiveTab('eosb')}
          style={{ 
            background: 'none', border: 'none', padding: '0.75rem 1rem', cursor: 'pointer',
            color: activeTab === 'eosb' ? '#6366f1' : 'var(--text-secondary)',
            borderBottom: activeTab === 'eosb' ? '2px solid #6366f1' : '2px solid transparent',
            fontWeight: activeTab === 'eosb' ? 600 : 400
          }}
        >
          {t('hr.eosb')}
        </button>
      </div>

      {showWpsModal && (
        <div className="modal-backdrop">
          <div className="modal-content" style={{ maxWidth: "450px" }}>
            <div className="modal-header">
              <h2>{t('hr.wps.modalTitle')}</h2>
              <button className="btn-close" onClick={() => setShowWpsModal(false)}>×</button>
            </div>
            <div className="modal-body">
              <p style={{ marginBottom: '1rem', color: 'var(--text-secondary)' }}>
                {t('hr.wps.modalSubtitle')}
              </p>
              
              <div className="form-group">
                <label>{t('hr.wps.runId')}</label>
                <input className="form-input" type="text" placeholder="e.g. pr_12345" id="pr-id" />
              </div>
              
              <div className="modal-actions">
                <button className="btn btn-secondary" onClick={() => setShowWpsModal(false)}>{t('common.cancel')}</button>
                <button 
                  className="btn btn-primary" 
                  onClick={() => {
                    const id = (document.getElementById('pr-id') as HTMLInputElement).value;
                    if (id) generateSifMutation.mutate(id);
                  }}
                  disabled={generateSifMutation.isPending}
                >
                  {t('hr.wps.generate')}
                </button>
              </div>
            </div>
          </div>
        </div>
      )}

      <div className="card">
        {activeTab === 'profiles' && (
          <div>
            <h2 style={{ fontSize: '1.25rem', marginBottom: '1.5rem' }}>{t('hr.employees')}</h2>
            {isLoading ? (
              <p className="text-tertiary">{t('hr.loading')}</p>
            ) : profiles.length === 0 ? (
              <p className="text-tertiary">{t('hr.noEmployees')}</p>
            ) : (
              <div className="table-responsive">
                <table className="data-table">
                  <thead>
                    <tr>
                      <th>{t('hr.employee')}</th>
                      <th>{t('hr.gosi')}</th>
                      <th style={{ textAlign: "right" }}>{t('hr.salary')}</th>
                      <th style={{ textAlign: "right" }}>{t('payroll.housing')}</th>
                      <th style={{ textAlign: "right" }}>{t('payroll.transport')}</th>
                      <th style={{ textAlign: "right" }}>{t('hr.totalWage')}</th>
                    </tr>
                  </thead>
                  <tbody>
                    {profiles.map((p: EmployeeProfile) => {
                      const total = Number(p.basicSalary) + Number(p.housingAllowance) + Number(p.transportAllowance);
                      return (
                        <tr key={p.id}>
                          <td style={{ fontWeight: 500 }}>{p.contact?.name || 'Unknown'}</td>
                          <td style={{ fontFamily: "monospace", color: 'var(--text-tertiary)' }}>{p.gosiNumber || '-'}</td>
                          <td style={{ textAlign: "right" }}>{Number(p.basicSalary).toLocaleString()}</td>
                          <td style={{ textAlign: "right" }}>{Number(p.housingAllowance).toLocaleString()}</td>
                          <td style={{ textAlign: "right" }}>{Number(p.transportAllowance).toLocaleString()}</td>
                          <td style={{ textAlign: "right", fontWeight: 600 }}>{total.toLocaleString()}</td>
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
              </div>
            )}
          </div>
        )}

        {activeTab === 'leave' && (
          <div>
            <h2 style={{ fontSize: '1.25rem', marginBottom: '1.5rem' }}>{t('hr.leaves')}</h2>
            <div style={{ textAlign: 'center', padding: '3rem', background: 'rgba(0,0,0,0.1)', borderRadius: '8px' }}>
              <span style={{ fontSize: '3rem' }}>🏖️</span>
              <p style={{ marginTop: '1rem', color: 'var(--text-secondary)' }}>Leave Requests Module</p>
              <p style={{ fontSize: '0.85rem', color: 'var(--text-tertiary)', marginTop: '0.5rem' }}>
                Track Annual, Sick, Hajj, and Iddah leave.
              </p>
            </div>
          </div>
        )}

        {activeTab === 'eosb' && (
          <div>
            <h2 style={{ fontSize: '1.25rem', marginBottom: '1.5rem' }}>{t('hr.eosb')}</h2>
            <p style={{ marginBottom: '1.5rem', color: 'var(--text-secondary)' }}>
              {t('hr.eosb.desc')}
            </p>
            
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '2rem' }}>
              <div>
                <h3 style={{ fontSize: '1rem', marginBottom: '1rem', borderBottom: '1px solid rgba(255,255,255,0.1)', paddingBottom: '0.5rem' }}>
                  Select Employee
                </h3>
                <div style={{ display: 'flex', flexDirection: 'column', gap: '0.5rem' }}>
                  {profiles.map((p: EmployeeProfile) => (
                    <button
                      key={p.id}
                      onClick={() => calculateEOSB(p)}
                      style={{
                        padding: '1rem',
                        background: 'rgba(255,255,255,0.05)',
                        border: '1px solid rgba(255,255,255,0.1)',
                        borderRadius: '0.5rem',
                        textAlign: 'left',
                        cursor: 'pointer',
                        display: 'flex',
                        justifyContent: 'space-between',
                        alignItems: 'center'
                      }}
                    >
                      <span>{p.contact?.name}</span>
                      <span style={{ color: '#6366f1' }}>Calculate →</span>
                    </button>
                  ))}
                </div>
              </div>
              
              <div>
                {eosbCalc ? (
                  <div style={{ padding: '1.5rem', background: 'rgba(99,102,241,0.1)', border: '1px solid rgba(99,102,241,0.3)', borderRadius: '0.5rem' }}>
                    <h3 style={{ fontSize: '1.1rem', marginBottom: '1rem', color: '#818cf8' }}>Liability Estimate</h3>
                    
                    <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: '0.5rem' }}>
                      <span style={{ color: 'var(--text-secondary)' }}>{t('hr.employee')}:</span>
                      <span style={{ fontWeight: 500 }}>{eosbCalc.employee}</span>
                    </div>
                    
                    <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: '0.5rem' }}>
                      <span style={{ color: 'var(--text-secondary)' }}>Years of Service:</span>
                      <span style={{ fontWeight: 500 }}>{eosbCalc.years} years</span>
                    </div>
                    
                    <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: '0.5rem' }}>
                      <span style={{ color: 'var(--text-secondary)' }}>Gross Wage (for calc):</span>
                      <span style={{ fontWeight: 500 }}>{eosbCalc.grossWage.toLocaleString()} SAR</span>
                    </div>
                    
                    <div style={{ marginTop: '1.5rem', paddingTop: '1rem', borderTop: '1px solid rgba(99,102,241,0.2)', display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                      <span style={{ fontSize: '1.1rem', fontWeight: 600 }}>Total EOSB Liability:</span>
                      <span style={{ fontSize: '1.5rem', fontWeight: 700, color: '#34d399' }}>{eosbCalc.amount.toLocaleString()} SAR</span>
                    </div>
                    
                    <button className="btn btn-primary" style={{ width: '100%', marginTop: '1.5rem' }}>
                      Accrue to General Ledger
                    </button>
                  </div>
                ) : (
                  <div style={{ height: '100%', display: 'flex', alignItems: 'center', justifyContent: 'center', border: '1px dashed rgba(255,255,255,0.2)', borderRadius: '0.5rem', color: 'var(--text-tertiary)' }}>
                    Select an employee to view calculation
                  </div>
                )}
              </div>
            </div>
          </div>
        )}
      </div>
    </div>
  );
}
