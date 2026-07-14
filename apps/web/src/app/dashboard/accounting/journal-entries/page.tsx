"use client";

import { useState } from "react";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { ApiClient } from "../../../../lib/api-client";
import { useLanguage } from "../../../../components/LanguageProvider";

interface Account {
  id: string;
  code: string;
  name: string;
}

interface JournalEntryLine {
  id?: string;
  accountId: string;
  description?: string;
  debit: string;
  credit: string;
  account?: Account;
}

interface JournalEntry {
  id: string;
  entryNumber: string;
  entryDate: string;
  description: string;
  status: string;
  lines: JournalEntryLine[];
}

export default function JournalEntriesPage() {
  const queryClient = useQueryClient();
  const { t } = useLanguage();
  const [showForm, setShowForm] = useState(false);
  
  // Form State
  const [entryDate, setEntryDate] = useState(new Date().toISOString().split('T')[0]);
  const [description, setDescription] = useState("");
  const [lines, setLines] = useState<Partial<JournalEntryLine>[]>([
    { accountId: "", debit: "", credit: "" },
    { accountId: "", debit: "", credit: "" }
  ]);

  const { data: entries, isLoading: loadingEntries } = useQuery({
    queryKey: ['journal-entries'],
    queryFn: () => ApiClient.get<JournalEntry[]>("/accounting/journal-entries"),
  });

  const { data: accounts } = useQuery({
    queryKey: ['coa'],
    queryFn: () => ApiClient.get<Account[]>("/accounting/chart-of-accounts"),
  });

  const createMutation = useMutation({
    mutationFn: (data: any) => ApiClient.post("/accounting/journal-entries", data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['journal-entries'] });
      setShowForm(false);
      // Reset form
      setDescription("");
      setLines([
        { accountId: "", debit: "", credit: "" },
        { accountId: "", debit: "", credit: "" }
      ]);
    },
    onError: (err: any) => {
      alert(err.message || t('je.form.error'));
    }
  });

  const handleAddLine = () => setLines([...lines, { accountId: "", debit: "", credit: "" }]);
  
  const handleLineChange = (index: number, field: keyof JournalEntryLine, value: string) => {
    const newLines = [...lines];
    newLines[index] = { ...newLines[index], [field]: value };
    // Mutual exclusivity for debit/credit
    if (field === 'debit' && value) newLines[index].credit = "";
    if (field === 'credit' && value) newLines[index].debit = "";
    setLines(newLines);
  };

  const handleRemoveLine = (index: number) => {
    if (lines.length > 2) setLines(lines.filter((_, i) => i !== index));
  };

  const totalDebits = lines.reduce((sum, line) => sum + (parseFloat(line.debit as string) || 0), 0);
  const totalCredits = lines.reduce((sum, line) => sum + (parseFloat(line.credit as string) || 0), 0);
  const isBalanced = totalDebits === totalCredits && totalDebits > 0;

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    if (!isBalanced) return alert("Debits must equal credits!");
    
    createMutation.mutate({
      entryDate: new Date(entryDate).toISOString(),
      description,
      lines: lines.map(l => ({
        accountId: l.accountId,
        description: description,
        debit: parseFloat(l.debit as string) || 0,
        credit: parseFloat(l.credit as string) || 0
      }))
    });
  };

  return (
    <div className="layout-container flex-col">
      <div className="page-content max-w-6xl w-full mx-auto">
        <div className="flex justify-between items-center mb-8">
          <div>
            <h1 className="heading-1 mb-2">{t('je.title')}</h1>
            <p className="text-secondary">{t('je.subtitle')}</p>
          </div>
          <button
            onClick={() => setShowForm(!showForm)}
            className="btn-primary"
          >
            {showForm ? t('common.cancel') : t('je.newEntry')}
          </button>
        </div>

        {showForm && (
          <div className="glass-panel p-6 mb-8">
            <h2 className="heading-2 mb-4">{t('je.form.title')}</h2>
            <form onSubmit={handleSubmit}>
              <div className="grid md-grid-cols-2 gap-4 mb-6">
                <div>
                  <label className="block text-sm font-medium text-secondary mb-1">{t('je.form.date')}</label>
                  <input 
                    type="date" 
                    required
                    value={entryDate}
                    onChange={e => setEntryDate(e.target.value)}
                    className="form-input" 
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium text-secondary mb-1">{t('je.form.description')}</label>
                  <input 
                    type="text" 
                    required
                    value={description}
                    onChange={e => setDescription(e.target.value)}
                    placeholder="E.g. Initial Capital Investment"
                    className="form-input" 
                  />
                </div>
              </div>

              <table className="data-table mb-4">
                <thead>
                  <tr>
                    <th>{t('je.form.account')}</th>
                    <th className="w-64">{t('je.form.debit')}</th>
                    <th className="w-64">{t('je.form.credit')}</th>
                    <th className="w-8"></th>
                  </tr>
                </thead>
                <tbody>
                  {lines.map((line, i) => (
                    <tr key={i}>
                      <td>
                        <select 
                          required
                          value={line.accountId}
                          onChange={e => handleLineChange(i, 'accountId', e.target.value)}
                          className="form-input"
                          style={{ backgroundColor: 'rgba(15,23,42,0.9)' }}
                        >
                          <option value="">{t('common.select')}</option>
                          {(accounts as any[])?.filter(a => !a.children || a.children.length === 0).map(acc => (
                            <option key={acc.id} value={acc.id}>{acc.code} - {acc.name}</option>
                          ))}
                        </select>
                      </td>
                      <td>
                        <input 
                          type="number" 
                          min="0" step="0.01"
                          value={line.debit}
                          onChange={e => handleLineChange(i, 'debit', e.target.value)}
                          className="form-input" 
                        />
                      </td>
                      <td>
                        <input 
                          type="number" 
                          min="0" step="0.01"
                          value={line.credit}
                          onChange={e => handleLineChange(i, 'credit', e.target.value)}
                          className="form-input" 
                        />
                      </td>
                      <td>
                        <button 
                          type="button" 
                          onClick={() => handleRemoveLine(i)}
                          disabled={lines.length <= 2}
                          className="text-error"
                          style={{ background: 'none', border: 'none', cursor: 'pointer', opacity: lines.length <= 2 ? 0.3 : 1 }}
                        >
                          ✕
                        </button>
                      </td>
                    </tr>
                  ))}
                </tbody>
                <tfoot>
                  <tr style={{ backgroundColor: 'rgba(255,255,255,0.05)' }}>
                    <td className="font-bold">{t('common.total')}</td>
                    <td className={`font-bold ${!isBalanced ? 'text-error' : ''}`}>{totalDebits.toFixed(2)}</td>
                    <td className={`font-bold ${!isBalanced ? 'text-error' : ''}`}>{totalCredits.toFixed(2)}</td>
                    <td></td>
                  </tr>
                </tfoot>
              </table>

              <div className="flex justify-between items-center mt-6">
                <button 
                  type="button" 
                  onClick={handleAddLine}
                  className="btn-secondary"
                >
                  {t('je.form.addLine')}
                </button>
                
                <button 
                  type="submit" 
                  disabled={!isBalanced || createMutation.isPending}
                  className="btn-primary"
                >
                  {createMutation.isPending ? t('common.saving') : t('common.submit')}
                </button>
              </div>
            </form>
          </div>
        )}

        <div className="glass-panel overflow-hidden">
          {loadingEntries ? (
            <div className="p-8 text-center text-secondary">{t('je.loading')}</div>
          ) : entries && entries.length > 0 ? (
            <div className="flex flex-col">
              {entries.map((entry) => (
                <div key={entry.id} className="p-6 border-b border-glass-border" style={{ borderBottomColor: 'rgba(255,255,255,0.1)' }}>
                  <div className="flex justify-between items-start mb-4">
                    <div>
                      <h3 className="text-lg font-bold text-primary">{entry.entryNumber}</h3>
                      <p className="text-secondary text-sm">{new Date(entry.entryDate).toLocaleDateString()} • {entry.description}</p>
                    </div>
                    <span className="tenant-badge">
                      {entry.status === 'Posted' ? t('status.posted') : entry.status === 'Draft' ? t('status.draft') : entry.status}
                    </span>
                  </div>
                  
                  <table className="data-table">
                    <thead>
                      <tr>
                        <th>{t('je.form.account')}</th>
                        <th style={{ textAlign: 'right' }}>{t('je.form.debit')}</th>
                        <th style={{ textAlign: 'right' }}>{t('je.form.credit')}</th>
                      </tr>
                    </thead>
                    <tbody>
                      {entry.lines.map((line: any) => (
                        <tr key={line.id}>
                          <td className="text-primary">{line.account?.code} - {line.account?.name}</td>
                          <td style={{ textAlign: 'right' }}>{parseFloat(line.debit) > 0 ? Number(line.debit).toLocaleString() : ''}</td>
                          <td style={{ textAlign: 'right' }}>{parseFloat(line.credit) > 0 ? Number(line.credit).toLocaleString() : ''}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              ))}
            </div>
          ) : (
            <div className="p-12 text-center">
              <h3 className="heading-2">{t('je.noEntries')}</h3>
              <p className="text-secondary max-w-sm mx-auto mb-6">
                {t('je.subtitle')}
              </p>
            </div>
          )}
        </div>
      </div>
    </div>
  );
}
