<script setup lang="ts">
defineProps<{
    message?: string;
    id?: string;
}>();
</script>

<template>
    <p v-if="message" :id="id" role="alert" class="text-sm text-destructive">
        {{ message }}
    </p>
</template>
