<script setup>
import {computed} from 'vue';
const props=defineProps({features:{type:Array,default:()=>[]},busy:Boolean});
defineEmits(['visit']);
const groups=computed(()=>[['added','Added'],['improved','Improved'],['fixed','Fixed']].map(([id,label])=>({
    id,label,items:props.features.filter(feature=>(feature.category||'added')===id),
})).filter(group=>group.items.length));
</script>

<template>
<section v-for="group in groups" :key="group.id" class="change-group">
    <h4><span class="change-label" :class="group.id">{{group.label}}</span></h4>
    <ul><li v-for="feature in group.items" :key="feature.title"><strong>{{feature.title}}.</strong> {{feature.text}} <button v-if="feature.url" class="change-link" :disabled="busy" :aria-label="'Open '+feature.title" @click="$emit('visit',feature.url)">Open <span aria-hidden="true">↗</span></button></li></ul>
</section>
</template>

<style scoped>
.change-group+.change-group{margin-top:20px}.change-group h4{margin:0 0 8px}
.change-label{display:inline-block;font-size:11px;font-weight:750;letter-spacing:.04em;padding:4px 8px;border-radius:6px}
.added{background:#e8f1e7;color:#3f6549}.improved{background:#fbe7ed;color:#8c3553}.fixed{background:#eee9f7;color:#645080}
ul{padding-left:20px;margin:0}li{font-size:14px;line-height:1.7;padding-left:3px;overflow-wrap:anywhere}li+li{margin-top:10px}li::marker{color:#d795a9}
li strong{font-weight:700}.change-link{display:inline;border:0;background:transparent;color:#8c3553;padding:4px 6px;min-height:44px;text-decoration:underline;text-underline-offset:3px;font-size:12px;font-weight:700;border-radius:6px}
.change-link:hover{background:#f9e7ec}.change-link:focus-visible{outline:2px solid #8c3553;outline-offset:2px}
</style>
