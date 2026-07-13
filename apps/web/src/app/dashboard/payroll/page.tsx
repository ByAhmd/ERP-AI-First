"use client";

import { useState } from "react";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { ApiClient } from "../../../lib/api-client";
import toast from "react-hot-toast";

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
      // First create the run
      const run = await ApiClient.post("/business/payroll", data);
      
      // Then immediately approve it (since we don't have a list view for runs on the backend yet)
      await ApiClient.post(`/business/payroll/${run.id}/approve`, {});
      return run;
    },
    onSuccess: () => {
      toast.success("Payroll run processed and approved successfully!");
      setAdjustments({});
    },
    onError: (err: any) => {
      toast.error(err.message || "Failed to process payroll");
    },
  });

  const handleRunPayroll = () => {
    if (!employees || employees.length === 0) {
      toast.error("No employees to process payroll for.");
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
          <h1 className="heading-1 mb-2">Payroll & Employees</h1>
          <p className="text-secondary">Manage employee profiles and run monthly payroll</p>
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
            {tab === "RunPayroll" ? "Run Payroll" : tab}
          </button>
        ))}
      </div>

      {activeTab === "Employees" && (
        <div className="glass-panel overflow-hidden">
          {isLoading ? (
            <div className="p-12 text-center text-secondary">Loading employees...</div>
          ) : !employees || employees.length === 0 ? (
            <div className="p-12 text-center">
              <h3 className="heading-2 mb-2">No employee profiles found</h3>
              <p className="text-secondary" style={{ maxWidth: "28rem", margin: "0 auto" }}>
                To add an employee, first create a Contact of type "Employee", then link an Employee Profile.
                (Profile creation UI to be added).
              </p>
            </div>
          ) : (
            <table className="data-table">
              <thead>
                <tr>
                  <th>Employee Name</th>
                  <th>GOSI Number</th>
                  <th style={{ textAlign: "right" }}>Basic Salary</th>
                  <th style={{ textAlign: "right" }}>Housing</th>
                  <th style={{ textAlign: "right" }}>Transport</th>
                  <th style={{ textAlign: "right" }}>Total Fixed</th>
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
          <h2 className="heading-2 mb-6">Run Monthly Payroll</h2>
          
          <div className="mb-6 max-w-md">
            <label className="block text-sm font-medium text-secondary mb-1">Payroll Period Name</label>
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
                  <th>Employee</th>
                  <th style={{ textAlign: "right" }}>Fixed Salary</th>
                  <th style={{ width: "150px" }}>Bonus</th>
                  <th style={{ width: "150px" }}>Other Deductions</th>
                  <th style={{ textAlign: "right" }}>Net Salary (Est.)</th>
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
              <div className="p-8 text-center text-secondary">No employees available for payroll.</div>
            )}
          </div>

          <div className="flex justify-end">
            <button
              onClick={handleRunPayroll}
              disabled={createRunMutation.isPending || !employees || employees.length === 0}
              className="btn-primary"
              style={{ padding: "0.75rem 2rem", fontSize: "1.1rem" }}
            >
              {createRunMutation.isPending ? "Processing..." : "Process & Approve Payroll"}
            </button>
          </div>
        </div>
      )}
    </div>
  );
}
