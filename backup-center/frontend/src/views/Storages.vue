<script setup>
import { ref, reactive, computed } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Plus, Refresh, Search, Edit, Delete, Connection, Link } from '@element-plus/icons-vue'
import { storagesApi } from '@/api/storages'
import { useAutoRefresh } from '@/composables/useAutoRefresh'
import { storageTypes, typeMeta } from '@/utils/storage-types'
import StorageFormDialog from '@/components/StorageFormDialog.vue'

const list = ref([])
const meta = reactive({ current_page: 1, total: 0, per_page: 20 })
const filters = reactive({ keyword: '', type: '', status: '' })

const statusOptions = [
  { value: '',         label: '全部状态' },
  { value: 'enabled',  label: '启用' },
  { value: 'disabled', label: '停用' },
]
const typeOptions = [
  { value: '', label: '全部类型' },
  ...storageTypes.map((t) => ({ value: t.value, label: t.label })),
]

const statusMeta = {
  enabled:  { text: '启用', cls: 'ok' },
  disabled: { text: '停用', cls: 'gray' },
}
const testMeta = {
  ok:     { text: '连接正常', cls: 'ok' },
  failed: { text: '连接失败', cls: 'fail' },
}

const doFetch = async () => {
  const res = await storagesApi.list({
    keyword: filters.keyword || undefined,
    type: filters.type || undefined,
    status: filters.status || undefined,
    page: meta.current_page,
  })
  list.value = res.data
  if (res.meta) {
    meta.current_page = res.meta.current_page
    meta.total = res.meta.total
    meta.per_page = res.meta.per_page
  }
}
const { loading, manualRefresh } = useAutoRefresh(doFetch, 60000)
const fetch = manualRefresh

const formOpen = ref(false)
const editing = ref(null)
const openCreate = () => { editing.value = null; formOpen.value = true }
const openEdit = async (row) => {
  // 先拉完整详情（含 config），再打开对话框 — 消除对话框内的异步竞态
  try {
    const detail = await storagesApi.get(row.id)
    editing.value = detail
    formOpen.value = true
  } catch (_) {
    // 拦截器已弹错
  }
}
const onSaved = () => fetch()

const testing = ref({})
const test = async (row) => {
  testing.value[row.id] = true
  try {
    const res = await storagesApi.test(row.id)
    ElMessage({ type: res.ok ? 'success' : 'warning', message: res.message })
    fetch()
  } catch (_) {
    // interceptor 弹错
  } finally {
    testing.value[row.id] = false
  }
}

const visitStorage = (row) => {
  if (!row.access_url) return
  window.open(row.access_url, '_blank', 'noopener,noreferrer')
}

const remove = (row) => {
  ElMessageBox.confirm(
    `确认删除存储"${row.name}"？关联的网站会解绑，历史备份记录保留。`,
    '删除存储',
    { type: 'warning', confirmButtonText: '删除', cancelButtonText: '取消' },
  )
    .then(async () => {
      await storagesApi.destroy(row.id)
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
  if (!n || n <= 0) return '0 B'
  const units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB']
  let v = Number(n), i = 0
  while (v >= 1024 && i < units.length - 1) { v /= 1024; i++ }
  return v.toFixed(v >= 100 || i === 0 ? 0 : 1) + ' ' + units[i]
}
const quotaPct = (row) => {
  if (!row?.quota_bytes) return 0
  return Math.round((Number(row.used_bytes || 0) / Number(row.quota_bytes)) * 100)
}
const usageClass = (row) => {
  const p = quotaPct(row)
  if (p >= 90) return 'crit'
  if (p >= 75) return 'warn'
  return 'ok'
}
</script>

<template>
  <div>
    <div class="toolbar">
      <div>
        <h2 class="page-h">存储</h2>
        <p class="page-sub">共 {{ total }} 个存储目标 · 后台每 60 秒静默刷新</p>
      </div>
      <div class="actions">
        <el-button :icon="Refresh" @click="fetch" :loading="loading">刷新</el-button>
        <el-button type="primary" :icon="Plus" @click="openCreate">添加存储</el-button>
      </div>
    </div>

    <div class="filter-bar bc-card">
      <el-input
        v-model="filters.keyword"
        placeholder="搜索名称 / 备注"
        clearable
        :prefix-icon="Search"
        style="width: 260px"
        @keyup.enter="fetch"
        @clear="fetch"
      />
      <el-select v-model="filters.type" style="width: 160px" @change="fetch">
        <el-option v-for="o in typeOptions" :key="o.value" :value="o.value" :label="o.label" />
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
        style="width: 100%"
        row-key="id"
        empty-text="暂无存储 · 点击右上角添加"
      >
        <el-table-column label="名称 / 备注" min-width="180">
          <template #default="{ row }">
            <div class="cell-name">
              <div class="storage-icon" :style="{ background: typeMeta[row.type]?.color + '15', color: typeMeta[row.type]?.color }">
                <el-icon size="18"><component :is="typeMeta[row.type]?.icon || 'Files'" /></el-icon>
              </div>
              <div class="cell-name-text">
                <div class="strong">{{ row.name }}</div>
                <div class="muted" v-if="row.remark">{{ row.remark }}</div>
              </div>
            </div>
          </template>
        </el-table-column>

        <el-table-column label="类型" width="120">
          <template #default="{ row }">
            <span class="chip chip-info">{{ typeMeta[row.type]?.label || row.type }}</span>
          </template>
        </el-table-column>

        <el-table-column label="强制备份" width="100">
          <template #default="{ row }">
            <span v-if="row.is_mandatory" class="chip chip-red">🔒 强制</span>
            <span v-else class="muted">—</span>
          </template>
        </el-table-column>

        <el-table-column label="目标路径" width="180">
          <template #default="{ row }">
            <span class="mono muted">{{ row.base_path || '/' }}</span>
          </template>
        </el-table-column>

        <el-table-column label="绑定网站" width="100">
          <template #default="{ row }">
            <span class="muted">{{ row.websites_count ?? 0 }} 个</span>
          </template>
        </el-table-column>

        <el-table-column label="连接测试" min-width="180">
          <template #default="{ row }">
            <div v-if="row.last_test_status" class="test-cell">
              <span :class="['badge', testMeta[row.last_test_status]?.cls]">
                {{ testMeta[row.last_test_status]?.text }}
              </span>
              <span class="muted" v-if="row.last_tested_at">{{ fmtTime(row.last_tested_at) }}</span>
            </div>
            <span v-else class="muted">尚未测试</span>
          </template>
        </el-table-column>

        <el-table-column label="存储资源" min-width="200">
          <template #default="{ row }">
            <div v-if="row.quota_bytes" class="quota-cell">
              <div class="quota-row">
                <span class="mono">{{ fmtBytes(row.used_bytes) }}</span>
                <span class="muted">/ {{ fmtBytes(row.quota_bytes) }}</span>
                <span class="quota-pct" :class="usageClass(row)">{{ quotaPct(row) }}%</span>
              </div>
              <div class="quota-bar">
                <div
                  class="quota-bar-fill"
                  :class="usageClass(row)"
                  :style="{ width: Math.min(100, quotaPct(row)) + '%' }"
                ></div>
              </div>
              <div class="muted" style="margin-top: 2px">
                剩余 {{ fmtBytes(Math.max(0, row.quota_bytes - row.used_bytes)) }}
              </div>
            </div>
            <div v-else-if="row.used_bytes > 0" class="quota-cell">
              <span class="mono">{{ fmtBytes(row.used_bytes) }}</span>
              <div class="muted" style="margin-top: 2px">未设配额</div>
            </div>
            <span v-else class="muted">—</span>
          </template>
        </el-table-column>

        <el-table-column label="状态" width="90">
          <template #default="{ row }">
            <span :class="['badge', statusMeta[row.status]?.cls]">
              {{ statusMeta[row.status]?.text }}
            </span>
          </template>
        </el-table-column>

        <el-table-column label="操作" width="290" fixed="right">
          <template #default="{ row }">
            <el-button v-if="row.access_url" link :icon="Link" @click="visitStorage(row)">访问</el-button>
            <el-button link :icon="Connection" :loading="testing[row.id]" @click="test(row)">测试</el-button>
            <el-button link :icon="Edit" @click="openEdit(row)">编辑</el-button>
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

    <StorageFormDialog v-model="formOpen" :storage="editing" @saved="onSaved" />
  </div>
</template>

<style scoped>
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
}

.table-card { padding: 6px 6px 12px; }
.pagination { padding: 16px 12px 8px; display: flex; justify-content: flex-end; }

.cell-name {
  display: flex;
  align-items: center;
  gap: 12px;
}
.storage-icon {
  width: 36px;
  height: 36px;
  border-radius: 10px;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}
.cell-name-text .strong { color: var(--bc-text-primary); font-weight: 500; }
.cell-name-text .muted, .muted { color: var(--bc-text-secondary); font-size: 12px; }
.strong { color: var(--bc-text-primary); font-weight: 500; }

.mono {
  font-family: 'JetBrains Mono', ui-monospace, Consolas, monospace;
  font-size: 12px;
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
.chip-info { background: #eff6ff; color: #2563eb; border: 1px solid #dbeafe; }
.chip-red  { background: #fef2f2; color: #dc2626; border: 1px solid #fecaca; }

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

.test-cell {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  flex-wrap: nowrap;
  white-space: nowrap;
}
.badge.ok   { background: #ecfdf5; color: #059669; border: 1px solid #a7f3d0; }
.badge.gray { background: #f1f5f9; color: #64748b; border: 1px solid #e2e8f0; }
.badge.fail { background: #fef2f2; color: #dc2626; border: 1px solid #fecaca; }

.quota-cell {
  font-size: 12px;
  line-height: 1.4;
}
.quota-row {
  display: flex;
  align-items: baseline;
  gap: 4px;
  margin-bottom: 4px;
}
.quota-pct {
  margin-left: auto;
  font-weight: 600;
  font-size: 12px;
}
.quota-pct.ok   { color: #059669; }
.quota-pct.warn { color: #d97706; }
.quota-pct.crit { color: #dc2626; }
.quota-bar {
  height: 6px;
  background: #e5e7eb;
  border-radius: 999px;
  overflow: hidden;
}
.quota-bar-fill {
  height: 100%;
  border-radius: 999px;
  transition: width .2s;
}
.quota-bar-fill.ok   { background: #10b981; }
.quota-bar-fill.warn { background: #f59e0b; }
.quota-bar-fill.crit { background: #ef4444; }
</style>
