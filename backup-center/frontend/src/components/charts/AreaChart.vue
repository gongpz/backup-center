<script setup>
import { computed } from 'vue'

const props = defineProps({
  data: {
    type: Array,
    required: true,
  },
})

const points = computed(() => {
  const max = Math.max(...props.data.map((d) => d.value), 1)
  return props.data.map((d, i) => {
    const x = (i / (props.data.length - 1)) * 100
    const y = 100 - (d.value / max) * 78
    return { x, y, ...d }
  })
})

const polyline = computed(() => points.value.map((p) => `${p.x},${p.y}`).join(' '))
const area = computed(() =>
  ['0,100', ...points.value.map((p) => `${p.x},${p.y}`), '100,100'].join(' '),
)
</script>

<template>
  <div class="area-chart">
    <svg
      viewBox="0 0 100 100"
      preserveAspectRatio="none"
      class="chart-svg"
    >
      <defs>
        <linearGradient id="area-grad" x1="0" y1="0" x2="0" y2="1">
          <stop offset="0%" stop-color="#3b82f6" stop-opacity="0.35" />
          <stop offset="100%" stop-color="#3b82f6" stop-opacity="0.02" />
        </linearGradient>
      </defs>
      <polygon :points="area" fill="url(#area-grad)" />
      <polyline
        :points="polyline"
        fill="none"
        stroke="#2563eb"
        stroke-width="1.8"
        stroke-linecap="round"
        stroke-linejoin="round"
        vector-effect="non-scaling-stroke"
      />
      <circle v-for="(p, i) in points" :key="i" :cx="p.x" :cy="p.y" r="1.5" fill="#2563eb" />
    </svg>
    <div class="x-labels">
      <span v-for="p in points" :key="p.label">{{ p.label }}</span>
    </div>
  </div>
</template>

<style scoped>
.area-chart {
  position: relative;
  height: 160px;
}
.chart-svg {
  position: absolute;
  inset: 0 0 20px 0;
  width: 100%;
  height: calc(100% - 20px);
}
.x-labels {
  position: absolute;
  bottom: 0;
  left: 0;
  right: 0;
  display: flex;
  justify-content: space-between;
}
.x-labels span {
  font-size: 10px;
  color: var(--bc-text-secondary);
}
</style>
