<script setup>
import StudyNote from './StudyNote.vue';
import ReportQuestion from './ReportQuestion.vue';
defineProps({question:Object});
</script>
<template><div v-if="question.key" class="inline-feedback">
<section><h3>Why this answer is correct</h3><p class="pre">{{question.rationale}}</p></section>
<section><h3>Why the other choices differ</h3><template v-for="(text,i) in question.expl" :key="i"><p v-if="String.fromCharCode(65+i)!==question.key" class="pre"><strong>{{String.fromCharCode(65+i)}}.</strong> {{text}}</p></template></section>
<section class="answer-reference"><h3>Reference</h3><p class="pre">{{question.source||'No reference recorded.'}}</p><p class="field-help">{{question.subject}} · {{question.topic}} · {{question.id}}<span v-if="question.version"> · version {{question.version}}</span></p></section>
<div class="question-tools" aria-label="Question tools"><StudyNote :key="question.id" :question-id="question.id"/><ReportQuestion v-if="question.version" :key="question.id+':'+question.version" :question="question"/></div>
</div></template>
