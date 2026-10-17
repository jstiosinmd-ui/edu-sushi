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
                        <p class="eyebrow">A little practice. A stronger you.</p>
                        <h1>Good things come<br>one roll at a time.</h1>
                        <p>Your questions, progress, and study crew in one place.</p>
                        <div class="crew" aria-label="Your study subjects">
                            <div v-for="(c,s) in crew" :key="s">
                                <img :src="c.image" :alt="c.name" width="68" height="68">
                                <strong>{{s==='Internal Medicine'?'Medicine':s}}</strong>
                            </div>
                        </div>
                        <p class="bank-summary"><span aria-hidden="true">●</span> {{questionCount.toLocaleString()}} questions · {{Object.keys(crew).length}} subjects · Your own pace</p>
                    </div>
                </div>
            </section>
            <section class="access" aria-labelledby="sign-in-title">
                <div class="login-form">
                    <img class="seal" :src="art.logo" alt="SUSHI" width="64" height="64">

                    <h2 id="sign-in-title">Welcome back</h2>

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
