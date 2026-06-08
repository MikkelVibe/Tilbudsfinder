<script setup>
defineProps({
    compact: {
        type: Boolean,
        default: false,
    },
});
</script>

<template>
    <div class="grid h-full w-full place-items-center bg-[#fbf9f4] p-4 text-center sm:p-6">
        <div class="relative flex h-full w-full flex-col items-center justify-center overflow-hidden border border-[#d8d0c3] bg-[linear-gradient(135deg,#fbf9f4_0%,#fbf9f4_48%,#efe8dc_48%,#efe8dc_52%,#fbf9f4_52%,#fbf9f4_100%)] px-5 py-8">
            <div class="absolute inset-x-5 top-5 border-t border-[#d8d0c3]"></div>
            <div class="absolute inset-x-5 bottom-5 border-t border-[#d8d0c3]"></div>
            <div class="grid size-20 place-items-center rounded-full border border-[#c9c1b4] bg-[#f5f3ee] text-[#173124]" :class="compact ? 'size-14' : 'sm:size-24'">
                <svg viewBox="0 0 48 48" class="size-10" :class="compact ? 'size-7' : 'sm:size-12'" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path d="M10 34l8-9 6 6 5-6 9 9" />
                    <rect x="8" y="12" width="32" height="24" rx="2" />
                    <circle cx="31" cy="20" r="3" />
                    <path d="M7 41 41 7" />
                </svg>
            </div>
            <p class="mt-5 text-[10px] font-black uppercase tracking-[0.28em] text-[#6f746d]" :class="compact ? 'mt-3' : ''">Intet billede</p>
            <p v-if="!compact" class="mt-3 max-w-[15rem] font-serif text-3xl font-bold leading-none tracking-[-0.04em] text-[#173124]">
                Billede mangler
            </p>
        </div>
    </div>
</template>
