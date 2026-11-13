<script setup lang="ts">
import AccountOverview from '@/components/account/AccountOverview.vue';
import { dashboard } from '@/routes/admin';
import type { DashboardPageProps } from '@/types';
defineProps<DashboardPageProps>();
defineOptions({
    layout: { breadcrumbs: [{ title: 'Pulpit', href: dashboard() }] },
});
</script>
<template>
    <AccountOverview
        title="Panel administratora"
        :account-created-at="accountCreatedAt"
    />
</template>
