<script setup>
import { nextTick, onMounted, ref } from 'vue';
import { getStatisticsConsent, isAnalyticsConfigured, setStatisticsConsent } from '../Support/analyticsConsent';

const isOpen = ref(false);
const hasSavedChoice = ref(false);
const rejectButton = ref(null);

function openSettings() {
    isOpen.value = true;
    nextTick(() => rejectButton.value?.focus());
}

function choose(statistics) {
    setStatisticsConsent(statistics);
    hasSavedChoice.value = true;
    isOpen.value = false;
}

onMounted(() => {
    if (!isAnalyticsConfigured()) {
        return;
    }

    hasSavedChoice.value = getStatisticsConsent() !== null;

    if (!hasSavedChoice.value) {
        openSettings();
    }
});
</script>

<template>
    <section
        v-if="isOpen"
        aria-labelledby="cookie-consent-title"
        class="fixed inset-x-0 bottom-0 z-50 border-t-4 border-[#173124] bg-[#fbf9f4] shadow-[0_-12px_40px_rgba(23,49,36,0.18)]"
    >
        <div class="mx-auto max-w-5xl px-6 py-6 lg:px-8">
            <div class="grid gap-5 md:grid-cols-[1fr_auto] md:items-end">
                <div class="max-w-3xl">
                    <p class="mb-2 text-xs font-extrabold uppercase tracking-[0.2em] text-[#b3261e]">Dit valg</p>
                    <h2 id="cookie-consent-title" class="font-serif text-2xl font-bold text-[#173124]">Må vi bruge statistik?</h2>
                    <p class="mt-3 text-sm leading-6 text-[#33473d]">
                        Vi bruger Google Analytics til at forstå, hvordan Tilbudsfinder bliver brugt. Statistik indlæses kun, hvis du accepterer. Vi gemmer altid dit valg lokalt i din browser, så vi kan huske det.
                    </p>
                    <details class="mt-3 text-sm text-[#33473d]">
                        <summary class="cursor-pointer font-bold underline decoration-2 underline-offset-4">Se detaljer</summary>
                        <p class="mt-2 leading-6">
                            Udbyder: Google. Formål: besøgsstatistik og forbedring af siden. Google Analytics kan sætte cookies som <code>_ga</code> og <code>_ga_TJ3MBH96Q0</code>. Du kan til enhver tid ændre eller trække dit valg tilbage via “Cookieindstillinger”.
                        </p>
                    </details>
                </div>

                <div class="grid min-w-64 grid-cols-1 gap-3 sm:grid-cols-2 md:grid-cols-1">
                    <button
                        ref="rejectButton"
                        type="button"
                        class="min-h-12 rounded-sm border-2 border-[#173124] bg-[#173124] px-5 py-3 text-sm font-extrabold text-white transition hover:bg-[#294c3b] focus-visible:outline-4 focus-visible:outline-offset-2 focus-visible:outline-[#b3261e]"
                        @click="choose(false)"
                    >
                        Afvis statistik
                    </button>
                    <button
                        type="button"
                        class="min-h-12 rounded-sm border-2 border-[#173124] bg-[#173124] px-5 py-3 text-sm font-extrabold text-white transition hover:bg-[#294c3b] focus-visible:outline-4 focus-visible:outline-offset-2 focus-visible:outline-[#b3261e]"
                        @click="choose(true)"
                    >
                        Tillad statistik
                    </button>
                </div>
            </div>
        </div>
    </section>

    <button
        v-else-if="hasSavedChoice"
        type="button"
        class="fixed bottom-4 left-4 z-40 rounded-sm border-2 border-[#173124] bg-[#fbf9f4] px-3 py-2 text-xs font-extrabold text-[#173124] shadow-md transition hover:bg-[#eee8dd] focus-visible:outline-4 focus-visible:outline-offset-2 focus-visible:outline-[#b3261e]"
        @click="openSettings"
    >
        Cookieindstillinger
    </button>
</template>
