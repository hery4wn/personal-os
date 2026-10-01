<script setup lang="ts">
import { nextTick, ref } from 'vue';
import { Download, WifiOff, RefreshCw, X, Smartphone, Check } from 'lucide-vue-next';
import { pwa, installPwa, applyPwaUpdate } from '../lib/pwa';
const help = ref<HTMLDialogElement>();
const secure = window.isSecureContext;
const ios = /iPhone|iPad|iPod/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
async function install() {
 if (pwa.canInstall) await installPwa();
 else { await nextTick(); help.value?.showModal(); }
}
</script>
<template>
 <button v-if="!pwa.installed" class="pwa-install nav-item" :disabled="pwa.installing" @click="install"><Download :size="17"/><span>{{pwa.installing?'Opening install…':'Install app'}}</span></button>
 <span v-else class="pwa-installed"><Check :size="14"/> App installed</span>
 <Teleport to="body">
  <div v-if="pwa.offline" class="pwa-connectivity" role="status"><WifiOff :size="15"/><span>You’re offline. Viewing fresh records and saving changes need a connection.</span></div>
  <div v-if="pwa.updateAvailable&&!pwa.offline" class="pwa-update" role="status"><RefreshCw :size="18"/><span><strong>An update is ready.</strong><small>Save your changes before reloading.</small></span><button class="btn btn-primary" :disabled="pwa.updating" @click="applyPwaUpdate">{{pwa.updating?'Updating…':'Reload'}}</button></div>
  <dialog ref="help" class="pwa-help form-dialog" aria-labelledby="pwa-help-title" @click="($event.target===help)&&help?.close()"><header class="dialog-header"><h2 id="pwa-help-title"><Smartphone :size="21"/> Install Personal OS</h2><button class="btn-icon" aria-label="Close installation help" @click="help?.close()"><X :size="20"/></button></header><div class="dialog-body"><p>Add Personal OS to your home screen or desktop and open it in its own window.</p><p v-if="!secure" class="error-banner">Installation needs an HTTPS address. For development on this computer, use localhost or 127.0.0.1.</p><p v-else-if="ios">Open your browser’s Share menu, then choose <strong>Add to Home Screen</strong>. If shown, keep <strong>Open as Web App</strong> enabled.</p><p v-else>Use your browser’s <strong>Install app</strong> option in the address bar or menu. On Android it may be called <strong>Add to Home screen</strong>. If no option appears, open this site in Chrome, Edge, or another browser that supports installation.</p><p v-if="pwa.error" class="error-banner" role="alert">{{pwa.error}}</p><p class="form-help">Your workspace needs a connection to its server. Offline mode shows a reconnect screen; it does not store personal records or queue edits.</p></div></dialog>
 </Teleport>
</template>
