import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { api } from '../api.js'
import { Badge, GARMENT_STATUS, ORDER_STATUS } from '../labels.jsx'
import { ErrorAlert } from '../components/widgets.jsx'

export default function DashboardPage() {
  const [orders, setOrders] = useState([])
  const [stats, setStats] = useState(null)
  const [error, setError] = useState('')
  const [form, setForm] = useState({ customer_name: '', customer_phone: '' })
  const [busy, setBusy] = useState(false)

  async function load() {
    try {
      const r = await api.get('/orders')
      setOrders(r.data)
      setStats(r.meta.stats)
    } catch (e) {
      setError(e.message)
    }
  }

  useEffect(() => { load() }, [])

  async function createOrder(e) {
    e.preventDefault()
    setError('')
    if (!form.customer_name.trim()) { setError('请填写顾客称呼'); return }
    setBusy(true)
    try {
      const r = await api.post('/orders', form)
      setForm({ customer_name: '', customer_phone: '' })
      await load()
      location.assign(`/orders/${r.data.id}`)
    } catch (e) {
      setError(e.message)
    } finally {
      setBusy(false)
    }
  }

  return (
    <div>
      <div className="topbar">
        <h1>收件台</h1>
        <div className="small muted">逐件编号拍照 · 顾客确认后进入洗护</div>
      </div>

      {stats && (
        <div className="stats">
          <div className="stat"><div className="num">{stats.order_count}</div><div className="label">订单总数（按单）</div></div>
          <div className="stat ok"><div className="num">{stats.garment_count}</div><div className="label">实际衣物件数（按件）</div></div>
          <div className="stat"><div className="num">{stats.active_order_count}</div><div className="label">进行中订单</div></div>
          <div className="stat warn"><div className="num">{stats.paused_order_count}</div><div className="label">暂停待处理</div></div>
          <div className="stat danger"><div className="num">{stats.disputed_garment_count}</div><div className="label">争议衣物</div></div>
        </div>
      )}

      <ErrorAlert error={error} />

      <div className="panel">
        <h2>新建收件订单</h2>
        <form onSubmit={createOrder} className="row">
          <div>
            <label>顾客称呼 *</label>
            <input value={form.customer_name} onChange={(e) => setForm({ ...form, customer_name: e.target.value })} placeholder="如：张女士" />
          </div>
          <div>
            <label>联系电话</label>
            <input value={form.customer_phone} onChange={(e) => setForm({ ...form, customer_phone: e.target.value })} placeholder="选填" />
          </div>
          <div style={{ display: 'flex', alignItems: 'flex-end' }}>
            <button disabled={busy}>{busy ? '创建中…' : '创建订单并开始预检'}</button>
          </div>
        </form>
      </div>

      <div className="panel">
        <h2>订单列表</h2>
        <table>
          <thead>
            <tr>
              <th>订单号</th><th>顾客</th><th>状态</th><th>衣物件数</th><th>创建时间</th><th></th>
            </tr>
          </thead>
          <tbody>
            {orders.map((o) => (
              <tr key={o.id} className="clickable" onClick={() => location.assign(`/orders/${o.id}`)}>
                <td><b>{o.code}</b></td>
                <td>{o.customer_name}</td>
                <td><Badge map={ORDER_STATUS} value={o.status} />
                  {o.status === 'paused' && <span className="small muted" style={{ marginLeft: 8 }}>{o.pause_note || ''}</span>}
                </td>
                <td><span className="tag-pill">{o.garments_count} 件</span></td>
                <td className="small muted">{new Date(o.created_at).toLocaleString('zh-CN')}</td>
                <td><Link to={`/orders/${o.id}`} onClick={(e) => e.stopPropagation()} className="btn sm secondary">处理</Link></td>
              </tr>
            ))}
            {orders.length === 0 && <tr><td colSpan={6} className="empty">暂无订单，先创建一单开始收件预检</td></tr>}
          </tbody>
        </table>
      </div>
    </div>
  )
}
