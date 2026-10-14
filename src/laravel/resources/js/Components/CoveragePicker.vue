<script setup>
import {ref,computed,watch} from 'vue';import SubjectPicker from './SubjectPicker.vue';
const props=defineProps({form:Object,catalog:Object,counts:Object});const search=ref('');
const custom=ref(props.form.topics.length>0||props.form.topic_scope==='custom');
const topics=computed(()=>props.catalog.topics.filter(t=>props.form.subjects.includes(t.subject)));
const visible=computed(()=>topics.value.filter(t=>t.topic.toLowerCase().includes(search.value.toLowerCase())));
const id=t=>t.subject+'\u001f'+t.topic;
function scope(value){custom.value=value;props.form.topic_scope=value?'custom':'all';props.form.topics=value?topics.value.map(id):[]}
function toggle(t){const key=id(t);props.form.topics=props.form.topics.includes(key)?props.form.topics.filter(x=>x!==key):[...props.form.topics,key]}
watch(()=>props.form.subjects.slice(),()=>{props.form.topics=props.form.topics.filter(t=>props.form.subjects.includes(t.split('\u001f')[0]))});
</script>
<template><SubjectPicker :subjects="catalog.subjects" v-model="form.subjects"/><div class="topic-picker"><h3>Topics on the menu</h3><div class="segmented"><button type="button" :class="{selected:!custom}" :aria-pressed="!custom" @click="scope(false)">All topics</button><button type="button" :class="{selected:custom}" :aria-pressed="custom" @click="scope(true)">Choose topics</button></div>
<template v-if="custom"><label class="topic-search">Search topics<input v-model="search" placeholder="Find a topic…"></label><div class="row"><small>{{form.topics.length}} topics selected</small><div class="segmented"><button type="button" @click="form.topics=topics.map(id)">Select all</button><button type="button" @click="form.topics=[]">Clear</button></div></div>
<div class="topic-results"><section v-for="subject in form.subjects" :key="subject"><template v-if="visible.some(t=>t.subject===subject)"><h4>{{subject}}</h4><button v-for="t in visible.filter(t=>t.subject===subject)" :key="id(t)" type="button" class="topic-option" :aria-pressed="form.topics.includes(id(t))" :class="{selected:form.topics.includes(id(t))}" @click="toggle(t)"><span>{{form.topics.includes(id(t))?'✓':'＋'}} {{t.topic}}</span><small>{{counts?.[id(t)]??t.count??''}} items</small></button></template></section><p v-if="!visible.length">No topics match this search.</p></div>
<div class="chips"><button v-for="key in form.topics.slice(0,6)" :key="key" type="button" @click="form.topics=form.topics.filter(t=>t!==key)">{{key.split('\u001f')[1]}} ×</button><small v-if="form.topics.length>6">+ {{form.topics.length-6}} more selected</small></div><p v-if="!form.topics.length" class="error">Choose at least one topic, or use All topics.</p></template></div></template>
