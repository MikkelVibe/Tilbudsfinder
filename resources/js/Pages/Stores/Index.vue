<script setup>
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import SiteHeader from '../../Components/SiteHeader.vue';

const props = defineProps({
    stores: {
        type: Array,
        default: () => [],
    },
    summary: {
        type: Object,
        required: true,
    },
});

const summaryLine = computed(() => {
    const storeCount = props.summary.storeCount ?? props.stores.length;
    const offerCount = props.summary.offerCount ?? 0;

    return `${storeCount} kæder · ${offerCount} aktive tilbud`;
});
</script>

<template>
    <main class="min-h-screen bg-[#fbf9f4] text-[#1f2a24]">
        <SiteHeader />

        <div class="mx-auto max-w-[1200px] px-6 py-8 lg:py-12">
            <section class="border-t-2 border-b border-[#111111] py-5">
                <div class="flex flex-wrap items-end justify-between gap-4">
                    <div>
                        <p class="text-xs font-extrabold uppercase tracking-[0.24em] text-[#173124]">Butiksoversigt</p>
                        <h1 class="mt-4 font-serif text-5xl font-bold leading-none text-[#18251e] lg:text-6xl">
                            Butikker og Kæder
                        </h1>
                    </div>
                    <p class="text-right text-xs font-extrabold uppercase tracking-[0.18em] text-[#6f746d]">
                        {{ summaryLine }}
                    </p>
                </div>

                <p class="mt-4 max-w-2xl text-base font-medium leading-7 text-[#5d655f]">
                    Gennemse aktuelle tilbud fra danske dagligvarekæder, eller vælg en butik for at se dens aktive tilbud.
                </p>
            </section>

            <section class="py-7">
                <div v-if="stores.length" class="grid gap-5 md:grid-cols-2 xl:grid-cols-4">
                    <template v-for="store in stores" :key="store.slug">
                        <Link
                            v-if="store.isActive"
                            :href="store.href"
                            prefetch
                            class="group relative flex min-h-[245px] flex-col border border-[#111111] bg-white p-4 transition hover:bg-[#f5f3ee] focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-[#173124]"
                        >
                            <div class="border-b border-[#e3ddd3] pb-3">
                                <div class="grid h-14 w-44 place-items-start overflow-hidden">
                                    <span
                                        v-if="store.logoUrl"
                                        class="grid h-12 w-40 place-items-start overflow-hidden"
                                    >
                                        <span :class="['inline-grid h-11 w-fit max-w-40 place-items-center overflow-hidden', store.logoNeedsBackdrop ? 'bg-[#111111]' : '']">
                                            <img
                                                :src="store.logoUrl"
                                                :alt="`${store.name} logo`"
                                                class="h-11 w-auto max-w-40 object-contain object-left grayscale contrast-125"
                                            >
                                        </span>
                                    </span>
                                </div>
                                <p class="mt-2 line-clamp-2 min-h-[2.4rem] break-words text-[1.35rem] font-black uppercase leading-[1.05] tracking-normal text-[#111111]">
                                    {{ store.name }}
                                </p>
                            </div>

                            <p class="mt-3 inline-flex w-fit bg-[#b3261e] px-2 py-1 text-[11px] font-black uppercase tracking-normal text-white">
                                {{ store.offerCountLabel }}
                            </p>

                            <div class="mt-6 border-t border-[#c9c1b4] pt-4">
                                <div class="grid grid-cols-[1fr_auto] gap-4 text-sm font-bold">
                                    <p class="text-[10px] font-black uppercase tracking-[0.14em] text-[#6f746d]">Tilbud lige nu</p>
                                    <p class="text-[#111111]">{{ store.offerCount }}</p>
                                </div>
                            </div>

                            <span class="mt-auto pt-5 text-[11px] font-black uppercase tracking-[0.16em] text-[#173124] transition group-hover:text-[#b3261e]">
                                Se tilbud
                            </span>
                        </Link>

                        <article
                            v-else
                            class="relative flex min-h-[245px] flex-col border border-[#d8d0c3] bg-[#f5f3ee] p-4 text-[#8d8a83]"
                        >
                            <div class="border-b border-[#ddd6ca] pb-3">
                                <div class="grid h-14 w-44 place-items-start overflow-hidden">
                                    <span
                                        v-if="store.logoUrl"
                                        class="grid h-12 w-40 place-items-start overflow-hidden"
                                    >
                                        <span :class="['inline-grid h-11 w-fit max-w-40 place-items-center overflow-hidden', store.logoNeedsBackdrop ? 'bg-[#111111]' : '']">
                                            <img
                                                :src="store.logoUrl"
                                                :alt="`${store.name} logo`"
                                                class="h-11 w-auto max-w-40 object-contain object-left grayscale opacity-55"
                                            >
                                        </span>
                                    </span>
                                </div>
                                <p class="mt-2 line-clamp-2 min-h-[2.4rem] break-words text-[1.35rem] font-black uppercase leading-[1.05] tracking-normal">
                                    {{ store.name }}
                                </p>
                            </div>

                            <p class="mt-3 inline-flex w-fit bg-[#a6a29a] px-2 py-1 text-[11px] font-black uppercase tracking-normal text-white">
                                Ingen aktive tilbud i dag
                            </p>

                            <div class="mt-8 border-t border-[#d8d0c3] pt-4">
                                <p class="text-sm font-semibold italic">
                                    Venter på næste avisudgivelse.
                                </p>
                            </div>
                        </article>
                    </template>
                </div>

                <div v-else class="border border-[#c9c1b4] bg-[#f5f3ee] px-5 py-10 text-center">
                    <h2 class="font-serif text-3xl font-bold leading-none text-[#18251e]">Ingen butikker fundet</h2>
                    <p class="mt-3 text-sm font-semibold text-[#6f746d]">Der er ingen aktive butikskæder endnu.</p>
                </div>
            </section>

            <div class="border-t-4 border-[#111111]" aria-hidden="true" />
        </div>
    </main>
</template>
