<script setup>
import { computed } from 'vue';
import NoImagePlaceholder from './NoImagePlaceholder.vue';

const props = defineProps({
    product: {
        type: Object,
        required: true,
    },
});

const titleParts = computed(() => String(props.product.name || '').split('&'));
</script>

<template>
    <section class="grid items-stretch gap-6 border-b border-[#c9c1b4] py-8 lg:grid-cols-[minmax(0,1fr)_400px] lg:gap-8 lg:py-10">
        <div class="relative order-2 grid h-[360px] w-full place-items-center overflow-hidden border border-[#d8d0c3] bg-[#eee8dd] p-0 sm:h-[480px] lg:order-1 lg:h-[760px]">
            <img
                v-if="product.imageUrl"
                :src="product.imageUrl"
                :alt="product.name"
                class="absolute inset-0 size-full object-contain object-center p-4"
            >
            <NoImagePlaceholder v-else />
        </div>

        <aside class="order-1 flex max-h-[760px] flex-col overflow-hidden border border-[#c9c1b4] bg-[#f5f3ee] p-5 sm:p-6 lg:order-2 lg:h-[760px]">
            <p class="border-b border-[#c9c1b4] pb-4 text-[10px] font-black uppercase tracking-[0.24em] text-[#6f746d]">Gælder nu</p>

            <div class="min-h-0 flex-1 py-6 text-center sm:text-left">
                <p class="mb-3 text-[10px] font-black uppercase tracking-[0.28em] text-[#6f746d]">Tilbud</p>
                <h1 class="font-serif text-4xl font-bold leading-[0.92] text-[#18251e] sm:text-5xl">
                    <template v-for="(part, index) in titleParts" :key="`${part}-${index}`">
                        <span>{{ part }}</span>
                        <span v-if="index < titleParts.length - 1" class="font-sans text-[0.78em] font-black tracking-normal">&amp;</span>
                    </template>
                </h1>
                <div v-if="product.description" class="mt-4 overflow-hidden">
                    <p class="line-clamp-6 text-sm italic leading-6 text-[#4a4a4a]">
                        {{ product.description }}
                    </p>
                </div>
            </div>

            <div class="mt-auto divide-y divide-[#d8d0c3] border-y border-[#d8d0c3] bg-[#fbf9f4]">
                <dl class="grid gap-1 py-4">
                    <dt class="px-4 text-[10px] font-black uppercase tracking-[0.22em] text-[#6f746d]">Gælder til og med</dt>
                    <dd class="px-4 text-sm font-extrabold text-[#18251e]">{{ product.currentOffer.validUntil }}</dd>
                </dl>
                <dl class="grid gap-2 py-4">
                    <dt class="px-4 text-[10px] font-black uppercase tracking-[0.22em] text-[#6f746d]">Tilbudspris</dt>
                    <dd class="px-4 font-sans text-7xl font-extrabold leading-none tracking-[-0.06em] text-[#b3261e]">
                        {{ product.currentOffer.price }}
                        <span class="text-sm tracking-normal">DKK</span>
                    </dd>
                </dl>
                <div :class="['grid gap-4 py-4', product.currentOffer.normalPrice ? 'grid-cols-2' : 'grid-cols-1']">
                    <dl class="px-4">
                        <dt class="text-[10px] font-black uppercase tracking-[0.22em] text-[#6f746d]">Enhedspris</dt>
                        <dd class="mt-1 text-sm font-extrabold text-[#18251e]">{{ product.currentOffer.unitPrice }}</dd>
                    </dl>
                    <dl v-if="product.currentOffer.normalPrice" class="border-l border-[#d8d0c3] px-4">
                        <dt class="text-[10px] font-black uppercase tracking-[0.22em] text-[#6f746d]">Normalpris</dt>
                        <dd class="mt-1 text-sm font-extrabold text-[#18251e]">{{ product.currentOffer.normalPrice }}</dd>
                    </dl>
                </div>
            </div>

            <button
                type="button"
                disabled
                class="mt-6 inline-flex w-full cursor-not-allowed items-center justify-center gap-3 bg-[#173124] px-5 py-4 text-xs font-black uppercase tracking-[0.2em] text-[#fbf9f4] opacity-60 transition focus-visible:ring-2 focus-visible:ring-[#173124] focus-visible:ring-offset-2 focus-visible:ring-offset-[#fbf9f4]"
            >
                <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path d="M8 6h13" />
                    <path d="M8 12h13" />
                    <path d="M8 18h13" />
                    <path d="M3 6h.01" />
                    <path d="M3 12h.01" />
                    <path d="M3 18h.01" />
                </svg>
                Tilføj til indkøbsliste
            </button>
            <p class="mt-3 text-center text-xs font-semibold text-[#6f746d]">Indkøbslister kommer senere.</p>
        </aside>
    </section>
</template>
