<script setup>
import {computed,ref,watch} from 'vue';
import {Link,useForm} from '@inertiajs/vue3';
import StudySheet from './StudySheet.vue';
const props=defineProps({catalog:Object,preferences:Object,resumable:{type:Array,default:()=>[]}});
const form=useForm({...props.preferences,subjects:[...props.preferences.subjects],topics:[...props.preferences.topics]});
const customize=ref(null);
const modes=[['practice','Practice'],['flashcards','Flashcards'],['exam','Exam']];
const availableSubjects=computed(()=>props.catalog.subjects.filter(s=>props.catalog.topics.some(t=>t.subject===s)));
const subjectChoice=computed({get:()=>form.subjects.length===1?form.subjects[0]:form.subjects.length===availableSubjects.value.length?'all':'custom',set:value=>{if(value==='custom')customize.value.open();else form.subjects=value==='all'?[...availableSubjects.value]:[value]}});
const topicKey=t=>t.topic_code||t.subject+'\x1f'+t.topic;
const topics=computed(()=>props.catalog.topics.filter(t=>form.subjects.includes(t.subject)));
watch(()=>[...form.subjects],()=>{const keys=topics.value.map(topicKey);form.topics=form.topics.filter(t=>keys.includes(t));form.clearErrors()});
watch(()=>form.mode,()=>form.clearErrors());
const sessions=computed(()=>props.resumable.filter(s=>s.mode===form.mode));
const startLabel=computed(()=>({practice:'Start practice',flashcards:'Start flashcards',exam:'Start exam'}[form.mode]));
function start(){form.post('/study/start',{preserveScroll:true})}
function switchTab(event,index){const delta=event.key==='ArrowRight'?1:event.key==='ArrowLeft'?-1:0;if(!delta)return;event.preventDefault();const next=(index+delta+modes.length)%modes.length;form.mode=modes[next][0];event.currentTarget.parentElement.querySelectorAll('[role=tab]')[next].focus()}
</script>
<template>
<section class="quick-study" aria-label="Study setup">
  <div class="study-mode-tabs" role="tablist" aria-label="Study mode"><button v-for="([mode,label],i) in modes" :id="'mode-'+mode" :key="mode" role="tab" :aria-selected="form.mode===mode" :tabindex="form.mode===mode?0:-1" aria-controls="study-setup" :disabled="form.processing" @click="form.mode=mode" @keydown="switchTab($event,i)">{{label}}</button></div>
  <div id="study-setup" role="tabpanel" :aria-labelledby="'mode-'+form.mode">
    <section v-if="sessions.length" class="resume-banner" aria-label="Continue your session"><div><h2>Continue your session</h2><p>{{sessions[0].title}} · {{sessions[0].completed}} of {{sessions[0].total}} {{form.mode==='flashcards'?'cards rated':'answered'}}</p><p v-if="sessions[0].deadline" class="field-help">The exam timer continues while you are away.</p></div><Link class="button primary" :href="sessions[0].url">Resume</Link><details v-if="sessions.length>1" class="other-sessions"><summary>Other sessions ({{sessions.length-1}})</summary><Link v-for="session in sessions.slice(1)" :key="session.id" :href="session.url">{{session.title}} · {{session.completed}}/{{session.total}}</Link></details></section>
    <form class="panel quick-study-form" @submit.prevent="start">
      <h2>New session</h2>
      <p class="field-help">{{form.mode==='exam'?'Answer under a time limit. Review feedback after submission.':form.mode==='flashcards'?'Answer, read the explanation, then rate your recall.':'Answer at your own pace and check each answer when ready.'}}</p>
      <div class="quick-study-fields"><label>Subject<select v-model="subjectChoice" :disabled="form.processing"><option v-for="subject in availableSubjects" :key="subject">{{subject}}</option><option value="all">All subjects</option><option value="custom">{{form.subjects.length}} {{form.subjects.length===1?'subject':'subjects'}} · customize</option></select></label>
        <fieldset class="quick-count"><legend>Questions</legend><div><button v-for="count in [5,10,20]" :key="count" type="button" :aria-pressed="form.count===count" :class="{selected:form.count===count}" :disabled="form.processing" @click="form.count=count">{{count}}</button><span v-if="![5,10,20].includes(form.count)">{{form.count}} selected</span></div></fieldset>
        <label v-if="form.mode==='exam'">Time limit (minutes)<input v-model.number="form.minutes" type="number" min="1" max="360" :disabled="form.processing" required></label>
      </div>
      <p v-if="form.pool!=='all'||form.topics.length||form.difficulty!=='All'" class="setup-summary">{{{new:'Unseen',missed:'Missed',low:'Low confidence',bookmarks:'Bookmarked',all:'All questions'}[form.pool]}} · {{form.topics.length?form.topics.length+' topics':'All topics'}} · {{form.difficulty==='All'?'All difficulties':form.difficulty}}</p>
      <ul v-if="Object.keys(form.errors).length" class="error" role="alert"><li v-for="(error,key) in form.errors" :key="key">{{error}}</li></ul>
      <div class="row"><button class="primary" :disabled="form.processing||!form.subjects.length">{{form.processing?'Starting…':startLabel}}</button><button type="button" :disabled="form.processing" @click="customize.open()">Customize</button></div>
      <p class="field-help">Your setup is remembered after you start a session.</p>
    </form>
  </div>
  <StudySheet ref="customize" title="Customize your session" :busy="form.processing"><div class="form-grid"><label>Question pool<select v-model="form.pool"><option value="all">All questions</option><option value="new">Unseen</option><option value="missed">Missed</option><option value="low">Low confidence</option><option value="bookmarks">Bookmarked</option></select></label><label>Difficulty<select v-model="form.difficulty"><option>All</option><option>Easy</option><option>Average</option><option>Difficult</option></select></label><label>Number of questions<input v-model.number="form.count" type="number" min="1" max="100"></label></div>
    <fieldset class="setup-subjects"><legend>Subjects</legend><label v-for="subject in availableSubjects" :key="subject"><input type="checkbox" v-model="form.subjects" :value="subject">{{subject}}</label></fieldset>
    <details class="setup-topics"><summary>Topics ({{form.topics.length||'all'}})</summary><button type="button" class="plain" @click="form.topics=[]">Use all topics</button><div class="topic-options"><label v-for="topic in topics" :key="topicKey(topic)"><input type="checkbox" v-model="form.topics" :value="topicKey(topic)"><span>{{topic.subject}} · {{topic.topic}} <small>({{topic.count}})</small></span></label></div></details>
    <p class="field-help">Advanced tools: <Link :href="form.mode==='flashcards'?'/flashcards?new=1':form.mode==='exam'?'/exams':'/practice?mode=tutor'">{{form.mode==='flashcards'?'Choose exact cards':form.mode==='exam'?'Build an exam with quotas':'Balance subjects or choose exact questions'}}</Link></p>
    <template #actions><button class="primary" :disabled="!form.subjects.length||form.count<1||form.count>100" @click="customize.close()">Done</button></template>
  </StudySheet>
</section>
</template>
