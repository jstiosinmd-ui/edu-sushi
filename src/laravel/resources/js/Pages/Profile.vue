<script setup>
import {computed,ref} from 'vue';import {Head,Link,useForm} from '@inertiajs/vue3';import Avatar from '../Components/Avatar.vue';import Errors from '../Components/Errors.vue';
const props=defineProps({profile:Object,closet:Array,badges:Array});const filter=ref('All');
const form=useForm({display_name:props.profile.display_name,bio:props.profile.bio||'',avatar:props.profile.avatar.id,pins:props.profile.pins||[]});
const chosen=computed(()=>props.closet.find(a=>a.id===form.avatar));const earned=computed(()=>props.badges.filter(b=>b.earned_at));
const shown=computed(()=>props.closet.filter(a=>filter.value==='All'||(filter.value==='Unlocked'?a.unlocked:!a.unlocked)));
function pin(id){form.pins=form.pins.includes(id)?form.pins.filter(x=>x!==id):form.pins.length<3?[...form.pins,id]:form.pins}
</script>
<template><Head title="My Profile"/><div class="page-title"><div><p class="eyebrow">Your place at the counter</p><h1>My Profile</h1><p>Your character, display name, bio, and pinned achievements are visible to signed-in learners.</p></div><Avatar :avatar="chosen" size="large"/></div>
<form class="panel" @submit.prevent="form.post('/profile',{preserveScroll:true})"><div class="form-grid"><label>Display name<input v-model="form.display_name" required maxlength="50"></label><label>Short bio<textarea v-model="form.bio" maxlength="240"></textarea></label></div>
<div id="closet" class="row spaced"><div><h2>Character Closet</h2><p>Each achievement brings a new friend. Your overall rank supplies the frame.</p></div><label>Show characters<select v-model="filter"><option>All</option><option>Unlocked</option><option>Locked</option></select></label></div>
<div class="closet-grid"><button v-for="a in shown" :key="a.id" type="button" :disabled="!a.unlocked" :class="{selected:form.avatar===a.id}" @click="form.avatar=a.id"><Avatar :avatar="a" :locked="!a.unlocked"/><strong>{{a.name}}</strong><small>{{a.unlocked?'Available':(badges.find(b=>b.id===a.badge)?.requirement||'Locked')}}</small></button></div>
<h2 class="spaced">Pin up to three achievements</h2><p v-if="!earned.length">Your first achievement will appear here.</p><div class="pin-list"><button v-for="b in earned" :key="b.id" type="button" :class="{selected:form.pins.includes(b.id)}" @click="pin(b.id)">{{form.pins.includes(b.id)?'★':'☆'}} {{b.name}}</button></div><Errors :errors="form.errors"/><button class="primary spaced" :disabled="form.processing">Save profile</button> <Link href="/passport">View Sushi Passport</Link></form></template>
