export type FieldType = 'text' | 'textarea' | 'select' | 'date' | 'datetime-local' | 'number' | 'money' | 'checkbox' | 'weekdays' | 'url' | 'currency';
export interface FieldOption { value:string | number; label:string }
export interface FieldSchema {
  key:string; label:string; type:FieldType; required?:boolean; wide?:boolean; options?:FieldOption[];
  lookup?:string; emptyLabel?:string; placeholder?:string; help?:string; min?:number; max?:number; maxlength?:number;
  when?:{key:string;value:unknown};
}
export interface RecordSchema { label:string; plural:string; fields:FieldSchema[]; defaults:Record<string,unknown> }
const labels:Record<string,string> = {todo:'To do',in_progress:'In progress',ewallet:'E-wallet',current:'Current season',year:'This year',three_to_five_year:'3–5 years',someday:'Someday',weekdays:'Selected weekdays'};
export const humanize = (value:string) => labels[value] || value.replace(/_/g,' ').replace(/^\w/,letter=>letter.toUpperCase());
const options = (values:string[]) => values.map(value=>({value,label:humanize(value)}));
const text = (key:string,label:string,required=false):FieldSchema => ({key,label,type:'text',required,wide:true,maxlength:255});
const area = (key:string,label:string,placeholder=''):FieldSchema => ({key,label,type:'textarea',wide:true,maxlength:50000,placeholder});
const select = (key:string,label:string,values:string[]):FieldSchema => ({key,label,type:'select',options:options(values)});
const relation = (key:string,label:string,lookup:string,emptyLabel:string):FieldSchema => ({key,label,type:'select',lookup,emptyLabel});
const date = (key:string,label:string,required=false):FieldSchema => ({key,label,type:'date',required});
const checkbox = (key:string,label:string):FieldSchema => ({key,label,type:'checkbox'});
const currency:FieldSchema = {key:'currency',label:'Currency',type:'currency',required:true,maxlength:3,help:'Amounts keep this currency after saving.'};
const money = (key:string,label:string,required=false):FieldSchema => ({key,label,type:'money',required});
const statuses = {
  tasks:['backlog','todo','in_progress','done'],projects:['planned','active','paused','completed','archived'],goals:['active','achieved','paused','archived'],
  ideas:['inbox','exploring','planned','archived'],savings:['active','achieved','paused','archived'],wishlist:['considering','planned','purchased','cancelled'],
  content:['idea','planning','script','recording','editing','ready','published'],
};
export const schemas:Record<string,RecordSchema> = {
  tasks:{label:'task',plural:'Tasks',defaults:{status:'todo',priority:'normal',due_has_time:false},fields:[
    text('title','Title',true),area('description','Description'),relation('project_id','Project','projects','No project'),select('status','Status',statuses.tasks),
    select('priority','Priority',['low','normal','high','urgent']),date('planned_date','Planned date'),{key:'due_at',label:'Due date',type:'date'},checkbox('due_has_time','Include a due time'),
    {key:'estimated_minutes',label:'Estimated minutes',type:'number',min:1,max:1440},
  ]},
  projects:{label:'project',plural:'Projects',defaults:{status:'active',is_current_focus:false},fields:[
    text('name','Project name',true),area('description','Description'),area('brief','Project brief','What does success look like?'),select('status','Status',statuses.projects),
    relation('goal_id','Goal','goals','No linked goal'),date('start_date','Start date'),date('target_date','Target date'),{...checkbox('is_current_focus','Make this my current focus'),wide:true,help:'Only an active project can be your current focus.'},
  ]},
  goals:{label:'goal',plural:'Goals',defaults:{status:'active',horizon:'current'},fields:[
    text('title','Goal',true),area('description','Description'),area('why_it_matters','Why it matters'),select('horizon','Time horizon',['current','year','three_to_five_year','someday']),
    select('status','Status',statuses.goals),date('target_date','Target date'),
  ]},
  events:{label:'event',plural:'Events',defaults:{all_day:false},fields:[
    text('title','Event title',true),area('description','Description'),{...checkbox('all_day','All-day event'),wide:true},
    {key:'starts_at',label:'Starts',type:'datetime-local',required:true},{key:'ends_at',label:'Ends',type:'datetime-local',required:true},{...text('category','Category'),maxlength:80},
  ]},
  ideas:{label:'idea',plural:'Ideas',defaults:{status:'inbox'},fields:[
    text('title','Idea',true),area('body','Details','Capture the thought while it is fresh.'),select('status','Status',statuses.ideas),text('source_context','Source or context'),
  ]},
  notes:{label:'note',plural:'Notes',defaults:{pinned:false},fields:[
    text('title','Title (optional)'),{...area('body','Note','Write your note here. Simple Markdown is welcome.'),help:'Add a title, a note, or both.'},checkbox('pinned','Pin this note'),
    {...select('notable_type','Link to',['project','goal','content']),emptyLabel:'Standalone note'},relation('notable_id','Linked item','notable','Choose an item'),
  ]},
  habits:{label:'habit',plural:'Habits',defaults:{frequency_type:'daily',active:true,active_weekdays:[1,2,3,4,5]},fields:[
    text('name','Habit name',true),area('description','Description'),select('frequency_type','Frequency',['daily','weekdays']),date('start_date','Start date',true),
    {key:'active_weekdays',label:'Active weekdays',type:'weekdays',wide:true,when:{key:'frequency_type',value:'weekdays'}},
    relation('goal_id','Goal','goals','No linked goal'),checkbox('active','Active habit'),
  ]},
  accounts:{label:'account',plural:'Accounts',defaults:{type:'bank',opening_balance:'0',active:true},fields:[
    text('name','Account name',true),select('type','Account type',['cash','bank','ewallet','savings','other']),currency,money('opening_balance','Opening balance'),checkbox('active','Active account'),
  ]},
  categories:{label:'category',plural:'Categories',defaults:{type:'expense',active:true},fields:[
    text('name','Category name',true),select('type','Transaction type',['income','expense']),{...text('icon','Icon or emoji (optional)'),maxlength:50,wide:false},checkbox('active','Active category'),
  ]},
  transactions:{label:'transaction',plural:'Transactions',defaults:{type:'expense'},fields:[
    select('type','Transaction type',['income','expense']),{...relation('account_id','Account','accounts','Choose account'),required:true},
    money('amount','Amount',true),{...relation('category_id','Category','categories','Choose category'),required:true},date('transaction_date','Date',true),area('description','Description'),
  ]},
  savings:{label:'savings goal',plural:'Savings goals',defaults:{status:'active',current_amount:'0'},fields:[
    text('name','Savings goal',true),currency,select('status','Status',statuses.savings),money('target_amount','Target amount',true),money('current_amount','Current amount'),date('target_date','Target date'),
  ]},
  wishlist:{label:'wishlist item',plural:'Wishlist',defaults:{status:'considering',priority:'medium'},fields:[
    text('name','Item name',true),currency,money('estimated_price','Estimated price'),select('priority','Priority',['low','medium','high']),
    select('status','Status',statuses.wishlist),date('desired_date','Desired date'),area('notes','Notes'),
  ]},
  content:{label:'content item',plural:'Content',defaults:{status:'idea'},fields:[
    text('title','Content title',true),{...text('platform','Platform'),wide:false,maxlength:80,placeholder:'e.g. Instagram, YouTube'},select('status','Stage',statuses.content),
    area('description','Description'),area('hook','Hook','How will you catch attention?'),area('script_notes','Script notes'),area('b_roll_notes','B-roll notes'),
    date('planned_publish_date','Planned publish date'),relation('project_id','Project','projects','No linked project'),{key:'published_url',label:'Published URL',type:'url',wide:true,maxlength:2048,placeholder:'https://'},
  ]},
  reviews:{label:'weekly review',plural:'Weekly reviews',defaults:{},fields:[
    date('week_start','Week start',true),date('week_end','Week end',true),area('wins','Wins','What went well this week?'),area('challenges','Challenges'),area('lessons','Lessons'),
    area('improvements','What to improve'),area('next_week_focus','Next week’s focus'),area('notes','Notes'),
  ]},
};
