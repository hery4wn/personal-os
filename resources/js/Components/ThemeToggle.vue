<script setup lang="ts">
import { ref } from 'vue';
import { Moon, Sun } from 'lucide-vue-next';
const dark = ref(document.documentElement.dataset.theme === 'dark');
function toggle() {
 dark.value = !dark.value;
 document.documentElement.dataset.theme = dark.value ? 'dark' : 'light';
 try { localStorage.setItem('personal-os-theme', dark.value ? 'dark' : 'light'); } catch {}
}
</script>
<template><button class="btn-icon theme-toggle" :aria-label="dark?'Switch to light mode':'Switch to dark mode'" :title="dark?'Light mode':'Dark mode'" :aria-pressed="dark" @click="toggle"><Sun v-if="dark" :size="20"/><Moon v-else :size="20"/></button></template>
