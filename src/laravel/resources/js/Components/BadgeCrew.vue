<script setup>
import {ref,computed} from 'vue';import {router,usePage} from '@inertiajs/vue3';import Avatar from './Avatar.vue';
const props=defineProps({badges:Array,compact:Boolean});const group=ref('All'),state=ref('All'),page=usePage();
const groups=computed(()=>['All',...new Set(props.badges.map(b=>b.group))]);
const filtered=computed(()=>props.badges.filter(b=>(group.value==='All'||b.group===group.value)&&(state.value==='All'||(state.value==='Earned'?b.earned_at:!b.earned_at))));
</script>
<template><div class="crew-filters"><label>Study tool or subject<select v-model="group"><option v-for="g in groups" :key="g">{{g}}</option></select></label><label>Status<select v-model="state"><option>All</option><option>Earned</option><option>Locked</option></select></label></div>
<div class="badge-grid" :class="{'compact-crew':compact}"><article v-for="b in filtered" :key="b.id" class="badge-card" :class="{earned:b.earned_at}">
<Avatar :avatar="b.rewards[0]" :locked="!b.earned_at" size="large"/><p class="eyebrow">{{b.group}}</p><h3>{{b.name}}</h3><strong class="character-name">{{b.rewards[0]?.name}}</strong><p>{{b.requirement}}</p>
<p v-if="b.earned_at" class="badge-earned">Earned · in your closet</p><template v-else><div class="meter"><i :style="{width:Math.min(100,b.progress/b.target*100)+'%'}"></i></div><small>{{b.progress}} / {{b.target}}</small></template>
<p v-if="b.available!==undefined&&b.available<b.target&&!b.earned_at" class="muted">{{b.available}} active questions. This milestone continues as the bank grows.</p>
<button v-if="b.earned_at&&b.rewards[0]" class="equip-button" :disabled="page.props.community?.profile.avatar.id===b.rewards[0].id" @click="router.post('/profile/equip',{avatar:b.rewards[0].id},{preserveScroll:true})">{{page.props.community?.profile.avatar.id===b.rewards[0].id?'Equipped':'Equip character'}}</button>
</article></div><p v-if="!filtered.length">No characters match these filters yet.</p></template>
