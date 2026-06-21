<script setup>
import { Link } from '@inertiajs/vue3';
</script>

<template>
    <header class="border-b-4 border-[#173124] bg-[#fbf9f4]">
        <div class="mx-auto flex max-w-7xl items-center justify-between gap-8 px-6 py-6 lg:px-8">
            <Link href="/" class="font-serif text-4xl font-bold tracking-[-0.04em] text-[#173124] sm:text-5xl">
                Tilbudsfinder
            </Link>

            <nav aria-label="Primær navigation" class="hidden items-center gap-8 text-xs font-extrabold uppercase tracking-[0.22em] text-[#173124] md:flex">
                <Link href="/tilbud" class="transition hover:text-[#b3261e]">Søg tilbud</Link>
                <Link href="/butikker" class="transition hover:text-[#b3261e]">Butikker</Link>
            </nav>
        </div>
    </header>
</template>
