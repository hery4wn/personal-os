<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { CalendarDays, Save, X } from 'lucide-vue-next';
import { request } from '../lib/api';
import { schemas, type FieldSchema } from '../lib/schemas';

const props=defineProps<{entity:string;record?:any;defaults?:any;lookups:any;settings:any;today:string}>();
const emit=defineEmits<{saved:[];close:[]}>();
const schema=computed(()=>schemas[props.entity]);
const dialog=ref<HTMLDialogElement>(); const busy=ref(false); const failure=ref('');
const errors=ref<Record<string,string[]>>({}); const form=reactive<Record<string,any>>({});
const timezone=props.settings.timezone || 'Asia/Makassar';
const weekdays=[{value:1,label:'Mon'},{value:2,label:'Tue'},{value:3,label:'Wed'},{value:4,label:'Thu'},{value:5,label:'Fri'},{value:6,label:'Sat'},{value:7,label:'Sun'}];
const noteItems=ref<any[]>([]); const noteLoading=ref(false); const noteError=ref(''); let noteSequence=0;
const snapshot=ref<any>(null); const summaryLoading=ref(false); const summaryError=ref(''); let summarySequence=0; let summaryTimer:ReturnType<typeof setTimeout>;
let closed=false;

function addDays(value:string,days:number) { const date=new Date(value+'T12:00:00Z'); date.setUTCDate(date.getUTCDate()+days); return date.toISOString().slice(0,10); }
function firstWeekDay(value:string) { const day=new Date(value+'T12:00:00Z').getUTCDay(); return addDays(value,-(day-Number(props.settings.week_starts_on ?? 1)+7)%7); }
function localDateTime(value:any):string {
  if(!value)return '';
  const text=String(value);
  if(!/(?:Z|[+-]\d{2}:?\d{2})$/i.test(text))return text.replace(' ','T').slice(0,16);
  const date=new Date(text); if(Number.isNaN(date.valueOf()))return '';
  const parts=new Intl.DateTimeFormat('en-CA',{timeZone:timezone,year:'numeric',month:'2-digit',day:'2-digit',hour:'2-digit',minute:'2-digit',hourCycle:'h23'}).formatToParts(date);
  const part=(type:string)=>parts.find(item=>item.type===type)?.value || '';
  return `${part('year')}-${part('month')}-${part('day')}T${part('hour')}:${part('minute')}`;
}
function initialForm() {
  const defaults:Record<string,any>={...schema.value.defaults};
  if(['accounts','savings','wishlist'].includes(props.entity))defaults.currency=props.settings.currency || 'IDR';
  if(props.entity==='transactions') {defaults.transaction_date=props.today;defaults.account_id=props.settings.default_finance_account_id || '';}
  if(props.entity==='habits')defaults.start_date=props.today;
  if(props.entity==='events') {defaults.starts_at=props.today+'T09:00';defaults.ends_at=props.today+'T10:00';}
  if(props.entity==='reviews') {defaults.week_start=firstWeekDay(props.today);defaults.week_end=addDays(defaults.week_start,6);}
  const values={...defaults,...props.defaults,...props.record};
  if(props.entity==='reviews'&&!props.record&&!props.defaults?.week_end)values.week_end=addDays(values.week_start,6);
  if(props.entity==='tasks'&&!props.record&&props.defaults?.due_at&&props.defaults?.due_has_time===undefined)values.due_has_time=String(values.due_at).length>10;
  for(const field of schema.value.fields) {
    const value=values[field.key];
    if(field.type==='checkbox')form[field.key]=Boolean(value);
    else if(field.type==='weekdays')form[field.key]=Array.isArray(value)?[...value].map(Number):[];
    else if(['due_at','starts_at','ends_at'].includes(field.key)) {
      const local=localDateTime(value);
      form[field.key]=(props.entity==='tasks'&&!values.due_has_time)||(props.entity==='events'&&values.all_day)?local.slice(0,10):local;
    } else if(field.type==='date')form[field.key]=value?String(value).slice(0,10):'';
    else form[field.key]=value ?? '';
  }
}
initialForm();
const initial=JSON.parse(JSON.stringify(form));
const visibleFields=computed(()=>schema.value.fields.filter(field=>(!field.when||form[field.when.key]===field.when.value)&&(field.key!=='notable_id'||Boolean(form.notable_type))));
const accountCurrency=computed(()=>props.entity==='transactions'?(props.lookups.accounts || []).find((item:any)=>item.id===form.account_id)?.currency || props.settings.currency:form.currency || props.settings.currency);
const amountStep=computed(()=>['IDR','JPY','KRW'].includes(accountCurrency.value || 'IDR')?'1':'0.01');
const savedSnapshot=computed(()=>Boolean(props.record?.snapshot && form.week_start===initial.week_start && form.week_end===initial.week_end));
const displaySnapshot=computed(()=>savedSnapshot.value?props.record.snapshot:snapshot.value);
const immutableCurrency=computed(()=>Boolean(props.record&&['savings','wishlist'].includes(props.entity)));
function inputType(field:FieldSchema) {
  if(field.key==='due_at')return form.due_has_time?'datetime-local':'date';
  if(field.key==='starts_at'||field.key==='ends_at')return form.all_day?'date':'datetime-local';
  if(field.type==='money')return 'number';
  if(field.type==='currency')return 'text';
  return field.type;
}
function fieldOptions(field:FieldSchema) {
  if(field.options) {
    if(props.entity==='ideas'&&field.key==='status'&&props.record?.status==='converted')return [...field.options,{value:'converted',label:'Converted'}];
    return field.options;
  }
  let items=field.lookup==='notable'?noteItems.value:props.lookups[field.lookup || ''] || [];
  if(field.lookup==='accounts'||field.lookup==='categories')items=items.filter((item:any)=>(item.active||item.id===props.record?.[field.key])&&(field.lookup!=='categories'||item.type===form.type));
  return items.map((item:any)=>({value:item.id,label:(item.name || item.title || 'Untitled')+(field.lookup==='accounts'?` (${item.currency})`:'')+(item.active===false?' · inactive':'')}));
}
function fieldErrors(key:string) { return Object.entries(errors.value).filter(([field])=>field===key||field.startsWith(key+'.')).flatMap(([,messages])=>messages); }
function fieldHelp(field:FieldSchema) {
  if(field.key==='currency'&&immutableCurrency.value)return 'This item keeps its original currency.';
  if(['due_at','starts_at','ends_at'].includes(field.key)&&inputType(field)==='datetime-local')return `Local time in ${timezone}`;
  return field.help;
}
function updateValue(field:FieldSchema,event:Event) { const value=(event.target as HTMLInputElement).value; form[field.key]=field.type==='currency'?value.toUpperCase():value; }
function descriptions(field:FieldSchema) { return [fieldHelp(field)?'help-'+field.key:'',fieldErrors(field.key).length?'error-'+field.key:''].filter(Boolean).join(' ') || undefined; }
function fieldMin(field:FieldSchema) {
  if(field.type==='money')return field.key==='opening_balance'?undefined:['amount','target_amount'].includes(field.key)?amountStep.value:0;
  if(props.entity==='reviews'&&field.key==='week_end')return form.week_start || undefined;
  return field.min;
}
function fieldMax(field:FieldSchema) { return props.entity==='reviews'&&field.key==='week_end'&&form.week_start?addDays(form.week_start,6):field.max; }
function money(value:any,currency:string=accountCurrency.value || 'IDR') {
  return new Intl.NumberFormat(currency==='IDR'?'id-ID':'en-US',{style:'currency',currency,...(currency==='IDR'?{minimumFractionDigits:0,maximumFractionDigits:0}:{})}).format(Number(value || 0));
}
watch(()=>form.due_has_time,(timed)=>{
  if(props.entity!=='tasks'||!form.due_at)return;
  form.due_at=timed?(form.due_at.includes('T')?form.due_at:form.due_at+'T09:00'):form.due_at.slice(0,10);
});
watch(()=>form.all_day,(allDay)=>{
  if(props.entity!=='events')return;
  for(const key of ['starts_at','ends_at'])if(form[key])form[key]=allDay?form[key].slice(0,10):form[key].includes('T')?form[key]:form[key]+(key==='starts_at'?'T09:00':'T10:00');
});
watch(()=>form.type,()=>{
  if(props.entity==='transactions'&&form.category_id&&!(props.lookups.categories || []).some((item:any)=>item.id===form.category_id&&item.type===form.type))form.category_id='';
});
watch(()=>form.status,()=>{if(props.entity==='projects'&&form.status!=='active')form.is_current_focus=false;});
watch(()=>form.notable_type,async(type,oldType)=>{
  if(props.entity!=='notes')return;
  const token=++noteSequence; noteError.value='';noteLoading.value=false;
  if(oldType!==undefined&&oldType!==type)form.notable_id='';
  const entity=({project:'projects',goal:'goals',content:'content'} as Record<string,string>)[type];
  noteItems.value=entity?props.lookups[entity] || []:[];
  if(!entity)return;
  if(entity!=='content'&&noteItems.value.length<500)return;
  noteLoading.value=true;
  try {
    const records:any[]=[];
    for(let page=1;;page++) {
      const response=await request(`/data/${entity}?per_page=100&page=${page}`,'GET');
      if(token!==noteSequence||closed)return;
      records.push(...response.data);
      if(page>=response.last_page)break;
    }
    noteItems.value=records;
  } catch(error:any) {if(token===noteSequence)noteError.value=error.message || 'Linked items could not be loaded.';}
  finally {if(token===noteSequence)noteLoading.value=false;}
},{immediate:true});
watch(()=>form.week_start,(start,previous)=>{
  if(props.entity==='reviews'&&start&&start!==previous&&/^\d{4}-\d{2}-\d{2}$/.test(start))form.week_end=addDays(start,6);
});
watch(()=>[form.week_start,form.week_end],()=>{
  if(props.entity!=='reviews')return;
  clearTimeout(summaryTimer); const token=++summarySequence; summaryError.value=''; snapshot.value=null; summaryLoading.value=false;
  if(savedSnapshot.value||!/^\d{4}-\d{2}-\d{2}$/.test(form.week_start || '')||!form.week_end||form.week_end<form.week_start||form.week_end>addDays(form.week_start,6))return;
  summaryLoading.value=true;
  summaryTimer=setTimeout(async()=>{
    try {
      const params=new URLSearchParams({week_start:form.week_start,week_end:form.week_end});
      const response=await request(`/review-summary?${params}`,'GET');
      if(token===summarySequence&&!closed)snapshot.value=response;
    } catch(error:any) {if(token===summarySequence&&!closed)summaryError.value=error.message || 'The weekly summary could not be loaded.';}
    finally {if(token===summarySequence&&!closed)summaryLoading.value=false;}
  },200);
},{immediate:true});

async function save() {
  if(busy.value)return; busy.value=true; errors.value={};failure.value='';
  try {
    const payload:Record<string,any>={};
    for(const field of schema.value.fields) {
      const value=form[field.key];
      if(field.type==='checkbox')payload[field.key]=Boolean(value);
      else if(field.type==='weekdays')payload[field.key]=form.frequency_type==='daily'?null:value.map(Number);
      else if(field.type==='number')payload[field.key]=value===''?null:Number(value);
      else payload[field.key]=value===''?null:value;
    }
    if(props.entity==='notes'&&!form.notable_type) {payload.notable_type=null;payload.notable_id=null;}
    if(immutableCurrency.value)delete payload.currency;
    for(const key of ['due_at','starts_at','ends_at'])if(key in payload&&payload[key]) {
      const flag=key==='due_at'?'due_has_time':'all_day';
      // Preserve the exact instant when a user only changes another field.
      if(props.record&&form[key]===initial[key]&&form[flag]===initial[flag])payload[key]=props.record[key];
      else if(props.entity==='events'&&form.all_day)payload[key]=form[key].slice(0,10)+(key==='starts_at'?'T00:00:00':'T23:59:59');
    }
    await request(`/data/${props.entity}${props.record?'/'+props.record.id:''}`,props.record?'PATCH':'POST',payload);
    emit('saved');
  } catch(error:any) {
    errors.value=error.errors || {};failure.value=error.message || 'Your changes could not be saved. Please try again.';
    await nextTick();dialog.value?.querySelector<HTMLElement>('[aria-invalid="true"]')?.focus();
  } finally {busy.value=false;}
}
onMounted(async()=>{dialog.value?.showModal();await nextTick();dialog.value?.querySelector<HTMLElement>('[data-form-field]')?.focus();});
onBeforeUnmount(()=>{closed=true;clearTimeout(summaryTimer);summarySequence++;noteSequence++;dialog.value?.close();});
</script>

<template>
 <dialog ref="dialog" class="form-dialog" aria-labelledby="record-form-title" @cancel.prevent="!busy&&emit('close')">
  <form @submit.prevent="save">
   <header class="dialog-header"><div><span class="eyebrow">YOUR PERSONAL WORKSPACE</span><h2 id="record-form-title">{{record?'Edit':'New'}} {{schema.label}}</h2></div><button type="button" class="btn-icon" aria-label="Close form" :disabled="busy" @click="emit('close')"><X :size="20"/></button></header>
   <div class="dialog-body">
    <p v-if="failure" class="error-banner" role="alert">{{failure}}</p>
    <section v-if="entity==='reviews'" class="review-summary" aria-label="Weekly progress summary">
     <div class="summary-heading"><CalendarDays :size="17"/><strong>{{savedSnapshot?'Saved weekly snapshot':'This week at a glance'}}</strong></div>
     <p v-if="summaryLoading" class="form-help" role="status">Gathering your progress…</p>
     <p v-else-if="summaryError" class="field-error" role="alert">{{summaryError}}</p>
     <template v-else-if="displaySnapshot"><div class="summary-metrics"><div><strong>{{displaySnapshot.tasks_completed}} / {{displaySnapshot.tasks_total}}</strong><span>Tasks completed</span></div><div><strong>{{displaySnapshot.habit_rate}}%</strong><span>Habit completion</span></div><div><strong>{{displaySnapshot.projects_updated}}</strong><span>Projects updated</span></div><div><strong>{{displaySnapshot.content_published}}</strong><span>Content published</span></div></div><div class="summary-finance"><span>Income <strong>{{money(displaySnapshot.income,displaySnapshot.currency)}}</strong></span><span>Expenses <strong>{{money(displaySnapshot.expenses,displaySnapshot.currency)}}</strong></span><span>Net savings <strong>{{money(displaySnapshot.net_savings,displaySnapshot.currency)}}</strong></span></div><p class="form-help">{{displaySnapshot.projects_completed || 0}} projects completed · {{displaySnapshot.ideas_captured || 0}} ideas captured</p></template>
     <p v-else class="form-help">Choose the dates for your review to see your progress.</p>
     <p v-if="savedSnapshot" class="form-help">Your saved metrics stay the same when you edit reflections. Changing the week creates a new snapshot.</p>
    </section>
    <div class="form-grid">
     <div v-for="field in visibleFields" :key="field.key" class="form-field" :class="{'full-width':field.wide}">
      <label v-if="field.type==='checkbox'" class="checkbox-label" :for="'record-'+field.key"><input :id="'record-'+field.key" v-model="form[field.key]" type="checkbox" :disabled="busy || (field.key==='is_current_focus'&&form.status!=='active')" :aria-invalid="fieldErrors(field.key).length>0" :aria-describedby="descriptions(field)" data-form-field/><span>{{field.label}}</span></label>
      <fieldset v-else-if="field.type==='weekdays'" class="weekday-field"><legend>{{field.label}}</legend><div class="weekday-options"><label v-for="day in weekdays" :key="day.value"><input v-model="form[field.key]" type="checkbox" :value="day.value" :disabled="busy" :aria-describedby="descriptions(field)" :aria-invalid="fieldErrors(field.key).length>0"/><span>{{day.label}}</span></label></div></fieldset>
      <template v-else>
       <label :for="'record-'+field.key">{{field.label}}<span v-if="field.type==='money'"> ({{accountCurrency}})</span><span v-if="field.required" class="required-mark" aria-hidden="true"> *</span></label>
       <textarea v-if="field.type==='textarea'" :id="'record-'+field.key" v-model="form[field.key]" :required="field.required" :maxlength="field.maxlength" :placeholder="field.placeholder" :disabled="busy" :rows="field.key==='body'||field.key==='script_notes'?6:3" :aria-invalid="fieldErrors(field.key).length>0" :aria-describedby="descriptions(field)" data-form-field/>
       <select v-else-if="field.type==='select'" :id="'record-'+field.key" v-model="form[field.key]" :required="field.required" :disabled="busy||(field.key==='notable_id' && noteLoading)" :aria-invalid="fieldErrors(field.key).length>0" :aria-describedby="descriptions(field)" data-form-field><option v-if="field.lookup||field.emptyLabel" value="">{{field.key==='notable_id' && noteLoading?'Loading items…':field.emptyLabel || 'Choose an option'}}</option><option v-for="option in fieldOptions(field)" :key="option.value" :value="option.value">{{option.label}}</option></select>
       <input v-else :id="'record-'+field.key" :value="form[field.key]" @input="updateValue(field,$event)" :type="inputType(field)" :required="field.required" :min="fieldMin(field)" :max="fieldMax(field)" :step="field.type==='money'?amountStep:field.type==='number'?1:undefined" :maxlength="field.maxlength" :placeholder="field.placeholder" :pattern="field.type==='currency'?'[A-Z]{3}':undefined" :list="field.type==='currency'?'record-currencies':undefined" :inputmode="field.type==='money'?'decimal':field.type==='number'?'numeric':undefined" :disabled="busy||(field.key==='currency'&&immutableCurrency)" :aria-invalid="fieldErrors(field.key).length>0" :aria-describedby="descriptions(field)" data-form-field/>
      </template>
      <small v-if="fieldHelp(field)" :id="'help-'+field.key" class="form-help">{{fieldHelp(field)}}</small>
      <small v-for="(message,index) in fieldErrors(field.key)" :id="index===0?'error-'+field.key:undefined" :key="message" class="field-error">{{message}}</small>
      <small v-if="field.key==='notable_id'&&noteError" class="field-error" role="alert">{{noteError}}</small>
     </div>
    </div>
    <datalist id="record-currencies"><option value="IDR"/><option value="USD"/><option value="EUR"/><option value="GBP"/><option value="SGD"/><option value="JPY"/><option value="KRW"/><option value="AUD"/></datalist>
    <p v-if="entity==='transactions'&&!lookups.accounts?.some((item:any)=>item.active)" class="form-help">Create an active account in Finance before adding a transaction.</p>
    <p v-if="entity==='reviews'" class="form-help">Your week starts on {{Number(settings.week_starts_on)===0?'Sunday':'Monday'}}. A review covers up to seven days.</p>
   </div>
   <footer class="dialog-footer"><button type="button" class="btn btn-secondary" :disabled="busy" @click="emit('close')">Cancel</button><button type="submit" class="btn btn-primary" :disabled="busy"><Save :size="16"/>{{busy?'Saving…':'Save '+schema.label}}</button></footer>
  </form>
 </dialog>
</template>

<style scoped>
.required-mark{color:var(--accent)}.form-field>label{font-size:.78rem;color:#687587;font-weight:550}.form-help{margin-top:0}.checkbox-label{min-height:44px;margin:0;font-weight:400}.weekday-field{border:0;padding:0;margin:0;min-width:0}.weekday-field legend{font-size:.78rem;color:#687587;font-weight:550;margin-bottom:11px}.weekday-options{display:flex;flex-wrap:wrap;gap:9px}.weekday-options label{display:flex;align-items:center;gap:5px;padding:9px 7px;background:#f5f6f7;border-radius:6px;font-size:.74rem}.review-summary{padding:17px;background:#f6f8f7;border:1px solid #e7ece9;border-radius:9px;margin-bottom:23px}.summary-heading{display:flex;align-items:center;gap:8px;color:#5a796f;font-size:.83rem}.summary-metrics{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin:17px 0}.summary-metrics>div{display:flex;flex-direction:column;gap:5px}.summary-metrics strong{font-size:1.15rem;font-weight:600}.summary-metrics span{font-size:.65rem;color:#76828d}.summary-finance{display:flex;flex-wrap:wrap;gap:15px;margin:13px 0;font-size:.69rem;color:#788592}.summary-finance strong{display:block;font-size:.8rem;color:#40564e;margin-top:4px}.summary-heading+.form-help{margin-top:12px}input:disabled,select:disabled{color:#707b88;background:#f5f6f7}@media(max-width:600px){.summary-metrics{grid-template-columns:1fr 1fr}.weekday-options{gap:6px}.weekday-options label{padding:8px 6px}.dialog-header h2{font-size:1.15rem}}
</style>
