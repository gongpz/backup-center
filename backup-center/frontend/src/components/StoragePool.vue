<script setup>
import { computed } from 'vue'

const props = defineProps({
  storages: { type: Array, default: () => [] },
})

// 段配色：base = 段整体色（quota 部分），dark = used 部分色。对齐 --bc-chart-* 图表色板
const PALETTE = [
  { base: 'var(--bc-chart-1)', dark: 'var(--bc-primary-800)' }, // 青瓷
  { base: 'var(--bc-chart-2)', dark: '#2f5f8f' },               // 钢蓝
  { base: 'var(--bc-chart-3)', dark: '#4a55a8' },               // 靛
  { base: 'var(--bc-chart-4)', dark: '#8a6a25' },               // 琥珀
  { base: 'var(--bc-chart-5)', dark: '#1f7355' },               // 玉绿
  { base: 'var(--bc-chart-6)', dark: '#7e3a4d' },               // 绛
  { base: 'var(--bc-chart-2)', dark: '#2f5f8f' },               // 钢蓝（循环）
  { base: 'var(--bc-chart-4)', dark: '#8a6a25' },               // 琥珀（循环）
]

const fmtBytes = (n) => {
  if (!n || n <= 0) return '0 B'
  const units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB']
  let v = Number(n), i = 0
  while (v >= 1024 && i < units.length - 1) { v /= 1024; i++ }
  return v.toFixed(v >= 100 || i === 0 ? 0 : 1) + ' ' + units[i]
}

/**
 * 稳定排序：按 id 升序，让颜色分配跨刷新不变。
 * 每个存储分配一个稳定的 palette 索引。
 */
const ordered = computed(() =>
  [...props.storages]
    .sort((a, b) => (a.id ?? 0) - (b.id ?? 0))
    .map((s, i) => ({
      ...s,
      _paletteIdx: i % PALETTE.length,
      _color: PALETTE[i % PALETTE.length],
    })),
)

// 只有配了 quota_bytes 的存储才参与顶部堆叠条
const quoted = computed(() =>
  ordered.value.filter((s) => Number(s.quota_bytes) > 0),
)

const totalQuota = computed(() =>
  quoted.value.reduce((s, p) => s + Number(p.quota_bytes || 0), 0),
)
const totalUsed = computed(() =>
  quoted.value.reduce((s, p) => s + Number(p.used_bytes || 0), 0),
)
const overallPct = computed(() => {
  if (!totalQuota.value) return 0
  return Math.round((totalUsed.value / totalQuota.value) * 100)
})

/**
 * 顶部比例线的基准宽度：
 *   - 配了配额 → 用配额（段宽 = 该存储容量占总容量的比例，深色 = 已用占比）
 *   - 没配额   → 用已用空间（段宽 = 该存储已用占总已用的比例，整段为深色）
 * 这样即使所有存储都没设配额，比例线依然可见，不会整条消失。
 */
const totalBasis = computed(() =>
  ordered.value.reduce(
    (s, p) =>
      s +
      (Number(p.quota_bytes) > 0
        ? Number(p.quota_bytes)
        : Math.max(0, Number(p.used_bytes || 0))),
    0,
  ),
)
const totalUsedAll = computed(() =>
  ordered.value.reduce((s, p) => s + Math.max(0, Number(p.used_bytes || 0)), 0),
)

// 顶部段：每段宽度 = 该基准 / 全部基准之和
const segments = computed(() => {
  const total = totalBasis.value
  if (!total) return []
  return ordered.value
    .filter(
      (s) =>
        (Number(s.quota_bytes) > 0
          ? Number(s.quota_bytes)
          : Math.max(0, Number(s.used_bytes || 0))) > 0,
    )
    .map((s) => {
      const quota = Number(s.quota_bytes || 0)
      const used = Math.max(0, Number(s.used_bytes || 0))
      const basis = quota > 0 ? quota : used
      const pct = (basis / total) * 100
      const usedPct = quota > 0 ? Math.min(100, (used / quota) * 100) : 100
      return {
        id: s.id,
        name: s.name,
        quota,
        used,
        pct,
        usedPct,
        hasQuota: quota > 0,
        baseColor: s._color.base,
        darkColor: s._color.dark,
      }
    })
})

const quotaPct = (p) => {
  if (!p?.quota_bytes) return 0
  return Math.round((Number(p.used_bytes || 0) / Number(p.quota_bytes)) * 100)
}
const usageClass = (p) => {
  const v = quotaPct(p)
  if (v >= 90) return 'crit'
  if (v >= 75) return 'warn'
  return 'ok'
}

const websiteTotal = (p) => p.websites_count ?? 0
const uploadTotal = (p) => Number(p.upload_count || 0)
</script>

<template>
  <div class="storage-pool bc-card">
    <div class="head">
      <div>
        <div class="chart-title">存储与空间</div>
        <div class="chart-sub">
          {{ storages.length }} 个存储目标
          <template v-if="totalQuota > 0">
            · 总容量 {{ fmtBytes(totalQuota) }} · 已用 {{ fmtBytes(totalUsed) }}（{{ overallPct }}%）
          </template>
          <template v-else-if="totalUsedAll > 0">
            · 总已用 {{ fmtBytes(totalUsedAll) }}（各存储未设配额）
          </template>
        </div>
      </div>
    </div>

    <div v-if="storages.length === 0" class="empty">
      <p>还没有配置任何存储目标</p>
      <router-link to="/storages" class="link">前往存储页面添加 →</router-link>
    </div>

    <template v-else>
      <!-- 堆叠段条：只要任一存储有容量或已用空间就展示（没配额时按已用空间比例） -->
      <div v-if="segments.length > 0" class="stack-wrap">
        <div class="stack-bar">
          <div
            v-for="seg in segments"
            :key="seg.id"
            class="stack-seg"
            :style="{ width: seg.pct + '%', backgroundColor: seg.baseColor }"
            :title="seg.hasQuota
              ? `${seg.name}：${fmtBytes(seg.used)} / ${fmtBytes(seg.quota)}（${Math.round(seg.usedPct)}%）`
              : `${seg.name}：已用 ${fmtBytes(seg.used)}（占已用总量 ${seg.pct.toFixed(1)}%）`"
          >
            <div
              class="stack-seg-used"
              :style="{ width: seg.usedPct + '%', backgroundColor: seg.darkColor }"
            ></div>
          </div>
        </div>

        <div class="stack-legend">
          <span
            v-for="seg in segments"
            :key="seg.id"
            class="legend-item"
            :title="`占总容量 ${seg.pct.toFixed(1)}%`"
          >
            <span class="legend-dot" :style="{ backgroundColor: seg.darkColor }"></span>
            <span class="legend-name">{{ seg.name }}</span>
            <span class="legend-pct">{{ seg.pct.toFixed(0) }}%</span>
          </span>
        </div>
      </div>

      <!-- 单存储卡片 grid -->
      <div class="pool-grid">
        <div v-for="p in ordered" :key="p.id" class="pool-card">
          <div class="pool-head">
            <span class="pool-color-dot" :style="{ backgroundColor: p._color.dark }"></span>
            <div class="pool-name-wrap">
              <div class="pool-title">{{ p.name }}</div>
            </div>
            <span class="badge type-badge">{{ p.type }}</span>
            <span :class="['badge', p.status === 'enabled' ? 'ok' : 'gray']">
              {{ p.status === 'enabled' ? '启用' : '停用' }}
            </span>
            <span v-if="p.is_mandatory" class="lock" title="强制备份">🔒</span>
          </div>
          <div class="pool-line">
            <span v-if="Number(p.quota_bytes) > 0" class="pool-usage">
              已用 <span class="strong">{{ fmtBytes(p.used_bytes || 0) }}</span>
              · 剩余 <span class="strong">{{ fmtBytes(Math.max(0, p.quota_bytes - (p.used_bytes || 0))) }}</span>
              · 共 {{ fmtBytes(p.quota_bytes) }}
              <span class="pool-quota-pct" :class="usageClass(p)">{{ quotaPct(p) }}%</span>
            </span>
            <span v-else-if="p.used_bytes > 0" class="pool-usage muted">
              已用 <span class="strong">{{ fmtBytes(p.used_bytes) }}</span> · 未设配额
            </span>
            <span v-else class="pool-usage muted">未设配额</span>
          </div>
          <div class="pool-line pool-line-sites">
            <span class="pool-usage">
              共托管 <span class="strong">{{ websiteTotal(p) }}</span> 个网站
              · 承载备份 <span class="strong">{{ uploadTotal(p) }}</span> 次
            </span>
          </div>
        </div>
      </div>
    </template>
  </div>
</template>

<style scoped>
.storage-pool { padding: 20px; }
.head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  margin-bottom: 16px;
}
.chart-title {
  font-size: 14px;
  font-weight: 600;
  color: var(--bc-text-primary);
}
.chart-sub {
  font-size: 12px;
  color: var(--bc-text-secondary);
  margin-top: 2px;
}

.empty { padding: 30px 10px; text-align: center; color: var(--bc-text-secondary); }
.empty p { margin: 0 0 8px; font-size: 13px; }
.link { color: var(--bc-primary); font-size: 13px; }
.link:hover { text-decoration: underline; }

/* ---------- 堆叠段条 ---------- */
.stack-wrap {
  margin-bottom: 20px;
}
.stack-bar {
  display: flex;
  height: 8px;
  border-radius: 999px;
  overflow: hidden;
  background: var(--bc-border-light);
}
.stack-seg {
  position: relative;
  height: 100%;
  transition: filter .15s;
  overflow: hidden;
}
.stack-seg:hover { filter: brightness(1.05); }
.stack-seg + .stack-seg {
  border-left: 1px solid var(--bc-bg-card);
}
.stack-seg-used {
  height: 100%;
  transition: width .3s ease;
}

.stack-legend {
  margin-top: 10px;
  display: flex;
  flex-wrap: wrap;
  gap: 12px 18px;
}
.legend-item {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-size: 12px;
  color: var(--bc-text-regular);
}
.legend-dot {
  width: 10px;
  height: 10px;
  border-radius: 3px;
  flex-shrink: 0;
}
.legend-name {
  max-width: 160px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.legend-pct {
  color: var(--bc-text-secondary);
  font-variant-numeric: tabular-nums;
}

/* ---------- 卡片网格 ---------- */
.pool-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
  gap: 12px;
}
.pool-card {
  padding: 6px 10px;
  border-radius: 8px;
  background: var(--bc-bg-inset);
  border: 1px solid var(--bc-border-light);
  transition: transform 0.15s, box-shadow 0.15s;
  display: flex;
  flex-direction: column;
  gap: 2px;
}
.pool-card:hover { transform: translateY(-1px); box-shadow: var(--bc-shadow-sm); }

.pool-head {
  display: flex;
  align-items: center;
  gap: 8px;
}
.pool-color-dot {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  flex-shrink: 0;
}
.pool-name-wrap { flex: 1; min-width: 0; }
.pool-title {
  font-size: 13px;
  font-weight: 500;
  color: var(--bc-text-primary);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.pool-line {
  display: flex;
  align-items: baseline;
  gap: 8px;
  font-size: 12px;
  color: var(--bc-text-secondary);
}
.pool-line .strong {
  color: var(--bc-text-primary);
  font-weight: 500;
}
.pool-usage {
  flex: 1;
  min-width: 0;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.pool-tail-count {
  flex-shrink: 0;
  color: var(--bc-text-secondary);
  white-space: nowrap;
}
.pool-line-sites {
  font-size: 11px;
  color: var(--bc-text-secondary);
}

.type-badge {
  background: var(--bc-primary-light);
  color: var(--bc-primary);
  border: 1px solid var(--bc-primary-100);
  text-transform: uppercase;
  letter-spacing: 0.4px;
  font-size: 10px;
  padding: 1px 12px;
  line-height: 1.6;
  white-space: nowrap;
}
html.dark .type-badge {
  background: var(--bc-primary-50);
  border-color: var(--bc-primary-100);
  color: var(--bc-primary-200);
}
.lock { font-size: 14px; line-height: 1; }

.badge {
  display: inline-flex;
  align-items: center;
  padding: 1px 12px;
  border-radius: 999px;
  font-size: 11px;
  font-weight: 500;
  line-height: 1.6;
  letter-spacing: 0.2px;
  white-space: nowrap;
}
.badge.ok    { background: var(--bc-success-bg); color: var(--bc-success); border: 1px solid var(--bc-success-border); }
.badge.gray  { background: var(--bc-bg-hover); color: var(--bc-text-secondary); border: 1px solid var(--bc-border-light); }

.pool-quota-pct {
  margin-left: 4px;
  font-weight: 600;
  font-variant-numeric: tabular-nums;
}
.pool-quota-pct.ok   { color: var(--bc-success); }
.pool-quota-pct.warn { color: var(--bc-warning); }
.pool-quota-pct.crit { color: var(--bc-danger); }
</style>
