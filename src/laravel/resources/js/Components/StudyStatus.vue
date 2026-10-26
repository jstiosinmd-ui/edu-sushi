<script setup>
import {ref,watch} from 'vue';
import StudySheet from './StudySheet.vue';
import SaveNotice from './SaveNotice.vue';
const props=defineProps({status:String,error:String,pending:Object,busy:Boolean});
defineEmits(['retry','reload']);
const details=ref(null);
watch(()=>[props.pending,props.error,props.busy],()=>{if(!props.pending&&!props.error&&!props.busy)details.value?.close()},{flush:'post'});
</script>

<template>
    <div class="study-status">
        <span role="status">{{busy?'Saving progress…':error||pending?'Progress needs attention.':status||'Your progress saves automatically.'}}</span>
        <button v-show="error||pending&&!busy" type="button" @click="details.open()">Details</button>
    </div>
    <StudySheet ref="details" title="Save progress" :busy="busy">
        <SaveNotice :status="status" :error="error" :pending="pending" :busy="busy" @retry="$emit('retry')" @reload="$emit('reload')"/>
        <button v-if="error&&!pending" class="primary" :disabled="busy" @click="$emit('retry')">Retry saving</button>
    </StudySheet>
</template>
