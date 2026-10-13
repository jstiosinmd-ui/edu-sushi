<script setup>
import {ref,computed,onMounted,onUnmounted} from 'vue';
import {Link,usePage,router} from '@inertiajs/vue3';
import Footer from './Footer.vue';
import Errors from './Errors.vue';
import {art} from '../art';
const page=usePage(),tierDialog=ref(null);
const header=ref(null);let headerObserver;
onMounted(()=>{headerObserver=new ResizeObserver(()=>document.documentElement.style.setProperty('--header-height',header.value.offsetHeight+'px'));headerObserver.observe(header.value)});
onUnmounted(()=>headerObserver?.disconnect());
const rank=computed(()=>page.props.achievement);
const nav=[['Counter','/'],['Practice','/practice'],['Memory Maki','/flashcards'],['Roll Call','/library'],['Bento Builder','/exams'],['My Progress','/progress']];
</script>
<template>
<div class="app-shell">
<header ref="header" class="sticky"><div class="topbar"><Link href="/" class="brand"><img :src="art.logo" alt="SUSHI"><span>SUSHI<small>Clerks Exam Studio</small></span></Link>
<button class="rank" @click="tierDialog.showModal()" aria-label="View achievement tiers"><strong>{{rank?.name}}</strong><small>{{rank?.count}} completed · {{rank?.remaining}} to next tier</small><span class="meter"><i :style="{width:rank?.percent+'%'}"></i></span></button>
<div class="account"><span>{{page.props.user.username}}</span><button class="plain" @click="router.post('/logout')">Sign out</button></div></div>
<nav><Link v-for="[label,path] in nav" :key="path" :href="path" :class="{active:page.url.split('?')[0]===path}">{{label}}</Link><Link v-if="page.props.user.role==='admin'" href="/admin" :class="{active:page.url.startsWith('/admin')}">Head Chef</Link></nav></header>
<main class="main"><p v-if="page.props.flash" class="notice" role="status">{{page.props.flash}}</p><Errors :errors="page.props.errors"/><slot/></main>
<Footer/>
<dialog ref="tierDialog" class="tier-dialog"><button class="dialog-close" @click="tierDialog.close()" aria-label="Close achievement tiers">Close</button><img class="portrait" :src="art.chef" alt="SUSHI chef"><h2>Your sushi journey</h2><p>One tier every 200 unique completed questions. A checked MCQ or rated flashcard counts once. Repeats add practice, not tier points.</p><p>New questions expand the menu. Your earned rank stays with you.</p><ol class="tiers"><li v-for="tier in rank?.tiers" :key="tier.at" :class="{earned:rank.count>=tier.at}"><strong>{{tier.name}}</strong><span>{{tier.at.toLocaleString()}} questions</span></li></ol><p>More Omakase Master tiers continue every 200 questions.</p></dialog>
</div>
</template>
