<script setup>
import {ref} from 'vue';
import StudySheet from './StudySheet.vue';import StudyNote from './StudyNote.vue';import ReportQuestion from './ReportQuestion.vue';
defineProps({question:Object});const sheet=ref(null),panel=ref('explanation'),visited=ref(false);
const titles={explanation:'Explanation',reference:'Reference',tools:'Notes and reporting'};
function open(which){panel.value=which;if(which==='tools')visited.value=true;sheet.value.open()}
</script>
<template><div v-if="question.key" class="answer-details"><button @click="open('explanation')">Explanation</button><button @click="open('reference')">Reference</button><button @click="open('tools')">Notes/report</button></div><StudySheet ref="sheet" :title="titles[panel]" class="answer-sheet"><section v-if="panel==='explanation'"><h3>{{question.key}} · {{question.options[question.key.charCodeAt(0)-65]}}</h3><p class="pre">{{question.rationale}}</p><details><summary>Why the other choices differ</summary><template v-for="(text,i) in question.expl" :key="i"><p v-if="String.fromCharCode(65+i)!==question.key" class="pre"><strong>{{String.fromCharCode(65+i)}}.</strong> {{text}}</p></template></details></section><section v-if="panel==='reference'"><p class="pre">{{question.source||'No reference recorded.'}}</p><p class="muted">{{question.subject}} · {{question.topic}} · {{question.id}}</p></section><section v-show="panel==='tools'"><template v-if="visited"><StudyNote :question-id="question.id"/><ReportQuestion v-if="question.version" :question="question"/></template></section></StudySheet></template>
