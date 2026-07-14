"use client";

import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { useParams, useRouter } from "next/navigation";
import { useState } from "react";
import { ApiClient } from "../../../../lib/api-client";
import toast from "react-hot-toast";
import { useLanguage } from "../../../../components/LanguageProvider";

interface Invoice {
  id: string;
  invoiceNumber: string;
  type: string;
  status: string;
  issueDate: string;
  total: string;
  subTotal: string;
  taxTotal: string;
  zatcaStatus: string;
  zatcaQrCode: string | null;
  zatcaXml: string | null;
  zatcaHash: string | null;
  zatcaUuid: string | null;
  zatcaPih: string | null;
  contact: {
    name: string;
    email: string;
  };
  lines: Array<{
    id: string;
    description: string;
    quantity: string;
    unitPrice: string;
    taxRate: string;
    total: string;
  }>;
}

export default function InvoiceDetailPage() {
  const params = useParams();
  const router = useRouter();
  const queryClient = useQueryClient();
  const { t, locale } = useLanguage();
  const invoiceId = params.id as string;
  
  const [showXml, setShowXml] = useState(false);

  const { data: invoice, isLoading } = useQuery({
    queryKey: ["invoice", invoiceId],
    queryFn: () => ApiClient.get<Invoice>(`/business/invoices/${invoiceId}`),
  });

  const generateZatcaMutation = useMutation({
    mutationFn: () => ApiClient.post(`/business/invoices/${invoiceId}/zatca/generate`, {}),
    onSuccess: () => {
      toast.success("ZATCA Data Generated Successfully");
      queryClient.invalidateQueries({ queryKey: ["invoice", invoiceId] });
    },
    onError: (err: any) => toast.error(err.message || "Failed to generate ZATCA data")
  });

  const submitZatcaMutation = useMutation({
    mutationFn: () => ApiClient.post(`/business/invoices/${invoiceId}/zatca/submit`, {}),
    onSuccess: (data: any) => {
      toast.success(data.message || "Invoice submitted to ZATCA");
      queryClient.invalidateQueries({ queryKey: ["invoice", invoiceId] });
    },
    onError: (err: any) => toast.error(err.message || "Failed to submit to ZATCA")
  });

  if (isLoading) return <div className="p-8 text-secondary">{t("common.loading")}</div>;
  if (!invoice) return <div className="p-8 text-error">{t("common.error")}</div>;

  const isZatcaGenerated = !!invoice.zatcaXml;
  const isZatcaSubmitted = invoice.zatcaStatus !== 'NotSubmitted';

  return (
    <div className="animate-fade-in" style={{ display: 'grid', gridTemplateColumns: '1fr 350px', gap: '2rem' }}>
      <div>
        <div style={{ display: 'flex', alignItems: 'center', gap: '1rem', marginBottom: '2rem' }}>
          <button onClick={() => router.push('/dashboard/invoices')} className="btn-secondary" style={{ padding: '0.5rem 1rem' }}>
            &larr; {t("common.back")}
          </button>
          <div>
            <h1 className="heading-1 m-0">{t("invoice.detail.invoiceNo")} {invoice.invoiceNumber}</h1>
            <p className="text-secondary">{invoice.contact?.name}</p>
          </div>
          <div style={{ marginLeft: 'auto' }}>
            <span style={{
              display: 'inline-block',
              padding: '0.25rem 0.75rem',
              borderRadius: '9999px',
              fontSize: '0.875rem',
              fontWeight: 600,
              backgroundColor: invoice.status === 'Approved' ? 'rgba(16, 185, 129, 0.1)' : 'rgba(255, 255, 255, 0.1)',
              color: invoice.status === 'Approved' ? '#10b981' : 'var(--text-secondary)'
            }}>
              {invoice.status === 'Approved' ? t('status.approved') : invoice.status === 'Draft' ? t('status.draft') : invoice.status === 'PendingApproval' ? t('status.pendingApproval') : invoice.status === 'Paid' ? t('status.paid') : invoice.status === 'Cancelled' ? t('status.cancelled') : invoice.status}
            </span>
          </div>
        </div>

        <div className="glass-panel p-6 mb-6">
          <h2 className="heading-2 mb-4">{t("invoice.detail.lineItems")}</h2>
          <table className="data-table" style={{ width: '100%' }}>
            <thead>
              <tr>
                <th>{t("invoice.detail.desc")}</th>
                <th style={{ textAlign: 'right' }}>{t("invoice.detail.qty")}</th>
                <th style={{ textAlign: 'right' }}>{t("invoice.detail.price")}</th>
                <th style={{ textAlign: 'right' }}>{t("invoice.detail.taxRate")}</th>
                <th style={{ textAlign: 'right' }}>{t("invoice.detail.lineTotal")}</th>
              </tr>
            </thead>
            <tbody>
              {invoice.lines.map(line => (
                <tr key={line.id}>
                  <td>{line.description}</td>
                  <td style={{ textAlign: 'right' }}>{parseFloat(line.quantity).toString()}</td>
                  <td style={{ textAlign: 'right' }}>{parseFloat(line.unitPrice).toLocaleString('en-SA', { minimumFractionDigits: 2 })}</td>
                  <td style={{ textAlign: 'right' }}>{parseFloat(line.taxRate)}%</td>
                  <td style={{ textAlign: 'right', fontWeight: 600 }}>{parseFloat(line.total).toLocaleString('en-SA', { minimumFractionDigits: 2 })}</td>
                </tr>
              ))}
            </tbody>
          </table>
          <div style={{ display: 'flex', justifyContent: 'flex-end', marginTop: '2rem' }}>
            <div style={{ width: '300px' }}>
              <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: '0.5rem' }}>
                <span className="text-secondary">{t("common.subtotal")}</span>
                <span>{parseFloat(invoice.subTotal).toLocaleString('en-SA', { minimumFractionDigits: 2 })}</span>
              </div>
              <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: '1rem' }}>
                <span className="text-secondary">{t("common.tax")}</span>
                <span>{parseFloat(invoice.taxTotal).toLocaleString('en-SA', { minimumFractionDigits: 2 })}</span>
              </div>
              <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: '1.25rem', fontWeight: 700, paddingTop: '1rem', borderTop: '1px solid rgba(255,255,255,0.1)' }}>
                <span>{t("invoices.form.total")}</span>
                <span style={{ color: 'var(--accent-primary)' }}>{parseFloat(invoice.total).toLocaleString('en-SA', { minimumFractionDigits: 2 })}</span>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div>
        <div className="glass-panel p-6" style={{ position: 'sticky', top: '2rem' }}>
          <h2 className="heading-2 mb-4" style={{ display: 'flex', alignItems: 'center', gap: '0.5rem' }}>
            <span style={{ color: '#10b981' }}>🛡️</span> ZATCA Compliance
          </h2>
          
          <div style={{ marginBottom: '1.5rem' }}>
            <div style={{ fontSize: '0.875rem', color: 'var(--text-secondary)', marginBottom: '0.5rem' }}>{t("invoice.detail.zatcaStatus")}</div>
            <div style={{ 
              display: 'inline-block',
              padding: '0.5rem 1rem', 
              borderRadius: '0.5rem',
              fontWeight: 600,
              backgroundColor: isZatcaSubmitted ? 'rgba(16, 185, 129, 0.1)' : 'rgba(245, 158, 11, 0.1)',
              color: isZatcaSubmitted ? '#10b981' : '#f59e0b',
              width: '100%',
              textAlign: 'center'
            }}>
              {invoice.zatcaStatus}
            </div>
          </div>

          {invoice.status === 'Approved' && (
            <div style={{ display: 'flex', flexDirection: 'column', gap: '1rem' }}>
              {!isZatcaGenerated && (
                <button 
                  className="btn-primary" 
                  onClick={() => generateZatcaMutation.mutate()}
                  disabled={generateZatcaMutation.isPending}
                >
                  {generateZatcaMutation.isPending ? 'Generating...' : 'Generate ZATCA Data'}
                </button>
              )}

              {isZatcaGenerated && !isZatcaSubmitted && (
                <button 
                  className="btn-primary" 
                  style={{ background: '#10b981', color: 'white', border: 'none' }}
                  onClick={() => submitZatcaMutation.mutate()}
                  disabled={submitZatcaMutation.isPending}
                >
                  {submitZatcaMutation.isPending ? t("invoice.detail.submitting") : t("invoice.detail.submitZatca")}
                </button>
              )}
            </div>
          )}

          {invoice.status !== 'Approved' && (
            <div className="text-secondary" style={{ fontSize: '0.875rem', textAlign: 'center' }}>
              Invoice must be approved before ZATCA processing.
            </div>
          )}

          {isZatcaGenerated && (
            <div style={{ marginTop: '2rem' }}>
              <div style={{ background: '#fff', padding: '1rem', borderRadius: '0.5rem', marginBottom: '1.5rem', display: 'flex', justifyContent: 'center' }}>
                {/* Fallback mock display if we don't have a real QR code library installed */}
                <div style={{ width: '150px', height: '150px', background: 'repeating-linear-gradient(45deg, #000, #000 10px, #fff 10px, #fff 20px)' }}></div>
              </div>

              <div style={{ fontSize: '0.75rem', color: 'var(--text-secondary)', marginBottom: '1rem', wordBreak: 'break-all' }}>
                <strong>TLV QR Base64:</strong><br />
                {invoice.zatcaQrCode?.substring(0, 50)}...
              </div>
              
              <div style={{ fontSize: '0.75rem', color: 'var(--text-secondary)', marginBottom: '1rem', wordBreak: 'break-all' }}>
                <strong>Invoice Hash (SHA256):</strong><br />
                {invoice.zatcaHash}
              </div>

              <div style={{ fontSize: '0.75rem', color: 'var(--text-secondary)', marginBottom: '1rem', wordBreak: 'break-all' }}>
                <strong>ZATCA UUID:</strong><br />
                {invoice.zatcaUuid}
              </div>

              <button 
                className="btn-secondary" 
                style={{ width: '100%', fontSize: '0.875rem' }}
                onClick={() => setShowXml(!showXml)}
              >
                {showXml ? 'Hide UBL XML' : 'View UBL XML'}
              </button>

              {showXml && (
                <pre style={{ 
                  marginTop: '1rem', 
                  padding: '1rem', 
                  background: 'rgba(0,0,0,0.5)', 
                  borderRadius: '0.5rem',
                  fontSize: '0.75rem',
                  color: 'var(--text-secondary)',
                  overflowX: 'auto',
                  maxHeight: '300px'
                }}>
                  {invoice.zatcaXml}
                </pre>
              )}
            </div>
          )}
        </div>
      </div>
    </div>
  );
}
