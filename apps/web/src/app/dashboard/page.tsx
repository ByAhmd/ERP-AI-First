"use client";

import { useQuery } from "@tanstack/react-query";
import { ApiClient } from "../../lib/api-client";

interface TrialBalanceEntry {
  accountId: string;
  accountCode: string;
  accountName: string;
  type: string;
  debit: number;
  credit: number;
}

export default function DashboardOverview() {
  const { data, isLoading, error } = useQuery({
    queryKey: ["trial-balance"],
    queryFn: () => ApiClient.get<TrialBalanceEntry[]>("/accounting/gl/trial-balance"),
  });

  const summary = (data || []).reduce(
    (acc, entry) => {
      if (entry.type === "Revenue") acc.revenue += entry.credit - entry.debit;
      if (entry.type === "Revenue") acc.netIncome += entry.credit - entry.debit;
      if (entry.type === "Expense") acc.netIncome -= entry.debit - entry.credit;
      if (entry.type === "Asset" && entry.accountName.toLowerCase().includes("cash")) {
        acc.cashBalance += entry.debit - entry.credit;
      }
      return acc;
    },
    { revenue: 0, netIncome: 0, cashBalance: 0 }
  );

  return (
    <div className="animate-fade-in">
      <div className="flex items-center justify-between mb-8">
        <h1 className="text-3xl font-bold">Financial Overview</h1>
        <div className="text-sm text-text-secondary bg-[rgba(255,255,255,0.05)] px-4 py-2 rounded-md">
          Period: July 2026
        </div>
      </div>

      {/* Summary Cards */}
      {isLoading ? (
        <div className="mb-8">Loading financial data...</div>
      ) : error ? (
        <div className="mb-8 text-red-500">Failed to load financial data.</div>
      ) : (
        <div className="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
          <div className="glass-panel p-6">
            <h3 className="text-sm font-medium text-text-secondary mb-2">Total Revenue</h3>
            <div className="text-3xl font-bold mb-1">SAR {summary.revenue.toLocaleString()}</div>
          </div>
          
          <div className="glass-panel p-6">
            <h3 className="text-sm font-medium text-text-secondary mb-2">Net Income</h3>
            <div className="text-3xl font-bold mb-1">SAR {summary.netIncome.toLocaleString()}</div>
          </div>
          
          <div className="glass-panel p-6">
            <h3 className="text-sm font-medium text-text-secondary mb-2">Cash Balance</h3>
            <div className="text-3xl font-bold mb-1">SAR {summary.cashBalance.toLocaleString()}</div>
          </div>
        </div>
      )}

      {/* Main Charts Area */}
      <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
        {/* Left Col (Wider) */}
        <div className="lg:col-span-2 flex flex-col gap-6">
          <div className="glass-panel p-6 min-h-[400px] flex flex-col">
            <h3 className="text-lg font-semibold mb-4">Cash Flow Trend</h3>
            <div className="flex-1 flex items-center justify-center border border-dashed border-glass-border rounded-md bg-[rgba(0,0,0,0.1)]">
              <span className="text-text-tertiary">Chart Placeholder (Requires Recharts)</span>
            </div>
          </div>
        </div>
        
        {/* Right Col */}
        <div className="flex flex-col gap-6">
          <div className="glass-panel p-6 min-h-[400px] flex flex-col">
            <h3 className="text-lg font-semibold mb-4">Pending Approvals</h3>
            <div className="flex-1 flex flex-col gap-3">
              {[1, 2, 3].map((i) => (
                <div key={i} className="p-3 rounded-md bg-[rgba(255,255,255,0.03)] border border-glass-border flex justify-between items-center">
                  <div>
                    <div className="text-sm font-medium">Purchase Invoice #{1000 + i}</div>
                    <div className="text-xs text-text-tertiary">Supplier A</div>
                  </div>
                  <div className="text-sm font-bold">SAR 5,000</div>
                </div>
              ))}
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}
