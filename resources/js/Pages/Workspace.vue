<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { MountainSnow, House, ListTodo, CalendarDays, FolderKanban, Target, Lightbulb, Clapperboard, Leaf, Wallet, BookOpen, Sparkles, Search, Plus, Settings, LogOut, X, Menu, Command, ChevronRight, FileText } from 'lucide-vue-next';
import HomeDashboard from '../Components/HomeDashboard.vue';
import ModuleView from '../Components/ModuleView.vue';
import RecordForm from '../Components/RecordForm.vue';
import QuickCapture from '../Components/QuickCapture.vue';
import ThemeToggle from '../Components/ThemeToggle.vue';
import PwaControls from '../Components/PwaControls.vue';
import { request } from '../lib/api';

const props = defineProps<{ section:string; selectedId?:string; user:any; settings:any; today:string; data:any; summary:any; lookups:any; pagination?:any }>();
const page = usePage();
const nav = [
 {group:'Daily',items:[{key:'home',label:'Home',icon:House},{key:'today',label:'Today',icon:ListTodo},{key:'calendar',label:'Calendar',icon:CalendarDays}]},
 {group:'Build',items:[{key:'projects',label:'Projects',icon:FolderKanban},{key:'goals',label:'Goals',icon:Target},{key:'ideas',label:'Ideas',icon:Lightbulb},{key:'notes',label:'Notes',icon:FileText},{key:'content',label:'Content',icon:Clapperboard}]},
 {group:'Life',items:[{key:'habits',label:'Habits',icon:Leaf},{key:'finance',label:'Finance',icon:Wallet}]},
 {group:'Reflect',items:[{key:'reviews',label:'Weekly review',icon:BookOpen},{key:'assistant',label:'AI Assistant',icon:Sparkles}]},
];
const navItems = nav.flatMap(g=>g.items);
const current = computed(()=> navItems.find(n=>n.key===props.section)?.label || 'Settings');
const editor = ref<{entity:string;record?:any;defaults?:any}|null>(null);
const capture = ref('');
const calendarRevision = ref(0);
const mobileMenu = ref(false);
const searchOpen = ref(false);
const searchQuery = ref('');
const searchResults = ref<any[]>([]);
const searching = ref(false);
const searchError = ref('');
const searchInput = ref<HTMLInputElement>();
const searchDialog = ref<HTMLDialogElement>();
const toast = ref('');
let toastTimeout:ReturnType<typeof setTimeout>;
let searchTimeout:ReturnType<typeof setTimeout>;
let searchSequence = 0;
const initials = computed(()=>props.user.name.split(' ').map((s:string)=>s[0]).slice(0,2).join(''));
function edit(entity:string, record?:any, defaults?:any) { editor.value={entity,record,defaults}; }
function saved() { if(editor.value?.entity==='events')calendarRevision.value++; editor.value=null; capture.value=''; toast.value='Saved to your workspace'; clearTimeout(toastTimeout); toastTimeout=setTimeout(()=>toast.value='',3500); router.reload(); }
async function openSearch() { searchOpen.value=true; await nextTick(); searchDialog.value?.showModal(); searchInput.value?.focus(); }
function closeSearch() { searchOpen.value=false; searchDialog.value?.close(); }
function keyboard(e:KeyboardEvent) {
 if ((e.ctrlKey||e.metaKey)&&e.key.toLowerCase()==='k') {e.preventDefault(); openSearch();}
 if ((e.ctrlKey||e.metaKey)&&e.shiftKey&&e.key.toLowerCase()==='n') {e.preventDefault(); capture.value='tasks';}
}
watch(searchQuery, ()=> {
 clearTimeout(searchTimeout); const seq=++searchSequence;
 if (!searchQuery.value.trim()) {searchResults.value=[];searching.value=false;return;}
 searching.value=true;
 searchTimeout=setTimeout(async()=>{
  try {const result:any=await request(`/search?q=${encodeURIComponent(searchQuery.value)}`,'GET');if(seq===searchSequence){const raw=result.results ?? result;searchResults.value=Array.isArray(raw)?raw:Object.entries(raw).flatMap(([type,items])=>(items as any[]).map(item=>({...item,type}))); searchError.value='';}}
  catch(e:any){if(seq===searchSequence)searchError.value=e.message;}
  finally {if(seq===searchSequence)searching.value=false;}
 },250);
});
function resultUrl(result:any) {const type=result.entity||result.type; return result.href || result.url || ({tasks:'/today',projects:`/projects/${result.id}`,goals:`/goals/${result.id}`,ideas:'/ideas',notes:'/notes',content:'/content'} as Record<string,string>)[type] || '/home';}
watch(()=>props.section,()=>{mobileMenu.value=false;closeSearch();});
watch(()=>page.url,async(url)=>{
 const item=new URL(url,window.location.origin).searchParams.get('item');
 if(!item)return;
 const entity=props.section==='projects'||props.section==='today'?'tasks':props.section;
 if(!['tasks','ideas','notes','content'].includes(entity))return;
 try {const result=await request(`/data/${entity}/${encodeURIComponent(item)}`,'GET');edit(entity,result.data);}
 catch(e:any){toast.value=e.message;clearTimeout(toastTimeout);toastTimeout=setTimeout(()=>toast.value='',5000);}
},{immediate:true});
onMounted(()=>document.addEventListener('keydown',keyboard));
onBeforeUnmount(()=>{document.removeEventListener('keydown',keyboard);clearTimeout(searchTimeout);clearTimeout(toastTimeout);});
</script>

<template>
 <Head :title="current" />
 <div class="app-layout">
  <a class="skip-link" href="#main-content">Skip to content</a>
  <div v-if="mobileMenu" class="nav-scrim" @click="mobileMenu=false"></div>
  <aside class="sidebar" :class="{ 'mobile-open':mobileMenu }" aria-label="Main navigation">
   <Link href="/home" class="brand"><MountainSnow :size="29" stroke-width="1.7" /><span>Personal OS<span class="brand-dot">.</span></span></Link>
   <button class="btn-icon close-nav" @click="mobileMenu=false" aria-label="Close navigation"><X :size="20" /></button>
   <div class="workspace-label">YOUR PERSONAL WORKSPACE</div>
   <nav>
    <div v-for="group in nav" :key="group.group" class="nav-group">
     <p class="nav-group-label">{{group.group}}</p>
     <Link v-for="item in group.items" :key="item.key" :href="`/${item.key}`" class="nav-item" :class="{active:section===item.key}" :aria-current="section===item.key?'page':undefined">
      <component :is="item.icon" :size="19" stroke-width="1.7" /><span>{{item.label}}</span>
      <span v-if="item.key==='today' && summary?.today?.total_count" class="nav-count">{{Math.max(0,summary.today.total_count-summary.today.completed_count)}}</span>
      <span v-if="item.key==='assistant'" class="soon-dot" aria-label="Coming soon"></span>
     </Link>
    </div>
   </nav>
   <div class="sidebar-bottom">
    <PwaControls/>
    <Link href="/settings" class="nav-item" :class="{active:section==='settings'}"><Settings :size="18"/><span>Settings</span></Link>
    <div class="profile-row"><Link href="/settings" class="profile-link"><span class="avatar">{{initials}}</span><span><strong>{{user.name}}</strong><small>One day at a time</small></span></Link><button class="btn-icon" aria-label="Log out" title="Log out" @click="router.post('/logout')"><LogOut :size="17"/></button></div>
   </div>
  </aside>
  <div class="app-main">
   <header class="topbar">
    <button class="mobile-brand btn-icon" aria-label="Open navigation" @click="mobileMenu=true"><MountainSnow :size="28"/></button>
    <div class="breadcrumb">My workspace <ChevronRight :size="13"/><span>{{current}}</span></div>
    <button class="search-trigger" @click="openSearch"><Search :size="17"/><span>Search Personal OS...</span><kbd><Command :size="11"/> K</kbd></button>
    <div class="topbar-actions"><ThemeToggle/><button v-if="section!=='home'" class="btn btn-primary capture-top" @click="capture='tasks'"><Plus :size="16"/> Quick Capture</button><Link class="avatar top-avatar" href="/settings" aria-label="Profile settings">{{initials}}</Link></div>
   </header>
   <main id="main-content" class="page-content">
    <HomeDashboard v-if="section==='home'" v-bind="props" :calendar-revision="calendarRevision" @edit="edit" @capture="capture=$event" />
    <template v-else>
     <ModuleView :key="`${section}-${selectedId||''}`" v-bind="props" @edit="edit" />
    </template>
   </main>
   <footer class="workspace-footer"><span class="footer-note"><Leaf :size="13"/> Small steps. Room to grow.</span><span>Personal OS <span class="version">V1</span></span></footer>
  </div>
  <nav class="mobile-bottom" aria-label="Mobile navigation"><Link v-for="item in navItems.filter(n=>['home','today','projects','calendar'].includes(n.key))" :key="item.key" :href="`/${item.key}`" :class="{active:section===item.key}"><component :is="item.icon" :size="20"/><span>{{item.label}}</span></Link><button @click="mobileMenu=true"><Menu :size="20"/><span>More</span></button></nav>
  <button v-if="section!=='home'" class="mobile-capture" aria-label="Quick Capture" @click="capture='tasks'"><Plus :size="25"/></button>
  <RecordForm v-if="editor" :key="`${editor.entity}-${editor.record?.id||'new'}`" v-bind="editor" :lookups="lookups" :settings="settings" :today="today" @close="editor=null" @saved="saved"/>
  <QuickCapture v-if="capture" :initial-type="capture" :lookups="lookups" :settings="settings" :today="today" @close="capture=''" @saved="saved"/>
  <dialog ref="searchDialog" class="search-dialog" @cancel="closeSearch" @click="($event.target===searchDialog)&&closeSearch()">
   <div class="search-input-wrap"><Search :size="21"/><input ref="searchInput" v-model="searchQuery" placeholder="Search tasks, projects, ideas..." aria-label="Search your workspace"/><button class="btn-icon" @click="closeSearch" aria-label="Close search"><X :size="19"/></button></div>
   <div class="search-results"><p v-if="searchError" class="error-banner">{{searchError}}</p><p v-else-if="searching" class="search-hint">Searching your workspace...</p><p v-else-if="!searchQuery" class="search-hint">Find a task, project, goal, idea, note, or content item.</p><p v-else-if="!searchResults.length" class="search-hint">No results for “{{searchQuery}}”. Try another word.</p><Link v-for="result in searchResults" :key="`${result.entity||result.type}-${result.id}`" :href="resultUrl(result)" @click="closeSearch" class="search-result"><span class="search-result-icon"><Search :size="17"/></span><span><strong>{{result.title||result.name}}</strong><small>{{(result.entity||result.type||'').replaceAll('_',' ')}}</small></span><ChevronRight :size="16"/></Link></div>
   <div class="search-dialog-footer">Search stays within your personal workspace <kbd>esc to close</kbd></div>
  </dialog>
  <div v-if="toast" class="toast" role="status">{{toast}}</div>
 </div>
</template>
