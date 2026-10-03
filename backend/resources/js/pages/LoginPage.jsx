import React, { useState } from 'react';
import { useAuth } from '../lib/auth.jsx';
import { useToast } from '../lib/toast.jsx';
import { Button, Field, inputClass } from '../components/ui.jsx';

const DEMO_ACCOUNTS = [
    { email: 'clerk@dryclean.local', name: '王小柜', role: '收件店员' },
    { email: 'washer@dryclean.local', name: '李洗护', role: '洗护人员' },
    { email: 'manager@dryclean.local', name: '赵经理', role: '门店经理' },
];

export default function LoginPage() {
    const { login } = useAuth();
    const toast = useToast();
    const [email, setEmail] = useState('clerk@dryclean.local');
    const [password, setPassword] = useState('password');
    const [busy, setBusy] = useState(false);

    const submit = async (e) => {
        e.preventDefault();
        setBusy(true);
        try {
            await login(email, password);
        } catch (err) {
            toast.error(err.message);
        } finally {
            setBusy(false);
        }
    };

    return (
        <div className="min-h-screen flex items-center justify-center bg-gradient-to-br from-slate-100 to-blue-100 p-4">
            <div className="bg-white rounded-2xl shadow-xl w-full max-w-md p-8">
                <div className="text-center mb-6">
                    <div className="text-4xl mb-2">🧺</div>
                    <h1 className="text-xl font-bold text-slate-800">洁衣通 · 衣物预检与赔偿系统</h1>
                    <p className="text-sm text-slate-400 mt-1">收件预检 · 完检核对 · 争议赔付</p>
                </div>
                <form onSubmit={submit} className="space-y-4">
                    <Field label="账号邮箱">
                        <input className={inputClass} value={email} onChange={(e) => setEmail(e.target.value)} />
                    </Field>
                    <Field label="密码">
                        <input type="password" className={inputClass} value={password} onChange={(e) => setPassword(e.target.value)} />
                    </Field>
                    <Button type="submit" size="lg" className="w-full" disabled={busy}>
                        {busy ? '登录中…' : '登 录'}
                    </Button>
                </form>
                <div className="mt-6 pt-5 border-t border-slate-100">
                    <p className="text-xs text-slate-400 mb-2">演示账号（密码均为 password，点击自动填充）：</p>
                    <div className="grid gap-1.5">
                        {DEMO_ACCOUNTS.map((a) => (
                            <button
                                key={a.email}
                                type="button"
                                onClick={() => { setEmail(a.email); setPassword('password'); }}
                                className="flex justify-between items-center text-sm px-3 py-2 rounded-lg bg-slate-50 hover:bg-blue-50 text-slate-700 transition"
                            >
                                <span>{a.name} · {a.role}</span>
                                <span className="text-xs text-slate-400">{a.email}</span>
                            </button>
                        ))}
                    </div>
                </div>
            </div>
        </div>
    );
}
