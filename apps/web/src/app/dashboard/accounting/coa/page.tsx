"use client";

import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { ApiClient } from "../../../../lib/api-client";
import { useRouter } from "next/navigation";

interface Account {
  id: string;
  code: string;
  name: string;
  type: string;
  description: string;
  status: string;
}

export default function ChartOfAccountsPage() {
  const queryClient = useQueryClient();
  const router = useRouter();

  const { data: accounts, isLoading } = useQuery({
    queryKey: ['coa'],
    queryFn: () => ApiClient.get<Account[]>("/accounting/chart-of-accounts"),
  });

  const seedMutation = useMutation({
    mutationFn: () => ApiClient.post("/accounting/chart-of-accounts/seed-template"),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['coa'] });
    },
    onError: (err: any) => {
      alert(err.message || "Failed to seed template");
    },
  });

  const isSeeding = seedMutation.isPending;

  return (
    <div className="layout-container flex-col">
      <div className="page-content max-w-6xl w-full mx-auto">
        <div className="flex justify-between items-center mb-8">
          <div>
            <h1 className="heading-1 mb-2">Chart of Accounts</h1>
            <p className="text-secondary">Manage your financial accounts and standard classifications.</p>
          </div>
          <div className="flex gap-4">
            <button
              onClick={() => seedMutation.mutate()}
              disabled={isSeeding}
              className="btn-secondary flex items-center gap-2"
            >
              {isSeeding ? "Seeding..." : "Seed Standard SME Template"}
            </button>
            
            <button 
               onClick={() => router.push("/dashboard/accounting/periods")}
               className="btn-primary"
            >
              Next: Accounting Periods
            </button>
          </div>
        </div>

        <div className="glass-panel overflow-hidden">
          {isLoading ? (
            <div className="p-8 text-center text-secondary">Loading accounts...</div>
          ) : accounts && accounts.length > 0 ? (
            <table className="data-table">
              <thead>
                <tr>
                  <th>Code</th>
                  <th>Name</th>
                  <th>Type</th>
                  <th>Description</th>
                  <th>Status</th>
                </tr>
              </thead>
              <tbody>
                {accounts.map((acc) => (
                  <tr key={acc.id}>
                    <td className="font-medium text-primary">{acc.code}</td>
                    <td>{acc.name}</td>
                    <td>
                      <span className="tenant-badge">
                        {acc.type}
                      </span>
                    </td>
                    <td className="text-sm text-secondary">{acc.description || "-"}</td>
                    <td>
                      <span className="tenant-badge">
                        {acc.status}
                      </span>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          ) : (
            <div className="p-12 text-center">
              <h3 className="heading-2">No accounts found</h3>
              <p className="text-secondary mb-6 max-w-md mx-auto">
                Your chart of accounts is currently empty. Seed the standard SME template to get started quickly.
              </p>
              <button
                onClick={() => seedMutation.mutate()}
                className="btn-primary px-8"
              >
                Seed Standard Template
              </button>
            </div>
          )}
        </div>
      </div>
    </div>
  );
}
