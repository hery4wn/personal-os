<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, reactive, ref, watch } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { ArrowLeft, ArrowUpRight, Check, ChevronLeft, ChevronRight, Flame, FolderKanban, Leaf, Lightbulb, Pencil, Pin, Plus, Search, Target, Trash2, X } from 'lucide-vue-next';
import { request } from '../lib/api';

const props = defineProps<{ section:string; selectedId?:string|null; user:any; settings:any; today:string; data:any; summary:any; lookups:any; pagination?:any }>();
const emit = defineEmits<{ edit:[entity:string,record?:any,defaults?:any] }>();
const records = ref<any[]>([]); const page = ref(1); const lastPage = ref(1); const total = ref(0);
const query = ref(''); const status = ref(''); const loading = ref(false); const busy = ref(''); const error = ref(''); const success = ref('');
const linkedProjects = ref<any[]>([]); const projectsPage = ref(1); const projectsLastPage = ref(1); const projectsLoading = ref(false);
const conversionDialog = ref<HTMLDialogElement>(); const conversionIdea = ref<any>(null); const conversionError = ref('');
const conversion = reactive({type:'task',title:'',body:''});
const expanded = ref<string|null>(null);
let listSequence = 0; let projectSequence = 0; let queryTimer:ReturnType<typeof setTimeout>;
const names:Record<string,string> = {goals:'goal',ideas:'idea',notes:'note',habits:'habit'};
const descriptions:Record<string,string> = {goals:'A clear direction for the things that matter.',ideas:'A little space for your next big thing.',notes:'Thoughts, details, and things worth keeping.',habits:'Small steps, repeated. Build a rhythm that lasts.'};
const statuses:Record<string,string[]> = {goals:['active','achieved','paused','archived'],ideas:['inbox','exploring','planned','converted','archived']};
const horizons:Record<string,string> = {current:'Current focus',year:'This year',three_to_five_year:'3–5 years',someday:'Someday'};
const goal = computed(() => props.selectedId ? (props.data.goals || []).find((item:any) => item.id === props.selectedId) : null);
const sectionTitle = computed(() => props.section.charAt(0).toUpperCase()+props.section.slice(1));

function label(value:string) { return value.replaceAll('_',' ').replace(/^\w/,char => char.toUpperCase()); }
function hasMore(body?:string) { return !!body && (body.length>180 || body.split('\n').length>3); }
function failure(caught:any) { const details = caught?.errors ? Object.values(caught.errors).flat().join(' ') : ''; return details || caught?.message || 'This action could not be completed. Please try again.'; }
function date(value:string) {
  if (!value) return 'No target date';
  const [year,month,day] = value.slice(0,10).split('-');
  if (props.settings.date_format === 'Y-m-d') return `${year}-${month}-${day}`;
  if (props.settings.date_format === 'd/m/Y') return `${day}/${month}/${year}`;
  if (props.settings.date_format === 'm/d/Y') return `${month}/${day}/${year}`;
  return new Date(value.slice(0,10)+'T12:00:00Z').toLocaleDateString('en-GB',{day:'numeric',month:'short',year:'numeric',timeZone:'UTC'});
}
function weekday(value:string) { return new Date(value+'T12:00:00Z').toLocaleDateString('en-GB',{weekday:'short',timeZone:'UTC'}); }
function relationship(item:any) {
  if (!item.notable_id || !item.notable_type) return null;
  const entity = item.notable_type === 'content' ? 'content' : item.notable_type+'s';
  const related = (props.lookups[entity] || props.data[entity] || []).find((record:any) => record.id === item.notable_id);
  return {title:related?.name || related?.title || label(item.notable_type),url:recordUrl(item.notable_type,item.notable_id)};
}
function goalName(id:string) { return (props.lookups.goals || []).find((item:any) => item.id === id)?.title; }
function recordUrl(type:string,id:string) { return type === 'project' || type === 'goal' ? `/${type}s/${id}` : type === 'task' ? `/today?item=${id}` : `/${type === 'content' ? 'content' : type+'s'}?item=${id}`; }
function replaceRecord(record:any) { records.value = records.value.map(item => item.id === record.id ? record : item); }
function seed() {
  listSequence++; loading.value=false;
  records.value = [...(props.data[props.section] || [])];
  page.value=1; lastPage.value=props.pagination?.[props.section]?.last_page || 1;
  total.value=props.pagination?.[props.section]?.total ?? records.value.length;
}
async function load(next=1) {
  const sequence=++listSequence; const entity=props.section; loading.value=true; error.value='';
  const params=new URLSearchParams({page:String(next),per_page:'50'});
  if (query.value.trim()) params.set('q',query.value.trim());
  if (status.value) params.set('status',status.value);
  try {
    const response=await request(`/data/${entity}?${params}`,'GET');
    if(sequence!==listSequence || entity!==props.section)return;
    records.value=response.data; page.value=response.current_page; lastPage.value=response.last_page; total.value=response.total;
  } catch(caught) { if(sequence===listSequence)error.value=failure(caught); }
  finally { if(sequence===listSequence)loading.value=false; }
}
async function loadProjects(next=1) {
  if(!props.selectedId)return;
  const sequence=++projectSequence; projectsLoading.value=true; error.value='';
  try {
    const params=new URLSearchParams({goal_id:props.selectedId,page:String(next),per_page:'50'});
    const response=await request(`/data/projects?${params}`,'GET');
    if(sequence!==projectSequence)return;
    linkedProjects.value=response.data; projectsPage.value=response.current_page; projectsLastPage.value=response.last_page;
  } catch(caught) { if(sequence===projectSequence)error.value=failure(caught); }
  finally { if(sequence===projectSequence)projectsLoading.value=false; }
}
async function patch(item:any,values:any) {
  if(busy.value)return;
  busy.value=item.id; error.value=''; success.value='';
  try {
    const response=await request(`/data/${props.section}/${item.id}`,'PATCH',values); replaceRecord(response.data);
    if(props.section==='notes')records.value.sort((a,b)=>Number(b.pinned)-Number(a.pinned));
  }
  catch(caught) { error.value=failure(caught); }
  finally { busy.value=''; }
}
async function remove(item:any) {
  if(busy.value || !window.confirm(`Delete “${item.title || item.name || 'Untitled note'}”? This removes it from your workspace.`))return;
  busy.value=item.id; error.value=''; success.value='';
  try {
    await request(`/data/${props.section}/${item.id}`,'DELETE');
    if(props.section==='goals' && props.selectedId){router.visit('/goals');return;}
    records.value=records.value.filter(record=>record.id!==item.id); total.value=Math.max(0,total.value-1);
    await load(records.value.length ? page.value : Math.max(1,page.value-1));
    success.value=`${label(names[props.section])} deleted.`;
  } catch(caught) { error.value=failure(caught); }
  finally { busy.value=''; }
}
async function toggle(habit:any,day:any) {
  if(busy.value || !habit.active || !day.scheduled || day.date>props.today)return;
  busy.value=habit.id; error.value=''; success.value='';
  try {
    const response=await request(`/data/habits/${habit.id}/toggle`,'POST',{date:day.date});
    replaceRecord({...habit,week:habit.week.map((item:any)=>item.date===day.date?{...item,completed:response.completed}:item)});
    const refreshed=await request(`/data/habits/${habit.id}`,'GET'); replaceRecord(refreshed.data);
  } catch(caught) { error.value=failure(caught); }
  finally { busy.value=''; }
}
async function openConversion(idea:any) {
  conversionIdea.value=idea; conversion.type='task'; conversion.title=idea.title; conversion.body=idea.body || ''; conversionError.value='';
  await nextTick(); conversionDialog.value?.showModal();
}
function closeConversion() { if(busy.value)return; conversionDialog.value?.close(); conversionIdea.value=null; }
async function convert() {
  if(busy.value || !conversionIdea.value)return;
  busy.value=conversionIdea.value.id; conversionError.value='';
  const overrides=conversion.type==='project' ? {name:conversion.title,description:conversion.body} : {title:conversion.title,[conversion.type==='note'?'body':'description']:conversion.body};
  try {
    const response=await request(`/data/ideas/${conversionIdea.value.id}/convert`,'POST',{type:conversion.type,overrides});
    replaceRecord({...conversionIdea.value,status:'converted',converted_type:response.type,converted_id:response.data.id});
    success.value=`Idea converted to ${response.type}. Use its link to continue.`;
    conversionDialog.value?.close(); conversionIdea.value=null;
  } catch(caught) { conversionError.value=failure(caught); }
  finally { busy.value=''; }
}
watch(() => props.section, () => {query.value='';status.value='';expanded.value=null;error.value='';success.value='';page.value=1;});
watch(() => [props.section,props.data,props.selectedId], () => {
  if(query.value || status.value || page.value>1)void load(page.value); else seed();
  projectSequence++;projectsLoading.value=false;
  linkedProjects.value=[...(props.data.projects || [])];projectsPage.value=1;projectsLastPage.value=props.pagination?.projects?.last_page || 1;
  const item=new URLSearchParams(window.location.search).get('item');
  if(item && ['notes','ideas','habits'].includes(props.section))void reveal(item);
}, {immediate:true});
watch(query, () => {clearTimeout(queryTimer);queryTimer=setTimeout(()=>load(1),250);});
watch(status, () => load(1));
async function reveal(id:string) {
  const entity=props.section;
  const sequence=listSequence;
  try {
    let record=records.value.find(item=>item.id===id);
    if(!record){record=(await request(`/data/${entity}/${id}`,'GET')).data;if(entity!==props.section || sequence!==listSequence)return;records.value=[record,...records.value];}
    expanded.value=id;
    await nextTick();document.getElementById(`organize-${id}`)?.scrollIntoView({block:'center',behavior:'smooth'});
  }catch(caught){if(entity===props.section)error.value=failure(caught);}
}
onBeforeUnmount(()=>{clearTimeout(queryTimer);listSequence++;projectSequence++;});
</script>

<template>
  <section class="organize-module">
    <template v-if="section==='goals' && goal">
      <Link class="quiet-link back-link" href="/goals"><ArrowLeft :size="15"/> All goals</Link>
      <header class="module-header"><div><div class="eyebrow">{{horizons[goal.horizon] || 'YOUR DIRECTION'}}</div><h1>{{goal.title}}</h1><p>{{date(goal.target_date)}}</p></div><div class="record-actions"><button class="btn btn-secondary" @click="emit('edit','goals',goal)"><Pencil :size="15"/> Edit goal</button><button class="btn-icon" :disabled="!!busy" aria-label="Delete goal" @click="remove(goal)"><Trash2 :size="17"/></button></div></header>
      <div class="settings-grid goal-detail">
        <section class="card"><div class="card-header"><h2><Target :size="19"/> The bigger picture</h2><span class="badge" :class="`status-${goal.status}`">{{label(goal.status)}}</span></div><p class="note-body">{{goal.description || 'Add a description to give this goal a little more clarity.'}}</p><div v-if="goal.why_it_matters" class="review-reflection"><h3>Why it matters</h3><p class="note-body">{{goal.why_it_matters}}</p></div></section>
        <section class="card"><div class="card-header"><h2>Progress through projects</h2><strong>{{goal.progress===null?'—':`${goal.progress}%`}}</strong></div><div class="progress-bar" role="progressbar" :aria-valuenow="goal.progress ?? 0" aria-valuemin="0" aria-valuemax="100" aria-label="Goal progress"><span :style="{width:(goal.progress || 0)+'%'}"></span></div><p class="small-text">{{goal.project_count ? `Average completion of ${goal.project_count} linked project${goal.project_count===1?'':'s'}.` : 'Link a project to start tracking progress.'}}</p><button class="btn btn-secondary goal-add-project" @click="emit('edit','projects',undefined,{goal_id:goal.id})"><Plus :size="15"/> Add linked project</button></section>
      </div>
      <div class="module-toolbar"><h2>Linked projects</h2><span class="small-text">{{goal.project_count}} {{goal.project_count===1?'project':'projects'}}</span></div>
      <div v-if="!linkedProjects.length" class="empty-state"><FolderKanban :size="32"/><h3>Turn your direction into a next step.</h3><p>Create a project connected to this goal.</p><button class="btn btn-primary" @click="emit('edit','projects',undefined,{goal_id:goal.id})">Add a project</button></div>
      <div v-else class="record-grid"><article v-for="project in linkedProjects" :key="project.id" class="record-card"><span class="badge" :class="`status-${project.status}`">{{label(project.status)}}</span><h3><Link :href="`/projects/${project.id}`">{{project.name}}</Link></h3><p class="clamped-copy">{{project.description || 'Your next step toward this goal.'}}</p><div class="progress-bar"><span :style="{width:project.progress+'%'}"></span></div><div class="card-header"><small class="small-text">{{project.completed_count}} of {{project.task_count}} tasks</small><strong class="small-text">{{project.progress}}%</strong></div><Link class="quiet-link" :href="`/projects/${project.id}`">Open project <ArrowUpRight :size="14"/></Link></article></div>
      <div v-if="projectsLastPage>1" class="pagination"><button class="btn-icon" aria-label="Previous projects" :disabled="projectsLoading || projectsPage<=1" @click="loadProjects(projectsPage-1)"><ChevronLeft :size="18"/></button><span>Page {{projectsPage}} of {{projectsLastPage}}</span><button class="btn-icon" aria-label="Next projects" :disabled="projectsLoading || projectsPage>=projectsLastPage" @click="loadProjects(projectsPage+1)"><ChevronRight :size="18"/></button></div>
    </template>
    <template v-else>
      <header class="module-header"><div><div class="eyebrow">{{section==='habits'?'CONSISTENCY OVER PERFECTION':'MAKE ROOM FOR WHAT MATTERS'}}</div><h1>{{sectionTitle}}</h1><p>{{descriptions[section]}}</p></div><button class="btn btn-primary" @click="emit('edit',section)"><Plus :size="17"/> Add {{names[section]}}</button></header>
      <section v-if="section==='goals'" class="direction-card"><div class="card-header"><h2>Your north star</h2><Link class="quiet-link" href="/settings"><Pencil :size="14"/> Edit direction</Link></div><p class="note-body">{{settings.vision_text || 'What kind of life are you working toward? Set a vision to keep your goals connected.'}}</p><div v-if="settings.current_season_text || settings.current_intention_text" class="direction-details"><div v-if="settings.current_season_text"><small>THIS SEASON</small><p>{{settings.current_season_text}}</p></div><div v-if="settings.current_intention_text"><small>CURRENT INTENTION</small><p>{{settings.current_intention_text}}</p></div></div></section>
      <div class="module-toolbar"><div class="filters organize-filters"><label class="search-field"><Search :size="16"/><input v-model="query" type="search" :placeholder="`Search ${section}...`" :aria-label="`Search ${section}`" maxlength="200"/></label><select v-if="statuses[section]" v-model="status" aria-label="Filter by status"><option value="">All statuses</option><option v-for="option in statuses[section]" :key="option" :value="option">{{label(option)}}</option></select></div><span class="small-text" role="status">{{loading?'Updating...':`${total} ${total===1?names[section]:section}`}}</span></div>
      <p v-if="section==='habits'" class="small-text week-caption">Your current week · Check off scheduled days through today. Completion rate covers the last 30 days.</p>
      <div v-if="!records.length && !loading" class="empty-state"><Leaf v-if="section==='habits'" :size="34"/><Target v-else-if="section==='goals'" :size="34"/><Lightbulb v-else :size="34"/><h3>{{query || status?'Nothing matches just yet.':section==='habits'?'Make a small promise to yourself.':section==='ideas'?'Every possibility starts somewhere.':section==='notes'?'A clear place for your thoughts.':'Give your next chapter a direction.'}}</h3><p>{{query || status?'Try another search or status filter.':`Add your first ${names[section]} and take it one step at a time.`}}</p><button v-if="!query && !status" class="btn btn-secondary" @click="emit('edit',section)">Add {{names[section]}}</button></div>
      <div v-else class="record-grid" :aria-busy="loading">
        <article v-for="item in records" :id="`organize-${item.id}`" :key="item.id" class="record-card" :class="{'expanded-record':expanded===item.id}">
          <template v-if="section==='goals'"><div class="card-header"><Target :size="19"/><span class="badge" :class="`status-${item.status}`">{{label(item.status)}}</span></div><h2><Link :href="`/goals/${item.id}`">{{item.title}}</Link></h2><small class="small-text">{{horizons[item.horizon]}}<template v-if="item.target_date"> · {{date(item.target_date)}}</template></small><p class="clamped-copy">{{item.description || item.why_it_matters || 'Make room for what matters.'}}</p><div class="progress-bar"><span :style="{width:(item.progress || 0)+'%'}"></span></div><div class="card-header"><span class="small-text">{{item.project_count ? `${item.project_count} linked project${item.project_count===1?'':'s'}`:'No projects linked yet'}}</span><strong class="small-text">{{item.progress===null?'—':`${item.progress}%`}}</strong></div><div class="record-actions"><Link class="btn btn-secondary" :href="`/goals/${item.id}`">View goal <ArrowUpRight :size="14"/></Link><button class="btn-icon" :aria-label="`Edit ${item.title}`" @click="emit('edit','goals',item)"><Pencil :size="15"/></button><button class="btn-icon" :disabled="!!busy" :aria-label="`Delete ${item.title}`" @click="remove(item)"><Trash2 :size="15"/></button></div></template>
          <template v-else-if="section==='ideas'"><div class="card-header"><Lightbulb :size="19"/><span class="badge" :class="`status-${item.status}`">{{label(item.status)}}</span></div><h2>{{item.title}}</h2><p :class="{'clamped-copy':expanded!==item.id}">{{item.body || 'A thought with room to grow.'}}</p><button v-if="hasMore(item.body)" class="quiet-link" @click="expanded=expanded===item.id?null:item.id">{{expanded===item.id?'Show less':'Read more'}}</button><small v-if="item.source_context" class="small-text">Source: {{item.source_context}}</small><div class="record-actions"><Link v-if="item.converted_id" class="btn btn-secondary" :href="recordUrl(item.converted_type,item.converted_id)">Open {{item.converted_type}} <ArrowUpRight :size="14"/></Link><button v-else class="btn btn-secondary" :disabled="!!busy" @click="openConversion(item)">Turn into... <ArrowUpRight :size="14"/></button><button class="btn-icon" :aria-label="`Edit ${item.title}`" @click="emit('edit','ideas',item)"><Pencil :size="15"/></button><button class="btn-icon" :disabled="!!busy" :aria-label="`Delete ${item.title}`" @click="remove(item)"><Trash2 :size="15"/></button></div></template>
          <template v-else-if="section==='notes'"><div class="card-header"><span class="small-text">{{date(item.updated_at)}}</span><button class="btn-icon" :class="{'pinned-note':item.pinned}" :disabled="!!busy" :aria-label="item.pinned?'Unpin note':'Pin note'" :aria-pressed="!!item.pinned" @click="patch(item,{pinned:!item.pinned})"><Pin :size="16"/></button></div><h2>{{item.title || 'Untitled note'}}</h2><p class="note-body" :class="{'clamped-copy':expanded!==item.id}">{{item.body || 'An open page, ready when you are.'}}</p><button v-if="hasMore(item.body)" class="quiet-link" @click="expanded=expanded===item.id?null:item.id">{{expanded===item.id?'Show less':'Read note'}}</button><Link v-if="relationship(item)" class="badge note-relation" :href="relationship(item)!.url">{{label(item.notable_type)}} · {{relationship(item)!.title}} <ArrowUpRight :size="12"/></Link><div class="record-actions"><button class="btn btn-secondary" @click="emit('edit','notes',item)"><Pencil :size="14"/> Edit note</button><button class="btn-icon" :disabled="!!busy" :aria-label="`Delete ${item.title || 'note'}`" @click="remove(item)"><Trash2 :size="15"/></button></div></template>
          <template v-else-if="section==='habits'"><div class="card-header"><Leaf :size="19"/><span class="badge" :class="item.active?'status-active':'status-paused'">{{item.active?'Active':'Paused'}}</span></div><h2>{{item.name}}</h2><p v-if="item.description" class="clamped-copy">{{item.description}}</p><span class="small-text">{{item.frequency_type==='daily'?'Every day':'Selected weekdays'}}</span><div class="habit-grid"><div v-for="day in item.week" :key="day.date" class="habit-day" :class="{today:day.date===today,completed:day.completed}"><abbr :title="date(day.date)">{{weekday(day.date).slice(0,1)}}</abbr><button :disabled="!!busy || !item.active || !day.scheduled || day.date>today" :aria-label="`${item.name}, ${date(day.date)}: ${day.completed?'completed':!day.scheduled?'not scheduled':day.date>today?'upcoming':'not completed'}`" :aria-pressed="!!day.completed" :title="`${date(day.date)}${!day.scheduled?' · Not scheduled':day.date>today?' · Upcoming':''}`" @click="toggle(item,day)"><Check v-if="day.completed" :size="15"/><span v-else>{{day.scheduled?'': '–'}}</span></button><small>{{Number(day.date.slice(8))}}</small></div></div><div class="habit-stats"><span><Flame :size="15"/> {{item.streak}} day streak</span><span>{{item.rate}}% completion</span></div><Link v-if="item.goal_id && goalName(item.goal_id)" class="badge note-relation" :href="`/goals/${item.goal_id}`"><Target :size="12"/> {{goalName(item.goal_id)}}</Link><div class="record-actions"><button class="btn btn-secondary" @click="emit('edit','habits',item)"><Pencil :size="14"/> Edit habit</button><button class="btn btn-secondary" :disabled="!!busy" @click="patch(item,{active:!item.active})">{{item.active?'Pause':'Activate'}}</button><button class="btn-icon" :disabled="!!busy" :aria-label="`Delete ${item.name}`" @click="remove(item)"><Trash2 :size="15"/></button></div></template>
        </article>
      </div>
      <div v-if="lastPage>1" class="pagination"><button class="btn-icon" aria-label="Previous page" :disabled="loading || page<=1" @click="load(page-1)"><ChevronLeft :size="18"/></button><span>Page {{page}} of {{lastPage}}</span><button class="btn-icon" aria-label="Next page" :disabled="loading || page>=lastPage" @click="load(page+1)"><ChevronRight :size="18"/></button></div>
    </template>
    <p v-if="error" class="error-banner" role="alert">{{error}}</p>
    <p v-if="success" class="success-banner" role="status">{{success}}</p>
    <dialog ref="conversionDialog" class="form-dialog" aria-labelledby="conversion-title" @cancel.prevent="closeConversion" @click="($event.target===conversionDialog)&&closeConversion()">
      <form @submit.prevent="convert"><header class="dialog-header"><div><div class="eyebrow">GIVE YOUR IDEA A NEXT STEP</div><h2 id="conversion-title">Turn your idea into action</h2></div><button type="button" class="btn-icon" :disabled="!!busy" aria-label="Close conversion" @click="closeConversion"><X :size="20"/></button></header><div class="dialog-body"><p class="form-help conversion-intro">Your idea stays here with a link to its new home. Review the details before creating it.</p><label class="form-field"><span>Create as</span><select v-model="conversion.type" :disabled="!!busy" autofocus><option value="task">Task</option><option value="project">Project</option><option value="content">Content item</option><option value="note">Note</option></select></label><label class="form-field"><span>{{conversion.type==='project'?'Project name':'Title'}}</span><input v-model="conversion.title" required maxlength="255" :disabled="!!busy"/></label><label class="form-field"><span>{{conversion.type==='note'?'Note body':'Description'}}</span><textarea v-model="conversion.body" rows="7" maxlength="50000" :disabled="!!busy"></textarea></label><p v-if="conversionError" class="error-banner" role="alert">{{conversionError}}</p></div><footer class="dialog-footer"><button type="button" class="btn btn-secondary" :disabled="!!busy" @click="closeConversion">Cancel</button><button type="submit" class="btn btn-primary" :disabled="!!busy">{{busy?'Creating...':`Create ${conversion.type==='content'?'content item':conversion.type}`}} <ArrowUpRight :size="15"/></button></footer></form>
    </dialog>
  </section>
</template>

<style scoped>
.back-link{display:inline-flex;align-items:center;gap:7px;margin-bottom:19px}.goal-detail{margin-bottom:28px}.goal-add-project{margin-top:22px}.organize-filters{margin-bottom:0}.search-field{display:flex;align-items:center;gap:7px;color:#8993a1}.direction-details{display:grid;grid-template-columns:1fr 1fr;gap:24px;margin-top:19px}.direction-details small{font-size:.64rem;letter-spacing:.12em;color:#927b69}.clamped-copy{display:-webkit-box;-webkit-line-clamp:4;-webkit-box-orient:vertical;overflow:hidden}.expanded-record{border-color:#cba997}.pinned-note{color:#b87f60;background:#faf0e7}.note-relation{margin-top:14px;max-width:100%;white-space:normal;overflow-wrap:anywhere}.habit-stats{display:flex;justify-content:space-between;gap:10px;font-size:.72rem;color:#718074}.habit-stats>span{display:flex;align-items:center;gap:5px}.habit-day button:disabled{opacity:.55;cursor:default}.habit-day button:disabled:not([aria-pressed="true"]){background:#f5f6f8}.habit-day abbr{text-decoration:none}.habit-day.today abbr{font-weight:700}.week-caption{margin:-4px 0 21px}.conversion-intro{margin:0 0 20px}.record-card h2,.record-card h3,.module-header h1{overflow-wrap:anywhere}.record-card h2 a:hover{color:var(--accent)}.organize-module>.error-banner,.organize-module>.success-banner{margin-top:18px}.record-card>.small-text{display:block}.record-card .quiet-link{display:inline-flex;gap:5px;align-items:center}.direction-card .card-header h2{margin:0}
@media(max-width:767px){.direction-details{grid-template-columns:1fr;gap:14px}.organize-filters{width:100%}.search-field{flex:1;min-width:0}.search-field input{width:100%;max-width:none;min-width:0}.habit-day button{width:34px;height:34px}.habit-stats{font-size:.8rem}.goal-detail{gap:15px}}
</style>
