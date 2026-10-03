const TOKEN_KEY = 'dryclean_token';

export function getToken() {
    return localStorage.getItem(TOKEN_KEY);
}

export function setToken(token) {
    if (token) {
        localStorage.setItem(TOKEN_KEY, token);
    } else {
        localStorage.removeItem(TOKEN_KEY);
    }
}

export class ApiError extends Error {
    constructor(message, status, code, errors) {
        super(message);
        this.status = status;
        this.code = code;
        this.errors = errors;
    }
}

export async function api(path, { method = 'GET', body, isForm = false, raw = false } = {}) {
    const headers = {};
    const token = getToken();
    if (token) {
        headers.Authorization = `Bearer ${token}`;
    }

    let payload;
    if (body !== undefined) {
        if (isForm) {
            payload = body; // FormData
        } else {
            headers['Content-Type'] = 'application/json';
            payload = JSON.stringify(body);
        }
    }

    const res = await fetch(`/api${path}`, { method, headers, body: payload });

    if (raw) {
        return res;
    }

    let data = null;
    const text = await res.text();
    if (text) {
        try {
            data = JSON.parse(text);
        } catch {
            data = { message: text };
        }
    }

    if (!res.ok) {
        throw new ApiError(
            data?.message || `请求失败（${res.status}）`,
            res.status,
            data?.code,
            data?.errors,
        );
    }

    return data;
}

/** 带鉴权的证据图片地址（<img> 无法直接带 Authorization 头，用查询令牌） */
export function photoUrl(photo) {
    return `${photo.url}?token=${encodeURIComponent(getToken() || '')}`;
}
