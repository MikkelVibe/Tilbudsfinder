<script setup>
import {
    CategoryScale,
    Chart,
    Filler,
    Legend,
    LinearScale,
    LineController,
    LineElement,
    PointElement,
    Tooltip,
} from 'chart.js';
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';

Chart.register(CategoryScale, LinearScale, LineController, LineElement, PointElement, Filler, Tooltip, Legend);

const props = defineProps({
    historyData: {
        type: Array,
        required: true,
    },
});

const canvas = ref(null);
let chart = null;

const dateKeys = computed(() => [...new Set(props.historyData.flatMap((series) => series.prices.map((item) => item.date)))].sort());
const labels = computed(() => dateKeys.value.map((date) => formatDate(date)));

const averagePrices = computed(() => dateKeys.value.map((date) => {
    const prices = props.historyData
        .map((series) => series.prices.find((item) => item.date === date)?.price)
        .filter((price) => typeof price === 'number');

    if (!prices.length) {
        return null;
    }

    return prices.reduce((sum, price) => sum + price, 0) / prices.length;
}));

const hasAverageSeries = computed(() => averagePrices.value.filter((price) => price !== null).length > 1);

const datasets = computed(() => [
    ...props.historyData.map((series) => ({
        label: series.grocer,
        data: dateKeys.value.map((date) => series.prices.find((item) => item.date === date)?.price ?? null),
        borderColor: series.color,
        backgroundColor: series.color,
        borderWidth: 3,
        pointBackgroundColor: '#fbf9f4',
        pointBorderColor: series.color,
        pointBorderWidth: 2,
        pointRadius: 4,
        pointHoverRadius: 6,
        tension: 0.28,
    })),
    ...(hasAverageSeries.value ? [{
        label: 'Gennemsnit',
        data: averagePrices.value,
        borderColor: '#111111',
        backgroundColor: '#111111',
        borderDash: [6, 6],
        borderWidth: 2,
        pointRadius: 0,
        pointHoverRadius: 0,
        tension: 0.28,
    }] : []),
]);

function formatDate(date) {
    return new Intl.DateTimeFormat('da-DK', {
        day: '2-digit',
        month: '2-digit',
    }).format(new Date(date));
}

function formatPrice(price) {
    return `${price.toLocaleString('da-DK', { minimumFractionDigits: 0, maximumFractionDigits: 2 })} kr.`;
}

function createChart() {
    if (!canvas.value) {
        return;
    }

    chart?.destroy();

    chart = new Chart(canvas.value, {
        type: 'line',
        data: {
            labels: labels.value,
            datasets: datasets.value,
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: {
                intersect: false,
                mode: 'index',
            },
            layout: {
                padding: {
                    top: 8,
                    right: 12,
                    bottom: 0,
                    left: 0,
                },
            },
            plugins: {
                legend: {
                    display: false,
                },
                tooltip: {
                    backgroundColor: '#173124',
                    borderColor: '#173124',
                    borderWidth: 1,
                    displayColors: true,
                    titleColor: '#fbf9f4',
                    bodyColor: '#fbf9f4',
                    callbacks: {
                        label: (context) => `${context.dataset.label}: ${formatPrice(context.parsed.y)}`,
                    },
                },
            },
            scales: {
                x: {
                    border: {
                        color: '#c9c1b4',
                    },
                    grid: {
                        color: '#d8d0c3',
                        tickColor: '#c9c1b4',
                    },
                    ticks: {
                        color: '#5d655f',
                        font: {
                            size: 11,
                            weight: 700,
                        },
                    },
                },
                y: {
                    border: {
                        color: '#c9c1b4',
                    },
                    grid: {
                        color: '#d8d0c3',
                        tickColor: '#c9c1b4',
                    },
                    ticks: {
                        color: '#173124',
                        callback: (value) => formatPrice(value),
                        font: {
                            size: 11,
                            weight: 800,
                        },
                    },
                },
            },
        },
    });
}

onMounted(() => {
    createChart();
});

watch(() => props.historyData, async () => {
    await nextTick();
    createChart();
}, { deep: true });

onBeforeUnmount(() => {
    chart?.destroy();
});
</script>

<template>
    <section class="flex h-full flex-col">
        <div class="mb-5 flex items-end justify-between gap-6 border-b-2 border-[#173124] pb-3">
            <h2 class="font-serif text-3xl font-bold leading-none tracking-[-0.03em] text-[#18251e]">Prishistorik</h2>
            <span class="text-[10px] font-black uppercase tracking-[0.2em] text-[#6f746d]">Alle butikker</span>
        </div>

        <div class="flex flex-1 flex-col border border-[#c9c1b4] bg-[#f5f3ee] p-4">
            <div class="min-h-[340px] flex-1">
                <canvas ref="canvas" aria-label="Prishistorik på tværs af butikker"></canvas>
            </div>

            <div class="mt-4 flex flex-wrap border-t border-[#c9c1b4] pt-3 text-xs font-extrabold uppercase tracking-[0.16em] text-[#5d655f]">
                <span v-for="series in historyData" :key="series.grocer" class="mr-4 inline-flex items-center gap-2">
                    <span class="size-2" :style="{ backgroundColor: series.color }"></span>{{ series.grocer }}
                </span>
                <span v-if="hasAverageSeries" class="mr-4 inline-flex items-center gap-2">
                    <span class="h-px w-8 border-t border-dashed border-[#111111]"></span>Gennemsnit
                </span>
            </div>
        </div>
    </section>
</template>
