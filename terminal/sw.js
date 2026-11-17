/* ESL Operacje – terminal: praca bez połączenia (service worker).
   Strona terminala i zasoby są przechowywane na tablecie, więc terminal uruchamia się także bez sieci.
   Zapytania do API nie są buforowane – dane czekają w kolejce terminala i wysyłają się po odzyskaniu połączenia. */
const V = 'esl-term-v8';
const CORE = ['./', './index.html', '../assets/outfit.css', '../assets/xlsx.full.min.js',
  '../assets/fonts/outfit-latin-400-normal.woff2', '../assets/fonts/outfit-latin-500-normal.woff2', '../assets/fonts/outfit-latin-600-normal.woff2',
  '../assets/fonts/outfit-latin-700-normal.woff2', '../assets/fonts/outfit-latin-800-normal.woff2',
  '../assets/fonts/outfit-latin-ext-400-normal.woff2', '../assets/fonts/outfit-latin-ext-500-normal.woff2', '../assets/fonts/outfit-latin-ext-600-normal.woff2',
  '../assets/fonts/outfit-latin-ext-700-normal.woff2', '../assets/fonts/outfit-latin-ext-800-normal.woff2'];
self.addEventListener('install', e => {
  e.waitUntil(caches.open(V).then(c => Promise.all(CORE.map(u => c.add(new Request(u, { cache: 'reload' })).catch(() => null)))).then(() => self.skipWaiting()));
});
self.addEventListener('activate', e => {
  e.waitUntil(caches.keys().then(ks => Promise.all(ks.filter(k => k.startsWith('esl-term-') && k !== V).map(k => caches.delete(k)))).then(() => self.clients.claim()));
});
self.addEventListener('fetch', e => {
  const r = e.request; if (r.method !== 'GET') return;
  const u = new URL(r.url); if (u.origin !== self.location.origin || u.pathname.includes('/api/')) return;
  if (r.mode === 'navigate') {   // strona: najpierw sieć (aktualizacje), bez sieci – kopia z tabletu
    e.respondWith(fetch(r).then(res => { if (res.ok) { const cp = res.clone(); caches.open(V).then(c => c.put('./index.html', cp)); } return res; })
      .catch(() => caches.match('./index.html').then(m => m || caches.match('./'))));
    return;
  }
  e.respondWith(caches.match(r).then(hit => {   // zasoby: kopia z tabletu, w tle odświeżenie
    const net = fetch(r).then(res => { if (res.ok) { const cp = res.clone(); caches.open(V).then(c => c.put(r, cp)); } return res; }).catch(() => hit);
    return hit || net;
  }));
});
