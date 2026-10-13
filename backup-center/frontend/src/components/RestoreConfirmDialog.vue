<script setup>
import { computed } from 'vue'
import { Download } from '@element-plus/icons-vue'

const props = defineProps({
  modelValue: { type: Boolean, default: false },
  // 待恢复文件（仅可恢复的）：[{ filename, local_dir, size }]
  files: { type: Array, default: () => [] },
  dateLabel: { type: String, default: '' },
  // 该套备份的文件总数（用于「N 个中仅 M 个可恢复」提示），缺省等于 files.length
  totalCount: { type: Number, default: 0 },
  // POST 中（按钮 loading，防止重复下发）
  sending: { type: Boolean, default: false },
})
const emit = defineEmits(['update:modelValue', 'confirm'])

const total = computed(() => props.files.reduce((s, f) => s + (f.size || 0), 0))
const skippedCount = computed(() => {
  const t = props.totalCount || props.files.length
  return Math.max(0, t - props.files.length)
})

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
</script>

<template>
  <el-dialog
    :model-value="modelValue"
    title="确认恢复备份"
    width="580px"
    :close-on-click-modal="false"
    @update:model-value="(v) => emit('update:modelValue', v)"
  >
    <!-- 顶部警告条：复用 WebsiteBindDialog .bind-notice 视觉（scoped 已复制过来） -->
    <div class="bind-notice">
      <div class="confirm-lead">将把所选备份文件下载回服务器原备份目录</div>
      <div class="confirm-bold">不会自动解压、不会自动导入数据库——下载完成后需手动处理</div>
    </div>

    <div class="confirm-meta">
      将恢复「<b>{{ dateLabel }}</b>」这一套备份，共 {{ files.length }} 个文件：
    </div>

    <!-- 文件清单：复用 .key-file-box 视觉语法 -->
    <div class="key-file-box confirm-files">
      <div class="confirm-file-list">
        <div v-for="(f, i) in files" :key="i" class="confirm-file">
          <span class="mono confirm-file-name" :title="f.filename">{{ f.filename }}</span>
          <span class="mono confirm-file-size">{{ fmtSize(f.size) }}</span>
          <span class="mono confirm-file-dir" :title="f.local_dir">→ {{ f.local_dir }}</span>
        </div>
      </div>
    </div>

    <p class="confirm-risk">
      若目标目录已存在同名文件将被覆盖；本次共需从云端下载约 <b>{{ fmtSize(total) }}</b>
    </p>
    <p v-if="skippedCount > 0" class="confirm-partial">
      这套备份共 {{ totalCount }} 个文件，其中 {{ skippedCount }} 个没有成功上传记录，本次只会恢复可用的 {{ files.length }} 个。
    </p>

    <template #footer>
      <el-button :disabled="sending" @click="emit('update:modelValue', false)">取消</el-button>
      <el-button type="warning" :icon="Download" :loading="sending" @click="emit('confirm')">确认恢复</el-button>
    </template>
  </el-dialog>
</template>

<style scoped>
/* ---- 以下三类复制自 WebsiteBindDialog.vue（scoped 样式不跨组件） ---- */
.bind-notice {
  background: var(--bc-warning-bg);
  border: 1px solid var(--bc-warning-border);
  border-radius: 8px;
  padding: 10px 12px;
  margin-bottom: 14px;
  font-size: 12px;
  color: var(--bc-warning);
  line-height: 1.7;
}
.mono { font-family: 'JetBrains Mono', ui-monospace, Consolas, monospace; }
.key-file-box {
  display: flex; align-items: center; gap: 10px;
  border: 1px solid var(--bc-primary-100);
  background: var(--bc-primary-light);
  border-radius: 10px;
  padding: 10px 12px;
}

/* ---- 确认弹窗 ---- */
.confirm-lead { font-weight: 600; }
.confirm-bold { font-weight: 600; color: var(--bc-text-primary); margin-top: 2px; }

.confirm-meta { font-size: var(--bc-fs-sm); color: var(--bc-text-secondary); margin-bottom: 10px; }
.confirm-meta b { color: var(--bc-text-primary); font-weight: 600; }

.confirm-files { align-items: flex-start; padding: 12px; }
.confirm-file-list { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 6px; }
.confirm-file {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  align-items: center;
  gap: 2px 8px;
}
.confirm-file-name {
  font-size: var(--bc-fs-xs);
  color: var(--bc-text-primary);
  font-weight: 500;
  min-width: 0;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.confirm-file-size {
  font-size: var(--bc-fs-xs);
  color: var(--bc-text-regular);
  font-variant-numeric: tabular-nums;
}
.confirm-file-dir {
  grid-column: 1 / -1;
  font-size: var(--bc-fs-2xs);
  color: var(--bc-text-secondary);
  min-width: 0;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.confirm-risk {
  margin: 12px 0 0;
  font-size: var(--bc-fs-xs);
  line-height: 1.7;
  color: var(--bc-text-secondary);
}
.confirm-risk b { color: var(--bc-text-primary); font-weight: 600; font-variant-numeric: tabular-nums; }
.confirm-partial {
  margin: 8px 0 0;
  font-size: var(--bc-fs-xs);
  line-height: 1.7;
  color: var(--bc-warning);
}
</style>
