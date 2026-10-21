<script setup>
import { ref, reactive, watch, computed } from 'vue'
import { ElMessage } from 'element-plus'
import { storagesApi } from '@/api/storages'
import { storageTypes, typeFields, encryptionFields, defaultEncryption, normalizeEncryption } from '@/utils/storage-types'

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
  encryption: { ...defaultEncryption },
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

const encryptionField = (key) => encryptionFields.find((f) => f.key === key)
const keyModeOptions = computed(() => encryptionField('key_mode')?.options || [])
const filenameEncryptionOptions = computed(() => encryptionField('filename_encryption')?.options || [])
const passwordPlaceholder = computed(() => encryptionField('password')?.placeholder || '')
// 当前密钥模式的说明（后端契约：center 中心托管并下发 / agent_local 中心不保存）
const keyModeDesc = computed(() => keyModeOptions.value.find((o) => o.value === form.encryption.key_mode)?.desc || '')

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
    encryption: { ...defaultEncryption },
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
    // encryption 块从 config 拆出单独编辑；password 透传服务端返回的明文密码（无则空串）
    const incomingConfig = { ...(props.storage.config || {}) }
    form.encryption = normalizeEncryption(incomingConfig.encryption)
    delete incomingConfig.encryption
    form.config = incomingConfig
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

// 文件名加密关闭时目录名加密无意义（后端同样拒绝该组合，422），跟随关掉避免提交即报错
watch(
  () => form.encryption.filename_encryption,
  (v) => {
    if (v === 'off') form.encryption.directory_name_encryption = false
  },
)

// 生成 rclone crypt 用随机密码：24 字节 CSPRNG → 48 位十六进制
const genEncryptionPassword = () => {
  const bytes = new Uint8Array(24)
  crypto.getRandomValues(bytes)
  return Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('')
}

// 新增存储：开启备份加密且密码为空时自动生成随机密码
// 编辑模式不自动生成：密码留空 = 不修改（后端契约），避免误导用户改密
watch(
  () => form.encryption.enabled,
  (v) => {
    if (v && !isEdit.value && !form.encryption.password) {
      form.encryption.password = genEncryptionPassword()
    }
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
        // encryption 原样提交：password 为空时由后端按「不修改」语义处理
        config: { ...form.config, encryption: { ...form.encryption } },
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
    width="900px"
    :close-on-click-modal="false"
    @update:model-value="(v) => emit('update:modelValue', v)"
  >
    <el-form
      ref="formRef"
      class="sf-form"
      :model="form"
      :rules="rules"
      label-width="112px"
      label-position="right"
      require-asterisk-position="left"
    >
      <div class="sf-grid">
        <!-- 左列：基本信息 -->
        <div class="sf-col">
          <div class="sf-col-title">基本信息</div>

          <el-form-item label="名称" prop="name">
            <el-input v-model="form.name" placeholder="例如：HostBrr Storage Box" />
          </el-form-item>

          <el-form-item label="类型" prop="type">
            <el-radio-group v-model="form.type" :disabled="isEdit">
              <el-radio v-for="t in storageTypes" :key="t.value" :value="t.value">{{ t.label }}</el-radio>
            </el-radio-group>
          </el-form-item>

          <el-form-item label="目标路径" prop="base_path">
            <el-input v-model="form.base_path" placeholder="备份文件的目标目录，可选（例如 /backups）" />
          </el-form-item>

          <el-form-item label="存储配额" prop="quota_size">
            <div class="sf-stack">
              <div class="sf-quota-row">
                <el-input-number
                  v-model="form.quota_size"
                  :min="0"
                  :precision="2"
                  :step="10"
                  style="width: 160px"
                />
                <el-select v-model="form.quota_unit" style="width: 90px">
                  <el-option value="GB" label="GB" />
                  <el-option value="TB" label="TB" />
                </el-select>
              </div>
              <span class="sf-hint">留 0 = 不限；用于在存储/首页展示"已用/剩余"</span>
            </div>
          </el-form-item>

          <el-form-item label="状态" prop="status">
            <el-radio-group v-model="form.status">
              <el-radio value="enabled">启用</el-radio>
              <el-radio value="disabled">停用</el-radio>
            </el-radio-group>
          </el-form-item>

          <el-form-item label="强制备份" prop="is_mandatory">
            <div class="sf-stack">
              <el-switch v-model="form.is_mandatory" />
              <span class="sf-hint">开启后，<b>所有</b>网站的备份都会自动推送到此存储（不用挨个网站配）</span>
            </div>
          </el-form-item>

          <el-form-item label="管理面板 URL" prop="access_url">
            <el-input v-model="form.access_url" placeholder="https://console.cloud.tencent.com/cos（可选，用于快速访问存储管理界面）" clearable />
          </el-form-item>

          <el-form-item label="备注" prop="remark">
            <el-input v-model="form.remark" type="textarea" :rows="2" maxlength="500" show-word-limit />
          </el-form-item>
        </div>

        <!-- 右列：连接与加密 -->
        <div class="sf-col">
          <div class="sf-col-title">连接与加密</div>

          <!-- 动态字段（随类型变化） -->
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

          <!-- 备份加密（所有类型通用，rclone crypt 包裹层） -->
          <el-form-item label="备份加密">
            <div class="sf-stack">
              <el-switch v-model="form.encryption.enabled" />
              <span class="sf-hint">开启后备份文件以加密形式上传至第三方存储（rclone crypt），云端仅存密文；恢复自动解密，不影响现有流程</span>
            </div>
          </el-form-item>

          <template v-if="form.encryption.enabled">
            <el-form-item label="密钥模式">
              <!-- popper-class + style.css 全局规则：EP 下拉项默认 height:34px/overflow:hidden，
                   会压扁选项内两行内容（label + desc），popper 的样式只能走全局覆盖 -->
              <div class="sf-stack">
                <el-select v-model="form.encryption.key_mode" style="width: 100%" popper-class="key-mode-popper">
                  <!-- 选项内说明用内联样式：下拉 popper 会 teleport 到 body，scoped 样式不生效 -->
                  <el-option v-for="o in keyModeOptions" :key="o.value" :value="o.value" :label="o.label">
                    <div style="line-height: 1.5; padding: 2px 0">
                      <div style="font-weight: 500">{{ o.label }}</div>
                      <div style="font-size: 12px; color: var(--bc-text-secondary); white-space: normal">{{ o.desc }}</div>
                    </div>
                  </el-option>
                </el-select>
                <span v-if="keyModeDesc" class="sf-hint">{{ keyModeDesc }}</span>
              </div>
            </el-form-item>

            <el-form-item v-if="form.encryption.key_mode === 'center'" label="加密密码">
              <!-- 明文展示：自动生成的随机密码需要可直接核对/抄录；留空提交 = 不修改 -->
              <el-input
                v-model="form.encryption.password"
                type="text"
                :placeholder="passwordPlaceholder"
              />
            </el-form-item>

            <el-form-item label="文件名加密">
              <el-select v-model="form.encryption.filename_encryption" style="width: 100%">
                <el-option
                  v-for="o in filenameEncryptionOptions"
                  :key="o.value"
                  :value="o.value"
                  :label="o.label"
                />
              </el-select>
            </el-form-item>

            <el-form-item label="目录名加密">
              <div class="sf-stack">
                <el-switch
                  v-model="form.encryption.directory_name_encryption"
                  :disabled="form.encryption.filename_encryption === 'off'"
                />
                <span v-if="form.encryption.filename_encryption === 'off'" class="sf-hint">
                  需先选择标准文件名加密
                </span>
              </div>
            </el-form-item>
          </template>
        </div>
      </div>
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
/* 解除 style.css 全局 .el-dialog .el-form { max-width:520px }（为窄对话框设计），横版需要满宽 */
.el-dialog .sf-form.sf-form { max-width: none; margin: 0; }

.sf-grid {
  display: grid;
  grid-template-columns: 340px minmax(0, 1fr);
  gap: 20px;
  align-items: start;
}
.sf-col { min-width: 0; }
.sf-col-title {
  font-size: 13px;
  font-weight: 600;
  color: var(--bc-text-primary);
  margin-bottom: 14px;
  display: flex;
  align-items: center;
  gap: 8px;
}
.sf-col-title::before {
  content: '';
  width: 3px;
  height: 14px;
  border-radius: 2px;
  background: var(--bc-primary);
  flex-shrink: 0;
}
.sf-stack {
  width: 100%;
  display: flex;
  flex-direction: column;
  gap: 4px;
}
.sf-quota-row {
  display: flex;
  gap: 8px;
  align-items: center;
}
.sf-hint {
  font-size: 12px;
  color: var(--bc-text-secondary);
  line-height: 1.6;
}
.sf-hint b {
  color: var(--bc-danger);
  font-weight: 500;
}

/* 窄视口兜底：单列堆叠，避免右列被挤压 */
@media (max-width: 860px) {
  .sf-grid { grid-template-columns: minmax(0, 1fr); }
}
</style>
