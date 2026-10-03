import React, { useState } from 'react';
import { useAuth } from './lib/auth.jsx';
import LoginPage from './pages/LoginPage.jsx';
import DashboardPage from './pages/DashboardPage.jsx';
import OrdersPage from './pages/OrdersPage.jsx';
import OrderDetailPage from './pages/OrderDetailPage.jsx';
import WashPage from './pages/WashPage.jsx';
import InspectionPage from './pages/InspectionPage.jsx';
import DisputesPage from './pages/DisputesPage.jsx';
import DisputeDetailPage from './pages/DisputeDetailPage.jsx';
import { Button } from './components/ui.jsx';

const NAV = [
    { key: 'dashboard', label: '收件台', roles: ['clerk', 'manager', 'washer'] },
    { key: 'orders', label: '订单', roles: ['clerk', 'manager'] },
    { key: 'wash', label: '洗护作业', roles: ['washer', 'manager', 'clerk'] },
    { key: 'inspection', label: '完检核对', roles: ['clerk', 'manager', 'washer'] },
    { key: 'disputes', label: '争议与赔付', roles: ['clerk', 'manager', 'washer'] },
];

export default function App() {
    const { user, loading, logout } = useAuth();
    const [route, setRoute] = useState({ name: 'dashboard' });

    if (loading) {
        return <div className="min-h-screen flex items-center justify-center text-slate-400">加载中…</div>;
    }
    if (!user) {
        return <LoginPage />;
    }

    const nav = (name, params = {}) => setRoute({ name, ...params });

    let page;
    switch (route.name) {
        case 'orders':
            page = <OrdersPage nav={nav} />;
            break;
        case 'order':
            page = <OrderDetailPage orderId={route.id} nav={nav} />;
            break;
        case 'wash':
            page = <WashPage nav={nav} />;
            break;
        case 'inspection':
            page = <InspectionPage nav={nav} />;
            break;
        case 'disputes':
            page = <DisputesPage nav={nav} />;
            break;
        case 'dispute':
            page = <DisputeDetailPage disputeId={route.id} nav={nav} />;
            break;
        default:
            page = <DashboardPage nav={nav} />;
    }

    return (
        <div className="min-h-screen bg-slate-100">
            <header className="bg-white border-b border-slate-200 sticky top-0 z-40">
                <div className="max-w-7xl mx-auto px-4 h-14 flex items-center gap-6">
                    <button onClick={() => nav('dashboard')} className="font-bold text-slate-800 flex items-center gap-2">
                        <span className="text-blue-600 text-lg">🧺</span> 洁衣通
                        <span className="text-xs font-normal text-slate-400">衣物预检与赔偿系统</span>
                    </button>
                    <nav className="flex items-center gap-1 flex-1">
                        {NAV.filter((n) => n.roles.includes(user.role)).map((n) => (
                            <button
                                key={n.key}
                                onClick={() => nav(n.key)}
                                className={`px-3 py-1.5 rounded-lg text-sm font-medium transition ${
                                    route.name === n.key
                                        || (n.key === 'orders' && route.name === 'order')
                                        || (n.key === 'disputes' && route.name === 'dispute')
                                        ? 'bg-blue-50 text-blue-700'
                                        : 'text-slate-600 hover:bg-slate-100'
                                }`}
                            >
                                {n.label}
                            </button>
                        ))}
                    </nav>
                    <div className="flex items-center gap-3 text-sm">
                        <span className="text-slate-600">{user.name}</span>
                        <span className="text-xs text-slate-400">{user.role_label}</span>
                        <Button variant="secondary" size="sm" onClick={logout}>退出</Button>
                    </div>
                </div>
            </header>
            <main className="max-w-7xl mx-auto px-4 py-6">{page}</main>
        </div>
    );
}
