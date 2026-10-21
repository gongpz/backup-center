<script setup>
import { Plus, Close } from '@element-plus/icons-vue'

const props = defineProps({
  modelValue: { type: Array, default: () => [] },
})
const emit = defineEmits(['update:modelValue'])

const emptyBackupPath = (idx = 0) => ({
  label: `备份目录${idx + 1}`,
  path: '',
  pattern: '',
})

const onField = (idx, field, value) => {
  emit('update:modelValue', props.modelValue.map((p, i) => (i === idx ? { ...p, [field]: value } : p)))
}

const add = () => {
  emit('update:modelValue', [...props.modelValue, emptyBackupPath(props.modelValue.length)])
}

const remove = (idx) => {
  const next = props.modelValue.filter((_, i) => i !== idx)
  emit('update:modelValue', next.length > 0 ? next : [emptyBackupPath(0)])
}
</script>

<template>
  <div class="bp-list">
    <div v-for="(bp, idx) in modelValue" :key="idx" class="bp-card">
      <div class="bp-card-head">
        <el-input
          :model-value="bp.label"
          :placeholder="`备份目录${idx + 1}`"
          class="bp-label-input"
          maxlength="64"
          @update:model-value="(v) => onField(idx, 'label', v)"
        />
        <button
          v-if="modelValue.length > 1"
          type="button"
          class="bp-remove"
          title="移除此目录"
          @click="remove(idx)"
        >
          <el-icon><Close /></el-icon>
        </button>
      </div>
      <el-input
        :model-value="bp.path"
        placeholder="Agent 扫描此目录，例如 /www/backup/site"
        class="bp-path-input"
        @update:model-value="(v) => onField(idx, 'path', v)"
      />
      <el-input
        :model-value="bp.pattern"
        placeholder="可选，文件名通配，例如 *.tar.gz（留空匹配全部）"
        class="bp-pattern-input"
        @update:model-value="(v) => onField(idx, 'pattern', v)"
      />
    </div>

    <button
      type="button"
      class="bp-add"
      title="添加备份目录"
      @click="add"
    >
      <el-icon><Plus /></el-icon>
      <span>添加备份目录</span>
    </button>
  </div>
</template>

<style scoped>
.bp-list {
  width: 100%;
  display: flex;
  flex-direction: column;
  gap: 10px;
}
.bp-card {
  position: relative;
  border: 1px solid var(--bc-border);
  border-radius: 10px;
  padding: 10px 12px 12px;
  background: var(--bc-bg-inset);
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.bp-card-head {
  display: flex;
  align-items: center;
  gap: 8px;
}
.bp-label-input :deep(.el-input__wrapper) {
  background: transparent;
  box-shadow: none;
  padding-left: 0;
}
.bp-label-input :deep(.el-input__inner) {
  font-weight: 600;
  color: var(--bc-text-primary);
  font-size: 13px;
}
.bp-remove {
  flex-shrink: 0;
  width: 26px;
  height: 26px;
  border-radius: 6px;
  border: 1px solid var(--bc-border);
  background: var(--bc-bg-card);
  color: var(--bc-text-secondary);
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  transition: all .15s;
}
.bp-remove:hover {
  color: var(--bc-danger);
  border-color: var(--bc-danger-border);
  background: var(--bc-danger-bg);
}

.bp-add {
  width: 100%;
  border: 1px dashed var(--bc-primary-200);
  border-radius: 10px;
  background: var(--bc-primary-light);
  color: var(--bc-primary);
  padding: 10px 12px;
  font-size: 13px;
  font-weight: 500;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  transition: all .15s;
}
.bp-add:hover {
  background: var(--bc-primary-100);
  border-color: var(--bc-primary);
}
.bp-add .el-icon {
  font-size: 14px;
}
</style>
