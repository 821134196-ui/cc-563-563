import { createContext, useContext, useEffect, useState } from 'react'
import { api, clearToken, getToken, setToken } from './api.js'

const AuthContext = createContext(null)

export function AuthProvider({ children }) {
  const [user, setUser] = useState(null)
  const [ready, setReady] = useState(false)

  useEffect(() => {
    const token = getToken()
    if (!token) {
      setReady(true)
      return
    }
    api
      .get('/me')
      .then((r) => setUser(r.data))
      .catch(() => clearToken())
      .finally(() => setReady(true))
  }, [])

  async function login(email, password) {
    const r = await api.post('/auth/login', { email, password })
    setToken(r.token)
    setUser(r.user)
    return r.user
  }

  function logout() {
    clearToken()
    setUser(null)
  }

  return (
    <AuthContext.Provider value={{ user, ready, login, logout }}>
      {children}
    </AuthContext.Provider>
  )
}

export function useAuth() {
  return useContext(AuthContext)
}

export const ROLE_LABELS = {
  cashier: '收件店员',
  washer: '洗护人员',
  manager: '店长',
}
