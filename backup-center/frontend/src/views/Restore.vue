<script setup>
import { ref, reactive, computed, watch, onMounted, onBeforeUnmount } from 'vue'
import { useRoute } from 'vue-router'
import { ElMessage } from 'element-plus'
import { Refresh, Calendar, Box } from '@element-plus/icons-vue'
import { restoreApi } from '@/api/restore'
import RestoreConfirmDialog from '@/components/RestoreConfirmDialog.vue'
import RestoreDetailDialog from '@/components/RestoreDetailDialog.vue'
import RestoreProgressDrawer from '@/components/RestoreProgressDrawer.vue'

const route = useRoute()

/* ---------- 筛选：网站 + 时间范围 ---------- */
const websites = ref([])
const websitesLoading = ref(true)
const selectedWebsiteId = ref(null)
const range = ref('month')
const rangeOptions = [
  { key: 'month', label: '一个月' },
  { key: 'week', label: '一周' },
  { key: '3day', label: '三天' },
  { key: '1day', label: '一天' },
]
const rangeNames = { month: '一个月', week: '一周', '3day': '三天', '1day': '一天' }

/* ---------- 套件列表（每组 = 一天，组内为多个恢复项） ---------- */
const groups = ref([])
const listLoading = ref(false)
let reqSeq = 0

/* ---------- 恢复详情弹窗 / 二次确认 ---------- */
const detailOpen = ref(false)
const detailSuite = ref(null)
const detailIds = ref([]) // 详情弹窗中勾选的 backup_log_id
const confirmOpen = ref(false)
const restoreSending = ref(false)

/* ---------- 进行中的恢复任务 ---------- */
const activeFiles = reactive({}) // { "<backup_log_id>": { filename, local_dir, size, command_id } }
const statusMap = reactive({})   // { "<backup_log_id>": { status, output, error, ... } }

/* ---------- 进度抽屉 / 轮询 ---------- */
const drawerOpen = ref(false)
const elapsed = ref(0)
let pollTimer = null
let tickTimer = null
let polling = false

// pending / dispatched 均视为「进行中」
const RUNNING = ['pending', 'dispatched']

/* ================= 数据加载 ================= */

const loadWebsites = async () => {
  try {
    const res = await restoreApi.websites()
    websites.value = res.data || []
    // 支持从备份记录页带 website_id 跳转（ux-spec §4.6）；否则默认第一个有备份的网站
    const qid = Number(route.query.website_id)
    let target = null
    if (qid && websites.value.some((w) => w.id === qid)) {
      target = qid
    } else {
      target = (websites.value.find((w) => w.last_backup_at) || websites.value[0])?.id ?? null
    }
    selectedWebsiteId.value = target
  } catch (_) {
    /* 拦截器已统一弹错 */
  } finally {
    websitesLoading.value = false
  }
}

const loadGroups = async () => {
  if (!selectedWebsiteId.value) {
    groups.value = []
    return
  }
  const seq = ++reqSeq
  listLoading.value = true
  try {
    const res = await restoreApi.backups({ website_id: selectedWebsiteId.value, range: range.value })
    if (seq !== reqSeq) return // 丢弃过期响应，防止慢请求覆盖新结果
    groups.value = res.data || []
    applyActiveCommands(res.active_commands || [])
  } catch (_) {
    // 失败时保留上一次成功结果，错误提示由拦截器统一弹出
  } finally {
    if (seq === reqSeq) listLoading.value = false
  }
}

// 刷新 / 重新进入页面后：把进行中的命令灌回 activeFiles / statusMap，并恢复轮询
// （否则用户刷新后看不到进度，进行中的任务也不再更新状态）
const applyActiveCommands = (cmds) => {
  if (!cmds.length) return
  for (const c of cmds) {
    const id = String(c.backup_log_id)
    activeFiles[id] = {
      filename: c.filename,
      local_dir: c.local_dir,
      size: fileSizeOf(c.backup_log_id),
      command_id: c.command_id,
    }
    // 已有轮询结果（含 output）时保留，仅在缺失时补种初始状态
    if (!statusMap[id]) statusMap[id] = { status: c.status, command_id: c.command_id }
  }
  // 有进行中命令时必须重启轮询（刷新后 polling 已随组件销毁而停止）
  if (cmds.some((c) => RUNNING.includes(c.status)) && !polling) startPolling()
}

// 切换网站 / 时间范围：重新拉取（进行中的恢复任务不中断）
watch([selectedWebsiteId, range], () => {
  loadGroups()
})

onMounted(loadWebsites)

/* ================= 计算属性 ================= */

const currentWebsite = computed(() => websites.value.find((w) => w.id === selectedWebsiteId.value) || null)

// 二次确认弹窗的文件清单：来自详情弹窗勾选结果（目标目录规则与原整套恢复一致）
const confirmFiles = computed(() => {
  const suite = detailSuite.value
  if (!suite) return []
  return detailIds.value
    .map((id) => (suite.files || []).find((f) => f.backup_log_id === id && f.restorable))
    .filter(Boolean)
    .map((f) => ({
      backup_log_id: f.backup_log_id,
      filename: f.filename,
      // advanced 模式恢复目标为线上 web_root（覆盖线上）；lite 保持 dirname(full_path)
      local_dir:
        currentWebsite.value?.restore_mode === 'advanced'
          ? (currentWebsite.value?.web_root || '—')
          : (dirname(f.full_path) || '—'),
      size: f.size,
    }))
})
const confirmDateLabel = computed(() => (detailSuite.value ? fmtFull(detailSuite.value.backup_at) : ''))

const activeIds = computed(() => Object.keys(activeFiles))
const activeCount = computed(() => activeIds.value.length)
const activeFilesArr = computed(() => activeIds.value.map((id) => ({ backup_log_id: Number(id), ...activeFiles[id] })))
const allTerminal = computed(
  () => activeIds.value.length > 0 && activeIds.value.every((id) => ['success', 'failed'].includes(statusMap[id]?.status)),
)
const hasAnyRestorable = computed(() => groups.value.some((g) => (g.suites || []).some((s) => s.restorable_count > 0)))

/* ================= 状态徽标 ================= */

// 后端下发的恢复项级状态（组内最坏优先）；无任何恢复状态时展示可恢复性
const restoreStateMeta = {
  pending: { cls: 'gray', text: '待执行' },
  dispatched: { cls: 'run', text: '执行中' },
  success: { cls: 'ok', text: '已恢复' },
  failed: { cls: 'fail', text: '恢复失败' },
}
// 无进行中/历史恢复状态时，展示可恢复性聚合（最坏优先）
const restorableMeta = (s) => {
  if (s.restorable_count === 0) return { cls: 'gray', text: '无成功上传' }
  if (s.restorable_count === s.total_count) return { cls: 'ok', text: '全部可恢复' }
  return { cls: 'warn', text: `${s.restorable_count}/${s.total_count} 可恢复` }
}
const headBadge = (s) => restoreStateMeta[s.restore_state] || restorableMeta(s)

/* ================= 恢复详情弹窗 ================= */

const openDetail = (s) => {
  detailSuite.value = s
  detailOpen.value = true
}

// 详情弹窗确认勾选 → 打开二次确认弹窗（高级模式的危险确认在其中）
const onDetailConfirm = (ids) => {
  detailIds.value = ids || []
  if (!detailIds.value.length) return
  confirmOpen.value = true
}

/* ================= 下发与轮询 ================= */

const onConfirmRestore = async (ids) => {
  if (restoreSending.value || !ids?.length) return
  restoreSending.value = true
  try {
    const res = await restoreApi.restore({ backup_log_ids: ids })
    const data = res.data || {}
    for (const [id, info] of Object.entries(data)) {
      activeFiles[id] = {
        filename: info.filename,
        local_dir: info.local_dir,
        size: fileSizeOf(Number(id)),
        command_id: info.command_id,
      }
      delete statusMap[id] // 重新下发：清掉旧终态，等待新一轮轮询
    }
    ElMessage.success(res.message || '已下发恢复指令')
    if (res.skipped?.length) {
      ElMessage.warning(`${res.skipped.length} 个文件被跳过：${res.skipped.map((s) => s.reason).join('；')}`)
    }
    confirmOpen.value = false
    detailOpen.value = false
    drawerOpen.value = true
    startPolling()
  } catch (_) {
    // 失败由拦截器弹错，不动 activeFiles
  } finally {
    restoreSending.value = false
  }
}

const stopPolling = () => {
  polling = false
  if (pollTimer) { clearTimeout(pollTimer); pollTimer = null }
  if (tickTimer) { clearInterval(tickTimer); tickTimer = null }
}

// 链式 setTimeout（非 setInterval）：避免慢请求重叠；stopPolling 后不再续排
const runPoll = async () => {
  if (!polling || !activeIds.value.length) return
  try {
    const res = await restoreApi.status(activeIds.value)
    const data = res.data || {}
    for (const [id, st] of Object.entries(data)) statusMap[id] = st
  } catch (_) {
    // 轮询失败静默忽略，下一轮重试
  }
  if (allTerminal.value) { stopPolling(); return }
  if (polling) pollTimer = setTimeout(runPoll, 3000)
}

const startPolling = () => {
  stopPolling()
  polling = true
  elapsed.value = 0
  tickTimer = setInterval(() => { elapsed.value += 1 }, 1000)
  runPoll()
}

// 轮询与计时定时器只在组件卸载时统一清理（关闭抽屉不取消任务）
onBeforeUnmount(stopPolling)

/* ================= 格式化 ================= */

const fmtTime = (iso) => {
  if (!iso) return '—'
  const d = new Date(iso)
  const diff = (Date.now() - d.getTime()) / 1000
  if (diff < 60) return `${Math.floor(diff)} 秒前`
  if (diff < 3600) return `${Math.floor(diff / 60)} 分钟前`
  if (diff < 86400) return `${Math.floor(diff / 3600)} 小时前`
  return d.toLocaleDateString()
}
const fmtFull = (iso) => (iso ? new Date(iso).toLocaleString() : '—')
const fmtSize = (bytes) => {
  if (!bytes) return '—'
  const units = ['B', 'KB', 'MB', 'GB', 'TB']
  let v = bytes, i = 0
  while (v >= 1024 && i < units.length - 1) {
    v /= 1024
    i++
  }
  return v.toFixed(v >= 100 || i === 0 ? 0 : 1) + ' ' + units[i]
}
const dayStart = (d) => { const x = new Date(d); x.setHours(0, 0, 0, 0); return x }
const fmtGroupDate = (dateStr) => {
  if (!dateStr) return '—'
  const d = new Date(`${dateStr}T00:00:00`)
  if (Number.isNaN(d.getTime())) return dateStr
  const today = dayStart(new Date())
  const day = dayStart(d)
  if (day.getTime() === today.getTime()) return '今天'
  if (day.getTime() === today.getTime() - 86400000) return '昨天'
  return `${d.getMonth() + 1}月${d.getDate()}日`
}
const fmtFullDate = (dateStr) => {
  if (!dateStr) return ''
  const d = new Date(`${dateStr}T00:00:00`)
  if (Number.isNaN(d.getTime())) return dateStr
  return `${d.getFullYear()}年${d.getMonth() + 1}月${d.getDate()}日`
}
const dirname = (p) => {
  if (!p) return ''
  const idx = String(p).lastIndexOf('/')
  return idx > 0 ? String(p).slice(0, idx) : ''
}
const fileTitle = (f) => {
  const lines = [f.filename]
  if (f.full_path) lines.push(f.full_path)
  if (f.latest_upload) {
    lines.push(`来源：${f.latest_upload.storage_name || '—'} · ${fmtFull(f.latest_upload.uploaded_at)}`)
    if (f.latest_upload.remote_path) lines.push(`远端路径：${f.latest_upload.remote_path}`)
  } else {
    lines.push('该文件没有成功上传到任何存储，无法从云端恢复')
  }
  return lines.join('\n')
}
// 恢复项卡片头部时刻（本地时区 HH:mm）
const hhmm = (iso) => {
  if (!iso) return '—'
  const d = new Date(iso)
  return `${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`
}
// 日期分组标题：今天 · 10月18日 / 昨天 · 10月17日 / 10月10日
const suiteDayLabel = (dateStr) => {
  if (!dateStr) return '—'
  const d = new Date(`${dateStr}T00:00:00`)
  if (Number.isNaN(d.getTime())) return dateStr
  const md = `${d.getMonth() + 1}月${d.getDate()}日`
  const rel = fmtGroupDate(dateStr)
  return rel === md ? md : `${rel} · ${md}`
}
// 恢复项总大小：优先用后端下发的 total_size，缺省时按文件累加
const suiteSize = (s) => s.total_size || (s.files || []).reduce((sum, f) => sum + (f.size || 0), 0)
// 跨套件查文件大小（下发后补 activeFiles.size、刷新灌入进行中命令时使用）
const fileSizeOf = (id) => {
  const pools = detailSuite.value ? [...(detailSuite.value.files || [])] : []
  for (const g of groups.value) for (const s of g.suites || []) pools.push(...(s.files || []))
  return pools.find((f) => f.backup_log_id === id)?.size || 0
}
</script>

<template>
  <div>
    <div class="toolbar">
      <div>
        <h2 class="page-h">数据恢复</h2>
        <p class="page-sub">
          <template v-if="currentWebsite && currentWebsite.restore_mode === 'advanced'">
            系统将自动解压覆盖网站根目录并重建导入数据库（覆盖线上站点）
          </template>
          <template v-else>
            把云端备份取回服务器原备份目录 · 仅下载归档，不解压、不导库
          </template>
        </p>
      </div>
      <div class="actions">
        <el-button :icon="Refresh" :loading="listLoading" @click="loadGroups">刷新</el-button>
      </div>
    </div>

    <div class="filter-bar bc-card">
      <el-select
        v-model="selectedWebsiteId"
        placeholder="所有网站"
        clearable
        filterable
        style="width: 240px"
      >
        <el-option v-for="w in websites" :key="w.id" :value="w.id" :label="w.name">
          <div class="opt-main">
            <span class="opt-name">{{ w.name }}</span>
            <span v-if="w.domain" class="opt-domain">{{ w.domain }}</span>
          </div>
          <div class="opt-sub">
            {{ w.server_name || '未知服务器' }} ·
            <template v-if="w.last_backup_at">最近备份 {{ fmtTime(w.last_backup_at) }}</template>
            <template v-else>从未备份</template>
          </div>
        </el-option>
      </el-select>

      <!-- 时间范围：复用 Dashboard .range-tabs 分段选择器 -->
      <div class="range">
        <el-icon size="14" style="color: var(--bc-text-secondary)"><Calendar /></el-icon>
        <div class="range-tabs">
          <button
            v-for="r in rangeOptions"
            :key="r.key"
            type="button"
            :class="['range-btn', { active: range === r.key }]"
            @click="range = r.key"
          >{{ r.label }}</button>
        </div>
      </div>
    </div>

    <!-- 进行中恢复任务横幅：刷新/重新进入后依然可见，点击查看进度 -->
    <div v-if="activeCount > 0" class="restore-active-bar" @click="drawerOpen = true">
      <el-icon size="14"><Refresh /></el-icon>
      <span>{{ activeCount }} 个恢复任务进行中 · 点击查看进度 →</span>
    </div>

    <div v-loading="listLoading || websitesLoading" class="restore-list">
      <!-- 未选网站 -->
      <div v-if="!selectedWebsiteId" class="empty">
        <el-icon class="empty-icon"><Box /></el-icon>
        <p class="empty-title">尚未选择网站</p>
        <p class="empty-sub">从上方选择一台服务器上的网站</p>
      </div>

      <!-- 空态 a：从未备份 / 空态 b：范围内无备份 -->
      <div v-else-if="!groups.length" class="empty">
        <template v-if="currentWebsite && !currentWebsite.last_backup_at">
          <el-icon class="empty-icon"><Box /></el-icon>
          <p class="empty-title">该网站还没有任何备份记录</p>
          <p class="empty-sub">Agent 发现新备份文件后会自动上报</p>
        </template>
        <template v-else>
          <el-icon class="empty-icon"><Box /></el-icon>
          <p class="empty-title">最近{{ rangeNames[range] }}内没有备份</p>
          <p class="empty-sub">最后一次备份：{{ fmtFull(currentWebsite?.last_backup_at) }}，可放宽时间范围试试</p>
        </template>
      </div>

      <!-- 空态 c：有记录但全部未成功上传（卡片仍展示，顶部警示条） -->
      <template v-else>
        <div v-if="!hasAnyRestorable" class="restore-warn-bar">
          这些备份都尚未成功上传到云端存储，暂不可恢复
        </div>

        <div class="suite-grid">
          <template v-for="g in groups" :key="g.date">
            <!-- 日期分组标题 -->
            <div class="suite-day" :title="fmtFullDate(g.date)">{{ suiteDayLabel(g.date) }}</div>
            <!-- 一个恢复项 = 一次备份（同批次产生的多个文件已合并） -->
            <div v-for="s in g.suites" :key="s.suite_id" class="restore-group">
              <div
                class="restore-set bc-card"
                :class="{ 'is-disabled': s.restorable_count === 0 }"
                role="button"
                :aria-label="`查看 ${fmtFull(s.backup_at)} 备份（${s.total_count} 个文件）的恢复详情`"
                :title="`${fmtFull(s.backup_at)} · 点击查看恢复详情`"
                tabindex="0"
                @click="openDetail(s)"
                @keydown.enter.prevent="openDetail(s)"
                @keydown.space.prevent="openDetail(s)"
              >
                <div class="restore-set-head">
                  <span class="restore-set-date" :title="fmtFull(s.backup_at)">{{ hhmm(s.backup_at) }}</span>
                  <span :class="['badge', headBadge(s).cls]">{{ headBadge(s).text }}</span>
                  <span class="restore-set-meta">{{ s.total_count }} 个文件 · {{ fmtSize(suiteSize(s)) }}</span>
                </div>

                <div class="file-list">
                  <div
                    v-for="f in s.files"
                    :key="f.backup_log_id"
                    class="file-row"
                    :class="{ 'is-unrestorable': !f.restorable }"
                    :title="fileTitle(f)"
                  >
                    <span class="chip" :class="f.type === 'database' ? 'chip-purple' : (f.type === 'snapshot' ? 'chip-snap' : 'chip-blue')">
                      {{ f.type === 'database' ? '数据库' : f.type === 'snapshot' ? '增量快照' : '网站目录' }}
                    </span>
                    <span class="file-name mono" :title="f.filename">{{ f.filename }}</span>
                    <span class="file-size">{{ fmtSize(f.size) }}</span>
                    <div class="file-stores">
                      <template v-if="f.latest_upload">
                        <span class="chip chip-neutral" :title="f.latest_upload.storage_name">{{ f.latest_upload.storage_name }}</span>
                        <span class="file-upload-time" :title="fmtFull(f.latest_upload.uploaded_at)">
                          {{ fmtTime(f.latest_upload.uploaded_at) }} 上传
                        </span>
                      </template>
                      <span v-else class="badge gray">无成功上传</span>
                    </div>
                  </div>
                </div>

                <div v-if="s.restore_state" class="restore-set-foot">
                  <el-button link type="primary" @click.stop="drawerOpen = true">查看进度 →</el-button>
                </div>
                <div v-else-if="s.restorable_count < s.total_count" class="restore-set-foot">
                  <span class="foot-warn">仅 {{ s.restorable_count }} / {{ s.total_count }} 个文件可恢复</span>
                </div>
              </div>
            </div>
          </template>
        </div>
      </template>
    </div>

    <RestoreConfirmDialog
      v-model="confirmOpen"
      :files="confirmFiles"
      :date-label="confirmDateLabel"
      :total-count="detailSuite?.total_count || 0"
      :sending="restoreSending"
      :restore-mode="currentWebsite?.restore_mode || 'lite'"
      @confirm="onConfirmRestore(detailIds)"
    />
    <RestoreDetailDialog
      v-model="detailOpen"
      :suite="detailSuite"
      :website="currentWebsite"
      :status-map="statusMap"
      :sending="restoreSending"
      @confirm="onDetailConfirm"
    />
    <RestoreProgressDrawer
      v-model="drawerOpen"
      :files="activeFilesArr"
      :status-map="statusMap"
      :elapsed="elapsed"
      :restore-mode="currentWebsite?.restore_mode || 'lite'"
    />
  </div>
</template>

<style scoped>
/* ---- 复制自 Websites.vue（scoped 样式不跨组件） ---- */
.toolbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 20px;
}
.page-h { margin: 0; font-size: 20px; font-weight: 600; color: var(--bc-text-primary); }
.page-sub { margin: 4px 0 0; font-size: 12px; color: var(--bc-text-secondary); }
.actions { display: inline-flex; gap: 8px; }

.filter-bar {
  display: flex;
  gap: 12px;
  align-items: center;
  margin-bottom: 16px;
  padding: 14px 16px;
  flex-wrap: wrap;
}

.chip {
  display: inline-flex;
  align-items: center;
  font-size: 11px;
  padding: 1px 12px;
  border-radius: 999px;
  font-weight: 500;
  line-height: 1.6;
  letter-spacing: 0.2px;
  white-space: nowrap;
  flex-shrink: 0;
}
.chip-blue    { background: var(--bc-primary-light); color: var(--bc-primary); border: 1px solid var(--bc-primary-100); }
.chip-purple  { background: var(--bc-info-bg); color: var(--bc-info); border: 1px solid var(--bc-info-border); }
.chip-snap    { background: var(--bc-success-bg); color: var(--bc-success); border: 1px solid var(--bc-success-border); }
.chip-neutral { background: var(--bc-bg-inset); color: var(--bc-text-secondary); border: 1px solid var(--bc-border-light); }

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
  flex-shrink: 0;
}
.badge.ok   { background: var(--bc-success-bg); color: var(--bc-success); border: 1px solid var(--bc-success-border); }
.badge.gray { background: var(--bc-bg-hover); color: var(--bc-text-secondary); border: 1px solid var(--bc-border-light); }
.badge.warn { background: var(--bc-warning-bg); color: var(--bc-warning); border: 1px solid var(--bc-warning-border); }
.badge.fail { background: var(--bc-danger-bg); color: var(--bc-danger); border: 1px solid var(--bc-danger-border); }
.badge.run  { background: var(--bc-primary-light); color: var(--bc-primary); border: 1px solid var(--bc-primary-100); }

.mono { font-family: 'JetBrains Mono', ui-monospace, Consolas, monospace; }
.muted { color: var(--bc-text-secondary); font-size: 12px; }

/* ---- 复制自 Dashboard.vue 的 .range 分段选择器（active 底色用 token，过渡显式列举） ---- */
.range {
  display: inline-flex;
  align-items: center;
  gap: 10px;
  padding: 6px 8px 6px 12px;
  background: var(--bc-bg-card);
  border: 1px solid var(--bc-border-light);
  border-radius: var(--bc-radius);
  box-shadow: var(--bc-shadow-sm);
  margin-left: auto;
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
  font-weight: 500;
  min-width: 36px;
  transition: color var(--bc-dur) var(--bc-ease), background-color var(--bc-dur) var(--bc-ease);
}
.range-btn:hover { color: var(--bc-text-primary); }
.range-btn.active {
  background: var(--bc-bg-card);
  color: var(--bc-primary);
  box-shadow: var(--bc-shadow-sm);
}

/* 网站下拉选项 */
.opt-main { display: flex; align-items: center; gap: 8px; min-width: 0; }
.opt-name { font-weight: 500; color: var(--bc-text-primary); white-space: nowrap; }
.opt-domain {
  font-size: 11px;
  color: var(--bc-text-placeholder);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.opt-sub { font-size: 11px; color: var(--bc-text-secondary); margin-top: 1px; }

/* ---- 进行中恢复任务横幅（primary 配色，点击打开进度抽屉） ---- */
.restore-active-bar {
  display: flex;
  align-items: center;
  gap: 8px;
  background: var(--bc-primary-light);
  border: 1px solid var(--bc-primary-100);
  border-radius: 8px;
  padding: 10px 12px;
  margin-bottom: 16px;
  font-size: 12px;
  color: var(--bc-primary);
  cursor: pointer;
  transition: background-color var(--bc-dur) var(--bc-ease);
}
.restore-active-bar:hover { background: var(--bc-primary-50); }

/* ---- 套件网格 ---- */
.restore-list { min-height: 200px; }
.suite-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(360px, 1fr));
  gap: 16px;
}
/* 分组切换依次浮现（reduced-motion 由全局兜底） */
.suite-grid > * { animation: bc-rise .45s var(--bc-ease-out) backwards; }
.suite-grid > :nth-child(1) { animation-delay: .04s; }
.suite-grid > :nth-child(2) { animation-delay: .08s; }
.suite-grid > :nth-child(3) { animation-delay: .12s; }
.suite-grid > :nth-child(4) { animation-delay: .16s; }

/* 日期分组标题（组内一个恢复项一张卡） */
.suite-day {
  grid-column: 1 / -1;
  margin: 4px 0 10px;
  font-size: 13px;
  font-weight: 600;
  color: var(--bc-text-secondary);
}
.suite-day:first-child { margin-top: 0; }
.restore-group { min-width: 0; }

.restore-set {
  position: relative;
  display: flex;
  flex-direction: column;
  gap: 12px;
  padding: 16px;
  height: 100%; /* 同行卡片等高，状态区底对齐 */
  min-width: 0;
  cursor: pointer; /* 整卡可点：打开恢复详情 */
}
.restore-set:focus-visible { outline: none; box-shadow: var(--bc-ring); }
.restore-set:not(.is-disabled):hover {
  border-color: var(--bc-border-light);
  box-shadow: var(--bc-shadow);
  transform: translateY(-1px);
}
.restore-set.is-disabled { opacity: .62; }

/* 卡片头部即入口：点击打开恢复详情弹窗 */
.restore-set-head {
  display: flex;
  align-items: center;
  gap: 8px;
  min-width: 0;
  min-height: 28px;
  cursor: pointer;
  border-radius: var(--bc-radius-sm);
}
.restore-set-head:focus-visible { outline: none; box-shadow: var(--bc-ring); }
.restore-set-date {
  font-size: var(--bc-fs-md);
  font-weight: 600;
  color: var(--bc-text-primary);
  white-space: nowrap;
  font-variant-numeric: tabular-nums;
}
.restore-set-meta {
  margin-left: auto;
  font-size: var(--bc-fs-xs);
  color: var(--bc-text-secondary);
  white-space: nowrap;
  font-variant-numeric: tabular-nums;
}

/* ---- 文件行 ---- */
.file-list { display: flex; flex-direction: column; gap: 8px; min-width: 0; }
.file-row {
  display: grid;
  grid-template-columns: auto minmax(0, 1fr) auto;
  align-items: center;
  gap: 4px 8px;
  min-width: 0;
}
.file-row.is-unrestorable { opacity: .55; }
.file-name {
  font-family: var(--bc-font-mono);
  font-size: var(--bc-fs-xs);
  font-weight: 500;
  color: var(--bc-text-primary);
  min-width: 0;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.file-size {
  font-family: var(--bc-font-mono);
  font-size: var(--bc-fs-xs);
  color: var(--bc-text-regular);
  font-variant-numeric: tabular-nums;
  flex-shrink: 0;
}
.file-stores {
  grid-column: 2 / -1;
  display: flex;
  align-items: center;
  gap: 4px;
  flex-wrap: wrap;
  min-width: 0;
}
.file-upload-time {
  font-size: var(--bc-fs-2xs);
  color: var(--bc-text-placeholder);
  white-space: nowrap;
}

.restore-set-foot {
  margin-top: auto;
  padding-top: 12px;
  border-top: 1px solid var(--bc-border-hair);
  min-width: 0;
}
.restore-set-foot .el-button { margin-left: 0; padding-left: 0; }
.foot-warn { font-size: var(--bc-fs-2xs); color: var(--bc-warning); }

/* ---- 空态 c 警示条（复用 .bind-notice 视觉语法） ---- */
.restore-warn-bar {
  background: var(--bc-warning-bg);
  border: 1px solid var(--bc-warning-border);
  border-radius: 8px;
  padding: 10px 12px;
  margin-bottom: 16px;
  font-size: 12px;
  color: var(--bc-warning);
}

/* ---- 空态 ---- */
.empty {
  padding: 56px 20px;
  text-align: center;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 4px;
}
.empty-icon {
  font-size: 40px;
  color: var(--bc-border-strong);
  margin-bottom: 8px;
}
.empty-title { margin: 0; font-size: var(--bc-fs-sm); color: var(--bc-text-secondary); font-weight: 500; }
.empty-sub { margin: 0; font-size: var(--bc-fs-xs); color: var(--bc-text-placeholder); }

/* ---- 响应式 ---- */
@media (max-width: 1180px) {
  .suite-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}
@media (max-width: 760px) {
  .suite-grid { grid-template-columns: minmax(0, 1fr); }
  .restore-set { padding: 12px; }
  .range { margin-left: 0; width: 100%; justify-content: space-between; }
}
</style>
