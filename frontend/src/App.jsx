import { Link, NavLink, Route, Routes, Navigate } from 'react-router-dom'
import { useAuth, ROLE_LABELS } from './auth.jsx'
import LoginPage from './pages/LoginPage.jsx'
import DashboardPage from './pages/DashboardPage.jsx'
import OrderPage from './pages/OrderPage.jsx'
import CareBoardPage from './pages/CareBoardPage.jsx'
import GarmentPage from './pages/GarmentPage.jsx'
import DisputesPage from './pages/DisputesPage.jsx'
import DisputeDetailPage from './pages/DisputeDetailPage.jsx'

function Shell() {
  const { user, logout } = useAuth()
  return (
    <div className="app-shell">
      <aside className="sidebar">
        <div className="brand">洁丰干洗门店</div>
        <NavLink to="/" end>收件台</NavLink>
        <NavLink to="/care">洗护与完工核对</NavLink>
        <NavLink to="/disputes">复检与赔偿</NavLink>
        <div className="spacer" />
        <div className="who">
          当前当班
          <b>{user?.name}</b>
          {ROLE_LABELS[user?.role] || user?.role}
        </div>
        <a href="#" onClick={(e) => { e.preventDefault(); logout() }} style={{ marginTop: 10 }}>
          退出登录
        </a>
      </aside>
      <main className="main">
        <Routes>
          <Route path="/" element={<DashboardPage />} />
          <Route path="/orders/:id" element={<OrderPage />} />
          <Route path="/care" element={<CareBoardPage />} />
          <Route path="/garments/:id" element={<GarmentPage />} />
          <Route path="/disputes" element={<DisputesPage />} />
          <Route path="/disputes/:id" element={<DisputeDetailPage />} />
          <Route path="*" element={<Navigate to="/" replace />} />
        </Routes>
      </main>
    </div>
  )
}

export default function App() {
  const { user, ready } = useAuth()
  if (!ready) return <div className="empty">加载中…</div>
  if (!user) {
    return (
      <Routes>
        <Route path="/login" element={<LoginPage />} />
        <Route path="*" element={<Navigate to="/login" replace />} />
      </Routes>
    )
  }
  return (
    <Routes>
      <Route path="/login" element={<Navigate to="/" replace />} />
      <Route path="/*" element={<Shell />} />
    </Routes>
  )
}
