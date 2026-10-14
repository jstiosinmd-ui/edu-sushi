<script setup>
import {Link,router} from '@inertiajs/vue3';import {crew} from '../art';
defineProps({subjects:Array});
function study(subject){router.post('/today-bento/start',{subjects:[subject],minutes:15})}
</script>
<template><section class="subject-map"><h2>Your ten-subject menu</h2><p>Current bank, distinct question stems, practice answers only. First-attempt accuracy includes the first graded response in any practice mode. Imported completion counts toward coverage; unknown first-attempt history is excluded from accuracy. Repeats do not increase coverage.</p>
<div class="subject-map-grid"><article v-for="s in subjects" :key="s.subject" class="panel subject-progress"><div class="subject-line"><img class="portrait" :src="crew[s.subject]?.image" :alt="crew[s.subject]?.name"><div><h3>{{s.subject}}</h3><small>{{crew[s.subject]?.name}} · {{s.avatar_unlocked?'Avatar unlocked':'Earn this avatar'}}</small></div></div>
<p><strong>{{s.attempted}} / {{s.total}}</strong> covered · {{s.remaining}} remaining</p><progress :value="s.attempted" :max="s.total||1" :aria-label="s.subject+' question coverage'"></progress>
<dl class="subject-measures"><div><dt>First-attempt accuracy</dt><dd>{{s.first_total?Math.round(s.first_correct/s.first_total*100)+'%':'No answers yet'}} <small>({{s.first_correct}} / {{s.first_total}})</small><small v-if="s.first_total>0&&s.first_total<5">Early sample · fewer than 5 answers</small></dd></div><div><dt>Familiarity</dt><dd>{{s.confident}} rated 5 <small>of {{s.rated}} rated questions</small></dd></div></dl>
<p v-if="s.next"><strong>{{s.next.name}}</strong> · {{s.next.progress}} / {{s.next.target}} distinct correct answers</p><p v-else-if="s.total">Next personal goal: {{s.remaining?'Complete coverage of all '+s.total+' questions':'Revisit the topics you missed'}}.</p>
<details v-if="s.topics.length"><summary>Topics to revisit</summary><p v-for="t in s.topics" :key="t.topic">{{t.topic}} · {{t.correct}} / {{t.total}} first answers correct</p></details>
<div class="row spaced"><button :disabled="!s.total" @click="study(s.subject)">Practice this subject</button><Link :href="'/library?subject='+encodeURIComponent(s.subject)">Browse</Link></div></article></div></section></template>
