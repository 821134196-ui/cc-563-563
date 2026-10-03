import React, { useState } from 'react';
import { api } from '../lib/api.js';
import { useToast } from '../lib/toast.jsx';
import { Badge, Button, Field, inputClass } from './ui.jsx';
import { GarmentStatusBadge } from './statusBadges.jsx';
import PhotoUploader from './PhotoUploader.jsx';
import MarkedPhoto from './MarkedPhoto.jsx';

const CARE_LABELS = [
    { key: 'no_wash', label: '不可水洗', danger: true },
    { key: 'hand_wash', label: '只能手洗' },
    { key: 'no_bleach', label: '不可漂白' },
    { key: 'low_heat', label: '低温熨烫' },
    { key: 'no_dryer', label: '不可烘干' },
    { key: 'dry_clean_only', label: '仅可干洗' },
];

export default function GarmentIntakeCard({ garment, orderStatus, onChange }) {
    const toast = useToast();
    const [showMark, setShowMark] = useState(false);
    const [showPocket, setShowPocket] = useState(false);
    const [mark, setMark] = useState({ kind: 'stain', label: '', description: '', x: 150, y: 150 });
    const [pocket, setPocket] = useState({ description: '', note: '' });
    const [busy, setBusy] = useState(false);

    const locked = garment.intake_locked;
    const editable = orderStatus === 'intake' && !locked;
    const intakePhotos = garment.photos.filter((p) => p.stage === 'intake');

    const addMark = async (e) => {
        e.preventDefault();
        setBusy(true);
        try {
            await api(`/garments/${garment.id}/marks`, { method: 'POST', body: mark });
            toast.success('标注已记录');
            setShowMark(false);
            setMark({ kind: 'stain', label: '', description: '', x: 150, y: 150 });
            onChange?.();
        } catch (err) {
            toast.error(err.message);
        } finally {
            setBusy(false);
        }
    };

    const reportPocket = async (e) => {
        e.preventDefault();
        setBusy(true);
        try {
            await api(`/garments/${garment.id}/pocket-items`, { method: 'POST', body: pocket });
            toast.success('已登记口袋物品，订单已自动暂停');
            setShowPocket(false);
            setPocket({ description: '', note: '' });
            onChange?.();
        } catch (err) {
            toast.error(err.message);
        } finally {
            setBusy(false);
        }
    };

    const returnItem = async (itemId) => {
        try {
            await api(`/garments/${garment.id}/pocket-items/${itemId}/return`, { method: 'POST' });
            toast.success('物品已归还');
            onChange?.();
        } catch (err) {
            toast.error(err.message);
        }
    };

    return (
        <div className="border border-slate-200 rounded-xl p-4 bg-white">
            <div className="flex items-start justify-between gap-3">
                <div>
                    <div className="flex items-center gap-2 flex-wrap">
                        <span className="font-mono font-bold text-slate-800 text-base">#{garment.tag_no}</span>
                        <GarmentStatusBadge {...garment} />
                        {locked && <Badge tone="purple">🔒 预检已锁定</Badge>}
                        {garment.washer && <span className="text-xs text-slate-400">洗护责任人：{garment.washer.name}</span>}
                    </div>
                    <div className="text-sm text-slate-500 mt-1">
                        {[garment.category, garment.color, garment.brand].filter(Boolean).join(' / ') || '未填写品类信息'}
                    </div>
                </div>
                {editable && (
                    <div className="flex gap-1.5 shrink-0">
                        <Button size="sm" variant="secondary" onClick={() => setShowMark((v) => !v)}>✚ 标注污渍/破损</Button>
                        <Button size="sm" variant="secondary" onClick={() => setShowPocket((v) => !v)}>👝 口袋物品</Button>
                    </div>
                )}
            </div>

            {garment.care_labels?.length > 0 && (
                <div className="flex flex-wrap gap-1.5 mt-2">
                    {garment.care_labels.map((key) => {
                        const def = CARE_LABELS.find((c) => c.key === key);
                        return (
                            <Badge key={key} tone={def?.danger ? 'red' : 'blue'}>
                                {def?.danger ? '⚠ ' : ''}{def?.label || key}
                            </Badge>
                        );
                    })}
                </div>
            )}

            {garment.description && <p className="text-xs text-slate-500 mt-2">{garment.description}</p>}

            {/* 污渍破损标注列表 */}
            {garment.marks?.length > 0 && (
                <div className="mt-3 flex flex-wrap gap-1.5">
                    {garment.marks.map((m) => (
                        <Badge key={m.id} tone={m.kind === 'stain' ? 'amber' : 'red'}>
                            {m.kind_label}{m.label ? `·${m.label}` : ''}{m.description ? `：${m.description}` : ''}
                        </Badge>
                    ))}
                </div>
            )}

            {/* 口袋物品 */}
            {garment.pocket_items?.length > 0 && (
                <div className="mt-3 space-y-1.5">
                    {garment.pocket_items.map((i) => (
                        <div key={i.id} className="flex items-center justify-between bg-amber-50 border border-amber-200 rounded-lg px-3 py-1.5 text-sm">
                            <div>
                                <span className="font-medium">👝 {i.description}</span>
                                {i.note && <span className="text-slate-500 ml-2 text-xs">{i.note}</span>}
                            </div>
                            <div className="flex items-center gap-2">
                                <Badge tone={i.status === 'returned' ? 'green' : 'red'}>{i.status_label}</Badge>
                                {i.status === 'found' && orderStatus === 'paused' && (
                                    <Button size="sm" variant="success" onClick={() => returnItem(i.id)}>登记归还</Button>
                                )}
                            </div>
                        </div>
                    ))}
                </div>
            )}

            {/* 预检照片 */}
            <div className="mt-3">
                <div className="flex items-center justify-between mb-2">
                    <span className="text-xs font-medium text-slate-500">
                        预检照片（{intakePhotos.length} 张）{locked && '· 不可变证据'}
                    </span>
                    <PhotoUploader garmentId={garment.id} stage="intake" locked={locked} disabled={!editable} onUploaded={onChange} />
                </div>
                {intakePhotos.length === 0 ? (
                    <div className="text-xs text-amber-600 bg-amber-50 rounded-lg px-3 py-2 border border-dashed border-amber-200">
                        尚未拍摄预检照片，提交顾客确认前必须至少上传一张
                    </div>
                ) : (
                    <div className="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-2">
                        {intakePhotos.map((p) => <MarkedPhoto key={p.id} photo={p} marks={garment.marks} />)}
                    </div>
                )}
            </div>

            {showMark && editable && (
                <form onSubmit={addMark} className="mt-3 grid grid-cols-2 gap-2 bg-slate-50 rounded-lg p-3 border border-slate-200">
                    <Field label="类型">
                        <select className={inputClass} value={mark.kind} onChange={(e) => setMark({ ...mark, kind: e.target.value })}>
                            <option value="stain">已有污渍</option>
                            <option value="damage">已有破损</option>
                        </select>
                    </Field>
                    <Field label="名称（如：袖口油渍）">
                        <input className={inputClass} value={mark.label} onChange={(e) => setMark({ ...mark, label: e.target.value })} />
                    </Field>
                    <div className="col-span-2">
                        <Field label="详细描述">
                            <input className={inputClass} value={mark.description} onChange={(e) => setMark({ ...mark, description: e.target.value })} />
                        </Field>
                    </div>
                    <div className="col-span-2 flex justify-end gap-2">
                        <Button type="button" variant="ghost" size="sm" onClick={() => setShowMark(false)}>取消</Button>
                        <Button type="submit" size="sm" disabled={busy}>保存标注</Button>
                    </div>
                </form>
            )}

            {showPocket && editable && (
                <form onSubmit={reportPocket} className="mt-3 grid grid-cols-2 gap-2 bg-amber-50/60 rounded-lg p-3 border border-amber-200">
                    <Field label="物品名称">
                        <input className={inputClass} required value={pocket.description} onChange={(e) => setPocket({ ...pocket, description: e.target.value })} placeholder="如：现金、U盘、钥匙" />
                    </Field>
                    <Field label="备注">
                        <input className={inputClass} value={pocket.note} onChange={(e) => setPocket({ ...pocket, note: e.target.value })} />
                    </Field>
                    <div className="col-span-2 flex justify-end gap-2">
                        <Button type="button" variant="ghost" size="sm" onClick={() => setShowPocket(false)}>取消</Button>
                        <Button type="submit" size="sm" variant="danger" disabled={busy}>登记并暂停订单</Button>
                    </div>
                </form>
            )}
        </div>
    );
}
