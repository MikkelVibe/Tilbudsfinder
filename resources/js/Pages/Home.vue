<script setup>
defineProps({
    appName: {
        type: String,
        required: true,
    },
});
</script>

<template>
    <main class="min-h-screen bg-stone-950 text-stone-50">
        <section class="mx-auto flex min-h-screen w-full max-w-6xl flex-col justify-center px-6 py-16">
            <p class="mb-5 text-sm font-semibold uppercase tracking-[0.35em] text-emerald-300">
                {{ appName }} v2
            </p>

            <div class="max-w-3xl">
                <h1 class="text-5xl font-semibold tracking-tight text-white sm:text-7xl">
                    Grocery offers, rebuilt for reliable search and price history.
                </h1>

                <p class="mt-6 max-w-2xl text-lg leading-8 text-stone-300">
                    This Laravel app now uses Inertia with Vue as the frontend foundation. Next up is the grocer import pipeline, normalized unit pricing, and fast active-deal discovery.
                </p>
            </div>

            <div class="mt-10 grid gap-4 sm:grid-cols-3">
                <div class="rounded-2xl border border-white/10 bg-white/5 p-5">
                    <h2 class="font-semibold text-emerald-200">Ingestion</h2>
                    <p class="mt-2 text-sm leading-6 text-stone-300">
                        JSON-based grocer adapters with retries, validation, and import history.
                    </p>
                </div>

                <div class="rounded-2xl border border-white/10 bg-white/5 p-5">
                    <h2 class="font-semibold text-emerald-200">Discovery</h2>
                    <p class="mt-2 text-sm leading-6 text-stone-300">
                        Search-first active deals with clean category, grocer, and unit-price filtering.
                    </p>
                </div>

                <div class="rounded-2xl border border-white/10 bg-white/5 p-5">
                    <h2 class="font-semibold text-emerald-200">History</h2>
                    <p class="mt-2 text-sm leading-6 text-stone-300">
                        Strict product matching so historical price comparisons stay trustworthy.
                    </p>
                </div>
            </div>
        </section>
    </main>
</template>
