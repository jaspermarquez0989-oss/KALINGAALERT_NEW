    import axios from 'axios';

const apiBase = (
    import.meta.env.VITE_API_BASE_URL
    ?? (import.meta.env.DEV ? 'http://127.0.0.1:8000/api/v1' : '/api/v1')
).replace(/\/$/, '');
const tokenKey = 'kalinga_alert_token';

const client = axios.create({
    baseURL: apiBase,
    headers: {
        Accept: 'application/json',
    },
});

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
    try {
        const response = await axios.post(`${apiBase}/auth/refresh`, null, {
            headers: {
                Accept: 'application/json',
                Authorization: `Bearer ${token}`,
            },
        });
        saveToken(response.data.access_token);
        return response.data.access_token;
    } catch {
        clearToken();
        return null;
    }
}

export async function api(path, { method = 'GET', body } = {}, retry = true) {
    const token = getToken();
    const headers = token ? { Authorization: `Bearer ${token}` } : {};

    try {
        const response = await client.request({
            url: path,
            method,
            data: body,
            headers,
        });
        return response.data;
    } catch (error) {
        if (error.response?.status === 401 && retry && token && !path.startsWith('/auth/')) {
            const renewedToken = await refreshToken(token);

            if (renewedToken) {
                return api(path, { method, body }, false);
            }
        }

        const payload = error.response?.data ?? {};
        const validationMessages = Object.values(payload.errors ?? {}).flat();
        throw new Error(validationMessages[0] ?? payload.message ?? error.message ?? 'The request could not be completed.');
    }
}
