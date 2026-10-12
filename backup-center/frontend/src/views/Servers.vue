<script setup>
import { ref, reactive, computed } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Plus, Refresh, Search, Edit, Delete, View, Upload, Loading } from '@element-plus/icons-vue'
import { serversApi } from '@/api/servers'
import { useAutoRefresh } from '@/composables/useAutoRefresh'
import ServerFormDialog from '@/components/ServerFormDialog.vue'
import ServerDetailDrawer from '@/components/ServerDetailDrawer.vue'

const list = ref([])
const meta = reactive({ current_page: 1, total: 0, per_page: 20 })

const filters = reactive({
  keyword: '',
  status: '',
  connect_type: '',
})

const statusOptions = [
  { value: '',        label: '全部状态' },
  { value: 'online',  label: '在线' },
  { value: 'offline', label: '离线' },
  { value: 'pending', label: '待连接' },
  { value: 'error',   label: '异常' },
]
const typeOptions = [
  { value: '',      label: '全部类型' },
  { value: 'agent', label: 'Agent 模式' },
  { value: 'ssh',   label: 'SSH 模式' },
]

const statusMeta = {
  online:  { text: '在线',   cls: 'ok' },
  offline: { text: '离线',   cls: 'gray' },
  pending: { text: '待连接', cls: 'warn' },
  error:   { text: '异常',   cls: 'fail' },
}

// 上一次的 pending_agent_command 状态快照（id -> status），用于检测状态变化后弹 toast
const lastCmdSnapshot = new Map()

const doFetch = async () => {
  const res = await serversApi.list({
    keyword: filters.keyword || undefined,
    status: filters.status || undefined,
    connect_type: filters.connect_type || undefined,
    page: meta.current_page,
  })
  list.value = res.data

  // diff：对每个 server 的 pending_agent_command 做前后对比
  for (const s of list.value) {
    const prev = lastCmdSnapshot.get(s.id) // 上次 { id, status }
    const cur = s.pending_agent_command    // 本次 { id, status, ...} or null
    if (prev && prev.id === cur?.id && prev.status !== cur.status) {
      // 同一条命令状态变了 → 弹 toast
      if (cur.status === 'success') {
        ElMessage.success(`"${s.name}" 升级成功：v${cur.from_version} → v${cur.to_version}`)
      } else if (cur.status === 'failed') {
        ElMessage.error(`"${s.name}" 升级失败：${cur.error || '未知错误'}`)
      }
    }
    lastCmdSnapshot.set(s.id, cur ? { id: cur.id, status: cur.status } : null)
  }

  if (res.meta) {
    meta.current_page = res.meta.current_page
    meta.total = res.meta.total
    meta.per_page = res.meta.per_page
  }
}

// 30 秒后台静默刷新；首次和手动才显示 loading
const { loading, manualRefresh } = useAutoRefresh(doFetch, 30000)
const fetch = manualRefresh

/* ---------- 对话框状态 ---------- */
const formOpen = ref(false)
const editing = ref(null)
const openCreate = () => {
  editing.value = null
  formOpen.value = true
}
const openEdit = (row) => {
  editing.value = row
  formOpen.value = true
}
const onSaved = () => fetch()

/* ---------- 详情抽屉 ---------- */
const detailOpen = ref(false)
const detailId = ref(null)
const openDetail = (row) => {
  detailId.value = row.id
  detailOpen.value = true
}

const remove = (row) => {
  ElMessageBox.confirm(
    `确认删除服务器"${row.name}"？该操作不可恢复。`,
    '删除服务器',
    { type: 'warning', confirmButtonText: '删除', cancelButtonText: '取消' },
  )
    .then(async () => {
      await serversApi.destroy(row.id)
      ElMessage.success('已删除')
      fetch()
    })
    .catch(() => {})
}

const fmtTime = (iso) => {
  if (!iso) return '—'
  const d = new Date(iso)
  const diff = (Date.now() - d.getTime()) / 1000
  if (diff < 60) return `${Math.floor(diff)} 秒前`
  if (diff < 3600) return `${Math.floor(diff / 60)} 分钟前`
  if (diff < 86400) return `${Math.floor(diff / 3600)} 小时前`
  return d.toLocaleDateString()
}

const total = computed(() => meta.total)

const fmtBytes = (n) => {
  if (n == null) return '—'
  const units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB']
  let v = Number(n), i = 0
  while (v >= 1024 && i < units.length - 1) { v /= 1024; i++ }
  return v.toFixed(v >= 100 || i === 0 ? 0 : 1) + ' ' + units[i]
}
const diskPct = (d) => {
  if (!d?.total) return 0
  return Math.round((Number(d.used) / Number(d.total)) * 100)
}
const diskColor = (d) => {
  const p = diskPct(d)
  if (p >= 90) return 'var(--bc-danger)'
  if (p >= 75) return 'var(--bc-warning)'
  return 'var(--bc-success)'
}

/* ---------- Agent 批量升级 ---------- */
const latestVersion = ref('')
const latestVersionError = ref(false)
const loadLatestVersion = async () => {
  latestVersionError.value = false
  try {
    const res = await serversApi.agentLatestVersion()
    latestVersion.value = res.version || ''
  } catch (_) {
    latestVersionError.value = true
    // 静默失败会让整个"可升级"体系隐身，用户会以为集群全在最新版
    console.warn('获取最新 Agent 版本失败，升级功能已隐藏')
  }
}
loadLatestVersion()

/**
 * 判断某个 Agent 服务器是否"可升级"：
 *   - 必须是 agent 模式
 *   - 必须 online（离线机器下发命令是无效噪音，还可能导致 Agent 恢复后突然自升级重启撞进备份窗口）
 *   - 已知 latestVersion 且当前版本落后
 */
const isAgentOutdated = (row) =>
  row.connect_type === 'agent' &&
  row.status === 'online' &&
  !!latestVersion.value &&
  row.agent_version &&
  row.agent_version !== latestVersion.value &&
  !isUpgrading(row)   // 正在升级中不算"可升级"，避免重复下发

const isUpgrading = (row) => {
  const c = row.pending_agent_command
  return c && (c.status === 'pending' || c.status === 'dispatched')
}

// 注意：outdatedList 只基于当前页 list（分页数据），不代表全局。UI 文案已明确"当前页"。
const outdatedList = computed(() =>
  list.value.filter(isAgentOutdated),
)

// 防重复点击：正在下发中的行 id
const dispatchingIds = ref(new Set())
const bulkDispatching = ref(false)

/**
 * 下发升级后加密轮询，让用户能实时看到"升级中 → 完成/失败"状态变化。
 * 5s、15s、30s、60s、90s 各拉一次，之后由 30s 静默刷新接管
 */
const pollAfterDispatch = () => {
  [5000, 15000, 30000, 60000, 90000].forEach((delay) => {
    setTimeout(() => doFetch().catch(() => {}), delay)
  })
}

const dispatchUpdate = (row) => {
  if (dispatchingIds.value.has(row.id)) return
  ElMessageBox.confirm(
    `确认给"${row.name}"下发升级到 v${latestVersion.value}？Agent 将在 60 秒内收到并执行，期间会短暂重启。\n\n注意：Agent 版本 < v0.7.0 的老机器不支持远程升级，需要 SSH 到目标机器手动执行 backup-agent update 或重装。`,
    '升级 Agent',
    { type: 'warning', confirmButtonText: '下发', cancelButtonText: '取消', dangerouslyUseHTMLString: false },
  )
    .then(async () => {
      const newSet = new Set(dispatchingIds.value)
      newSet.add(row.id)
      dispatchingIds.value = newSet
      try {
        const res = await serversApi.dispatchAgentUpdate(row.id)
        ElMessage.success(res.message || '已下发，请关注该行的升级进度')
        await fetch()
        pollAfterDispatch()
      } catch (_) { /* 拦截器处理 */ } finally {
        const s = new Set(dispatchingIds.value)
        s.delete(row.id)
        dispatchingIds.value = s
      }
    })
    .catch(() => {})
}

const bulkDispatch = () => {
  if (bulkDispatching.value) return
  const targets = outdatedList.value
  if (targets.length === 0) {
    ElMessage.info('当前页没有需要升级的 Agent')
    return
  }
  ElMessageBox.confirm(
    `将向当前页 ${targets.length} 台在线且版本落后的 Agent 下发升级到 v${latestVersion.value}。\n\n注意：其他分页的落后 Agent 请翻页后再次操作；Agent < v0.7.0 的老机器不支持远程升级。`,
    '批量升级 Agent（当前页）',
    { type: 'warning', confirmButtonText: '批量下发', cancelButtonText: '取消' },
  )
    .then(async () => {
      bulkDispatching.value = true
      try {
        const res = await serversApi.bulkAgentUpdate(targets.map((s) => s.id))
        ElMessage.success(res.message || '已下发，请关注升级进度')
        await fetch()
        pollAfterDispatch()
      } catch (_) { /* 拦截器处理 */ } finally {
        bulkDispatching.value = false
      }
    })
    .catch(() => {})
}
</script>

<template>
  <div>
    <!-- 顶部工具条 -->
    <div class="toolbar">
      <div>
        <h2 class="page-h">服务器</h2>
        <p class="page-sub">共 {{ total }} 台服务器 · 后台每 30 秒静默刷新</p>
      </div>
      <div class="actions">
        <el-tooltip
          v-if="latestVersionError"
          content="获取最新 Agent 版本失败，升级功能暂时不可用"
          placement="top"
        >
          <span class="ver-fail-chip" @click="loadLatestVersion">版本获取失败·点击重试</span>
        </el-tooltip>
        <el-button :icon="Refresh" @click="fetch" :loading="loading">刷新</el-button>
        <el-button
          v-if="outdatedList.length > 0"
          :icon="Upload"
          :loading="bulkDispatching"
          @click="bulkDispatch"
        >
          <span>升级当前页落后</span>
          <span class="badge-count">{{ outdatedList.length }}</span>
        </el-button>
        <el-button type="primary" :icon="Plus" @click="openCreate">添加服务器</el-button>
      </div>
    </div>

    <!-- 筛选栏 -->
    <div class="filter-bar bc-card">
      <el-input
        v-model="filters.keyword"
        placeholder="搜索名称 / IP / 备注"
        clearable
        :prefix-icon="Search"
        style="width: 260px"
        @keyup.enter="fetch"
        @clear="fetch"
      />
      <el-select v-model="filters.status" style="width: 140px" @change="fetch">
        <el-option v-for="o in statusOptions" :key="o.value" :value="o.value" :label="o.label" />
      </el-select>
      <el-select v-model="filters.connect_type" style="width: 160px" @change="fetch">
        <el-option v-for="o in typeOptions" :key="o.value" :value="o.value" :label="o.label" />
      </el-select>
      <el-button @click="fetch">应用</el-button>
    </div>

    <!-- 列表 -->
    <div class="table-card bc-card">
      <el-table
        :data="list"
        v-loading="loading"
        style="width: 100%"
        row-key="id"
        empty-text="暂无服务器 · 点击右上角添加"
      >
        <el-table-column label="名称 / 备注" min-width="132">
          <template #default="{ row }">
            <div class="cell-name">
              <div class="server-avatar" :class="row.connect_type">
                {{ (row.name || 'S').charAt(0).toUpperCase() }}
              </div>
              <div class="cell-name-text">
                <div class="strong">{{ row.name }}</div>
                <div class="muted" v-if="row.remark">{{ row.remark }}</div>
              </div>
            </div>
          </template>
        </el-table-column>

        <el-table-column label="连接方式" width="130">
          <template #default="{ row }">
            <span class="chip" :class="row.connect_type === 'agent' ? 'chip-blue' : 'chip-purple'">
              {{ row.connect_type === 'agent' ? 'Agent' : 'SSH' }}
            </span>
          </template>
        </el-table-column>

        <el-table-column label="IP / 系统" width="150">
          <template #default="{ row }">
            <div class="ellipsis" :title="row.ip || '—'">{{ row.ip || '—' }}</div>
            <div class="muted ellipsis" :title="row.os_type || '—'">{{ row.os_type || '—' }}</div>
          </template>
        </el-table-column>

        <el-table-column label="资源使用" min-width="210">
          <template #default="{ row }">
            <div v-if="row.connect_type === 'agent' && row.status === 'online'" class="usage">
              <div class="usage-row">
                <span class="u-label">CPU</span>
                <el-progress :percentage="row.cpu_usage || 0" :stroke-width="6" :show-text="false" />
                <span class="u-val">{{ row.cpu_usage ?? 0 }}%</span>
              </div>
              <div class="usage-row">
                <span class="u-label">内存</span>
                <el-progress :percentage="row.mem_usage || 0" :stroke-width="6" :show-text="false" />
                <span class="u-val">{{ row.mem_usage ?? 0 }}%</span>
              </div>

              <template v-if="row.disks && row.disks.length">
                <div
                  v-for="d in row.disks.slice(0, 3)"
                  :key="d.mount"
                  class="usage-row"
                  :title="`${d.mount} · ${d.fs} · 已用 ${fmtBytes(d.used)} / 总 ${fmtBytes(d.total)}`"
                >
                  <span class="u-label u-mount">{{ d.mount }}</span>
                  <el-progress
                    :percentage="diskPct(d)"
                    :stroke-width="6"
                    :show-text="false"
                    :color="diskColor(d)"
                  />
                  <span class="u-val">{{ diskPct(d) }}%</span>
                </div>
                <div v-if="row.disks.length > 3" class="usage-more">
                  还有 {{ row.disks.length - 3 }} 个分区，见详情
                </div>
              </template>
              <div v-else-if="row.disk_usage != null" class="usage-row">
                <span class="u-label">磁盘</span>
                <el-progress :percentage="row.disk_usage || 0" :stroke-width="6" :show-text="false" />
                <span class="u-val">{{ row.disk_usage ?? 0 }}%</span>
              </div>
            </div>
            <span v-else class="muted">—</span>
          </template>
        </el-table-column>

        <el-table-column label="Agent 版本" width="170">
          <template #default="{ row }">
            <div class="ver-cell">
              <span v-if="row.agent_version" class="mono">v{{ row.agent_version }}</span>
              <span v-else class="muted">—</span>

              <span
                v-if="isUpgrading(row)"
                class="upg-chip"
                :title="row.pending_agent_command?.status === 'pending' ? `等待 Agent 心跳（最长 60 秒）· 目标 v${row.pending_agent_command.to_version}` : `Agent 正在执行升级 → v${row.pending_agent_command.to_version}`"
              >
                <el-icon class="upg-spin"><Loading /></el-icon>
                升级中 v{{ row.pending_agent_command.to_version }}
              </span>
            </div>
          </template>
        </el-table-column>

        <el-table-column label="最后心跳" width="104">
          <template #default="{ row }">
            <span class="muted">{{ fmtTime(row.last_seen_at) }}</span>
          </template>
        </el-table-column>

        <el-table-column label="状态" width="90">
          <template #default="{ row }">
            <span :class="['badge', statusMeta[row.status]?.cls]">
              {{ statusMeta[row.status]?.text }}
            </span>
          </template>
        </el-table-column>

        <el-table-column label="操作" width="360" fixed="right">
          <template #default="{ row }">
            <div class="row-actions">
              <el-button link :icon="View" @click="openDetail(row)">详情</el-button>
              <el-button link :icon="Edit" @click="openEdit(row)">编辑</el-button>
              <el-button
                v-if="isAgentOutdated(row)"
                link
                :icon="Upload"
                type="primary"
                :loading="dispatchingIds.has(row.id)"
                :disabled="dispatchingIds.has(row.id)"
                @click="dispatchUpdate(row)"
              >升级</el-button>
              <el-button link :icon="Delete" type="danger" @click="remove(row)">删除</el-button>
            </div>
          </template>
        </el-table-column>
      </el-table>

      <div v-if="meta.total > meta.per_page" class="pagination">
        <el-pagination
          layout="prev, pager, next, total"
          :total="meta.total"
          :page-size="meta.per_page"
          :current-page="meta.current_page"
          @current-change="(p) => { meta.current_page = p; fetch(); }"
        />
      </div>
    </div>

    <ServerFormDialog v-model="formOpen" :server="editing" @saved="onSaved" />
    <ServerDetailDrawer v-model="detailOpen" :server-id="detailId" @refreshed="fetch" />
  </div>
</template>

<style scoped>
.toolbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 20px;
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
.actions {
  display: inline-flex;
  gap: 8px;
}

.filter-bar {
  display: flex;
  gap: 12px;
  align-items: center;
  margin-bottom: 16px;
  padding: 14px 16px;
}

.table-card {
  padding: 6px 6px 12px;
}
.pagination {
  padding: 16px 12px 8px;
  display: flex;
  justify-content: flex-end;
}

.cell-name {
  display: flex;
  align-items: center;
  gap: 10px;
}
.server-avatar {
  width: 32px;
  height: 32px;
  border-radius: 8px;
  color: var(--bc-on-accent);
  font-weight: 600;
  font-size: 13px;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}
.server-avatar.agent { background: linear-gradient(135deg, var(--bc-primary-400), var(--bc-primary)); }
.server-avatar.ssh   { background: linear-gradient(135deg, var(--bc-chart-3), var(--bc-info)); }
.cell-name-text .strong {
  color: var(--bc-text-primary);
  font-weight: 500;
}
.cell-name-text .muted {
  font-size: 12px;
  color: var(--bc-text-secondary);
  margin-top: 2px;
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
}
.chip-blue   { background: var(--bc-primary-light); color: var(--bc-primary); border: 1px solid var(--bc-primary-100); }
.chip-purple { background: var(--bc-info-bg); color: var(--bc-info); border: 1px solid var(--bc-info-bg); }

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
.badge.gray { background: var(--bc-bg-hover); color: var(--bc-text-secondary); border: 1px solid var(--bc-border-light); }
.badge.warn { background: var(--bc-warning-bg); color: var(--bc-warning); border: 1px solid var(--bc-warning-border); }
.badge.fail { background: var(--bc-danger-bg); color: var(--bc-danger); border: 1px solid var(--bc-danger-border); }

.usage {
  display: flex;
  flex-direction: column;
  gap: 4px;
}
.usage-row {
  display: grid;
  grid-template-columns: 60px 1fr 40px;
  align-items: center;
  gap: 6px;
}
.u-label {
  font-size: 11px;
  color: var(--bc-text-secondary);
}
.u-mount {
  font-family: 'JetBrains Mono', ui-monospace, Consolas, monospace;
  font-size: 10px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.usage-more {
  font-size: 11px;
  color: var(--bc-text-secondary);
  padding-left: 34px;
  margin-top: 2px;
}
.u-val {
  font-size: 11px;
  color: var(--bc-text-primary);
  font-variant-numeric: tabular-nums;
  text-align: right;
}

.mono {
  font-family: 'JetBrains Mono', ui-monospace, Consolas, monospace;
  font-size: 12px;
}
.ellipsis {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.muted {
  color: var(--bc-text-secondary);
  font-size: 12px;
}

.ver-cell {
  display: inline-flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 4px;
  white-space: nowrap;
}
.upg-chip {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 1px 12px;
  border-radius: 999px;
  font-size: 11px;
  font-weight: 500;
  background: var(--bc-primary-light);
  color: var(--bc-primary);
  border: 1px solid var(--bc-primary-100);
  line-height: 1.6;
  letter-spacing: 0.2px;
}
.upg-spin {
  animation: upg-rotate 1s linear infinite;
  font-size: 12px;
}
@keyframes upg-rotate {
  from { transform: rotate(0deg); }
  to   { transform: rotate(360deg); }
}
.badge-count {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 18px;
  height: 18px;
  padding: 0 5px;
  margin-left: 4px;
  border-radius: 999px;
  background: var(--bc-warning);
  color: var(--bc-on-accent);
  font-size: 11px;
  font-weight: 600;
}
.ver-fail-chip {
  display: inline-flex;
  align-items: center;
  padding: 2px 10px;
  border-radius: 999px;
  background: var(--bc-danger-bg);
  color: var(--bc-danger);
  border: 1px solid var(--bc-danger-border);
  font-size: 12px;
  cursor: pointer;
}
.ver-fail-chip:hover { background: var(--bc-danger-bg); }
.row-actions {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  flex-wrap: nowrap;
  white-space: nowrap;
}
.row-actions .el-button + .el-button {
  margin-left: 0;
}
</style>
