"use client";

import { useState, useMemo } from "react";
import { useQuery } from "@tanstack/react-query";
import { ApiClient } from "../../../../lib/api-client";
import { useLanguage } from "../../../../components/LanguageProvider";
import { Filter, Search, Calendar, FileText } from "lucide-react";

interface Account {
  id: string;
  code: string;
  name: string;
  type: string;
}

interface Transaction {
  id: string;
  debit: string;
  credit: string;
  description?: string;
  account: Account;
  journalEntry: {
    id: string;
    entryNumber: string;
    entryDate: string;
    description: string;
    status: string;
  };
}

export default function GeneralLedgerPage() {
  const { t, locale } = useLanguage();
  const formatCurrency = (val: number) => 
    new Intl.NumberFormat(locale === 'ar' ? 'ar-SA' : 'en-US', { style: 'currency', currency: 'SAR' }).format(val);
  
  // Advanced Filter State
  const [selectedAccountId, setSelectedAccountId] = useState<string>("");
  const [startDate, setStartDate] = useState<string>("");
  const [endDate, setEndDate] = useState<string>("");
  const [searchQuery, setSearchQuery] = useState<string>("");

  const { data: transactions, isLoading: loadingTxns, error } = useQuery({
    queryKey: ['gl-transactions'],
    queryFn: () => ApiClient.get<Transaction[]>("/accounting/gl/transactions"),
  });

  const { data: accounts } = useQuery({
    queryKey: ['coa'],
    queryFn: () => ApiClient.get<Account[]>("/accounting/chart-of-accounts"),
  });

  // Client-side advanced filtering
  const filteredTransactions = useMemo(() => {
    if (!transactions) return [];
    
    return transactions.filter(txn => {
      // 1. Account Filter
      if (selectedAccountId && txn.account.id !== selectedAccountId) {
        return false;
      }
      
      // 2. Date Range Filter
      const txnDate = new Date(txn.journalEntry.entryDate);
      if (startDate && txnDate < new Date(startDate)) return false;
      if (endDate && txnDate > new Date(endDate)) return false;
      
      // 3. Search Query Filter (Entry Number, Line Description, Entry Description)
      if (searchQuery) {
        const query = searchQuery.toLowerCase();
        const matchesEntryNumber = txn.journalEntry.entryNumber.toLowerCase().includes(query);
        const matchesLineDesc = (txn.description || "").toLowerCase().includes(query);
        const matchesEntryDesc = (txn.journalEntry.description || "").toLowerCase().includes(query);
        const matchesAccount = txn.account.name.toLowerCase().includes(query) || txn.account.code.includes(query);
        
        if (!matchesEntryNumber && !matchesLineDesc && !matchesEntryDesc && !matchesAccount) {
          return false;
        }
      }
      
      return true;
    });
  }, [transactions, selectedAccountId, startDate, endDate, searchQuery]);

  // Calculate Running Balance if a single account is selected
  let runningBalance = 0;

  return (
    <div className="layout-container flex-col">
      <div className="page-content max-w-7xl w-full mx-auto">
        <div className="flex justify-between items-center mb-6">
          <div>
            <h1 className="heading-1 mb-2">{t('ledger.title')}</h1>
            <p className="text-secondary">{t('ledger.subtitle')}</p>
          </div>
        </div>

        {/* Advanced Filters */}
        <div className="glass-panel p-4 mb-6 flex flex-wrap gap-4 items-end">
          <div className="flex-1 min-w-[200px]">
            <label className="block text-xs font-medium text-secondary mb-1 uppercase tracking-wider flex items-center gap-1">
              <Filter className="w-3 h-3" /> Filter by Account
            </label>
            <select
              value={selectedAccountId}
              onChange={(e) => setSelectedAccountId(e.target.value)}
              className="form-input text-sm py-2"
              style={{ backgroundColor: 'rgba(15,23,42,0.6)' }}
            >
              <option value="">{t('common.all')} Accounts</option>
              {(accounts ?? []).map((acc) => (
                <option key={acc.id} value={acc.id}>
                  {acc.code} - {acc.name}
                </option>
              ))}
            </select>
          </div>

          <div className="w-40">
            <label className="block text-xs font-medium text-secondary mb-1 uppercase tracking-wider flex items-center gap-1">
              <Calendar className="w-3 h-3" /> Start Date
            </label>
            <input
              type="date"
              value={startDate}
              onChange={(e) => setStartDate(e.target.value)}
              className="form-input text-sm py-2"
            />
          </div>

          <div className="w-40">
            <label className="block text-xs font-medium text-secondary mb-1 uppercase tracking-wider flex items-center gap-1">
              <Calendar className="w-3 h-3" /> End Date
            </label>
            <input
              type="date"
              value={endDate}
              onChange={(e) => setEndDate(e.target.value)}
              className="form-input text-sm py-2"
            />
          </div>

          <div className="flex-1 min-w-[250px] relative">
            <label className="block text-xs font-medium text-secondary mb-1 uppercase tracking-wider flex items-center gap-1">
              <Search className="w-3 h-3" /> Search
            </label>
            <div className="relative">
              <input
                type="text"
                placeholder="Search ref, desc, account..."
                value={searchQuery}
                onChange={(e) => setSearchQuery(e.target.value)}
                className="form-input text-sm py-2 pl-9 w-full"
              />
              <Search className="w-4 h-4 text-secondary absolute left-3 top-1/2 -translate-y-1/2" />
            </div>
          </div>
          
          <button 
            onClick={() => {
              setSelectedAccountId("");
              setStartDate("");
              setEndDate("");
              setSearchQuery("");
            }}
            className="btn-secondary h-10"
          >
            Clear Filters
          </button>
        </div>

        {loadingTxns ? (
          <div className="flex justify-center p-12 text-secondary">
            <div className="animate-spin w-8 h-8 border-2 border-primary border-t-transparent rounded-full mx-auto"></div>
          </div>
        ) : error ? (
          <div className="glass-panel p-8 text-center text-error border-error/20">
            {t('common.error')}
          </div>
        ) : filteredTransactions.length === 0 ? (
          <div className="glass-panel p-16 text-center flex flex-col items-center justify-center">
            <div className="w-16 h-16 rounded-full bg-white/5 flex items-center justify-center mb-4 text-secondary">
              <FileText className="w-8 h-8" />
            </div>
            <h3 className="text-lg font-medium mb-2 text-white">No Transactions Found</h3>
            <p className="text-secondary max-w-md mx-auto">
              {searchQuery || selectedAccountId || startDate || endDate 
                ? "Try adjusting your advanced filters to see more results."
                : "Your general ledger is currently empty. Post a journal entry to see it here."}
            </p>
          </div>
        ) : (
          <div className="glass-panel overflow-hidden">
            <div className="overflow-x-auto">
              <table className="data-table">
                <thead>
                  <tr>
                    <th className="w-32">{t('ledger.date')}</th>
                    <th className="w-32">{t('ledger.reference')}</th>
                    <th className="w-48">{t('reports.account')}</th>
                    <th>{t('ledger.description')}</th>
                    <th className="text-right w-32">{t('ledger.debit')}</th>
                    <th className="text-right w-32">{t('ledger.credit')}</th>
                    {selectedAccountId && (
                      <th className="text-right w-32 text-primary">{t('ledger.balance')}</th>
                    )}
                  </tr>
                </thead>
                <tbody>
                  {/* Note: In a real accounting system, transactions should be strictly sorted ascending by date to calculate running balance accurately. The API currently returns them descending by date, so running balance calculation here is purely illustrative if we map downwards. For true running balance, we reverse the array. */}
                  {[...filteredTransactions].reverse().map((txn) => {
                    const debit = parseFloat(txn.debit);
                    const credit = parseFloat(txn.credit);
                    
                    if (selectedAccountId) {
                      const accType = txn.account.type;
                      if (accType === 'Asset' || accType === 'Expense') {
                        runningBalance += (debit - credit);
                      } else {
                        runningBalance += (credit - debit);
                      }
                    }

                    return (
                      <tr key={txn.id} className="hover:bg-white/5 group transition-colors">
                        <td className="whitespace-nowrap text-sm text-secondary">
                          {new Date(txn.journalEntry.entryDate).toLocaleDateString()}
                        </td>
                        <td className="font-mono text-xs">
                          <span className="bg-white/10 px-2 py-1 rounded text-white/80 group-hover:bg-primary/20 group-hover:text-primary transition-colors">
                            {txn.journalEntry.entryNumber}
                          </span>
                        </td>
                        <td>
                          <div className="flex flex-col">
                            <span className="font-medium text-sm">{txn.account.name}</span>
                            <span className="text-xs text-secondary font-mono">{txn.account.code}</span>
                          </div>
                        </td>
                        <td className="text-sm">
                          {txn.description || txn.journalEntry.description}
                        </td>
                        <td className="text-right font-mono text-sm">
                          {debit > 0 ? formatCurrency(debit) : '-'}
                        </td>
                        <td className="text-right font-mono text-sm">
                          {credit > 0 ? formatCurrency(credit) : '-'}
                        </td>
                        {selectedAccountId && (
                          <td className={`text-right font-mono font-medium text-sm ${runningBalance < 0 ? 'text-error' : 'text-primary'}`}>
                            {formatCurrency(runningBalance)}
                          </td>
                        )}
                      </tr>
                    );
                  }).reverse()}
                </tbody>
              </table>
            </div>
            <div className="bg-[rgba(15,23,42,0.8)] border-t border-[var(--border-color)] p-4 flex justify-between items-center text-sm text-secondary">
              <span>Showing {filteredTransactions.length} transaction lines</span>
              {!selectedAccountId && (
                <span>Select an account to view running balances.</span>
              )}
            </div>
          </div>
        )}
      </div>
    </div>
  );
}
