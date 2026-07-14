"use client";

import { useState } from "react";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { ApiClient } from "../../../lib/api-client";
import toast from "react-hot-toast";
import { useLanguage } from "../../../components/LanguageProvider";

interface EmployeeProfile {
  id: string;
  contactId: string;
  gosiNumber?: string;
  basicSalary: string;
  housingAllowance: string;
  transportAllowance: string;
  contact?: {
    id: string;
    name: string;
    email?: string;
  };
}

export default function PayrollPage() {
  const queryClient = useQueryClient();
  const { t, locale } = useLanguage();
  const [activeTab, setActiveTab] = useState<"Employees" | "RunPayroll">("Employees");
  const [periodName, setPeriodName] = useState(() => {
    const now = new Date();
    return `${now.toLocaleString('default', { month: 'long' })} ${now.getFullYear()}`;
  });
  
  // Custom bonus/deduction state per employee ID
  const [adjustments, setAdjustments] = useState<Record<string, { bonus: number; otherDeductions: number }>>({});

  const { data: employees, isLoading } = useQuery({
    queryKey: ["employee-profiles"],
    queryFn: () => ApiClient.get<EmployeeProfile[]>("/business/employee-profiles").catch(() => []),
  });

  const { data: payrollHistory, isLoading: isLoadingHistory } = useQuery({
    queryKey: ["payroll-runs"],
    queryFn: () => ApiClient.get<any[]>("/business/payroll").catch(() => []),
  });

  const handleAdjustmentChange = (empId: string, field: "bonus" | "otherDeductions", value: string) => {
    const numValue = parseFloat(value) || 0;
    setAdjustments(prev => ({
      ...prev,
      [empId]: {
        ...(prev[empId] || { bonus: 0, otherDeductions: 0 }),
        [field]: numValue
      }
    }));
  };

  const createRunMutation = useMutation({
    mutationFn: async (data: any) => {
      // Create the run
      const run: any = await ApiClient.post("/business/payroll", data);
      
      // Then immediately approve it (since we don't have a list view for runs on the backend yet for demo)
      // Actually wait, now we DO have an approval system! 
      // But the original code immediately approves it for simplicity. We should probably NOT approve it here anymore,
      // it should be handled in the Approvals Inbox! So I will remove the auto-approve.
      // await ApiClient.post(`/business/payroll/${run.id}/approve`, {});
      return run;
    },
    onSuccess: () => {
      toast.success(t("payroll.form.success"));
      setAdjustments({});
      queryClient.invalidateQueries({ queryKey: ["payroll-runs"] });
    },
    onError: (err: any) => {
      toast.error(err.message || t("payroll.form.error"));
    },
  });

  const handleRunPayroll = () => {
    if (!employees || employees.length === 0) {
      toast.error(t("payroll.noEmployees"));
      return;
    }

    const payslips = employees.map(emp => ({
      employeeProfileId: emp.id,
      bonus: adjustments[emp.id]?.bonus || 0,
      otherDeductions: adjustments[emp.id]?.otherDeductions || 0,
    }));

    createRunMutation.mutate({
      periodName,
      payslips,
    });
  };

  return (
    <div className="animate-fade-in">
      <div className="flex justify-between items-center mb-8">
        <div>
          <h1 className="heading-1 mb-2">{t("payroll.title")}</h1>
          <p className="text-secondary">{t("payroll.subtitle")}</p>
        </div>
      </div>

      <div className="flex gap-2 mb-6" style={{ borderBottom: "1px solid rgba(255,255,255,0.1)", paddingBottom: "1rem" }}>
        {["Employees", "RunPayroll"].map((tab) => (
          <button
            key={tab}
            onClick={() => setActiveTab(tab as any)}
            style={{
              padding: "0.5rem 1rem",
              borderRadius: "0.375rem",
              fontSize: "0.875rem",
              fontWeight: 600,
              border: "none",
              background: activeTab === tab ? "var(--accent-primary)" : "transparent",
              color: activeTab === tab ? "#fff" : "var(--text-secondary)",
              cursor: "pointer",
              transition: "all 0.2s",
            }}
          >
            {tab === "RunPayroll" ? t("payroll.runPayroll") : t("payroll.employees")}
          </button>
        ))}
      </div>

      {activeTab === "Employees" && (
        <div className="glass-panel overflow-hidden">
          {isLoading ? (
            <div className="p-12 text-center text-secondary">{t("payroll.loading")}</div>
          ) : !employees || employees.length === 0 ? (
            <div className="p-12 text-center">
              <h3 className="heading-2 mb-2">{t("payroll.noEmployees")}</h3>
              <p className="text-secondary" style={{ maxWidth: "28rem", margin: "0 auto" }}>
                To add an employee, first create a Contact of type &quot;Employee&quot;, then link an Employee Profile.
                (Profile creation UI to be added).
              </p>
            </div>
          ) : (
            <table className="data-table">
              <thead>
                <tr>
                  <th>{t("payroll.employee")}</th>
                  <th>{t("payroll.gosi")}</th>
                  <th style={{ textAlign: "right" }}>{t("payroll.basic")}</th>
                  <th style={{ textAlign: "right" }}>{t("payroll.housing")}</th>
                  <th style={{ textAlign: "right" }}>{t("payroll.transport")}</th>
                  <th style={{ textAlign: "right" }}>{t("payroll.gross")}</th>
                </tr>
              </thead>
              <tbody>
                {employees.map((emp) => {
                  const basic = parseFloat(emp.basicSalary);
                  const housing = parseFloat(emp.housingAllowance);
                  const transport = parseFloat(emp.transportAllowance);
                  const total = basic + housing + transport;
                  
                  return (
                    <tr key={emp.id}>
                      <td style={{ fontWeight: 600 }}>{emp.contact?.name ?? "Unknown"}</td>
                      <td className="text-secondary">{emp.gosiNumber ?? "—"}</td>
                      <td style={{ textAlign: "right", fontFamily: "monospace" }}>
                        {basic.toLocaleString("en-SA", { minimumFractionDigits: 2 })}
                      </td>
                      <td style={{ textAlign: "right", fontFamily: "monospace" }}>
                        {housing.toLocaleString("en-SA", { minimumFractionDigits: 2 })}
                      </td>
                      <td style={{ textAlign: "right", fontFamily: "monospace" }}>
                        {transport.toLocaleString("en-SA", { minimumFractionDigits: 2 })}
                      </td>
                      <td style={{ textAlign: "right", fontFamily: "monospace", fontWeight: 600, color: "var(--accent-primary)" }}>
                        {total.toLocaleString("en-SA", { minimumFractionDigits: 2 })}
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          )}
        </div>
      )}

      {activeTab === "RunPayroll" && (
        <div className="glass-panel p-6 animate-fade-in">
          <h2 className="heading-2 mb-6">{t("payroll.form.title")}</h2>
          
          <div className="mb-6 max-w-md">
            <label className="block text-sm font-medium text-secondary mb-1">{t("payroll.form.period")}</label>
            <input
              type="text"
              value={periodName}
              onChange={(e) => setPeriodName(e.target.value)}
              className="form-input"
              placeholder="e.g. October 2026"
            />
          </div>

          <div className="mb-8 overflow-x-auto">
            <table className="data-table" style={{ minWidth: "800px" }}>
              <thead>
                <tr>
                  <th>{t("payroll.employee")}</th>
                  <th style={{ textAlign: "right" }}>{t("payroll.gross")}</th>
                  <th style={{ width: "150px" }}>{t("payroll.form.adjustments")}</th>
                  <th style={{ width: "150px" }}>{t("payroll.deductions")}</th>
                  <th style={{ textAlign: "right" }}>{t("payroll.net")}</th>
                </tr>
              </thead>
              <tbody>
                {(employees || []).map(emp => {
                  const basic = parseFloat(emp.basicSalary);
                  const housing = parseFloat(emp.housingAllowance);
                  const transport = parseFloat(emp.transportAllowance);
                  const fixedGross = basic + housing + transport;
                  
                  const adj = adjustments[emp.id] || { bonus: 0, otherDeductions: 0 };
                  const gross = fixedGross + adj.bonus;
                  
                  // GOSI Estimate (10% of basic+housing up to 45k)
                  const gosiApplicable = Math.min(basic + housing, 45000);
                  const gosi = gosiApplicable * 0.10;
                  
                  const net = gross - gosi - adj.otherDeductions;
                  
                  return (
                    <tr key={emp.id}>
                      <td style={{ fontWeight: 600 }}>{emp.contact?.name}</td>
                      <td style={{ textAlign: "right", fontFamily: "monospace" }}>
                        {fixedGross.toLocaleString("en-SA", { minimumFractionDigits: 2 })}
                      </td>
                      <td>
                        <input
                          type="number"
                          min="0"
                          step="0.01"
                          value={adj.bonus || ""}
                          onChange={(e) => handleAdjustmentChange(emp.id, "bonus", e.target.value)}
                          className="form-input"
                          style={{ padding: "0.25rem 0.5rem", height: "auto" }}
                          placeholder="0.00"
                        />
                      </td>
                      <td>
                        <input
                          type="number"
                          min="0"
                          step="0.01"
                          value={adj.otherDeductions || ""}
                          onChange={(e) => handleAdjustmentChange(emp.id, "otherDeductions", e.target.value)}
                          className="form-input"
                          style={{ padding: "0.25rem 0.5rem", height: "auto" }}
                          placeholder="0.00"
                        />
                      </td>
                      <td style={{ textAlign: "right", fontFamily: "monospace", fontWeight: 600, color: "var(--accent-primary)" }}>
                        {net.toLocaleString("en-SA", { minimumFractionDigits: 2 })}
                      </td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
            {(!employees || employees.length === 0) && (
              <div className="p-8 text-center text-secondary">{t("payroll.noEmployees")}</div>
            )}
          </div>

          <div className="flex justify-end">
            <button
              onClick={handleRunPayroll}
              disabled={createRunMutation.isPending || !employees || employees.length === 0}
              className="btn-primary"
              style={{ padding: "0.75rem 2rem", fontSize: "1.1rem" }}
            >
              {createRunMutation.isPending ? t("common.saving") : t("payroll.form.submit")}
            </button>
          </div>

          <div style={{ marginTop: '3rem' }}>
            <h3 className="heading-3 mb-4">{t("payroll.history")}</h3>
            {isLoadingHistory ? (
              <div className="text-secondary">{t("payroll.loading")}</div>
            ) : !payrollHistory || payrollHistory.length === 0 ? (
              <div className="text-secondary">{t("payroll.noHistory")}</div>
            ) : (
              <div className="overflow-x-auto">
                <table className="data-table">
                  <thead>
                    <tr>
                      <th>{t("payroll.period")}</th>
                      <th>{t("common.date")}</th>
                      <th style={{ textAlign: "right" }}>{t("payroll.totalGross")}</th>
                      <th style={{ textAlign: "right" }}>{t("payroll.deductions")}</th>
                      <th style={{ textAlign: "right" }}>{t("payroll.totalNet")}</th>
                      <th>{t("payroll.status")}</th>
                    </tr>
                  </thead>
                  <tbody>
                    {payrollHistory.map((run) => (
                      <tr key={run.id}>
                        <td style={{ fontWeight: 600 }}>{run.periodName}</td>
                        <td className="text-secondary">{new Date(run.createdAt).toLocaleDateString()}</td>
                        <td style={{ textAlign: "right", fontFamily: "monospace" }}>
                          {parseFloat(run.totalGross).toLocaleString("en-SA", { minimumFractionDigits: 2 })}
                        </td>
                        <td style={{ textAlign: "right", fontFamily: "monospace", color: "#ef4444" }}>
                          {parseFloat(run.totalDeductions).toLocaleString("en-SA", { minimumFractionDigits: 2 })}
                        </td>
                        <td style={{ textAlign: "right", fontFamily: "monospace", fontWeight: 600, color: "var(--accent-primary)" }}>
                          {parseFloat(run.totalNet).toLocaleString("en-SA", { minimumFractionDigits: 2 })}
                        </td>
                        <td>
                          <span
                            style={{
                              display: 'inline-block',
                              padding: '0.25rem 0.75rem',
                              borderRadius: '9999px',
                              fontSize: '0.75rem',
                              fontWeight: 600,
                              backgroundColor: 
                                run.status === 'Approved' ? 'rgba(16, 185, 129, 0.1)' :
                                run.status === 'PendingApproval' ? 'rgba(245, 158, 11, 0.1)' :
                                'rgba(255, 255, 255, 0.1)',
                              color: 
                                run.status === 'Approved' ? '#10b981' :
                                run.status === 'PendingApproval' ? '#f59e0b' :
                                'var(--text-secondary)'
                            }}
                          >
                            {run.status === 'Approved' ? t('status.approved') : run.status === 'PendingApproval' ? t('status.pendingApproval') : run.status}
                          </span>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </div>
        </div>
      )}
    </div>
  );
}
