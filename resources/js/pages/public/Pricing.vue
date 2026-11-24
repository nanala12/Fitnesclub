<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import PublicSeo from '@/components/public/PublicSeo.vue';
import EmptyState from '@/components/shared/EmptyState.vue';
import { Button } from '@/components/ui/button';
import { pricing, contact } from '@/routes/public';
import { create } from '@/routes/checkout';
import type { PublicMembershipPlan } from '@/types/plans';
import type { PriceCategory, PublicPriceCategory } from '@/types/pricing';
import type { ClubInfo } from '@/types/public';

const props = defineProps<{
    plans: PublicMembershipPlan[];
    priceCategories: PublicPriceCategory[];
    categories: PriceCategory[];
    featuredPlans: PublicMembershipPlan[];
}>();
const page = usePage<{ club: ClubInfo | null }>();
const selected = ref('all');
const visibleCategories = computed(() =>
    props.priceCategories.filter(
        (category) =>
            selected.value === 'all' || category.category === selected.value,
    ),
);
const filters = computed(() => [
    { value: 'all', label: 'Wszystkie' },
    ...props.categories,
]);
</script>
<template>
    <PublicSeo
        title="Cennik"
        description="Siłownia, trening personalny, fizjoterapia i zabiegi. Poznaj cennik usług i karnetów ActiveFit."
        :canonical="pricing.url()"
    />
    <div class="pricing-page">
        <section class="pricing-hero">
            <p class="pricing-eyebrow">ActiveFit / Cennik</p>
            <h1>Wybierz aktywność.<br />Sprawdź cenę.</h1>
            <p class="pricing-lead">
                Siłownia, trening personalny, fizjoterapia i zabiegi. Wszystkie
                najważniejsze ceny znajdziesz w jednym miejscu.
            </p>
            <span class="pricing-badge"
                >Przejrzysta oferta • bez zbędnych formalności</span
            >
        </section>
        <section
            id="cennik"
            aria-labelledby="price-list-title"
            class="pricing-section"
        >
            <p class="pricing-eyebrow">Pełna oferta</p>
            <h2 id="price-list-title">Poznaj cały cennik</h2>
            <p class="pricing-sub">
                Wybierz kategorię, aby znaleźć interesującą Cię usługę.
            </p>
            <div
                class="pricing-filters"
                role="group"
                aria-label="Kategorie cennika"
            >
                <button
                    v-for="filter in filters"
                    :key="filter.value"
                    type="button"
                    :aria-pressed="selected === filter.value"
                    :data-filter="filter.value"
                    aria-controls="price-categories"
                    class="pricing-filter"
                    @click="selected = filter.value"
                >
                    {{ filter.label }}
                </button>
            </div>
            <p class="sr-only" role="status" aria-live="polite">
                Widocznych kategorii: {{ visibleCategories.length }}
            </p>
            <div id="price-categories" class="pricing-categories">
                <article
                    v-for="category in visibleCategories"
                    :key="category.id"
                    class="pricing-category"
                    :data-category="category.category"
                >
                    <h3>{{ category.name }}</h3>
                    <dl>
                        <div
                            v-for="item in category.items"
                            :key="item.id"
                            class="pricing-row"
                        >
                            <dt>{{ item.name }}</dt>
                            <dd>
                                <span>{{ item.priceLabel }}</span
                                ><Link
                                    v-if="item.purchaseUrl"
                                    :href="item.purchaseUrl"
                                    class="ml-3 inline-flex min-h-11 items-center text-xs underline"
                                    >Kup karnet<span class="sr-only">
                                        {{ item.name }}</span
                                    ></Link
                                >
                            </dd>
                        </div>
                    </dl>
                </article>
            </div>
            <EmptyState
                v-if="priceCategories.length === 0"
                title="Cennik będzie dostępny wkrótce"
                description="Nie opublikowano jeszcze oferty. Skontaktuj się z klubem, aby zapytać o szczegóły."
            />
            <EmptyState
                v-else-if="visibleCategories.length === 0"
                title="Brak pozycji w tej kategorii"
                description="Wybierz inną kategorię lub wyświetl cały cennik."
                ><Button variant="outline" @click="selected = 'all'"
                    >Pokaż wszystkie</Button
                ></EmptyState
            >
        </section>
        <section class="pricing-info" aria-labelledby="price-contact-title">
            <h2 id="price-contact-title">
                Masz pytania o zajęcia lub zabiegi?
            </h2>
            <p>
                Skontaktuj się z nami. Chętnie pomożemy wybrać odpowiednią
                usługę. Przed zakupem sprawdź aktualną ofertę.
            </p>
            <Button v-if="page.props.club?.phoneHref" as-child class="mt-4"
                ><a :href="page.props.club.phoneHref"
                    >Zadzwoń: {{ page.props.club.phone }} →</a
                ></Button
            >
            <Button v-else as-child class="mt-4"
                ><Link :href="contact()">Kontakt z klubem →</Link></Button
            >
        </section>
        <section
            id="karnety"
            aria-labelledby="popular-plans-title"
            class="pricing-section"
        >
            <p class="pricing-eyebrow">Siłownia</p>
            <h2 id="popular-plans-title">Najpopularniejsze karnety</h2>
            <p class="pricing-sub">
                Wybierz opcję dopasowaną do częstotliwości i godzin treningu.
            </p>
            <div v-if="featuredPlans.length" class="pricing-plans">
                <article
                    v-for="(plan, index) in featuredPlans"
                    :key="plan.id"
                    class="pricing-plan"
                    :class="{ 'pricing-plan-emphasis': index === 1 }"
                >
                    <p class="pricing-eyebrow">
                        {{
                            plan.kind === 'time'
                                ? 'Karnet czasowy'
                                : 'Karnet z limitem wejść'
                        }}
                    </p>
                    <h3>{{ plan.name }}</h3>
                    <p v-if="plan.description" class="pricing-plan-description">
                        {{ plan.description }}
                    </p>
                    <p class="pricing-plan-price">{{ plan.priceLabel }}</p>
                    <p class="pricing-plan-unit">
                        {{
                            plan.kind === 'entries'
                                ? `Liczba wejść: ${plan.includedEntries} • bez ograniczenia czasowego`
                                : plan.durationMonths === 1
                                  ? 'za miesiąc'
                                  : `Liczba miesięcy: ${plan.durationMonths}`
                        }}
                    </p>
                    <Button as-child class="mt-auto w-full"
                        ><Link :href="create({ query: { plan: plan.id } })"
                            >Kup karnet →<span class="sr-only">
                                {{ plan.name }}</span
                            ></Link
                        ></Button
                    >
                </article>
            </div>
            <EmptyState
                v-else
                title="Wyróżnione karnety pojawią się wkrótce"
                description="Aktualne pozycje oferty znajdziesz w pełnym cenniku powyżej."
            />
            <p class="pricing-notice">
                Ważność karnetów czasowych rozpoczyna się przy pierwszej wizycie
                w klubie. Karnety wejściowe nie mają ograniczenia czasowego.
            </p>
        </section>
    </div>
</template>
<style scoped>
.pricing-hero {
    padding: 14px 0 62px;
}
.pricing-eyebrow {
    color: var(--color-primary);
    text-transform: uppercase;
    letter-spacing: 2px;
    font-size: 12px;
    font-weight: 800;
}
.pricing-hero h1 {
    font-size: clamp(36px, 5.2vw, 62px);
    line-height: 1.08;
    letter-spacing: -2.5px;
    max-width: 760px;
    margin: 17px 0 20px;
    font-weight: 800;
}
.pricing-lead {
    font-size: 18px;
    color: var(--color-muted-foreground);
    max-width: 720px;
}
.pricing-badge {
    display: inline-block;
    background: var(--color-secondary);
    border-radius: 999px;
    padding: 8px 16px;
    font-size: 12px;
    margin-top: 23px;
}
.pricing-section {
    padding: 26px 0 58px;
}
.pricing-section h2 {
    font-size: 32px;
    letter-spacing: -0.9px;
    margin: 0 0 9px;
    font-weight: 700;
}
.pricing-section > .pricing-eyebrow {
    margin-bottom: 5px;
}
.pricing-sub {
    margin: 0 0 27px;
    color: var(--color-muted-foreground);
}
.pricing-filters {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin: 26px 0 22px;
    padding-bottom: 4px;
}
.pricing-filter {
    max-width: 100%;
    border: 1px solid var(--color-border);
    border-radius: 999px;
    background: var(--color-card);
    padding: 12px 17px;
    cursor: pointer;
    font-size: 13px;
    font-weight: 650;
    min-height: 44px;
}
.pricing-filter[aria-pressed='true'] {
    background: var(--color-primary);
    border-color: var(--color-primary);
    color: var(--color-primary-foreground);
}
.pricing-filter:focus-visible {
    outline: 3px solid var(--color-ring);
    outline-offset: 3px;
}
.pricing-categories {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 18px;
}
.pricing-category {
    min-width: 0;
    background: var(--color-card);
    border: 1px solid var(--color-border);
    border-radius: 17px;
    padding: 26px;
}
.pricing-category h3 {
    font-size: 20px;
    font-weight: 700;
    margin: 0 0 16px;
    overflow-wrap: anywhere;
}
.pricing-row {
    display: flex;
    justify-content: space-between;
    align-items: baseline;
    gap: 18px;
    padding: 12px 0;
    border-top: 1px solid var(--color-secondary);
    font-size: 14px;
}
.pricing-row dt {
    color: var(--color-muted-foreground);
    min-width: 0;
    overflow-wrap: anywhere;
}
.pricing-row dd {
    font-size: 15px;
    font-weight: 700;
    text-align: right;
    max-width: 55%;
    flex-shrink: 0;
    overflow-wrap: anywhere;
}
.pricing-info {
    background: var(--color-secondary);
    border-radius: 18px;
    padding: 29px;
    margin: 13px 0 44px;
}
.pricing-info h2 {
    font-size: 23px;
    font-weight: 700;
}
.pricing-info p {
    color: var(--color-muted-foreground);
    margin: 9px 0 0;
    font-size: 14px;
}
.pricing-plans {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 20px;
}
.pricing-plan {
    min-width: 0;
    border: 1px solid var(--color-border);
    border-radius: 18px;
    background: var(--color-card);
    padding: 26px;
    display: flex;
    flex-direction: column;
    min-height: 319px;
}
.pricing-plan-emphasis {
    border: 2px solid var(--color-primary);
    background: var(--color-secondary);
}
.pricing-plan h3 {
    font-size: 21px;
    font-weight: 700;
    margin: 17px 0 8px;
    overflow-wrap: anywhere;
}
.pricing-plan-description {
    color: var(--color-muted-foreground);
    font-size: 14px;
    white-space: pre-line;
    overflow-wrap: anywhere;
}
.pricing-plan-price {
    font-weight: 800;
    font-size: 37px;
    margin: 24px 0 0;
    letter-spacing: -1px;
    overflow-wrap: anywhere;
}
.pricing-plan-unit {
    color: var(--color-muted-foreground);
    font-size: 13px;
    margin-bottom: 24px;
}
.pricing-notice {
    font-size: 13px;
    color: var(--color-muted-foreground);
    margin-top: 20px;
}
@media (max-width: 800px) {
    .pricing-hero {
        padding: 10px 0 40px;
    }
    .pricing-plans,
    .pricing-categories {
        grid-template-columns: 1fr;
    }
    .pricing-section {
        padding-bottom: 38px;
    }
    .pricing-section h2 {
        font-size: 28px;
    }
    .pricing-plan {
        min-height: 290px;
    }
    .pricing-hero h1 {
        letter-spacing: -1.5px;
    }
    .pricing-lead {
        font-size: 16px;
    }
}
@media (max-width: 480px) {
    .pricing-filter {
        flex: 1 1 auto;
    }
    .pricing-filters {
        gap: 9px;
    }
}
</style>
