<script setup lang="ts">
import { computed, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import { CalendarDays, ChevronLeft, ChevronRight, Check, Plus } from 'lucide-vue-next';
import { request } from '../lib/api';
import { watchCalendarRefresh } from '../lib/calendarRefresh';
const props=defineProps<{today:string;settings:any;data:any}>();
const emit=defineEmits<{edit:[entity:string,record?:any,defaults?:any];checkin:[date:string,checked:boolean]}>();
const anchor=ref(props.today.slice(0,7)+'-01'); const selected=ref(props.today); const hovered=ref('');
const events=ref<any[]>([]); const checked=ref<string[]>([]); const loading=ref(false); const saving=ref(false); const error=ref(''); let sequence=0;
const day=(value:string)=>new Date(value+'T12:00:00Z');
function add(value:string,n:number){const date=day(value);date.setUTCDate(date.getUTCDate()+n);return date.toISOString().slice(0,10);}
const start=computed(()=>add(anchor.value,-((day(anchor.value).getUTCDay()-Number(props.settings.week_starts_on??1)+7)%7)));
const days=computed(()=>Array.from({length:42},(_,i)=>add(start.value,i)));
const weekdays=computed(()=>Array.from({length:7},(_,i)=>['Sun','Mon','Tue','Wed','Thu','Fri','Sat'][(i+Number(props.settings.week_starts_on??1))%7]));
const heading=computed(()=>day(anchor.value).toLocaleDateString('en-GB',{month:'long',year:'numeric',timeZone:'UTC'}));
const detailDate=computed(()=>hovered.value||selected.value);
const detailHeading=computed(()=>day(detailDate.value).toLocaleDateString('en-GB',{weekday:'short',day:'numeric',month:'short',timeZone:'UTC'}));
const localDate=(value:string)=>new Intl.DateTimeFormat('en-CA',{timeZone:props.settings.timezone||'Asia/Makassar'}).format(new Date(value));
const time=(value:string)=>new Intl.DateTimeFormat('en-GB',{hour:'2-digit',minute:'2-digit',timeZone:props.settings.timezone||'Asia/Makassar'}).format(new Date(value));
const byDay=computed(()=>Object.fromEntries(days.value.map(date=>[date,events.value.filter(e=>localDate(e.starts_at)<=date&&localDate(e.ends_at)>=date).map(e=>({...e,entity:'events',label:e.all_day?'All day':localDate(e.starts_at)<date?'Continues':time(e.starts_at)}))])));
const detailItems=computed(()=>byDay.value[detailDate.value]||[]);
function move(n:number){const date=day(anchor.value);date.setUTCMonth(date.getUTCMonth()+n);anchor.value=date.toISOString().slice(0,10);selected.value=anchor.value;hovered.value='';}
async function load(){
 const seq=++sequence;loading.value=true;error.value='';events.value=[];checked.value=[];
 const params=new URLSearchParams({from:days.value[0],to:days.value[41],per_page:'100'});
 try{
  const [rows,checks]=await Promise.all([(async()=>{const rows:any[]=[];for(let page=1;;page++){
   const response=await request(`/data/events?${params}&page=${page}`,'GET');
   if(seq!==sequence)return [];rows.push(...response.data);if(page>=response.last_page)return rows;
  }})(),request(`/daily-checkins?${params}`,'GET')]);
  if(seq===sequence){events.value=rows;checked.value=checks.dates;}
 }catch(e:any){if(seq===sequence)error.value=e.message;}finally{if(seq===sequence)loading.value=false;}
}
async function checkDay(){if(saving.value||loading.value)return;const date=selected.value;const next=!checked.value.includes(date);saving.value=true;error.value='';try{await request('/daily-checkins','PUT',{date,checked:next});checked.value=next?[...checked.value,date]:checked.value.filter(d=>d!==date);emit('checkin',date,next);}catch(e:any){error.value=e.message;}finally{saving.value=false;}}
watchCalendarRefresh(()=>({data:props.data,range:start.value,timezone:props.settings.timezone}),load);
</script>
<template><section class="card home-calendar"><div class="card-header"><h2><CalendarDays :size="21"/> Your month</h2><Link href="/calendar" class="quiet-link">Open <ChevronRight :size="16"/></Link></div><div class="month-navigation"><button class="btn-icon" aria-label="Previous month" @click="move(-1)"><ChevronLeft :size="18"/></button><h3>{{heading}}</h3><button class="btn-icon" aria-label="Next month" @click="move(1)"><ChevronRight :size="18"/></button><button class="text-button month-today" @click="anchor=today.slice(0,7)+'-01';selected=today">Today</button></div><p v-if="error" class="error-banner" role="alert">{{error}} <button @click="load()">Retry</button></p><p v-if="loading" role="status" class="muted">Loading your month…</p><div class="mini-month" @mouseleave="hovered=''"><span v-for="name in weekdays" :key="name" class="month-weekday">{{name}}</span><button v-for="date in days" :key="date" class="month-day" :class="{outside:date.slice(0,7)!==anchor.slice(0,7),selected:date===selected,today:date===today,checked:checked.includes(date),'has-event':byDay[date]?.some(i=>i.entity==='events')}" :aria-label="`${date}${date===today?', today':''}, ${(byDay[date]||[]).length} events${checked.includes(date)?', checked in':''}`" :aria-pressed="date===selected" @click="selected=date;hovered=''" @mouseenter="hovered=date" @focus="selected=date"><span>{{Number(date.slice(8))}}</span><Check v-if="checked.includes(date)" :size="10" class="day-tick"/><span class="day-markers"><i v-if="byDay[date]?.some(i=>i.entity==='events')" class="event-marker"></i></span></button></div><div class="month-legend"><span><i class="event-marker"></i>Event</span><span><Check :size="12"/>Daily check-in</span></div><div class="day-details"><div class="day-detail-heading"><strong>{{detailHeading}}</strong><button v-if="!hovered||hovered===selected" class="day-check" :aria-pressed="checked.includes(selected)" :disabled="saving||loading" @click="checkDay"><Check :size="16"/>{{checked.includes(selected)?'Checked in':'Check in'}}</button></div><p v-if="!loading&&!detailItems.length" class="muted">Nothing planned. A little space for you.</p><button v-for="item in detailItems" :key="item.entity+item.id" class="day-item" @click="emit('edit',item.entity,item)"><span>{{item.label}}</span><strong>{{item.title}}</strong><ChevronRight :size="15"/></button><button class="card-add" @click="emit('edit','events',undefined,{starts_at:selected+'T09:00',ends_at:selected+'T10:00'})"><Plus :size="17"/> Add event on {{selected.slice(8)}} {{day(selected).toLocaleDateString('en-GB',{month:'short',timeZone:'UTC'})}}</button></div></section></template>
