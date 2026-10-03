import { useCallback, useState } from 'react'
import { Link } from 'react-router-dom'
import { api } from '../api.js'
import { Badge, COMP_STATUS, DISPUTE_STATUS, fmtTime, yuan } from '../labels.jsx'
import { usePolling } from '../components/widgets.jsx'

export default function DisputesPage() {
  const [disputes, setDisputes] = useState([])
  const [error, setError] = useState('')

  const load = useCallback(async () => {
    try {
      const r = await api.get('/disputes')
      setDisputes(r.data)
    } catch (e) {
      setError(e.message)
    }
  }, [])
  usePolling(load, 6000, [])

  return (
    <div>
      <div className="topbar">
        <h1>复检与赔偿处理</h1>
        <div className="small muted">赔付须由店长审批；洗护人员不能审批自己责任范围内的赔付</div>
      </div>
      {error && <div className="alert danger">{error}</div>}

      <div className="panel">
        <table>
          <thead>
            <tr>
              <th>#</th><th>衣物</th><th>描述</th><th>责任初判</th><th>争议状态</th><th>赔付申请</th><th>开启时间</th><th></th>
            </tr>
          </thead>
          <tbody>
            {disputes.map((d) => (
              <tr key={d.id}>
                <td>{d.id}</td>
                <td><span className="tag-pill">#{d.garment?.tag}</span>
                  {d.garment?.washer && <div className="small muted">洗护：{d.garment.washer.name}</div>}
                </td>
                <td style={{ maxWidth: 260 }} className="small">{d.description}</td>
                <td className="small">{RESP_LABELS[d.suspected_responsibility] || d.suspected_responsibility}</td>
                <td><Badge map={DISPUTE_STATUS} value={d.status} /></td>
                <td>
                  {d.compensation
                    ? <span><Badge map={COMP_STATUS} value={d.compensation.status} /> <b>{yuan(d.compensation.amount_cents)}</b></span>
                    : <span className="muted small">未发起</span>}
                </td>
                <td className="small muted">{fmtTime(d.created_at)}</td>
                <td><Link className="btn sm secondary" to={`/disputes/${d.id}`}>处理</Link></td>
              </tr>
            ))}
            {disputes.length === 0 && <tr><td colSpan={8} className="empty">暂无争议。完工核对发现差异后会在此出现。</td></tr>}
          </tbody>
        </table>
      </div>
    </div>
  )
}

export const RESP_LABELS = {
  washer: '洗护方责任',
  customer: '顾客原因',
  store: '门店责任',
  unknown: '待判定',
}
