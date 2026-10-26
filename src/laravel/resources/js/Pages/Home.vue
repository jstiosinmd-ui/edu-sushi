<script setup>
import {computed} from 'vue';
import {Head,Link,router,usePage} from '@inertiajs/vue3';
import {art,modes,date} from '../art';
const props=defineProps({catalog:Object,stats:Object,assignments:Array,daily:Object,deck:Object});
const page=usePage();
const unfinished=computed(()=>props.stats.attempts.filter(a=>a.status==='active'&&a.mode!=='fresh'));
</script>
<template>
<Head title="Study"/>
<header class="page-heading"><div><h1>Study</h1><p>Welcome back, {{page.props.community.profile.display_name}}.</p></div><Link href="/flashcards?decks=1" class="text-link">Saved decks <span aria-hidden="true">→</span></Link></header>
<section v-if="unfinished.length" class="resume-banner" aria-label="Unfinished rounds"><div><h2>Continue a round</h2><p>Your answers are saved. Timed exams keep their original deadline.</p></div><div class="resume-links"><Link v-for="a in unfinished" :key="a.id" :href="'/attempts/'+a.id" class="button">Resume {{modes[a.mode]||'round'}} · {{a.items_count}} questions</Link></div></section>
<section class="panel daily-catch">
 <div class="daily-catch-copy"><div class="section-heading"><h2>Today's catch</h2><span v-if="daily.completed_today" class="status-label">Completed</span><span v-else class="status-label">10 questions</span></div>
 <p v-if="daily.attempt">{{daily.answered}} of 10 questions answered. Pick up where you left off.</p><p v-else>Practice with ten questions in Fresh Catch, then review your answers.</p>
 <progress v-if="daily.attempt" class="study-progress" :value="daily.answered" :max="10" aria-label="Today's catch progress"/>
 <Link :href="daily.attempt?'/attempts/'+daily.attempt:'/practice?mode=fresh'" class="button primary">{{daily.attempt?'Continue catch':daily.completed_today?'Start another catch':'Start daily catch'}} <span aria-hidden="true">→</span></Link>
 <p v-if="!daily.available" class="field-help">Fewer than 10 eligible questions. Choose more subjects in setup.</p>
 <details v-if="daily.remind" class="quiet-reminder"><summary>Reminder options</summary><button class="plain" @click="router.post('/reminders/dismiss',{action:'snooze'},{preserveScroll:true})">In 1 hour</button><button class="plain" @click="router.post('/reminders/dismiss',{action:'skip'},{preserveScroll:true})">Dismiss today</button></details></div>
 <img :src="art.temaki" alt="" class="daily-character" width="120" height="120">
</section>
<div class="study-launches">
 <Link :href="deck?'/flashcards/decks/'+deck.id:'/flashcards?new=1'" class="study-launch"><div><span class="eyebrow">Memory Maki</span><h2>Flashcards</h2><p>{{deck?deck.confident+' of '+deck.total+' cards rated 5. Continue your deck.':'Choose topics, answer questions, and rate your recall.'}}</p></div><span aria-hidden="true">→</span></Link>
 <Link href="/practice?mode=exam" class="study-launch"><div><span class="eyebrow">Wasabi Rush</span><h2>Practice exam</h2><p>Set a time limit and review answers after submission.</p></div><span aria-hidden="true">→</span></Link>
</div>
<div class="study-shortcuts"><Link href="/library">Question library <span aria-hidden="true">→</span></Link><Link href="/practice?mode=tutor">Tutor practice <span aria-hidden="true">→</span></Link><Link href="/exams">Exam builder <span aria-hidden="true">→</span></Link></div>
<section class="home-progress" aria-label="Study progress"><div><strong>{{stats.completed.toLocaleString()}}</strong><span>Questions completed</span></div><div><strong>{{stats.first_total?Math.round(stats.first_correct/stats.first_total*100)+'%':'—'}}</strong><span>First-answer accuracy</span><small>{{stats.first_total.toLocaleString()}} first answers</small></div><Link class="text-link" href="/progress">View progress <span aria-hidden="true">→</span></Link></section>
<section v-if="assignments.length" class="panel"><h2>Assigned exams</h2><div v-for="a in assignments" :key="a.id" class="row spaced"><div><strong>{{a.exam.title}}</strong><p>{{date(a.opens_at)}} to {{date(a.closes_at)}} · {{a.minutes}} min</p></div><button @click="router.post('/assignments/'+a.id+'/start')">Open exam</button></div></section>
</template>
