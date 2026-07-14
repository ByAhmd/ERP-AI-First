"use client";

import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { ApiClient } from "../../../../lib/api-client";
import { useRouter } from "next/navigation";
import { useLanguage } from "../../../../components/LanguageProvider";

interface AccountingPeriod {
  id: string;
  name: string;
  startDate: string;
  endDate: string;
  status: 'Open' | 'Closed' | 'Adjusting';
}

export default function AccountingPeriodsPage() {
  const queryClient = useQueryClient();
  const router = useRouter();
  const { t } = useLanguage();

  const { data: periods, isLoading } = useQuery({
    queryKey: ['accounting-periods'],
    queryFn: () => ApiClient.get<AccountingPeriod[]>("/accounting/periods"),
  });

  const initMutation = useMutation({
    mutationFn: (year: number) => ApiClient.post("/accounting/periods/initialize-year", { year }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['accounting-periods'] });
    },
  });

  const isInitializing = initMutation.isPending;

  const getStatusLabel = (status: string) => {
    if (status === 'Open') return t('status.open');
    if (status === 'Closed') return t('status.closed');
    if (status === 'Adjusting') return t('status.adjusting');
    return status;
  };

  return (
    <div className="layout-container flex-col">
      <div className="page-content max-w-6xl w-full mx-auto">
        <div className="flex justify-between items-center mb-8">
          <div>
            <h1 className="heading-1 mb-2">{t('periods.title')}</h1>
            <p className="text-secondary">{t('periods.subtitle')}</p>
          </div>
          <div className="flex gap-4">
            <button
              onClick={() => initMutation.mutate(new Date().getFullYear())}
              disabled={isInitializing || (periods && periods.length > 0)}
              className="btn-secondary flex items-center gap-2"
            >
              {isInitializing ? t('common.saving') : `${t('periods.newFiscalYear')} ${new Date().getFullYear()}`}
            </button>
            
            <button 
               onClick={() => router.push("/dashboard/accounting/journal-entries")}
               className="btn-primary"
            >
              {t('common.next')}: {t('nav.journalEntries')}
            </button>
          </div>
        </div>

        <div className="glass-panel overflow-hidden">
          {isLoading ? (
            <div className="p-8 text-center text-secondary">{t('periods.loading')}</div>
          ) : periods && periods.length > 0 ? (
            <table className="data-table">
              <thead>
                <tr>
                  <th>{t('periods.period')}</th>
                  <th>{t('periods.startDate')}</th>
                  <th>{t('periods.endDate')}</th>
                  <th>{t('periods.status')}</th>
                </tr>
              </thead>
              <tbody>
                {periods.map((period) => (
                  <tr key={period.id}>
                    <td className="font-medium text-primary">{period.name}</td>
                    <td className="text-secondary">{new Date(period.startDate).toLocaleDateString()}</td>
                    <td className="text-secondary">{new Date(period.endDate).toLocaleDateString()}</td>
                    <td>
                      <span className="tenant-badge">
                        {getStatusLabel(period.status)}
                      </span>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          ) : (
            <div className="p-12 text-center">
              <h3 className="heading-2">{t('periods.noPeriods')}</h3>
              <p className="text-secondary max-w-sm mx-auto mb-6">
                {t('periods.subtitle')}
              </p>
            </div>
          )}
        </div>
      </div>
    </div>
  );
}
