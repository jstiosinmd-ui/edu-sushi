<script setup>
import {ref,onMounted,onUnmounted} from 'vue';
const dock=ref(null);let observer;
onMounted(()=>{observer=new ResizeObserver(()=>document.documentElement.style.setProperty('--study-dock-height',dock.value.offsetHeight+'px'));observer.observe(dock.value)});
onUnmounted(()=>{observer?.disconnect();document.documentElement.style.removeProperty('--study-dock-height')});
</script>
<template><section ref="dock" class="study-dock"><slot/></section></template>
