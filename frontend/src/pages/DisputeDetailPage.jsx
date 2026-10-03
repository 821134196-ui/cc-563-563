import { useCallback, useEffect, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { api } from '../api.js'
import { useAuth } from '../auth.jsx'
import { Badge, COMP_STATUS, DISPUTE_STATUS, fmtTime, yuan } from '../labels.jsx'
import { ErrorAlert, PhotoUploader } from '../components/widgets.jsx'
import { RESP_LABELS } from './DisputesPage.jsx'

export default function DisputeDetailPage() {
  const { id } = useParams()
  const { user } = useAuth()
  const [d, setD] = useState(null)
  const [error, setError] = useState('')
  const [info, setInfo] = useState('')
  const [busy, setBusy] = useState(false)

  const load = useCallback(async () => {
    try {
      setD((await api.get(`/disputes/${id}`)).data)
    } catch (e) {
      setError(e.message)
    }
  }, [id])
  useEffect(() => { load() }, [load])

  async function guard(fn, okMsg) {
    setError(''); setInfo(''); setBusy(true)
    try { await fn(); await load(); if (okMsg) setInfo(okMsg) }
    catch (e) { setError(e.message) } finally { setBusy(false) }
  }

  if (!d) return <div className="empty">{error ? <ErrorAlert error={error} /> : '加载中…'}</div>

  const comp = d.compensation
  const receipt = comp?.receipt
  const isManager = user.role === 'manager'
  const isWasher = user.role === 'washer'
  const settled = ['paid', 'closed'].includes(d.status)

  return (
    <div>
      <div className="topbar">
        <div>
          <Link to="/disputes" className="small">← 返回争议列表</Link>
          <h1 className="mt8">争议 #{d.id} · 衣物 <span className="tag-pill">#{d.garment?.tag}</span></h1>
          <div className="muted small">
            洗护经手：{d.garment?.washer?.name || '未登记'} · 责任初判：{RESP_LABELS[d.suspected_responsibility]}
          </div>
        </div>
        <Badge map={DISPUTE_STATUS} value={d.status} />
      </div>

      <ErrorAlert error={error} />
      {info && <div className="alert ok">{info}</div>}

      <div className="panel">
        <h2>争议情况</h2>
        <p>{d.description || '（无描述）'}</p>
        <div className="small muted">开启人：{d.opened_by?.name || '—'} · {fmtTime(d.created_at)}</div>
        <div className="mt8">
          <Link className="btn sm secondary" to={`/garments/${d.garment?.id}`}>
            查看衣物完整档案（原始/当前照片并排、污渍破损标注）
          </Link>
        </div>
      </div>

      {/* 复检 */}
      <div className="panel">
        <h2>复检</h2>
        {d.status === 'rechecking' ? (
          <div>
            <div className="alert info">复检进行中：请在衣物档案页上传复检照片并提交复检结论（一致 / 仍有差异）。</div>
            <Link className="btn" to={`/garments/${d.garment?.id}`}>前往衣物档案提交复检</Link>
          </div>
        ) : (
          <div className="flex between" style={{ flexWrap: 'wrap' }}>
            <span className="muted small">对完工检查结论有异议时，可发起复检补充复核照片与结论。</span>
            {['open', 'negotiating'].includes(d.status) && (
              <button className="btn secondary" disabled={busy} onClick={() => guard(async () => {
                await api.post(`/disputes/${id}/recheck`)
              }, '已发起复检')}>发起复检</button>
            )}
          </div>
        )}
      </div>

      {/* 协商记录 */}
      <NotesPanel dispute={d} settled={settled} onReload={load} setError={setError} />

      {/* 赔付申请 */}
      <div className="panel">
        <h2>赔偿协商与审批</h2>
        {!comp ? (
          <RequestForm busy={busy} onSubmit={(amountCents, reason) => guard(async () => {
            await api.post(`/disputes/${id}/compensation`, { amount_cents: amountCents, reason })
          }, '赔付申请已提交，等待店长审批。')} />
        ) : (
          <CompensationPanel
            comp={comp}
            receipt={receipt}
            isManager={isManager}
            isWasher={isWasher}
            settled={settled}
            busy={busy}
            guard={guard}
          />
        )}
        {isWasher && (
          <div className="alert warn mt16">
            职责分离规则：洗护人员无权审批赔付（尤其不能审批自己经手衣物的赔付），请使用店长账号审批。
          </div>
        )}
      </div>
    </div>
  )
}

function RequestForm({ busy, onSubmit }) {
  const [yuanVal, setYuanVal] = useState('')
  const [reason, setReason] = useState('')
  const [err, setErr] = useState('')

  function submit() {
    setErr('')
    const amount = Math.round(Number(yuanVal) * 100)
    if (!Number.isFinite(amount) || amount <= 0) { setErr('请填写正确的赔付金额'); return }
    if (!reason.trim()) { setErr('请填写赔付理由/协商依据'); return }
    onSubmit(amount, reason.trim())
  }

  return (
    <div>
      {err && <div className="error-text">{err}</div>}
      <div className="row">
        <div style={{ flex: '0 0 180px' }}>
          <label>协商赔付金额（元）</label>
          <input type="number" min="0.01" step="0.01" value={yuanVal} onChange={(e) => setYuanVal(e.target.value)} placeholder="如 50.00" />
        </div>
        <div>
          <label>赔付理由 / 协商依据</label>
          <input value={reason} onChange={(e) => setReason(e.target.value)} placeholder="如 按衣物原价协商，顾客同意" />
        </div>
      </div>
      <div className="flex end mt16">
        <button disabled={busy} onClick={submit}>发起赔付申请</button>
      </div>
    </div>
  )
}

function CompensationPanel({ comp, receipt, isManager, isWasher, settled, busy, guard }) {
  const [note, setNote] = useState('')
  const [payMsg, setPayMsg] = useState('')

  return (
    <div>
      <table>
        <tbody>
          <tr><th style={{ width: 140 }}>赔付金额</th><td><b style={{ fontSize: 16 }}>{yuan(comp.amount_cents)}</b></td></tr>
          <tr><th>申请状态</th><td><Badge map={COMP_STATUS} value={comp.status} /></td></tr>
          <tr><th>申请人</th><td>{comp.requested_by?.name || '—'} · {fmtTime(comp.created_at)}</td></tr>
          <tr><th>申请理由</th><td className="small">{comp.reason}</td></tr>
          {comp.decided_by && (
            <>
              <tr><th>审批人</th><td>{comp.decided_by?.name} · {fmtTime(comp.decided_at)}</td></tr>
              <tr><th>审批意见</th><td className="small">{comp.decision_note || '（无）'}</td></tr>
            </>
          )}
        </tbody>
      </table>

      {comp.status === 'pending' && (
        <div className="mt16">
          <label>店长审批意见</label>
          <textarea value={note} onChange={(e) => setNote(e.target.value)} placeholder="批准或驳回的依据（选填，建议留痕）" />
          <div className="flex end gap8 mt8">
            {isManager ? (
              <>
                <button className="btn danger" disabled={busy} onClick={() => guard(async () => {
                  await api.post(`/disputes/${comp.dispute_id}/compensation/decide`, { approved: false, note })
                }, '已驳回赔付申请。')}>驳回</button>
                <button disabled={busy} onClick={() => guard(async () => {
                  await api.post(`/disputes/${comp.dispute_id}/compensation/decide`, { approved: true, note })
                }, '已批准赔付，可执行付款。')}>批准赔付</button>
              </>
            ) : (
              <span className="muted small">
                {isWasher ? '仅店长可审批（洗护人员职责分离限制）。' : '仅店长可审批。'}
              </span>
            )}
          </div>
        </div>
      )}

      {comp.status === 'approved' && (
        <div className="mt16 note-box">
          <h3>执行赔付（模拟支付接口）</h3>
          <p className="small muted">
            系统按赔付单生成固定幂等键。重复点击“确认付款”或回执重发，都只会返回同一张回执，绝不会二次付款。
          </p>
          {isManager ? (
            <button disabled={busy} onClick={async () => {
              setPayMsg('')
              await guard(async () => {
                const r = await api.post(`/disputes/${comp.dispute_id}/compensation/pay`, {})
                setPayMsg(`付款成功，回执号：${r.data.receipt_no}`)
              })
            }}>{busy ? '处理中…' : '确认付款（可重复点击验证幂等）'}</button>
          ) : <span className="muted small">仅店长可执行付款。</span>}
          {payMsg && <div className="alert ok mt8">{payMsg}</div>}
        </div>
      )}

      {receipt && (
        <div className="note-box mt16" style={{ borderColor: '#a8dcc0', background: '#f3fbf6' }}>
          <h3>🧾 赔付回执（模拟）</h3>
          <table>
            <tbody>
              <tr><th style={{ width: 140 }}>回执号</th><td><b>{receipt.receipt_no}</b></td></tr>
              <tr><th>金额</th><td>{yuan(receipt.amount_cents)}（{receipt.gateway?.toUpperCase()} 网关）</td></tr>
              <tr><th>幂等键</th><td className="small">{receipt.idempotency_key}</td></tr>
              <tr><th>付款时间</th><td>{fmtTime(receipt.paid_at)}</td></tr>
              <tr><th>状态</th><td><span className="badge green">{receipt.status}</span></td></tr>
            </tbody>
          </table>
          <div className="small muted mt8">同一赔付单再次请求付款将返回这张相同回执，不会产生新付款记录。</div>
        </div>
      )}
    </div>
  )
}

function NotesPanel({ dispute, settled, onReload, setError }) {
  const [body, setBody] = useState('')
  const [busy, setBusy] = useState(false)

  async function send() {
    if (!body.trim()) return
    setBusy(true); setError('')
    try {
      await api.post(`/disputes/${dispute.id}/notes`, { body: body.trim() })
      setBody('')
      await onReload()
    } catch (e) { setError(e.message) } finally { setBusy(false) }
  }

  return (
    <div className="panel">
      <h2>赔偿协商记录</h2>
      {dispute.notes.length === 0 && <div className="empty">暂无协商记录</div>}
      {dispute.notes.map((n) => (
        <div key={n.id} className="note-box">
          <div className="author small">{n.author?.name || '—'} <span className="muted">· {fmtTime(n.created_at)}</span></div>
          <div className="small mt8" style={{ whiteSpace: 'pre-wrap' }}>{n.body}</div>
        </div>
      ))}
      {!settled && (
        <div className="mt16">
          <textarea value={body} onChange={(e) => setBody(e.target.value)} placeholder="记录与顾客/洗护人员的协商内容、责任认定依据…" />
          <div className="flex end mt8">
            <button className="btn secondary" disabled={busy || !body.trim()} onClick={send}>添加协商记录</button>
          </div>
        </div>
      )}
    </div>
  )
}
