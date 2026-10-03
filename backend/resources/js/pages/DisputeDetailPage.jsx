import React, { useCallback, useEffect, useState } from 'react';
import { api } from '../lib/api.js';
import { useToast } from '../lib/toast.jsx';
import { useAuth } from '../lib/auth.jsx';
import { Badge, Button, Card, EmptyState, Field, inputClass, Modal, formatMoney, formatTime } from '../components/ui.jsx';
import MarkedPhoto from '../components/MarkedPhoto.jsx';
import { photoUrl } from '../lib/api.js';

const TYPE_LABELS = {
    new_damage: '新增破损',
    new_stain: '新增污渍',
    missing: '缺件/丢失',
    other: '其他',
};

export default function DisputeDetailPage({ disputeId, nav }) {
    const toast = useToast();
    const { user } = useAuth();
    const [dispute, setDispute] = useState(null);
    const [comparison, setComparison] = useState(null);
    const [amount, setAmount] = useState(0);
    const [note, setNote] = useState('');
    const [review, setReview] = useState({ result: 'confirmed', finding: '' });
    const [busy, setBusy] = useState(false);

    const load = useCallback(async () => {
        try {
            const d = await api(`/disputes/${disputeId}`);
            setDispute(d.dispute);
            setAmount(d.dispute.requested_amount || 0);
            const c = await api(`/garments/${d.dispute.garment.id}/comparison`);
            setComparison(c);
        } catch (e) {
            toast.error(e.message);
        }
    }, [disputeId, toast]);

    useEffect(() => { load(); }, [load]);

    const post = async (path, body, okMsg) => {
        setBusy(true);
        try {
            await api(path, { method: 'POST', body });
            toast.success(okMsg);
            await load();
            return true;
        } catch (e) {
            toast.error(e.message);
            return false;
        } finally {
            setBusy(false);
        }
    };

    if (!dispute) return <div className="text-slate-400 text-sm">加载中…</div>;

    const comp = dispute.compensation;
    const isManager = user.role === 'manager';
    const washerId = dispute.garment.washer?.id;
    const isResponsibleWasher = washerId && washerId === user.id;
    const canSelfApproveBlocked = isManager && isResponsibleWasher;

    return (
        <div className="space-y-5">
            <div className="flex items-center gap-3">
                <Button variant="ghost" size="sm" onClick={() => nav('disputes')}>← 返回列表</Button>
                <h2 className="text-lg font-bold text-slate-800">争议 D-{String(dispute.id).padStart(4, '0')}</h2>
                <Badge tone="blue">{TYPE_LABELS[dispute.type]}</Badge>
                <Badge tone={dispute.status === 'resolved' ? 'green' : dispute.status === 'rejected' ? 'gray' : 'amber'}>
                    {STATUS_LABELS[dispute.status] || dispute.status}
                </Badge>
            </div>

            <div className="grid grid-cols-1 lg:grid-cols-3 gap-5">
                <div className="lg:col-span-2 space-y-4">
                    <Card title="争议情况">
                        <dl className="text-sm space-y-2">
                            <div><span className="text-slate-400">订单：</span><span className="text-blue-700">{dispute.order_no}</span></div>
                            <div>
                                <span className="text-slate-400">衣物：</span>
                                <span className="font-mono font-semibold">{dispute.garment.tag_no}</span>
                                {dispute.garment.washer && (
                                    <span className="text-slate-500">（洗护责任人：{dispute.garment.washer.name}）</span>
                                )}
                            </div>
                            <div><span className="text-slate-400">顾客索赔：</span><strong>{formatMoney(dispute.requested_amount)}</strong></div>
                            <div className="bg-slate-50 rounded-lg p-3 text-slate-700">{dispute.description}</div>
                            <div className="text-xs text-slate-400">提出人：{dispute.opened_by} · {formatTime(dispute.created_at)}</div>
                        </dl>
                    </Card>

                    {comparison && (
                        <Card title="证据对比（原始预检 ↔ 完检当前）">
                            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <PhotoColumn
                                    title="🔒 原始预检照片（不可变）"
                                    photos={comparison.intake_photos}
                                    marks={comparison.garment.marks}
                                />
                                <PhotoColumn
                                    title="完检照片"
                                    photos={comparison.completion_photos}
                                    marks={[]}
                                />
                            </div>
                        </Card>
                    )}

                    <Card title="复检记录">
                        {dispute.reviews.length === 0 ? <EmptyState>尚未发起复检</EmptyState> : (
                            <ol className="space-y-3">
                                {dispute.reviews.map((r) => (
                                    <li key={r.id} className="border border-slate-200 rounded-lg p-3">
                                        <div className="flex items-center gap-2 text-sm">
                                            <Badge tone={r.result === 'confirmed' ? 'red' : r.result === 'not_found' ? 'green' : 'amber'}>
                                                {r.result_label}
                                            </Badge>
                                            <span className="text-slate-600">复检人：{r.reviewer}</span>
                                            <span className="text-xs text-slate-400 ml-auto">{formatTime(r.created_at)}</span>
                                        </div>
                                        <p className="text-sm text-slate-700 mt-2">{r.finding}</p>
                                    </li>
                                ))}
                            </ol>
                        )}

                        {['open', 'rechecking'].includes(dispute.status) && (
                            <div className="mt-4 pt-4 border-t border-slate-100">
                                {dispute.status === 'open' && (
                                    <Button
                                        variant="secondary"
                                        disabled={busy}
                                        onClick={() => post(`/disputes/${dispute.id}/recheck`, {}, '复检已发起')}
                                    >
                                        发起复检
                                    </Button>
                                )}
                                {dispute.status === 'rechecking' && (
                                    <div className="space-y-3">
                                        <Field label="复检结论">
                                            <select className={inputClass} value={review.result}
                                                onChange={(e) => setReview({ ...review, result: e.target.value })}>
                                                <option value="confirmed">确认存在差异（进入赔偿协商）</option>
                                                <option value="inconclusive">存疑（进入赔偿协商）</option>
                                                <option value="not_found">未见差异（退回处理）</option>
                                            </select>
                                        </Field>
                                        <Field label="复检说明">
                                            <textarea className={inputClass} rows={3} value={review.finding}
                                                onChange={(e) => setReview({ ...review, finding: e.target.value })}
                                                placeholder="对照原始预检照片与完检照片说明发现" />
                                        </Field>
                                        <Button disabled={busy || !review.finding.trim()}
                                            onClick={() => post(`/disputes/${dispute.id}/reviews`, review, '复检结论已提交')}>
                                            提交复检结论
                                        </Button>
                                    </div>
                                )}
                            </div>
                        )}
                    </Card>
                </div>

                {/* 赔偿协商侧栏 */}
                <div className="space-y-4">
                    <Card title="赔偿处理">
                        {!comp ? (
                            <div className="space-y-3">
                                {dispute.status === 'negotiating' || dispute.status === 'open' ? (
                                    <>
                                        <p className="text-sm text-slate-500">复检确认差异后，与顾客协商赔付金额，提交经理审批。</p>
                                        <Field label={`赔付金额（分）= ${formatMoney(amount)}`}>
                                            <input type="number" min="1" className={inputClass} value={amount}
                                                onChange={(e) => setAmount(Number(e.target.value))} />
                                        </Field>
                                        <Button className="w-full" disabled={busy || amount <= 0}
                                            onClick={() => post(`/disputes/${dispute.id}/compensation`, { amount }, '赔付方案已提交审批')}>
                                            提交赔付申请
                                        </Button>
                                    </>
                                ) : (
                                    <p className="text-sm text-slate-400">需先完成复检并确认差异，才能发起赔付协商。</p>
                                )}
                            </div>
                        ) : (
                            <CompensationPanel
                                comp={comp}
                                isManager={isManager}
                                canSelfApproveBlocked={canSelfApproveBlocked}
                                currentUserName={user.name}
                                washerName={dispute.garment.washer?.name}
                                busy={busy}
                                note={note}
                                setNote={setNote}
                                onApprove={(approved) => post(`/compensations/${comp.id}/approve`, { approved, note }, approved ? '赔付已批准' : '赔付已驳回')}
                                onRepropose={() => post(`/compensations/${comp.id}/repropose`, { amount }, '赔付方案已修改重新提交')}
                                onPay={() => post(`/compensations/${comp.id}/pay`, {}, '赔付已支付，回执已生成')}
                                amount={amount}
                                setAmount={setAmount}
                            />
                        )}
                    </Card>

                    {['open', 'rechecking', 'negotiating'].includes(dispute.status) && (
                        <Card title="无责处理">
                            <p className="text-sm text-slate-500 mb-3">如复检确认门店无责任（差异为收件时已有），可驳回并关闭争议。</p>
                            <Field label="关闭说明">
                                <textarea className={inputClass} rows={2} value={note} onChange={(e) => setNote(e.target.value)}
                                    placeholder="如：该破损在预检照片中已存在并标注" />
                            </Field>
                            <Button variant="secondary" className="w-full mt-2" disabled={busy || !note.trim()}
                                onClick={() => post(`/disputes/${dispute.id}/close`, { note }, '争议已关闭')}>
                                驳回并关闭争议
                            </Button>
                        </Card>
                    )}

                    {dispute.resolution_note && (
                        <Card title="处理结果">
                            <p className="text-sm text-slate-600">{dispute.resolution_note}</p>
                            <p className="text-xs text-slate-400 mt-2">{formatTime(dispute.closed_at)}</p>
                        </Card>
                    )}
                </div>
            </div>
        </div>
    );
}

function CompensationPanel({ comp, isManager, canSelfApproveBlocked, currentUserName, washerName, busy, note, setNote, onApprove, onRepropose, onPay, amount, setAmount }) {
    return (
        <div className="space-y-3 text-sm">
            <div className="flex items-center justify-between">
                <span className="text-slate-500">赔付金额</span>
                <strong className="text-lg">{formatMoney(comp.amount)}</strong>
            </div>
            <div className="flex items-center justify-between">
                <span className="text-slate-500">状态</span>
                <Badge tone={comp.status === 'paid' ? 'green' : comp.status === 'rejected' ? 'gray' : 'amber'}>{comp.status_label}</Badge>
            </div>
            {comp.approved_by_name && (
                <div className="flex items-center justify-between">
                    <span className="text-slate-500">审批人</span>
                    <span>{comp.approved_by_name} · {formatTime(comp.approved_at)}</span>
                </div>
            )}
            {comp.approval_note && <p className="bg-slate-50 rounded p-2 text-xs text-slate-600">{comp.approval_note}</p>}

            {comp.receipt && (
                <div className="bg-emerald-50 border border-emerald-200 rounded-lg p-3 text-xs space-y-1">
                    <div className="font-semibold text-emerald-800">✅ 赔付回执（模拟网关）</div>
                    <div>回执号：<span className="font-mono">{comp.receipt.receipt_no}</span></div>
                    <div>幂等键：<span className="font-mono">{comp.receipt.idempotency_key}</span></div>
                    <div>网关状态：{comp.receipt.gateway_status}</div>
                </div>
            )}

            {comp.status === 'requested' && (
                isManager ? (
                    canSelfApproveBlocked ? (
                        <div className="bg-red-50 border border-red-200 rounded-lg p-3 text-xs text-red-700">
                            ⛔ 您（{currentUserName}）是该衣物的洗护责任人，不能审批自己责任范围内的赔付，请由其他经理处理。
                        </div>
                    ) : (
                        <div className="space-y-2 pt-2 border-t border-slate-100">
                            <Field label="审批意见">
                                <textarea className={inputClass} rows={2} value={note} onChange={(e) => setNote(e.target.value)} />
                            </Field>
                            <div className="flex gap-2">
                                <Button variant="danger" className="flex-1" disabled={busy} onClick={() => onApprove(false)}>驳回</Button>
                                <Button variant="success" className="flex-1" disabled={busy} onClick={() => onApprove(true)}>批准赔付</Button>
                            </div>
                        </div>
                    )
                ) : (
                    <p className="text-xs text-slate-400">等待门店经理审批（洗护责任人无权审批本人责任赔付）。</p>
                )
            )}

            {comp.status === 'approved' && (
                <Button variant="success" className="w-full" disabled={busy} onClick={onPay}>
                    确认支付（调用模拟赔付接口）
                </Button>
            )}

            {comp.status === 'rejected' && (
                <div className="space-y-2 pt-2 border-t border-slate-100">
                    <Field label={`修改金额重新提交 = ${formatMoney(amount)}`}>
                        <input type="number" min="1" className={inputClass} value={amount}
                            onChange={(e) => setAmount(Number(e.target.value))} />
                    </Field>
                    <Button className="w-full" disabled={busy || amount <= 0} onClick={onRepropose}>修改方案并重新提交</Button>
                </div>
            )}

            {comp.status === 'paid' && (
                <p className="text-xs text-slate-400">该赔付已支付完成，重复支付请求会被系统拦截。</p>
            )}
        </div>
    );
}

function PhotoColumn({ title, photos, marks }) {
    return (
        <div>
            <div className="text-xs font-medium text-slate-500 mb-2">{title}（{photos.length}）</div>
            {photos.length === 0 ? <EmptyState>无照片</EmptyState> : (
                <div className="grid grid-cols-2 gap-2">
                    {photos.map((p) => <MarkedPhoto key={p.id} photo={p} marks={marks} />)}
                </div>
            )}
        </div>
    );
}

const STATUS_LABELS = {
    open: '待处理',
    rechecking: '复检中',
    negotiating: '赔偿协商中',
    resolved: '已解决',
    rejected: '已驳回/关闭',
};
