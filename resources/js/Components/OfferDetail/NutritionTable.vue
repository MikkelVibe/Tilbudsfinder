<script setup>
defineProps({
    nutrition: {
        type: Array,
        required: true,
    },
});
</script>

<template>
    <section class="flex h-full flex-col">
        <div class="mb-5 flex items-end justify-between gap-6 border-b-2 border-[#173124] pb-3">
            <h2 class="font-serif text-3xl font-bold leading-none tracking-[-0.03em] text-[#18251e]">Næringsindhold pr. 100g</h2>
        </div>

        <table class="h-full w-full border border-[#c9c1b4] border-collapse bg-[#f5f3ee] text-sm">
            <caption class="sr-only">Næringsdeklaration pr. 100 gram</caption>
            <thead>
                <tr class="border-b border-[#c9c1b4] bg-[#fbf9f4]">
                    <th colspan="2" class="px-4 py-3 text-left text-[10px] font-black uppercase tracking-[0.22em] text-[#173124]">
                        Næringsdeklaration
                    </th>
                </tr>
                <tr class="border-b border-[#c9c1b4]">
                    <th class="px-4 py-3 text-left text-[10px] font-black uppercase tracking-[0.18em] text-[#6f746d]">Indhold pr. 100g</th>
                    <th class="px-4 py-3 text-right text-[10px] font-black uppercase tracking-[0.18em] text-[#6f746d]">Værdi</th>
                </tr>
            </thead>
            <tbody>
                <template v-for="item in nutrition" :key="item.label">
                    <tr class="border-b border-[#d8d0c3] last:border-b-0">
                        <td class="px-4 py-3 font-bold text-[#18251e]">{{ item.label }}</td>
                        <td class="px-4 py-3 text-right font-semibold text-[#18251e]">{{ item.value }}</td>
                    </tr>
                    <tr
                        v-for="subItem in item.subItems || []"
                        :key="`${item.label}-${subItem.label}`"
                        class="border-b border-[#d8d0c3] last:border-b-0"
                    >
                        <td class="px-4 py-3 pl-8 text-[#5d655f]">- {{ subItem.label }}</td>
                        <td class="px-4 py-3 text-right font-semibold text-[#18251e]">{{ subItem.value }}</td>
                    </tr>
                </template>
            </tbody>
        </table>
    </section>
</template>
