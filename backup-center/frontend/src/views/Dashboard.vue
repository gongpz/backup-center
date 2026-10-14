<script setup>
import { ref, computed, watch } from 'vue'
import {
  Monitor,
  Cpu,
  CircleCheck,
  CircleClose,
  ArrowUp,
  ArrowDown,
  Calendar,
  TrendCharts,
  Histogram,
  FolderOpened,
  DataAnalysis,
  Coin,
  DataLine,
} from '@element-plus/icons-vue'
import BarChart from '@/components/charts/BarChart.vue'
import AreaChart from '@/components/charts/AreaChart.vue'
import StoragePool from '@/components/StoragePool.vue'
import { dashboardApi } from '@/api/dashboard'
import { useAutoRefresh } from '@/composables/useAutoRefresh'

const rangeOptions = [
  { key: 'day',      label: '天'   },
  { key: 'week',     label: '周'   },
  { key: 'month',    label: '月'   },
  { key: 'quarter',  label: '季度' },
  { key: 'halfyear', label: '半年' },
  { key: 'year',     label: '年'   },
]
const range = ref('week')
const currentRangeLabel = ref('近 7 天')

/* ---------- 数据 ---------- */
const data = ref(null)
const doFetch = async () => {
  const d = await dashboardApi.get(range.value)
  data.value = d
  currentRangeLabel.value = d.range_label
}
const { loading, manualRefresh } = useAutoRefresh(doFetch, 60000)

// 切换范围时立即拉一次
watch(range, () => {
  manualRefresh()
})

/* ---------- 格式化 ---------- */
const fmtSize = (bytes) => {
  if (! bytes) return { value: '0', unit: 'B' }
  const units = ['B', 'KB', 'MB', 'GB', 'TB']
  let v = bytes, i = 0
  while (v >= 1024 && i < units.length - 1) { v /= 1024; i++ }
  return { value: (v >= 100 || i === 0) ? v.toFixed(0) : v.toFixed(1), unit: units[i] }
}
const fmtPct = (v) => {
  if (v === null || v === undefined) return { text: '—', positive: true, isNil: true }
  return {
    text: (v >= 0 ? '+' : '') + v.toFixed(1) + '%',
    positive: v >= 0,
  }
}
const rangePrefix = computed(() => ({
  day: '24 小时', week: '近 7 天', month: '近 30 天', quarter: '季度', halfyear: '半年', year: '年度',
})[range.value])

/* ---------- KPI 卡（第一排 数量类）---------- */
const statsRow1 = computed(() => {
  if (! data.value) return []
  const s = data.value.stats
  const c = s.change
  return [
    {
      label: '服务器数量',
      value: s.server_count.toString(),
      hint: `${s.server_online} 台在线`,
      change: null, positive: true,
      icon: Monitor, tone: 'blue',
    },
    {
      label: '网站数量',
      value: s.website_count.toString(),
      hint: `${s.website_enabled} 个已启用`,
      change: null, positive: true,
      icon: Cpu, tone: 'indigo',
    },
    {
      label: `${rangePrefix.value}成功备份`,
      value: s.period_success.toLocaleString(),
      change: c.success,
      icon: CircleCheck, tone: 'emerald',
    },
    {
      label: `${rangePrefix.value}失败数量`,
      value: s.period_failed.toLocaleString(),
      change: c.failed,
      changeReversed: true, // 失败下降是好事
      icon: CircleClose, tone: 'red',
    },
  ]
})

/* ---------- KPI 卡（第二排 容量/次数）---------- */
const statsRow2 = computed(() => {
  if (! data.value) return []
  const s = data.value.stats
  const c = s.change
  const period = fmtSize(s.period_size)
  const cum = fmtSize(s.cumulative_size)
  return [
    {
      label: `${rangePrefix.value}备份大小`,
      value: period.value + ' ' + period.unit,
      change: c.size,
      icon: FolderOpened, tone: 'cyan',
    },
    {
      label: `${rangePrefix.value}备份次数`,
      value: s.period_count.toLocaleString(),
      change: c.count,
      icon: DataAnalysis, tone: 'purple',
    },
    {
      label: '累计备份大小',
      value: cum.value + ' ' + cum.unit,
      icon: Coin, tone: 'amber',
    },
    {
      label: '累计备份次数',
      value: s.cumulative_count.toLocaleString(),
      icon: DataLine, tone: 'pink',
    },
  ]
})

/* ---------- 图表数据 ---------- */
const barData = computed(() => data.value?.count_trend || [])

// 大小趋势：转成合适单位
const sizeTrendUnit = computed(() => {
  const values = data.value?.size_trend?.map((t) => t.value) || [0]
  const max = Math.max(...values, 0)
  if (max >= 1024 ** 4) return { unit: 'TB', factor: 1024 ** 4 }
  if (max >= 1024 ** 3) return { unit: 'GB', factor: 1024 ** 3 }
  if (max >= 1024 ** 2) return { unit: 'MB', factor: 1024 ** 2 }
  if (max >= 1024) return { unit: 'KB', factor: 1024 }
  return { unit: 'B', factor: 1 }
})
const sizeData = computed(() =>
  (data.value?.size_trend || []).map((t) => ({
    label: t.label,
    value: sizeTrendUnit.value.factor
      ? +(t.value / sizeTrendUnit.value.factor).toFixed(2)
      : 0,
  })),
)

const sizePeak = computed(() => Math.max(...sizeData.value.map((d) => d.value), 0))
const sizeAvg  = computed(() => {
  const arr = sizeData.value.map((d) => d.value)
  return arr.length ? +(arr.reduce((s, v) => s + v, 0) / arr.length).toFixed(2) : 0
})
const sizeTotal = computed(() =>
  +(sizeData.value.reduce((s, d) => s + d.value, 0)).toFixed(2),
)

/* ---------- 表格：最近备份记录 ---------- */
const tableRows = computed(() => data.value?.recent_logs || [])

const statusMeta = {
  success: { text: '成功', class: 'ok' },
  failed:  { text: '失败', class: 'fail' },
  running: { text: '执行中', class: 'run' },
}
const fmtTime = (iso) => {
  if (! iso) return '—'
  const d = new Date(iso)
  const diff = (Date.now() - d.getTime()) / 1000
  if (diff < 60) return `${Math.floor(diff)} 秒前`
  if (diff < 3600) return `${Math.floor(diff / 60)} 分钟前`
  if (diff < 86400) return `${Math.floor(diff / 3600)} 小时前`
  return d.toLocaleDateString()
}
const fmtRowSize = (bytes) => {
  if (! bytes) return '—'
  const s = fmtSize(bytes)
  return s.value + ' ' + s.unit
}
const fmtDuration = (sec) => {
  if (! sec) return '—'
  if (sec < 60) return `${sec}s`
  const m = Math.floor(sec / 60), r = sec % 60
  return r ? `${m}m ${r}s` : `${m}m`
}
</script>

<template>
  <div v-loading="loading && !data">
    <!-- 顶部工具条 -->
    <div class="toolbar">
      <div>
        <h2 class="page-h">仪表盘</h2>
        <p class="page-sub">{{ currentRangeLabel }} · 备份运行概览 · 后台每 60 秒静默刷新</p>
      </div>
      <div class="range">
        <el-icon size="14" style="color: var(--bc-text-secondary)"><Calendar /></el-icon>
        <div class="range-tabs">
          <button
            v-for="r in rangeOptions"
            :key="r.key"
            :class="['range-btn', { active: range === r.key }]"
            @click="range = r.key"
          >
            {{ r.label }}
          </button>
        </div>
      </div>
    </div>

    <!-- KPI 第一排 -->
    <div class="stat-grid">
      <div v-for="s in statsRow1" :key="s.label" class="stat-card bc-card">
        <div class="stat-head">
          <span class="stat-label">{{ s.label }}</span>
          <div :class="['stat-icon', `tone-${s.tone}`]">
            <el-icon size="16"><component :is="s.icon" /></el-icon>
          </div>
        </div>
        <div class="stat-value">{{ s.value }}</div>
        <div v-if="s.change !== null && s.change !== undefined" :class="['stat-change', (s.changeReversed ? s.change <= 0 : s.change >= 0) ? 'up' : 'down']">
          <el-icon size="12">
            <component :is="s.change >= 0 ? ArrowUp : ArrowDown" />
          </el-icon>
          <span>{{ fmtPct(s.change).text }}</span>
          <span class="change-hint">vs 上一周期</span>
        </div>
        <div v-else class="stat-change up">
          <span class="change-hint">{{ s.hint || '—' }}</span>
        </div>
      </div>
    </div>

    <!-- KPI 第二排 -->
    <div class="stat-grid stat-grid--sub">
      <div v-for="s in statsRow2" :key="s.label" class="stat-card bc-card">
        <div class="stat-head">
          <span class="stat-label">{{ s.label }}</span>
          <div :class="['stat-icon', `tone-${s.tone}`]">
            <el-icon size="16"><component :is="s.icon" /></el-icon>
          </div>
        </div>
        <div class="stat-value">{{ s.value }}</div>
        <div v-if="s.change !== null && s.change !== undefined" :class="['stat-change', s.change >= 0 ? 'up' : 'down']">
          <el-icon size="12">
            <component :is="s.change >= 0 ? ArrowUp : ArrowDown" />
          </el-icon>
          <span>{{ fmtPct(s.change).text }}</span>
          <span class="change-hint">vs 上一周期</span>
        </div>
        <div v-else class="stat-change up">
          <span class="change-hint">全部时间累计</span>
        </div>
      </div>
    </div>

    <!-- 存储列表 -->
    <StoragePool v-if="data" :storages="data.storages" class="mb-block" />

    <!-- 图表区 -->
    <div class="chart-grid">
      <div class="chart-card bc-card chart-main">
        <div class="chart-head">
          <div>
            <div class="chart-title">备份数量趋势</div>
            <div class="chart-sub">{{ currentRangeLabel }}</div>
          </div>
          <span class="chip chip-blue">
            <el-icon size="12"><Histogram /></el-icon>
            柱状图
          </span>
        </div>
        <BarChart :data="barData" />
      </div>

      <div class="chart-card bc-card">
        <div class="chart-head">
          <div>
            <div class="chart-title">备份大小趋势</div>
            <div class="chart-sub">{{ currentRangeLabel }} · 单位 {{ sizeTrendUnit.unit }}</div>
          </div>
          <span class="chip chip-purple">
            <el-icon size="12"><TrendCharts /></el-icon>
            趋势
          </span>
        </div>
        <AreaChart :data="sizeData" />
        <div class="summary">
          <div>
            <div class="summary-label">峰值</div>
            <div class="summary-val">
              {{ sizePeak }}
              <span class="summary-unit">{{ sizeTrendUnit.unit }}</span>
            </div>
          </div>
          <div>
            <div class="summary-label">平均</div>
            <div class="summary-val">
              {{ sizeAvg }}
              <span class="summary-unit">{{ sizeTrendUnit.unit }}</span>
            </div>
          </div>
          <div>
            <div class="summary-label">累计</div>
            <div class="summary-val">
              {{ sizeTotal }}
              <span class="summary-unit">{{ sizeTrendUnit.unit }}</span>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- 最近备份记录 -->
    <div class="table-card bc-card">
      <div class="table-head">
        <div>
          <div class="chart-title">最近备份记录</div>
          <div class="chart-sub">显示最新 10 条</div>
        </div>
        <router-link to="/backup-logs" class="chart-sub link">查看全部 →</router-link>
      </div>
      <div class="table-wrap">
        <table v-if="tableRows.length" class="recent-table">
          <thead>
            <tr>
              <th>编号</th>
              <th>网站</th>
              <th>服务器</th>
              <th>大小</th>
              <th>耗时</th>
              <th>时间</th>
              <th>状态</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in tableRows" :key="row.id">
              <td class="mono">#{{ row.id }}</td>
              <td>
                <div class="site-cell">
                  <div class="site-avatar">{{ (row.website?.name || 'W').charAt(0).toUpperCase() }}</div>
                  <span>{{ row.website?.name || '—' }}</span>
                </div>
              </td>
              <td>{{ row.server?.name || '—' }}</td>
              <td class="strong">{{ fmtRowSize(row.size) }}</td>
              <td>{{ fmtDuration(row.duration_seconds) }}</td>
              <td :title="`源备份：${new Date(row.backup_at).toLocaleString()}\n同步于：${row.created_at ? new Date(row.created_at).toLocaleString() : '—'}`">
                <div>{{ fmtTime(row.created_at || row.backup_at) }}</div>
                <div v-if="row.created_at && row.backup_at && row.created_at !== row.backup_at" class="cell-sub">
                  源于 {{ fmtTime(row.backup_at) }}
                </div>
              </td>
              <td>
                <span :class="['badge', statusMeta[row.status]?.class]">
                  {{ statusMeta[row.status]?.text || row.status }}
                </span>
              </td>
            </tr>
          </tbody>
        </table>
        <div v-else class="empty">
          暂无备份记录 · Agent 上报后会在此显示
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
/* 列序：1 编号 2 网站 3 服务器 4 大小 5 耗时 6 时间 7 状态(始终保留) */
/* ---------- 顶部工具条 ---------- */
.toolbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 20px;
  gap: 16px;
  flex-wrap: wrap;
}
.page-h {
  margin: 0;
  font-size: 20px;
  font-weight: 600;
  color: var(--bc-text-primary);
}
.page-sub {
  margin: 4px 0 0;
  font-size: 12px;
  color: var(--bc-text-secondary);
}
.range {
  display: inline-flex;
  align-items: center;
  gap: 10px;
  padding: 6px 8px 6px 12px;
  background: var(--bc-bg-card);
  border: 1px solid var(--bc-border-light);
  border-radius: var(--bc-radius);
  box-shadow: var(--bc-shadow-sm);
}
.range-tabs {
  display: inline-flex;
  gap: 2px;
  background: var(--bc-bg-page);
  border-radius: 6px;
  padding: 2px;
}
.range-btn {
  border: none;
  background: transparent;
  padding: 5px 12px;
  border-radius: 4px;
  font-size: 12px;
  color: var(--bc-text-secondary);
  cursor: pointer;
  transition: all 0.15s;
  font-weight: 500;
  min-width: 36px;
  min-height: 28px;
}
.range-btn:hover { color: var(--bc-text-primary); }
.range-btn.active {
  background: var(--bc-bg-card);
  color: var(--bc-primary);
  box-shadow: var(--bc-shadow-sm);
}
.range-btn:active { transform: scale(.94); }

/* ---------- KPI 卡片 ---------- */
.stat-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
  gap: 16px;
  margin-bottom: 16px;
}
.stat-card { padding: 20px; }
.stat-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 12px;
}
.stat-label {
  font-size: 12px;
  font-weight: 500;
  color: var(--bc-text-secondary);
  letter-spacing: 0.3px;
}
.stat-icon {
  width: 32px;
  height: 32px;
  border-radius: 8px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
}
.tone-blue    { background: var(--bc-primary-light); color: var(--bc-primary); }
.tone-indigo  { background: rgba(110, 123, 200, .14); color: var(--bc-chart-3); }
.tone-emerald { background: var(--bc-success-bg); color: var(--bc-success); }
.tone-red     { background: var(--bc-danger-bg); color: var(--bc-danger); }
.tone-cyan    { background: rgba(46, 155, 118, .14); color: var(--bc-chart-5); }
.tone-purple  { background: var(--bc-info-bg); color: var(--bc-info); }
.tone-amber   { background: var(--bc-warning-bg); color: var(--bc-warning); }
.tone-pink    { background: rgba(168, 86, 107, .14); color: var(--bc-chart-6); }

.stat-value {
  font-family: var(--bc-font-num);
  font-size: 28px;
  font-weight: 600;
  color: var(--bc-text-primary);
  line-height: 1.15;
  letter-spacing: -.02em;
  margin-bottom: 8px;
}
/* 第二排「容量 / 次数」降级：让第一排的数量/成败成为唯一视觉焦点 */
.stat-grid--sub .stat-value { font-size: 24px; line-height: 1.2; }
.stat-grid--sub .stat-card { padding: 16px 20px; }
.stat-grid--sub .stat-head { margin-bottom: 10px; }
.stat-change {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  font-size: 12px;
  font-weight: 500;
}
.stat-change.up   { color: var(--bc-success); }
.stat-change.down { color: var(--bc-danger); }
.change-hint {
  color: var(--bc-text-secondary);
  font-weight: 400;
  margin-left: 4px;
}

.mb-block { margin-bottom: 20px; }

/* ---------- 图表区 ---------- */
.chart-grid {
  display: grid;
  grid-template-columns: minmax(0, 3fr) minmax(0, 2fr);
  gap: 16px;
  margin-bottom: 20px;
}
@media (max-width: 1279px) {
  .chart-grid { grid-template-columns: 1fr; }
}
.chart-card { padding: 20px; }
.chart-head {
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
.chart-sub.link {
  color: var(--bc-primary);
  cursor: pointer;
}
.chart-sub.link:hover { text-decoration: underline; }

.chip {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  font-size: 11px;
  padding: 1px 12px;
  border-radius: 999px;
  font-weight: 500;
  line-height: 1.6;
  letter-spacing: 0.2px;
  white-space: nowrap;
}
.chip-blue   { background: var(--bc-primary-light); color: var(--bc-primary); border: 1px solid var(--bc-primary-100); }
.chip-purple { background: var(--bc-info-bg); color: var(--bc-info); border: 1px solid var(--bc-info-border); }

.summary {
  display: flex;
  justify-content: space-between;
  padding-top: 14px;
  margin-top: 12px;
  border-top: 1px solid var(--bc-border-light);
}
.summary-label {
  font-size: 11px;
  color: var(--bc-text-secondary);
}
.summary-val {
  font-size: 15px;
  font-weight: 600;
  color: var(--bc-text-primary);
  margin-top: 2px;
}
.summary-unit {
  font-size: 11px;
  font-weight: 500;
  color: var(--bc-text-secondary);
  margin-left: 2px;
}

/* ---------- 表格 ---------- */
.table-card { padding: 0; overflow: hidden; }
.table-head {
  padding: 18px 20px;
  border-bottom: 1px solid var(--bc-border-light);
  display: flex;
  align-items: center;
  justify-content: space-between;
}
.table-wrap { overflow-x: auto; }
table {
  width: 100%;
  border-collapse: collapse;
  font-size: 13px;
}
thead tr { background: var(--bc-bg-page); }
th {
  text-align: left;
  padding: 10px 20px;
  font-size: 11px;
  font-weight: 600;
  color: var(--bc-text-secondary);
  text-transform: uppercase;
  letter-spacing: 0.4px;
  border-bottom: 1px solid var(--bc-border-light);
}
td {
  padding: 12px 20px;
  color: var(--bc-text-regular);
  border-bottom: 1px solid var(--bc-border-light);
}
tbody tr:last-child td { border-bottom: none; }
tbody tr:hover { background: var(--bc-bg-hover); }
.mono {
  font-family: 'JetBrains Mono', ui-monospace, Consolas, monospace;
  font-size: 12px;
  color: var(--bc-text-secondary);
}
.strong {
  font-weight: 600;
  color: var(--bc-text-primary);
}
.site-cell {
  display: flex;
  align-items: center;
  gap: 10px;
}
.cell-sub {
  font-size: 11px;
  color: var(--bc-text-secondary);
  margin-top: 2px;
}
.site-avatar {
  width: 28px;
  height: 28px;
  border-radius: 50%;
  background: var(--bc-primary-light);
  color: var(--bc-primary);
  font-size: 12px;
  font-weight: 600;
  display: flex;
  align-items: center;
  justify-content: center;
  border: 1px solid var(--bc-primary-100);
  flex-shrink: 0;
}
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
.badge.ok   { background: var(--bc-success-bg); color: var(--bc-success); border: 1px solid var(--bc-success-border); }
.badge.fail { background: var(--bc-danger-bg); color: var(--bc-danger); border: 1px solid var(--bc-danger-border); }
.badge.run  { background: var(--bc-warning-bg); color: var(--bc-warning); border: 1px solid var(--bc-warning-border); }

.empty {
  padding: 60px 20px;
  text-align: center;
  color: var(--bc-text-secondary);
  font-size: 13px;
}
</style>
