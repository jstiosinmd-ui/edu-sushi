<script setup>
import {ref,useId,onUnmounted} from 'vue';
const props=defineProps({title:String,busy:Boolean}),emit=defineEmits(['close','open']);
const dialog=ref(null),titleId=useId();let opener;
function open(){opener=document.activeElement;if(!dialog.value.open){dialog.value.showModal();emit('open')}}
function close(){if(!props.busy)dialog.value?.close()}
function closed(){emit('close');if(opener?.isConnected)opener.focus({preventScroll:true})}
onUnmounted(()=>dialog.value?.close());
defineExpose({open,close});
</script>
<template><dialog ref="dialog" class="study-sheet" :aria-labelledby="titleId" @close="closed" @cancel="e=>{if(busy)e.preventDefault()}" @click="e=>{if(e.target===dialog)close()}"><header><h2 :id="titleId">{{title}}</h2><button type="button" :disabled="busy" aria-label="Close dialog" @click="close">Close</button></header><div class="sheet-body"><slot/></div><footer v-if="$slots.actions" class="sheet-actions"><slot name="actions"/></footer></dialog></template>
