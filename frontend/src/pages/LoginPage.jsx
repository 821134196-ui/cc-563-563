import { useState } from 'react'
import { useAuth } from '../auth.jsx'

const ACCOUNTS = [
  { email: 'cashier@store.local', name: '王收件（收件店员）' },
  { email: 'washer@store.local', name: '李洗护（洗护人员）' },
  { email: 'manager@store.local', name: '赵店长（赔付审批）' },
]

export default function LoginPage() {
  const { login } = useAuth()
  const [email, setEmail] = useState('cashier@store.local')
  const [password, setPassword] = useState('password')
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)

  async function submit(e) {
    e.preventDefault()
    setError('')
    setBusy(true)
    try {
      await login(email, password)
      location.assign('/')
    } catch (err) {
      setError(err.message)
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="login-wrap">
      <div className="panel login-card">
        <h1>衣物预检与赔偿系统</h1>
        <p className="muted small">请选择当班店员身份登录（演示环境）</p>
        <form onSubmit={submit}>
          <label>店员账号</label>
          <select value={email} onChange={(e) => setEmail(e.target.value)}>
            {ACCOUNTS.map((a) => <option key={a.email} value={a.email}>{a.name}</option>)}
          </select>
          <label>密码</label>
          <input type="password" value={password} onChange={(e) => setPassword(e.target.value)} />
          {error && <div className="error-text">{error}</div>}
          <button className="mt16" style={{ width: '100%' }} disabled={busy}>
            {busy ? '登录中…' : '登录'}
          </button>
        </form>
        <div className="login-accounts">
          演示账号密码统一为 <code>password</code>
        </div>
      </div>
    </div>
  )
}
