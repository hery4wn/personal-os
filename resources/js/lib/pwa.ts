import { reactive } from 'vue';
import { router } from '@inertiajs/vue3';

type InstallEvent = Event & {
 prompt(): Promise<void>;
 userChoice: Promise<{ outcome: 'accepted' | 'dismissed' }>;
};
export const pwa = reactive({
 offline: !navigator.onLine,
 installed: window.matchMedia('(display-mode: standalone)').matches || !!(navigator as Navigator & { standalone?: boolean }).standalone,
 canInstall: false,
 installing: false,
 updateAvailable: false,
 updating: false,
 error: '',
});
let installEvent: InstallEvent | null = null;
let registration: ServiceWorkerRegistration | undefined;
let started = false;

export function initPwa() {
 if (started) return;
 started = true;
 window.addEventListener('online', () => { pwa.offline = false; });
 window.addEventListener('offline', () => { pwa.offline = true; });
 router.on('before', event => {
  if (!navigator.onLine) { pwa.offline = true; event.preventDefault(); }
 });
 window.addEventListener('beforeinstallprompt', event => {
  event.preventDefault(); installEvent = event as InstallEvent; pwa.canInstall = true;
 });
 window.addEventListener('appinstalled', () => { pwa.installed = true; pwa.canInstall = false; installEvent = null; });
 window.matchMedia('(display-mode: standalone)').addEventListener('change', event => { pwa.installed = event.matches; });
 if (!import.meta.env.PROD || !window.isSecureContext || !('serviceWorker' in navigator)) return;
 navigator.serviceWorker.addEventListener('controllerchange', () => {
  if (pwa.updating) window.location.reload();
 });
 void navigator.serviceWorker.register('/sw.js', { scope: '/', updateViaCache: 'none' }).then(value => {
  registration = value;
  pwa.updateAvailable = !!value.waiting;
  const observeInstall = () => {
   const worker = value.installing;
   worker?.addEventListener('statechange', () => {
    if (worker.state === 'installed' && navigator.serviceWorker.controller) pwa.updateAvailable = true;
   });
  };
  observeInstall();
  value.addEventListener('updatefound', observeInstall);
  document.addEventListener('visibilitychange', () => {
   if (document.visibilityState === 'visible' && navigator.onLine) void value.update().catch(() => {});
  });
 }).catch(() => { pwa.error = 'Offline setup is unavailable. Reconnect and reload to try again.'; });
}

export async function installPwa() {
 if (!installEvent || pwa.installing) return;
 const prompt = installEvent;
 pwa.installing = true; pwa.error = '';
 try {
  await prompt.prompt();
  await prompt.userChoice;
  // appinstalled confirms installation; accepting the prompt alone does not.
 } catch { pwa.error = 'The install prompt could not open. Try your browser’s install menu.'; }
 finally { installEvent = null; pwa.canInstall = false; pwa.installing = false; }
}

export function applyPwaUpdate() {
 if (!registration?.waiting) { pwa.updateAvailable = false; return; }
 pwa.updating = true;
 registration.waiting.postMessage({ type: 'SKIP_WAITING' });
}
