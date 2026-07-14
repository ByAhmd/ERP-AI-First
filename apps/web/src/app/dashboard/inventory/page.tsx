"use client";

import { useState } from "react";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { ApiClient } from "../../../lib/api-client";
import toast from "react-hot-toast";
import { useLanguage } from '../../../components/LanguageProvider';

export default function InventoryPage() {
  const { t, locale } = useLanguage();
  const queryClient = useQueryClient();
  const [activeTab, setActiveTab] = useState<"items" | "warehouses" | "transactions">("items");
  const [showItemForm, setShowItemForm] = useState(false);
  const [showWarehouseForm, setShowWarehouseForm] = useState(false);

  const [itemForm, setItemForm] = useState({
    code: "",
    name: "",
    type: "Product",
    description: "",
  });

  const [warehouseForm, setWarehouseForm] = useState({
    code: "",
    name: "",
    location: "",
  });

  const { data: items, isLoading: itemsLoading } = useQuery({
    queryKey: ["inventory-items"],
    queryFn: () => ApiClient.get<any[]>("/accounting/inventory/items"),
  });

  const { data: warehouses, isLoading: warehousesLoading } = useQuery({
    queryKey: ["inventory-warehouses"],
    queryFn: () => ApiClient.get<any[]>("/accounting/inventory/warehouses"),
  });

  const { data: transactions, isLoading: transactionsLoading } = useQuery({
    queryKey: ["inventory-transactions"],
    queryFn: () => ApiClient.get<any[]>("/accounting/inventory/transactions"),
  });

  const createItemMutation = useMutation({
    mutationFn: (data: any) => ApiClient.post("/accounting/inventory/items", data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["inventory-items"] });
      setShowItemForm(false);
      setItemForm({ code: "", name: "", type: "Product", description: "" });
      toast.success(t('contacts.form.success'));
    },
    onError: (err: any) => {
      toast.error(err.message || t('contacts.form.error'));
    },
  });

  const createWarehouseMutation = useMutation({
    mutationFn: (data: any) => ApiClient.post("/accounting/inventory/warehouses", data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["inventory-warehouses"] });
      setShowWarehouseForm(false);
      setWarehouseForm({ code: "", name: "", location: "" });
      toast.success("Warehouse created successfully");
    },
    onError: (err: any) => {
      toast.error(err.message || "Failed to create warehouse");
    },
  });

  const handleItemSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    createItemMutation.mutate(itemForm);
  };

  const handleWarehouseSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    createWarehouseMutation.mutate(warehouseForm);
  };

  return (
    <div className="animate-fade-in">
      <div className="flex justify-between items-center mb-8">
        <div>
          <h1 className="heading-1 mb-2">{t('inventory.title')}</h1>
          <p className="text-secondary">{t('inventory.subtitle')}</p>
        </div>
        <div>
          {activeTab === "items" && (
            <button onClick={() => setShowItemForm(!showItemForm)} className="btn-primary">
              {showItemForm ? t('common.cancel') : t('inventory.newItem')}
            </button>
          )}
          {activeTab === "warehouses" && (
            <button onClick={() => setShowWarehouseForm(!showWarehouseForm)} className="btn-primary">
              {showWarehouseForm ? t('common.cancel') : t('inventory.newWarehouse')}
            </button>
          )}
        </div>
      </div>

      <div className="flex gap-6 mb-8 border-b border-[rgba(255,255,255,0.1)]">
        <button 
          onClick={() => setActiveTab('items')}
          style={{ 
            background: 'none', border: 'none', padding: '0.75rem 1rem', cursor: 'pointer',
            color: activeTab === 'items' ? '#6366f1' : 'var(--text-secondary)',
            borderBottom: activeTab === 'items' ? '2px solid #6366f1' : '2px solid transparent',
            fontWeight: activeTab === 'items' ? 600 : 400
          }}
        >
          {t('inventory.items')}
        </button>
        <button 
          onClick={() => setActiveTab('warehouses')}
          style={{ 
            background: 'none', border: 'none', padding: '0.75rem 1rem', cursor: 'pointer',
            color: activeTab === 'warehouses' ? '#6366f1' : 'var(--text-secondary)',
            borderBottom: activeTab === 'warehouses' ? '2px solid #6366f1' : '2px solid transparent',
            fontWeight: activeTab === 'warehouses' ? 600 : 400
          }}
        >
          {t('inventory.warehouses')}
        </button>
        <button 
          onClick={() => setActiveTab('transactions')}
          style={{ 
            background: 'none', border: 'none', padding: '0.75rem 1rem', cursor: 'pointer',
            color: activeTab === 'transactions' ? '#6366f1' : 'var(--text-secondary)',
            borderBottom: activeTab === 'transactions' ? '2px solid #6366f1' : '2px solid transparent',
            fontWeight: activeTab === 'transactions' ? 600 : 400
          }}
        >
          {t('inventory.movements')}
        </button>
      </div>

      {activeTab === "items" && showItemForm && (
        <div className="glass-panel p-6 mb-8 animate-fade-in">
          <h2 className="heading-2 mb-6">Create Item</h2>
          <form onSubmit={handleItemSubmit}>
            <div className="grid grid-cols-2 gap-4 mb-6">
              <div>
                <label className="block text-sm font-medium text-secondary mb-1">Code *</label>
                <input required type="text" value={itemForm.code} onChange={e => setItemForm({...itemForm, code: e.target.value})} className="form-input" placeholder="e.g. PRD-001" />
              </div>
              <div>
                <label className="block text-sm font-medium text-secondary mb-1">Name *</label>
                <input required type="text" value={itemForm.name} onChange={e => setItemForm({...itemForm, name: e.target.value})} className="form-input" placeholder="e.g. Premium Widget" />
              </div>
              <div>
                <label className="block text-sm font-medium text-secondary mb-1">Type</label>
                <select value={itemForm.type} onChange={e => setItemForm({...itemForm, type: e.target.value})} className="form-input" style={{ backgroundColor: 'rgba(15,23,42,0.9)' }}>
                  <option value="Product">Product (Tracked Inventory)</option>
                  <option value="Service">Service (Non-tracked)</option>
                </select>
              </div>
              <div>
                <label className="block text-sm font-medium text-secondary mb-1">Description</label>
                <input type="text" value={itemForm.description} onChange={e => setItemForm({...itemForm, description: e.target.value})} className="form-input" placeholder="Optional" />
              </div>
            </div>
            <div className="flex justify-end gap-4">
              <button type="button" onClick={() => setShowItemForm(false)} className="btn-secondary">{t('common.cancel')}</button>
              <button type="submit" disabled={createItemMutation.isPending} className="btn-primary">
                {createItemMutation.isPending ? t('common.saving') : t('common.create')}
              </button>
            </div>
          </form>
        </div>
      )}

      {activeTab === "warehouses" && showWarehouseForm && (
        <div className="glass-panel p-6 mb-8 animate-fade-in">
          <h2 className="heading-2 mb-6">Create Warehouse</h2>
          <form onSubmit={handleWarehouseSubmit}>
            <div className="grid grid-cols-2 gap-4 mb-6">
              <div>
                <label className="block text-sm font-medium text-secondary mb-1">Code *</label>
                <input required type="text" value={warehouseForm.code} onChange={e => setWarehouseForm({...warehouseForm, code: e.target.value})} className="form-input" placeholder="e.g. WH-MAIN" />
              </div>
              <div>
                <label className="block text-sm font-medium text-secondary mb-1">Name *</label>
                <input required type="text" value={warehouseForm.name} onChange={e => setWarehouseForm({...warehouseForm, name: e.target.value})} className="form-input" placeholder="e.g. Main Distribution Center" />
              </div>
              <div className="col-span-2">
                <label className="block text-sm font-medium text-secondary mb-1">Location</label>
                <input type="text" value={warehouseForm.location} onChange={e => setWarehouseForm({...warehouseForm, location: e.target.value})} className="form-input" placeholder="e.g. Riyadh, KSA" />
              </div>
            </div>
            <div className="flex justify-end gap-4">
              <button type="button" onClick={() => setShowWarehouseForm(false)} className="btn-secondary">{t('common.cancel')}</button>
              <button type="submit" disabled={createWarehouseMutation.isPending} className="btn-primary">
                {createWarehouseMutation.isPending ? t('common.saving') : t('common.create')}
              </button>
            </div>
          </form>
        </div>
      )}

      {activeTab === "items" && (
        <div className="glass-panel overflow-hidden">
          {itemsLoading ? (
            <div className="p-12 text-center text-secondary">{t('inventory.loading')}</div>
          ) : !items || items.length === 0 ? (
            <div className="p-12 text-center text-secondary">{t('inventory.noItems')}</div>
          ) : (
            <table className="data-table">
              <thead>
                <tr>
                  <th>{t('inventory.sku')}</th>
                  <th>{t('inventory.item')}</th>
                  <th>{t('inventory.type')}</th>
                  <th>{t('inventory.avgCost')}</th>
                  <th>{t('inventory.onHand')}</th>
                </tr>
              </thead>
              <tbody>
                {items.map(item => {
                  const totalStock = item.inventoryBalances?.reduce((sum: number, b: any) => sum + parseFloat(b.quantity), 0) || 0;
                  return (
                    <tr key={item.id}>
                      <td className="font-semibold">{item.code}</td>
                      <td>{item.name}</td>
                      <td>
                        <span className={`px-2 py-1 rounded text-xs font-semibold ${item.type === 'Product' ? 'bg-blue-900/30 text-blue-400' : 'bg-purple-900/30 text-purple-400'}`}>
                          {item.type}
                        </span>
                      </td>
                      <td className="font-mono">SAR {parseFloat(item.weightedAverageCost).toFixed(2)}</td>
                      <td className="font-mono">{item.type === 'Product' ? totalStock.toFixed(2) : '-'}</td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          )}
        </div>
      )}

      {activeTab === "warehouses" && (
        <div className="glass-panel overflow-hidden">
          {warehousesLoading ? (
            <div className="p-12 text-center text-secondary">{t('inventory.loading')}</div>
          ) : !warehouses || warehouses.length === 0 ? (
            <div className="p-12 text-center text-secondary">No warehouses found. Create one first.</div>
          ) : (
            <table className="data-table">
              <thead>
                <tr>
                  <th>Code</th>
                  <th>Name</th>
                  <th>Location</th>
                </tr>
              </thead>
              <tbody>
                {warehouses.map(wh => (
                  <tr key={wh.id}>
                    <td className="font-semibold">{wh.code}</td>
                    <td>{wh.name}</td>
                    <td className="text-secondary">{wh.location || '-'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </div>
      )}

      {activeTab === "transactions" && (
        <div className="glass-panel overflow-hidden">
          {transactionsLoading ? (
            <div className="p-12 text-center text-secondary">{t('inventory.loading')}</div>
          ) : !transactions || transactions.length === 0 ? (
            <div className="p-12 text-center text-secondary">No inventory transactions found.</div>
          ) : (
            <table className="data-table">
              <thead>
                <tr>
                  <th>Date</th>
                  <th>Type</th>
                  <th>{t('inventory.item')}</th>
                  <th>Warehouse</th>
                  <th>Quantity</th>
                  <th>Unit Cost</th>
                  <th>Reference</th>
                </tr>
              </thead>
              <tbody>
                {transactions.map(tx => (
                  <tr key={tx.id}>
                    <td className="text-secondary">{new Date(tx.date).toLocaleDateString()}</td>
                    <td>
                      <span className={`px-2 py-1 rounded text-xs font-semibold ${tx.type === 'Purchase' ? 'bg-green-900/30 text-green-400' : tx.type === 'Sale' ? 'bg-blue-900/30 text-blue-400' : 'bg-orange-900/30 text-orange-400'}`}>
                        {tx.type}
                      </span>
                    </td>
                    <td>{tx.item?.name || tx.itemId}</td>
                    <td>{tx.warehouse?.name || tx.warehouseId}</td>
                    <td className="font-mono font-semibold" style={{ color: parseFloat(tx.quantity) > 0 ? '#34d399' : '#f87171' }}>
                      {parseFloat(tx.quantity) > 0 ? '+' : ''}{parseFloat(tx.quantity).toFixed(2)}
                    </td>
                    <td className="font-mono">SAR {parseFloat(tx.unitCost).toFixed(2)}</td>
                    <td className="text-secondary">{tx.reference || '-'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </div>
      )}
    </div>
  );
}
