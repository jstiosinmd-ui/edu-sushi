<script setup>
defineProps({avatar:Object,name:String,locked:Boolean,size:{type:String,default:'medium'}});
</script>
<template><span class="avatar" :class="['avatar-'+size,'frame-'+(avatar?.frame||'bronze'),{'avatar-locked':locked}]"><img loading="lazy" :src="avatar?.image||'/art/rice-rookie.png'" :alt="locked?'Locked character':((name||avatar?.name||'Profile character')+' · '+(avatar?.frame||'bronze')+' frame')"><span v-if="avatar?.frame==='sakura'" class="avatar-flowers" aria-hidden="true">✿</span></span></template>
