import React, { useCallback, useEffect, useState } from 'react';
import { api } from '../lib/api.js';
import { useToast } from '../lib/toast.jsx';
import { useAuth } from '../lib/auth.jsx';
import { Badge, Button, Card, EmptyState, Field, inputClass, Modal, formatTime } from '../components/ui.jsx';
import { OrderStatusBadge } from '../components/statusBadges.jsx';
import GarmentIntakeCard from '../components/GarmentIntakeCard.jsx';

const CARE_OPTIONS = [
    { key: 'no_wash', label: '不可水洗（自动暂停订单）' },
    { key: 'hand_wash', label: '只能手洗' },
    { key: 'no_bleach', label: '不可漂白' },
    { key: 'low_heat', label: '低温熨烫' },
    { key: 'no_dryer', label: '不可烘干' },
    { key: 'dry_clean_only', label: '仅可干洗' },
];

export default function OrderDetailPage({ orderId, nav }) {
    const toast = useToast();
    const { user } = useAuth();
    const [order, setOrder] = useState(null);
    const [events, setEvents] = useState([]);
    const [showAdd, setShowAdd] = useState(false);
    const [showConfirm, setShowConfirm] = useState(false);
    const [showPause, setShowPause] = useState(false);
    const [signature, setSignature] = useState('');
    const [refuseNote, setRefuseNote] = useState('');
    const [pauseForm, setPauseForm] = useState({ reason: 'refused_signature', note: '' });
    const [resumeNote, setResumeNote] = useState('');
    const [busy, setBusy] = useState(false);
    const [form, setForm] = useState({ tag_no: '', category: '', color: '', brand: '', description: '', care_labels: [] });

    const load = useCallback(async () => {
        try {
            const d = await api(`/orders/${orderId}`);
            setOrder(d.order);
            const e = await api(`/orders/${orderId}/events`);
            setEvents(e.events);
        } catch (err) {
            toast.error(err.message);
        }
    }, [orderId, toast]);

    useEffect(() => { load(); }, [load]);

    const act = async (path, body, successMsg) => {
        setBusy(true);
        try {
            const d = await api(path, { method: 'POST', body });
            toast.success(successMsg);
            if (d.order) setOrder(d.order);
            await load();
        } catch (err) {
            toast.error(err.message);
        } finally {
            setBusy(false);
        }
    };

    const addGarment = async (e) => {
        e.preventDefault();
        setBusy(true);
        try {
            const d = await api(`/orders/${orderId}/garments`, { method: 'POST', body: form });
            toast.success(`衣物 ${d.garment.tag_no} 已入库`);
            setShowAdd(false);
            setForm({ tag_no: '', category: '', color: '', brand: '', description: '', care_labels: [] });
            setOrder(d.order);
            await load();
        } catch (err) {
            toast.error(err.message);
        } finally {
            setBusy(false);
        }
    };

    if (!order) return <div className="text-slate-400 text-sm">加载中…</div>;

    const canEditIntake = ['clerk', 'manager'].includes(user.role);
    const inIntake = order.status === 'intake';
    const awaiting = order.status === 'awaiting_confirmation';
    const paused = order.status === 'paused';

    return (
        <div className="space-y-5">
            <div className="flex items-center gap-3">
                <Button variant="ghost" size="sm" onClick={() => nav('orders')}>← 返回列表</Button>
                <h2 className="text-lg font-bold text-slate-800">{order.order_no}</h2>
                <OrderStatusBadge {...order} />
                <span className="text-sm text-slate-500">{order.customer_name} {order.customer_phone}</span>
                {order.signature_name && <Badge tone="green">✍ 顾客已签名：{order.signature_name}</Badge>}
            </div>

            {paused && (
                <Card className="border-red-200">
                    <div className="flex items-start gap-4">
                        <span className="text-3xl">⛔</span>
                        <div className="flex-1">
                            <h3 className="font-bold text-red-800">订单已暂停：{order.pause.reason_label}</h3>
                            <p className="text-sm text-slate-600 mt-1">{order.pause.note}</p>
                            <p className="text-xs text-slate-400 mt-1">
                                暂停人：{order.pause.paused_by || '—'} · {formatTime(order.pause.paused_at)}
                            </p>
                            {order.pause.reason === 'pocket_item' && (
                                <p className="text-xs text-amber-700 mt-2">请逐件登记口袋物品归还，全部归还后订单自动/手动恢复。</p>
                            )}
                            {canEditIntake && (
                                <div className="mt-3 flex items-end gap-2">
                                    <div className="flex-1 max-w-md">
                                        <Field label="处理说明">
                                            <input className={inputClass} value={resumeNote} onChange={(e) => setResumeNote(e.target.value)} placeholder="如：已与顾客电话确认改为干洗" />
                                        </Field>
                                    </div>
                                    <Button
                                        variant="success"
                                        disabled={busy}
                                        onClick={() => act(`/orders/${orderId}/resume`, { note: resumeNote || '暂停事项已处理' }, '订单已恢复')}
                                    >
                                        事项已处理，恢复订单
                                    </Button>
                                </div>
                            )}
                        </div>
                    </div>
                </Card>
            )}

            <div className="grid grid-cols-1 lg:grid-cols-3 gap-5">
                <div className="lg:col-span-2 space-y-4">
                    <Card
                        title={`衣物清单（${order.garment_count} 件）`}
                        extra={
                            <div className="flex gap-2">
                                {canEditIntake && (inIntake || paused) && (
                                    <Button size="sm" onClick={() => setShowAdd(true)} disabled={paused}>+ 衣物入库</Button>
                                )}
                                {canEditIntake && (
                                    <Button size="sm" variant="secondary" onClick={() => setShowPause(true)} disabled={paused || order.status === 'completed'}>
                                        手动暂停
                                    </Button>
                                )}
                            </div>
                        }
                    >
                        {order.garments.length === 0 ? <EmptyState>还没有录入衣物，点击“衣物入库”逐件编号拍照</EmptyState> : (
                            <div className="space-y-3">
                                {order.garments.map((g) => (
                                    <GarmentIntakeCard key={g.id} garment={g} orderStatus={order.status} onChange={load} />
                                ))}
                            </div>
                        )}

                        {(inIntake || awaiting) && canEditIntake && (
                            <div className="mt-5 pt-4 border-t border-slate-100 flex flex-wrap items-center justify-between gap-3">
                                <div className="text-sm text-slate-500">
                                    {inIntake
                                        ? '完成所有衣物的拍照、污渍破损与护理标签标注后，请顾客当面确认。'
                                        : '等待顾客确认预检结果；顾客如不认可可以拒签，订单将暂停处理。'}
                                </div>
                                <div className="flex gap-2">
                                    {inIntake && (
                                        <Button onClick={() => act(`/orders/${orderId}/submit-confirmation`, {}, '已提交顾客确认')} disabled={busy || order.garment_count === 0}>
                                            提交顾客确认
                                        </Button>
                                    )}
                                    {awaiting && (
                                        <>
                                            <Button variant="danger" disabled={busy} onClick={() => act(`/orders/${orderId}/refuse`, { note: refuseNote }, '已登记顾客拒签，订单暂停')}>
                                                顾客拒签
                                            </Button>
                                            <Button variant="success" onClick={() => setShowConfirm(true)} disabled={busy}>
                                                顾客确认签名，进入洗护
                                            </Button>
                                        </>
                                    )}
                                </div>
                            </div>
                        )}
                    </Card>
                </div>

                <div className="space-y-4">
                    <Card title="订单信息">
                        <dl className="text-sm space-y-2">
                            <Row k="订单号" v={<span className="font-mono">{order.order_no}</span>} />
                            <Row k="顾客" v={order.customer_name} />
                            <Row k="电话" v={order.customer_phone || '—'} />
                            <Row k="衣物件数" v={<strong>{order.garment_count} 件</strong>} />
                            <Row k="创建时间" v={formatTime(order.created_at)} />
                            <Row k="确认时间" v={formatTime(order.confirmed_at)} />
                            <Row k="完成时间" v={formatTime(order.completed_at)} />
                        </dl>
                    </Card>

                    <Card title="责任处理过程（审计时间线）">
                        {events.length === 0 ? <EmptyState>暂无记录</EmptyState> : (
                            <ol className="relative border-l-2 border-slate-100 ml-1.5 space-y-3">
                                {events.map((ev) => (
                                    <li key={ev.id} className="ml-4">
                                        <span className="absolute -left-[7px] w-3 h-3 rounded-full bg-blue-400 border-2 border-white" style={{ marginTop: 4 }} />
                                        <div className="text-xs">
                                            <span className="font-medium text-slate-700">{EVENT_LABELS[ev.type] || ev.type}</span>
                                            {ev.actor && <span className="text-slate-400"> · {ev.actor}</span>}
                                        </div>
                                        {ev.garment_tag && <div className="text-xs text-blue-600 mt-0.5">衣物 #{ev.garment_tag}</div>}
                                        {ev.payload && Object.keys(ev.payload).length > 0 && (
                                            <pre className="text-[10px] text-slate-400 bg-slate-50 rounded p-1.5 mt-1 overflow-x-auto whitespace-pre-wrap break-all">
                                                {JSON.stringify(ev.payload, null, 0)}
                                            </pre>
                                        )}
                                        <div className="text-[10px] text-slate-300 mt-0.5">{formatTime(ev.at)}</div>
                                    </li>
                                ))}
                            </ol>
                        )}
                    </Card>
                </div>
            </div>

            {/* 入库弹窗 */}
            <Modal
                open={showAdd}
                title="衣物入库（逐件编号）"
                wide
                onClose={() => setShowAdd(false)}
                footer={
                    <>
                        <Button variant="secondary" onClick={() => setShowAdd(false)}>取消</Button>
                        <Button form="add-garment-form" type="submit" disabled={busy}>保存并开始拍照</Button>
                    </>
                }
            >
                <form id="add-garment-form" onSubmit={addGarment} className="grid grid-cols-2 gap-4">
                    <Field label="衣物编号 / 吊牌号" hint="全局唯一，重复编号会被拦截">
                        <input className={inputClass} required value={form.tag_no} onChange={(e) => setForm({ ...form, tag_no: e.target.value })} placeholder="如 G-20261003-001" />
                    </Field>
                    <Field label="品类">
                        <input className={inputClass} value={form.category} onChange={(e) => setForm({ ...form, category: e.target.value })} placeholder="如 羊毛大衣" />
                    </Field>
                    <Field label="颜色">
                        <input className={inputClass} value={form.color} onChange={(e) => setForm({ ...form, color: e.target.value })} />
                    </Field>
                    <Field label="品牌">
                        <input className={inputClass} value={form.brand} onChange={(e) => setForm({ ...form, brand: e.target.value })} />
                    </Field>
                    <div className="col-span-2">
                        <Field label="护理标签">
                            <div className="grid grid-cols-2 gap-1.5">
                                {CARE_OPTIONS.map((c) => (
                                    <label key={c.key} className={`flex items-center gap-2 text-sm rounded-lg border px-3 py-2 cursor-pointer ${
                                        form.care_labels.includes(c.key) ? 'border-blue-400 bg-blue-50' : 'border-slate-200'
                                    }`}>
                                        <input
                                            type="checkbox"
                                            checked={form.care_labels.includes(c.key)}
                                            onChange={() => setForm({
                                                ...form,
                                                care_labels: form.care_labels.includes(c.key)
                                                    ? form.care_labels.filter((k) => k !== c.key)
                                                    : [...form.care_labels, c.key],
                                            })}
                                        />
                                        {c.label}
                                    </label>
                                ))}
                            </div>
                        </Field>
                    </div>
                    <div className="col-span-2">
                        <Field label="面料/备注">
                            <textarea className={inputClass} rows={2} value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} />
                        </Field>
                    </div>
                </form>
            </Modal>

            {/* 顾客签名确认 */}
            <Modal
                open={showConfirm}
                title="顾客确认预检结果"
                onClose={() => setShowConfirm(false)}
                footer={
                    <>
                        <Button variant="secondary" onClick={() => setShowConfirm(false)}>取消</Button>
                        <Button variant="success" disabled={busy} onClick={() => {
                            act(`/orders/${orderId}/confirm`, { signature_name: signature }, '顾客已确认，预检证据锁定，进入洗护').then(() => setShowConfirm(false));
                            setShowConfirm(false);
                        }}>确认签名</Button>
                    </>
                }
            >
                <div className="space-y-3 text-sm">
                    <p className="text-slate-600">
                        顾客已当面核对 {order.garment_count} 件衣物的原始照片、已有污渍/破损标注与护理标签。
                        <strong className="text-red-600">确认后预检照片将永久锁定，不可替换、追加或删除。</strong>
                    </p>
                    <Field label="顾客签名（请输入姓名）">
                        <input className={inputClass} value={signature} onChange={(e) => setSignature(e.target.value)} placeholder="顾客亲笔签名姓名" />
                    </Field>
                </div>
            </Modal>

            {/* 手动暂停 */}
            <Modal
                open={showPause}
                title="暂停订单"
                onClose={() => setShowPause(false)}
                footer={
                    <>
                        <Button variant="secondary" onClick={() => setShowPause(false)}>取消</Button>
                        <Button variant="danger" disabled={busy} onClick={() => {
                            act(`/orders/${orderId}/pause`, pauseForm, '订单已暂停');
                            setShowPause(false);
                        }}>确认暂停</Button>
                    </>
                }
            >
                <div className="space-y-3">
                    <Field label="暂停原因">
                        <select className={inputClass} value={pauseForm.reason} onChange={(e) => setPauseForm({ ...pauseForm, reason: e.target.value })}>
                            <option value="refused_signature">顾客拒签</option>
                            <option value="no_wash_label">护理标签禁止水洗</option>
                            <option value="pocket_item">发现口袋物品</option>
                        </select>
                    </Field>
                    <Field label="情况说明">
                        <textarea className={inputClass} rows={3} value={pauseForm.note} onChange={(e) => setPauseForm({ ...pauseForm, note: e.target.value })} />
                    </Field>
                </div>
            </Modal>
        </div>
    );
}

function Row({ k, v }) {
    return (
        <div className="flex justify-between gap-3">
            <dt className="text-slate-400 shrink-0">{k}</dt>
            <dd className="text-slate-700 text-right">{v}</dd>
        </div>
    );
}

const EVENT_LABELS = {
    'order.created': '创建订单',
    'order.paused': '订单暂停',
    'order.resumed': '订单恢复',
    'order.submitted_for_confirmation': '提交顾客确认',
    'order.confirmed': '顾客确认签名',
    'order.completed': '订单完成',
    'garment.registered': '衣物入库',
    'garment.mark_added': '添加污渍/破损标注',
    'garment.pocket_item_found': '发现口袋物品',
    'garment.pocket_item_returned': '口袋物品已归还',
    'garment.wash_started': '开始洗护',
    'garment.washed': '洗护完成',
    'garment.inspection_passed': '完检通过',
    'garment.handed_over': '交付顾客',
    'dispute.opened': '发起争议',
    'dispute.recheck_started': '发起复检',
    'dispute.review_submitted': '复检结论',
    'dispute.closed': '争议关闭',
    'dispute.resolved': '争议已解决',
    'compensation.proposed': '提出赔付',
    'compensation.reproposed': '修改赔付方案',
    'compensation.approved': '赔付批准',
    'compensation.rejected': '赔付驳回',
    'compensation.paid': '赔付已支付',
    'compensation.self_approval_blocked': '⛔ 自审被拦截',
    'compensation.duplicate_receipt_ignored': '重复回执被忽略',
    'compensation.receipt_confirmed': '回执确认',
};
