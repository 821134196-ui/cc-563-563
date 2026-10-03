import { useCallback, useEffect, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { api, photoUrl } from '../api.js'
import { Badge, CARE_LABELS, DISPUTE_STATUS, GARMENT_STATUS, fmtTime } from '../labels.jsx'
import { ErrorAlert, PhotoUploader } from '../components/widgets.jsx'

export default function GarmentPage() {
  const { id } = useParams()
  const [g, setG] = useState(null)
  const [error, setError] = useState('')
  const [info, setInfo] = useState('')

  const load = useCallback(async () => {
    try {
      const r = await api.get(`/garments/${id}`)
      setG(r.data)
    } catch (e) {
      setError(e.message)
    }
  }, [id])
  useEffect(() => { load() }, [load])

  if (!g) return <div className="empty">{error ? <ErrorAlert error={error} /> : '加载中…'}</div>

  const intake = g.photos.filter((p) => p.stage === 'intake')
  const postwash = g.photos.filter((p) => p.stage === 'postwash')
  const recheck = g.photos.filter((p) => p.stage === 'recheck')
  const intakeMarks = g.marks.filter((m) => m.stage === 'intake')
  const newMarks = g.marks.filter((m) => m.stage !== 'intake')
  const activeDispute = g.disputes.find((d) => !['closed', 'rejected'].includes(d.status)) || g.disputes[0]

  return (
    <div>
      <div className="topbar">
        <div>
          <Link to="/care" className="small">← 返回洗护看板</Link>
          <h1 className="mt8">衣物档案 <span className="tag-pill" style={{ fontSize: 15 }}>#{g.tag}</span></h1>
          <div className="muted small">
            {g.category} · {g.color} · 订单 {g.order.code}（{g.order.customer_name}）
            {g.washer && <> · 洗护：{g.washer.name}</>}
          </div>
        </div>
        <div className="flex gap8">
          <Badge map={GARMENT_STATUS} value={g.status} />
          <Link className="btn sm ghost" to={`/orders/${g.order_id}`}>订单</Link>
        </div>
      </div>

      <ErrorAlert error={error} />
      {info && <div className="alert ok">{info}</div>}

      {g.care_labels?.length > 0 && (
        <div className="panel" style={{ padding: '12px 18px' }}>
          <b className="small">护理标签：</b>
          {g.care_labels.map((k) => (
            <span key={k} className={`tag-pill ${k === 'no_water_wash' ? 'badge red' : ''}`} style={{ marginRight: 6 }}>
              {CARE_LABELS[k] || k}
            </span>
          ))}
        </div>
      )}

      {/* 并排对比 */}
      <div className="panel">
        <h2>完工核对 · 原始照片 与 当前照片 并排对比</h2>
        <div className="compare">
          <div>
            <div className="frame locked">
              <span className="tagline">🔒 原始预检（不可更改）</span>
              {intake[0]
                ? <img src={photoUrl(intake[0].id)} alt="原始预检" />
                : <span className="muted">无原始照片</span>}
            </div>
            <div className="gallery">
              {intake.map((p) => (
                <a key={p.id} className="thumb" href={photoUrl(p.id)} target="_blank" rel="noreferrer">
                  <img src={photoUrl(p.id)} alt="预检" />
                </a>
              ))}
            </div>
            {intakeMarks.length > 0 && (
              <div className="note-box">
                <b className="small">预检已标注（收件时原状）</b>
                {intakeMarks.map((m) => (
                  <div key={m.id} className="small">
                    {m.kind === 'stain' ? '🟡 污渍' : '🔴 破损'} · {m.location}{m.description ? `：${m.description}` : ''}
                  </div>
                ))}
              </div>
            )}
          </div>

          <div>
            <div className="frame">
              <span className="tagline">当前（洗护后）</span>
              {(postwash[0] || recheck[0])
                ? <img src={photoUrl((postwash[0] || recheck[0]).id)} alt="洗护后" />
                : <span className="muted">尚未上传当前照片</span>}
            </div>
            <div className="gallery">
              {[...postwash, ...recheck].map((p) => (
                <a key={p.id} className="thumb" href={photoUrl(p.id)} target="_blank" rel="noreferrer">
                  <img src={photoUrl(p.id)} alt={p.stage === 'recheck' ? '复检' : '完工'} />
                </a>
              ))}
            </div>
            {newMarks.length > 0 && (
              <div className="note-box" style={{ borderColor: '#f0c2bd' }}>
                <b className="small" style={{ color: 'var(--danger)' }}>洗护后新发现</b>
                {newMarks.map((m) => (
                  <div key={m.id} className="small">
                    {m.kind === 'stain' ? '🟡 污渍' : '🔴 破损'} · {m.location}{m.description ? `：${m.description}` : ''}
                    <span className="muted">（{m.stage === 'recheck' ? '复检' : '完工'}登记）</span>
                  </div>
                ))}
              </div>
            )}
          </div>
        </div>

        {(['washed', 'disputed'].includes(g.status)) && (
          <div className="mt16">
            <h3>上传当前照片</h3>
            <PhotoUploader
              stage={activeDispute?.status === 'rechecking' ? 'recheck' : 'postwash'}
              garmentId={g.id}
              onUploaded={load}
            />
          </div>
        )}
      </div>

      {/* 核对结论 */}
      {(['washed', 'disputed'].includes(g.status)) && (
        <InspectPanel garment={g} dispute={activeDispute} onReload={load} setError={setError} setInfo={setInfo} />
      )}

      {/* 检查历史 */}
      <div className="panel">
        <h2>检查记录</h2>
        {g.inspections.length === 0 && <div className="empty">尚无检查记录</div>}
        <ul className="timeline">
          {g.inspections.map((ins) => (
            <li key={ins.id}>
              <span className="t-time">{fmtTime(ins.created_at)}</span>
              {ins.kind === 'recheck' ? '复检' : '完工检查'} ·{' '}
              {ins.result === 'consistent'
                ? <span className="badge green">核对一致</span>
                : <span className="badge red">发现差异</span>}
              {ins.inspector && <span className="muted small"> · {ins.inspector.name}</span>}
              {ins.notes && <div className="small muted">{ins.notes}</div>}
            </li>
          ))}
        </ul>
      </div>

      {/* 争议入口 */}
      {activeDispute && (
        <div className="panel">
          <h2 className="flex between">
            破损争议与赔偿
            <Badge map={DISPUTE_STATUS} value={activeDispute.status} />
          </h2>
          <p className="small">{activeDispute.description}</p>
          <Link className="btn" to={`/disputes/${activeDispute.id}`}>进入争议处理（复检 / 赔偿协商 / 审批与回执）</Link>
        </div>
      )}
    </div>
  )
}

function InspectPanel({ garment, dispute, onReload, setError, setInfo }) {
  const [result, setResult] = useState('consistent')
  const [notes, setNotes] = useState('')
  const [marks, setMarks] = useState([])
  const [busy, setBusy] = useState(false)

  const isRecheck = dispute?.status === 'rechecking'

  function addMark() {
    setMarks([...marks, { kind: 'damage', location: '', description: '' }])
  }
  function updMark(i, patch) {
    setMarks(marks.map((m, idx) => (idx === i ? { ...m, ...patch } : m)))
  }
  function delMark(i) {
    setMarks(marks.filter((_, idx) => idx !== i))
  }

  async function submit() {
    setError(''); setBusy(true)
    try {
      if (result === 'discrepancy' && marks.length === 0) {
        setError('发现差异时，必须记录至少一处新污渍/破损的位置与描述。')
        setBusy(false)
        return
      }
      await api.post(`/garments/${garment.id}/inspections`, {
        result,
        notes,
        kind: isRecheck ? 'recheck' : 'postwash',
        new_marks: result === 'discrepancy' ? marks : [],
      })
      setInfo(result === 'discrepancy'
        ? '已记录差异并开启争议，可前往发起复检与赔偿协商。'
        : '核对一致，该衣物已完结。')
      setResult('consistent'); setNotes(''); setMarks([])
      await onReload()
    } catch (e) {
      setError(e.message)
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="panel">
      <h2>{isRecheck ? '提交复检结论' : '完工核对结论'}</h2>
      <div className="checks">
        <label>
          <input type="radio" checked={result === 'consistent'} onChange={() => setResult('consistent')} />
          与预检记录一致（无新增破损/污渍）
        </label>
        <label>
          <input type="radio" checked={result === 'discrepancy'} onChange={() => setResult('discrepancy')} />
          发现差异（新增破损/污渍），发起复检及赔偿协商
        </label>
      </div>

      {result === 'discrepancy' && (
        <div className="note-box mt16" style={{ borderColor: '#f0c2bd' }}>
          <h3>新增痕迹（对比原始预检照片后填写）</h3>
          {marks.map((m, i) => (
            <div key={i} className="row mt8">
              <div style={{ flex: '0 0 110px' }}>
                <label>类型</label>
                <select value={m.kind} onChange={(e) => updMark(i, { kind: e.target.value })}>
                  <option value="damage">破损</option>
                  <option value="stain">污渍</option>
                </select>
              </div>
              <div style={{ flex: '0 0 150px' }}>
                <label>位置 *</label>
                <input value={m.location} onChange={(e) => updMark(i, { location: e.target.value })} placeholder="如 右肩" />
              </div>
              <div>
                <label>描述</label>
                <input value={m.description} onChange={(e) => updMark(i, { description: e.target.value })} placeholder="如 约3cm裂口" />
              </div>
              <div style={{ flex: '0 0 60px', display: 'flex', alignItems: 'flex-end' }}>
                <button type="button" className="btn sm ghost" onClick={() => delMark(i)}>删除</button>
              </div>
            </div>
          ))}
          <button type="button" className="btn sm secondary mt8" onClick={addMark}>＋ 添加一处差异</button>
        </div>
      )}

      <label className="mt16">核对说明</label>
      <textarea value={notes} onChange={(e) => setNotes(e.target.value)} placeholder="记录现场情况、与顾客沟通内容等" />

      <div className="flex end mt16">
        <button disabled={busy} onClick={submit}>{busy ? '提交中…' : '提交核对结论'}</button>
      </div>
    </div>
  )
}
