<script setup>
import {crew} from '../art';
defineProps({subjects:Array,modelValue:Array});const emit=defineEmits(['update:modelValue']);
function toggle(value,checked,selected){emit('update:modelValue',checked?[...selected,value]:selected.filter(x=>x!==value))}
</script><template><fieldset><legend>Subjects</legend><div class="subject-grid"><label v-for="subject in subjects" :key="subject" class="subject-choice" :class="{selected:modelValue.includes(subject)}"><input type="checkbox" :value="subject" :checked="modelValue.includes(subject)" @change="toggle(subject,$event.target.checked,modelValue)"><img :src="crew[subject]?.image" :alt="crew[subject]?.name"><span><strong>{{subject}}</strong><small>{{crew[subject]?.name}}</small></span></label></div></fieldset></template>
