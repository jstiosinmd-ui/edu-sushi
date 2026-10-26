<script setup>
import {ref} from 'vue';
import {Head,useForm} from '@inertiajs/vue3';
import Footer from '../Components/Footer.vue';
import Errors from '../Components/Errors.vue';
import {art,crew} from '../art';
import '../../css/login.css';

defineProps({questionCount:{type:Number,default:0}});
const show=ref(false);
const form=useForm({username:'',password:''});
function submit(){
    form.post('/login',{onFinish:()=>form.reset('password')});
}
</script>

<template>
    <Head title="Sign in"/>
    <div class="login">
        <main class="login-layout">
            <section class="identity" aria-label="Meet SUSHI">
                <div class="identity-content">
                    <img class="banner" :src="art.loginBanner" width="1440" height="480"
                         alt="SUSHI Clerks Comprehensive Exam Reviewer" fetchpriority="high">
                    <div class="caption">
                        <h1>Build your knowledge,<br>one roll at a time.</h1>
                        <p>Practice questions, review what you missed, and follow your progress.</p>
                        <p class="bank-summary">{{questionCount.toLocaleString()}} questions · {{Object.keys(crew).length}} subjects</p>
                    </div>
                    <div class="login-art" aria-hidden="true"><img :src="art.temaki" alt="" width="100" height="100"><img :src="art.memo" alt="" width="100" height="100"><img :src="art.wasabi" alt="" width="100" height="100"></div>
                </div>
            </section>
            <section class="access" aria-labelledby="sign-in-title">
                <div class="login-form">

                    <h2 id="sign-in-title">Sign in</h2>
                    <p class="muted">Continue your study.</p>

                    <Errors :errors="form.errors"/>
                    <form @submit.prevent="submit">
                        <label>Username
                            <input v-model="form.username" name="username" autocomplete="username"
                                   autocapitalize="none" spellcheck="false" placeholder="Enter your username" required>
                        </label>
                        <label>Password
                            <span class="password-input">
                                <input v-model="form.password" name="password" aria-label="Password"
                                       :type="show?'text':'password'" autocomplete="current-password"
                                       placeholder="Enter your password" required>
                                <button type="button" class="plain" @click="show=!show"
                                        :aria-label="show?'Hide password':'Show password'" :aria-pressed="show">{{show?'Hide':'Show'}}</button>
                            </span>
                        </label>
                        <button type="submit" class="primary full" :disabled="form.processing" :aria-busy="form.processing">
                            {{form.processing?'Signing in…':"Sign in"}} <span v-if="!form.processing" aria-hidden="true">→</span>
                        </button>
                    </form>
                    <p class="help">Need help signing in? Contact your administrator.</p>

                </div>
            </section>
        </main>
        <Footer/>
    </div>
</template>
