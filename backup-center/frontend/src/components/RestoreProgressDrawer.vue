<script setup>
import { computed } from 'vue'

const props = defineProps({
  modelValue: { type: Boolean, default: false },
  // activeFiles 数组：[{ backup_log_id, filename, local_dir, size, command_id }]
  files: { type: Array, default: () => [] },
  // 轮询结果：{ "<backup_log_id>": { status, output, error, command_id, ... } }
  statusMap: { type: Object, default: () => ({}) },
  // 已执行秒数（计时器在 Restore.vue）
  elapsed: { type: Number, default: 0 },
})
const emit = defineEmits(['update:modelValue'])

/* 状态映射与 BackupLogs.vue 完全一致（视觉规格 §3.1） */
const restoreLabel = (s) => ({ pending: '待执行', dispatched: '执行中', success: '已恢复', failed: '恢复失败' }[s] || s)
const restoreBadgeCls = (s) => ({ pending: 'gray', dispatched: 'run', success: 'ok', failed: 'fail' }[s] || 'gray')

const statusOf = (f) => props.statusMap[f.backup_log_id]?.status || 'pending'
const detailOf = (f) => {
  const st = props.statusMap[f.backup_log_id]
  if (!st) return '等待 Agent 心跳（最长 60 秒）'
  if (st.status === 'failed') return st.error || '恢复失败'
  return st.output || ''
}
const msgCls = (f) => {
  const s = statusOf(f)
  return { dispatched: 'df-msg-run', success: 'df-msg-ok', failed: 'df-msg-err' }[s] || ''
}

const failedCount = computed(() => props.files.filter((f) => statusOf(f) === 'failed').length)
const allTerminal = computed(
  () => props.files.length > 0 && props.files.every((f) => ['success', 'failed'].includes(statusOf(f))),
)
</script>

<template>
  <el-drawer
    :model-value="modelValue"
    title="恢复进度"
    size="520px"
    direction="rtl"
    @update:model-value="(v) => emit('update:modelValue', v)"
  >
    <!-- 首屏明示：关闭抽屉不取消任务 -->
    <div class="bind-notice">关闭抽屉不会取消任务，Agent 将继续执行，可在本页查看实时状态</div>

    <div class="drawer-timer">已执行 <b>{{ elapsed }}</b> 秒</div>

    <div class="drawer-files">
      <div v-for="f in files" :key="f.backup_log_id" class="drawer-file">
        <div class="df-head">
          <span :class="['badge', restoreBadgeCls(statusOf(f))]">{{ restoreLabel(statusOf(f)) }}</span>
          <span class="mono df-name" :title="f.filename">{{ f.filename }}</span>
        </div>
        <div class="mono df-dir" :title="f.local_dir">目标目录：{{ f.local_dir }}</div>
        <div v-if="detailOf(f)" class="mono df-msg" :class="msgCls(f)" :title="detailOf(f)">{{ detailOf(f) }}</div>
      </div>
    </div>

    <div v-if="allTerminal" class="drawer-summary" :class="{ 'is-failed': failedCount > 0 }">
      {{ failedCount > 0 ? `${failedCount} 个文件恢复失败` : '全部完成' }}
    </div>
  </el-drawer>
</template>

<style scoped>
/* ---- 复制自 WebsiteBindDialog.vue / BackupLogs.vue（scoped 不跨组件） ---- */
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
/* 执行中呼吸感（reduced-motion 由全局兜底） */
.badge.run { animation: bc-breathe 2.4s var(--bc-ease) infinite; }
@keyframes bc-breathe {
  0%, 100% { opacity: 1; }
  50%      { opacity: .72; }
}

/* ---- 进度抽屉 ---- */
.drawer-timer {
  font-size: var(--bc-fs-xs);
  color: var(--bc-text-secondary);
  margin-bottom: 14px;
  font-variant-numeric: tabular-nums;
}
.drawer-timer b { color: var(--bc-text-primary); font-weight: 600; }

.drawer-files { display: flex; flex-direction: column; gap: 14px; }
.drawer-file {
  display: flex; flex-direction: column; gap: 4px;
  padding-bottom: 12px;
  border-bottom: 1px solid var(--bc-border-hair);
  min-width: 0;
}
.drawer-file:last-child { border-bottom: none; padding-bottom: 0; }
.df-head { display: flex; align-items: center; gap: 8px; min-width: 0; }
.df-name {
  font-size: var(--bc-fs-xs);
  color: var(--bc-text-primary);
  font-weight: 500;
  min-width: 0;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.df-dir {
  font-size: var(--bc-fs-2xs);
  color: var(--bc-text-placeholder);
  min-width: 0;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.df-msg {
  font-size: var(--bc-fs-2xs);
  line-height: 1.4;
  color: var(--bc-text-secondary);
  max-width: 100%;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  font-variant-numeric: tabular-nums;
}
.df-msg-run { color: var(--bc-primary); }
.df-msg-ok  { color: var(--bc-success); }
.df-msg-err { color: var(--bc-danger); }

.drawer-summary {
  margin-top: 16px;
  padding: 10px 12px;
  border-radius: var(--bc-radius);
  background: var(--bc-success-bg);
  border: 1px solid var(--bc-success-border);
  color: var(--bc-success);
  font-size: var(--bc-fs-sm);
  font-weight: 500;
  text-align: center;
}
.drawer-summary.is-failed {
  background: var(--bc-danger-bg);
  border-color: var(--bc-danger-border);
  color: var(--bc-danger);
}
</style>
