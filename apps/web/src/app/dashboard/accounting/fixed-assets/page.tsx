"use client";

import { useState } from "react";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { ApiClient } from "../../../../lib/api-client";
import toast from "react-hot-toast";
import { useLanguage } from "../../../../components/LanguageProvider";

export default function FixedAssetsPage() {
  const queryClient = useQueryClient();
  const { t } = useLanguage();
  const [showForm, setShowForm] = useState(false);
  const [selectedAsset, setSelectedAsset] = useState<string | null>(null);

  const [formData, setFormData] = useState({
    code: "",
    name: "",
    description: "",
    purchaseDate: "",
    purchasePrice: "",
    salvageValue: "",
    usefulLifeMonths: "",
    depreciationMethod: "StraightLine",
    assetAccountId: "",
    depreciationAccountId: "",
    expenseAccountId: "",
  });

  const { data: assets, isLoading } = useQuery({
    queryKey: ["fixed-assets"],
    queryFn: () => ApiClient.get<any[]>("/accounting/fixed-assets"),
  });

  const { data: accounts } = useQuery({
    queryKey: ["accounts"],
    queryFn: () => ApiClient.get<any[]>("/accounting/chart-of-accounts"),
  });

  const { data: schedules } = useQuery({
    queryKey: ["fixed-asset-schedules", selectedAsset],
    queryFn: () => ApiClient.get<any>(`/accounting/fixed-assets/${selectedAsset}/schedules`),
    enabled: !!selectedAsset,
  });

  const createMutation = useMutation({
    mutationFn: (data: any) => ApiClient.post("/accounting/fixed-assets", data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["fixed-assets"] });
      setShowForm(false);
      toast.success(t('assets.form.title') + " — OK");
    },
    onError: (err: any) => {
      toast.error(err.message || t('common.error'));
    },
  });

  const runDepreciationMutation = useMutation({
    mutationFn: (data: any) => ApiClient.post("/accounting/fixed-assets/run-depreciation", data),
    onSuccess: (data: any) => {
      queryClient.invalidateQueries({ queryKey: ["fixed-assets"] });
      queryClient.invalidateQueries({ queryKey: ["fixed-asset-schedules"] });
      toast.success(`Successfully posted ${data.postedCount} depreciation journals.`);
    },
    onError: (err: any) => {
      toast.error(err.message || t('common.error'));
    },
  });

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    createMutation.mutate({
      ...formData,
      purchasePrice: parseFloat(formData.purchasePrice),
      salvageValue: parseFloat(formData.salvageValue),
      usefulLifeMonths: parseInt(formData.usefulLifeMonths, 10),
    });
  };

  const handleRunDepreciation = () => {
    const monthEnd = new Date();
    monthEnd.setMonth(monthEnd.getMonth() + 1);
    monthEnd.setDate(0);
    runDepreciationMutation.mutate({ asOfDate: monthEnd.toISOString() });
  };

  return (
    <div className="animate-fade-in">
      <div className="flex justify-between items-center mb-8">
        <div>
          <h1 className="heading-1 mb-2">{t('assets.title')}</h1>
          <p className="text-secondary">{t('assets.subtitle')}</p>
        </div>
        <div className="flex gap-4">
          <button onClick={handleRunDepreciation} disabled={runDepreciationMutation.isPending} className="btn-secondary" style={{ borderColor: 'var(--accent-primary)', color: 'var(--accent-primary)' }}>
            {runDepreciationMutation.isPending ? t('common.saving') : t('assets.depreciate')}
          </button>
          <button onClick={() => setShowForm(!showForm)} className="btn-primary">
            {showForm ? t('common.cancel') : t('assets.newAsset')}
          </button>
        </div>
      </div>

      {showForm && (
        <div className="glass-panel p-6 mb-8 animate-fade-in">
          <h2 className="heading-2 mb-6">{t('assets.form.title')}</h2>
          <form onSubmit={handleSubmit}>
            <div className="grid grid-cols-2 gap-4 mb-6">
              <div>
                <label className="block text-sm font-medium text-secondary mb-1">Code *</label>
                <input required type="text" value={formData.code} onChange={e => setFormData({...formData, code: e.target.value})} className="form-input" placeholder="e.g. FA-001" />
              </div>
              <div>
                <label className="block text-sm font-medium text-secondary mb-1">{t('assets.form.name')} *</label>
                <input required type="text" value={formData.name} onChange={e => setFormData({...formData, name: e.target.value})} className="form-input" placeholder="e.g. Delivery Van" />
              </div>
              
              <div>
                <label className="block text-sm font-medium text-secondary mb-1">{t('assets.form.purchaseDate')} *</label>
                <input required type="date" value={formData.purchaseDate} onChange={e => setFormData({...formData, purchaseDate: e.target.value})} className="form-input" />
              </div>
              <div>
                <label className="block text-sm font-medium text-secondary mb-1">{t('assets.form.purchaseCost')} *</label>
                <input required type="number" step="0.01" value={formData.purchasePrice} onChange={e => setFormData({...formData, purchasePrice: e.target.value})} className="form-input" />
              </div>

              <div>
                <label className="block text-sm font-medium text-secondary mb-1">{t('assets.form.residualValue')} *</label>
                <input required type="number" step="0.01" value={formData.salvageValue} onChange={e => setFormData({...formData, salvageValue: e.target.value})} className="form-input" />
              </div>
              <div>
                <label className="block text-sm font-medium text-secondary mb-1">{t('assets.form.usefulLife')} *</label>
                <input required type="number" value={formData.usefulLifeMonths} onChange={e => setFormData({...formData, usefulLifeMonths: e.target.value})} className="form-input" />
              </div>

              <div>
                <label className="block text-sm font-medium text-secondary mb-1">{t('assets.form.assetAccount')} *</label>
                <select required value={formData.assetAccountId} onChange={e => setFormData({...formData, assetAccountId: e.target.value})} className="form-input" style={{ backgroundColor: 'rgba(15,23,42,0.9)' }}>
                  <option value="">{t('common.select')}</option>
                  {(accounts ?? []).filter((a: any) => a.type === 'Asset' && (!a.children || a.children.length === 0)).map((a: any) => (
                    <option key={a.id} value={a.id}>{a.code} - {a.name}</option>
                  ))}
                </select>
              </div>
              
              <div>
                <label className="block text-sm font-medium text-secondary mb-1">{t('assets.form.depAccount')} *</label>
                <select required value={formData.depreciationAccountId} onChange={e => setFormData({...formData, depreciationAccountId: e.target.value})} className="form-input" style={{ backgroundColor: 'rgba(15,23,42,0.9)' }}>
                  <option value="">{t('common.select')}</option>
                  {(accounts ?? []).filter((a: any) => a.type === 'Asset' && (!a.children || a.children.length === 0)).map((a: any) => (
                    <option key={a.id} value={a.id}>{a.code} - {a.name}</option>
                  ))}
                </select>
              </div>

              <div>
                <label className="block text-sm font-medium text-secondary mb-1">{t('assets.form.expAccount')} *</label>
                <select required value={formData.expenseAccountId} onChange={e => setFormData({...formData, expenseAccountId: e.target.value})} className="form-input" style={{ backgroundColor: 'rgba(15,23,42,0.9)' }}>
                  <option value="">{t('common.select')}</option>
                  {(accounts ?? []).filter((a: any) => a.type === 'Expense' && (!a.children || a.children.length === 0)).map((a: any) => (
                    <option key={a.id} value={a.id}>{a.code} - {a.name}</option>
                  ))}
                </select>
              </div>
            </div>
            <div className="flex justify-end gap-4">
              <button type="button" onClick={() => setShowForm(false)} className="btn-secondary">{t('common.cancel')}</button>
              <button type="submit" disabled={createMutation.isPending} className="btn-primary">
                {createMutation.isPending ? t('common.saving') : t('common.create')}
              </button>
            </div>
          </form>
        </div>
      )}

      {selectedAsset && schedules && (
        <div className="glass-panel p-6 mb-8 animate-fade-in border-blue-500/30">
          <div className="flex justify-between items-center mb-6">
            <h2 className="heading-2 mb-0">{t('assets.depreciationMethod')}: {schedules.name}</h2>
            <button onClick={() => setSelectedAsset(null)} className="text-secondary hover:text-white">✕ {t('common.close')}</button>
          </div>
          <div className="overflow-hidden rounded border border-[rgba(255,255,255,0.1)]">
            <table className="w-full text-left border-collapse">
              <thead>
                <tr className="bg-[rgba(255,255,255,0.02)]">
                  <th className="p-3 font-medium text-secondary border-b border-[rgba(255,255,255,0.1)]">{t('ledger.date')}</th>
                  <th className="p-3 font-medium text-secondary border-b border-[rgba(255,255,255,0.1)] text-right">Expense Amount</th>
                  <th className="p-3 font-medium text-secondary border-b border-[rgba(255,255,255,0.1)] text-right">Accumulated</th>
                  <th className="p-3 font-medium text-secondary border-b border-[rgba(255,255,255,0.1)] text-center">{t('common.status')}</th>
                </tr>
              </thead>
              <tbody>
                {schedules.schedules.map((s: any) => (
                  <tr key={s.id} className="border-b border-[rgba(255,255,255,0.05)]">
                    <td className="p-3">{new Date(s.date).toLocaleDateString()}</td>
                    <td className="p-3 text-right font-mono text-blue-400">SAR {parseFloat(s.amount).toLocaleString(undefined, {minimumFractionDigits: 2})}</td>
                    <td className="p-3 text-right font-mono">SAR {parseFloat(s.accumulated).toLocaleString(undefined, {minimumFractionDigits: 2})}</td>
                    <td className="p-3 text-center">
                      <span className={`px-2 py-1 rounded text-xs font-semibold ${s.posted ? 'bg-green-900/30 text-green-400' : 'bg-gray-800 text-gray-400'}`}>
                        {s.posted ? t('status.posted') : t('status.pending')}
                      </span>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      )}

      <div className="glass-panel overflow-hidden">
        {isLoading ? (
          <div className="p-12 text-center text-secondary">{t('assets.loading')}</div>
        ) : !assets || assets.length === 0 ? (
          <div className="p-12 text-center text-secondary">{t('assets.noAssets')}</div>
        ) : (
          <table className="data-table">
            <thead>
              <tr>
                <th>Code</th>
                <th>{t('assets.assetName')}</th>
                <th>{t('assets.purchaseDate')}</th>
                <th>{t('assets.purchaseValue')}</th>
                <th>{t('assets.depreciationMethod')}</th>
                <th>{t('common.actions')}</th>
              </tr>
            </thead>
            <tbody>
              {assets.map(asset => (
                <tr key={asset.id}>
                  <td className="font-semibold">{asset.code}</td>
                  <td>{asset.name}</td>
                  <td className="text-secondary">{new Date(asset.purchaseDate).toLocaleDateString()}</td>
                  <td className="font-mono">SAR {parseFloat(asset.purchasePrice).toLocaleString()}</td>
                  <td>
                    <span className="px-2 py-1 bg-indigo-900/30 text-indigo-400 rounded text-xs font-semibold">
                      {asset.depreciationMethod}
                    </span>
                  </td>
                  <td>
                    <button 
                      onClick={() => setSelectedAsset(asset.id)}
                      className="text-blue-400 hover:text-blue-300 text-sm font-medium"
                    >
                      {t('common.view')}
                    </button>
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
