import React, { useEffect, useState, useCallback } from 'react';
import { api } from '../lib/api.js';
import { useToast } from '../lib/toast.jsx';
import { useAuth } from '../lib/auth.jsx';
import { Card, Button, Field, inputClass, Modal, EmptyState } from '../components/ui.jsx';
import { OrderStatusBadge } from '../components/statusBadges.jsx';

export default function OrdersPage({ nav }) {
    const toast = useToast();
    const { user } = useAuth();
    const [orders, setOrders] = useState([]);
    const [filter, setFilter] = useState('');
    const [showCreate, setShowCreate] = useState(false);
    const [busy, setBusy] = useState(false);
    const [form, setForm] = useState({ customer_name: '', customer_phone: '' });

    const canCreate = ['clerk', 'manager'].includes(user.role);

    const load = useCallback(async () => {
        try {
            const d = await api(`/orders${filter ? `?status=${filter}` : ''}`);
            setOrders(d.orders);
        } catch (e) {
            toast.error(e.message);
        }
    }, [toast, filter]);

    useEffect(() => { load(); }, [load]);

    const createOrder = async (e) => {
        e.preventDefault();
        setBusy(true);
        try {
            const d = await api('/orders', { method: 'POST', body: form });
            toast.success(`订单 ${d.order.order_no} 已创建`);
            setShowCreate(false);
            setForm({ customer_name: '', customer_phone: '' });
            nav('order', { id: d.order.id });
        } catch (e) {
            toast.error(e.message);
        } finally {
            setBusy(false);
        }
    };

    return (
        <div className="space-y-4">
            <div className="flex items-center justify-between">
                <h2 className="text-lg font-bold text-slate-800">订单</h2>
                <div className="flex items-center gap-2">
                    <select className={`${inputClass} w-40`} value={filter} onChange={(e) => setFilter(e.target.value)}>
                        <option value="">全部状态</option>
                        <option value="intake">收件预检中</option>
                        <option value="awaiting_confirmation">待顾客确认</option>
                        <option value="paused">已暂停</option>
                        <option value="in_wash">洗护中</option>
                        <option value="completed">已完成</option>
                    </select>
                    {canCreate && <Button onClick={() => setShowCreate(true)}>+ 新建订单</Button>}
                </div>
            </div>

            <Card>
                {orders.length === 0 ? <EmptyState>暂无订单</EmptyState> : (
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="text-left text-xs text-slate-400 border-b border-slate-100">
                                <th className="py-2 pr-3 font-medium">订单号</th>
                                <th className="py-2 pr-3 font-medium">顾客</th>
                                <th className="py-2 pr-3 font-medium">衣物件数</th>
                                <th className="py-2 pr-3 font-medium">状态</th>
                                <th className="py-2 pr-3 font-medium">顾客签名</th>
                                <th className="py-2 font-medium">创建时间</th>
                            </tr>
                        </thead>
                        <tbody>
                            {orders.map((o) => (
                                <tr
                                    key={o.id}
                                    onClick={() => nav('order', { id: o.id })}
                                    className="border-b border-slate-50 last:border-0 hover:bg-slate-50 cursor-pointer"
                                >
                                    <td className="py-2.5 pr-3 font-medium text-blue-700">{o.order_no}</td>
                                    <td className="py-2.5 pr-3">
                                        {o.customer_name}
                                        {o.customer_phone && <span className="text-xs text-slate-400 ml-1">{o.customer_phone}</span>}
                                    </td>
                                    <td className="py-2.5 pr-3 font-semibold">{o.garment_count}</td>
                                    <td className="py-2.5 pr-3"><OrderStatusBadge {...o} /></td>
                                    <td className="py-2.5 pr-3 text-slate-500">{o.signature_name || '—'}</td>
                                    <td className="py-2.5 text-xs text-slate-400">{new Date(o.created_at).toLocaleString('zh-CN', { hour12: false })}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
            </Card>

            <Modal
                open={showCreate}
                title="新建收件订单"
                onClose={() => setShowCreate(false)}
                footer={
                    <>
                        <Button variant="secondary" onClick={() => setShowCreate(false)}>取消</Button>
                        <Button onClick={createOrder} disabled={busy}>{busy ? '创建中…' : '创建并开始预检'}</Button>
                    </>
                }
            >
                <form onSubmit={createOrder} className="space-y-4">
                    <Field label="顾客姓名">
                        <input
                            className={inputClass}
                            value={form.customer_name}
                            onChange={(e) => setForm({ ...form, customer_name: e.target.value })}
                            required
                        />
                    </Field>
                    <Field label="联系电话（选填）">
                        <input
                            className={inputClass}
                            value={form.customer_phone}
                            onChange={(e) => setForm({ ...form, customer_phone: e.target.value })}
                        />
                    </Field>
                </form>
            </Modal>
        </div>
    );
}
