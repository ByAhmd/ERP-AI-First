"use client";

import { useState } from "react";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { ApiClient } from "../../../../lib/api-client";
import toast from "react-hot-toast";

export default function BankReconciliationPage() {
  const queryClient = useQueryClient();
  const [showUploadForm, setShowUploadForm] = useState(false);
  const [activeRecon, setActiveRecon] = useState<string | null>(null);

  const [formData, setFormData] = useState({
    accountId: "",
    statementDate: "",
    openingBalance: "",
    closingBalance: "",
    transactionsRaw: "", // simple text area for MVP: date,amount,ref
  });

  const { data: reconciliations, isLoading: isReconLoading } = useQuery({
    queryKey: ["reconciliations"],
    queryFn: () => ApiClient.get<any[]>("/accounting/reconciliation"),
  });

  const { data: activeDetails, isLoading: isActiveLoading } = useQuery({
    queryKey: ["reconciliation-details", activeRecon],
    queryFn: () => ApiClient.get<any>(`/accounting/reconciliation/${activeRecon}`),
    enabled: !!activeRecon,
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
      toast.success("Bank Statement uploaded successfully");
    },
    onError: (err: any) => {
      toast.error(err.message || "Failed to upload statement");
    },
  });

  const autoMatchMutation = useMutation({
    mutationFn: (id: string) => ApiClient.post(`/accounting/reconciliation/${id}/auto-match`),
    onSuccess: (data: any) => {
      queryClient.invalidateQueries({ queryKey: ["reconciliation-details", activeRecon] });
      toast.success(`Auto-matched ${data.matchedCount} transactions!`);
    },
    onError: (err: any) => {
      toast.error(err.message || "Auto-match failed");
    },
  });

  const completeMutation = useMutation({
    mutationFn: (id: string) => ApiClient.post(`/accounting/reconciliation/${id}/reconcile`),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["reconciliations"] });
      queryClient.invalidateQueries({ queryKey: ["reconciliation-details", activeRecon] });
      toast.success("Reconciliation completed!");
      setActiveRecon(null);
    },
    onError: (err: any) => {
      toast.error(err.message || "Failed to complete reconciliation");
    },
  });

  const handleUpload = (e: React.FormEvent) => {
    e.preventDefault();
    
    // Parse raw transactions for MVP
    const lines = formData.transactionsRaw.split('\n').filter(l => l.trim().length > 0);
    const parsedTxns = lines.map(line => {
      const [date, amount, description, reference] = line.split(',');
      return {
        date,
        amount: parseFloat(amount),
        description: description || 'Bank Txn',
        reference: reference || '',
      };
    });

    uploadMutation.mutate({
      accountId: formData.accountId,
      statementDate: formData.statementDate,
      openingBalance: parseFloat(formData.openingBalance),
      closingBalance: parseFloat(formData.closingBalance),
      transactions: parsedTxns,
    });
  };

  if (activeRecon && activeDetails) {
    return (
      <div className="animate-fade-in">
        <div className="flex justify-between items-center mb-6">
          <div>
            <button onClick={() => setActiveRecon(null)} className="text-secondary hover:text-white mb-2 text-sm flex items-center gap-1">
              ← Back to List
            </button>
            <h1 className="heading-1 mb-2">Reconcile: {activeDetails.account?.name}</h1>
            <p className="text-secondary">Statement Date: {new Date(activeDetails.bankStatement?.statementDate).toLocaleDateString()}</p>
          </div>
          <div className="flex gap-4">
            <button 
              onClick={() => autoMatchMutation.mutate(activeRecon)} 
              disabled={activeDetails.status === 'Reconciled' || autoMatchMutation.isPending}
              className="btn-secondary"
            >
              {autoMatchMutation.isPending ? "Matching..." : "⚡ Auto-Match"}
            </button>
            <button 
              onClick={() => completeMutation.mutate(activeRecon)}
              disabled={activeDetails.status === 'Reconciled' || completeMutation.isPending}
              className="btn-primary bg-green-600 hover:bg-green-500 border-none"
            >
              {completeMutation.isPending ? "Completing..." : "Complete Reconciliation"}
            </button>
          </div>
        </div>

        <div className="grid grid-cols-2 gap-6 mb-8">
          <div className="glass-panel p-6 border-blue-500/20">
            <h3 className="heading-3 mb-4 text-blue-400">Bank Statement Lines</h3>
            <div className="space-y-3">
              {activeDetails.bankStatement?.transactions?.map((t: any) => {
                // Check if this amount exists in journal lines that are matched to this recon
                const isMatched = activeDetails.journalLines?.some((jl: any) => 
                   jl.reconciliationId === activeRecon && 
                   parseFloat(t.amount) === (parseFloat(jl.debit) - parseFloat(jl.credit))
                );

                return (
                  <div key={t.id} className="p-3 bg-[rgba(255,255,255,0.02)] border border-[rgba(255,255,255,0.05)] rounded flex justify-between items-center">
                    <div>
                      <div className="font-semibold">{new Date(t.date).toLocaleDateString()}</div>
                      <div className="text-sm text-secondary">{t.description}</div>
                    </div>
                    <div className="flex items-center gap-4">
                      <div className={`font-mono ${parseFloat(t.amount) > 0 ? 'text-green-400' : 'text-red-400'}`}>
                        {parseFloat(t.amount) > 0 ? '+' : ''}{parseFloat(t.amount).toLocaleString(undefined, {minimumFractionDigits:2})}
                      </div>
                      <div className={`w-2 h-2 rounded-full ${isMatched ? 'bg-green-500' : 'bg-gray-600'}`}></div>
                    </div>
                  </div>
                );
              })}
            </div>
          </div>

          <div className="glass-panel p-6 border-indigo-500/20">
            <h3 className="heading-3 mb-4 text-indigo-400">ERP Ledger Entries (Matched)</h3>
            <div className="space-y-3">
              {activeDetails.journalLines?.filter((jl:any) => jl.reconciliationId === activeRecon).map((jl: any) => {
                const amount = parseFloat(jl.debit) - parseFloat(jl.credit);
                return (
                  <div key={jl.id} className="p-3 bg-[rgba(255,255,255,0.02)] border border-[rgba(99,102,241,0.2)] rounded flex justify-between items-center">
                    <div>
                      <div className="font-semibold">{new Date(jl.createdAt).toLocaleDateString()}</div>
                      <div className="text-sm text-secondary">{jl.description || 'Journal Entry'}</div>
                    </div>
                    <div className="flex items-center gap-4">
                      <div className={`font-mono ${amount > 0 ? 'text-green-400' : 'text-red-400'}`}>
                         {amount > 0 ? '+' : ''}{amount.toLocaleString(undefined, {minimumFractionDigits:2})}
                      </div>
                      <div className="w-2 h-2 rounded-full bg-green-500"></div>
                    </div>
                  </div>
                );
              })}
              {activeDetails.journalLines?.filter((jl:any) => jl.reconciliationId === activeRecon).length === 0 && (
                <div className="text-secondary text-center p-8">No ledger entries matched yet. Click Auto-Match.</div>
              )}
            </div>
          </div>
        </div>
      </div>
    );
  }

  return (
    <div className="animate-fade-in">
      <div className="flex justify-between items-center mb-8">
        <div>
          <h1 className="heading-1 mb-2">Bank Reconciliation</h1>
          <p className="text-secondary">Match bank statement lines with your ERP ledger entries.</p>
        </div>
        <button onClick={() => setShowUploadForm(!showUploadForm)} className="btn-primary">
          {showUploadForm ? "Cancel" : "+ Upload Statement"}
        </button>
      </div>

      {showUploadForm && (
        <div className="glass-panel p-6 mb-8 animate-fade-in">
          <h2 className="heading-2 mb-6">Upload Bank Statement</h2>
          <form onSubmit={handleUpload}>
            <div className="grid grid-cols-2 gap-4 mb-6">
              <div>
                <label className="block text-sm font-medium text-secondary mb-1">Bank Account *</label>
                <select required value={formData.accountId} onChange={e => setFormData({...formData, accountId: e.target.value})} className="form-input" style={{ backgroundColor: 'rgba(15,23,42,0.9)' }}>
                  <option value="">Select Account...</option>
                  {(accounts ?? []).filter((a: any) => a.type === 'Asset').map((a: any) => (
                    <option key={a.id} value={a.id}>{a.code} - {a.name}</option>
                  ))}
                </select>
              </div>
              
              <div>
                <label className="block text-sm font-medium text-secondary mb-1">Statement Date *</label>
                <input required type="date" value={formData.statementDate} onChange={e => setFormData({...formData, statementDate: e.target.value})} className="form-input" />
              </div>

              <div>
                <label className="block text-sm font-medium text-secondary mb-1">Opening Balance *</label>
                <input required type="number" step="0.01" value={formData.openingBalance} onChange={e => setFormData({...formData, openingBalance: e.target.value})} className="form-input" />
              </div>

              <div>
                <label className="block text-sm font-medium text-secondary mb-1">Closing Balance *</label>
                <input required type="number" step="0.01" value={formData.closingBalance} onChange={e => setFormData({...formData, closingBalance: e.target.value})} className="form-input" />
              </div>
              
              <div className="col-span-2">
                <label className="block text-sm font-medium text-secondary mb-1">Raw Transactions (CSV format: date,amount,description,ref)</label>
                <textarea 
                  required 
                  rows={4} 
                  value={formData.transactionsRaw} 
                  onChange={e => setFormData({...formData, transactionsRaw: e.target.value})} 
                  className="form-input font-mono text-sm" 
                  placeholder="2026-07-01,1500.00,Client Payment,REF123&#10;2026-07-02,-350.00,Office Supplies,REF456"
                ></textarea>
              </div>
            </div>
            
            <div className="flex justify-end gap-4">
              <button type="button" onClick={() => setShowUploadForm(false)} className="btn-secondary">Cancel</button>
              <button type="submit" disabled={uploadMutation.isPending} className="btn-primary">
                {uploadMutation.isPending ? "Uploading..." : "Upload & Create Draft"}
              </button>
            </div>
          </form>
        </div>
      )}

      <div className="glass-panel overflow-hidden">
        {isReconLoading ? (
          <div className="p-12 text-center text-secondary">Loading reconciliations...</div>
        ) : !reconciliations || reconciliations.length === 0 ? (
          <div className="p-12 text-center text-secondary">No reconciliations found.</div>
        ) : (
          <table className="data-table">
            <thead>
              <tr>
                <th>Date</th>
                <th>Account</th>
                <th>Statement Date</th>
                <th>Status</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              {reconciliations.map(recon => (
                <tr key={recon.id}>
                  <td className="text-secondary">{new Date(recon.createdAt).toLocaleDateString()}</td>
                  <td className="font-semibold">{recon.account?.name}</td>
                  <td>{new Date(recon.bankStatement?.statementDate).toLocaleDateString()}</td>
                  <td>
                    <span className={`px-2 py-1 rounded text-xs font-semibold ${recon.status === 'Reconciled' ? 'bg-green-900/30 text-green-400' : 'bg-yellow-900/30 text-yellow-400'}`}>
                      {recon.status}
                    </span>
                  </td>
                  <td>
                    <button onClick={() => setActiveRecon(recon.id)} className="text-blue-400 hover:text-blue-300 text-sm font-medium">
                      {recon.status === 'Draft' ? 'Continue' : 'View'}
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
