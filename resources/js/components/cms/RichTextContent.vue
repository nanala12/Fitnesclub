<script setup lang="ts">
// Only server-sanitized CMS HTML is accepted here.
defineProps<{ html: string }>();
</script>
<template><div class="rich-text" v-html="html" /></template>
