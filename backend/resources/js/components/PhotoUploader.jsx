import React, { useRef, useState } from 'react';
import { api } from '../lib/api.js';
import { useToast } from '../lib/toast.jsx';
import { Button } from './ui.jsx';

/**
 * 照片上传：预检 / 完检 / 争议。
 * locked=true（预检已确认）时服务端会拒绝，前端也禁用入口并展示“证据已锁定”。
 */
export default function PhotoUploader({ garmentId, stage, locked, onUploaded, disabled }) {
    const toast = useToast();
    const inputRef = useRef(null);
    const [busy, setBusy] = useState(false);

    const pick = () => !locked && !disabled && !busy && inputRef.current?.click();

    const onChange = async (e) => {
        const file = e.target.files?.[0];
        if (!file) return;
        setBusy(true);
        try {
            const fd = new FormData();
            fd.append('photo', file);
            fd.append('stage', stage);
            const d = await api(`/garments/${garmentId}/photos`, { method: 'POST', body: fd, isForm: true });
            toast.success('照片已存入证据档案');
            onUploaded?.(d.photo);
        } catch (err) {
            toast.error(err.message);
        } finally {
            setBusy(false);
            if (inputRef.current) inputRef.current.value = '';
        }
    };

    const label = { intake: '上传预检照片', completion: '上传完检照片', dispute: '上传争议照片' }[stage];

    return (
        <div>
            <input ref={inputRef} type="file" accept="image/jpeg,image/png,image/webp" className="hidden" onChange={onChange} />
            {locked ? (
                <div className="flex items-center gap-2 text-xs text-slate-400 bg-slate-50 border border-dashed border-slate-200 rounded-lg px-3 py-2">
                    🔒 预检证据已由顾客确认锁定，原始照片不得替换、追加或删除
                </div>
            ) : (
                <Button variant="secondary" size="sm" onClick={pick} disabled={disabled || busy}>
                    {busy ? '上传中…' : `📷 ${label}`}
                </Button>
            )}
        </div>
    );
}
