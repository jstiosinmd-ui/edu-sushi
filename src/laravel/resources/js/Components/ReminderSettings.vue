<script setup>
import {ref} from 'vue';import {useForm} from '@inertiajs/vue3';import axios from 'axios';import Errors from './Errors.vue';import {message} from '../art';
const props=defineProps({preferences:Object,pushKey:String});const form=useForm({...props.preferences}),status=ref(''),busy=ref(false);
const zones=typeof Intl.supportedValuesOf==='function'?Intl.supportedValuesOf('timeZone'):['Asia/Manila','Asia/Singapore','UTC','America/New_York'];
if(!zones.includes(form.timezone))zones.unshift(form.timezone);
async function enable(){
 busy.value=true;status.value='';
 try{
  if(!('serviceWorker' in navigator)||!('PushManager' in window)||!('Notification'in window))throw new Error('This browser does not support push. On iPhone or iPad, add SUSHI to your Home Screen first. In-app reminders remain available.');
  if(!props.pushKey)throw new Error('Push is not configured on this server yet. In-app reminders remain available.');
  const permission=await Notification.requestPermission();if(permission!=='granted')throw new Error('Notifications were not allowed. You can change this in browser settings.');
  const registration=await navigator.serviceWorker.register('/sw.js');await navigator.serviceWorker.ready;
  const raw=atob(props.pushKey.replace(/-/g,'+').replace(/_/g,'/')),key=Uint8Array.from(raw,c=>c.charCodeAt(0));
  const sub=await registration.pushManager.getSubscription()||await registration.pushManager.subscribe({userVisibleOnly:true,applicationServerKey:key});
  await axios.post('/reminders/subscribe',sub.toJSON());form.reminder_enabled=true;form.post('/reminders/settings',{preserveScroll:true});
  status.value='This device is ready. Save your preferred reminder time below.';
 }catch(e){status.value=e.response?message(e):e.message}finally{busy.value=false}
}
async function disable(){
 busy.value=true;try{const reg=await navigator.serviceWorker?.getRegistration();const sub=await reg?.pushManager.getSubscription();if(sub){await axios.post('/reminders/unsubscribe',{endpoint:sub.endpoint});await sub.unsubscribe()}form.reminder_enabled=false;form.post('/reminders/settings',{preserveScroll:true});status.value='Push reminders are off.'}catch(e){status.value=message(e)}finally{busy.value=false}
}
</script>
<template><section class="panel"><h2>Your daily reminder</h2><p>One friendly reminder if today's Fresh Catch is unfinished. The most recently enabled device receives it.</p>
<div class="row spaced"><button type="button" :disabled="busy" @click="enable">Enable notifications on this device</button><button type="button" :disabled="busy" @click="disable">Turn off push reminders</button></div>
<p class="muted">On iPhone or iPad: Share → Add to Home Screen, open SUSHI there, then enable notifications. Browser permission and server setup are required. In-app reminders work without push.</p>
<p v-if="status" class="notice" role="status">{{status}}</p><form @submit.prevent="form.post('/reminders/settings',{preserveScroll:true})"><div class="form-grid"><label>Reminder time<input type="time" v-model="form.reminder_time" required></label><label>Study timezone<select v-model="form.timezone"><option v-for="z in zones" :key="z">{{z}}</option></select></label><label class="check"><input type="checkbox" v-model="form.reminder_enabled">Daily push reminder</label></div><p class="muted">Timezone changes are limited to once every seven days. Past daily stamps retain their recorded dates.</p><Errors :errors="form.errors"/><button :disabled="form.processing">Save reminder preferences</button></form></section></template>
