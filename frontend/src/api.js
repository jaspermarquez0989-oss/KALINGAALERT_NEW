const apiBase = import.meta.env.VITE_API_BASE_URL ?? 'http://127.0.0.1:8000/api/v1';
const tokenKey = 'kalinga_alert_token';

export function getToken() {
    return sessionStorage.getItem(tokenKey);
}

export function saveToken(token) {
    sessionStorage.setItem(tokenKey, token);
}

export function clearToken() {
    sessionStorage.removeItem(tokenKey);
}

async function refreshToken(token) {
    const response = await fetch(`${apiBase}/auth/refresh`, {
        method: 'POST',
        headers: {
            Accept: 'application/json',
            Authorization: `Bearer ${token}`,
        },
    });

    if (!response.ok) {
        clearToken();
        return null;
    }

    const payload = await response.json();
    saveToken(payload.access_token);

    return payload.access_token;
}

export async function api(path, { method = 'GET', body } = {}, retry = true) {
    const headers = { Accept: 'application/json' };
    const token = getToken();

    if (token) {
        headers.Authorization = `Bearer ${token}`;
    }

    const options = { method, headers };

    if (body !== undefined) {
        headers['Content-Type'] = 'application/json';
        options.body = JSON.stringify(body);
    }

    const response = await fetch(`${apiBase}${path}`, options);

    if (response.status === 401 && retry && token && !path.startsWith('/auth/')) {
        const renewedToken = await refreshToken(token);

        if (renewedToken) {
            return api(path, { method, body }, false);
        }
    }

    const contentType = response.headers.get('content-type') ?? '';
    const payload = contentType.includes('application/json') ? await response.json() : {};

    if (!response.ok) {
        const validationMessages = Object.values(payload.errors ?? {}).flat();
        throw new Error(validationMessages[0] ?? payload.message ?? 'The request could not be completed.');
    }

    return payload;
}
