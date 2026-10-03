import React, { useState } from 'react';
import { photoUrl } from '../lib/api.js';
import { formatTime } from './ui.jsx';

/**
 * 带预检标注（污渍/破损坐标）的照片查看器。
 */
export default function MarkedPhoto({ photo, marks = [] }) {
    const [zoomed, setZoomed] = useState(false);

    return (
        <>
            <button type="button" onClick={() => setZoomed(true)} className="block text-left group">
                <div className="relative rounded-lg overflow-hidden border border-slate-200 bg-slate-50">
                    <img
                        src={photoUrl(photo)}
                        alt={photo.filename}
                        className="w-full h-40 object-cover group-hover:opacity-95"
                        loading="lazy"
                    />
                    {marks.map((m) => (
                        <span
                            key={m.id}
                            title={`${m.kind_label}${m.label ? `：${m.label}` : ''}`}
                            className={`absolute -translate-x-1/2 -translate-y-1/2 w-5 h-5 rounded-full border-2 border-white shadow text-[10px] leading-[18px] text-center text-white font-bold ${
                                m.kind === 'stain' ? 'bg-amber-500/90' : 'bg-red-600/90'
                            }`}
                            style={{ left: `${m.x / 10}%`, top: `${m.y / 10}%` }}
                        >
                            {m.kind === 'stain' ? '污' : '破'}
                        </span>
                    ))}
                </div>
                <div className="mt-1 text-[11px] text-slate-400 flex items-center gap-1">
                    <span className={photo.checksum_valid === false ? 'text-red-500' : ''}>
                        {photo.checksum_valid === false ? '⚠️ 校验异常' : '🔒 校验完好'}
                    </span>
                    <span>· {formatTime(photo.uploaded_at)}</span>
                </div>
            </button>
            {zoomed && (
                <div className="fixed inset-0 z-[60] bg-slate-900/70 flex items-center justify-center p-6" onClick={() => setZoomed(false)}>
                    <div className="relative max-h-full" onClick={(e) => e.stopPropagation()}>
                        <img src={photoUrl(photo)} alt={photo.filename} className="max-h-[85vh] max-w-[90vw] rounded-lg" />
                        {marks.map((m) => (
                            <span
                                key={m.id}
                                className={`absolute -translate-x-1/2 -translate-y-1/2 w-7 h-7 rounded-full border-2 border-white shadow text-sm leading-6 text-center text-white font-bold ${
                                    m.kind === 'stain' ? 'bg-amber-500/90' : 'bg-red-600/90'
                                }`}
                                style={{ left: `${m.x / 10}%`, top: `${m.y / 10}%` }}
                            >
                                {m.kind === 'stain' ? '污' : '破'}
                            </span>
                        ))}
                        <div className="mt-2 text-xs text-slate-300 font-mono break-all">sha256: {photo.checksum}</div>
                    </div>
                </div>
            )}
        </>
    );
}
