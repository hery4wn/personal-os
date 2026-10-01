<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { ArrowDownLeft, ArrowUpRight, Bot, CalendarDays, Check, ChevronLeft, ChevronRight, Clapperboard, CreditCard, GripVertical, Pencil, Plus, Save, Target, Trash2, Wallet } from 'lucide-vue-next';
import { request } from '../lib/api';

const props = defineProps<{section:string;selectedId?:string|null;user:any;settings:any;today:string;data:any;summary:any;lookups:any;pagination?:any}>();
const emit = defineEmits<{edit:[entity:string,record?:any,defaults?:any]}>();
const error = ref(''); const success = ref(''); const busy = ref(false);
const tabs = [{id:'overview',label:'Overview'},{id:'transactions',label:'Transactions'},{id:'accounts',label:'Accounts'},{id:'categories',label:'Categories'},{id:'savings',label:'Savings goals'},{id:'wishlist',label:'Wishlist'}];
const financeTab = ref('overview');
const month = ref(props.summary.finance?.month || props.today.slice(0,7));
const list = ref<any[]>([]); const page = ref(1); const lastPage = ref(1); const total = ref(0); const loading = ref(false);
const transactionType = ref(''); const categoryFilter = ref(''); const accountFilter = ref('');
const stages = ['idea','planning','script','recording','editing','ready','published'];
const content = ref<any[]>([]); const dragged = ref<string|null>(null); const dropStage = ref(''); const moving = ref(false);
const contentView = ref('board'); const platformFilter = ref(''); const contentStatus = ref('');
const reviewRecords = ref<any[]>([]);
type Feed = 'content' | 'reviews';
const feedMeta = reactive<Record<Feed,{page:number;lastPage:number;total:number;loading:boolean}>>({content:{page:1,lastPage:1,total:0,loading:false},reviews:{page:1,lastPage:1,total:0,loading:false}});
const feedRequests:Record<Feed,number> = {content:0,reviews:0};
const knownPlatforms = ref<string[]>([]);
const reviewId = ref('');
const labels:Record<string,string> = {transactions:'transaction',accounts:'account',categories:'category',savings:'savings goal',wishlist:'wishlist item'};
let listRequest = 0;
const finance = computed(() => props.summary.finance || {income:'0',expenses:'0',net_savings:'0',breakdown:[]});
const reviews = computed(() => reviewRecords.value);
const review = computed(() => reviews.value.find((item:any) => item.id === reviewId.value) || reviews.value[0]);
const displayedContent = computed(() => content.value.filter(item => (!platformFilter.value || item.platform === platformFilter.value) && (!contentStatus.value || item.status === contentStatus.value)));
const platforms = computed(() => [...new Set([...knownPlatforms.value,...content.value.map(item => item.platform).filter(Boolean)])].sort() as string[]);
const activeAccounts = computed(() => (props.lookups.accounts || []).filter((item:any) => item.active));
const settingsForm = reactive<any>({}); const settingsErrors = ref<Record<string,string[]>>({});
const preferenceFields = ['timezone','currency','week_starts_on','date_format','default_finance_account_id','vision_text','current_season_text','current_intention_text','principles_text'];
for (const entity of ['content','reviews'] as Feed[]) {
  watch(() => [props.section,props.data[entity]], () => {
    if (props.section !== entity) return;
    const meta = feedMeta[entity];
    if (meta.page > 1 || (entity === 'content' && (platformFilter.value || contentStatus.value))) {
      loadFeed(entity,meta.page,false);
      return;
    }
    feedRequests[entity]++;
    const records = (props.data[entity] || []).map((item:any) => ({...item}));
    if (entity === 'content') content.value = records; else reviewRecords.value = records;
    meta.page = 1; meta.lastPage = props.pagination?.[entity]?.last_page || 1;
    meta.total = props.pagination?.[entity]?.total ?? records.length;
    meta.loading = false;
  }, {immediate:true});
}
watch([platformFilter,contentStatus], () => { if(props.section === 'content') loadFeed('content',1,false); });
watch(() => [props.user,props.settings], () => {
  settingsForm.name = props.user.name; settingsForm.email = props.user.email;
  for (const key of preferenceFields) settingsForm[key] = props.settings[key] ?? '';
}, {immediate:true});
watch(() => props.summary.finance?.month, value => { if (value) month.value = value; });
watch(financeTab, () => { error.value=''; if (financeTab.value !== 'overview') loadFinance(); });
watch([transactionType,categoryFilter,accountFilter], () => { if(financeTab.value === 'transactions') loadFinance(); });
watch(() => props.data, () => { if(props.section === 'finance' && financeTab.value !== 'overview') loadFinance(page.value); });

function title(value:string) { return value.replace(/_/g,' ').replace(/^\w/,char => char.toUpperCase()); }
function money(value:any, currency = props.settings.currency || 'IDR') {
  const options:Intl.NumberFormatOptions = {style:'currency',currency};
  if(currency === 'IDR') { options.minimumFractionDigits=0; options.maximumFractionDigits=0; }
  return new Intl.NumberFormat(currency === 'IDR' ? 'id-ID' : 'en-US',options).format(String(value ?? '0') as any);
}
function units(value:any):bigint { const [whole,fraction=''] = String(value || '0').split('.'); return BigInt(whole || '0') * 100n + BigInt((fraction+'00').slice(0,2)) * (whole.startsWith('-') ? -1n : 1n); }
function percent(current:any,target:any) { const denominator = units(target); if(denominator <= 0n)return 0; const result=units(current)*100n/denominator; return Number(result > 100n ? 100n : result < 0n ? 0n : result); }
function date(value:string) {
  if(!value)return 'No date';
  const [year,month,day] = value.slice(0,10).split('-');
  if(props.settings.date_format === 'Y-m-d')return `${year}-${month}-${day}`;
  if(props.settings.date_format === 'd/m/Y')return `${day}/${month}/${year}`;
  if(props.settings.date_format === 'm/d/Y')return `${month}/${day}/${year}`;
  return new Date(value.slice(0,10)+'T12:00:00').toLocaleDateString('en-GB',{day:'2-digit',month:'short',year:'numeric'});
}
function lookup(entity:string,id:string) { return (props.lookups[entity] || props.data[entity] || []).find((item:any) => item.id === id); }
function itemName(item:any) { return item.name || item.title || item.description || 'this item'; }
function failure(caught:any) { return caught.errors ? Object.values(caught.errors).flat().join(' ') : caught.message || 'Something went wrong. Please try again.'; }
function reload() { router.reload(); }
async function loadFeed(entity:Feed,next=1,append=true) {
  const token = ++feedRequests[entity]; const meta = feedMeta[entity]; meta.loading=true; error.value='';
  const params=new URLSearchParams({per_page:'50'});
  if(entity === 'content') {
    knownPlatforms.value=platforms.value;
    if(platformFilter.value)params.set('platform',platformFilter.value);
    if(contentStatus.value)params.set('status',contentStatus.value);
  }
  try {
    let records:any[] = append ? [...(entity === 'content' ? content.value : reviewRecords.value)] : [];
    let response:any;
    for(let current=append ? next : 1;current<=next;current++) {
      params.set('page',String(current));
      response=await request(`/data/${entity}?${params}`,'GET');
      if(token !== feedRequests[entity])return;
      records.push(...response.data);
      if(current>=response.last_page)break;
    }
    records=[...new Map(records.map(item=>[item.id,item])).values()];
    if(entity === 'content')content.value=records; else reviewRecords.value=records;
    meta.page=response.current_page; meta.lastPage=response.last_page; meta.total=response.total;
  } catch(caught:any) { if(token === feedRequests[entity])error.value=failure(caught); }
  finally { if(token === feedRequests[entity])meta.loading=false; }
}
async function remove(entity:string,item:any) {
  if(!window.confirm(`Delete ${itemName(item)}? This will remove it from your active records.`))return;
  busy.value=true; error.value='';
  try { await request(`/data/${entity}/${item.id}`,'DELETE'); reload(); }
  catch(caught:any) { error.value=failure(caught); }
  finally { busy.value=false; }
}
async function loadFinance(next=1) {
  const entity=financeTab.value; if(entity === 'overview')return;
  const token=++listRequest; loading.value=true; error.value='';
  const params=new URLSearchParams({page:String(next),per_page:'30'});
  if(entity === 'transactions') {
    for(const [key,value] of Object.entries(props.pagination?.transactions?.filters || {})) if(value)params.set(key,String(value));
    if(transactionType.value)params.set('type',transactionType.value);
    if(categoryFilter.value)params.set('category_id',categoryFilter.value);
    if(accountFilter.value)params.set('account_id',accountFilter.value);
  }
  try {
    const response=await request(`/data/${entity}?${params}`,'GET'); if(token !== listRequest)return;
    if(response.current_page>response.last_page && response.total>0) { await loadFinance(response.last_page); return; }
    list.value=response.data; page.value=response.current_page; lastPage.value=response.last_page; total.value=response.total;
  }
  catch(caught:any) { if(token === listRequest)error.value=failure(caught); }
  finally { if(token === listRequest)loading.value=false; }
}
function changeMonth() { if(!/^\d{4}-\d{2}$/.test(month.value))return; router.get('/finance',{month:month.value},{preserveState:true,preserveScroll:true}); }
function stageItems(status:string) { return displayedContent.value.filter(item => item.status === status).sort((a,b) => a.sort_order-b.sort_order); }
function dragStart(event:DragEvent,item:any) { if(moving.value||feedMeta.content.loading)return; dragged.value=item.id; event.dataTransfer?.setData('text/plain',item.id); if(event.dataTransfer)event.dataTransfer.effectAllowed='move'; }
async function moveContent(id:string,status:string,beforeId?:string) {
  if(moving.value || feedMeta.content.loading || id===beforeId)return; const snapshot=content.value.map(item=>({...item})); const item=content.value.find(row=>row.id===id); if(!item)return;
  const siblings=content.value.filter(row=>row.status===status && row.id!==id).sort((a,b)=>a.sort_order-b.sort_order);
  const found=beforeId ? siblings.findIndex(row=>row.id===beforeId) : -1;
  const at=found>=0 ? found : siblings.length; item.status=status; siblings.splice(at,0,item); siblings.forEach((row,index)=>row.sort_order=index);
  moving.value=true; error.value=''; dragged.value=null; dropStage.value='';
  try {
    // Resolve the real position across every platform and page before a card drop.
    // A column drop uses the server's clamped append position.
    let position=1000000;
    if(beforeId) {
      const params=new URLSearchParams({status,per_page:'100'}); let offset=0;
      for(let current=1;;current++) {
        params.set('page',String(current));
        const response=await request(`/data/content?${params}`,'GET');
        const rows=response.data.filter((row:any)=>row.id!==id);
        const index=rows.findIndex((row:any)=>row.id===beforeId);
        if(index>=0) { position=offset+index; break; }
        offset+=rows.length;
        if(current>=response.last_page)throw new Error('That content item changed. Refresh the page and try again.');
      }
    }
    await request(`/data/content/${id}/move`,'POST',{status,sort_order:position});
    await loadFeed('content',feedMeta.content.page,false);
  }
  catch(caught:any) { content.value=snapshot; error.value=failure(caught); }
  finally { moving.value=false; }
}
function drop(event:DragEvent,status:string,beforeId?:string) { const id=dragged.value || event.dataTransfer?.getData('text/plain'); if(id)moveContent(id,status,beforeId); }
async function saveSettings() {
  busy.value=true; error.value=''; success.value=''; settingsErrors.value={};
  try { await request('/settings','PATCH',{...settingsForm,week_starts_on:Number(settingsForm.week_starts_on),default_finance_account_id:settingsForm.default_finance_account_id || null}); success.value='Your settings are saved.'; reload(); }
  catch(caught:any) { settingsErrors.value=caught.errors || {}; error.value=failure(caught); }
  finally { busy.value=false; }
}
function newReview() {
  const start=new Date(props.today+'T12:00:00Z'); const weekday=start.getUTCDay(); const first=Number(props.settings.week_starts_on ?? 1);
  start.setUTCDate(start.getUTCDate()-(weekday-first+7)%7);
  emit('edit','reviews',undefined,{week_start:start.toISOString().slice(0,10)});
}
</script>

<template>
 <div class="life-modules">
  <p v-if="error" class="error-banner" role="alert">{{error}}</p>
  <p v-if="success" class="success-banner" role="status">{{success}}</p>
  <template v-if="section==='finance'">
   <header class="module-header"><div><h1>Finance</h1><p>A clear picture of your money, one entry at a time.</p></div><button class="btn btn-primary" @click="emit('edit','transactions',undefined,{type:'expense',transaction_date:today})"><Plus :size="16"/> Add transaction</button></header>
   <div class="module-toolbar"><div class="tabs" aria-label="Finance views"><button v-for="tab in tabs" :key="tab.id" class="tab" :class="{active:financeTab===tab.id}" :aria-pressed="financeTab===tab.id" @click="financeTab=tab.id">{{tab.label}}</button></div><label class="month-field"><span>Month</span><input type="month" v-model="month" @change="changeMonth"/></label></div>
   <template v-if="financeTab==='overview'">
    <div class="metric-grid finance-metrics"><article class="metric-card"><span><ArrowDownLeft :size="16"/> Income</span><strong class="text-green">{{money(finance.income)}}</strong></article><article class="metric-card"><span><ArrowUpRight :size="16"/> Expenses</span><strong class="text-rose">{{money(finance.expenses)}}</strong></article><article class="metric-card"><span><Wallet :size="16"/> Net savings</span><strong>{{money(finance.net_savings)}}</strong></article></div>
    <p class="form-help currency-note">Monthly totals include accounts in {{settings.currency}}. Each account keeps its own currency.</p>
    <div class="settings-grid"><section class="card"><div class="card-header"><h2>Spending by category</h2><button class="text-button" @click="financeTab='transactions'">View entries <ChevronRight :size="14"/></button></div><div v-for="category in finance.breakdown" :key="category.category_id" class="breakdown-row"><div><span>{{category.name}}</span><strong>{{money(category.amount)}}</strong></div><div class="progress-bar"><span :style="{width:percent(category.amount,finance.expenses)+'%'}"/></div></div><div v-if="!finance.breakdown?.length" class="compact-empty"><Wallet :size="30"/><p>No expenses this month.</p><span>Your spending will appear here as you add it.</span></div></section>
     <section class="card"><div class="card-header"><h2>Your accounts</h2><button class="text-button" @click="financeTab='accounts'">Manage <ChevronRight :size="14"/></button></div><div v-for="account in data.accounts" :key="account.id" class="account-row"><span class="account-icon"><CreditCard :size="19"/></span><div><strong>{{account.name}}</strong><small>{{title(account.type)}} · {{account.active?'Active':'Inactive'}}</small></div><strong>{{money(account.balance,account.currency)}}</strong></div><div v-if="!data.accounts?.length" class="compact-empty"><p>Add an account to get started.</p><button class="text-button" @click="emit('edit','accounts')">Add account</button></div></section></div>
   </template>
   <template v-else>
    <div class="module-toolbar"><h2>{{tabs.find(tab=>tab.id===financeTab)?.label}}</h2><button class="btn btn-secondary" @click="emit('edit',financeTab)"><Plus :size="16"/> Add {{labels[financeTab]}}</button></div>
    <div v-if="financeTab==='transactions'" class="filters"><select v-model="transactionType" aria-label="Transaction type"><option value="">All types</option><option value="income">Income</option><option value="expense">Expense</option></select><select v-model="categoryFilter" aria-label="Category"><option value="">All categories</option><option v-for="category in lookups.categories" :key="category.id" :value="category.id">{{category.name}}</option></select><select v-model="accountFilter" aria-label="Account"><option value="">All accounts</option><option v-for="account in lookups.accounts" :key="account.id" :value="account.id">{{account.name}} ({{account.currency}})</option></select></div>
    <p v-if="loading" class="muted loading-copy" role="status">Loading your records…</p>
    <div v-else-if="!list.length" class="empty-state"><Wallet :size="36"/><h2>No {{financeTab==='savings'?'savings goals':financeTab}} yet</h2><p>{{financeTab==='transactions'?'Add an entry or choose another month or filter.':'Start with one item and keep things simple.'}}</p><button class="btn btn-primary" @click="emit('edit',financeTab)"><Plus :size="16"/> Add {{labels[financeTab]}}</button></div>
    <div v-else-if="financeTab==='transactions'" class="table-wrap"><table class="data-table"><thead><tr><th>Date</th><th>Description / category</th><th>Account</th><th>Amount</th><th><span class="sr-only">Actions</span></th></tr></thead><tbody><tr v-for="item in list" :key="item.id"><td>{{date(item.transaction_date)}}</td><td><strong>{{item.description || lookup('categories',item.category_id)?.name}}</strong><small class="table-subtitle">{{lookup('categories',item.category_id)?.name}}</small></td><td>{{lookup('accounts',item.account_id)?.name || 'Account'}}</td><td class="money-cell" :class="item.type==='income'?'text-green':'text-rose'">{{item.type==='income'?'+':'−'}}{{money(item.amount,lookup('accounts',item.account_id)?.currency)}}</td><td><div class="record-actions"><button class="btn-icon" :aria-label="'Edit '+itemName(item)" @click="emit('edit','transactions',item)"><Pencil :size="15"/></button><button class="btn-icon" :disabled="busy" :aria-label="'Delete '+itemName(item)" @click="remove('transactions',item)"><Trash2 :size="15"/></button></div></td></tr></tbody></table></div>
    <div v-else class="record-grid"><article v-for="item in list" :key="item.id" class="record-card"><div class="card-header"><span class="badge" :class="'status-'+(item.status || (item.active?'active':'archived'))">{{title(item.status || (item.active?'active':'inactive'))}}</span><span v-if="item.type" class="muted small-text">{{title(item.type)}}</span></div><h3>{{item.name}}</h3><template v-if="financeTab==='accounts'"><strong class="record-amount">{{money(item.balance,item.currency)}}</strong><p>Opening balance {{money(item.opening_balance,item.currency)}}</p></template><template v-if="financeTab==='savings'"><strong class="record-amount">{{money(item.current_amount,item.currency)}}</strong><p>of {{money(item.target_amount,item.currency)}} saved</p><div class="progress-bar"><span :style="{width:percent(item.current_amount,item.target_amount)+'%'}"/></div><small class="muted">{{percent(item.current_amount,item.target_amount)}}% · {{item.target_date?'Target '+date(item.target_date):'No target date'}}</small></template><template v-if="financeTab==='wishlist'"><strong class="record-amount">{{item.estimated_price==null?'Price not set':money(item.estimated_price,item.currency)}}</strong><p>{{title(item.priority)}} priority<span v-if="item.desired_date"> · {{date(item.desired_date)}}</span></p><p v-if="item.notes">{{item.notes}}</p></template><div class="record-actions"><button class="btn btn-secondary" @click="emit('edit',financeTab,item)"><Pencil :size="13"/> Edit</button><button class="btn btn-danger" :disabled="busy" @click="remove(financeTab,item)"><Trash2 :size="13"/> Delete</button></div></article></div>
    <nav v-if="lastPage>1" class="pagination" aria-label="Finance pagination"><button class="btn btn-secondary" :disabled="loading||page<=1" @click="loadFinance(page-1)"><ChevronLeft :size="14"/> Previous</button><span>{{page}} of {{lastPage}} · {{total}} items</span><button class="btn btn-secondary" :disabled="loading||page>=lastPage" @click="loadFinance(page+1)">Next <ChevronRight :size="14"/></button></nav>
   </template>
  </template>
  <template v-else-if="section==='content'">
   <header class="module-header"><div><h1>Content</h1><p>Give your ideas a path from first thought to published.</p></div><button class="btn btn-primary" @click="emit('edit','content')"><Plus :size="16"/> New content</button></header>
   <div class="module-toolbar"><div class="tabs"><button class="tab" :class="{active:contentView==='board'}" @click="contentView='board'">Pipeline</button><button class="tab" :class="{active:contentView==='list'}" @click="contentView='list'">List</button></div><div class="filters"><input v-model.lazy="platformFilter" list="content-platforms" aria-label="Filter platform" placeholder="All platforms" maxlength="80"/><datalist id="content-platforms"><option v-for="platform in platforms" :key="platform" :value="platform"/></datalist><select v-model="contentStatus" aria-label="Filter content stage"><option value="">All stages</option><option v-for="stage in stages" :key="stage" :value="stage">{{title(stage)}}</option></select></div></div>
   <p v-if="moving || feedMeta.content.loading" role="status" class="muted loading-copy">{{moving ? 'Saving content position…' : 'Loading content…'}}</p>
   <div v-if="contentView==='board'" class="kanban-board content-board" :aria-busy="moving||feedMeta.content.loading"><section v-for="stage in stages.filter(stage=>!contentStatus||stage===contentStatus)" :key="stage" class="kanban-column" :class="{'drag-over':dropStage===stage}" @dragover.prevent="dropStage=stage" @dragleave.self="dropStage=''" @drop.prevent="drop($event,stage)"><header><span>{{title(stage)}}</span><span class="badge">{{stageItems(stage).length}}</span></header><article v-for="item in stageItems(stage)" :key="item.id" class="kanban-card" :draggable="!moving&&!feedMeta.content.loading" @dragstart="dragStart($event,item)" @dragend="dragged=null;dropStage=''" @dragover.prevent.stop @drop.prevent.stop="drop($event,stage,item.id)"><span class="content-platform"><Clapperboard :size="13"/>{{item.platform || 'Platform not set'}}<GripVertical :size="13"/></span><button class="content-title" @click="emit('edit','content',item)">{{item.title}}</button><p v-if="item.planned_publish_date"><CalendarDays :size="12"/> {{date(item.planned_publish_date)}}</p><select :value="item.status" :disabled="moving||feedMeta.content.loading" :aria-label="'Stage for '+item.title" @change="moveContent(item.id,($event.target as HTMLSelectElement).value)"><option v-for="status in stages" :key="status" :value="status">{{title(status)}}</option></select><div class="record-actions"><button class="btn-icon" :aria-label="'Edit '+item.title" @click="emit('edit','content',item)"><Pencil :size="14"/></button><a v-if="item.published_url" :href="item.published_url" target="_blank" rel="noopener noreferrer" class="text-button">Open published</a><button class="btn-icon" :disabled="busy" :aria-label="'Delete '+item.title" @click="remove('content',item)"><Trash2 :size="14"/></button></div></article><button class="text-button" @click="emit('edit','content',undefined,{status:stage})"><Plus :size="14"/> Add content</button></section></div>
   <div v-else-if="displayedContent.length" class="task-list"><article v-for="item in displayedContent" :key="item.id" class="task-row"><Clapperboard :size="18"/><div class="task-main"><button class="task-title" @click="emit('edit','content',item)">{{item.title}}</button><div class="task-meta"><span>{{item.platform || 'No platform'}}</span><span>{{item.planned_publish_date?date(item.planned_publish_date):'Unscheduled'}}</span></div></div><select :value="item.status" :disabled="moving||feedMeta.content.loading" :aria-label="'Stage for '+item.title" @change="moveContent(item.id,($event.target as HTMLSelectElement).value)"><option v-for="stage in stages" :key="stage" :value="stage">{{title(stage)}}</option></select><div class="record-actions"><button class="btn-icon" :aria-label="'Edit '+item.title" @click="emit('edit','content',item)"><Pencil :size="14"/></button><button class="btn-icon" :aria-label="'Delete '+item.title" :disabled="busy" @click="remove('content',item)"><Trash2 :size="14"/></button></div></article></div><div v-else class="empty-state"><Clapperboard :size="36"/><h2>Space for your next idea</h2><p>Create a content item or adjust your filters.</p></div><nav v-if="feedMeta.content.total" class="pagination" aria-label="Content pagination"><span>Showing {{content.length}} of {{feedMeta.content.total}} items</span><button v-if="feedMeta.content.page<feedMeta.content.lastPage" class="btn btn-secondary" :disabled="moving||feedMeta.content.loading" @click="loadFeed('content',feedMeta.content.page+1)">{{feedMeta.content.loading?'Loading…':'Load more content'}} <ChevronRight :size="14"/></button></nav>
  </template>
  <template v-else-if="section==='reviews'">
   <header class="module-header"><div><h1>Weekly review</h1><p>Notice your progress. Make room for a better week.</p></div><button class="btn btn-primary" @click="newReview"><Plus :size="16"/> New review</button></header>
   <div v-if="review" class="review-layout"><aside class="review-history"><h2>Past weeks</h2><button v-for="item in reviews" :key="item.id" class="review-week" :class="{active:review.id===item.id}" @click="reviewId=item.id"><CalendarDays :size="17"/><span>{{date(item.week_start)}}<small>to {{date(item.week_end)}}</small></span><ChevronRight :size="14"/></button><div class="review-history-more"><span v-if="feedMeta.reviews.total>reviews.length" class="small-text muted">{{reviews.length}} of {{feedMeta.reviews.total}} weeks</span><button v-if="feedMeta.reviews.page<feedMeta.reviews.lastPage" class="btn btn-secondary" :disabled="feedMeta.reviews.loading" @click="loadFeed('reviews',feedMeta.reviews.page+1)">{{feedMeta.reviews.loading?'Loading…':'Older reviews'}} <ChevronRight :size="14"/></button></div></aside><article class="card review-detail"><div class="module-toolbar"><div><span class="eyebrow">WEEK IN REVIEW</span><h2>{{date(review.week_start)}} – {{date(review.week_end)}}</h2></div><div class="record-actions"><button class="btn btn-secondary" @click="emit('edit','reviews',review)"><Pencil :size="14"/> Edit review</button><button class="btn-icon" :disabled="busy" aria-label="Delete review" @click="remove('reviews',review)"><Trash2 :size="15"/></button></div></div><template v-if="review.snapshot"><div class="metric-grid review-metrics"><div class="metric-card"><span>Tasks completed</span><strong>{{review.snapshot.tasks_completed}} / {{review.snapshot.tasks_total}}</strong></div><div class="metric-card"><span>Habit completion</span><strong>{{review.snapshot.habit_rate}}%</strong></div><div class="metric-card"><span>Projects updated</span><strong>{{review.snapshot.projects_updated}}</strong></div><div class="metric-card"><span>Content published</span><strong>{{review.snapshot.content_published}}</strong></div></div><div class="review-money"><span>Income <strong>{{money(review.snapshot.income,review.snapshot.currency)}}</strong></span><span>Expenses <strong>{{money(review.snapshot.expenses,review.snapshot.currency)}}</strong></span><span>Net savings <strong>{{money(review.snapshot.net_savings,review.snapshot.currency)}}</strong></span></div><p class="form-help">Saved snapshot · {{review.snapshot.projects_completed || 0}} projects completed · {{review.snapshot.ideas_captured || 0}} ideas captured</p></template><section v-for="field in ['wins','challenges','lessons','improvements','next_week_focus','notes']" :key="field" class="review-reflection"><h3>{{field==='improvements'?'What to improve':title(field)}}</h3><p>{{review[field] || 'No reflection added yet.'}}</p></section></article></div><div v-else class="empty-state"><Target :size="36"/><h2>Pause, reflect, move forward.</h2><p>Your first weekly review brings together real progress and a little reflection.</p><button class="btn btn-primary" @click="newReview">Start a review</button></div>
  </template>
  <template v-else-if="section==='settings'">
   <header class="module-header"><div><h1>Settings</h1><p>Make this space feel like yours.</p></div></header>
   <form @submit.prevent="saveSettings"><div class="settings-grid"><section class="card"><h2>Profile & preferences</h2><div class="form-grid"><label class="form-field full-width"><span>Name</span><input v-model="settingsForm.name" required maxlength="255" autocomplete="name"/></label><label class="form-field full-width"><span>Email</span><input v-model="settingsForm.email" type="email" required autocomplete="email"/></label><label class="form-field full-width"><span>Timezone</span><input v-model="settingsForm.timezone" required list="timezone-options" placeholder="Asia/Makassar"/><datalist id="timezone-options"><option value="Asia/Makassar"/><option value="Asia/Jakarta"/><option value="Asia/Jayapura"/><option value="UTC"/><option value="Europe/London"/><option value="America/New_York"/></datalist><small>Used for your calendar, habits, and daily view.</small></label><label class="form-field"><span>Currency</span><input v-model="settingsForm.currency" list="currency-options" required pattern="[A-Z]{3}" maxlength="3" @input="settingsForm.currency=settingsForm.currency.toUpperCase()"/><datalist id="currency-options"><option value="IDR"/><option value="USD"/><option value="EUR"/><option value="GBP"/><option value="SGD"/><option value="JPY"/></datalist></label><label class="form-field"><span>Week starts on</span><select v-model="settingsForm.week_starts_on"><option :value="1">Monday</option><option :value="0">Sunday</option></select></label><label class="form-field"><span>Date format</span><select v-model="settingsForm.date_format"><option value="d M Y">29 Sep 2026</option><option value="d/m/Y">29/09/2026</option><option value="m/d/Y">09/29/2026</option><option value="Y-m-d">2026-09-29</option></select></label><label class="form-field"><span>Default account</span><select v-model="settingsForm.default_finance_account_id"><option value="">Choose on capture</option><option v-for="account in activeAccounts" :key="account.id" :value="account.id">{{account.name}} ({{account.currency}})</option></select></label></div></section><section class="card"><h2>Your direction</h2><div class="form-grid"><label class="form-field full-width"><span>Long-term vision</span><textarea v-model="settingsForm.vision_text" placeholder="What kind of life are you building?" maxlength="10000"/></label><label class="form-field full-width"><span>Current season / focus</span><textarea v-model="settingsForm.current_season_text" placeholder="What matters most in this chapter?" maxlength="10000"/></label><label class="form-field full-width"><span>Daily intention</span><input v-model="settingsForm.current_intention_text" placeholder="Make space for what matters." maxlength="1000"/><small>Shown on your Home dashboard.</small></label><label class="form-field full-width"><span>Principles</span><textarea v-model="settingsForm.principles_text" placeholder="A few ideas you want to live by." maxlength="10000"/></label></div></section></div><div class="settings-save"><button class="btn btn-primary" :disabled="busy"><Save :size="16"/>{{busy?'Saving…':'Save settings'}}</button><span v-if="success" class="text-green"><Check :size="15"/> Saved</span></div></form>
  </template>
  <section v-else-if="section==='assistant'" class="assistant-placeholder"><span class="assistant-icon"><Bot :size="40" stroke-width="1.4"/></span><span class="badge">Coming later</span><h1>A little help, when you're ready.</h1><p>For now, Personal OS is your quiet place to plan, act, and reflect. A future assistant will help you make sense of it all.</p><div class="assistant-future"><span><Target :size="18"/> Clearer priorities</span><span><CalendarDays :size="18"/> Thoughtful weekly reflections</span><span><Clapperboard :size="18"/> Help shaping your ideas</span></div><p class="small-text muted">AI features are not active in this version.</p></section>
 </div>
</template>

<style scoped>
.review-history-more{display:flex;flex-direction:column;gap:10px;padding:12px}.review-reflection p{white-space:pre-wrap}.content-board[aria-busy="true"]{opacity:.7}
.module-toolbar>.tabs{margin-top:0;margin-bottom:0}.month-field{display:flex;align-items:center;gap:10px;color:#7f8998;font-size:.8rem}.month-field input{width:175px}.finance-metrics{grid-template-columns:repeat(3,minmax(0,1fr))}.metric-card>span{display:flex;align-items:center;gap:7px}.currency-note{margin:-9px 0 24px}.breakdown-row{margin:19px 0}.breakdown-row>div:first-child{display:flex;justify-content:space-between;gap:10px;font-size:.82rem}.breakdown-row .progress-bar{margin:10px 0}.account-row{display:flex;align-items:center;gap:12px;padding:15px 0;border-bottom:1px solid var(--border);font-size:.85rem}.account-row:last-child{border:0}.account-row>div{flex:1}.account-row small,.table-subtitle{display:block;font-size:.72rem;color:#929aa7;margin-top:3px}.account-icon{padding:10px;background:#f1f5f4;border-radius:8px;color:#668f83}.account-row>strong{font-size:.88rem;white-space:nowrap}.record-amount{font-size:1.3rem}.money-cell{white-space:nowrap;font-weight:600}.loading-copy{padding:20px 0}.content-platform{display:flex;align-items:center;gap:5px;color:#959caa;font-size:.66rem}.content-platform>svg:last-child{margin-left:auto}.content-title{display:block;text-align:left;line-height:1.55;font-size:.86rem;font-weight:600;padding:12px 0 5px;overflow-wrap:anywhere}.kanban-card p{display:flex;align-items:center;gap:5px}.kanban-card .record-actions .text-button{font-size:.66rem}.review-layout{display:grid;grid-template-columns:225px minmax(0,1fr);gap:22px}.review-history h2{font-size:.85rem;color:#8d96a4;margin:4px 0 15px}.review-week{display:flex;align-items:center;gap:10px;padding:15px 12px;text-align:left;border-radius:8px;width:100%;font-size:.8rem;color:#7e899a;margin-bottom:5px}.review-week>span{flex:1}.review-week small{display:block;font-size:.7rem;margin-top:4px}.review-week.active{color:#ae6a53;background:#f5eae4}.review-detail .module-toolbar h2{font-size:1.12rem;margin-top:8px}.review-detail .record-actions{margin:0}.review-metrics{gap:10px;margin-top:20px}.review-metrics .metric-card{padding:13px}.review-metrics .metric-card>span{font-size:.65rem}.review-metrics .metric-card strong{font-size:1.4rem}.review-money{display:flex;flex-wrap:wrap;gap:20px;margin:23px 0;font-size:.72rem;color:#8a94a3}.review-money strong{display:block;font-size:.93rem;margin-top:5px;color:#3b495b}.settings-save{display:flex;align-items:center;gap:15px;margin:25px 0}.settings-save>span{display:flex;align-items:center;gap:5px;font-size:.85rem}.assistant-placeholder{max-width:680px;margin:65px auto;text-align:center;display:flex;flex-direction:column;align-items:center;gap:23px}.assistant-icon{display:flex;align-items:center;justify-content:center;height:92px;width:92px;border-radius:27px;background:#f2eae2;color:#ab795e}.assistant-placeholder h1{font-size:2rem;line-height:1.3}.assistant-placeholder>p{color:#939ba7;line-height:1.85;max-width:500px}.assistant-future{display:flex;flex-direction:column;gap:19px;align-items:flex-start;padding:20px;font-size:.84rem;color:#717f8e}.assistant-future>span{display:flex;gap:12px;align-items:center}.life-modules>.module-header{margin-top:0}@media(max-width:1000px){.review-layout{grid-template-columns:1fr}.review-history{display:flex;overflow-x:auto;gap:8px}.review-history h2{display:none}.review-week{min-width:185px;width:auto}.finance-metrics .metric-card strong{font-size:1.2rem}}@media(max-width:600px){.finance-metrics{grid-template-columns:1fr}.finance-metrics .metric-card{display:flex;justify-content:space-between;align-items:center}.finance-metrics .metric-card strong{margin:0}.review-metrics{grid-template-columns:1fr 1fr}.assistant-placeholder{margin:30px auto}.assistant-placeholder h1{font-size:1.6rem}.module-toolbar .filters{margin:0}.review-detail{padding:17px}.account-row{gap:8px}.account-row>strong{font-size:.75rem}.month-field{width:100%;justify-content:flex-end}}
</style>
