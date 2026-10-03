// 后端 API 封装：统一注入店员 Token，统一错误信息。
const TOKEN_KEY = 'dryclean_token'

export function getToken() {
  return localStorage.getItem(TOKEN_KEY)
}
export function setToken(token) {
  localStorage.setItem(TOKEN_KEY, token)
}
export function clearToken() {
  localStorage.removeItem(TOKEN_KEY)
}

export class ApiError extends Error {
  constructor(message, status, code, details) {
    super(message)
    this.status = status
    this.code = code
    this.details = details
  }
}

async function request(method, path, body, { isForm = false } = {}) {
  const headers = { Accept: 'application/json' }
  const token = getToken()
  if (token) headers.Authorization = `Bearer ${token}`
  if (body && !isForm) headers['Content-Type'] = 'application/json'

  const res = await fetch(`/api${path}`, {
    method,
    headers,
    body: body ? (isForm ? body : JSON.stringify(body)) : undefined,
  })

  let data = null
  const text = await res.text()
  if (text) {
    try {
      data = JSON.parse(text)
    } catch {
      data = { message: text }
    }
  }

  if (!res.ok) {
    if (res.status === 401) {
      clearToken()
      if (!location.pathname.endsWith('/login')) location.assign('/login')
    }
    throw new ApiError(
      data?.message || `请求失败（${res.status}）`,
      res.status,
      data?.error_code || 'error',
      data?.errors || data?.context || null,
    )
  }
  return data
}

export const api = {
  get: (p) => request('GET', p),
  post: (p, b) => request('POST', p, b),
  upload: (p, formData) => request('POST', p, formData, { isForm: true }),
}

// <img> 无法加 Authorization 头：照片 URL 附带查询令牌
export function photoUrl(id) {
  const t = getToken()
  return `/api/photos/${id}/content?token=${encodeURIComponent(t || '')}`
}
