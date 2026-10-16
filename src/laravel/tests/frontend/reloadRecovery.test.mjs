import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import path from 'node:path';
import {parse,compileScript} from '@vue/compiler-sfc';
import {build} from 'esbuild';
import {createRenderer,h,nextTick} from 'vue';

const renderer=createRenderer({
    createElement:()=>({}),createText:()=>({}),createComment:()=>({}),
    setText(){},setElementText(){},parentNode:()=>null,nextSibling:()=>null,
    insert(){},remove(){},patchProp(){},
});
const mocks={
    '@inertiajs/vue3':"export const Head={},Link={},router={reload(){globalThis.__sushiReload.reloads++}};export function usePage(){return {props:{user:{id:1}}}}",
    'axios':"export default {post(){return Promise.resolve({data:{}})}}",
    'art':"export const crew={},modes={},art={};export const message=()=>'';",
    'useStudyDelivery':"import {ref} from 'vue';export function useStudyDelivery(){return {pending:ref(null),busy:ref(false),error:ref(''),status:ref(''),send(action,payload){globalThis.__sushiReload.sent.push({action,payload})},retry(){},discard(){globalThis.__sushiReload.discards++}}}",
    'studyDelivery':"export function answerKey(){return null}",
};

// Compile the real script-setup components. Only network, browser, and child UI
// dependencies are stubbed; refs, computed values, watchers, and lifecycle run.
async function component(name){
    const root=path.resolve('resources/js/Pages');
    const {descriptor}=parse(await fs.readFile(path.join(root,name+'.vue'),'utf8'));
    const script=compileScript(descriptor,{id:'reload-'+name});
    const output=await build({
        stdin:{contents:script.content,resolveDir:root,loader:'js'},
        bundle:true,format:'esm',platform:'node',write:false,
        plugins:[{name:'test-boundaries',setup(b){
            b.onResolve({filter:/.*/},args=>{
                if(args.path==='vue')return {path:import.meta.resolve('vue'),external:true};
                const key=Object.keys(mocks).find(k=>args.path===k||args.path.endsWith('/'+k));
                if(key)return {path:key,namespace:'mock'};
                if(args.path.endsWith('.vue'))return {path:'child',namespace:'mock'};
            });
            b.onLoad({filter:/.*/,namespace:'mock'},args=>({contents:mocks[args.path]||'export default {};',loader:'js'}));
        }}],
    });
    const result=(await import('data:text/javascript;base64,'+Buffer.from(output.outputFiles[0].text).toString('base64'))).default;
    result.render=()=>null;
    return result;
}
async function mount(t,name,prop,value){
    globalThis.window={addEventListener(){},removeEventListener(){},scrollTo(){}};
    globalThis.__sushiReload={sent:[],reloads:0,discards:0};
    const page=await component(name),container={};
    const render=value=>renderer.render(h(page,{[prop]:value,bookmarks:[]}),container);
    render(value);await nextTick();
    t.after(()=>{renderer.render(null,container);delete globalThis.window;delete globalThis.__sushiReload});
    return {state:container._vnode.component.setupState,update:async value=>{render(value);await nextTick()}};
}
const attempt=extra=>({id:1,revision:0,mode:'tutor',server_now:'2026-10-16T00:00:00Z',
    status:'active',deadline:null,items:[{item_id:11,checked:false},{item_id:12,checked:false}],...extra});

test('attempt recovery adopts server answers, revision and clock while retaining the visible question',async t=>{
    const {state,update}=await mount(t,'Attempt','attempt',attempt());
    state.index=1;
    state.reload();
    assert.equal(globalThis.__sushiReload.discards,1);
    assert.equal(globalThis.__sushiReload.reloads,1);
    await update(attempt({revision:7,mode:'exam',server_now:'2026-10-16T00:10:00Z',deadline:'2026-10-16T00:12:00Z',
        items:[{item_id:12,answer:'C',checked:false},{item_id:11,checked:true}]}));
    assert.equal(state.data.revision,7);
    assert.equal(state.index,0);
    assert.equal(state.q.item_id,12);
    assert.equal(state.q.answer,'C');
    assert.equal(state.remaining,120);
    state.save('A');
    assert.equal(globalThis.__sushiReload.sent[0].payload.revision,7);
    assert.equal(globalThis.__sushiReload.sent[0].payload.item_id,12);
});
test('attempt recovery shows completion and resets navigation when the previous item no longer exists',async t=>{
    const {state,update}=await mount(t,'Attempt','attempt',attempt());
    state.index=1;
    await update(attempt({revision:9,status:'finished',score:1,items:[{item_id:31,checked:true,answer:'A'}]}));
    assert.equal(state.ended,true);
    assert.equal(state.data.score,1);
    assert.equal(state.index,0);
    state.save('B');
    assert.equal(globalThis.__sushiReload.sent.length,0);
});
test('Memory Maki recovery adopts the current presentation token and clears stale feedback',async t=>{
    const old={id:2,reviews:0,goal_prompt:false,finished:false,session_number:1,question:{id:'OLD',token:'old',answer:null}};
    const {state,update}=await mount(t,'FlashcardDeck','deck',old);
    state.sessionError='Old conflict';state.saved='Old confirmation';
    state.reload();
    assert.equal(globalThis.__sushiReload.discards,1);
    assert.equal(globalThis.__sushiReload.reloads,1);
    await update({...old,reviews:7,counts:[0,1,0,0,0,1],question:{id:'NEW',token:'current',answer:null}});
    assert.equal(state.data.reviews,7);
    assert.deepEqual(state.data.counts,[0,1,0,0,0,1]);
    assert.equal(state.sessionError,'');
    assert.equal(state.saved,'');
    await state.answer('B');
    assert.deepEqual(globalThis.__sushiReload.sent[0],{action:'answer',payload:{question_id:'NEW',answer:'B',token:'current'}});
});
test('Memory Maki recovery adopts a finished session and its summary',async t=>{
    const {state,update}=await mount(t,'FlashcardDeck','deck',{id:2,reviews:0,goal_prompt:false,finished:false});
    await update({id:2,reviews:10,goal_prompt:false,finished:true,session_number:2,session_reviews:4,question:null});
    assert.equal(state.data.finished,true);
    assert.equal(state.data.session_number,2);
    assert.equal(state.data.session_reviews,4);
    assert.equal(state.q,null);
});

