<script setup>
import { Link, router } from '@inertiajs/vue3';
import { computed, nextTick, onUnmounted, reactive, ref, watch } from 'vue';
import SiteHeader from '../../Components/SiteHeader.vue';

const props = defineProps({
    filters: {
        type: Object,
        required: true,
    },
    grocers: {
        type: Array,
        default: () => [],
    },
    results: {
        type: Object,
        required: true,
    },
    sortOptions: {
        type: Array,
        default: () => [],
    },
    quickSearches: {
        type: Array,
        default: () => [],
    },
});

const form = reactive({
    q: props.filters.q || '',
    grocers: [...(props.filters.grocers || [])],
    sort: props.filters.sort || 'relevance',
    price_min: props.filters.price_min ?? '',
    price_max: props.filters.price_max ?? '',
});

const loadedImages = reactive({});
const displayedOffers = ref([...props.results.data]);
const paginationMeta = ref({ ...props.results.meta });
let searchTimeout = null;
let syncingFromProps = false;

const activeGrocers = computed(() => new Set(form.grocers));
const hasActiveFilters = computed(() => form.grocers.length > 0 || form.price_min !== '' || form.price_max !== '');
const selectedSortLabel = computed(() => props.sortOptions.find((option) => option.value === form.sort)?.label || 'Relevans');
const resultCountLabel = computed(() => {
    const total = props.results.meta.total;
    const query = form.q ? ` for '${form.q}'` : '';

    return `${total} ${total === 1 ? 'resultat' : 'resultater'}${query}`;
});

watch(form, () => {
    if (syncingFromProps) {
        return;
    }

    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => visit(), 180);
}, { deep: true });

onUnmounted(() => {
    clearTimeout(searchTimeout);
});

watch(() => form.q, () => {
    if (syncingFromProps) {
        return;
    }

    form.sort = 'relevance';
});

watch(() => props.results, (results) => {
    if (results.meta.current_page > 1 && results.meta.current_page === paginationMeta.value.current_page + 1) {
        displayedOffers.value = [...displayedOffers.value, ...results.data];
    } else {
        displayedOffers.value = [...results.data];
    }

    paginationMeta.value = { ...results.meta };
}, { deep: true });

watch(() => props.filters, async (filters) => {
    syncingFromProps = true;
    form.q = filters.q || '';
    form.grocers = [...(filters.grocers || [])];
    form.sort = filters.sort || 'relevance';
    form.price_min = filters.price_min ?? '';
    form.price_max = filters.price_max ?? '';

    await nextTick();
    syncingFromProps = false;
}, { deep: true });

function visit(page = 1) {
    router.get('/tilbud', cleanedParams(page), {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        only: ['filters', 'results', 'grocers'],
    });
}

function cleanedParams(page = 1) {
    const params = {};

    if (form.q.trim() !== '') {
        params.q = form.q.trim();
    }

    if (form.grocers.length > 0) {
        params.grocers = form.grocers;
    }

    if (form.sort !== 'relevance') {
        params.sort = form.sort;
    }

    if (form.price_min !== '') {
        params.price_min = form.price_min;
    }

    if (form.price_max !== '') {
        params.price_max = form.price_max;
    }

    if (page > 1) {
        params.page = page;
    }

    return params;
}

function toggleGrocer(slug) {
    if (activeGrocers.value.has(slug)) {
        form.grocers = form.grocers.filter((selected) => selected !== slug);

        return;
    }

    form.grocers = [...form.grocers, slug];
}

function setQuickSearch(term) {
    form.q = term;
    form.sort = 'relevance';
}

function clearFilters() {
    form.grocers = [];
    form.price_min = '';
    form.price_max = '';
}

function loadNextPage() {
    visit(paginationMeta.value.current_page + 1);
}

function markImageLoaded(key) {
    loadedImages[key] = true;
}

function submitSearch() {
    form.sort = 'relevance';
    visit();
}

function storeSummary(offer) {
    if (offer.productStoreCount <= 1) {
        return offer.grocerName;
    }

    return `${offer.productStoreCount} butikker`;
}
</script>

<template>
    <main class="min-h-screen bg-[#fbf9f4] text-[#1f2a24]">
        <SiteHeader />

        <div class="mx-auto grid max-w-[1220px] gap-8 px-5 py-7 lg:grid-cols-[230px_minmax(0,1fr)] lg:px-8 lg:py-10">
            <aside class="space-y-7 lg:sticky lg:top-6 lg:self-start">
                <section>
                    <h2 class="text-xs font-extrabold uppercase tracking-[0.18em] text-[#173124]">Butik</h2>
                    <div class="mt-3 space-y-2">
                        <label
                            v-for="grocer in grocers"
                            :key="grocer.slug"
                            class="flex cursor-pointer items-center gap-2 text-sm font-semibold text-[#1f2a24]"
                        >
                            <input
                                type="checkbox"
                                :checked="activeGrocers.has(grocer.slug)"
                                class="size-3.5 border-[#a8a094] text-[#173124] focus:ring-[#173124]"
                                @change="toggleGrocer(grocer.slug)"
                            >
                            <span class="min-w-0 flex-1">{{ grocer.name }}</span>
                            <span class="text-xs font-bold text-[#73786f]">{{ grocer.count }}</span>
                        </label>
                    </div>
                </section>

                <div class="border-t border-[#d4cbbd]" />

                <section>
                    <h2 class="text-xs font-extrabold uppercase tracking-[0.18em] text-[#173124]">Prisinterval</h2>
                    <div class="mt-3 grid gap-2">
                        <div class="grid grid-cols-[34px_1fr_28px] items-center border border-[#c9c1b4] bg-white">
                            <label for="price-min" class="px-2 text-xs font-extrabold uppercase tracking-[0.12em] text-[#697069]">Fra</label>
                            <input
                                id="price-min"
                                v-model="form.price_min"
                                inputmode="decimal"
                                type="number"
                                min="0"
                                step="0.01"
                                class="h-10 min-w-0 bg-transparent px-2 text-sm font-bold text-[#18251e] outline-none"
                            >
                            <span class="pr-2 text-right text-xs font-bold text-[#697069]">kr</span>
                        </div>
                        <div class="grid grid-cols-[34px_1fr_28px] items-center border border-[#c9c1b4] bg-white">
                            <label for="price-max" class="px-2 text-xs font-extrabold uppercase tracking-[0.12em] text-[#697069]">Til</label>
                            <input
                                id="price-max"
                                v-model="form.price_max"
                                inputmode="decimal"
                                type="number"
                                min="0"
                                step="0.01"
                                class="h-10 min-w-0 bg-transparent px-2 text-sm font-bold text-[#18251e] outline-none"
                            >
                            <span class="pr-2 text-right text-xs font-bold text-[#697069]">kr</span>
                        </div>
                    </div>
                    <button
                        v-if="hasActiveFilters"
                        type="button"
                        class="mt-4 text-xs font-extrabold uppercase tracking-[0.16em] text-[#173124] hover:text-[#b3261e]"
                        @click="clearFilters"
                    >
                        Ryd filtre
                    </button>
                </section>
            </aside>

            <section class="min-w-0">
                <form class="border-b-2 border-[#111111] pb-5" @submit.prevent="submitSearch">
                    <label for="offer-search-page" class="sr-only">Søg tilbud</label>
                    <div class="flex min-h-[58px] border-2 border-[#173124] bg-white p-1.5">
                        <input
                            id="offer-search-page"
                            v-model="form.q"
                            type="search"
                            placeholder="Søg efter varer"
                            class="min-w-0 flex-1 bg-transparent px-3 text-xl font-bold text-[#18251e] outline-none placeholder:text-[#8a8f88]"
                        >
                        <button type="submit" class="bg-[#173124] px-6 text-sm font-extrabold uppercase tracking-[0.18em] text-[#fbf9f4] hover:bg-[#0f241a]">
                            Søg
                        </button>
                    </div>

                    <div class="mt-4 flex flex-wrap items-center justify-between gap-3">
                        <div class="flex flex-wrap gap-2">
                            <button
                                v-for="term in quickSearches"
                                :key="term"
                                type="button"
                                class="border border-[#c9c1b4] bg-[#f5f3ee] px-3 py-1.5 text-sm font-bold text-[#173124] transition hover:border-[#173124] hover:bg-white"
                                @click="setQuickSearch(term)"
                            >
                                {{ term }}
                            </button>
                        </div>

                        <label class="grid min-w-[210px] grid-cols-[auto_1fr] items-center border border-[#c9c1b4] bg-white text-xs font-extrabold uppercase tracking-[0.14em] text-[#173124]">
                            <span class="border-r border-[#d8d0c3] px-3 py-2">Sorter</span>
                            <select
                                v-model="form.sort"
                                class="h-9 min-w-0 appearance-none bg-transparent px-3 text-sm font-bold normal-case tracking-normal text-[#18251e] outline-none"
                                :aria-label="`Sorter efter ${selectedSortLabel}`"
                            >
                                <option v-for="option in sortOptions" :key="option.value" :value="option.value">
                                    {{ option.label }}
                                </option>
                            </select>
                        </label>
                    </div>
                </form>

                <div class="mt-6 flex flex-wrap items-end justify-between gap-4">
                    <div>
                        <h1 class="font-serif text-3xl font-bold tracking-[-0.03em] text-[#18251e] sm:text-4xl">{{ resultCountLabel }}</h1>
                        <div v-if="form.grocers.length" class="mt-2 flex flex-wrap items-center gap-2 text-[11px] font-extrabold uppercase tracking-[0.12em]">
                            <span class="bg-[#dbe7f2] px-2 py-1 text-[#173124]">
                                Filtreret efter: {{ form.grocers.join(', ') }}
                            </span>
                            <button type="button" class="text-[#73786f] hover:text-[#b3261e]" @click="clearFilters">Ryd alle</button>
                        </div>
                    </div>
                </div>

                <div v-if="displayedOffers.length" class="mt-7 grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                    <Link
                        v-for="offer in displayedOffers"
                        :key="offer.id"
                        :href="`/tilbud/${offer.id}`"
                        prefetch
                        class="block border border-[#c9c1b4] bg-white transition hover:border-[#173124] focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-[#173124]"
                    >
                        <div :class="['relative m-4 grid h-[220px] place-items-center overflow-hidden border border-[#d8d0c3] bg-[#f5f3ee]']">
                            <template v-if="offer.imageUrl">
                                <div
                                    v-if="!loadedImages[offer.id]"
                                    class="image-skeleton absolute inset-0"
                                    aria-hidden="true"
                                />
                                <img
                                    :src="offer.imageUrl"
                                    :alt="offer.title"
                                    :class="[
                                        'absolute inset-0 size-full object-contain object-center p-4 transition-opacity duration-200',
                                        loadedImages[offer.id] ? 'opacity-100' : 'opacity-0',
                                    ]"
                                    loading="lazy"
                                    @load="markImageLoaded(offer.id)"
                                >
                            </template>
                            <div v-else class="grid size-28 place-items-center rounded-full border-2 border-[#173124] bg-[#fbf9f4] px-3 text-center font-serif text-lg font-bold leading-tight text-[#173124]">
                                {{ offer.fallbackLabel }}
                            </div>
                        </div>

                        <div class="px-4 pb-4">
                            <div class="flex min-h-7 items-center justify-between gap-3 text-[10px] font-extrabold uppercase tracking-[0.1em] text-[#173124]">
                                <span class="border-2 border-[#173124] px-2 py-0.5 leading-none">{{ storeSummary(offer) }}</span>
                                <span class="text-[#697069]">Gælder til {{ offer.validUntil }}</span>
                            </div>

                            <h2 class="mt-3 line-clamp-2 min-h-[3rem] text-lg font-extrabold leading-tight text-[#18251e]">{{ offer.title }}</h2>
                            <p class="mt-1 text-sm font-semibold uppercase tracking-[0.08em] text-[#6f746d]">{{ offer.amount || 'UKENDT MÆNGDE' }}</p>

                            <div class="mt-4 border-y border-[#d8d0c3] py-3">
                                <p class="font-sans text-5xl font-extrabold leading-none tracking-[-0.06em] text-[#b3261e]">
                                    {{ offer.price }}
                                    <span class="text-sm tracking-normal">DKK</span>
                                </p>
                            </div>

                            <div class="mt-3 grid min-h-10 grid-cols-[1fr_auto] items-start gap-3 text-xs font-bold uppercase tracking-[0.12em] text-[#5d655f]">
                                <span class="leading-5">{{ offer.productStoreCount > 1 ? `Bedste pris hos ${offer.grocerName}` : `Gælder til ${offer.validUntil}` }}</span>
                                <span class="whitespace-nowrap text-right leading-5">{{ offer.unitPrice || 'UKENDT/STK' }}</span>
                            </div>
                        </div>
                    </Link>
                </div>

                <div v-else class="mt-8 border-y border-[#c9c1b4] py-10 text-center">
                    <h2 class="font-serif text-3xl font-bold tracking-[-0.03em]">Ingen tilbud fundet</h2>
                    <p class="mt-2 text-sm font-semibold text-[#697069]">Prøv en bredere søgning eller færre filtre.</p>
                </div>

                <div v-if="paginationMeta.current_page < paginationMeta.last_page" class="mt-8 text-center">
                    <button
                        type="button"
                        class="inline-flex h-11 items-center justify-center bg-[#171717] px-7 text-xs font-extrabold uppercase tracking-[0.12em] text-white hover:bg-[#173124]"
                        @click="loadNextPage"
                    >
                        Indlæs flere tilbud
                    </button>
                    <p class="mt-3 text-xs font-semibold text-[#697069]">
                        Viser {{ displayedOffers.length }} af {{ paginationMeta.total }} resultater
                    </p>
                </div>
            </section>
        </div>
    </main>
</template>
