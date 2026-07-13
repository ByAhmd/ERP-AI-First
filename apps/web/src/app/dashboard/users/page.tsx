"use client";

import { useState } from "react";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { ApiClient } from "../../../lib/api-client";
import toast from "react-hot-toast";

interface Role {
  id: string;
  name: string;
}

interface TenantUser {
  role: Role;
}

interface User {
  id: string;
  email: string;
  fullName: string;
  tenantUsers?: TenantUser[];
  role?: Role; // The backend returns role at top level now
}

export default function UsersPage() {
  const queryClient = useQueryClient();
  const [showInviteForm, setShowInviteForm] = useState(false);
  const [inviteData, setInviteData] = useState({ email: "", fullName: "", roleId: "" });

  const { data: users, isLoading, error } = useQuery({
    queryKey: ["users"],
    queryFn: () => ApiClient.get<User[]>("/users"),
  });

  const { data: roles } = useQuery({
    queryKey: ["roles"],
    queryFn: () => ApiClient.get<Role[]>("/roles"),
  });

  const inviteMutation = useMutation({
    mutationFn: (data: any) => ApiClient.post("/users/invite", data),
    onSuccess: () => {
      toast.success("User invited successfully!");
      setShowInviteForm(false);
      setInviteData({ email: "", fullName: "", roleId: "" });
      queryClient.invalidateQueries({ queryKey: ["users"] });
    },
    onError: (err: any) => {
      toast.error(err.message || "Failed to invite user");
    },
  });

  const handleInvite = (e: React.FormEvent) => {
    e.preventDefault();
    if (!inviteData.roleId) {
      toast.error("Please select a role");
      return;
    }
    inviteMutation.mutate(inviteData);
  };

  return (
    <div className="animate-fade-in">
      <div className="flex justify-between items-center mb-8">
        <div>
          <h1 className="heading-1 mb-2">User Management</h1>
          <p className="text-secondary">View access and roles for your team</p>
        </div>
        <button className="btn-primary" onClick={() => setShowInviteForm(!showInviteForm)}>
          {showInviteForm ? "Cancel" : "+ Invite User"}
        </button>
      </div>

      {showInviteForm && (
        <div className="glass-panel p-6 mb-8 animate-fade-in">
          <h2 className="heading-2 mb-4">Invite New User</h2>
          <form onSubmit={handleInvite} className="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div>
              <label className="block text-sm font-medium text-secondary mb-1">Full Name</label>
              <input required type="text" className="form-input" value={inviteData.fullName} onChange={e => setInviteData({...inviteData, fullName: e.target.value})} placeholder="John Doe" />
            </div>
            <div>
              <label className="block text-sm font-medium text-secondary mb-1">Email</label>
              <input required type="email" className="form-input" value={inviteData.email} onChange={e => setInviteData({...inviteData, email: e.target.value})} placeholder="john@example.com" />
            </div>
            <div>
              <label className="block text-sm font-medium text-secondary mb-1">Role</label>
              <select required className="form-input" style={{ backgroundColor: "rgba(15,23,42,0.9)" }} value={inviteData.roleId} onChange={e => setInviteData({...inviteData, roleId: e.target.value})}>
                <option value="">Select a role...</option>
                {roles?.map(role => (
                  <option key={role.id} value={role.id}>{role.name}</option>
                ))}
              </select>
            </div>
            <div className="md:col-span-3 flex justify-end">
              <button type="submit" className="btn-primary" disabled={inviteMutation.isPending}>
                {inviteMutation.isPending ? "Inviting..." : "Send Invite"}
              </button>
            </div>
          </form>
        </div>
      )}

      <div className="glass-panel overflow-hidden">
        {isLoading ? (
          <div className="p-12 text-center text-secondary">Loading users...</div>
        ) : error ? (
          <div className="p-12 text-center" style={{ color: "var(--error)" }}>
            Failed to load users.
          </div>
        ) : !users || users.length === 0 ? (
          <div className="p-12 text-center text-secondary">
            No users found for this tenant.
          </div>
        ) : (
          <table className="data-table">
            <thead>
              <tr>
                <th>Name</th>
                <th>Email</th>
                <th>Role</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
              {users.map((user) => {
                const roleName = user.role?.name || user.tenantUsers?.[0]?.role?.name || "Member";
                return (
                  <tr key={user.id}>
                    <td style={{ fontWeight: 600 }}>
                      {user.fullName}
                    </td>
                    <td className="text-secondary">{user.email}</td>
                    <td>
                      <span
                        style={{
                          padding: "0.2rem 0.6rem",
                          borderRadius: "0.25rem",
                          fontSize: "0.75rem",
                          fontWeight: 600,
                          background: roleName.includes("Admin") || roleName === "Owner" ? "rgba(245,158,11,0.15)" : "rgba(59,130,246,0.15)",
                          color: roleName.includes("Admin") || roleName === "Owner" ? "#fbbf24" : "#60a5fa",
                        }}
                      >
                        {roleName}
                      </span>
                    </td>
                    <td>
                      <span
                        style={{
                          padding: "0.2rem 0.6rem",
                          borderRadius: "0.25rem",
                          fontSize: "0.75rem",
                          fontWeight: 600,
                          background: "rgba(16,185,129,0.15)",
                          color: "#34d399",
                        }}
                      >
                        Active
                      </span>
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        )}
      </div>
    </div>
  );
}
