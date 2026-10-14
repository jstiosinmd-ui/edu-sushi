<script setup>
import {ref,watch} from 'vue';
import {Link,router} from '@inertiajs/vue3';
import axios from 'axios';
import SubjectPicker from './SubjectPicker.vue';
import {message} from '../art';
const props=defineProps({subjects:Array,initial:Object});
const minutes=ref(props.initial.minutes),chosen=ref([...props.initial.subjects]),plan=ref(props.initial),busy=ref(false),error=ref('');
let generation=0;
watch([minutes,chosen],async()=>{const request=++generation;busy.value=true;error.value='';try{const r=await axios.post('/today-bento/preview',{minutes:minutes.value,subjects:chosen.value});if(request===generation)plan.value=r.data}catch(e){if(request===generation){plan.value=null;error.value=message(e)}}finally{if(request===generation)busy.value=false}});
function start(){busy.value=true;router.post('/today-bento/start',{minutes:minutes.value,subjects:chosen.value},{onFinish:()=>busy.value=false})}
</script>
<template><section class="panel today-bento"><div class="row"><div><p class="eyebrow">A plan for your next study break</p><h2>Today's Bento</h2></div><div class="choice-buttons" aria-label="Available study time"><button v-for="n in [5,15,30]" :key="n" :aria-pressed="minutes===n" :class="{selected:minutes===n}" @click="minutes=n">{{n}} min</button></div></div>
<details><summary>Choose subjects · {{chosen.length}} selected</summary><SubjectPicker :subjects="subjects" v-model="chosen"/></details>
<p v-if="error" role="alert">{{error}}</p><div v-if="plan" :aria-busy="busy"><h3>{{plan.count}} questions with Maki Mentor</h3><p>{{plan.reason}}</p><p class="muted">{{Object.entries(plan.coverage).map(([s,n])=>s+' · '+n).join(' / ')}}</p><p class="muted">About {{minutes}} minutes at your own pace. This practice round does not award a Fresh Catch stamp.</p><button class="primary" :disabled="busy||!plan.count" @click="start">{{busy?'Updating…':'Start my bento'}}</button><p v-if="plan.resume"><Link :href="'/flashcards/decks/'+plan.resume.id">Continue {{plan.resume.title}} instead →</Link></p></div></section></template>
