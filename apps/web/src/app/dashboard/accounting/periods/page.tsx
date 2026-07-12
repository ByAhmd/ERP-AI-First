"use client";

import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { ApiClient } from "../../../../lib/api-client";
import { useRouter } from "next/navigation";

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

  return (
    <div className="layout-container flex-col">
      <div className="page-content max-w-6xl w-full mx-auto">
        <div className="flex justify-between items-center mb-8">
          <div>
            <h1 className="heading-1 mb-2">Accounting Periods</h1>
            <p className="text-secondary">Manage fiscal years and accounting periods to control postings.</p>
          </div>
          <div className="flex gap-4">
            <button
              onClick={() => initMutation.mutate(new Date().getFullYear())}
              disabled={isInitializing || (periods && periods.length > 0)}
              className="btn-secondary flex items-center gap-2"
            >
              {isInitializing ? "Initializing..." : `Initialize ${new Date().getFullYear()} Fiscal Year`}
            </button>
            
            <button 
               onClick={() => router.push("/dashboard/accounting/journal-entries")}
               className="btn-primary"
            >
              Next: Journal Entries
            </button>
          </div>
        </div>

        <div className="glass-panel overflow-hidden">
          {isLoading ? (
            <div className="p-8 text-center text-secondary">Loading periods...</div>
          ) : periods && periods.length > 0 ? (
            <table className="data-table">
              <thead>
                <tr>
                  <th>Period Name</th>
                  <th>Start Date</th>
                  <th>End Date</th>
                  <th>Status</th>
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
                        {period.status}
                      </span>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          ) : (
            <div className="p-12 text-center">
              <h3 className="heading-2">No periods initialized</h3>
              <p className="text-secondary max-w-sm mx-auto mb-6">
                You must initialize a fiscal year and its accounting periods before you can post any journal entries.
              </p>
            </div>
          )}
        </div>
      </div>
    </div>
  );
}
