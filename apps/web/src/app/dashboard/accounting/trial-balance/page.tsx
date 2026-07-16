"use client";

import { useQuery } from "@tanstack/react-query";
import { ApiClient } from "../../../../lib/api-client";
import { useLanguage } from "../../../../components/LanguageProvider";

interface TrialBalanceItem {
  id: string;
  code: string;
  name: string;
  type: string;
  totalDebit: string;
  totalCredit: string;
  balance: string;
  balanceType: "Debit" | "Credit";
}

interface TrialBalanceResponse {
  items: TrialBalanceItem[];
  totals: {
    debit: string;
    credit: string;
  };
  isBalanced: boolean;
}

export default function TrialBalancePage() {
  const { t, locale } = useLanguage();
  const formatCurrency = (val: number) => 
    new Intl.NumberFormat(locale === 'ar' ? 'ar-SA' : 'en-US', { style: 'currency', currency: 'SAR' }).format(val);

  const { data, isLoading, error } = useQuery({
    queryKey: ['trial-balance'],
    queryFn: () => ApiClient.get<TrialBalanceResponse>("/accounting/gl/trial-balance"),
  });

  return (
    <div className="layout-container flex-col">
      <div className="page-content max-w-5xl w-full mx-auto">
        <div className="flex justify-between items-center mb-8">
          <div>
            <h1 className="heading-1 mb-2">{t('reports.trialBalance')}</h1>
            <p className="text-secondary">{t('reports.trialBalance.subtitle')}</p>
          </div>
          {data && (
            <div className={`px-4 py-2 rounded-lg font-medium text-sm flex items-center gap-2 ${data.isBalanced ? 'bg-green-500/20 text-green-400' : 'bg-red-500/20 text-red-400'}`}>
              <span className="w-2 h-2 rounded-full bg-current"></span>
              {data.isBalanced ? "Balanced" : "Discrepancy Detected"}
            </div>
          )}
        </div>

        {isLoading ? (
          <div className="flex justify-center p-12 text-secondary">
            <div className="animate-spin w-8 h-8 border-2 border-primary border-t-transparent rounded-full mb-4 mx-auto"></div>
          </div>
        ) : error ? (
          <div className="glass-panel p-8 text-center text-error border-error/20">
            {t('common.error')}
          </div>
        ) : !data || data.items.length === 0 ? (
          <div className="glass-panel p-12 text-center text-secondary">
            {t('reports.noData')}
          </div>
        ) : (
          <div className="glass-panel overflow-hidden">
            <table className="data-table">
              <thead>
                <tr>
                  <th className="w-24">{t('reports.account')}</th>
                  <th>{t('common.name')}</th>
                  <th className="w-32">{t('common.type')}</th>
                  <th className="text-right w-40">{t('reports.debit')}</th>
                  <th className="text-right w-40">{t('reports.credit')}</th>
                </tr>
              </thead>
              <tbody>
                {data.items.map((item) => (
                  <tr key={item.id}>
                    <td className="font-mono text-sm text-secondary">{item.code}</td>
                    <td className="font-medium">{item.name}</td>
                    <td>
                      <span className="badge badge-outline text-xs">
                        {t(`coa.accountType.${item.type.toLowerCase()}`)}
                      </span>
                    </td>
                    <td className="text-right font-mono">
                      {item.balanceType === 'Debit' ? formatCurrency(parseFloat(item.balance)) : '-'}
                    </td>
                    <td className="text-right font-mono">
                      {item.balanceType === 'Credit' ? formatCurrency(parseFloat(item.balance)) : '-'}
                    </td>
                  </tr>
                ))}
              </tbody>
              <tfoot className="bg-[rgba(255,255,255,0.02)] font-semibold border-t border-[var(--border-color)]">
                <tr>
                  <td colSpan={3} className="text-right py-4 px-4">{t('reports.total')}</td>
                  <td className="text-right py-4 px-4 font-mono text-primary">
                    {formatCurrency(parseFloat(data.totals.debit))}
                  </td>
                  <td className="text-right py-4 px-4 font-mono text-primary">
                    {formatCurrency(parseFloat(data.totals.credit))}
                  </td>
                </tr>
              </tfoot>
            </table>
          </div>
        )}
      </div>
    </div>
  );
}
