import React, { useCallback, useEffect, useState } from 'react';
import { api } from '../lib/api.js';
import { useToast } from '../lib/toast.jsx';
import { useAuth } from '../lib/auth.jsx';
import { Card, Button, EmptyState, Badge, Modal, Field, inputClass } from '../components/ui.jsx';
import { GarmentStatusBadge } from '../components/statusBadges.jsx';
import PhotoUploader from '../components/PhotoUploader.jsx';
import MarkedPhoto from '../components/MarkedPhoto.jsx';

export default function InspectionPage({ nav }) {
    const toast = useToast();
    const { user } = useAuth();
    const [garments, setGarments] = useState([]);
    const [selectedId, setSelectedId] = useState(null);
    const [comparison, setComparison] = useState(null);
    const [showDispute, setShowDispute] = useState(false);
    const [disputeForm, setDisputeForm] = useState({ type: 'new_damage', description: '', requested_amount: 0 });
    const [busy, setBusy] = useState(false);

    const load = useCallback(async () => {
        try {
            const d = await api('/wash/queue');
            const list = d.garments.filter((g) =>
                ['ready_inspection', 'disputed', 'rechecking', 'inspection_passed'].includes(g.status));
            setGarments(list);
            if (selectedId) {
                loadComparison(selectedId);
            }
        } catch (e) {
            toast.error(e.message);
        }
    }, [toast]);

    const loadComparison = useCallback(async (id) => {
        try {
            const d = await api(`/garments/${id}/comparison`);
            setComparison(d);
        } catch (e) {
            toast.error(e.message);
        }
    }, [toast]);

    useEffect(() => { load(); }, [load]);

    const selectGarment = (id) => {
        setSelectedId(id);
        loadComparison(id);
    };

    const pass = async () => {
        setBusy(true);
        try {
            await api(`/garments/${selectedId}/inspection/pass`, { method: 'POST' });
            toast.success('完检通过，无差异');
            setSelectedId(null);
            setComparison(null);
            load();
        } catch (e) {
            toast.error(e.message);
        } finally {
            setBusy(false);
        }
    };

    const handover = async (id) => {
        try {
            await api(`/garments/${id}/handover`, { method: 'POST' });
            toast.success('已交付顾客');
            load();
        } catch (e) {
            toast.error(e.message);
        }
    };

    const openDispute = async (e) => {
        e.preventDefault();
        setBusy(true);
        try {
            const d = await api(`/garments/${selectedId}/disputes`, { method: 'POST', body: disputeForm });
            toast.success('已发起争议与复检');
            setShowDispute(false);
            setDisputeForm({ type: 'new_damage', description: '', requested_amount: 0 });
            nav('dispute', { id: d.dispute.id });
        } catch (e2) {
            toast.error(e2.message);
        } finally {
            setBusy(false);
        }
    };

    return (
        <div className="space-y-4">
            <h2 className="text-lg font-bold text-slate-800">完检核对</h2>

            <div className="grid grid-cols-1 lg:grid-cols-4 gap-4">
                {/* 衣物列表 */}
                <Card title="待核对此批次" className="lg:col-span-1">
                    {garments.length === 0 ? <EmptyState>暂无待完检衣物</EmptyState> : (
                        <div className="space-y-1.5">
                            {garments.map((g) => (
                                <button
                                    key={g.id}
                                    onClick={() => selectGarment(g.id)}
                                    className={`w-full text-left px-3 py-2 rounded-lg border text-sm transition ${
                                        selectedId === g.id ? 'border-blue-400 bg-blue-50' : 'border-slate-200 hover:bg-slate-50'
                                    }`}
                                >
                                    <div className="flex items-center justify-between">
                                        <span className="font-mono font-semibold">#{g.tag_no}</span>
                                        <GarmentStatusBadge {...g} />
                                    </div>
                                    <div className="text-xs text-slate-400 mt-0.5">
                                        {[g.category, g.color].filter(Boolean).join(' / ') || '—'}
                                    </div>
                                </button>
                            ))}
                        </div>
                    )}
                </Card>

                {/* 并排对比 */}
                <div className="lg:col-span-3 space-y-4">
                    {!comparison ? (
                        <Card><EmptyState>请从左侧选择一件衣物，并排核对原始预检照片与完检照片</EmptyState></Card>
                    ) : (
                        <>
                            <Card
                                title={
                                    <span className="flex items-center gap-2">
                                        衣物 #{comparison.garment.tag_no}
                                        <GarmentStatusBadge {...comparison.garment} />
                                        {comparison.garment.washer && <Badge tone="gray">洗护：{comparison.garment.washer.name}</Badge>}
                                    </span>
                                }
                            >
                                <ComparisonPanel comparison={comparison} />
                            </Card>

                            {comparison.garment.status === 'ready_inspection' && (
                                <Card title="完检操作">
                                    <div className="flex flex-wrap items-center justify-between gap-3">
                                        <div className="flex-1 min-w-60">
                                            <PhotoUploader
                                                garmentId={comparison.garment.id}
                                                stage="completion"
                                                locked={false}
                                                disabled={false}
                                                onUploaded={() => loadComparison(selectedId)}
                                            />
                                            <p className="text-xs text-slate-400 mt-1.5">拍摄洗护完成后的当前状态，与左侧原始照片逐张比对。</p>
                                        </div>
                                        <div className="flex gap-2">
                                            <Button variant="danger" onClick={() => setShowDispute(true)} disabled={busy}>
                                                发现差异，发起复检/赔偿
                                            </Button>
                                            <Button variant="success" onClick={pass} disabled={busy || comparison.completion_photos.length === 0}>
                                                核对一致，完检通过
                                            </Button>
                                        </div>
                                    </div>
                                </Card>
                            )}

                            {comparison.garment.status === 'inspection_passed' && (
                                <Card title="交付">
                                    <div className="flex items-center justify-between">
                                        <p className="text-sm text-slate-500">完检通过，可交付顾客。</p>
                                        <Button variant="success" onClick={() => handover(comparison.garment.id)}>交付顾客</Button>
                                    </div>
                                </Card>
                            )}

                            {(comparison.garment.status === 'disputed' || comparison.garment.status === 'rechecking') && (
                                <Card className="border-red-200 bg-red-50/40">
                                    <p className="text-sm text-red-700">该衣物存在进行中的争议，请到「争议与赔付」处理。</p>
                                </Card>
                            )}
                        </>
                    )}
                </div>
            </div>

            {/* 发起争议 */}
            <Modal
                open={showDispute}
                title="发现差异：发起复检与赔偿协商"
                wide
                onClose={() => setShowDispute(false)}
                footer={
                    <>
                        <Button variant="secondary" onClick={() => setShowDispute(false)}>取消</Button>
                        <Button variant="danger" form="dispute-form" type="submit" disabled={busy}>发起争议</Button>
                    </>
                }
            >
                <form id="dispute-form" onSubmit={openDispute} className="space-y-4">
                    <Field label="差异类型">
                        <select className={inputClass} value={disputeForm.type} onChange={(e) => setDisputeForm({ ...disputeForm, type: e.target.value })}>
                            <option value="new_damage">新增破损</option>
                            <option value="new_stain">新增污渍</option>
                            <option value="missing">缺件/附件丢失</option>
                            <option value="other">其他问题</option>
                        </select>
                    </Field>
                    <Field label="差异描述（对比原始照片说明位置、形态）">
                        <textarea className={inputClass} rows={3} required value={disputeForm.description}
                            onChange={(e) => setDisputeForm({ ...disputeForm, description: e.target.value })} />
                    </Field>
                    <Field label="顾客索赔金额（分）" hint="如 5000 表示 ¥50.00">
                        <input type="number" min="0" className={inputClass} value={disputeForm.requested_amount}
                            onChange={(e) => setDisputeForm({ ...disputeForm, requested_amount: Number(e.target.value) })} />
                    </Field>
                </form>
            </Modal>
        </div>
    );
}

function ComparisonPanel({ comparison }) {
    const { garment, intake_photos, completion_photos } = comparison;
    const intakeMarks = garment.marks || [];

    return (
        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
                <div className="flex items-center gap-2 mb-2">
                    <Badge tone="blue">原始预检照片</Badge>
                    <Badge tone="purple">🔒 已锁定不可替换</Badge>
                    <span className="text-xs text-slate-400">{intake_photos.length} 张</span>
                </div>
                {intake_photos.length === 0 ? (
                    <div className="text-sm text-slate-400 border border-dashed border-slate-200 rounded-lg p-6 text-center">无预检照片</div>
                ) : (
                    <div className="grid grid-cols-2 gap-2">
                        {intake_photos.map((p) => <MarkedPhoto key={p.id} photo={p} marks={intakeMarks} />)}
                    </div>
                )}
                {intakeMarks.length > 0 && (
                    <div className="mt-2 text-xs text-slate-500">
                        收件时已标注：
                        {intakeMarks.map((m) => `${m.kind_label}${m.label ? `(${m.label})` : ''}`).join('、')}
                    </div>
                )}
            </div>
            <div>
                <div className="flex items-center gap-2 mb-2">
                    <Badge tone="amber">当前完检照片</Badge>
                    <span className="text-xs text-slate-400">{completion_photos.length} 张</span>
                </div>
                {completion_photos.length === 0 ? (
                    <div className="text-sm text-amber-600 border border-dashed border-amber-200 bg-amber-50 rounded-lg p-6 text-center">
                        尚未拍摄完检照片，请先上传当前照片再核对
                    </div>
                ) : (
                    <div className="grid grid-cols-2 gap-2">
                        {completion_photos.map((p) => <MarkedPhoto key={p.id} photo={p} marks={[]} />)}
                    </div>
                )}
            </div>
        </div>
    );
}
