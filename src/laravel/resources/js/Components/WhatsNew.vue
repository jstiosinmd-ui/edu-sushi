<script setup>
import {ref,watch,nextTick} from 'vue';
import {router,usePage} from '@inertiajs/vue3';
import axios from 'axios';
import {art,message} from '../art';
import ReleaseChanges from './ReleaseChanges.vue';

const props=defineProps({defer:Boolean});
const page=usePage(),dialog=ref(null),busy=ref(false),error=ref('');
const releaseDate=value=>new Intl.DateTimeFormat('en',{month:'long',day:'numeric',year:'numeric',timeZone:'UTC'}).format(new Date(value+'T12:00:00Z'));
async function open(){
    await nextTick();
    if(page.props.featureUpdate&&!dialog.value?.open){
        dialog.value.showModal();
        dialog.value.querySelector('.journey-scroll')?.scrollTo({top:0});
    }
}
watch(()=>[page.props.featureUpdate?.release,page.props.featureUpdate?.unread,page.props.user?.id,props.defer],()=>{
    if(page.props.featureUpdate?.unread&&!props.defer)open();
},{immediate:true});
async function close(url){
    if(busy.value)return;
    busy.value=true;error.value='';
    try{
        await axios.post('/feature-updates/read',{release:page.props.featureUpdate.release});
        page.props.featureUpdate.unread=false;
        dialog.value.close();
        if(url)router.visit(url);
    }catch(e){error.value=message(e)}finally{busy.value=false}
}
defineExpose({open});
</script>

<template>
<dialog ref="dialog" class="journey-dialog whats-new" aria-labelledby="whats-new-title" @cancel.prevent="close()">
    <header class="journey-header">
        <img class="small-portrait" :src="art.logo" alt="SUSHI">
        <div><p class="eyebrow">What's new at the counter</p><h2 id="whats-new-title">Changelog</h2></div>
        <button type="button" :disabled="busy" aria-label="Close changelog" @click="close()">✕</button>
    </header>
    <div v-if="page.props.featureUpdate" class="journey-scroll" tabindex="0" aria-label="Release history">
        <article class="current-release">
            <div class="release-meta"><span class="version-tag">v{{page.props.featureUpdate.version}}</span><span class="latest-tag">Latest</span><time :datetime="page.props.featureUpdate.released_on">{{releaseDate(page.props.featureUpdate.released_on)}}</time></div>
            <h3 class="release-name">{{page.props.featureUpdate.title}}</h3>
            <p class="release-summary">{{page.props.featureUpdate.summary}}</p>
            <ReleaseChanges :features="page.props.featureUpdate.features" :busy="busy" @visit="close"/>
        </article>
        <section v-if="page.props.featureUpdate.history?.length" class="release-history" aria-labelledby="previous-releases">
            <h3 id="previous-releases">Previous releases</h3>
            <details v-for="release in page.props.featureUpdate.history" :key="release.id" class="past-release">
                <summary><span class="version-tag">v{{release.version}}</span><span class="past-title">{{release.name}}</span><time :datetime="release.released_on">{{releaseDate(release.released_on)}}</time></summary>
                <div class="past-content"><p class="release-summary">{{release.summary}}</p><ReleaseChanges :features="release.features" :busy="busy" @visit="close"/></div>
            </details>
        </section>
    </div>
    <footer class="new-actions"><div><p>Read this again from your account menu.</p><p v-if="error" class="error" role="alert">{{error}}</p></div><button class="primary" :disabled="busy" @click="close()">{{busy?'Saving…':'Back to studying'}}</button></footer>
</dialog>
</template>

<style scoped>
.whats-new{width:min(760px,calc(100% - 24px));height:fit-content;max-height:calc(100dvh - 32px);margin:auto;border-radius:24px}
.whats-new .journey-header{grid-template-columns:auto minmax(0,1fr) auto;gap:14px;padding:20px 24px;background:#fffaf7}
.whats-new .journey-header h2{margin:0;font-family:inherit;font-size:25px;font-weight:750}
.whats-new .journey-header .eyebrow{font-size:10px;margin-bottom:4px}
.whats-new .journey-header>button{background:transparent;color:var(--ink);min-height:44px}
.whats-new .journey-scroll{max-height:62dvh;flex:0 1 auto;padding:24px}
.release-meta{display:flex;align-items:center;flex-wrap:wrap;gap:10px}
.version-tag{display:inline-block;font-size:13px;font-weight:750;color:#862b49;background:#f9e7ec;padding:4px 9px;border-radius:7px;white-space:nowrap}
.latest-tag{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#45694a}
time{font-size:12px;color:#76656c}
.release-meta time{margin-left:auto}
.release-name{font-size:23px;margin:14px 0 6px}
.release-summary{font-size:14px;line-height:1.65;margin:0 0 20px;color:#76656c}
.release-history{border-top:1px solid var(--line);margin-top:28px;padding-top:20px}
.release-history>h3{font-size:12px;text-transform:uppercase;letter-spacing:.08em;margin:0 0 12px;color:#76656c}
.past-release{border:1px solid var(--line);border-radius:12px;margin-top:10px;background:#fffaf8}
.past-release summary{padding:14px;cursor:pointer;line-height:1.8;min-height:48px}
.past-title{font-weight:700;font-size:14px;margin-left:10px}
.past-release time{display:block;margin-left:20px}
.past-content{padding:6px 16px 16px}
.new-actions{padding:16px 24px;flex-shrink:0;display:flex;align-items:center;justify-content:space-between;gap:16px;border-top:1px solid var(--line);background:#fffaf7}
.new-actions p{font-size:12px;margin:0;max-width:34ch}.new-actions .primary{white-space:nowrap}
@media(max-width:550px){.whats-new .journey-header{padding:16px;gap:10px}.whats-new .journey-header h2{font-size:23px}.whats-new .journey-scroll{padding:20px 16px}.release-meta time{flex-basis:100%;margin-left:0}.new-actions{padding:14px 16px;align-items:stretch;flex-direction:column;gap:10px}.new-actions p{max-width:none}.new-actions .primary{width:100%}.past-title{margin-left:6px}}
</style>
