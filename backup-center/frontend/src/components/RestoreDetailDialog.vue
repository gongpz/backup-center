<script setup>
import { computed, ref, watch } from 'vue'

const props = defineProps({
  modelValue: { type: Boolean, default: false },
  // 恢复项（一次备份合并后的套件）：
  // { suite_id, backup_at, match, total_count, restorable_count, total_size, restore_state, files[] }
  suite: { type: Object, default: null },
  // 当前网站（restore_mode / web_root，用于确认文案）
  website: { type: Object, default: null },
  // 恢复状态表：{ "<backup_log_id>": { status, ... } }，进行中的文件禁止重复勾选
  statusMap: { type: Object, default: () => ({}) },
  // 下发中（透传二次确认弹窗的按钮 loading，防重复下发）
  sending: { type: Boolean, default: false },
})
const emit = defineEmits(['update:modelValue', 'confirm'])

// pending / dispatched 均视为「恢复中」
const RUNNING = ['pending', 'dispatched']

const isRunning = (f) => RUNNING.includes(props.statusMap[f.backup_log_id]?.status)
const isAdvanced = computed(() => props.website?.restore_mode === 'advanced')
const files = computed(() => props.suite?.files || [])
const restorableCount = computed(() => files.value.filter((f) => f.restorable).length)
const totalSize = computed(() => props.suite?.total_size || files.value.reduce((s, f) => s + (f.size || 0), 0))

// 勾选集合：打开 / 切换恢复项时**默认不勾选任何文件**——
// 用户反馈「点开就全选再手动取消」体验差，改为主动勾选 + 全选/全不选快捷操作
const checked = ref(new Set())
watch(
  () => props.suite,
  () => {
    checked.value = new Set()
  },
  { immediate: true },
)

// 可勾选的文件（可恢复且非恢复中）
const selectableFiles = computed(() => files.value.filter((f) => f.restorable && !isRunning(f)))
const selectAll = () => {
  checked.value = new Set(selectableFiles.value.map((f) => f.backup_log_id))
}
const selectNone = () => {
  checked.value = new Set()
}
const allSelected = computed(
  () => selectableFiles.value.length > 0 && checked.value.size === selectableFiles.value.length,
)

const checkedSize = computed(() =>
  files.value.filter((f) => checked.value.has(f.backup_log_id)).reduce((s, f) => s + (f.size || 0), 0),
)

const onCheck = (f, val) => {
  const next = new Set(checked.value)
  if (val) next.add(f.backup_log_id)
  else next.delete(f.backup_log_id)
  checked.value = next
}

/* ---------- 格式化（scoped 组件不共享 JS，自 Restore.vue 复制） ---------- */
const fmtFull = (iso) => (iso ? new Date(iso).toLocaleString() : '—')
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

// 行 title：完整路径 + 云端来源 + 不可勾选原因
const fileTitle = (f) => {
  const lines = [f.filename]
  if (f.full_path) lines.push(f.full_path)
  if (isRunning(f)) lines.push('该文件正在恢复中，暂不可重复勾选下发')
  if (!f.restorable) lines.push('该文件没有成功上传到任何存储，无法从云端恢复')
  if (f.latest_upload) {
    lines.push(`来源：${f.latest_upload.storage_name || '—'} · ${fmtFull(f.latest_upload.uploaded_at)}`)
    if (f.latest_upload.remote_path) lines.push(`远端路径：${f.latest_upload.remote_path}`)
  }
  return lines.join('\n')
}
</script>

<template>
  <el-dialog
    :model-value="modelValue"
    title="恢复详情"
    width="560px"
    append-to-body
    @update:model-value="(v) => emit('update:modelValue', v)"
  >
    <div v-if="suite" class="detail">
      <!-- 概要：完整备份时间 + 文件数 + 总大小 -->
      <div class="detail-summary">
        <span class="summary-time">{{ fmtFull(suite.backup_at) }}</span>
        <span class="summary-meta">{{ suite.total_count }} 个文件 · 共 {{ fmtSize(totalSize) }}</span>
      </div>

      <!-- 时间窗口合并提示（match=exact 时为同批次备份，不提示） -->
      <p v-if="suite.match === 'window'" class="detail-hint">
        这些文件按备份时间自动合并为一次备份，如不需要可取消勾选
      </p>

      <!-- 高级模式警示条（复用 Restore.vue .restore-warn-bar 视觉语法） -->
      <div v-if="isAdvanced" class="detail-warn">
        高级模式：恢复将自动解压覆盖网站根目录并重建导入数据库（覆盖线上站点）
      </div>

      <!-- 文件列表：勾选本次要恢复的文件 -->
      <div class="file-list">
        <el-checkbox
          v-for="f in suite.files"
          :key="f.backup_log_id"
          class="file-check"
          :model-value="checked.has(f.backup_log_id)"
          :label="f.backup_log_id"
          :disabled="!f.restorable || isRunning(f)"
          :title="fileTitle(f)"
          @update:model-value="(v) => onCheck(f, v)"
        >
          <span class="file-row">
            <span class="file-row-main">
              <span class="chip" :class="f.type === 'database' ? 'chip-purple' : 'chip-blue'">
                {{ f.label || (f.type === 'database' ? '数据库' : '网站目录') }}
              </span>
              <span class="file-name mono" :title="f.full_path">{{ f.filename }}</span>
              <span class="file-size">{{ fmtSize(f.size) }}</span>
              <span v-if="isRunning(f)" class="badge run">恢复中</span>
            </span>
            <span class="file-row-sub">
              <template v-if="f.latest_upload">
                <span class="chip chip-neutral" :title="f.latest_upload.storage_name">{{ f.latest_upload.storage_name }}</span>
                <span class="file-upload-time" :title="fmtFull(f.latest_upload.uploaded_at)">
                  {{ fmtTime(f.latest_upload.uploaded_at) }} 上传
                </span>
              </template>
              <span v-else class="badge gray">无成功上传</span>
            </span>
          </span>
        </el-checkbox>
      </div>

      <!-- lite 模式底部说明（advanced 的警示已在顶部展示） -->
      <p v-if="!isAdvanced" class="detail-note">文件将下载回服务器原备份目录</p>
    </div>

    <template #footer>
      <div class="detail-foot">
        <span class="foot-stat">已选 {{ checked.size }} / {{ restorableCount }} 个可恢复文件 · 共 {{ fmtSize(checkedSize) }}</span>
        <div class="foot-actions">
          <el-button link size="small" :disabled="sending || allSelected" @click="selectAll">全选</el-button>
          <el-button link size="small" :disabled="sending || !checked.size" @click="selectNone">全不选</el-button>
          <el-button :disabled="sending" @click="emit('update:modelValue', false)">取消</el-button>
          <el-button
            type="primary"
            :loading="sending"
            :disabled="!checked.size"
            @click="emit('confirm', [...checked])"
          >
            恢复所选（{{ checked.size }}）
          </el-button>
        </div>
      </div>
    </template>
  </el-dialog>
</template>

<style scoped>
/* ---- 以下样式复制自 Restore.vue（scoped 样式不跨组件） ---- */
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

/* ---- 概要区 ---- */
.detail-summary {
  display: flex;
  align-items: baseline;
  gap: 10px;
  flex-wrap: wrap;
}
.summary-time { font-size: var(--bc-fs-md); font-weight: 600; color: var(--bc-text-primary); }
.summary-meta {
  font-size: var(--bc-fs-xs);
  color: var(--bc-text-secondary);
  font-variant-numeric: tabular-nums;
}

.detail-hint { margin: 8px 0 0; font-size: 12px; color: var(--bc-text-secondary); line-height: 1.7; }

/* 高级模式警示条：复用 Restore.vue .restore-warn-bar 视觉语法 */
.detail-warn {
  background: var(--bc-warning-bg);
  border: 1px solid var(--bc-warning-border);
  border-radius: 8px;
  padding: 10px 12px;
  margin-top: 10px;
  font-size: 12px;
  color: var(--bc-warning);
  line-height: 1.7;
}

/* ---- 文件列表（勾选行） ---- */
.file-list {
  display: flex;
  flex-direction: column;
  gap: 4px;
  margin-top: 12px;
  min-width: 0;
}
.file-check {
  width: 100%;
  margin-right: 0;
  padding: 8px;
  border-radius: var(--bc-radius-sm);
  align-items: flex-start;
  height: auto;
}
.file-check:hover { background: var(--bc-bg-hover); }
.file-check.is-disabled,
.file-check.is-disabled:hover { background: transparent; }
.file-check :deep(.el-checkbox__label) {
  flex: 1;
  min-width: 0;
  width: 100%;
  display: flex;
}
.file-row { display: flex; flex-direction: column; gap: 2px; min-width: 0; flex: 1; }
.file-row-main { display: flex; align-items: center; gap: 8px; min-width: 0; }
.file-row-sub { display: flex; align-items: center; gap: 4px; flex-wrap: wrap; min-width: 0; }
.file-name {
  flex: 1;
  min-width: 0;
  font-size: var(--bc-fs-xs);
  font-weight: 500;
  color: var(--bc-text-primary);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.file-size {
  font-size: var(--bc-fs-xs);
  color: var(--bc-text-regular);
  font-variant-numeric: tabular-nums;
  flex-shrink: 0;
}
.file-upload-time {
  font-size: var(--bc-fs-2xs);
  color: var(--bc-text-placeholder);
  white-space: nowrap;
}

.detail-note { margin: 12px 0 0; font-size: 12px; color: var(--bc-text-placeholder); }

/* ---- footer ---- */
.detail-foot {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
}
.foot-stat {
  font-size: var(--bc-fs-xs);
  color: var(--bc-text-secondary);
  font-variant-numeric: tabular-nums;
}
.foot-actions { display: inline-flex; gap: 8px; flex-shrink: 0; }
</style>
