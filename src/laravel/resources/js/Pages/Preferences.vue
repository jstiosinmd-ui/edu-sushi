<script setup>
import {Head,Link} from '@inertiajs/vue3';import ReminderSettings from '../Components/ReminderSettings.vue';
defineProps({preferences:Object,pushKey:String});
</script>
<template><Head title="Settings"/><h1>Settings</h1><p>Make your study habit fit your day.</p><ReminderSettings :preferences="preferences" :push-key="pushKey"/><p><Link href="/password">Change password</Link></p></template>
