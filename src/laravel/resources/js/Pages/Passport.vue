<script setup>
import {Head,Link} from '@inertiajs/vue3';import BadgeCrew from '../Components/BadgeCrew.vue';defineProps({badges:Array,closet:Array});
</script>
<template><Head title="Sushi Passport"/><div class="page-title"><div><p class="eyebrow">Collect your study story</p><h1>Sushi Passport</h1><p>{{badges.filter(b=>b.earned_at).length}} / {{badges.length}} achievements earned. A new sushi character for every milestone.</p></div><Link class="button" href="/profile#closet">Character Closet</Link></div><BadgeCrew :badges="badges"/></template>
