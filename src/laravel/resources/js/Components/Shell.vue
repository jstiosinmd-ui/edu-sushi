<script setup>
import {ref,computed,onMounted,onUnmounted} from 'vue';
import {Link,usePage,router} from '@inertiajs/vue3';
import Footer from './Footer.vue';import Errors from './Errors.vue';import Avatar from './Avatar.vue';import {art} from '../art';
const page=usePage(),tierDialog=ref(null),header=ref(null);let observer;
onMounted(()=>{observer=new ResizeObserver(()=>document.documentElement.style.setProperty('--header-height',header.value.offsetHeight+'px'));observer.observe(header.value)});
onUnmounted(()=>observer?.disconnect());
const rank=computed(()=>page.props.achievement),community=computed(()=>page.props.community);
const nav=[['Counter','/'],['Fresh Catch','/fresh-catch'],['Memory Maki','/flashcards'],['Passport','/passport'],['Leaderboard','/leaderboard'],['More practice','/practice'],['Roll Call','/library'],['Bento Builder','/exams'],['My Progress','/progress']];
</script>
<template><div class="app-shell"><header ref="header" class="sticky"><div class="topbar"><Link href="/" class="brand"><img :src="art.logo" alt="SUSHI"><span>SUSHI<small>Clerks Exam Studio</small></span></Link>
<button class="rank" @click="tierDialog.showModal()" aria-label="View achievement tiers"><strong>{{rank?.name}}</strong><small>{{rank?.count}} completed · {{rank?.remaining}} to next tier</small><span class="meter"><i :style="{width:rank?.percent+'%'}"></i></span></button>
<Link href="/passport" class="streak-pill" :aria-label="community?.habits.current+' study-day streak, '+community?.badge_count+' achievements'"><strong>🔥 {{community?.habits.current}}</strong><small>{{community?.badge_count}} stamps</small></Link>
<div class="account"><Link href="/profile" class="profile-link"><Avatar :avatar="community?.profile.avatar" size="small"/><span>{{community?.profile.display_name}}</span></Link><button class="plain signout" @click="router.post('/logout')">Sign out</button></div></div>
<nav aria-label="Main navigation"><Link v-for="[label,path] in nav" :key="path" :href="path" :class="{active:page.url.split('?')[0]===path}">{{label}}</Link><Link v-if="page.props.user.role==='admin'" href="/admin" :class="{active:page.url.startsWith('/admin')}">Head Chef</Link></nav></header>
<main class="main"><p v-if="page.props.flash" class="notice" role="status">{{page.props.flash}}</p><Errors :errors="page.props.errors"/><slot/></main><Footer/>
<dialog ref="tierDialog" class="tier-dialog"><button class="dialog-close" @click="tierDialog.close()" aria-label="Close achievement tiers">Close</button><img class="portrait" :src="art.chef" alt="SUSHI chef"><h2>Your sushi journey</h2><p>One tier every 200 unique completed questions. Checked MCQs and rated flashcards share the same count. Repeats add practice.</p><p>Your earned journey continues as the question bank grows.</p><ol class="tiers"><li v-for="t in rank?.tiers" :key="t.at" :class="{earned:rank.count>=t.at}"><strong>{{t.name}}</strong><span>{{t.at.toLocaleString()}} questions</span></li></ol><Link href="/passport" @click="tierDialog.close()">Open your Sushi Passport</Link></dialog></div></template>
