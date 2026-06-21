<script setup>
import { Link } from '@inertiajs/vue3';
import NoImagePlaceholder from './NoImagePlaceholder.vue';

defineProps({
    offer: {
        type: Object,
        required: true,
    },
});
</script>

<template>
    <Link :href="`/tilbud/${offer.id}`" prefetch class="flex min-h-[280px] flex-col border border-[#c9c1b4] bg-[#f5f3ee] p-3 transition hover:border-[#173124] hover:bg-[#fbf9f4] focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-[#173124]">
        <div class="relative grid h-[220px] place-items-stretch overflow-hidden border border-[#d8d0c3] bg-[#fbf9f4] p-0">
            <img
                v-if="offer.imageUrl"
                :src="offer.imageUrl"
                :alt="offer.name"
                class="absolute inset-0 size-full object-contain object-center"
                loading="lazy"
            >
            <NoImagePlaceholder v-else compact />
        </div>

        <div class="mt-3 flex flex-1 flex-col border-t border-[#c9c1b4] pt-3">
            <p v-if="offer.store" class="mb-2 text-[10px] font-black uppercase tracking-[0.18em] text-[#173124]">{{ offer.store }}</p>
            <h3 class="line-clamp-2 text-base font-extrabold leading-tight text-[#18251e]">{{ offer.name }}</h3>
            <p class="mt-1 text-xs font-bold uppercase tracking-[0.12em] text-[#6f746d]">{{ offer.weight }}</p>
            <p class="mt-auto border-t border-[#d8d0c3] pt-3 font-sans text-5xl font-extrabold leading-none tracking-[-0.06em] text-[#b3261e]">
                {{ offer.price }}
                <span class="text-sm tracking-normal">DKK</span>
            </p>
        </div>
    </Link>
</template>
