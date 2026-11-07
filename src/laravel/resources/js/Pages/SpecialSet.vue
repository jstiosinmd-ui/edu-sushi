<script setup>
import {computed,ref} from 'vue';
import {Head,Link} from '@inertiajs/vue3';
import axios from 'axios';
const props=defineProps({set:Object,questions:Array,saved:Object,summary:Object});
const feedback=ref({...props.saved}),summary=ref({...props.summary});
const position=ref(Math.max(0,props.questions.findIndex(q=>!feedback.value[q.number])));
const question=computed(()=>props.questions[position.value]);
const result=computed(()=>feedback.value[question.value.number]);
const busy=ref(false),error=ref('');
async function answer(letter=null){
 if(busy.value||result.value)return;
 const number=question.value.number;busy.value=true;error.value='';
 try{const {data}=await axios.post('/special-sets/'+props.set.slug+'/answers',{number,answer:letter,hash:props.set.hash});feedback.value[number]=data.feedback;summary.value=data.summary;}
 catch(e){error.value=e.response?.status===409?'This set has changed. Reload the page to continue.':'Your answer was not saved. Check your connection and try again.';}
 finally{busy.value=false;}
}
function move(value){if(!busy.value){position.value=value;error.value='';}}
</script>
<template>
<Head :title="set.title"/>
<header class="page-heading"><div><Link href="/special-sets">← Special sets</Link><h1>{{set.title}}</h1></div><div aria-live="polite">{{summary.completed}} / {{summary.total}} completed<br><strong>{{summary.correct}} / {{summary.scored}} correct</strong></div></header>
<div class="special-toolbar panel"><label>Question <select :value="position" :disabled="busy" @change="move(Number($event.target.value))"><option v-for="(q,index) in questions" :value="index" :key="q.id">{{q.number}}{{feedback[q.number]?' · completed':''}}{{q.review_only?' · review only':''}}</option></select></label><span>{{summary.total-summary.scorable}} items are for review only</span></div>
<article class="panel special-question" :aria-busy="busy">
<p class="muted">Question {{question.number}} of {{questions.length}} · Source page {{question.page}}</p>
<p v-if="question.review_only" class="special-review"><strong>Review only.</strong> This item needs clarification and will not affect your score.</p>
<h2>{{question.stem}}</h2>
<img v-if="question.image" :src="'/special-set-assets/'+question.image" alt="Original 12-lead ECG for estimating heart rate" class="special-figure">
<div class="special-options"><button v-for="(text,letter) in question.options" :key="question.id+letter" :disabled="busy||!!result||question.review_only" :class="{chosen:result?.answer===letter,correct:result?.key===letter}" @click="answer(letter)"><strong>{{letter}}.</strong> {{text}}<span v-if="result?.answer===letter"> · Your answer</span><span v-if="result?.key===letter"> · Correct answer</span></button></div>
<button v-if="question.review_only&&!result" class="primary" :disabled="busy" @click="answer()">{{busy?'Saving…':'Read rationale'}}</button>
<p v-if="error" role="alert">{{error}}</p>
<section v-if="result" class="special-feedback" aria-live="polite"><h3>{{result.review_only?'Explanation and review note':result.correct?'Correct':'Answer: '+result.key}}</h3><p>{{result.rationale}}</p><ul v-if="result.references.length"><li v-for="source in result.references" :key="source.url"><a :href="source.url" target="_blank" rel="noopener noreferrer">{{source.title}}</a></li></ul><p class="muted">Progress saved.</p></section>
<nav class="special-toolbar" aria-label="Question navigation"><button :disabled="position===0||busy" @click="move(position-1)">Previous</button><span>{{position+1}} / {{questions.length}}</span><button :disabled="position===questions.length-1||busy" @click="move(position+1)">Next</button></nav>
</article>
<p v-if="summary.completed===summary.total" class="panel" role="status">Set complete. {{summary.correct}} of {{summary.scored}} scored answers were correct; {{summary.reviewed}} review-only explanations were read.</p>
<details class="panel"><summary>Source and rationale notes</summary><p>{{set.source_file}}</p><p>{{set.note}}</p></details>
</template>
<style scoped>
.special-toolbar{display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap}.special-question{max-width:960px;margin-inline:auto}.special-question h2{font-size:1.2rem;line-height:1.65;white-space:pre-line}.special-options{display:grid;gap:.7rem;margin:1.3rem 0}.special-options button{text-align:left;white-space:normal;line-height:1.6;padding:1rem;opacity:1}.special-options .chosen{border:2px solid #9d3656}.special-options .correct{border:2px solid #267454;background:#edf7f0;color:#173f2c}.special-options button:disabled{cursor:default}.special-feedback{margin:1rem 0;padding:1.2rem;background:#f4f0e8;border-radius:12px;color:#272520}.special-feedback p{line-height:1.75}.special-review{padding:.8rem;background:#fff3d8;color:#594414;border-radius:8px}.special-figure{display:block;max-width:100%;height:auto;margin:1rem auto}.special-feedback a{overflow-wrap:anywhere}
</style>
