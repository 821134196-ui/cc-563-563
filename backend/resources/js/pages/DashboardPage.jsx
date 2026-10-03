import React, { useEffect, useState, useCallback } from 'react';
import { api } from '../lib/api.js';
import { useToast } from '../lib/toast.jsx';
import { useAuth } from '../lib/auth.jsx';
import { Card, Button, Badge, formatMoney } from '../components/ui.jsx';
import { GARMENT_STATUS_TONE } from '../components/statusBadges.jsx';

export default function DashboardPage({ nav }) {
    const toast = useToast();
    const { user } = useAuth();
    const [stats, setStats] = useState(null);
    const [paused, setPaused] = useState([]);

    const load = useCallback(async () => {
        try {
            const [s, p] = await Promise.all([
                api('/dashboard/intake-stats'),
                api('/dashboard/paused'),
            ]);
            setStats(s);
            setPaused(p.orders);
        } catch (e) {
            toast.error(e.message);
        }
    }, [toast]);

    useEffect(() => { load(); }, [load]);

    if (!stats) return <div className="text-slate-400 text-sm">加载中…</div>;

    const garmentStatus = stats.garments_by_status || {};

    return (
        <div className="space-y-6">
            <div className="flex items-center justify-between">
                <div>
                    <h2 className="text-lg font-bold text-slate-800">收件台</h2>
                    <p className="text-sm text-slate-500">今日 {new Date().toLocaleDateString('zh-CN')} · 欢迎，{user.name}</p>
                </div>
                <Button variant="secondary" onClick={load}>刷新</Button>
            </div>

            {paused.length > 0 && (
                <Card className="border-red-200 bg-red-50/40">
                    <div className="flex items-start gap-3">
                        <span className="text-2xl">⚠️</span>
                        <div className="flex-1">
                            <h3 className="font-semibold text-red-800">有 {paused.length} 个订单已暂停，等待店员处理</h3>
                            <div className="mt-3 space-y-2">
                                {paused.map((o) => (
                                    <div key={o.id} className="flex items-center justify-between bg-white rounded-lg border border-red-200 px-4 py-2.5">
                                        <div>
                                            <div className="flex items-center gap-2">
                                                <span className="font-medium text-sm">{o.order_no}</span>
                                                <Badge tone="red">{o.pause_reason_label}</Badge>
                                                <span className="text-xs text-slate-400">{o.customer_name}</span>
                                            </div>
                                            <p className="text-xs text-slate-500 mt-0.5">{o.pause_note}</p>
                                            {o.open_pocket_items?.length > 0 && (
                                                <p className="text-xs text-amber-700 mt-0.5">
                                                    待处理口袋物品：{o.open_pocket_items.map((i) => i.description).join('、')}
                                                </p>
                                            )}
                                        </div>
                                        <Button size="sm" onClick={() => nav('order', { id: o.id })}>去处理</Button>
                                    </div>
                                ))}
                            </div>
                        </div>
                    </div>
                </Card>
            )}

            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                <Card title="订单数">
                    <div className="grid grid-cols-2 gap-3">
                        <Stat label="订单总数" value={stats.orders_total} tone="text-blue-600" big />
                        <Stat label="今日新增" value={stats.orders_today} />
                        <Stat label="收件预检中" value={stats.orders_in_intake} />
                        <Stat label="暂停" value={stats.orders_paused} tone={stats.orders_paused ? 'text-red-600' : ''} />
                        <Stat label="洗护中" value={stats.orders_in_wash} />
                        <Stat label="已完成" value={stats.orders_completed} tone="text-emerald-600" />
                    </div>
                </Card>

                <Card
                    title="实际衣物件数"
                    extra={<span className="text-xs text-slate-400">每件衣物独立编号，与订单数分开统计</span>}
                >
                    <div className="grid grid-cols-2 gap-3">
                        <Stat label="衣物总件数" value={stats.garments_total} tone="text-purple-600" big />
                        <Stat label="今日入库" value={stats.garments_today} />
                        {Object.entries(GARMENT_LABELS).map(([key, label]) => (
                            <Stat
                                key={key}
                                label={label}
                                value={garmentStatus[key] || 0}
                                tone={key === 'disputed' && garmentStatus[key] ? 'text-red-600' : ''}
                            />
                        ))}
                    </div>
                </Card>
            </div>

            <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                <Card title="进行中争议">
                    <div className="text-3xl font-bold text-red-600">{stats.disputes_open}</div>
                    <Button variant="secondary" size="sm" className="mt-3" onClick={() => nav('disputes')}>查看争议</Button>
                </Card>
                <Card title="待处理赔付">
                    <div className="text-3xl font-bold text-amber-600">{stats.compensations_pending}</div>
                    <p className="text-xs text-slate-400 mt-2">待审批 / 已批准待付</p>
                </Card>
                <Card title="累计已赔付">
                    <div className="text-3xl font-bold text-emerald-600">{formatMoney(stats.compensations_paid_total_cents)}</div>
                    <p className="text-xs text-slate-400 mt-2">模拟网关实际付款金额</p>
                </Card>
            </div>
        </div>
    );
}

const GARMENT_LABELS = {
    intake: '预检中',
    confirmed: '待洗护',
    washing: '洗护中',
    ready_inspection: '待完检',
    inspection_passed: '完检通过',
    disputed: '争议中',
    completed: '已交付',
};

function Stat({ label, value, tone = '', big }) {
    return (
        <div className="bg-slate-50 rounded-lg px-4 py-3">
            <div className="text-xs text-slate-500">{label}</div>
            <div className={`${big ? 'text-3xl' : 'text-2xl'} font-bold mt-0.5 ${tone || 'text-slate-800'}`}>{value}</div>
        </div>
    );
}
