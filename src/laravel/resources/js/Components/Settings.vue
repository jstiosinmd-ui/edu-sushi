<script setup>
import {computed} from 'vue';import SubjectPicker from './SubjectPicker.vue';
const props=defineProps({form:Object,catalog:Object,exam:Boolean});
const topics=computed(()=>props.catalog.topics.filter(t=>props.form.subjects.includes(t.subject)));
</script><template>
<SubjectPicker :subjects="catalog.subjects" v-model="form.subjects"/>
<div v-if="form.mode!=='fresh'||exam" class="form-grid">
<label>Questions<input v-model.number="form.count" type="number" min="1" :max="catalog.total" required></label>
<label>Difficulty<select v-model="form.difficulty"><option>All</option><option>Easy</option><option>Average</option><option>Difficult</option></select></label>
<label v-if="!exam">Question pool<select v-model="form.pool"><option value="all">All questions</option><option value="new">Not completed</option><option value="missed">Missed questions</option><option value="bookmarks">Bookmarks</option></select></label>
<label>Draw seed<input v-model.number="form.seed" type="number" min="0" max="4294967295" required></label>
<label v-if="form.mode==='exam'&&!exam">Minutes<input v-model.number="form.minutes" type="number" min="1" max="360"></label>
<label class="check"><input type="checkbox" v-model="form.balance">Balance selected subjects or topics</label>
</div>
<details v-if="form.mode!=='fresh'||exam"><summary>Topics <span class="muted">(all topics when none selected)</span></summary><div class="topic-grid"><label v-for="t in topics" :key="t.subject+t.topic" class="check"><input type="checkbox" v-model="form.topics" :value="t.subject+'\u001f'+t.topic">{{t.subject}} / {{t.topic}}</label></div></details>
<p v-else class="notice">Ten questions from unanswered items and your latest incorrect answers. Correct answers leave this pool; a later miss brings them back. Duplicate stems appear only once per draw.</p>
</template>
