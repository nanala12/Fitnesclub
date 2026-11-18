<script setup lang="ts">
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import NewsPostController from '@/actions/App/Http/Controllers/Admin/Cms/NewsPostController';
import ContentImage from '@/components/shared/ContentImage.vue';
import ActionButton from '@/components/shared/ActionButton.vue';
import ConfirmDialog from '@/components/shared/ConfirmDialog.vue';
import FeedbackMessage from '@/components/shared/FeedbackMessage.vue';
import EmptyState from '@/components/shared/EmptyState.vue';
import Pagination from '@/components/shared/Pagination.vue';
import { Button } from '@/components/ui/button';
import type { AdminNewsPost } from '@/types/news';
import type { PaginationLink, SharedPageProps } from '@/types';

const props = defineProps<{
    posts: {
        data: AdminNewsPost[];
        total: number;
        current_page: number;
        links: PaginationLink[];
    };
}>();
const page = usePage<SharedPageProps>();
const form = useForm({
    revision: 0,
    page: 1,
    is_published: false,
    current_media_id: null as number | null,
    image: null,
});
const selected = ref<AdminNewsPost | null>(null);
const confirmOpen = ref(false);
const feedback = computed(() =>
    Object.values(form.errors).filter(Boolean).join(' '),
);
const labels = {
    draft: 'Ukryta',
    scheduled: 'Zaplanowana',
    published: 'Opublikowana',
};
function date(value: string) {
    return new Intl.DateTimeFormat('pl-PL', {
        dateStyle: 'medium',
        timeStyle: 'short',
        timeZone: page.props.businessTimezone,
    }).format(new Date(value));
}
function visibility(post: AdminNewsPost) {
    form.clearErrors();
    form.revision = post.revision;
    form.page = props.posts.current_page;
    form.is_published = !post.is_published;
    form.patch(NewsPostController.visibility.url(post.id), {
        preserveScroll: true,
    });
}
function remove() {
    if (!selected.value) return;
    form.clearErrors();
    form.revision = selected.value.revision;
    form.current_media_id = selected.value.image?.id ?? null;
    form.page = props.posts.current_page;
    form.delete(NewsPostController.destroy.url(selected.value.id), {
        preserveScroll: true,
        onSuccess: () => {
            confirmOpen.value = false;
        },
    });
}
</script>
<template>
    <Head title="Aktualności" />
    <div class="space-y-6">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <h1 class="font-heading text-3xl sm:text-4xl">Aktualności</h1>
                <p class="mt-2 text-sm text-muted-foreground">
                    Pisz wiadomości z klubu, dodawaj zdjęcia i zarządzaj
                    publikacją.
                </p>
            </div>
            <Button as-child
                ><Link :href="NewsPostController.create()"
                    >Dodaj aktualność</Link
                ></Button
            >
        </div>
        <FeedbackMessage v-if="feedback" variant="error" :title="feedback"
            ><Link
                v-if="form.errors.revision"
                :href="
                    NewsPostController.index({
                        query: { page: posts.current_page },
                    })
                "
                class="underline"
                >Wczytaj aktualne dane</Link
            ></FeedbackMessage
        >
        <EmptyState
            v-if="posts.total === 0"
            title="Nie ma jeszcze aktualności"
            description="Dodaj pierwszy wpis. Domyślnie pozostanie ukryty."
        />
        <div v-else class="space-y-4" :aria-busy="form.processing">
            <p class="text-sm text-muted-foreground">
                Wpisów: {{ posts.total }}
            </p>
            <article
                v-for="post in posts.data"
                :key="post.id"
                class="grid min-w-0 gap-4 rounded-2xl border border-border bg-card p-4 sm:grid-cols-[128px_minmax(0,1fr)] sm:p-5"
            >
                <ContentImage
                    v-if="post.image"
                    :src="post.image.src"
                    :alt="post.image.alt"
                    class="aspect-video w-full rounded-xl sm:aspect-square"
                />
                <div
                    v-else
                    class="flex aspect-video items-center justify-center rounded-xl bg-muted text-sm text-muted-foreground sm:aspect-square"
                >
                    Bez zdjęcia
                </div>
                <div class="min-w-0 space-y-3">
                    <div class="flex flex-wrap items-center gap-3">
                        <span
                            class="rounded-full bg-muted px-3 py-1 text-xs font-semibold"
                            >{{ labels[post.status] }}</span
                        ><time
                            v-if="post.publishedAt"
                            :datetime="post.publishedAt"
                            class="text-xs text-muted-foreground"
                            >{{ date(post.publishedAt) }}</time
                        >
                    </div>
                    <h2 class="text-xl font-bold break-words">
                        {{ post.title }}
                    </h2>
                    <p
                        v-if="post.excerpt"
                        class="line-clamp-3 text-sm text-muted-foreground"
                    >
                        {{ post.excerpt }}
                    </p>
                    <div class="flex flex-wrap gap-2">
                        <Button as-child variant="outline"
                            ><Link :href="NewsPostController.edit(post.id)"
                                >Edytuj</Link
                            ></Button
                        ><ActionButton
                            variant="outline"
                            :disabled="form.processing"
                            @click="visibility(post)"
                            >{{
                                post.is_published ? 'Ukryj' : 'Włącz publikację'
                            }}</ActionButton
                        ><Button
                            v-if="post.publicUrl"
                            as-child
                            variant="outline"
                            ><Link :href="post.publicUrl"
                                >Zobacz na stronie</Link
                            ></Button
                        ><ActionButton
                            variant="outline"
                            :disabled="form.processing"
                            @click="
                                selected = post;
                                form.clearErrors();
                                confirmOpen = true;
                            "
                            >Usuń</ActionButton
                        >
                    </div>
                </div>
            </article>
            <Pagination :links="posts.links" />
        </div>
        <ConfirmDialog
            v-model:open="confirmOpen"
            title="Usunąć aktualność?"
            :description="`Wpis „${selected?.title ?? ''}” i jego zdjęcie zostaną usunięte. Tej operacji nie można cofnąć.`"
            confirm-label="Usuń aktualność"
            destructive
            :processing="form.processing"
            :error="feedback"
            @confirm="remove"
        />
    </div>
</template>
