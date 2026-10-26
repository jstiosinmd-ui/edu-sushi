<script setup>
import {ref,watch} from 'vue';

const props=defineProps({panelKey:[String,Number],label:{type:String,default:'Study content'},focusOnChange:Boolean});
const stage=ref(null),current=ref(null),direction=ref('next');
let moveFocus=false;
watch(()=>props.panelKey,(value,old)=>{
    direction.value=typeof value==='number'&&typeof old==='number'&&value<old?'back':'next';
    moveFocus=props.focusOnChange||!!stage.value?.contains(document.activeElement);
});
function leaving(element){element.inert=true;element.setAttribute('aria-hidden','true')}
function entering(element){element.inert=false;element.removeAttribute('aria-hidden')}
function entered(element){
    if(element!==current.value)return;
    if(moveFocus)(element.querySelector('[data-stage-focus]')||element).focus({preventScroll:true});
}
function reveal(selector){
    const element=current.value,target=element?.querySelector(selector);
    if(!target)return;
    element.scrollTop+=target.getBoundingClientRect().top-element.getBoundingClientRect().top-16;
    target.focus({preventScroll:true});
}
defineExpose({reveal});
</script>

<template>
    <div ref="stage" class="slide-stage">
        <Transition :name="'panel-'+direction" @before-enter="entering" @before-leave="leaving" @after-enter="entered">
            <section :key="panelKey" ref="current" class="slide-page" tabindex="-1" :aria-label="label">
                <slot/>
            </section>
        </Transition>
    </div>
</template>
