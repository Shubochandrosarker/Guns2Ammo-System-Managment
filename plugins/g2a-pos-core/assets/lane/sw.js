// PWA service worker. Caches the lane shell so the till keeps functioning
// during a network blip. Firearm transfers stay blocked offline (enforced by lane.js).
const CACHE = 'g2a-lane-v2';
const SHELL = [
  './',
  'index.html',
  'lane.js',
  'lane.css',
  'modules/api.js',
  'modules/cart.js',
  'modules/scanner.js',
  'modules/escpos.js',
  'modules/zpl.js',
  'manifest.webmanifest',
];

self.addEventListener('install', e => {
  e.waitUntil(caches.open(CACHE).then(c => c.addAll(SHELL)).catch(() => {}));
  self.skipWaiting();
});
self.addEventListener('activate', e => {
  e.waitUntil(caches.keys().then(keys => Promise.all(keys.filter(k => k !== CACHE).map(k => caches.delete(k)))));
  self.clients.claim();
});
self.addEventListener('fetch', e => {
  const url = new URL(e.request.url);
  // Don't cache API calls — let them fail loudly when offline.
  if (url.pathname.includes('/wp-json/')) return;
  e.respondWith(
    caches.match(e.request).then(cached => cached || fetch(e.request).then(resp => {
      const copy = resp.clone();
      caches.open(CACHE).then(c => c.put(e.request, copy)).catch(() => {});
      return resp;
    }).catch(() => cached))
  );
});
