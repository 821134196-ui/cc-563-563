import React, { createContext, useContext, useState, useCallback } from 'react';

const ToastContext = createContext(null);

let seq = 0;

export function ToastProvider({ children }) {
    const [toasts, setToasts] = useState([]);

    const push = useCallback((message, kind = 'info') => {
        const id = ++seq;
        setToasts((t) => [...t, { id, message, kind }]);
        setTimeout(() => {
            setToasts((t) => t.filter((x) => x.id !== id));
        }, kind === 'error' ? 6000 : 3500);
    }, []);

    const toast = {
        info: (m) => push(m, 'info'),
        success: (m) => push(m, 'success'),
        error: (m) => push(m, 'error'),
    };

    return (
        <ToastContext.Provider value={toast}>
            {children}
            <div className="fixed bottom-4 right-4 z-[100] flex flex-col gap-2 max-w-sm">
                {toasts.map((t) => (
                    <div
                        key={t.id}
                        className={`px-4 py-3 rounded-lg shadow-lg text-sm leading-5 border ${
                            t.kind === 'success'
                                ? 'bg-emerald-50 border-emerald-300 text-emerald-800'
                                : t.kind === 'error'
                                    ? 'bg-red-50 border-red-300 text-red-800'
                                    : 'bg-slate-50 border-slate-300 text-slate-800'
                        }`}
                    >
                        {t.message}
                    </div>
                ))}
            </div>
        </ToastContext.Provider>
    );
}

export function useToast() {
    return useContext(ToastContext);
}
