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

// Catmull-Rom 插值转三次贝塞尔：折线 → 平滑曲线
// tension 控制平滑强度（1 为标准 Catmull-Rom）；日报数据点密集、振幅陡，
// 取 2 才能得到明显可见的峰谷圆角，且数据交替起伏时每个内点都是极值点，
// 中心差分切线近似水平，不会过冲穿出数据范围
function curve(pts, tension = 2) {
  if (pts.length < 2) return ''
  let d = ''
  for (let i = 0; i < pts.length - 1; i++) {
    const p0 = pts[i - 1] || pts[i]
    const p1 = pts[i]
    const p2 = pts[i + 1]
    const p3 = pts[i + 2] || p2
    const c1x = p1.x + ((p2.x - p0.x) / 6) * tension
    const c1y = p1.y + ((p2.y - p0.y) / 6) * tension
    const c2x = p2.x - ((p3.x - p1.x) / 6) * tension
    const c2y = p2.y - ((p3.y - p1.y) / 6) * tension
    d += ` C ${c1x.toFixed(2)},${c1y.toFixed(2)} ${c2x.toFixed(2)},${c2y.toFixed(2)} ${p2.x.toFixed(2)},${p2.y.toFixed(2)}`
  }
  return d
}

const linePath = computed(() => {
  const pts = points.value
  return pts.length ? `M ${pts[0].x.toFixed(2)},${pts[0].y.toFixed(2)}` + curve(pts) : ''
})
const areaPath = computed(() => {
  const pts = points.value
  if (pts.length < 2) return ''
  return `M ${pts[0].x.toFixed(2)},100 L ${pts[0].x.toFixed(2)},${pts[0].y.toFixed(2)}` + curve(pts) + ' L 100,100 Z'
})
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
          <stop offset="0%" stop-color="#0f6e8c" stop-opacity="0.28" />
          <stop offset="100%" stop-color="#0f6e8c" stop-opacity="0.02" />
        </linearGradient>
      </defs>
      <path class="area-fill" :d="areaPath" fill="url(#area-grad)" />
      <path
        class="trend-line"
        :d="linePath"
        fill="none"
        stroke="#0f6e8c"
        stroke-width="1.8"
        stroke-linecap="round"
        stroke-linejoin="round"
        vector-effect="non-scaling-stroke"
        pathLength="100"
      />
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
/* 入场：面积淡入 → 曲线绘制 */
.chart-svg .area-fill { animation: bc-fade .6s .35s var(--bc-ease-out) backwards; }
.chart-svg .trend-line {
  stroke-dasharray: 100;
  stroke-dashoffset: 100;
  animation: bc-draw .9s var(--bc-ease-out) .1s forwards;
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
