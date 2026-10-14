<script setup>
import {watch} from 'vue';import {useForm,Head,usePage} from '@inertiajs/vue3';import RoundBuilder from '../Components/RoundBuilder.vue';import SubjectPicker from '../Components/SubjectPicker.vue';import Errors from '../Components/Errors.vue';import {art,modes} from '../art';
const props=defineProps({catalog:Object,freshSubjects:Array}),page=usePage();
const mode=()=>new URL(page.url,'http://local').searchParams.get('mode')||'fresh';
const form=useForm({mode:['fresh','tutor','exam'].includes(mode())?mode():'fresh',count:20,subjects:[...(props.freshSubjects||props.catalog.subjects)],topics:[],topic_scope:'all',difficulty:'All',pool:'all',seed:Math.floor(Math.random()*4294967296),balance:false,minutes:30});
watch(()=>page.url,()=>{if(['fresh','tutor','exam'].includes(mode()))form.mode=mode()});
function start(){form.post('/attempts')}
</script>
<template><Head title="Practice"/><div class="page-title"><div><p class="eyebrow">Choose your round</p><h1>{{modes[form.mode]}}</h1><p>{{form.mode==='exam'?'A timed challenge, with feedback at the finish.':form.mode==='tutor'?'A guided round, with feedback after every answer.':'Ten new or previously missed questions to keep you moving.'}}</p></div><img class="portrait" :src="art[form.mode==='fresh'?'temaki':form.mode==='exam'?'wasabi':'maki']" alt="Practice sushi character"></div>
<div class="panel"><Errors :errors="form.errors"/><template v-if="form.mode==='fresh'"><SubjectPicker :subjects="catalog.subjects" v-model="form.subjects"/><button class="primary" :disabled="!form.subjects.length||form.processing" @click="start">Start Fresh Catch</button></template><RoundBuilder v-else :key="form.mode" :form="form" :catalog="catalog" :busy="form.processing" @start="start"/></div></template>
