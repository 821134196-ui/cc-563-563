import React, { useCallback, useEffect, useState } from 'react';
import { api } from '../lib/api.js';
import { useToast } from '../lib/toast.jsx';
import { useAuth } from '../lib/auth.jsx';
import { Card, Button, EmptyState, Badge } from '../components/ui.jsx';
import { GarmentStatusBadge } from '../components/statusBadges.jsx';
import MarkedPhoto from '../components/MarkedPhoto.jsx';

export default function WashPage({ nav }) {
    const toast = useToast();
    const { user } = useAuth();
    const [garments, setGarments] = useState([]);
    const [tab, setTab] = useState('confirmed');

    const load = useCallback(async () => {
        try {
            const d = await api('/wash/queue');
            setGarments(d.garments);
        } catch (e) {
            toast.error(e.message);
        }
    }, [toast]);

    useEffect(() => { load(); }, [load]);

    const doAction = async (id, action, msg) => {
        try {
            await api(`/garments/${id}/${action}`, { method: 'POST' });
            toast.success(msg);
            load();
        } catch (e) {
            toast.error(e.message);
        }
    };

    const groups = {
        confirmed: garments.filter((g) => g.status === 'confirmed'),
        washing: garments.filter((g) => g.status === 'washing'),
        ready: garments.filter((g) => g.status === 'ready_inspection'),
    };

    const tabs = [
        { key: 'confirmed', label: `待洗护（${groups.confirmed.length}）` },
        { key: 'washing', label: `洗护中（${groups.washing.length}）` },
        { key: 'ready', label: `待完检（${groups.ready.length}）` },
    ];

    const list = groups[tab] || [];

    return (
        <div className="space-y-4">
            <h2 className="text-lg font-bold text-slate-800">洗护作业</h2>
            <div className="flex gap-1 bg-slate-200/60 p-1 rounded-lg w-fit">
                {tabs.map((t) => (
                    <button
                        key={t.key}
                        onClick={() => setTab(t.key)}
                        className={`px-4 py-1.5 rounded-md text-sm font-medium ${tab === t.key ? 'bg-white shadow text-blue-700' : 'text-slate-600'}`}
                    >
                        {t.label}
                    </button>
                ))}
            </div>

            {list.length === 0 ? <Card><EmptyState>当前没有相关衣物</EmptyState></Card> : (
                <div className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
                    {list.map((g) => (
                        <Card key={g.id}>
                            <div className="flex items-center justify-between">
                                <span className="font-mono font-bold">#{g.tag_no}</span>
                                <GarmentStatusBadge {...g} />
                            </div>
                            <div className="text-xs text-slate-500 mt-1">
                                {g.order_no} · {g.customer_name} · {[g.category, g.color].filter(Boolean).join(' / ')}
                            </div>
                            {g.care_labels?.length > 0 && (
                                <div className="flex flex-wrap gap-1 mt-2">
                                    {g.care_labels.map((l) => (
                                        <Badge key={l} tone={l === 'no_wash' ? 'red' : 'blue'}>
                                            {l === 'no_wash' ? '⚠ 不可水洗' : CARE_LABEL_MAP[l] || l}
                                        </Badge>
                                    ))}
                                </div>
                            )}
                            <div className="mt-2 text-xs text-slate-400">
                                {g.washer ? `洗护责任人：${g.washer.name}` : '尚无洗护责任人'}
                            </div>
                            <div className="mt-3 flex gap-2">
                                {g.status === 'confirmed' && (
                                    <Button size="sm" onClick={() => doAction(g.id, 'wash/start', '已认领并开始洗护')}
                                        disabled={!['washer', 'manager'].includes(user.role)}>
                                        认领并开始洗护
                                    </Button>
                                )}
                                {g.status === 'washing' && (
                                    <>
                                        <Button size="sm" onClick={() => doAction(g.id, 'wash/finish', '洗护完成，等待完检')}
                                            disabled={g.washer?.id !== user.id && user.role !== 'manager'}>
                                            洗护完成
                                        </Button>
                                        {g.washer?.id !== user.id && <span className="text-xs text-slate-400 self-center">仅责任人可操作</span>}
                                    </>
                                )}
                                {g.status === 'ready_inspection' && (
                                    <Button size="sm" variant="secondary" onClick={() => nav('inspection')}>
                                        前往完检核对
                                    </Button>
                                )}
                            </div>
                        </Card>
                    ))}
                </div>
            )}
        </div>
    );
}

const CARE_LABEL_MAP = {
    hand_wash: '只能手洗',
    no_bleach: '不可漂白',
    low_heat: '低温熨烫',
    no_dryer: '不可烘干',
    dry_clean_only: '仅可干洗',
};
