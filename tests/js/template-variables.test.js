// Run: node tests/js/template-variables.test.js (fake DOM, no browser needed)
const assert=require('assert');
class El{constructor(t){this.tagName=t;this.children=[];this.attrs={};this.listeners={};this.className='';this.value='';this.textContent='';this.maxLength=-1;this.id='';}
 appendChild(c){this.children.push(c);return c}setAttribute(k,v){this.attrs[k]=v}getAttribute(k){return this.attrs[k]===undefined?null:this.attrs[k]}
 addEventListener(e,f){(this.listeners[e]=this.listeners[e]||[]).push(f)}dispatchEvent(ev){(this.listeners[ev.type]||[]).forEach(f=>f(ev));return true}
 focus(){}setSelectionRange(a,b){this.selectionStart=a;this.selectionEnd=b}
 find(fn,acc=[]){this.children.forEach(c=>{if(fn(c))acc.push(c);c.find(fn,acc)});return acc}}
global.Event=class{constructor(t){this.type=t}};
global.document={readyState:'complete',body:{},documentElement:{},createElement:t=>new El(t),getElementById:()=>null,querySelectorAll:()=>[],addEventListener(){}};
global.window={requestAnimationFrame:f=>f(),publishrankVariables:{
 groups:[{id:'site',label:'Site'},{id:'content',label:'Content'},{id:'taxonomy',label:'Taxonomy'}],
 variables:[
 {id:'sitename',token:'%%sitename%%',label:'Site name',description:'',fallback:'Empty.',group:'site',contexts:['post','home','archive'],available:true,reason:''},
 {id:'sep',token:'%%sep%%',label:'Sep',description:'',fallback:'x',group:'site',contexts:['post'],available:true,reason:''},
 {id:'title',token:'%%title%%',label:'Post title',description:'',fallback:'',group:'content',contexts:['post','home'],available:true,reason:''},
 {id:'category',token:'%%category%%',label:'Category',description:'',fallback:'',group:'taxonomy',contexts:['post'],available:false,reason:'Not available: no category'}],
 i18n:{placeholder:'Insert variable…',label:'Insert',inserted:'Inserted %s',tooLong:'too long',fallback:'If empty:',empty:'none'}}};
global.window.document=global.document;
require(require('path').join(__dirname,'../../assets/js/template-variables.js'));
const P=window.PublishRankVariables; assert(P);
// insertion at cursor, preserving text
let inp=new El('input');inp.value='Hello World';inp.__prSel={s:5,e:5};inp.maxLength=-1;
assert(P.insertToken(inp,'%%title%%')); assert.strictEqual(inp.value,'Hello%%title%% World'); assert.strictEqual(inp.selectionStart,14);
// replace selection
inp.value='abcdef';inp.__prSel={s:1,e:3};P.insertToken(inp,'%%x%%');assert.strictEqual(inp.value,'a%%x%%def');
// multiple tokens in a row
inp.value='';inp.__prSel=undefined;P.insertToken(inp,'%%a%%');P.insertToken(inp,'%%b%%');assert.strictEqual(inp.value,'%%a%%%%b%%');
// maxLength refuses
inp.value='12345';inp.maxLength=8;inp.__prSel={s:5,e:5};assert.strictEqual(P.insertToken(inp,'%%title%%'),false);assert.strictEqual(inp.value,'12345');
// event dispatched
let fired=0;inp.maxLength=-1;inp.addEventListener('input',()=>fired++);P.insertToken(inp,'z');assert.strictEqual(fired,1);
// mount: groups, filtering, disabled option, description excludes sep, no double mount
function mountFor(field,ctx){const slot=new El('span');const i=new El('input');i.value='Hi';i.maxLength=-1;P.mount(slot,i,field,ctx);return{slot,i}}
let m=mountFor('title','post');
let opts=m.slot.find(c=>c.tagName==='option');
assert(opts.some(o=>o.value==='%%sep%%'),'sep in title');
const cat=opts.find(o=>o.attrs['data-id']==='category');assert(cat.disabled&&/Not available/.test(cat.textContent));
assert.strictEqual(m.slot.find(c=>c.tagName==='optgroup').length,3);
let d=mountFor('description','post');assert(!d.slot.find(c=>c.tagName==='option').some(o=>o.value==='%%sep%%'),'sep hidden in description');
let h=mountFor('title','home');assert(!h.slot.find(c=>c.tagName==='option').some(o=>o.value==='%%category%%'),'category hidden for home');
assert.strictEqual(P.mount(m.slot,m.i),false,'no duplicate mount');
// select change inserts and resets
const sel=m.slot.find(c=>c.tagName==='select')[0];m.i.__prSel={s:2,e:2};sel.value='%%sitename%%';sel.dispatchEvent(new Event('change'));
assert.strictEqual(m.i.value,'Hi%%sitename%%');assert.strictEqual(sel.value,'');
const help=m.slot.find(c=>/pr-var-help/.test(c.className))[0];assert(/Inserted %%sitename%%/.test(help.textContent),help.textContent);
console.log('JS picker tests passed');
