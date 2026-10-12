<script setup>
import { ref, reactive, watch, computed } from 'vue'
import { ElMessage } from 'element-plus'
import { storagesApi } from '@/api/storages'
import { storageTypes, typeFields } from '@/utils/storage-types'

const props = defineProps({
  modelValue: { type: Boolean, default: false },
  storage: { type: Object, default: null },
})
const emit = defineEmits(['update:modelValue', 'saved'])

const isEdit = computed(() => !!props.storage?.id)
const title = computed(() => (isEdit.value ? '编辑存储' : '添加存储'))

const formRef = ref(null)
const saving = ref(false)

const form = reactive({
  name: '',
  type: 'sftp',
  base_path: '',
  quota_size: 0,          // 用户输入的容量值
  quota_unit: 'GB',       // GB | TB
  status: 'enabled',
  is_mandatory: false,
  remark: '',
  access_url: '',
  config: {},
})

const UNIT_BYTES = { GB: 1024 ** 3, TB: 1024 ** 4 }
const encodeQuota = () => {
  if (!form.quota_size || form.quota_size <= 0) return null
  return Math.round(form.quota_size * UNIT_BYTES[form.quota_unit])
}
const decodeQuota = (bytes) => {
  if (!bytes || bytes <= 0) {
    form.quota_size = 0
    form.quota_unit = 'GB'
    return
  }
  // >= 1TB 时用 TB，否则 GB
  if (bytes >= UNIT_BYTES.TB) {
    form.quota_unit = 'TB'
    form.quota_size = +(bytes / UNIT_BYTES.TB).toFixed(2)
  } else {
    form.quota_unit = 'GB'
    form.quota_size = +(bytes / UNIT_BYTES.GB).toFixed(2)
  }
}

const currentFields = computed(() => typeFields[form.type] || [])

const rules = computed(() => {
  const r = {
    name: [{ required: true, message: '请输入存储名称', trigger: 'blur' }],
    type: [{ required: true, message: '请选择类型', trigger: 'change' }],
  }
  for (const f of currentFields.value) {
    if (f.required) {
      r[`config.${f.key}`] = [{ required: true, message: `请输入${f.label}`, trigger: 'blur' }]
    }
  }
  return r
})

const resetForm = () => {
  Object.assign(form, {
    name: '',
    type: 'sftp',
    base_path: '',
    quota_size: 0,
    quota_unit: 'GB',
    status: 'enabled',
    is_mandatory: false,
    remark: '',
    access_url: '',
    config: {},
  })
  for (const f of typeFields.sftp) {
    if (f.default !== undefined) form.config[f.key] = f.default
  }
}

const applyDefaultsForType = () => {
  const cfg = {}
  for (const f of currentFields.value) {
    if (f.default !== undefined) cfg[f.key] = f.default
  }
  form.config = cfg
}

watch(
  () => props.modelValue,
  (v) => {
    if (! v) return
    resetForm()
    if (! props.storage) return

    // 编辑：父组件已经把完整 storage（含 config）传进来了，直接同步更新
    // 顺序很重要：先设 config（此时 type 还是 sftp，applyDefaultsForType 不会覆盖），
    // 再设 type（触发 currentFields 重算，此时 config 已就位）
    form.name = props.storage.name || ''
    form.base_path = props.storage.base_path || ''
    decodeQuota(props.storage.quota_bytes)
    form.status = props.storage.status || 'enabled'
    form.is_mandatory = !! props.storage.is_mandatory
    form.remark = props.storage.remark || ''
    form.access_url = props.storage.access_url || ''
    form.config = { ...(props.storage.config || {}) }
    form.type = props.storage.type
  },
)

// 切换 type 时清空/填默认
watch(
  () => form.type,
  (nt, ot) => {
    if (nt !== ot && !isEdit.value) applyDefaultsForType()
  },
)

const close = () => emit('update:modelValue', false)

const onSubmit = () => {
  formRef.value?.validate(async (valid) => {
    if (!valid) return
    saving.value = true
    try {
      const payload = {
        name: form.name,
        type: form.type,
        base_path: form.base_path,
        quota_bytes: encodeQuota(),
        status: form.status,
        is_mandatory: form.is_mandatory,
        remark: form.remark,
        access_url: form.access_url || null,
        config: form.config,
      }
      const saved = isEdit.value
        ? await storagesApi.update(props.storage.id, payload)
        : await storagesApi.create(payload)
      ElMessage.success(isEdit.value ? '已更新' : '已添加')
      emit('saved', saved)
      close()
    } catch (_) {
      // interceptor 弹错
    } finally {
      saving.value = false
    }
  })
}
</script>

<template>
  <el-dialog
    :model-value="modelValue"
    :title="title"
    width="620px"
    :close-on-click-modal="false"
    @update:model-value="(v) => emit('update:modelValue', v)"
  >
    <el-form
      ref="formRef"
      :model="form"
      :rules="rules"
      label-width="95px"
      label-position="right"
      require-asterisk-position="left"
    >
      <el-form-item label="名称" prop="name">
        <el-input v-model="form.name" placeholder="例如：HostBrr Storage Box" />
      </el-form-item>

      <el-form-item label="类型" prop="type">
        <el-radio-group v-model="form.type" :disabled="isEdit">
          <el-radio v-for="t in storageTypes" :key="t.value" :value="t.value">{{ t.label }}</el-radio>
        </el-radio-group>
      </el-form-item>

      <!-- 动态字段 -->
      <el-form-item
        v-for="f in currentFields"
        :key="f.key"
        :label="f.label"
        :prop="`config.${f.key}`"
      >
        <el-input
          v-if="f.type === 'text' || f.type === 'password'"
          v-model="form.config[f.key]"
          :type="f.type === 'password' ? 'password' : 'text'"
          :placeholder="f.placeholder"
          :show-password="f.type === 'password'"
        />
        <el-input-number
          v-else-if="f.type === 'number'"
          v-model="form.config[f.key]"
          :min="1"
          :max="65535"
          style="width: 160px"
        />
        <el-input
          v-else-if="f.type === 'textarea'"
          v-model="form.config[f.key]"
          type="textarea"
          :rows="4"
          :placeholder="f.placeholder"
        />
        <el-select v-else-if="f.type === 'select'" v-model="form.config[f.key]" style="width: 100%">
          <el-option v-for="o in f.options" :key="String(o.value)" :value="o.value" :label="o.label" />
        </el-select>
      </el-form-item>

      <el-form-item label="目标路径" prop="base_path">
        <el-input v-model="form.base_path" placeholder="备份文件的目标目录，可选（例如 /backups）" />
      </el-form-item>

      <el-form-item label="存储配额" prop="quota_size">
        <el-input-number
          v-model="form.quota_size"
          :min="0"
          :precision="2"
          :step="10"
          style="width: 160px"
        />
        <el-select v-model="form.quota_unit" style="width: 90px; margin-left: 8px">
          <el-option value="GB" label="GB" />
          <el-option value="TB" label="TB" />
        </el-select>
        <span class="hint">留 0 = 不限；用于在存储/首页展示"已用/剩余"</span>
      </el-form-item>

      <el-form-item label="状态" prop="status">
        <el-radio-group v-model="form.status">
          <el-radio value="enabled">启用</el-radio>
          <el-radio value="disabled">停用</el-radio>
        </el-radio-group>
      </el-form-item>

      <el-form-item label="强制备份" prop="is_mandatory">
        <el-switch v-model="form.is_mandatory" />
        <span class="mandatory-hint">
          开启后，<b>所有</b>网站的备份都会自动推送到此存储（不用挨个网站配）
        </span>
      </el-form-item>

      <el-form-item label="管理面板 URL" prop="access_url">
        <el-input v-model="form.access_url" placeholder="https://console.cloud.tencent.com/cos（可选，用于快速访问存储管理界面）" clearable />
      </el-form-item>

      <el-form-item label="备注" prop="remark">
        <el-input v-model="form.remark" type="textarea" :rows="2" maxlength="500" show-word-limit />
      </el-form-item>
    </el-form>

    <template #footer>
      <el-button @click="close">取消</el-button>
      <el-button type="primary" :loading="saving" @click="onSubmit">
        {{ isEdit ? '保存' : '添加' }}
      </el-button>
    </template>
  </el-dialog>
</template>

<style scoped>
.mandatory-hint {
  margin-left: 12px;
  font-size: 12px;
  color: var(--bc-text-secondary);
}
.mandatory-hint b {
  color: var(--bc-danger);
  font-weight: 500;
}
.hint {
  margin-left: 12px;
  font-size: 12px;
  color: var(--bc-text-secondary);
}
</style>
