<script setup>
import { Link } from '@inertiajs/vue3';
import { nextTick, onMounted, ref, watch } from 'vue';
import OfferClipping from '../../Components/OfferDetail/OfferClipping.vue';
import NutritionTable from '../../Components/OfferDetail/NutritionTable.vue';
import PriceHistoryGraph from '../../Components/OfferDetail/PriceHistoryGraph.vue';
import ProductHero from '../../Components/OfferDetail/ProductHero.vue';
import SiteHeader from '../../Components/SiteHeader.vue';

const props = defineProps({
    product: {
        type: Object,
        required: true,
    },
    recommendations: {
        type: Array,
        default: () => [],
    },
    currentProductPrices: {
        type: Array,
        default: () => [],
    },
});

const descriptionExpanded = ref(false);
const descriptionBody = ref(null);
const descriptionCanExpand = ref(false);

async function measureDescriptionOverflow() {
    await nextTick();

    if (!descriptionBody.value) {
        descriptionCanExpand.value = false;

        return;
    }

    descriptionCanExpand.value = descriptionBody.value.scrollHeight > descriptionBody.value.clientHeight + 2;

    if (!descriptionCanExpand.value) {
        descriptionExpanded.value = false;
    }
}

onMounted(measureDescriptionOverflow);
watch(() => props.product.fullDescription, measureDescriptionOverflow);
</script>

<template>
    <main class="min-h-screen bg-[#fbf9f4] text-[#1f2a24]">
        <SiteHeader />

        <div class="mx-auto max-w-[1200px] px-6 py-8 lg:py-12">
            <div class="mb-6 border-t-2 border-[#111111] pt-4">
                <div class="flex items-center justify-between gap-4 border-b border-[#111111] pb-3 text-xs font-extrabold uppercase tracking-[0.24em] text-[#173124]">
                    <span>Tilbud</span>
                    <span class="text-right">{{ product.currentOffer.validUntil }}</span>
                </div>
            </div>

            <ProductHero :product="product" />

            <section v-if="product.fullDescription" class="border-b border-[#c9c1b4] py-8">
                <div class="mb-4 flex items-end justify-between gap-6 border-b-2 border-[#173124] pb-3">
                    <h2 class="font-serif text-3xl font-bold leading-none tracking-[-0.03em] text-[#18251e]">Produktbeskrivelse</h2>
                    <span class="text-[10px] font-black uppercase tracking-[0.22em] text-[#6f746d]">Fra kilden</span>
                </div>

                <article class="bg-[#f5f3ee]">
                    <div
                        ref="descriptionBody"
                        :class="[
                            'relative overflow-hidden px-5 py-5 sm:px-6',
                            descriptionExpanded ? 'max-h-none md:columns-2 md:gap-12' : 'max-h-[340px]',
                        ]"
                    >
                        <p class="whitespace-pre-line text-[15px] font-semibold leading-8 text-[#35423a]">
                            {{ product.fullDescription }}
                        </p>
                        <div
                            v-if="descriptionCanExpand && !descriptionExpanded"
                            class="pointer-events-none absolute inset-x-0 bottom-0 h-20 bg-linear-to-b from-transparent to-[#f5f3ee]"
                            aria-hidden="true"
                        />
                    </div>

                    <div v-if="descriptionCanExpand" class="flex justify-center border-t border-[#d8d0c3] px-5 py-3">
                        <button
                            type="button"
                            class="inline-flex min-h-10 items-center justify-center border-2 border-[#173124] bg-[#173124] px-5 text-xs font-black uppercase tracking-[0.18em] text-[#fbf9f4] transition hover:bg-[#fbf9f4] hover:text-[#173124]"
                            @click="descriptionExpanded = !descriptionExpanded"
                        >
                            {{ descriptionExpanded ? 'Vis mindre' : 'Læs hele beskrivelsen' }}
                        </button>
                    </div>
                </article>
            </section>

            <div v-if="product.nutrition?.length" class="grid items-stretch gap-10 border-b border-[#c9c1b4] py-8 lg:grid-cols-2 lg:gap-12">
                <PriceHistoryGraph :history-data="product.history" />
                <NutritionTable :nutrition="product.nutrition" />
            </div>

            <div v-else class="border-b border-[#c9c1b4] py-8">
                <PriceHistoryGraph :history-data="product.history" />
            </div>

            <section v-if="currentProductPrices.length > 1" class="border-b border-[#c9c1b4] py-8">
                <div class="mb-6 flex items-end justify-between gap-6 border-b-2 border-[#173124] pb-3">
                    <h2 class="font-serif text-3xl font-bold leading-none tracking-[-0.03em] text-[#18251e]">Priser lige nu</h2>
                    <span class="text-[10px] font-black uppercase tracking-[0.2em] text-[#6f746d]">Samme vare</span>
                </div>

                <div class="divide-y divide-[#d8d0c3] border-y border-[#c9c1b4] bg-[#f5f3ee]">
                    <Link
                        v-for="price in currentProductPrices"
                        :key="price.id"
                        :href="`/tilbud/${price.id}`"
                        prefetch
                        class="grid gap-3 px-4 py-4 text-sm font-bold transition hover:bg-white hover:text-[#b3261e] sm:grid-cols-[1fr_auto_auto] sm:items-center"
                    >
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="text-base font-extrabold text-[#18251e]">{{ price.store }}</span>
                                <span v-if="price.isCurrent" class="border border-[#173124] px-2 py-0.5 text-[10px] font-black uppercase tracking-[0.16em] text-[#173124]">Denne</span>
                            </div>
                            <p class="mt-1 text-xs font-extrabold uppercase tracking-[0.12em] text-[#6f746d]">Gælder til {{ price.validUntil }}</p>
                        </div>
                        <p class="text-3xl font-extrabold leading-none tracking-[-0.05em] text-[#b3261e]">
                            {{ price.price }}
                            <span class="text-sm tracking-normal">DKK</span>
                        </p>
                        <p class="text-xs font-extrabold uppercase tracking-[0.12em] text-[#5d655f] sm:min-w-28 sm:text-right">{{ price.unitPrice || 'Ukendt enhedspris' }}</p>
                    </Link>
                </div>
            </section>

            <section class="py-10">
                <div class="mb-6 flex items-end justify-between gap-6 border-b-2 border-[#173124] pb-3">
                    <h2 class="font-serif text-3xl font-bold leading-none tracking-[-0.03em] text-[#18251e]">Lignende tilbud</h2>
                    <span class="text-[10px] font-black uppercase tracking-[0.2em] text-[#6f746d]">Matcher søgning</span>
                </div>

                <div v-if="recommendations.length" class="grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
                    <OfferClipping
                        v-for="offer in recommendations"
                        :key="offer.id"
                        :offer="offer"
                    />
                </div>

                <div v-else class="border border-[#c9c1b4] bg-[#f5f3ee] p-6 text-sm font-semibold text-[#6f746d]">
                    Ingen lignende tilbud fundet endnu.
                </div>
            </section>
        </div>
    </main>
</template>
