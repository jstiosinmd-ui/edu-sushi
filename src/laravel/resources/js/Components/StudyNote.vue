<script setup>
import {ref,watch} from 'vue';
import axios from 'axios';
import {message} from '../art';
const props=defineProps({questionId:String}),open=ref(false),note=ref(''),reason=ref(''),revision=ref(0),busy=ref(false),status=ref('');
watch(()=>props.questionId,()=>{open.value=false;status.value=''});
async function load(){busy.value=true;status.value='';try{const r=await axios.get('/questions/'+props.questionId+'/note');note.value=r.data.note;reason.value=r.data.reason;revision.value=r.data.revision;open.value=true}catch(e){status.value=message(e)}finally{busy.value=false}}
async function save(){busy.value=true;status.value='';try{const r=await axios.post('/questions/'+props.questionId+'/note',{note:note.value,reason:reason.value||null,revision:revision.value});revision.value=r.data.revision;status.value='Saved to your private Second Serving notebook.'}catch(e){status.value=message(e)}finally{busy.value=false}}
</script>
<template><div class="study-note"><button v-if="!open" :disabled="busy" @click="load">Add a private study note</button><form v-else @submit.prevent="save"><h4>Second Serving · private note</h4><label>What happened?<select v-model="reason"><option value="">Choose a reason (optional)</option><option value="unknown">I didn't know this</option><option value="confused">I confused two concepts</option><option value="misread">I misread the question</option><option value="guessed">I guessed</option></select></label><label>What will help you remember?<textarea v-model="note" maxlength="2000" rows="3" placeholder="Write your own takeaway…"></textarea></label><button :disabled="busy" class="primary">Save note</button><button type="button" @click="open=false">Close</button></form><p role="status">{{status}}</p></div></template>
