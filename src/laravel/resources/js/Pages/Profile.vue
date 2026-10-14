<script setup>
import {ref,computed} from 'vue';
import {Head,Link,useForm,router} from '@inertiajs/vue3';
import Avatar from '../Components/Avatar.vue';
import Errors from '../Components/Errors.vue';
import ReminderSettings from '../Components/ReminderSettings.vue';
const props=defineProps({profile:Object,closet:Array,badges:Array,hasPhoto:Boolean,photoUrl:String,preferences:Object,pushKey:String});
const form=useForm({display_name:props.profile.display_name,bio:props.profile.bio||'',avatar:props.profile.avatar.id,pins:props.profile.pins||[],photo:null,remove_photo:false});
const preview=ref(''),image=ref(null),crop=ref(50),photoError=ref('');
const chosen=computed(()=>form.avatar==='photo'?{image:preview.value||props.photoUrl,frame:'base'}:props.closet.find(a=>a.id===form.avatar));
const earned=computed(()=>props.badges.filter(b=>b.earned_at));
function pin(id){form.pins=form.pins.includes(id)?form.pins.filter(x=>x!==id):form.pins.length<3?[...form.pins,id]:form.pins}
async function choosePhoto(e){
 photoError.value='';const file=e.target.files[0];if(!file)return;
 if(!['image/jpeg','image/png','image/webp'].includes(file.type)||file.size>4*1024*1024){photoError.value='Choose a JPEG, PNG, or WebP up to 4 MB.';return}
 try{image.value=await createImageBitmap(file);crop.value=50;await recrop();form.avatar='photo';form.remove_photo=false}catch{photoError.value='This image could not be opened.'}
}
async function recrop(){
 if(!image.value)return;const source=image.value,s=Math.min(source.width,source.height),canvas=document.createElement('canvas');canvas.width=canvas.height=512;
 const ctx=canvas.getContext('2d');ctx.fillStyle='#fff8f3';ctx.fillRect(0,0,512,512);ctx.drawImage(source,(source.width-s)*crop.value/100,(source.height-s)*crop.value/100,s,s,0,0,512,512);
 const blob=await new Promise(resolve=>canvas.toBlob(resolve,'image/jpeg',.9));form.photo=new File([blob],'portrait.jpg',{type:'image/jpeg'});
 if(preview.value)URL.revokeObjectURL(preview.value);preview.value=URL.createObjectURL(blob);
}
function save(){form.post('/profile',{forceFormData:true,preserveScroll:true,onSuccess:()=>{form.photo=null}})}
</script>
<template><Head title="My Profile"/><div class="page-title"><div><p class="eyebrow">Your place at the counter</p><h1>My Profile</h1><p>Your display name, portrait, bio, and pinned badges are visible to signed-in learners.</p></div><Avatar :avatar="chosen" size="large"/></div>
<form class="panel" @submit.prevent="save"><div class="form-grid"><label>Display name<input v-model="form.display_name" required maxlength="50"></label><label>Short bio<textarea v-model="form.bio" maxlength="240"></textarea></label><label>Upload a profile photo<input type="file" accept="image/jpeg,image/png,image/webp" @change="choosePhoto"><small>JPEG, PNG, or WebP · up to 4 MB</small></label></div>
<label v-if="preview">Move photo crop<input type="range" v-model="crop" min="0" max="100" @input="recrop"></label><p v-if="photoError" role="alert">{{photoError}}</p>
<div class="row"><button v-if="hasPhoto||preview" type="button" @click="form.avatar='photo'">Use my photo</button><button v-if="hasPhoto||preview" type="button" @click="form.remove_photo=true;form.avatar='rice';form.photo=null;preview=''">Remove photo</button></div>
<h2 class="spaced">Character Closet</h2><p>Select an unlocked portrait. Earn achievements to meet the rest of the crew.</p>
<div class="closet-grid"><button v-for="a in closet" :key="a.id" type="button" :disabled="!a.unlocked" :class="{selected:form.avatar===a.id}" @click="form.avatar=a.id"><Avatar :avatar="a"/><strong>{{a.name}}</strong><small>{{a.unlocked?'Available':'Locked · '+(badges.find(b=>b.id===a.badge)?.name||'Achievement')}}</small></button></div>
<h2 class="spaced">Pin up to three achievements</h2><p v-if="!earned.length">Your first achievement will appear here.</p><div class="pin-list"><button v-for="b in earned" :key="b.id" type="button" :class="{selected:form.pins.includes(b.id)}" @click="pin(b.id)">{{form.pins.includes(b.id)?'★':'☆'}} {{b.name}}</button></div>
<Errors :errors="form.errors"/><button class="primary spaced" :disabled="form.processing">Save profile</button> <Link href="/passport">View Sushi Passport</Link></form>
<p><Link href="/password">Change password</Link> · <button class="plain" @click="router.post('/logout')">Sign out</button></p><ReminderSettings :preferences="preferences" :push-key="pushKey"/>
</template>

