<script setup>
import {Head,Link,router,usePage} from '@inertiajs/vue3';
import {date} from '../art';
import QuickStudy from '../Components/QuickStudy.vue';
defineProps({catalog:Object,stats:Object,assignments:Array,daily:Object,preferences:Object,resumable:Array});
const page=usePage();
</script>
<template>
<Head title="Study"/>
<header class="page-heading"><div><h1>Study</h1><p>Welcome back, {{page.props.community.profile.display_name}}.</p></div><Link href="/progress" class="text-link">View progress →</Link></header>
<QuickStudy :catalog="catalog" :preferences="preferences" :resumable="resumable"/>
<nav class="study-shortcuts" aria-label="Saved study material"><Link href="/library?view=bookmarks">Bookmarked</Link><Link href="/progress/notebook?status=all">Notes</Link><Link href="/flashcards?decks=1">Saved sets</Link><Link href="/progress">Review mistakes</Link></nav>
<details class="panel"><summary>Daily catch · {{daily.completed_today?'completed today':'10 questions'}}</summary><p>{{daily.attempt?daily.answered+' of 10 questions answered.':'Practice with ten unseen or previously missed questions.'}}</p><Link :href="daily.attempt?'/attempts/'+daily.attempt:'/practice?mode=fresh'" class="button">{{daily.attempt?'Resume daily catch':'Start daily catch'}}</Link><div v-if="daily.remind" class="row"><button class="plain" @click="router.post('/reminders/dismiss',{action:'snooze'},{preserveScroll:true})">Remind in 1 hour</button><button class="plain" @click="router.post('/reminders/dismiss',{action:'skip'},{preserveScroll:true})">Dismiss today</button></div></details>
<section class="home-progress" aria-label="Study progress"><div><strong>{{stats.completed.toLocaleString()}}</strong><span>Questions completed</span></div><div><strong>{{stats.first_total?Math.round(stats.first_correct/stats.first_total*100)+'%':'—'}}</strong><span>First-answer accuracy</span><small>{{stats.first_total.toLocaleString()}} {{stats.first_total===1?'first answer':'first answers'}}</small></div><Link href="/exams">Exam builder →</Link></section>
<section v-if="assignments.length" class="panel"><h2>Assigned exams</h2><div v-for="a in assignments" :key="a.id" class="row spaced"><div><strong>{{a.exam.title}}</strong><p>{{date(a.opens_at)}} to {{date(a.closes_at)}} · {{a.minutes}} min</p></div><button @click="router.post('/assignments/'+a.id+'/start')">Open exam</button></div></section>
</template>
