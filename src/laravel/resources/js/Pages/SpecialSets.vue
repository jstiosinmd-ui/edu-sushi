<script setup>
import {Head,Link} from '@inertiajs/vue3';
defineProps({sets:Array});
</script>
<template>
<Head title="Special sets"/>
<header class="page-heading"><div><h1>Special sets</h1><p>2027 diagnostic questions with answer rationales.</p></div></header>
<p class="panel">Choose a subject to study its 100 questions. Items with incomplete or ambiguous answers are labeled <strong>Review only</strong> and excluded from your score. Your progress is saved for each set.</p>
<div class="special-set-grid"><article v-for="set in sets" :key="set.slug" class="panel">
<h2>{{set.title}}</h2><p>{{set.summary.total}} questions · {{set.summary.scorable}} scored · {{set.summary.total-set.summary.scorable}} review only</p>
<progress :value="set.summary.completed" :max="set.summary.total" :aria-label="set.title+' progress'"></progress>
<p>{{set.summary.completed}} of {{set.summary.total}} completed<span v-if="set.summary.scored"> · {{set.summary.correct}} / {{set.summary.scored}} correct</span></p>
<Link :href="'/special-sets/'+set.slug" class="button primary">{{set.summary.completed?'Continue set':'Start set'}}</Link>
</article></div>
</template>
<style scoped>.special-set-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,290px),1fr));gap:1rem}.special-set-grid progress{width:100%;accent-color:#9d3656}.special-set-grid h2{font-size:1.35rem}</style>
