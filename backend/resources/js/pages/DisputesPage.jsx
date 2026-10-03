import React, { useCallback, useEffect, useState } from 'react';
import { api } from '../lib/api.js';
import { useToast } from '../lib/toast.jsx';
import { Card, EmptyState, Badge, formatMoney } from '../components/ui.jsx';

const STATUS_META = {
    open: { label: '待处理', tone: 'amber' },
    rechecking: { label: '复检中', tone: 'purple' },
    negotiating: { label: '赔偿协商中', tone: 'blue' },
    resolved: { label: '已解决', tone: 'green' },
    rejected: { label: '已驳回/关闭', tone: 'gray' },
};

const TYPE_LABELS = {
    new_damage: '新增破损',
    new_stain: '新增污渍',
    missing: '缺件/丢失',
    other: '其他',
};

export default function DisputesPage({ nav }) {
    const toast = useToast();
    const [disputes, setDisputes] = useState([]);
    const [filter, setFilter] = useState('');

    const load = useCallback(async () => {
        try {
            const d = await api(`/disputes${filter ? `?status=${filter}` : ''}`);
            setDisputes(d.disputes);
        } catch (e) {
            toast.error(e.message);
        }
    }, [toast, filter]);

    useEffect(() => { load(); }, [load]);

    return (
        <div className="space-y-4">
            <div className="flex items-center justify-between">
                <h2 className="text-lg font-bold text-slate-800">争议与赔付</h2>
                <select
                    className="w-40 rounded-lg border border-slate-300 px-3 py-2 text-sm"
                    value={filter}
                    onChange={(e) => setFilter(e.target.value)}
                >
                    <option value="">全部</option>
                    <option value="open">待处理</option>
                    <option value="rechecking">复检中</option>
                    <option value="negotiating">赔偿协商中</option>
                    <option value="resolved">已解决</option>
                    <option value="rejected">已驳回/关闭</option>
                </select>
            </div>

            <Card>
                {disputes.length === 0 ? <EmptyState>暂无争议记录</EmptyState> : (
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="text-left text-xs text-slate-400 border-b border-slate-100">
                                <th className="py-2 pr-3 font-medium">#</th>
                                <th className="py-2 pr-3 font-medium">订单</th>
                                <th className="py-2 pr-3 font-medium">衣物编号</th>
                                <th className="py-2 pr-3 font-medium">类型</th>
                                <th className="py-2 pr-3 font-medium">状态</th>
                                <th className="py-2 pr-3 font-medium">赔付</th>
                                <th className="py-2 font-medium">提出时间</th>
                            </tr>
                        </thead>
                        <tbody>
                            {disputes.map((d) => {
                                const meta = STATUS_META[d.status] || { label: d.status, tone: 'gray' };
                                return (
                                    <tr key={d.id} onClick={() => nav('dispute', { id: d.id })}
                                        className="border-b border-slate-50 last:border-0 hover:bg-slate-50 cursor-pointer">
                                        <td className="py-2.5 pr-3 font-semibold text-slate-700">D-{String(d.id).padStart(4, '0')}</td>
                                        <td className="py-2.5 pr-3 text-blue-700">{d.order_no}</td>
                                        <td className="py-2.5 pr-3 font-mono">{d.garment.tag_no}</td>
                                        <td className="py-2.5 pr-3">{TYPE_LABELS[d.type] || d.type}</td>
                                        <td className="py-2.5 pr-3"><Badge tone={meta.tone}>{meta.label}</Badge></td>
                                        <td className="py-2.5 pr-3">
                                            {d.compensation ? (
                                                <span className="flex flex-col">
                                                    <span>{formatMoney(d.compensation.amount)}</span>
                                                    <Badge tone={d.compensation.status === 'paid' ? 'green' : d.compensation.status === 'rejected' ? 'gray' : 'amber'}>
                                                        {d.compensation.status_label}
                                                    </Badge>
                                                </span>
                                            ) : <span className="text-slate-300">—</span>}
                                        </td>
                                        <td className="py-2.5 text-xs text-slate-400">{new Date(d.created_at).toLocaleString('zh-CN', { hour12: false })}</td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                )}
            </Card>
        </div>
    );
}
