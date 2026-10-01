<script setup lang="ts">
import { computed, ref } from 'vue';
import { CalendarDays, ChevronLeft, ChevronRight, Plus, Clock3 } from 'lucide-vue-next';
import { request } from '../lib/api';
import { watchCalendarRefresh } from '../lib/calendarRefresh';

const props=defineProps<{today:string;settings:any;data:any}>();
const emit=defineEmits<{edit:[entity:string,record?:any,defaults?:any]}>();
type View='month'|'week'|'agenda';
const view=ref<View>(window.matchMedia('(max-width:767px)').matches?'agenda':'month');
const anchor=ref(props.today);const events=ref<any[]>([]);const loading=ref(false);const error=ref('');
const page=ref(1);const lastPage=ref(1);let sequence=0;
const weekdayNames=['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
const firstDay=computed(()=>Number(props.settings.week_starts_on??1));
function day(value:string){return new Date(value+'T12:00:00Z');}
function key(date:Date){return date.toISOString().slice(0,10);}
function add(value:string,n:number){const d=day(value);d.setUTCDate(d.getUTCDate()+n);return key(d);}
function weekStart(value:string){return add(value,-((day(value).getUTCDay()-firstDay.value+7)%7));}
const range=computed(()=>{
 if(view.value==='agenda')return {from:anchor.value,to:anchor.value};
 if(view.value==='week'){const from=weekStart(anchor.value);return {from,to:add(from,6)};}
 const from=weekStart(anchor.value.slice(0,7)+'-01');return {from,to:add(from,41)};
});
const days=computed(()=>Array.from({length:view.value==='month'?42:view.value==='week'?7:1},(_,i)=>add(range.value.from,i)));
const heading=computed(()=>new Intl.DateTimeFormat('en-GB',{month:'long',year:'numeric',timeZone:'UTC',...(view.value==='agenda'?{day:'numeric' as const}:{})}).format(day(anchor.value)));
const weekdayHeaders=computed(()=>Array.from({length:7},(_,i)=>weekdayNames[(i+firstDay.value)%7]));
const localDate=(value:string)=>new Intl.DateTimeFormat('en-CA',{timeZone:props.settings.timezone||'Asia/Makassar'}).format(new Date(value));
function time(value:string){return new Intl.DateTimeFormat('en-GB',{hour:'2-digit',minute:'2-digit',timeZone:props.settings.timezone||'Asia/Makassar'}).format(new Date(value));}
function items(date:string){
 const schedule=events.value.filter(e=>localDate(e.starts_at)<=date&&localDate(e.ends_at)>=date).map(e=>({...e,entity:'events',sort:e.all_day?'00:00':time(e.starts_at),displayTime:e.all_day?'All day':localDate(e.starts_at)<date?'Continues':time(e.starts_at)}));
 return schedule.sort((a,b)=>a.sort.localeCompare(b.sort));
}
function navigate(direction:number){if(view.value==='month'){const d=day(anchor.value.slice(0,7)+'-01');d.setUTCMonth(d.getUTCMonth()+direction);anchor.value=key(d);}else anchor.value=add(anchor.value,direction*(view.value==='week'?7:1));}
function addEvent(date=anchor.value){emit('edit','events',undefined,{starts_at:date+'T09:00',ends_at:date+'T10:00',category:'Personal'});}
async function load(append=false){
 const seq=++sequence;loading.value=true;error.value='';
 try{
  const params=new URLSearchParams({...range.value,per_page:'100',page:String(append?page.value+1:1)});
  const response=await request(`/data/events?${params}`,'GET');
  if(seq!==sequence)return;
  events.value=append?[...events.value,...response.data]:response.data;
  page.value=response.current_page;lastPage.value=response.last_page;
 }catch(e:any){if(seq===sequence)error.value=e.message;}finally{if(seq===sequence)loading.value=false;}
}
watchCalendarRefresh(()=>({data:props.data,range:range.value,timezone:props.settings.timezone}),()=>load());
</script>

<template>
 <section class="calendar-module">
  <header class="module-header"><div><div class="eyebrow">GIVE YOUR PLANS A PLACE</div><h1>Calendar</h1><p>Time for your work, and the rest of your life.</p></div><button class="btn btn-primary" @click="addEvent()"><Plus :size="17"/> Add event</button></header>
  <div class="module-toolbar"><div class="calendar-navigation"><button class="btn-icon" aria-label="Previous calendar period" @click="navigate(-1)"><ChevronLeft :size="19"/></button><h2>{{heading}}</h2><button class="btn-icon" aria-label="Next calendar period" @click="navigate(1)"><ChevronRight :size="19"/></button><button class="btn btn-secondary" @click="anchor=today">Today</button></div><div class="tabs" aria-label="Calendar view"><button v-for="option in (['month','week','agenda'] as View[])" :key="option" class="tab" :class="{active:view===option}" :aria-pressed="view===option" @click="view=option">{{option.charAt(0).toUpperCase()+option.slice(1)}}</button></div></div>
  <div class="calendar-context"><span><span class="legend-dot event-dot"></span>Events</span><span class="calendar-zone">{{settings.timezone?.replaceAll('_',' ')}}</span></div>
  <p v-if="error" class="error-banner" role="alert">{{error}} <button class="text-button" @click="load()">Retry</button></p>
  <p v-if="loading" class="calendar-loading" role="status">Updating calendar...</p>
  <template v-if="view==='month'"><div class="calendar-grid month-grid"><div v-for="weekday in weekdayHeaders" :key="weekday" class="calendar-weekday"><abbr :title="weekday">{{weekday.slice(0,3)}}</abbr></div><div v-for="date in days" :key="date" class="calendar-day" :class="{today:date===today,'other-month':date.slice(0,7)!==anchor.slice(0,7)}"><button class="calendar-date-button" :aria-label="`Add event on ${date}`" @click="addEvent(date)">{{Number(date.slice(8))}}</button><button v-for="item in items(date)" :key="item.entity+item.id" class="calendar-item" :title="`${item.displayTime} · ${item.title}`" @click="emit('edit',item.entity,item)"><span>{{item.title}}</span><small v-if="item.entity==='events'&&!item.all_day">{{item.displayTime}}</small></button></div></div></template>
  <div v-else-if="view==='week'" class="calendar-week"><section v-for="date in days" :key="date" class="week-day" :class="{today:date===today}"><header><span>{{weekdayNames[day(date).getUTCDay()].slice(0,3)}}</span><strong>{{Number(date.slice(8))}}</strong><button class="btn-icon" :aria-label="`Add event on ${date}`" @click="addEvent(date)"><Plus :size="15"/></button></header><button v-for="item in items(date)" :key="item.entity+item.id" class="week-event" @click="emit('edit',item.entity,item)"><small>{{item.displayTime}}</small><strong>{{item.title}}</strong><span>{{item.category||'Event'}}</span></button><p v-if="!items(date).length" class="week-free">Open day</p></section></div>
  <section v-else class="agenda-card card"><div class="card-header"><h2><CalendarDays :size="20"/> {{weekdayNames[day(anchor).getUTCDay()]}}</h2><input class="agenda-date" type="date" v-model="anchor" aria-label="Agenda date"/></div><div v-if="!items(anchor).length&&!loading" class="empty-state"><Clock3 :size="32"/><h3>A little breathing room.</h3><p>No events on this day.</p><button class="btn btn-secondary" @click="addEvent()">Plan a time block</button></div><button v-for="item in items(anchor)" :key="item.entity+item.id" class="agenda-entry" @click="emit('edit',item.entity,item)"><span class="agenda-time">{{item.displayTime}}</span><span class="agenda-entry-body"><strong>{{item.title}}</strong><small>{{item.category||'Event'}}<template v-if="item.entity==='events'&&!item.all_day"> · {{time(item.starts_at)}}–{{time(item.ends_at)}}</template></small></span><ChevronRight :size="17"/></button></section>
  <button v-if="page<lastPage" class="btn btn-secondary calendar-load" :disabled="loading" @click="load(true)">Load more events</button>
 </section>
</template>

<style scoped>
.module-header h1{margin-top:7px}.calendar-navigation{display:flex;align-items:center;gap:10px}.calendar-navigation h2{font-size:1.1rem;min-width:175px;text-align:center}.calendar-navigation>.btn{margin-left:10px}.module-toolbar .tabs{margin:0}.calendar-context{display:flex;align-items:center;gap:18px;margin-bottom:16px;color:#737e8e;font-size:.75rem}.calendar-context>span{display:flex;align-items:center;gap:6px}.legend-dot{width:8px;height:8px;border-radius:3px;background:#8cabc6}.calendar-zone{margin-left:auto}.calendar-loading{font-size:.8rem;color:#858e9a;margin-bottom:10px}.calendar-date-button{width:28px;height:28px;display:flex;align-items:center;justify-content:center;border-radius:50%;margin-bottom:7px}.today>.calendar-date-button{background:var(--accent);color:white}.calendar-item{display:flex;align-items:center;gap:5px;flex-wrap:wrap;white-space:normal;font-size:.7rem;line-height:1.45}.calendar-item>span{min-width:0;overflow:hidden;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical}.calendar-item small{display:block;font-size:.6rem;width:100%;opacity:.8}.calendar-item.completed>span{text-decoration:line-through}.calendar-week{display:grid;grid-template-columns:repeat(7,minmax(130px,1fr));overflow-x:auto;border:1px solid var(--border);background:#fff;border-radius:10px;min-height:380px}.week-day{border-right:1px solid var(--border);padding:12px}.week-day:last-child{border-right:0}.week-day.today{background:#fffaf7}.week-day>header{display:flex;align-items:center;gap:7px;margin-bottom:19px;color:#838c99}.week-day>header span{font-size:.7rem}.week-day>header strong{font-size:1.05rem;color:#475569}.week-day>header button{margin-left:auto}.week-event{display:flex;flex-direction:column;gap:6px;width:100%;text-align:left;background:#eef4fa;border-left:2px solid #8fabcb;border-radius:4px;padding:11px 8px;margin-bottom:10px}.week-event.task{background:#fbefea;border-color:#c88c76}.week-event strong{font-size:.8rem;line-height:1.5;font-weight:550}.week-event small,.week-event>span{font-size:.65rem;color:#7f8b9b}.week-free{color:#a6afbb;text-align:center;padding-top:25px;font-size:.7rem}.agenda-date{max-width:160px}.agenda-entry{display:flex;align-items:center;gap:24px;padding:23px 5px;width:100%;text-align:left;border-bottom:1px solid var(--border)}.agenda-entry:last-child{border:0}.agenda-time{font-size:.8rem;color:#798697;min-width:83px}.agenda-entry-body{display:flex;flex-direction:column;gap:5px;flex:1}.agenda-entry-body strong{font-size:.95rem;font-weight:550}.agenda-entry-body small{font-size:.75rem;color:#87929f}.agenda-entry>svg{color:#9ba3af}.calendar-load{margin-top:20px}.calendar-weekday abbr{text-decoration:none}
@media(max-width:767px){.calendar-navigation{gap:5px;width:100%;justify-content:space-between}.calendar-navigation h2{min-width:0;font-size:1rem}.calendar-navigation>.btn{margin:0;font-size:.75rem;padding:7px 10px}.calendar-context{gap:12px;font-size:.65rem}.calendar-zone{font-size:.6rem}.month-grid .calendar-day{min-height:90px}.calendar-item{padding:3px;gap:2px;font-size:.55rem}.calendar-item small{display:none}.calendar-item>svg{width:9px}.calendar-date-button{width:24px;height:24px;font-size:.7rem}.agenda-entry{gap:12px}.agenda-time{min-width:65px;font-size:.75rem}.agenda-entry-body strong{font-size:.85rem}.agenda-date{max-width:140px;font-size:.75rem}.agenda-card{padding:16px}}
</style>
