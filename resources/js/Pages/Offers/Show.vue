<script setup>
import OfferClipping from '../../Components/OfferDetail/OfferClipping.vue';
import NutritionTable from '../../Components/OfferDetail/NutritionTable.vue';
import PriceHistoryGraph from '../../Components/OfferDetail/PriceHistoryGraph.vue';
import ProductHero from '../../Components/OfferDetail/ProductHero.vue';
import SiteHeader from '../../Components/SiteHeader.vue';

defineProps({
    product: {
        type: Object,
        required: true,
    },
    recommendations: {
        type: Array,
        default: () => [],
    },
});
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

            <div v-if="product.nutrition?.length" class="grid items-stretch gap-10 border-b border-[#c9c1b4] py-8 lg:grid-cols-2 lg:gap-12">
                <PriceHistoryGraph :history-data="product.history" />
                <NutritionTable :nutrition="product.nutrition" />
            </div>

            <div v-else class="border-b border-[#c9c1b4] py-8">
                <PriceHistoryGraph :history-data="product.history" />
            </div>

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
