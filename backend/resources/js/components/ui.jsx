import React from 'react';

export function cx(...parts) {
    return parts.filter(Boolean).join(' ');
}

const badgeTone = {
    gray: 'bg-slate-100 text-slate-700 border-slate-200',
    blue: 'bg-blue-50 text-blue-700 border-blue-200',
    amber: 'bg-amber-50 text-amber-800 border-amber-200',
    red: 'bg-red-50 text-red-700 border-red-200',
    green: 'bg-emerald-50 text-emerald-700 border-emerald-200',
    purple: 'bg-purple-50 text-purple-700 border-purple-200',
};

export function Badge({ tone = 'gray', children, className }) {
    return (
        <span className={cx('inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium border', badgeTone[tone], className)}>
            {children}
        </span>
    );
}

export function Card({ title, extra, children, className }) {
    return (
        <section className={cx('bg-white rounded-xl border border-slate-200 shadow-sm', className)}>
            {(title || extra) && (
                <header className="flex items-center justify-between px-5 py-3 border-b border-slate-100">
                    {title && <h3 className="font-semibold text-slate-800">{title}</h3>}
                    {extra}
                </header>
            )}
            <div className="p-5">{children}</div>
        </section>
    );
}

export function Button({ variant = 'primary', size = 'md', className, ...props }) {
    const variants = {
        primary: 'bg-blue-600 hover:bg-blue-700 text-white disabled:bg-blue-300',
        secondary: 'bg-white hover:bg-slate-50 text-slate-700 border border-slate-300',
        danger: 'bg-red-600 hover:bg-red-700 text-white disabled:bg-red-300',
        success: 'bg-emerald-600 hover:bg-emerald-700 text-white',
        ghost: 'bg-transparent hover:bg-slate-100 text-slate-600',
    };
    const sizes = {
        sm: 'px-2.5 py-1 text-xs',
        md: 'px-3.5 py-1.5 text-sm',
        lg: 'px-5 py-2.5 text-base',
    };
    return (
        <button
            className={cx('inline-flex items-center justify-center gap-1.5 rounded-lg font-medium transition disabled:cursor-not-allowed', variants[variant], sizes[size], className)}
            {...props}
        />
    );
}

export function Field({ label, hint, error, children }) {
    return (
        <label className="block">
            {label && <span className="block text-sm font-medium text-slate-700 mb-1">{label}</span>}
            {children}
            {hint && !error && <span className="block text-xs text-slate-400 mt-1">{hint}</span>}
            {error && <span className="block text-xs text-red-600 mt-1">{error}</span>}
        </label>
    );
}

export const inputClass = 'w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500/30 focus:border-blue-500';

export function Modal({ open, title, onClose, children, footer, wide }) {
    if (!open) return null;
    return (
        <div className="fixed inset-0 z-50 flex items-start justify-center bg-slate-900/40 p-4 overflow-y-auto">
            <div className={cx('bg-white rounded-xl shadow-xl w-full my-10', wide ? 'max-w-3xl' : 'max-w-md')}>
                <header className="flex items-center justify-between px-5 py-3.5 border-b border-slate-100">
                    <h3 className="font-semibold text-slate-800">{title}</h3>
                    <button onClick={onClose} className="text-slate-400 hover:text-slate-600 text-xl leading-none">×</button>
                </header>
                <div className="p-5">{children}</div>
                {footer && <footer className="px-5 py-3 border-t border-slate-100 flex justify-end gap-2">{footer}</footer>}
            </div>
        </div>
    );
}

export function formatMoney(cents) {
    if (cents === null || cents === undefined) return '—';
    return `¥${(cents / 100).toFixed(2)}`;
}

export function formatTime(iso) {
    if (!iso) return '—';
    const d = new Date(iso);
    return d.toLocaleString('zh-CN', { hour12: false });
}

export function EmptyState({ children }) {
    return <div className="text-center text-slate-400 text-sm py-10">{children}</div>;
}
