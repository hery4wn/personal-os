/* Generated into public/sw.js by the production build. */
const CACHE_PREFIX = 'personal-os-public-';
const CACHE = CACHE_PREFIX + '__BUILD_VERSION__';
const PUBLIC_FILES = ['/offline.html', '/icons/icon-192.png', '/icons/icon-512.png', '/icons/icon-maskable-512.png', '/icons/apple-touch-icon.png'];

self.addEventListener('install', event => {
 event.waitUntil(caches.open(CACHE).then(cache => cache.addAll(PUBLIC_FILES.map(url => new Request(url, { cache: 'reload', credentials: 'omit' })))));
});

self.addEventListener('activate', event => {
 event.waitUntil((async () => {
  for (const name of await caches.keys()) {
   if (name.startsWith(CACHE_PREFIX) && name !== CACHE) await caches.delete(name);
  }
  await self.clients.claim();
 })());
});

self.addEventListener('message', event => {
 if (event.data?.type === 'SKIP_WAITING') self.skipWaiting();
});

self.addEventListener('fetch', event => {
 const { request } = event;
 const url = new URL(request.url);
 if (request.method !== 'GET' || url.origin !== self.location.origin) return;

 // Authenticated HTML, Inertia responses, JSON, and writes are never cached.
 if (request.mode === 'navigate') {
  event.respondWith(fetch(request).catch(async () => {
   const fallback = await (await caches.open(CACHE)).match('/offline.html');
   return fallback || new Response('Personal OS is offline. Reconnect and reload.', { status: 503, headers: { 'Content-Type': 'text/plain; charset=utf-8' } });
  }));
  return;
 }

 // Only these public, non-personal files can be read from Cache Storage.
 if (!url.search && PUBLIC_FILES.includes(url.pathname)) {
  event.respondWith((async () => {
   const cached = await (await caches.open(CACHE)).match(url.pathname);
   return cached || fetch(request);
  })());
 }
});
