<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import FeedbackMessage from '@/components/shared/FeedbackMessage.vue';
import { Button } from '@/components/ui/button';
import { home } from '@/routes';
defineOptions({
    layout: {
        title: 'Dostęp do konta zawieszony',
        description: 'Skontaktuj się z klubem, aby wyjaśnić status konta.',
    },
});
</script>
<template>
    <Head title="Dostęp zawieszony" />
    <FeedbackMessage
        title="Nie możesz teraz korzystać z konta"
        variant="warning"
        >Sesja została zakończona. W razie pytań skontaktuj się z obsługą
        klubu.</FeedbackMessage
    >
    <Button as-child class="w-full"
        ><Link :href="home()">Przejdź do strony klubu</Link></Button
    >
</template>
