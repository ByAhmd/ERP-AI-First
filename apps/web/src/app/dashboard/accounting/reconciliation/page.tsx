"use client";

import { useState, useRef } from "react";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { ApiClient } from "../../../../lib/api-client";
import toast from "react-hot-toast";
import { useLanguage } from "../../../../components/LanguageProvider";

interface ParsedTransaction {
  date: string;
  amount: number;
  description: string;
  reference: string;
}

export default function BankReconciliationPage() {
  const queryClient = useQueryClient();
  const { t } = useLanguage();
  const [showUploadForm, setShowUploadForm] = useState(false);
  const [activeRecon, setActiveRecon] = useState<string | null>(null);

  const [formData, setFormData] = useState({
    accountId: "",
    statementDate: "",
    openingBalance: "",
    closingBalance: "",
  });

  const [parsedTxns, setParsedTxns] = useState<ParsedTransaction[]>([]);
  const [csvFileName, setCsvFileName] = useState<string | null>(null);
  const fileInputRef = useRef<HTMLInputElement>(null);

  const { data: reconciliations, isLoading: isReconLoading } = useQuery({
    queryKey: ["reconciliations"],
    queryFn: () => ApiClient.get<any[]>("/accounting/reconciliation"),
  });

  const { data: activeDetails, isLoading: isActiveLoading } = useQuery({
    queryKey: ["reconciliation-details", activeRecon],
    queryFn: () => ApiClient.get<any>(`/accounting/reconciliation/${activeRecon}`),
    enabled: !!activeRecon,
  });

  const { data: unreconciledLines } = useQuery({
    queryKey: ["unreconciled-lines", activeDetails?.accountId],
    queryFn: () => ApiClient.get<any[]>(`/accounting/reconciliation/account/${activeDetails.accountId}/unreconciled`),
    enabled: !!activeDetails?.accountId && activeDetails?.status !== 'Reconciled',
  });

  const { data: accounts } = useQuery({
    queryKey: ["accounts"],
    queryFn: () => ApiClient.get<any[]>("/accounting/chart-of-accounts"),
  });

  const uploadMutation = useMutation({
    mutationFn: (data: any) => ApiClient.post("/accounting/reconciliation/statement", data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["reconciliations"] });
      setShowUploadForm(false);
      setParsedTxns([]);
      setCsvFileName(null);
      setFormData({
        accountId: "",
        statementDate: "",
        openingBalance: "",
        closingBalance: "",
      });
      toast.success(t('recon.upload.success'));
    },
    onError: (err: any) => {
      toast.error(err.message || t('recon.upload.error'));
    },
  });

  const autoMatchMutation = useMutation({
    mutationFn: (id: string) => ApiClient.post(`/accounting/reconciliation/${id}/auto-match`),
    onSuccess: (data: any) => {
      queryClient.invalidateQueries({ queryKey: ["reconciliation-details", activeRecon] });
      queryClient.invalidateQueries({ queryKey: ["unreconciled-lines", activeDetails?.accountId] });
      toast.success(`${data.matchedCount} ${t('recon.match.success')}`);
    },
    onError: (err: any) => {
      toast.error(err.message || t('recon.match.error'));
    },
  });

  const manualMatchMutation = useMutation({
    mutationFn: (journalLineId: string) => ApiClient.post(`/accounting/reconciliation/${activeRecon}/manual-match`, { journalLineIds: [journalLineId] }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["reconciliation-details", activeRecon] });
      queryClient.invalidateQueries({ queryKey: ["unreconciled-lines", activeDetails?.accountId] });
      toast.success("Matched manually");
    },
    onError: (err: any) => {
      toast.error(err.message || "Failed to match");
    },
  });

  const manualUnmatchMutation = useMutation({
    mutationFn: (journalLineId: string) => ApiClient.post(`/accounting/reconciliation/${activeRecon}/manual-unmatch`, { journalLineIds: [journalLineId] }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["reconciliation-details", activeRecon] });
      queryClient.invalidateQueries({ queryKey: ["unreconciled-lines", activeDetails?.accountId] });
      toast.success("Unmatched manually");
    },
    onError: (err: any) => {
      toast.error(err.message || "Failed to unmatch");
    },
  });

  const completeMutation = useMutation({
    mutationFn: (id: string) => ApiClient.post(`/accounting/reconciliation/${id}/reconcile`),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["reconciliations"] });
      queryClient.invalidateQueries({ queryKey: ["reconciliation-details", activeRecon] });
      toast.success(t('recon.complete.success'));
      setActiveRecon(null);
    },
    onError: (err: any) => {
      toast.error(err.message || t('recon.complete.error'));
    },
  });

  const handleFileUpload = (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    if (!file) return;

    setCsvFileName(file.name);
    const reader = new FileReader();
    reader.onload = (event) => {
      const text = event.target?.result as string;
      const lines = text.split('\n').filter(l => l.trim().length > 0);
      
      const parsed: ParsedTransaction[] = [];
      let isFirstRow = true;

      for (const line of lines) {
        const cols = line.split(/,(?=(?:(?:[^"]*"){2})*[^"]*$)/).map(col => col.replace(/^"|"$/g, '').trim());
        
        if (isFirstRow) {
          isFirstRow = false;
          if (cols[0]?.toLowerCase().includes('date')) {
            continue;
          }
        }

        if (cols.length >= 2) {
          const [date, amountStr, desc, ref] = cols;
          const amount = parseFloat(amountStr.replace(/,/g, ''));
          if (!isNaN(amount)) {
            parsed.push({
              date: date,
              amount: amount,
              description: desc || 'Bank Txn',
              reference: ref || '',
            });
          }
        }
      }

      setParsedTxns(parsed);
      toast.success(`Parsed ${parsed.length} transactions from CSV`);
    };
    reader.onerror = () => {
      toast.error("Failed to read file");
    };
    reader.readAsText(file);
  };

  const handleUpload = (e: React.FormEvent) => {
    e.preventDefault();
    
    if (parsedTxns.length === 0) {
      toast.error("Please upload a valid CSV file with transactions");
      return;
    }

    uploadMutation.mutate({
      accountId: formData.accountId,
      statementDate: formData.statementDate,
      openingBalance: parseFloat(formData.openingBalance),
      closingBalance: parseFloat(formData.closingBalance),
      transactions: parsedTxns,
    });
  };

  if (activeRecon && activeDetails) {
    const matchedLedgerLines = activeDetails.journalLines?.filter((jl:any) => jl.reconciliationId === activeRecon) || [];
    
    return (
      <div className="animate-fade-in">
        <div className="flex justify-between items-center mb-6">
          <div>
            <button onClick={() => setActiveRecon(null)} className="text-secondary hover:text-white mb-2 text-sm flex items-center gap-1">
              ← {t('common.back')}
            </button>
            <h1 className="heading-1 mb-2">{t('recon.title')}: {activeDetails.bankStatement?.account?.name}</h1>
            <p className="text-secondary">{t('recon.statementDate')}: {new Date(activeDetails.bankStatement?.statementDate).toLocaleDateString()}</p>
          </div>
          <div className="flex gap-4">
            <button 
              onClick={() => autoMatchMutation.mutate(activeRecon)} 
              disabled={activeDetails.status === 'Reconciled' || autoMatchMutation.isPending}
              className="btn-secondary"
            >
              {autoMatchMutation.isPending ? t('common.saving') : t('recon.autoMatch')}
            </button>
            <button 
              onClick={() => completeMutation.mutate(activeRecon)}
              disabled={activeDetails.status === 'Reconciled' || completeMutation.isPending}
              className="btn-primary bg-green-600 hover:bg-green-500 border-none"
            >
              {completeMutation.isPending ? t('common.saving') : t('recon.complete')}
            </button>
          </div>
        </div>

        <div className="grid grid-cols-2 gap-6 mb-8">
          <div className="glass-panel p-6 border-blue-500/20">
            <h3 className="heading-3 mb-4 text-blue-400">{t('recon.details.statementTxns')}</h3>
            <div className="space-y-3">
              {activeDetails.bankStatement?.transactions?.map((t_item: any) => {
                const isMatched = activeDetails.journalLines?.some((jl: any) => 
                   jl.reconciliationId === activeRecon && 
                   parseFloat(t_item.amount) === (parseFloat(jl.debit) - parseFloat(jl.credit))
                );

                return (
                  <div key={t_item.id} className="p-3 bg-[rgba(255,255,255,0.02)] border border-[rgba(255,255,255,0.05)] rounded flex justify-between items-center">
                    <div>
                      <div className="font-semibold">{new Date(t_item.date).toLocaleDateString()}</div>
                      <div className="text-sm text-secondary">{t_item.description}</div>
                    </div>
                    <div className="flex items-center gap-4">
                      <div className={`font-mono ${parseFloat(t_item.amount) > 0 ? 'text-green-400' : 'text-red-400'}`}>
                        {parseFloat(t_item.amount) > 0 ? '+' : ''}{parseFloat(t_item.amount).toLocaleString(undefined, {minimumFractionDigits:2})}
                      </div>
                      <div className={`w-2 h-2 rounded-full ${isMatched ? 'bg-green-500' : 'bg-gray-600'}`}></div>
                    </div>
                  </div>
                );
              })}
            </div>
          </div>

          <div className="flex flex-col gap-6">
            <div className="glass-panel p-6 border-indigo-500/20">
              <h3 className="heading-3 mb-4 text-indigo-400">{t('recon.details.ledgerLines')} (Matched)</h3>
              <div className="space-y-3">
                {matchedLedgerLines.map((jl: any) => {
                  const amount = parseFloat(jl.debit) - parseFloat(jl.credit);
                  return (
                    <div key={jl.id} className="p-3 bg-[rgba(255,255,255,0.02)] border border-[rgba(99,102,241,0.2)] rounded flex justify-between items-center group">
                      <div>
                        <div className="font-semibold">{new Date(jl.createdAt).toLocaleDateString()}</div>
                        <div className="text-sm text-secondary">{jl.description || 'Journal Entry'}</div>
                      </div>
                      <div className="flex items-center gap-4">
                        <div className={`font-mono ${amount > 0 ? 'text-green-400' : 'text-red-400'}`}>
                           {amount > 0 ? '+' : ''}{amount.toLocaleString(undefined, {minimumFractionDigits:2})}
                        </div>
                        {activeDetails.status !== 'Reconciled' && (
                          <button 
                            onClick={() => manualUnmatchMutation.mutate(jl.id)}
                            className="w-6 h-6 flex items-center justify-center rounded bg-red-900/40 text-red-400 hover:bg-red-800/60 transition-colors"
                            title="Unmatch"
                          >
                            ×
                          </button>
                        )}
                        {activeDetails.status === 'Reconciled' && (
                          <div className="w-2 h-2 rounded-full bg-green-500"></div>
                        )}
                      </div>
                    </div>
                  );
                })}
                {matchedLedgerLines.length === 0 && (
                  <div className="text-secondary text-center p-8">{t('recon.noRecords')}</div>
                )}
              </div>
            </div>

            {activeDetails.status !== 'Reconciled' && unreconciledLines && unreconciledLines.length > 0 && (
              <div className="glass-panel p-6 border-yellow-500/20">
                <h3 className="heading-3 mb-4 text-yellow-400">Unreconciled Ledger Lines (Click to Match)</h3>
                <div className="space-y-3">
                  {unreconciledLines.map((jl: any) => {
                    const amount = parseFloat(jl.debit) - parseFloat(jl.credit);
                    return (
                      <div 
                        key={jl.id} 
                        onClick={() => manualMatchMutation.mutate(jl.id)}
                        className="p-3 bg-[rgba(255,255,255,0.02)] border border-[rgba(234,179,8,0.2)] rounded flex justify-between items-center cursor-pointer hover:bg-[rgba(255,255,255,0.05)] transition-colors"
                      >
                        <div>
                          <div className="font-semibold">{new Date(jl.createdAt).toLocaleDateString()}</div>
                          <div className="text-sm text-secondary">{jl.description || 'Journal Entry'}</div>
                        </div>
                        <div className="flex items-center gap-4">
                          <div className={`font-mono ${amount > 0 ? 'text-green-400' : 'text-red-400'}`}>
                             {amount > 0 ? '+' : ''}{amount.toLocaleString(undefined, {minimumFractionDigits:2})}
                          </div>
                          <div className="text-xs text-yellow-400 font-medium">Match</div>
                        </div>
                      </div>
                    );
                  })}
                </div>
              </div>
            )}
          </div>
        </div>
      </div>
    );
  }

  return (
    <div className="animate-fade-in">
      <div className="flex justify-between items-center mb-8">
        <div>
          <h1 className="heading-1 mb-2">{t('recon.title')}</h1>
          <p className="text-secondary">{t('recon.subtitle')}</p>
        </div>
        <button onClick={() => setShowUploadForm(!showUploadForm)} className="btn-primary">
          {showUploadForm ? t('common.cancel') : t('recon.uploadStatement')}
        </button>
      </div>

      {showUploadForm && (
        <div className="glass-panel p-6 mb-8 animate-fade-in">
          <h2 className="heading-2 mb-6">{t('recon.uploadStatement')}</h2>
          <form onSubmit={handleUpload}>
            <div className="grid grid-cols-2 gap-4 mb-6">
              <div>
                <label className="block text-sm font-medium text-secondary mb-1">{t('recon.form.account')} *</label>
                <select required value={formData.accountId} onChange={e => setFormData({...formData, accountId: e.target.value})} className="form-input" style={{ backgroundColor: 'rgba(15,23,42,0.9)' }}>
                  <option value="">{t('common.select')}</option>
                  {(accounts ?? []).filter((a: any) => a.type === 'Asset').map((a: any) => (
                    <option key={a.id} value={a.id}>{a.code} - {a.name}</option>
                  ))}
                </select>
              </div>
              
              <div>
                <label className="block text-sm font-medium text-secondary mb-1">{t('recon.form.date')} *</label>
                <input required type="date" value={formData.statementDate} onChange={e => setFormData({...formData, statementDate: e.target.value})} className="form-input" />
              </div>

              <div>
                <label className="block text-sm font-medium text-secondary mb-1">{t('recon.form.opening')} *</label>
                <input required type="number" step="0.01" value={formData.openingBalance} onChange={e => setFormData({...formData, openingBalance: e.target.value})} className="form-input" />
              </div>

              <div>
                <label className="block text-sm font-medium text-secondary mb-1">{t('recon.form.closing')} *</label>
                <input required type="number" step="0.01" value={formData.closingBalance} onChange={e => setFormData({...formData, closingBalance: e.target.value})} className="form-input" />
              </div>
              
              <div className="col-span-2 mt-4">
                <label className="block text-sm font-medium text-secondary mb-2">{t('recon.form.transactions')} (CSV)</label>
                <div 
                  className="border-2 border-dashed border-gray-600 rounded-lg p-8 text-center cursor-pointer hover:border-blue-500 transition-colors"
                  onClick={() => fileInputRef.current?.click()}
                >
                  <input 
                    type="file" 
                    accept=".csv" 
                    className="hidden" 
                    ref={fileInputRef}
                    onChange={handleFileUpload}
                  />
                  <div className="text-4xl mb-2">📄</div>
                  <p className="text-white font-medium mb-1">
                    {csvFileName || "Click to browse for a CSV file"}
                  </p>
                  <p className="text-secondary text-sm">
                    {csvFileName ? `${parsedTxns.length} transactions ready` : "Format: Date, Amount, Description, Reference"}
                  </p>
                </div>
              </div>

              {parsedTxns.length > 0 && (
                <div className="col-span-2 mt-4">
                  <h4 className="font-medium mb-2 text-sm text-secondary">Preview ({parsedTxns.length} transactions)</h4>
                  <div className="max-h-48 overflow-y-auto rounded border border-[rgba(255,255,255,0.1)]">
                    <table className="w-full text-sm text-left">
                      <thead className="bg-[rgba(255,255,255,0.05)] sticky top-0">
                        <tr>
                          <th className="p-2">Date</th>
                          <th className="p-2">Amount</th>
                          <th className="p-2">Description</th>
                          <th className="p-2">Reference</th>
                        </tr>
                      </thead>
                      <tbody>
                        {parsedTxns.slice(0, 5).map((txn, i) => (
                          <tr key={i} className="border-t border-[rgba(255,255,255,0.05)]">
                            <td className="p-2">{txn.date}</td>
                            <td className={`p-2 font-mono ${txn.amount > 0 ? 'text-green-400' : 'text-red-400'}`}>
                              {txn.amount > 0 ? '+' : ''}{txn.amount}
                            </td>
                            <td className="p-2">{txn.description}</td>
                            <td className="p-2">{txn.reference}</td>
                          </tr>
                        ))}
                        {parsedTxns.length > 5 && (
                          <tr className="border-t border-[rgba(255,255,255,0.05)]">
                            <td colSpan={4} className="p-2 text-center text-secondary italic">
                              ... and {parsedTxns.length - 5} more transactions
                            </td>
                          </tr>
                        )}
                      </tbody>
                    </table>
                  </div>
                </div>
              )}
            </div>
            
            <div className="flex justify-end gap-4">
              <button type="button" onClick={() => setShowUploadForm(false)} className="btn-secondary">{t('common.cancel')}</button>
              <button type="submit" disabled={uploadMutation.isPending || parsedTxns.length === 0} className="btn-primary">
                {uploadMutation.isPending ? t('common.saving') : t('recon.form.upload')}
              </button>
            </div>
          </form>
        </div>
      )}

      <div className="glass-panel overflow-hidden">
        {isReconLoading ? (
          <div className="p-12 text-center text-secondary">{t('recon.loading')}</div>
        ) : !reconciliations || reconciliations.length === 0 ? (
          <div className="p-12 text-center text-secondary">{t('recon.noRecords')}</div>
        ) : (
          <table className="data-table">
            <thead>
              <tr>
                <th>{t('common.date')}</th>
                <th>{t('recon.account')}</th>
                <th>{t('recon.statementDate')}</th>
                <th>{t('recon.status')}</th>
                <th>{t('recon.actions')}</th>
              </tr>
            </thead>
            <tbody>
              {reconciliations.map(recon => (
                <tr key={recon.id}>
                  <td className="text-secondary">{new Date(recon.createdAt).toLocaleDateString()}</td>
                  <td className="font-semibold">{recon.bankStatement?.account?.name}</td>
                  <td>{new Date(recon.bankStatement?.statementDate).toLocaleDateString()}</td>
                  <td>
                    <span className={`px-2 py-1 rounded text-xs font-semibold ${recon.status === 'Reconciled' ? 'bg-green-900/30 text-green-400' : 'bg-yellow-900/30 text-yellow-400'}`}>
                      {recon.status === 'Reconciled' ? t('status.reconciled') : recon.status === 'Draft' ? t('status.draft') : recon.status}
                    </span>
                  </td>
                  <td>
                    <button onClick={() => setActiveRecon(recon.id)} className="text-blue-400 hover:text-blue-300 text-sm font-medium">
                      {recon.status === 'Draft' ? t('common.edit') : t('common.view')}
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>
    </div>
  );
}
