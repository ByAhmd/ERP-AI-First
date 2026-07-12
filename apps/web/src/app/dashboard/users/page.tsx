"use client";

import { useQuery } from "@tanstack/react-query";
import { ApiClient } from "../../../lib/api-client";

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
  firstName: string;
  lastName: string;
  tenantUsers?: TenantUser[];
}

export default function UsersPage() {
  const { data: users, isLoading, error } = useQuery({
    queryKey: ["users"],
    queryFn: () => ApiClient.get<User[]>("/users"),
  });

  return (
    <div className="animate-fade-in">
      <div className="flex justify-between items-center mb-8">
        <div>
          <h1 className="heading-1 mb-2">User Management</h1>
          <p className="text-secondary">View access and roles for your team</p>
        </div>
      </div>

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
                const roleName = user.tenantUsers?.[0]?.role?.name || "Member";
                return (
                  <tr key={user.id}>
                    <td style={{ fontWeight: 600 }}>
                      {user.firstName} {user.lastName}
                    </td>
                    <td className="text-secondary">{user.email}</td>
                    <td>
                      <span
                        style={{
                          padding: "0.2rem 0.6rem",
                          borderRadius: "0.25rem",
                          fontSize: "0.75rem",
                          fontWeight: 600,
                          background: roleName === "Owner" ? "rgba(245,158,11,0.15)" : "rgba(59,130,246,0.15)",
                          color: roleName === "Owner" ? "#fbbf24" : "#60a5fa",
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
