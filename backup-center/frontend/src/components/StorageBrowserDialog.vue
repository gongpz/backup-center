<script setup>
import { ref, computed, watch } from 'vue'
import { FolderOpened, Document } from '@element-plus/icons-vue'
import { storagesApi } from '@/api/storages'

const props = defineProps({
  modelValue: { type: Boolean, default: false },
  storageId: { type: Number, default: null },
  // 选填：传了服务端直达该网站的增量快照根（ Websites.vue 增量站入口用）
  websiteId: { type: Number, default: null },
  // 选填：表单已填的目录（如 目标路径），打开时直接定位到该文件夹
  initialPath: { type: String, default: '' },
  // 选填：true 时底部显示「选择此目录」，供表单回填目录
  selectable: { type: Boolean, default: false },
  // 选填：预览态（新建存储尚未保存，无 storageId）。传 true 时改用 previewFiles，
  // 用 previewStorage 里的 type/config/base_path 直接列目录
  preview: { type: Boolean, default: false },
  // 预览态的存储快照：{ type, config, base_path }
  previewStorage: { type: Object, default: null },
})
const emit = defineEmits(['update:modelValue', 'select'])

const loading = ref(false)
// null = 首次打开（带 website_id 让服务端解析初始路径）；string = 进入指定目录
const navPath = ref(null)
const result = ref(null)   // 最近一次成功响应
const errorMsg = ref('')   // ok:false / 网络异常时的提示

const storageMeta = computed(() => result.value?.storage || null)
const title = computed(() => (storageMeta.value ? `浏览备份文件 - ${storageMeta.value.name}` : '浏览备份文件'))
const entries = computed(() => result.value?.entries || [])
const emptyText = computed(() => (errorMsg.value ? '无法加载目录内容' : '空目录'))

const load = async () => {
  loading.value = true
  errorMsg.value = ''
  result.value = null
  try {
    let res
    // 预览态（新建存储，无 storageId）：直接按提交的快照列目录
    if (props.preview && ! props.storageId) {
      res = await storagesApi.previewFiles({
        type: props.previewStorage?.type,
        config: props.previewStorage?.config || {},
        base_path: props.previewStorage?.base_path || '',
        path: navPath.value || '',
      })
    } else {
      const params = {}
      if (navPath.value) params.path = navPath.value
      else if (props.websiteId) params.website_id = props.websiteId
      res = await storagesApi.browseFiles(props.storageId, params)
    }
    if (res?.ok) {
      result.value = res
    } else {
      errorMsg.value = res?.message || '浏览失败'
    }
  } catch (_) {
    // 拦截器已弹错；这里只保证表格区为空态
    errorMsg.value = '加载失败，请稍后重试'
  } finally {
    loading.value = false
  }
}

// 进入子目录 / 面包屑回跳：带新路径重新拉取
const go = (p) => { navPath.value = p || null; load() }

// 每次打开都重置：initialPath 非空直接定位到该文件夹（表单回填场景），
// 否则回落服务端 base_path / website 增量快照根，避免残留上次浏览位置
watch(() => props.modelValue, (v) => {
  if (!v) return
  navPath.value = props.initialPath ? props.initialPath : null
  load()
})

// 选择当前目录回填表单：取服务端返回的当前目录（下钻后随之更新）
const onSelect = () => {
  if (!result.value) return
  emit('select', result.value.path)
  emit('update:modelValue', false)
}

/* ---------- 面包屑 ---------- */
const rootPath = computed(() => storageMeta.value?.base_path || '')
const rootLabel = computed(() => rootPath.value || '根目录')
// 当前路径去掉 base_path 前缀后剩余的路段（服务端返回的 path 已规范化）
const crumbSegments = computed(() => {
  const root = rootPath.value
  const p = result.value?.path || ''
  if (!p || p === root) return []
  const rest = (root && p.startsWith(root) ? p.slice(root.length) : p).replace(/^\/+/, '')
  return rest ? rest.split('/') : []
})
// 点击第 i 段：拼接到该段为止的前缀路径
const crumbPrefix = (i) => {
  const segs = crumbSegments.value.slice(0, i + 1)
  return rootPath.value ? `${rootPath.value}/${segs.join('/')}` : segs.join('/')
}
const atRoot = computed(() => crumbSegments.value.length === 0)

/* ---------- 表格 ---------- */
// 目录行整行可点进入；文件行无操作（name 上 title 提示「只读浏览」）
const onRowClick = (row) => { if (row.is_dir) go(row.path) }

// 字节人类可读（1024 进制，KiB/MiB/GiB 一位小数）
const formatBytes = (n) => {
  if (n === null || n === undefined) return '—'
  const units = ['B', 'KiB', 'MiB', 'GiB', 'TiB', 'PiB']
  let v = Number(n)
  let i = 0
  while (v >= 1024 && i < units.length - 1) { v /= 1024; i++ }
  return (i === 0 ? v.toFixed(0) : v.toFixed(1)) + ' ' + units[i]
}
const fmtModified = (iso) => {
  if (!iso) return '—'
  const d = new Date(iso)
  if (Number.isNaN(d.getTime())) return '—'
  const p = (n) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())} ${p(d.getHours())}:${p(d.getMinutes())}`
}
</script>

<template>
  <el-dialog
    :model-value="modelValue"
    :title="title"
    width="760px"
    append-to-body
    :close-on-click-modal="false"
    @update:model-value="(v) => emit('update:modelValue', v)"
  >
    <!-- 面包屑 + 当前完整路径（次要色小字） -->
    <div class="browser-crumbs">
      <el-breadcrumb>
        <el-breadcrumb-item>
          <span v-if="atRoot" class="crumb-current">{{ rootLabel }}</span>
          <span v-else class="crumb-link" @click="go(rootPath.value)">{{ rootLabel }}</span>
        </el-breadcrumb-item>
        <el-breadcrumb-item v-for="(seg, i) in crumbSegments" :key="i">
          <span v-if="i === crumbSegments.length - 1" class="crumb-current">{{ seg }}</span>
          <span v-else class="crumb-link" @click="go(crumbPrefix(i))">{{ seg }}</span>
        </el-breadcrumb-item>
      </el-breadcrumb>
      <span v-if="result" class="current-path mono" :title="result.path">{{ result.path }}</span>
    </div>

    <!-- 当前目录递归统计：与列表同一次响应返回，无需二次请求 -->
    <div class="browser-stats">
      <template v-if="loading">统计加载中…</template>
      <template v-else-if="result && result.recursive_size !== null && result.recursive_size !== undefined">
        当前目录合计 <b>{{ formatBytes(result.recursive_size) }}</b> · {{ result.recursive_count }} 个对象
      </template>
      <template v-else-if="result">递归统计不可用</template>
    </div>

    <el-alert
      v-if="result && result.truncated"
      class="browser-alert"
      type="warning"
      :closable="false"
      show-icon
    >目录条目超过 500，仅显示前 500 条</el-alert>

    <el-alert
      v-if="errorMsg"
      class="browser-alert"
      type="error"
      :closable="false"
      show-icon
    >{{ errorMsg }}</el-alert>

    <el-table
      :data="entries"
      v-loading="loading"
      class="browser-table"
      style="width: 100%"
      row-class-name="({ row }) => (row.is_dir ? 'dir-row' : '')"
      :empty-text="emptyText"
      @row-click="onRowClick"
    >
      <el-table-column label="名称" min-width="240">
        <template #default="{ row }">
          <div class="entry-name">
            <el-icon :size="15" class="entry-icon"><component :is="row.is_dir ? FolderOpened : Document" /></el-icon>
            <span class="entry-text" :title="row.is_dir ? '' : '只读浏览'">{{ row.name }}</span>
          </div>
        </template>
      </el-table-column>
      <el-table-column label="大小" width="120">
        <template #default="{ row }">
          <span v-if="row.is_dir" class="muted">—</span>
          <span v-else class="mono">{{ formatBytes(row.size) }}</span>
        </template>
      </el-table-column>
      <el-table-column label="修改时间" width="170">
        <template #default="{ row }">
          <span class="muted">{{ fmtModified(row.modified) }}</span>
        </template>
      </el-table-column>
    </el-table>

    <template #footer>
      <template v-if="selectable">
        <el-button type="primary" :disabled="!result" @click="onSelect">选择此目录</el-button>
        <el-button @click="emit('update:modelValue', false)">取消</el-button>
      </template>
      <el-button v-else @click="emit('update:modelValue', false)">关闭</el-button>
    </template>
  </el-dialog>
</template>

<style scoped>
.browser-crumbs {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 12px;
  flex-wrap: wrap;
  margin-bottom: 10px;
}
.current-path {
  font-size: var(--bc-fs-xs);
  color: var(--bc-text-secondary);
  max-width: 100%;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.crumb-link {
  color: var(--bc-primary);
  cursor: pointer;
  font-weight: 500;
}
.crumb-link:hover { color: var(--bc-primary-hover); text-decoration: underline; }
.crumb-current { color: var(--bc-text-primary); font-weight: 500; }

.browser-stats {
  font-size: var(--bc-fs-xs);
  color: var(--bc-text-secondary);
  margin-bottom: 10px;
}
.browser-stats b { color: var(--bc-text-primary); font-weight: 600; font-variant-numeric: tabular-nums; }

.browser-alert { margin-bottom: 12px; }

/* 目录行：手形光标 + 默认行 hover 高亮（--el-table-row-hover-bg-color 已走 design token） */
.browser-table :deep(.dir-row) { cursor: pointer; }
.entry-name { display: flex; align-items: center; gap: 8px; min-width: 0; }
.entry-icon { color: var(--bc-primary); flex-shrink: 0; }
.entry-text {
  color: var(--bc-text-primary);
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.mono { font-family: 'JetBrains Mono', ui-monospace, Consolas, monospace; font-size: var(--bc-fs-xs); }
.muted { color: var(--bc-text-secondary); }
</style>
