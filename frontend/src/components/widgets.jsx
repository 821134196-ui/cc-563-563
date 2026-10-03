import { useEffect, useState } from 'react'
import { api } from '../api.js'

// 错误条
export function ErrorAlert({ error }) {
  if (!error) return null
  return <div className="alert danger">{error}</div>
}

// 照片上传小块
export function PhotoUploader({ stage, garmentId, onUploaded, disabled, hint }) {
  const [file, setFile] = useState(null)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')

  async function upload() {
    if (!file) return
    setBusy(true)
    setError('')
    try {
      const fd = new FormData()
      fd.append('stage', stage)
      fd.append('photo', file)
      const r = await api.upload(`/garments/${garmentId}/photos`, fd)
      onUploaded?.(r.data)
      setFile(null)
    } catch (e) {
      setError(e.message)
    } finally {
      setBusy(false)
    }
  }

  return (
    <div>
      <div className="flex gap8" style={{ flexWrap: 'wrap' }}>
        <input
          type="file"
          accept="image/png,image/jpeg,image/webp"
          disabled={disabled || busy}
          onChange={(e) => setFile(e.target.files?.[0] || null)}
          style={{ flex: 2, fontSize: 12.5 }}
        />
        <button className="btn sm secondary" disabled={disabled || busy || !file} onClick={upload}>
          {busy ? '上传中…' : '上传照片'}
        </button>
      </div>
      {hint && <div className="small muted mt8">{hint}</div>}
      {error && <div className="error-text">{error}</div>}
    </div>
  )
}

// 简单的自动刷新：每 ms 重新执行 fn
export function usePolling(fn, ms = 5000, deps = []) {
  useEffect(() => {
    fn()
    const t = setInterval(fn, ms)
    return () => clearInterval(t)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, deps)
}
