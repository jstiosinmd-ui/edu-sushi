<script setup>
defineProps({avatar:Object,name:String,size:{type:String,default:'medium'}});
</script>
<template><span class="avatar" :class="['avatar-'+size,'frame-'+(avatar?.frame||'base')]"><img :src="avatar?.image||'/art/rice-rookie.png'" :alt="name||avatar?.name||'Profile portrait'"></span></template>
