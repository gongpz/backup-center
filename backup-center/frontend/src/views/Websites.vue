<script setup>
import { ref, reactive, onMounted, computed } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Plus, Refresh, Search, Edit, Delete, Sort, Key, Box } from '@element-plus/icons-vue'
import { websitesApi } from '@/api/websites'
import { serversApi } from '@/api/servers'
import { useAutoRefresh } from '@/composables/useAutoRefresh'
import { humanize as humanizeSchedule } from '@/utils/schedule'
import WebsiteFormDialog from '@/components/WebsiteFormDialog.vue'
import WebsiteBindDialog from '@/components/WebsiteBindDialog.vue'

const list = ref([])
const meta = reactive({ current_page: 1, total: 0, per_page: 20 })

const filters = reactive({
  keyword: '',
  status: '',
  server_id: '',
})

const serverOptions = ref([])
const statusOptions = [
  { value: '',         label: '全部状态' },
  { value: 'enabled',  label: '启用' },
  { value: 'disabled', label: '停用' },
]

const statusMeta = {
  enabled:  { text: '启用', cls: 'ok' },
  disabled: { text: '停用', cls: 'gray' },
}
const backupMeta = {
  success: { text: '成功',   cls: 'ok' },
  failed:  { text: '失败',   cls: 'fail' },
  running: { text: '执行中', cls: 'run' },
}

const doFetch = async () => {
  const res = await websitesApi.list({
    keyword: filters.keyword || undefined,
    status: filters.status || undefined,
    server_id: filters.server_id || undefined,
    page: meta.current_page,
  })
  list.value = res.data
  if (res.meta) {
    meta.current_page = res.meta.current_page
    meta.total = res.meta.total
    meta.per_page = res.meta.per_page
  }
}

// 60 秒后台静默刷新；首次和手动才显示 loading
const { loading, manualRefresh } = useAutoRefresh(doFetch, 60000)
const fetch = manualRefresh

const loadServers = async () => {
  const res = await serversApi.list({ per_page: 100 })
  serverOptions.value = res.data || []
}

/* ---------- 对话框 ---------- */
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

/* 根目录绑定对话框 */
const bindOpen = ref(false)
const bindingWebsite = ref(null)
const openBind = (row) => {
  bindingWebsite.value = row
  bindOpen.value = true
}
const onBindSaved = () => fetch()

// 一键同步：触发所属 Agent 立即扫描 backup_paths，把未上传的文件推到各存储
const syncingIds = ref(new Set())
const syncNow = async (row) => {
  if (syncingIds.value.has(row.id)) return
  syncingIds.value = new Set([...syncingIds.value, row.id])
  try {
    const res = await websitesApi.syncNow(row.id)
    ElMessage.success(res.message || '已下发同步指令，最长 60 秒内 Agent 会执行')
  } catch (_) { /* interceptor 弹错 */   } finally {
    const s = new Set(syncingIds.value)
    s.delete(row.id)
    syncingIds.value = s
  }
}

// 立即自主备份（P3）：仅 self 来源网站显示；复用 websitesApi.selfBackup 下发指令
const selfBackingIds = ref(new Set())
const selfBackupNow = async (row) => {
  if (selfBackingIds.value.has(row.id)) return
  selfBackingIds.value = new Set([...selfBackingIds.value, row.id])
  try {
    const res = await websitesApi.selfBackup(row.id)
    ElMessage.success(res.message || '已下发自主备份指令，最长 60 秒内 Agent 会执行')
  } catch (_) { /* interceptor 弹错 */ } finally {
    const s = new Set(selfBackingIds.value)
    s.delete(row.id)
    selfBackingIds.value = s
  }
}

const remove = (row) => {
  ElMessageBox.confirm(
    `确认删除网站"${row.name}"？备份记录也会一并删除。`,
    '删除网站',
    { type: 'warning', confirmButtonText: '删除', cancelButtonText: '取消' },
  )
    .then(async () => {
      await websitesApi.destroy(row.id)
      ElMessage.success('已删除')
      fetch()
    })
    .catch(() => {})
}

// 服务器筛选下拉的选项加载（一次性）
onMounted(loadServers)

const fmtTime = (iso) => {
  if (!iso) return '—'
  const d = new Date(iso)
  const diff = (Date.now() - d.getTime()) / 1000
  if (diff < 60) return `${Math.floor(diff)} 秒前`
  if (diff < 3600) return `${Math.floor(diff / 60)} 分钟前`
  if (diff < 86400) return `${Math.floor(diff / 3600)} 小时前`
  return d.toLocaleDateString()
}
const fmtFull = (iso) => iso ? new Date(iso).toLocaleString() : '—'

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

// 只取路径最后一段（比如 /www/backup/database/mysql/keyaml → keyaml）
const pathBasename = (p) => {
  if (!p) return ''
  const parts = String(p).split('/').filter(Boolean)
  return parts.length ? parts[parts.length - 1] : p
}

/**
 * 一条 backup_log 的综合上传结果：
 *   ok      = 所有 uploads 都 uploaded（或没配任何存储 → 只发现文件本身也算 ok）
 *   partial = 至少一个成功且至少一个失败
 *   fail    = 全部失败或 backup_log.status=failed
 *   pending = 上传还在进行
 */
const uploadOverallCls = (log) => {
  if (!log) return 'gray'
  if (log.status === 'failed') return 'fail'
  const s = log.succeeded?.length || 0
  const f = log.failed?.length || 0
  if (s === 0 && f === 0) return 'ok'   // 没配 storage 也算 ok（本地发现即成功）
  if (f === 0) return 'ok'
  if (s === 0) return 'fail'
  return 'partial'
}

const total = computed(() => meta.total)
</script>

<template>
  <div>
    <div class="toolbar">
      <div>
        <h2 class="page-h">网站</h2>
        <p class="page-sub">共 {{ total }} 个网站 · 后台每 60 秒静默刷新</p>
      </div>
      <div class="actions">
        <el-button :icon="Refresh" @click="fetch" :loading="loading">刷新</el-button>
        <el-button type="primary" :icon="Plus" @click="openCreate">添加网站</el-button>
      </div>
    </div>

    <div class="filter-bar bc-card">
      <el-input
        v-model="filters.keyword"
        placeholder="搜索名称 / 域名 / 目录 / 备注"
        clearable
        :prefix-icon="Search"
        style="width: 300px"
        @keyup.enter="fetch"
        @clear="fetch"
      />
      <el-select
        v-model="filters.server_id"
        placeholder="所属服务器"
        clearable
        filterable
        style="width: 200px"
        @change="fetch"
        @clear="fetch"
      >
        <el-option v-for="s in serverOptions" :key="s.id" :value="s.id" :label="s.name" />
      </el-select>
      <el-select v-model="filters.status" style="width: 140px" @change="fetch">
        <el-option v-for="o in statusOptions" :key="o.value" :value="o.value" :label="o.label" />
      </el-select>
      <el-button @click="fetch">应用</el-button>
    </div>

    <div class="table-card bc-card">
      <el-table
        :data="list"
        v-loading="loading"
        class="websites-table"
        style="width: 100%"
        row-key="id"
        empty-text="暂无网站 · 点击右上角添加"
      >
        <el-table-column label="网站" min-width="190">
          <template #default="{ row }">
            <div class="cell-name">
              <div class="site-avatar">{{ (row.name || 'W').charAt(0).toUpperCase() }}</div>
              <div class="cell-name-text">
                <div class="strong">{{ row.name }}</div>
                <div class="muted" v-if="row.domain">{{ row.domain }}</div>
              </div>
            </div>
          </template>
        </el-table-column>

        <el-table-column label="所属服务器" min-width="180">
          <template #default="{ row }">
            <div v-if="row.server" class="server-cell">
              <span class="strong">{{ row.server.name }}</span>
              <span
                class="chip"
                :class="row.server.connect_type === 'agent' ? 'chip-blue' : 'chip-purple'"
              >
                {{ row.server.connect_type === 'agent' ? 'Agent' : 'SSH' }}
              </span>
            </div>
          </template>
        </el-table-column>

        <el-table-column label="备份目录" min-width="220">
          <template #default="{ row }">
            <div v-if="row.backup_paths?.length" class="paths-cell">
              <div
                v-for="(bp, idx) in row.backup_paths"
                :key="idx"
                class="paths-row"
              >
                <span class="chip chip-blue" :title="bp.label || `目录${idx + 1}`">{{ bp.label || `目录${idx + 1}` }}</span>
                <span class="chip chip-purple mono" :title="bp.path">{{ pathBasename(bp.path) }}</span>
                <span v-if="bp.pattern && bp.pattern !== '*'" class="chip chip-cyan" :title="bp.pattern">{{ bp.pattern }}</span>
              </div>
            </div>
            <span v-else class="muted">未配置</span>
          </template>
        </el-table-column>

        <el-table-column label="网站根目录" min-width="200">
          <template #default="{ row }">
            <div v-if="row.web_root" class="root-cell">
              <span class="chip chip-cyan mono" :title="`绑定于 ${fmtFull(row.root_bound_at)}`">{{ row.web_root }}</span>
            </div>
            <span v-else class="muted">未绑定</span>
          </template>
        </el-table-column>

        <el-table-column label="最近备份" min-width="230">
          <template #default="{ row }">
            <div v-if="(row.backup_source === 'self' ? row.recent_self_backups : row.recent_backups)?.length" class="recent-backups">
              <div
                v-for="(rb, idx) in (row.backup_source === 'self' ? row.recent_self_backups : row.recent_backups)"
                :key="idx"
                class="rb-row"
              >
                <template v-if="rb.log">
                  <span
                    v-if="uploadOverallCls(rb.log) === 'ok'"
                    class="badge ok"
                    :title="rb.log.succeeded.length ? `已上传 ${rb.log.succeeded.length} 个存储：${rb.log.succeeded.map(s=>s.storage_name).join('、')}` : '成功'"
                  >成功</span>
                  <span
                    v-else-if="uploadOverallCls(rb.log) === 'partial'"
                    class="badge warn"
                    :title="`失败 ${rb.log.failed.length} 个：${rb.log.failed.map(f => f.storage_name).join('、')}`"
                  >部分失败 · {{ rb.log.failed.map(f => f.storage_name).join('、') }}</span>
                  <span
                    v-else-if="uploadOverallCls(rb.log) === 'fail'"
                    class="badge fail"
                    :title="rb.log.failed.map(f => f.storage_name + ': ' + (f.error || '未知错误')).join('\n')"
                  >失败 · {{ rb.log.failed.map(f => f.storage_name).join('、') }}</span>
                  <span
                    v-else
                    class="badge gray"
                  >{{ backupMeta[rb.log.status]?.text || rb.log.status }}</span>
                  <span class="muted rb-time" :title="`源文件时间：${fmtFull(rb.log.backup_at)}\n同步时间：${fmtFull(rb.log.synced_at)}`">
                    <span class="rb-time-primary">同步于 {{ fmtTime(rb.log.synced_at || rb.log.backup_at) }}</span>
                    <span v-if="rb.log.synced_at && rb.log.backup_at && rb.log.synced_at !== rb.log.backup_at" class="rb-time-sub">
                      · 备份于 {{ fmtTime(rb.log.backup_at) }}
                    </span>
                  </span>
                </template>
                <span v-else class="muted rb-empty">— 从未备份</span>
              </div>
            </div>
            <span v-else class="muted">— 未配置备份目录</span>
          </template>
        </el-table-column>

        <el-table-column label="备份计划" width="130">
          <template #default="{ row }">
            <span v-if="row.schedule" class="chip chip-cyan">
              {{ humanizeSchedule(row.schedule) }}
            </span>
            <span v-else class="muted">未启用</span>
          </template>
        </el-table-column>

        <el-table-column label="保留" width="80">
          <template #default="{ row }">
            <span class="muted">{{ row.retention_days }} 天</span>
          </template>
        </el-table-column>

        <el-table-column label="状态" width="90">
          <template #default="{ row }">
            <span :class="['badge', statusMeta[row.status]?.cls]">
              {{ statusMeta[row.status]?.text }}
            </span>
          </template>
        </el-table-column>

        <el-table-column label="操作" width="260" fixed="right">
          <template #default="{ row }">
            <div class="row-actions">
              <el-button
                v-if="row.backup_source !== 'self'"
                link
                :icon="Sort"
                type="primary"
                :loading="syncingIds.has(row.id)"
                @click="syncNow(row)"
              >同步</el-button>
              <el-button
                v-if="row.backup_source === 'self'"
                link
                :icon="Box"
                type="primary"
                :loading="selfBackingIds.has(row.id)"
                @click="selfBackupNow(row)"
              >备份</el-button>
              <el-button link :icon="Key" @click="openBind(row)">绑定</el-button>
              <el-button link :icon="Edit" @click="openEdit(row)">编辑</el-button>
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

    <WebsiteFormDialog v-model="formOpen" :website="editing" @saved="onSaved" />
    <WebsiteBindDialog v-model="bindOpen" :website="bindingWebsite" @saved="onBindSaved" />
  </div>
</template>

<style scoped>
/* 列序：1 网站 2 所属服务器 3 备份目录 4 网站根目录 5 最近备份 6 备份计划 7 保留 8 状态 9 操作(始终保留) */
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

.table-card { padding: 6px 6px 12px; }
.pagination {
  padding: 16px 12px 8px;
  display: flex;
  justify-content: flex-end;
}

.cell-name { display: flex; align-items: center; gap: 10px; }
.site-avatar {
  width: 32px;
  height: 32px;
  border-radius: 8px;
  background: linear-gradient(135deg, var(--bc-chart-5), var(--bc-success));
  color: var(--bc-on-accent);
  font-weight: 600;
  font-size: 13px;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}
.cell-name-text .strong { color: var(--bc-text-primary); font-weight: 500; }
.cell-name-text .muted, .muted { color: var(--bc-text-secondary); font-size: 12px; }
.mono {
  font-family: 'JetBrains Mono', ui-monospace, Consolas, monospace;
  font-size: 12px;
  color: var(--bc-text-primary);
}
.strong { color: var(--bc-text-primary); font-weight: 500; }

.paths-cell {
  display: flex;
  flex-direction: column;
  gap: 6px;
  padding: 2px 0;
}
.root-cell {
  display: flex;
  align-items: center;
  gap: 6px;
}
.root-cell .chip { max-width: 100%; }
.root-cell .mono { overflow: hidden; text-overflow: ellipsis; }
.paths-row {
  display: flex;
  align-items: center;
  gap: 6px;
  flex-wrap: wrap;
  line-height: 1.4;
}
/* 已合并到统一的 .chip 样式 */
.paths-path {
  word-break: break-all;
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

.server-cell {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  flex-wrap: nowrap;
  white-space: nowrap;
}
.chip-blue   { background: var(--bc-primary-light); color: var(--bc-primary); border: 1px solid var(--bc-primary-100); }
.chip-purple { background: var(--bc-info-bg); color: var(--bc-info); border: 1px solid var(--bc-info-border); }
.chip-cyan   { background: rgba(46, 155, 118, .14); color: var(--bc-chart-5); border: 1px solid rgba(46, 155, 118, .22); }

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
.badge.run  { background: var(--bc-primary-light); color: var(--bc-primary); border: 1px solid var(--bc-primary-100); }

.recent-backups {
  display: flex;
  flex-direction: column;
  gap: 4px;
  padding: 2px 0;
}
.rb-row {
  display: flex;
  align-items: center;
  gap: 6px;
  flex-wrap: nowrap;
  white-space: nowrap;
  line-height: 1.5;
}
/* rb-label 已删除 —— 最近备份列不再展示自定义目录标签 */
.rb-time { font-size: 11px; display: inline-flex; gap: 4px; align-items: baseline; }
.rb-time-primary { color: var(--bc-text-secondary); }
.rb-time-sub { color: var(--bc-text-placeholder); font-size: 10px; }
.rb-empty { font-size: 12px; }

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
