<script setup>import {usePage} from '@inertiajs/vue3';const page=usePage();</script>
<template><footer class="footer"><span><strong>SUSHI</strong> · Clerks Comprehensive Exam Reviewer<small v-if="page.props.release" class="release-label">v{{page.props.release.version}} · {{page.props.release.name}}</small></span><span>Jon Bryan S. Tiosin, MD<br>(c) jstiosin 2026</span></footer></template>
<style scoped>.release-label{display:block;margin-top:6px}</style>
