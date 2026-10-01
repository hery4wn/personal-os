import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { runInNewContext } from 'node:vm';

const root = new URL('../../', import.meta.url);
const source = await readFile(new URL('public/sw.js', root), 'utf8');
function worker() {
 const listeners = {}; const stores = new Map(); const fetched = [];
 let offline = false; let claimed = false; let skipped = false;
 const origin = 'https://personal-os.test';
 class LocalRequest { constructor(url, options) { this.url = new URL(url, origin).href; Object.assign(this, options); } }
 const caches = {
  async open(name) {
   if (!stores.has(name)) stores.set(name, new Map());
   const values = stores.get(name);
   return {
    async addAll(requests) { for (const request of requests) values.set(new URL(request.url).pathname, new Response('Public offline screen')); },
    async match(url) { return values.get(typeof url === 'string' ? url : new URL(url.url).pathname)?.clone(); },
   };
  },
  async keys() { return [...stores.keys()]; },
  async delete(name) { return stores.delete(name); },
 };
 const self = { location: { origin }, clients: { async claim() { claimed = true; } }, skipWaiting() { skipped = true; }, addEventListener(name, callback) { listeners[name] = callback; } };
 runInNewContext(source, { self, caches, URL, Request: LocalRequest, Response, fetch: async request => { fetched.push(request); if (offline) throw new Error('offline'); return new Response('Private account page'); } });
 const lifecycle = async name => { let work; listeners[name]({ waitUntil(value) { work = value; } }); await work; };
 const fetchEvent = async (path, { method = 'GET', mode = 'cors' } = {}) => {
  let response;
  listeners.fetch({ request: { url: new URL(path, origin).href, method, mode }, respondWith(value) { response = value; } });
  return response ? await response : undefined;
 };
 return { stores, fetched, lifecycle, fetchEvent, listeners, setOffline: value => { offline = value; }, claimed: () => claimed, skipped: () => skipped };
}

test('manifest has correctly sized install icons, standalone scope and start URL', async () => {
 const manifest = JSON.parse(await readFile(new URL('public/manifest.webmanifest', root), 'utf8'));
 assert.equal(manifest.display, 'standalone'); assert.equal(manifest.start_url, '/home'); assert.equal(manifest.scope, '/');
 assert.ok(manifest.icons.some(icon => icon.purpose === 'maskable'));
 for (const icon of manifest.icons) {
  const png = await readFile(new URL('public' + icon.src, root));
  assert.equal(png.subarray(1, 4).toString(), 'PNG');
  assert.equal(`${png.readUInt32BE(16)}x${png.readUInt32BE(20)}`, icon.sizes);
 }
 assert.ok(!source.includes('__BUILD_VERSION__'));
});

test('only public fallback resources are cached; private navigation uses the network', async () => {
 const w = worker(); await w.lifecycle('install');
 const entries = [...w.stores.values()][0];
 assert.ok(entries.has('/offline.html'));
 assert.ok([...entries.keys()].every(path => path === '/offline.html' || path.startsWith('/icons/')));
 const response = await w.fetchEvent('/home', { mode: 'navigate' });
 assert.equal(await response.text(), 'Private account page');
 assert.ok(!entries.has('/home'));
 w.setOffline(true);
 const offline = await w.fetchEvent('/projects/private-project', { mode: 'navigate' });
 assert.equal(await offline.text(), 'Public offline screen');
 assert.ok(!entries.has('/projects/private-project'));
});

test('API, Inertia, cross-origin and write requests are never intercepted or replayed', async () => {
 const w = worker(); await w.lifecycle('install'); w.setOffline(true);
 for (const path of ['/data/tasks', '/home', '/search?q=secret', '/settings', '/daily-checkins', '/icons/icon-192.png?private=value', 'https://other.test/icons/icon-192.png']) {
  assert.equal(await w.fetchEvent(path), undefined);
 }
 for (const method of ['POST', 'PATCH', 'DELETE', 'PUT']) assert.equal(await w.fetchEvent('/data/tasks', { method }), undefined);
 assert.equal(w.fetched.length, 0);
});

test('updates remove only old Personal OS caches and wait for explicit activation', async () => {
 const w = worker(); await w.lifecycle('install');
 w.stores.set('personal-os-public-old', new Map()); w.stores.set('another-app-cache', new Map());
 assert.equal(w.skipped(), false);
 await w.lifecycle('activate');
 assert.equal(w.stores.has('personal-os-public-old'), false);
 assert.equal(w.stores.has('another-app-cache'), true); assert.equal(w.claimed(), true);
 w.listeners.message({ data: { type: 'OTHER' } }); assert.equal(w.skipped(), false);
 w.listeners.message({ data: { type: 'SKIP_WAITING' } }); assert.equal(w.skipped(), true);
});
