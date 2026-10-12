<script setup>
import { ref, computed } from 'vue'

const props = defineProps({
  data: {
    type: Array,
    required: true,
  },
})

const hoveredIndex = ref(null)
const maxValue = computed(() => Math.max(...props.data.map((d) => d.value), 1))
</script>

<template>
  <div class="bar-chart">
    <div class="y-axis">
      <span v-for="pct in [100, 75, 50, 25, 0]" :key="pct" class="y-label">{{ pct }}</span>
    </div>
    <div class="grid">
      <div v-for="pct in [100, 75, 50, 25, 0]" :key="pct" class="grid-line" />
    </div>
    <div class="bars">
      <div
        v-for="(d, i) in data"
        :key="i"
        class="bar-wrap"
        @mouseenter="hoveredIndex = i"
        @mouseleave="hoveredIndex = null"
      >
        <div v-if="hoveredIndex === i" class="tooltip">
          {{ d.label }}: {{ d.value }}
        </div>
        <div
          class="bar"
          :class="{ 'is-hovered': hoveredIndex === i }"
          :style="{ height: `${(d.value / maxValue) * 100}%` }"
        />
        <span class="x-label">{{ d.label }}</span>
      </div>
    </div>
  </div>
</template>

<style scoped>
.bar-chart {
  position: relative;
  height: 220px;
  padding: 4px 0 22px 32px;
}
.y-axis {
  position: absolute;
  left: 0;
  top: 4px;
  bottom: 22px;
  width: 28px;
  display: flex;
  flex-direction: column;
  justify-content: space-between;
  align-items: flex-end;
  pointer-events: none;
}
.y-label {
  font-size: 10px;
  color: var(--bc-text-placeholder);
  line-height: 1;
}
.grid {
  position: absolute;
  left: 32px;
  right: 0;
  top: 4px;
  bottom: 22px;
  display: flex;
  flex-direction: column;
  justify-content: space-between;
  pointer-events: none;
}
.grid-line {
  border-top: 1px dashed var(--bc-border-light);
}
.bars {
  position: relative;
  height: 100%;
  display: flex;
  align-items: flex-end;
  gap: 8px;
}
.bar-wrap {
  flex: 1;
  height: 100%;
  display: flex;
  flex-direction: column;
  justify-content: flex-end;
  align-items: center;
  position: relative;
}
.bar {
  width: 100%;
  max-width: 32px;
  border-radius: 6px 6px 0 0;
  background: linear-gradient(180deg, #3b82f6 0%, #2563eb 100%);
  transition: background 0.15s, transform 0.15s;
}
.bar.is-hovered {
  background: linear-gradient(180deg, #60a5fa 0%, #3b82f6 100%);
}
.x-label {
  position: absolute;
  bottom: -18px;
  font-size: 10px;
  color: var(--bc-text-secondary);
  white-space: nowrap;
}
.tooltip {
  position: absolute;
  top: -30px;
  padding: 4px 8px;
  background: var(--bc-text-primary);
  color: #fff;
  font-size: 11px;
  border-radius: 6px;
  white-space: nowrap;
  z-index: 10;
  pointer-events: none;
  box-shadow: var(--bc-shadow);
}
</style>
