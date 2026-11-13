<script setup lang="ts">
import AccountOverview from '@/components/account/AccountOverview.vue';
import { dashboard } from '@/routes';
import type { DashboardPageProps } from '@/types';
defineProps<DashboardPageProps>();
defineOptions({
    layout: { breadcrumbs: [{ title: 'Moje konto', href: dashboard() }] },
});
</script>
<template><AccountOverview :account-created-at="accountCreatedAt" /></template>
