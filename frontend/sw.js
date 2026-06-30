const CACHE = 'boutique-d-v1';
const STATIC = [
  '/', '/login.html', '/dashboard.html', '/pos.html', '/stock.html',
  '/products.html', '/users.html', '/reports.html',
  '/css/app.css', '/js/api.js', '/js/app.js', '/manifest.json',
];

self.addEventListener('install', e => {
  e.waitUntil(
    caches.open(CACHE).then(c => c.addAll(STATIC)).then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', e => {
  e.waitUntil(
    caches.keys().then(keys =>
      Promise.all(keys.filter(k => k !== CACHE).map(k => caches.delete(k)))
    ).then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', e => {
  const url = new URL(e.request.url);

  // Les appels API ne sont jamais mis en cache
  if (url.pathname.startsWith('/api/')) return;

  // Stratégie : Cache first, réseau en fallback
  e.respondWith(
    caches.match(e.request).then(cached => {
      if (cached) return cached;
      return fetch(e.request).then(res => {
        if (res.ok && e.request.method === 'GET') {
          const clone = res.clone();
          caches.open(CACHE).then(c => c.put(e.request, clone));
        }
        return res;
      }).catch(() => {
        // Offline : retourner login.html pour les navigations HTML
        if (e.request.headers.get('accept')?.includes('text/html')) {
          return caches.match('/login.html');
        }
      });
    })
  );
});
