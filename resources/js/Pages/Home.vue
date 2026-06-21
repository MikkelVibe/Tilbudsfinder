<script setup>
import { Link } from '@inertiajs/vue3';
import { reactive, ref } from 'vue';
import SiteHeader from '../Components/SiteHeader.vue';

const props = defineProps({
    popularOffers: {
        type: Array,
        default: () => [],
    },
    latestOffers: {
        type: Array,
        default: () => [],
    },
    stores: {
        type: Array,
        default: () => [],
    },
    allStoreSlugs: {
        type: Array,
        default: () => [],
    },
    enabledStoreCount: {
        type: Number,
        default: 0,
    },
});

const quickSearches = ['mælk', 'kaffe', 'kylling', 'smør', 'pasta'];
const loadedImages = reactive({});
const searchQuery = ref('');

function markImageLoaded(key) {
    loadedImages[key] = true;
}

function searchHref(term) {
    return `/tilbud?q=${encodeURIComponent(term)}`;
}

function storeHref(store) {
    return `/tilbud?grocers[]=${encodeURIComponent(store.slug)}`;
}

function allStoresHref() {
    return '/butikker';
}
</script>

<template>
    <main class="min-h-screen bg-[#fbf9f4] text-[#1f2a24]">
        <SiteHeader />

        <div class="mx-auto grid max-w-[1200px] gap-8 px-6 py-8 lg:grid-cols-[minmax(0,2fr)_minmax(320px,1fr)] lg:py-12">
            <section class="min-w-0">
                <div id="search" class="border-t-2 border-b border-[#111111] py-4">
                    <div class="flex items-center gap-4 border-b border-[#111111] pb-3 text-xs font-extrabold uppercase tracking-[0.24em] text-[#173124]">
                        <span>Dagens udgave</span>
                    </div>

                    <div class="mt-7 max-w-3xl">
                        <h1 class="font-serif text-5xl font-bold leading-[0.95] tracking-[-0.04em] text-[#18251e] lg:text-6xl">
                            Find de bedste priser.
                        </h1>
                        <p class="mt-4 max-w-2xl text-base leading-7 text-[#5d655f]">
                            Søg på tværs af ugens tilbud og find hurtigt den bedste pris i de butikker, du handler i.
                        </p>
                    </div>

                    <form class="mt-8" action="/tilbud" method="get">
                        <label for="offer-search" class="sr-only">Søg efter varer</label>
                        <div class="relative flex items-center gap-2 border-2 border-[#173124] bg-white p-1.5">
                            <span class="pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 text-[#173124]" aria-hidden="true">
                                <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="m21 21-4.35-4.35" />
                                    <circle cx="11" cy="11" r="7" />
                                </svg>
                            </span>
                            <input
                                id="offer-search"
                                v-model="searchQuery"
                                name="q"
                                type="search"
                                placeholder="Søg efter varer"
                                class="min-w-0 flex-1 bg-transparent py-3 pl-11 pr-2 text-lg font-semibold text-[#18251e] outline-none placeholder:text-[#8a8f88]"
                            >
                            <button type="submit" class="hidden self-stretch bg-[#173124] px-6 text-sm font-extrabold uppercase tracking-[0.18em] text-[#fbf9f4] transition hover:bg-[#0f241a] sm:block">
                                Søg
                            </button>
                        </div>

                        <div class="mt-4 flex flex-wrap gap-2">
                            <Link
                                v-for="term in quickSearches"
                                :key="term"
                                :href="searchHref(term)"
                                class="border border-[#c9c1b4] bg-[#f5f3ee] px-3 py-1.5 text-sm font-bold text-[#173124] transition hover:border-[#173124] hover:bg-white"
                            >
                                {{ term }}
                            </Link>
                        </div>
                    </form>
                </div>

                <section class="mt-12">
                    <div class="mb-5 flex items-end justify-between gap-6 border-b-2 border-[#173124] pb-3">
                        <h2 class="font-serif text-3xl font-bold tracking-[-0.03em] text-[#18251e]">Populære tilbud</h2>
                        <button type="button" class="text-xs font-extrabold uppercase tracking-[0.2em] text-[#173124] hover:text-[#b3261e]">Se alle</button>
                    </div>

                    <div v-if="popularOffers.length" class="grid gap-4 md:grid-cols-3">
                        <Link
                            v-for="offer in popularOffers"
                            :key="offer.id"
                            :href="`/tilbud/${offer.id}`"
                            prefetch
                            class="block border border-[#c9c1b4] bg-white transition hover:border-[#173124] focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-[#173124]"
                        >
                            <div :class="['relative m-4 grid h-[220px] place-items-center overflow-hidden border border-[#d8d0c3]', offer.color]">
                                <template v-if="offer.imageUrl">
                                    <div
                                        v-if="!loadedImages[`popular-${offer.id}`]"
                                        class="image-skeleton absolute inset-0"
                                        aria-hidden="true"
                                    />
                                    <img
                                        :src="offer.imageUrl"
                                        :alt="offer.title"
                                        :class="[
                                            'absolute inset-0 size-full object-contain object-center transition-opacity duration-200',
                                            loadedImages[`popular-${offer.id}`] ? 'opacity-100' : 'opacity-0',
                                        ]"
                                        loading="lazy"
                                        @load="markImageLoaded(`popular-${offer.id}`)"
                                    >
                                </template>
                                <div v-else class="grid size-28 place-items-center rounded-full border-2 border-[#173124] bg-[#fbf9f4] px-3 text-center font-serif text-lg font-bold leading-tight text-[#173124]">
                                    {{ offer.fallbackLabel }}
                                </div>
                            </div>

                            <div class="px-4 pb-4">
                                <h3 class="line-clamp-2 min-h-[3rem] text-lg font-extrabold leading-tight text-[#18251e]">{{ offer.title }}</h3>
                                <p class="mt-1 text-sm font-semibold uppercase tracking-[0.08em] text-[#6f746d]">{{ offer.amount || 'UKENDT MÆNGDE' }}</p>

                                <div class="mt-4 border-y border-[#d8d0c3] py-3">
                                    <p class="font-sans text-5xl font-extrabold leading-none tracking-[-0.06em] text-[#b3261e]">
                                        {{ offer.price }}
                                        <span class="text-sm tracking-normal">DKK</span>
                                    </p>
                                </div>

                                <div class="mt-3 grid min-h-10 grid-cols-[1fr_auto] items-start gap-3 text-xs font-bold uppercase tracking-[0.12em] text-[#5d655f]">
                                    <span class="leading-5">Gælder til {{ offer.validUntil }}</span>
                                    <span class="whitespace-nowrap text-right leading-5">{{ offer.unitPrice || 'UKENDT/STK' }}</span>
                                </div>
                            </div>
                        </Link>
                    </div>

                    <div v-else class="border border-[#c9c1b4] bg-white p-6 text-sm font-semibold text-[#6f746d]">
                        Ingen aktive tilbud endnu.
                    </div>
                </section>

                <section class="mt-12">
                    <div class="mb-5 flex items-end justify-between gap-6 border-b-2 border-[#173124] pb-3">
                        <h2 class="font-serif text-3xl font-bold tracking-[-0.03em] text-[#18251e]">Senest tilføjede</h2>
                        <span class="text-xs font-extrabold uppercase tracking-[0.2em] text-[#5d655f]">Seneste import</span>
                    </div>

                    <div v-if="latestOffers.length" class="divide-y divide-[#c9c1b4] border-y border-[#c9c1b4]">
                        <Link v-for="offer in latestOffers" :key="offer.id" :href="`/tilbud/${offer.id}`" prefetch class="grid grid-cols-[72px_1fr_auto] gap-4 border border-transparent px-3 py-4 transition hover:border-[#173124] hover:bg-white hover:text-[#b3261e] focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-[#173124]">
                            <div :class="['relative grid size-[72px] place-items-center overflow-hidden border border-[#d8d0c3]', offer.color]">
                                <template v-if="offer.imageUrl">
                                    <div
                                        v-if="!loadedImages[`latest-${offer.id}`]"
                                        class="image-skeleton absolute inset-0"
                                        aria-hidden="true"
                                    />
                                    <img
                                        :src="offer.imageUrl"
                                        :alt="offer.title"
                                        :class="[
                                            'absolute inset-0 size-full object-contain object-center transition-opacity duration-200',
                                            loadedImages[`latest-${offer.id}`] ? 'opacity-100' : 'opacity-0',
                                        ]"
                                        loading="lazy"
                                        @load="markImageLoaded(`latest-${offer.id}`)"
                                    >
                                </template>
                                <span v-else class="px-2 text-center font-serif text-sm font-bold leading-tight text-[#173124]">{{ offer.fallbackLabel }}</span>
                            </div>

                            <div class="min-w-0">
                                <h3 class="line-clamp-1 text-lg font-extrabold text-[#18251e]">{{ offer.title }}</h3>
                                <p class="mt-1 text-sm font-semibold text-[#6f746d]">{{ offer.meta }}</p>
                            </div>
                            <p class="self-center text-3xl font-extrabold tracking-[-0.05em] text-[#b3261e]">{{ offer.price }}</p>
                        </Link>
                    </div>

                    <div v-else class="border-y border-[#c9c1b4] py-5 text-sm font-semibold text-[#6f746d]">
                        Ingen tilbud er tilføjet endnu.
                    </div>
                </section>
            </section>

            <aside class="space-y-8 lg:border-l lg:border-[#c9c1b4] lg:pl-8">
                <section id="stores">
                    <div class="border-b-2 border-[#173124] pb-3">
                        <h2 class="font-serif text-3xl font-bold tracking-[-0.03em] text-[#18251e]">Butikker</h2>
                    </div>

                    <div class="divide-y divide-[#c9c1b4] border-b border-[#c9c1b4]">
                        <Link
                            v-for="store in stores"
                            :key="store.slug"
                            :href="storeHref(store)"
                            prefetch
                            class="flex w-full items-center justify-between gap-4 py-3 text-left text-sm font-bold transition hover:text-[#b3261e]"
                        >
                            <span>{{ store.name }}</span>
                            <span class="text-xs font-extrabold uppercase tracking-[0.12em] text-[#6f746d]">{{ store.count }}</span>
                        </Link>
                        <div v-if="!stores.length" class="py-5 text-sm font-semibold text-[#6f746d]">
                            Ingen butikker med aktive tilbud endnu.
                        </div>
                    </div>

                    <Link :href="allStoresHref()" prefetch class="mt-4 inline-flex items-center gap-2 text-sm font-extrabold uppercase tracking-[0.18em] text-[#173124] hover:text-[#b3261e]">
                        Se alle {{ enabledStoreCount }} kæder
                        <svg class="size-3 translate-y-px" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="square" stroke-linejoin="miter" aria-hidden="true">
                            <path d="M3 8h9" />
                            <path d="m9 5 3 3-3 3" />
                        </svg>
                    </Link>
                </section>

                <section class="border-2 border-[#173124] bg-[#f5f3ee] p-5">
                    <p class="text-xs font-extrabold uppercase tracking-[0.24em] text-[#173124]">Sådan virker det</p>
                    <h2 class="mt-4 font-serif text-3xl font-bold leading-tight tracking-[-0.03em] text-[#18251e]">
                        Ugens tilbudsavis, gjort søgbar.
                    </h2>
                    <p class="mt-4 text-sm font-medium leading-6 text-[#5d655f]">
                        Vi samler aktuelle tilbud, så du kan søge efter varen i stedet for at bladre kæde for kæde.
                    </p>
                </section>
            </aside>
        </div>
    </main>
</template>
