const API_BASE = 'http://localhost:8000/api';

const api = {
  _token: () => localStorage.getItem('token'),

  async _fetch(method, path, body = null, isForm = false) {
    const headers = { Authorization: `Bearer ${this._token()}` };
    if (!isForm) headers['Content-Type'] = 'application/json';

    const opts = { method, headers };
    if (body) opts.body = isForm ? body : JSON.stringify(body);

    let res;
    try {
      res = await fetch(`${API_BASE}${path}`, opts);
    } catch {
      throw new Error('Impossible de contacter le serveur. Vérifiez la connexion.');
    }

    const json = await res.json();

    if (!res.ok) {
      // Validation errors (422)
      if (res.status === 422 && json.errors) {
        const msgs = Object.values(json.errors).flat().join(' ');
        throw new Error(msgs);
      }
      // Auth expired
      if (res.status === 401) {
        localStorage.clear();
        window.location.href = 'login.html';
        return;
      }
      throw new Error(json.message || `Erreur ${res.status}`);
    }

    return json.data ?? json;
  },

  get: (path)           => api._fetch('GET', path),
  post: (path, body)    => api._fetch('POST', path, body),
  put: (path, body)     => api._fetch('PUT', path, body),
  patch: (path, body)   => api._fetch('PATCH', path, body),
  delete: (path)        => api._fetch('DELETE', path),
};
