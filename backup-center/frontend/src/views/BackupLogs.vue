<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Refresh, Search, Delete } from '@element-plus/icons-vue'
import { backupLogsApi } from '@/api/backupLogs'
import { websitesApi } from '@/api/websites'
import { serversApi } from '@/api/servers'
import { useAutoRefresh } from '@/composables/useAutoRefresh'

const router = useRouter()
// 恢复功能已独立为「数据恢复」栏目
const gotoRestore = () => router.push('/restore')

const list = ref([])
const meta = reactive({ current_page: 1, total: 0, per_page: 20 })

const filters = reactive({
  keyword: '',
  status: '',
  website_id: '',
  server_id: '',
})

const websiteOptions = ref([])
const serverOptions = ref([])

const statusOptions = [
  { value: '',        label: '全部状态' },
  { value: 'success', label: '成功' },
  { value: 'failed',  label: '失败' },
  { value: 'running', label: '执行中' },
]

const statusMeta = {
  success: { text: '成功',   cls: 'ok' },
  failed:  { text: '失败',   cls: 'fail' },
  running: { text: '执行中', cls: 'run' },
}
const uploadMeta = {
  pending:       { text: '待上传',   cls: 'gray' },
  uploading:     { text: '上传中',   cls: 'run' },
  uploaded:      { text: '已上传',   cls: 'ok' },
  upload_failed: { text: '上传失败', cls: 'fail' },
}
// 每条 upload 记录的状态映射（不同于 backup_logs.upload_status 的聚合值）
const uploadCellMeta = {
  pending:   { text: '待上传',  cls: 'gray' },
  uploading: { text: '上传中',  cls: 'run' },
  uploaded:  { text: '已上传',  cls: 'ok' },
  failed:    { text: '失败',    cls: 'fail' },
}

const pathBasename = (p) => {
  if (!p) return ''
  const parts = String(p).split('/').filter(Boolean)
  return parts.length ? parts[parts.length - 1] : p
}
const dirname = (p) => {
  if (!p) return ''
  const s = String(p)
  const idx = s.lastIndexOf('/')
  return idx > 0 ? s.slice(0, idx) : ''
}

const doFetch = async () => {
  const res = await backupLogsApi.list({
    keyword: filters.keyword || undefined,
    status: filters.status || undefined,
    website_id: filters.website_id || undefined,
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

// 30 秒后台静默刷新
const { loading, manualRefresh } = useAutoRefresh(doFetch, 30000)
const fetch = manualRefresh

const loadFilterOptions = async () => {
  const [w, s] = await Promise.all([
    websitesApi.list({ per_page: 100 }),
    serversApi.list({ per_page: 100 }),
  ])
  websiteOptions.value = w.data || []
  serverOptions.value = s.data || []
}
onMounted(loadFilterOptions)

const remove = (row) => {
  ElMessageBox.confirm(
    `确认删除备份记录"${row.filename}"？（不会影响原备份文件，只删本平台记录）`,
    '删除备份记录',
    { type: 'warning', confirmButtonText: '删除', cancelButtonText: '取消' },
  )
    .then(async () => {
      await backupLogsApi.destroy(row.id)
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
const fmtDuration = (sec) => {
  if (!sec) return '—'
  if (sec < 60) return `${sec}s`
  const m = Math.floor(sec / 60)
  const s = sec % 60
  return s ? `${m}m ${s}s` : `${m}m`
}

const total = computed(() => meta.total)
</script>

<template>
  <div>
    <div class="toolbar">
      <div>
        <h2 class="page-h">备份记录</h2>
        <p class="page-sub">共 {{ total }} 条记录 · 后台每 30 秒静默刷新</p>
      </div>
      <div class="actions">
        <el-button :icon="Refresh" @click="fetch" :loading="loading">刷新</el-button>
      </div>
    </div>

    <div class="filter-bar bc-card">
      <el-input
        v-model="filters.keyword"
        placeholder="搜索文件名 / 路径"
        clearable
        :prefix-icon="Search"
        style="width: 240px"
        @keyup.enter="fetch"
        @clear="fetch"
      />
      <el-select
        v-model="filters.website_id"
        placeholder="所有网站"
        clearable
        filterable
        style="width: 180px"
        @change="fetch"
        @clear="fetch"
      >
        <el-option v-for="w in websiteOptions" :key="w.id" :value="w.id" :label="w.name" />
      </el-select>
      <el-select
        v-model="filters.server_id"
        placeholder="所有服务器"
        clearable
        filterable
        style="width: 180px"
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
        class="backup-logs-table"
        style="width: 100%"
        row-key="id"
        empty-text="暂无备份记录 · Agent 发现新备份文件后会自动上报"
      >
        <el-table-column type="expand">
          <template #default="{ row }">
            <div class="expand-panel">
              <div class="expand-title">上传到远端存储的结果</div>
              <div v-if="!row.uploads || row.uploads.length === 0" class="expand-empty">
                该备份没有触发任何存储上传（未配置目标存储 / 上传队列尚未启动）
              </div>
              <el-table v-else :data="row.uploads" size="small" style="width: 100%">
                <el-table-column label="存储" width="180">
                  <template #default="{ row: u }">
                    <div class="strong">{{ u.storage_name }}</div>
                    <div class="muted">{{ u.storage_type || '' }}</div>
                  </template>
                </el-table-column>
                <el-table-column label="状态" width="100">
                  <template #default="{ row: u }">
                    <span :class="['badge', uploadCellMeta[u.status]?.cls || 'gray']">
                      {{ uploadCellMeta[u.status]?.text || u.status }}
                    </span>
                  </template>
                </el-table-column>
                <el-table-column label="大小" width="90">
                  <template #default="{ row: u }">
                    <span class="muted">{{ fmtSize(u.uploaded_bytes) }}</span>
                  </template>
                </el-table-column>
                <el-table-column label="用时" width="80">
                  <template #default="{ row: u }">
                    <span class="muted">{{ fmtDuration(u.duration_seconds) }}</span>
                  </template>
                </el-table-column>
                <el-table-column label="尝试" width="70">
                  <template #default="{ row: u }">
                    <span class="muted">{{ u.attempts || 0 }} 次</span>
                  </template>
                </el-table-column>
                <el-table-column label="完成时间" width="130">
                  <template #default="{ row: u }">
                    <span class="muted">{{ fmtTime(u.finished_at) }}</span>
                  </template>
                </el-table-column>
                <el-table-column label="错误 / 远端路径" min-width="240">
                  <template #default="{ row: u }">
                    <div v-if="u.error_message" class="err-msg" :title="u.error_message">
                      ❌ {{ u.error_message }}
                    </div>
                    <div v-else-if="u.remote_path" class="mono muted" :title="u.remote_path">
                      {{ u.remote_path }}
                    </div>
                    <span v-else class="muted">—</span>
                  </template>
                </el-table-column>
              </el-table>
              <div class="expand-foot">
                <el-button link type="primary" @click="gotoRestore">去数据恢复 →</el-button>
              </div>
              <div v-if="row.error_message" class="log-err">
                <b>备份错误：</b>{{ row.error_message }}
              </div>
            </div>
          </template>
        </el-table-column>

        <el-table-column label="文件" min-width="150">
          <template #default="{ row }">
            <div class="cell-file">
              <div class="mono strong" :title="row.filename">{{ row.filename }}</div>
              <div class="muted" v-if="row.full_path && row.full_path !== row.filename" :title="row.full_path">
                {{ pathBasename(dirname(row.full_path)) || '—' }}
              </div>
            </div>
          </template>
        </el-table-column>

        <el-table-column label="网站" width="160">
          <template #default="{ row }">
            <div v-if="row.website">
              <div class="strong">{{ row.website.name }}</div>
              <div class="muted" v-if="row.website.domain">{{ row.website.domain }}</div>
            </div>
          </template>
        </el-table-column>

        <el-table-column label="服务器" width="140">
          <template #default="{ row }">
            <span v-if="row.server">{{ row.server.name }}</span>
          </template>
        </el-table-column>

        <el-table-column label="大小" width="100">
          <template #default="{ row }">
            <span class="strong">{{ fmtSize(row.size) }}</span>
          </template>
        </el-table-column>

        <el-table-column label="用时" width="90">
          <template #default="{ row }">
            <span class="muted">{{ fmtDuration(row.duration_seconds) }}</span>
          </template>
        </el-table-column>

        <el-table-column label="备份时间" width="130">
          <template #default="{ row }">
            <span class="muted">{{ fmtTime(row.backup_at) }}</span>
          </template>
        </el-table-column>

        <el-table-column label="状态" width="90">
          <template #default="{ row }">
            <span :class="['badge', statusMeta[row.status]?.cls]">
              {{ statusMeta[row.status]?.text }}
            </span>
          </template>
        </el-table-column>

        <el-table-column label="云上传" width="130">
          <template #default="{ row }">
            <span :class="['badge', uploadMeta[row.upload_status]?.cls]">
              {{ uploadMeta[row.upload_status]?.text || '—' }}
            </span>
          </template>
        </el-table-column>

        <el-table-column label="操作" width="90" fixed="right">
          <template #default="{ row }">
            <el-button link :icon="Delete" type="danger" @click="remove(row)">删除</el-button>
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
  </div>
</template>

<style scoped>
/* 主表列序：1 展开 2 文件 3 网站 4 服务器 5 大小 6 用时 7 备份时间 8 状态 9 云上传 10 操作(始终保留) */
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

.cell-file .strong { color: var(--bc-text-primary); font-weight: 500; }
.cell-file .muted { color: var(--bc-text-secondary); font-size: 11px; margin-top: 2px; }

.strong { color: var(--bc-text-primary); font-weight: 500; }
.muted  { color: var(--bc-text-secondary); font-size: 12px; }

.mono {
  font-family: 'JetBrains Mono', ui-monospace, Consolas, monospace;
  font-size: 12px;
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
.badge.gray { background: var(--bc-bg-hover); color: var(--bc-text-secondary); border: 1px solid var(--bc-border-light); }
.badge.warn { background: var(--bc-warning-bg); color: var(--bc-warning); border: 1px solid var(--bc-warning-border); }
.badge.fail { background: var(--bc-danger-bg); color: var(--bc-danger); border: 1px solid var(--bc-danger-border); }
.badge.run  { background: var(--bc-primary-light); color: var(--bc-primary); border: 1px solid var(--bc-primary-100); }

.expand-panel {
  padding: 12px 20px 8px 60px;
  background: var(--bc-bg-page);
}
.expand-title {
  font-size: 12px;
  font-weight: 600;
  color: var(--bc-text-primary);
  margin-bottom: 8px;
}
.expand-foot { margin-top: 8px; }
.expand-foot .el-button { margin-left: 0; padding-left: 0; }
.expand-empty {
  padding: 12px;
  color: var(--bc-text-secondary);
  font-size: 12px;
}
.err-msg {
  font-size: 12px;
  color: var(--bc-danger);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  max-width: 100%;
}
.log-err {
  margin-top: 10px;
  padding: 8px 10px;
  border-radius: 6px;
  background: var(--bc-danger-bg);
  border: 1px solid var(--bc-danger-border);
  color: var(--bc-danger);
  font-size: 12px;
}
</style>
