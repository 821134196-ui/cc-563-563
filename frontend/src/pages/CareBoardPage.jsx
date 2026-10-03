import { useCallback, useState } from 'react'
import { Link } from 'react-router-dom'
import { api } from '../api.js'
import { useAuth, ROLE_LABELS } from '../auth.jsx'
import { Badge, GARMENT_STATUS } from '../labels.jsx'
import { ErrorAlert, usePolling } from '../components/widgets.jsx'

const GROUPS = [
  { key: 'disputed', title: '争议处理中' },
  { key: 'washed', title: '洗护完成 · 待完工核对' },
  { key: 'washing', title: '洗护中' },
  { key: 'confirmed', title: '待洗护（顾客已确认）' },
]

export default function CareBoardPage() {
  const { user } = useAuth()
  const [garments, setGarments] = useState([])
  const [error, setError] = useState('')
  const [busyId, setBusyId] = useState(null)

  const load = useCallback(async () => {
    try {
      const r = await api.get('/garments-board')
      setGarments(r.data)
    } catch (e) {
      setError(e.message)
    }
  }, [])
  usePolling(load, 6000, [])

  async function act(garment, action) {
    setBusyId(garment.id)
    setError('')
    try {
      await api.post(`/garments/${garment.id}/care/${action}`)
      await load()
    } catch (e) {
      setError(e.message)
    } finally {
      setBusyId(null)
    }
  }

  return (
    <div>
      <div className="topbar">
        <h1>洗护与完工核对</h1>
        <div className="small muted">当前身份：{ROLE_LABELS[user.role] || user.role}</div>
      </div>
      <ErrorAlert error={error} />

      {GROUPS.map((g) => {
        const list = garments.filter((x) => x.status === g.key)
        return (
          <div className="panel" key={g.key}>
            <h2>{g.title} <span className="muted small">（{list.length}）</span></h2>
            {list.length === 0 && <div className="empty">暂无</div>}
            {list.map((garment) => (
              <div key={garment.id} className="note-box flex between" style={{ flexWrap: 'wrap' }}>
                <div className="flex gap8" style={{ flexWrap: 'wrap' }}>
                  <span className="tag-pill">#{garment.tag}</span>
                  <b>{garment.category || '未分类'}</b>
                  <span className="muted small">订单 {garment.order_code} · {garment.customer_name}</span>
                  <Badge map={GARMENT_STATUS} value={garment.status} />
                  {garment.washer && <span className="small muted">洗护：{garment.washer.name}</span>}
                </div>
                <div className="flex gap8">
                  {garment.status === 'confirmed' && (
                    <button className="btn sm" disabled={busyId === garment.id || user.role !== 'washer'}
                      title={user.role !== 'washer' ? '仅洗护人员可开始洗护' : ''}
                      onClick={() => act(garment, 'start')}>
                      {busyId === garment.id ? '…' : '开始洗护'}
                    </button>
                  )}
                  {garment.status === 'washing' && (
                    <button className="btn sm" disabled={busyId === garment.id} onClick={() => act(garment, 'finish')}>
                      {busyId === garment.id ? '…' : '登记洗护完成'}
                    </button>
                  )}
                  {(garment.status === 'washed' || garment.status === 'disputed') && (
                    <Link className="btn sm secondary" to={`/garments/${garment.id}`}>
                      {garment.status === 'disputed' ? '查看争议 / 复检' : '去完工核对'}
                    </Link>
                  )}
                  {garment.status === 'confirmed' && (
                    <Link className="btn sm ghost" to={`/garments/${garment.id}`}>档案</Link>
                  )}
                </div>
              </div>
            ))}
          </div>
        )
      })}
    </div>
  )
}
