"use client";

import { useState } from "react";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { ApiClient } from "../../../lib/api-client";
import toast from "react-hot-toast";
import { useLanguage } from '../../../components/LanguageProvider';

interface Permission {
  key: string;
  description: string;
}

interface RolePermission {
  permission: Permission;
}

interface Role {
  id: string;
  name: string;
  description: string | null;
  isSystemRole: boolean;
  rolePermissions: RolePermission[];
}

export default function RolesPage() {
  const queryClient = useQueryClient();
  const { t } = useLanguage();
  const [showRoleForm, setShowRoleForm] = useState(false);
  const [editingRole, setEditingRole] = useState<Role | null>(null);
  const [formData, setFormData] = useState({ name: "", description: "", permissionIds: [] as string[] });

  const { data: roles, isLoading: rolesLoading } = useQuery({
    queryKey: ["roles"],
    queryFn: () => ApiClient.get<Role[]>("/roles"),
  });

  const { data: permissions } = useQuery({
    queryKey: ["permissions"],
    queryFn: () => ApiClient.get<any[]>("/permissions"),
  });

  const saveMutation = useMutation({
    mutationFn: (data: any) => 
      editingRole 
        ? ApiClient.put(`/roles/${editingRole.id}`, data)
        : ApiClient.post("/roles", data),
    onSuccess: () => {
      toast.success(t('roles.success'));
      setShowRoleForm(false);
      setEditingRole(null);
      setFormData({ name: "", description: "", permissionIds: [] });
      queryClient.invalidateQueries({ queryKey: ["roles"] });
    },
    onError: (err: any) => {
      toast.error(err.message || t('roles.error'));
    },
  });

  const handleEdit = (role: Role) => {
    if (role.isSystemRole) return;
    setEditingRole(role);
    setFormData({
      name: role.name,
      description: role.description || "",
      permissionIds: role.rolePermissions.map(rp => rp.permission.key) // We'll map back to ID on submit
    });
    setShowRoleForm(true);
  };

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    if (!formData.name) {
      toast.error(t('roles.error'));
      return;
    }
    // Map selected permission keys to their IDs
    const permIds = permissions?.filter(p => formData.permissionIds.includes(p.key)).map(p => p.id) || [];
    saveMutation.mutate({ ...formData, permissionIds: permIds });
  };

  const togglePermission = (key: string) => {
    setFormData(prev => ({
      ...prev,
      permissionIds: prev.permissionIds.includes(key)
        ? prev.permissionIds.filter(k => k !== key)
        : [...prev.permissionIds, key]
    }));
  };

  // Group permissions by category (e.g. 'accounting', 'business')
  const groupedPermissions = permissions?.reduce((acc: Record<string, any[]>, perm) => {
    const category = perm.key.split('.')[0];
    if (!acc[category]) acc[category] = [];
    acc[category].push(perm);
    return acc;
  }, {});

  return (
    <div className="animate-fade-in">
      <div className="flex justify-between items-center mb-8">
        <div>
          <h1 className="heading-1 mb-2">{t('roles.title')}</h1>
          <p className="text-secondary">{t('roles.subtitle')}</p>
        </div>
        <button className="btn-primary" onClick={() => {
          setEditingRole(null);
          setFormData({ name: "", description: "", permissionIds: [] });
          setShowRoleForm(!showRoleForm);
        }}>
          {showRoleForm ? t('common.cancel') : `+ ${t('roles.new')}`}
        </button>
      </div>

      {showRoleForm && (
        <div className="glass-panel p-6 mb-8 animate-fade-in">
          <h2 className="heading-2 mb-4">{editingRole ? t('roles.edit') : t('roles.create')}</h2>
          <form onSubmit={handleSubmit}>
            <div className="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
              <div>
                <label className="block text-sm font-medium text-secondary mb-1">{t('roles.name')}</label>
                <input required type="text" className="form-input" value={formData.name} onChange={e => setFormData({...formData, name: e.target.value})} placeholder={t('roles.form.namePlaceholder')} />
              </div>
              <div>
                <label className="block text-sm font-medium text-secondary mb-1">{t('roles.description')}</label>
                <input type="text" className="form-input" value={formData.description} onChange={e => setFormData({...formData, description: e.target.value})} placeholder={t('roles.form.descPlaceholder')} />
              </div>
            </div>

            <h3 className="heading-3 mb-4">{t('roles.form.selectPermissions')}</h3>
            <div className="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
              {groupedPermissions && Object.entries(groupedPermissions).map(([category, perms]) => (
                <div key={category} className="p-4" style={{ background: "rgba(255,255,255,0.03)", borderRadius: "0.5rem" }}>
                  <h4 className="font-semibold text-lg mb-3 capitalize">{category}</h4>
                  <div className="space-y-2">
                    {perms.map(perm => (
                      <label key={perm.key} className="flex items-center space-x-2 cursor-pointer">
                        <input
                          type="checkbox"
                          className="form-checkbox h-4 w-4 text-blue-500 rounded border-gray-600 bg-gray-700"
                          checked={formData.permissionIds.includes(perm.key)}
                          onChange={() => togglePermission(perm.key)}
                        />
                        <span className="text-sm text-secondary">{perm.description}</span>
                      </label>
                    ))}
                  </div>
                </div>
              ))}
            </div>

            <div className="flex justify-end">
              <button type="submit" className="btn-primary" disabled={saveMutation.isPending}>
                {saveMutation.isPending ? t('common.loading') : t('roles.save')}
              </button>
            </div>
          </form>
        </div>
      )}

      <div className="glass-panel overflow-hidden">
        {rolesLoading ? (
          <div className="p-12 text-center text-secondary">{t('roles.loading')}</div>
        ) : !roles || roles.length === 0 ? (
          <div className="p-12 text-center text-secondary">{t('roles.noRoles')}</div>
        ) : (
          <table className="data-table">
            <thead>
              <tr>
                <th>{t('roles.name')}</th>
                <th>{t('roles.description')}</th>
                <th>{t('roles.type')}</th>
                <th>{t('roles.permissions')}</th>
                <th>{t('common.actions')}</th>
              </tr>
            </thead>
            <tbody>
              {roles.map((role) => (
                <tr key={role.id}>
                  <td style={{ fontWeight: 600 }}>{role.name}</td>
                  <td className="text-secondary">{role.description || '-'}</td>
                  <td>
                    <span
                      style={{
                        padding: "0.2rem 0.6rem",
                        borderRadius: "0.25rem",
                        fontSize: "0.75rem",
                        fontWeight: 600,
                        background: role.isSystemRole ? "rgba(245,158,11,0.15)" : "rgba(16,185,129,0.15)",
                        color: role.isSystemRole ? "#fbbf24" : "#34d399",
                      }}
                    >
                      {role.isSystemRole ? t('roles.system') : t('roles.custom')}
                    </span>
                  </td>
                  <td className="text-secondary text-sm">
                    {role.isSystemRole ? 'All Module Permissions' : `${role.rolePermissions.length} selected`}
                  </td>
                  <td>
                    {!role.isSystemRole && (
                      <button onClick={() => handleEdit(role)} className="text-blue-400 hover:text-blue-300">
                        {t('roles.edit')}
                      </button>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>
    </div>
  );
}
