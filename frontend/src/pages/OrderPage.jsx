import { useCallback, useEffect, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { api, photoUrl } from '../api.js'
import { ACTION_LABELS, Badge, CARE_LABELS, GARMENT_STATUS, ORDER_STATUS, PAUSE_REASONS, fmtTime } from '../labels.jsx'
import { ErrorAlert, PhotoUploader } from '../components/widgets.jsx'

const CARE_KEYS = Object.keys(CARE_LABELS)

export default function OrderPage() {
  const { id } = useParams()
  const [order, setOrder] = useState(null)
  const [error, setError] = useState('')
  const [info, setInfo] = useState('')
  const [busy, setBusy] = useState(false)

  const load = useCallback(async () => {
    try {
      const r = await api.get(`/orders/${id}`)
      setOrder(r.data)
    } catch (e) {
      setError(e.message)
    }
  }, [id])

  useEffect(() => { load() }, [load])

  async function guard(fn) {
    setError(''); setInfo(''); setBusy(true)
    try { await fn(); await load() } catch (e) { setError(e.message) } finally { setBusy(false) }
  }

  if (!order) return <div className="empty">{error ? <ErrorAlert error={error} /> : '加载中…'}</div>

  const locked = order.status !== 'intake' && order.status !== 'paused'
  const isPaused = order.status === 'paused'

  return (
    <div>
      <div className="topbar">
        <div>
          <Link to="/" className="small">← 返回收件台</Link>
          <h1 className="mt8">订单 {order.code}</h1>
          <div className="muted small">顾客：{order.customer_name} {order.customer_phone && `· ${order.customer_phone}`}</div>
        </div>
        <div className="flex gap8">
          <Badge map={ORDER_STATUS} value={order.status} />
          <span className="tag-pill">{order.garments_count} 件衣物</span>
        </div>
      </div>

      <ErrorAlert error={error} />
      {info && <div className="alert ok">{info}</div>}

      {isPaused && <PauseBanner order={order} onReload={load} setError={setError} />}

      <GarmentList
        order={order}
        locked={locked}
        onReload={load}
        setError={setError}
        setInfo={setInfo}
      />

      {!locked && (
        <div className="panel">
          <h2>暂停订单（异常情形处理）</h2>
          <p className="muted small">出现下列情况必须先暂停订单，处理完成后才可让顾客确认进入洗护。</p>
          <PauseButtons order={order} disabled={busy} />
        </div>
      )}

      <div className="panel">
        <h2>顾客确认与进入洗护</h2>
        <p className="muted small">
          顾客与店员逐件核对衣物编号、照片、已标注污渍/破损及护理标签后确认。
          <b>确认后原始预检照片与标注立即锁定，任何人不能替换或追加。</b>
        </p>
        <div className="flex end">
          <button
            disabled={busy || isPaused || order.status !== 'intake' || order.garments.length === 0}
            onClick={() => guard(async () => {
              await api.post(`/orders/${id}/confirm`)
              setInfo('顾客已确认，预检证据已锁定，订单进入洗护流程。')
            })}
          >
            顾客确认无误，进入洗护
          </button>
        </div>
      </div>

      <EventTimeline events={order.events} />
    </div>
  )
}

function PauseBanner({ order, onReload, setError }) {
  const [note, setNote] = useState('')
  const [busy, setBusy] = useState(false)
  return (
    <div className="alert danger">
      <div className="flex between">
        <b>⛔ 订单已暂停：{PAUSE_REASONS[order.pause_reason] || order.pause_reason}</b>
      </div>
      {order.pause_note && <div className="mt8">暂停说明：{order.pause_note}</div>}
      <div className="mt8 small">暂停时间：{fmtTime(order.paused_at)}</div>
      <hr className="hr" />
      <label>店员处理记录（如何解决、是否已联系顾客/取出物品/改干洗）</label>
      <textarea value={note} onChange={(e) => setNote(e.target.value)} placeholder="必须写明处理过程，处理完成后才能恢复订单" />
      <div className="mt8">
        <button disabled={busy || !note.trim()} onClick={async () => {
          setBusy(true); setError('')
          try { await api.post(`/orders/${order.id}/resume`, { note: note.trim() }); await onReload() }
          catch (e) { setError(e.message) } finally { setBusy(false) }
        }}>{busy ? '提交中…' : '处理完成，恢复订单'}</button>
      </div>
    </div>
  )
}

function PauseButtons({ order, disabled }) {
  const reasons = [
    { key: 'customer_refused_sign', text: '顾客拒签' },
    { key: 'label_forbids_water_wash', text: '护理标签禁止水洗' },
    { key: 'pocket_items_found', text: '发现口袋物品' },
  ]
  const [note, setNote] = useState('')
  return (
    <div>
      <textarea value={note} onChange={(e) => setNote(e.target.value)} placeholder="暂停原因补充说明（选填，但建议写清楚现场情况）" />
      <div className="flex gap8 mt8" style={{ flexWrap: 'wrap' }}>
        {reasons.map((r) => (
          <button key={r.key} className="btn danger" disabled={disabled} onClick={async () => {
            try {
              await api.post(`/orders/${order.id}/pause`, { reason: r.key, note })
              location.reload()
            } catch (e) { alert(e.message) }
          }}>{r.text}，暂停订单</button>
        ))}
      </div>
    </div>
  )
}

function GarmentList({ order, locked, onReload, setError, setInfo }) {
  const [form, setForm] = useState({ tag: '', category: '', color: '', care_labels: [] })
  const [busy, setBusy] = useState(false)

  async function addGarment(e) {
    e.preventDefault()
    if (!form.tag.trim()) { setError('每件衣物必须填写唯一标签号'); return }
    setBusy(true); setError('')
    try {
      await api.post(`/orders/${order.id}/garments`, {
        ...form,
        tag: form.tag.trim(),
        pocket_items_found: false,
      })
      setForm({ tag: '', category: '', color: '', care_labels: [] })
      await onReload()
    } catch (e) { setError(e.message) } finally { setBusy(false) }
  }

  return (
    <div className="panel">
      <h2>逐件预检（{order.garments.length} 件）</h2>
      {order.garments.map((g) => (
        <GarmentCard key={g.id} garment={g} locked={locked} onReload={onReload} setError={setError} setInfo={setInfo} />
      ))}

      {!locked && (
        <div className="note-box mt16">
          <h3>添加衣物并编号</h3>
          <form onSubmit={addGarment}>
            <div className="row">
              <div style={{ flex: '0 0 140px' }}>
                <label>衣物标签号 *</label>
                <input value={form.tag} onChange={(e) => setForm({ ...form, tag: e.target.value })} placeholder="如 A-001" />
              </div>
              <div>
                <label>类别</label>
                <input value={form.category} onChange={(e) => setForm({ ...form, category: e.target.value })} placeholder="如 西装外套" />
              </div>
              <div>
                <label>颜色</label>
                <input value={form.color} onChange={(e) => setForm({ ...form, color: e.target.value })} placeholder="如 深蓝" />
              </div>
            </div>
            <label>护理标签</label>
            <div className="checks">
              {CARE_KEYS.map((k) => (
                <label key={k}>
                  <input
                    type="checkbox"
                    checked={form.care_labels.includes(k)}
                    onChange={(e) => setForm({
                      ...form,
                      care_labels: e.target.checked
                        ? [...form.care_labels, k]
                        : form.care_labels.filter((x) => x !== k),
                    })}
                  />
                  {CARE_LABELS[k]}
                </label>
              ))}
            </div>
            <div className="mt16 flex end">
              <button disabled={busy}>{busy ? '添加中…' : '添加该件衣物'}</button>
            </div>
          </form>
        </div>
      )}
    </div>
  )
}

function GarmentCard({ garment, locked, onReload, setError, setInfo }) {
  const [showMark, setShowMark] = useState(false)
  const [mark, setMark] = useState({ kind: 'stain', location: '', description: '' })
  const intakeLocked = !!garment.intake_locked_at

  return (
    <div className="note-box mt16">
      <div className="flex between" style={{ flexWrap: 'wrap' }}>
        <div className="flex gap8">
          <span className="tag-pill" style={{ fontSize: 13 }}>#{garment.tag}</span>
          <b>{garment.category || '未填类别'}</b>
          <span className="muted small">{garment.color}</span>
          <Badge map={GARMENT_STATUS} value={garment.status} />
        </div>
        <Link to={`/garments/${garment.id}`} className="btn sm secondary">打开完整档案 / 完工核对</Link>
      </div>

      {garment.care_labels?.length > 0 && (
        <div className="mt8 small">
          护理标签：{garment.care_labels.map((k) => (
            <span key={k} className={`tag-pill ${k === 'no_water_wash' ? '' : ''}`} style={{ marginRight: 6 }}>{CARE_LABELS[k] || k}</span>
          ))}
          {garment.care_labels.includes('no_water_wash') && (
            <span className="badge red" style={{ marginLeft: 6 }}>禁水洗：确认前必须先暂停处理</span>
          )}
        </div>
      )}

      {(garment.marks || []).length > 0 && (
        <div className="mt8 small">
          {garment.marks.map((m) => (
            <span key={m.id} className="tag-pill" style={{ marginRight: 6 }}>
              {m.kind === 'stain' ? '污渍' : '破损'} · {m.location}{m.description ? `：${m.description}` : ''}
            </span>
          ))}
        </div>
      )}

      <div className="mt8">
        <div className="small muted">原始预检照片（{garment.intake_photos?.length || 0} 张）</div>
        <div className="gallery">
          {(garment.intake_photos || []).map((p) => (
            <a key={p.id} className="thumb" href={photoUrl(p.id)} target="_blank" rel="noreferrer">
              <img src={photoUrl(p.id)} alt="预检照片" />
            </a>
          ))}
        </div>
        {!intakeLocked ? (
          <div className="mt8">
            <PhotoUploader
              stage="intake"
              garmentId={garment.id}
              onUploaded={onReload}
              hint="每件衣物至少一张原始照片；同一照片不能用于第二件衣物入库。"
            />
          </div>
        ) : (
          <div className="locked-note mt8">🔒 顾客已于 {fmtTime(garment.intake_locked_at)} 确认，原始证据锁定，不可替换或追加</div>
        )}
      </div>

      {!intakeLocked && (
        <div className="mt8">
          {!showMark ? (
            <button className="btn sm ghost" onClick={() => setShowMark(true)}>＋ 标注已有污渍 / 破损</button>
          ) : (
            <div>
              <div className="row">
                <div style={{ flex: '0 0 120px' }}>
                  <label>类型</label>
                  <select value={mark.kind} onChange={(e) => setMark({ ...mark, kind: e.target.value })}>
                    <option value="stain">污渍</option>
                    <option value="damage">破损</option>
                  </select>
                </div>
                <div>
                  <label>位置 *</label>
                  <input value={mark.location} onChange={(e) => setMark({ ...mark, location: e.target.value })} placeholder="如 左袖口" />
                </div>
              </div>
              <label>描述</label>
              <input value={mark.description} onChange={(e) => setMark({ ...mark, description: e.target.value })} placeholder="如 油渍约2cm" />
              <div className="flex gap8 mt8">
                <button className="btn sm" onClick={async () => {
                  if (!mark.location.trim()) { setError('请填写污渍/破损位置'); return }
                  try {
                    await api.post(`/garments/${garment.id}/marks`, mark)
                    setMark({ kind: 'stain', location: '', description: '' })
                    setShowMark(false); setInfo('标注已保存'); await onReload()
                  } catch (e) { setError(e.message) }
                }}>保存标注</button>
                <button className="btn sm ghost" onClick={() => setShowMark(false)}>取消</button>
              </div>
            </div>
          )}
        </div>
      )}
    </div>
  )
}

function EventTimeline({ events }) {
  if (!events?.length) return null
  return (
    <div className="panel">
      <h2>处理过程记录（审计链）</h2>
      <ul className="timeline">
        {events.map((e) => (
          <li key={e.id}>
            <span className="t-time">{fmtTime(e.created_at)}</span>
            <b>{e.actor?.name || '系统'}</b> · {ACTION_LABELS[e.action] || e.action}
            {e.payload && Object.keys(e.payload).length > 0 && (
              <span className="muted small"> {JSON.stringify(e.payload)}</span>
            )}
          </li>
        ))}
      </ul>
    </div>
  )
}
