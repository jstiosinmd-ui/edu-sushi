<script setup>
defineProps({calibration:Object,accuracy:Object});
const groups=[['correct_confident','Correct + confident','Keep checking your recall.'],['correct_unsure','Correct + unsure','Reinforce what you got right.'],['incorrect_unsure','Incorrect + unsure','Review the explanation and try again.'],['incorrect_confident','Incorrect + confident','Check this concept carefully.']];
</script>
<template><details class="recall-balance panel"><summary>Accuracy and familiarity · {{accuracy.correct}} / {{accuracy.total}} correct this session</summary><p>Latest rated answer per card. Confident means rating 4–5; unsure means 1–3. Your familiarity rating is given after seeing feedback.</p><div class="recall-grid"><div v-for="[id,label,hint] in groups" :key="id"><strong>{{calibration[id]}}</strong><span>{{label}}</span><small>{{hint}}</small></div></div></details></template>
