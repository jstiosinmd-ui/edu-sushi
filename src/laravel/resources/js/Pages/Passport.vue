<script setup>
import {Head,Link,usePage} from '@inertiajs/vue3';import WeekStamps from '../Components/WeekStamps.vue';import BadgeCrew from '../Components/BadgeCrew.vue';defineProps({badges:Array,closet:Array});const page=usePage();
</script>
<template><Head title="Sushi Passport"/><div class="page-title"><div><h1>Sushi Passport</h1><p>{{badges.filter(b=>b.earned_at).length}} / {{badges.length}} achievements earned</p></div><Link class="button" href="/profile#closet">Character Closet</Link></div><details class="panel"><summary>Daily stamps</summary><WeekStamps :habits="page.props.community.habits"/></details><BadgeCrew :badges="badges"/></template>
