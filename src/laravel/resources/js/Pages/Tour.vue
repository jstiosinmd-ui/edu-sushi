<script setup>
import {computed,ref,watch,nextTick} from 'vue';
import {Head,Link} from '@inertiajs/vue3';
import {art} from '../art';
const props=defineProps({tour:Object}),step=ref(0),heading=ref(null),imageDialog=ref(null),selectedImage=ref(null),zoomed=ref(false);
const current=computed(()=>props.tour.stops[step.value]);
watch(step,()=>nextTick(()=>heading.value?.focus()));
async function inspect(screen){zoomed.value=false;selectedImage.value=screen;await nextTick();imageDialog.value.showModal()}
</script>
<template>
<Head :title="tour.title"/>
<div class="page-title"><div><p class="eyebrow">Your illustrated studio guide</p><h1>{{tour.title}}</h1><p>Hop from station to station and learn your way around SUSHI.</p></div><img class="portrait" :src="art.logo" alt="SUSHI"></div>
<div class="tour-layout">
<nav aria-label="Tour stations"><button v-for="(stop,i) in tour.stops" :key="stop.id" :class="{selected:step===i}" :aria-current="step===i?'step':undefined" @click="step=i"><span>{{i+1}}</span>{{stop.name}}</button></nav>
<section class="panel tour-stop">
<div class="tour-intro"><div><p class="eyebrow">Station {{step+1}} of {{tour.stops.length}}</p><h2 ref="heading" tabindex="-1">{{current.name}}</h2><p>{{current.intro}}</p></div><img class="portrait" :src="art[current.art]" :alt="current.name+' character'"></div>
<ol class="tour-instructions"><li v-for="(text,i) in current.steps" :key="text"><span aria-hidden="true">{{i+1}}</span>{{text}}</li></ol>
<div class="tour-screens"><figure v-for="screen in current.screens" :key="screen.src"><button class="screen-preview" @click="inspect(screen)" :aria-label="'Enlarge screenshot: '+screen.caption"><img :src="screen.src" :alt="screen.alt" width="1280" height="720" loading="lazy"><span>Tap to enlarge ↗</span></button><figcaption>{{screen.caption}}</figcaption></figure></div>
<p class="screenshot-note">Screenshots from v{{tour.version}} use demonstration accounts. Your questions and results will differ.</p>
<div class="tour-actions"><Link class="button" :href="current.url">Open {{current.name}} →</Link><div class="row"><button :disabled="step===0" @click="step--">Previous</button><button v-if="step<tour.stops.length-1" class="primary" @click="step++">Next station</button><Link v-else class="button primary" href="/">Back to the Counter</Link></div></div>
</section></div>
<dialog ref="imageDialog" class="tour-image-dialog" aria-labelledby="tour-image-title" @click="e=>{if(e.target===imageDialog)imageDialog.close()}">
<header><div><p class="eyebrow">{{tour.title}}</p><h2 id="tour-image-title">{{current.name}}</h2></div><button aria-label="Close screenshot" @click="imageDialog.close()">✕</button></header>
<div class="image-scroll" :class="{zoomed}"><img v-if="selectedImage" :src="selectedImage.src" :alt="selectedImage.alt"></div>
<footer v-if="selectedImage"><button class="zoom-control" :aria-pressed="zoomed" @click="zoomed=!zoomed">{{zoomed?'Fit image':'Zoom in'}}</button><p>{{selectedImage.caption}}</p><a :href="selectedImage.src" target="_blank" rel="noopener">Open original screenshot ↗</a></footer>
</dialog>
</template>
<style scoped>
.tour-layout{display:grid;grid-template-columns:220px minmax(0,1fr);gap:24px;align-items:start}.tour-layout nav{display:flex;flex-direction:column;gap:8px;position:sticky;top:calc(var(--header-height,140px) + 20px)}.tour-layout nav button{text-align:left;display:flex;gap:12px;align-items:center}.tour-layout nav button span{font-size:.8rem;opacity:.7}.tour-stop{min-width:0}.tour-intro{display:flex;gap:18px;justify-content:space-between;align-items:center}.tour-stop h2{font-size:1.8rem;margin:0}.tour-instructions{list-style:none;padding:0;display:grid;gap:16px;margin:24px 0}.tour-instructions li{display:flex;gap:12px;line-height:1.6;align-items:baseline}.tour-instructions li>span{display:inline-grid;place-items:center;border-radius:50%;background:#f9e3e9;width:26px;height:26px;flex-shrink:0;font-size:.8rem;font-weight:700}.tour-screens{display:grid;gap:24px}.tour-screens figure{margin:0}.screen-preview{padding:0;width:100%;overflow:hidden;border:1px solid #e7c4ce;border-radius:16px;background:#fff;position:relative;display:block}.screen-preview img{width:100%;height:auto;display:block}.screen-preview span{display:block;padding:10px;background:#fbe9ee;color:#792440;font-size:.8rem;text-align:right}.tour-screens figcaption{padding:10px 4px 0;line-height:1.6;font-size:.9rem}.screenshot-note{font-size:.8rem;color:var(--muted);margin:20px 0}.tour-actions{display:flex;gap:16px;justify-content:space-between;flex-wrap:wrap}.tour-image-dialog{width:min(1200px,calc(100% - 24px));max-width:none;max-height:calc(100dvh - 24px);padding:0;border:1px solid #e7c4ce;border-radius:20px;background:var(--cream);color:var(--ink);margin:auto}.tour-image-dialog[open]{display:flex;flex-direction:column}.tour-image-dialog::backdrop{background:#32132299}.tour-image-dialog header{display:flex;justify-content:space-between;align-items:center;padding:16px 22px;border-bottom:1px solid var(--line)}.tour-image-dialog h2{margin:0;font-size:1.35rem}.tour-image-dialog .eyebrow{margin:0 0 5px}.tour-image-dialog header button{min-width:44px}.image-scroll{overflow:auto;min-height:0;scrollbar-width:thin}.image-scroll img{width:100%;height:auto;display:block}.image-scroll.zoomed img{width:auto;max-width:none}.zoom-control{float:right;margin:0 0 8px 12px}.tour-image-dialog footer{padding:12px 22px;flex-shrink:0;font-size:.85rem}.tour-image-dialog footer p{margin:0 0 8px}:global(body:has(.tour-image-dialog[open])){overflow:hidden}
@media(max-width:760px){.tour-layout{grid-template-columns:1fr;gap:12px}.tour-layout nav{position:static;flex-direction:row;overflow:auto;padding:4px 0 12px;scrollbar-width:thin;scrollbar-color:#d795a9 transparent}.tour-layout nav button{flex:0 0 170px}.tour-intro>.portrait{width:72px;height:72px}.tour-stop{padding:20px}.tour-actions{flex-direction:column}.tour-actions>.row{justify-content:space-between}.image-scroll.zoomed img{width:auto;max-width:none}.zoom-control{float:right;margin:0 0 8px 12px}.tour-image-dialog footer{padding:12px 16px}}
</style>
