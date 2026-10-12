<script setup>
import { ref, reactive, watch, computed } from 'vue'
import { ElMessage } from 'element-plus'
import { serversApi } from '@/api/servers'

const props = defineProps({
  modelValue: { type: Boolean, default: false },
  server: { type: Object, default: null },
})
const emit = defineEmits(['update:modelValue', 'saved'])

const isEdit = computed(() => !!props.server?.id)
const title = computed(() => (isEdit.value ? '编辑服务器' : '添加服务器'))

const formRef = ref(null)
const saving = ref(false)

const form = reactive({
  name: '',
  connect_type: 'agent',
  os_type: '',
  ip: '',
  remark: '',
  ssh_port: 22,
  ssh_user: '',
  ssh_private_key: '',
  backup_path: '',
})

const osOptions = [
  { value: 'ubuntu',  label: 'Ubuntu' },
  { value: 'debian',  label: 'Debian' },
  { value: 'centos',  label: 'CentOS' },
  { value: 'rocky',   label: 'Rocky Linux' },
  { value: 'linux',   label: 'Linux (通用)' },
  { value: 'windows', label: 'Windows' },
]

const rules = computed(() => {
  const r = {
    name: [{ required: true, message: '请输入服务器名称', trigger: 'blur' }],
    connect_type: [{ required: true, message: '请选择连接方式', trigger: 'change' }],
  }
  if (form.connect_type === 'ssh') {
    r.ip = [{ required: true, message: '请输入 IP', trigger: 'blur' }]
    r.ssh_port = [{ required: true, message: '请输入端口', trigger: 'blur' }]
    r.ssh_user = [{ required: true, message: '请输入用户名', trigger: 'blur' }]
  }
  return r
})

watch(
  () => props.modelValue,
  (v) => {
    if (v) {
      // 每次打开重置表单
      Object.assign(form, {
        name: '',
        connect_type: 'agent',
        os_type: '',
        ip: '',
        remark: '',
        ssh_port: 22,
        ssh_user: '',
        ssh_private_key: '',
        backup_path: '',
      })
      if (props.server) {
        Object.assign(form, {
          name: props.server.name || '',
          connect_type: props.server.connect_type || 'agent',
          os_type: props.server.os_type || '',
          ip: props.server.ip || '',
          remark: props.server.remark || '',
          ssh_port: props.server.ssh_port || 22,
          ssh_user: props.server.ssh_user || '',
          ssh_private_key: '',
          backup_path: props.server.backup_path || '',
        })
      }
    }
  },
)

const close = () => emit('update:modelValue', false)

const onSubmit = () => {
  formRef.value?.validate(async (valid) => {
    if (!valid) return
    saving.value = true
    try {
      const payload = { ...form }
      // Agent 模式不发 SSH 字段
      if (payload.connect_type === 'agent') {
        delete payload.ssh_user
        delete payload.ssh_private_key
        delete payload.backup_path
      }
      // 编辑时如果没填新的私钥，就不覆盖
      if (isEdit.value && !payload.ssh_private_key) {
        delete payload.ssh_private_key
      }

      const saved = isEdit.value
        ? await serversApi.update(props.server.id, payload)
        : await serversApi.create(payload)

      ElMessage.success(isEdit.value ? '已更新' : '已添加')
      emit('saved', saved)
      close()
    } catch (_) {
      // http 拦截器已弹错
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
    width="560px"
    :close-on-click-modal="false"
    @update:model-value="(v) => emit('update:modelValue', v)"
  >
    <el-form ref="formRef" :model="form" :rules="rules" label-width="88px" label-position="left">
      <el-form-item label="连接方式" prop="connect_type">
        <el-radio-group v-model="form.connect_type" :disabled="isEdit">
          <el-radio value="agent">Agent 模式（推荐）</el-radio>
          <el-radio value="ssh">SSH 模式</el-radio>
        </el-radio-group>
      </el-form-item>

      <el-form-item label="名称" prop="name">
        <el-input v-model="form.name" placeholder="例如：香港生产机-01" />
      </el-form-item>

      <el-form-item label="系统类型" prop="os_type">
        <el-select v-model="form.os_type" placeholder="选择系统（可选，Agent 会自动上报）" clearable style="width: 100%">
          <el-option v-for="o in osOptions" :key="o.value" :value="o.value" :label="o.label" />
        </el-select>
      </el-form-item>

      <el-form-item :label="form.connect_type === 'ssh' ? 'IP 地址' : 'IP（可选）'" prop="ip">
        <el-input v-model="form.ip" :placeholder="form.connect_type === 'ssh' ? '必填' : 'Agent 首次连接会自动填入'" />
      </el-form-item>

      <!-- SSH 模式独有 -->
      <template v-if="form.connect_type === 'ssh'">
        <el-form-item label="SSH 端口" prop="ssh_port">
          <el-input-number v-model="form.ssh_port" :min="1" :max="65535" style="width: 160px" />
        </el-form-item>
        <el-form-item label="SSH 用户名" prop="ssh_user">
          <el-input v-model="form.ssh_user" placeholder="root / ubuntu" />
        </el-form-item>
        <el-form-item :label="isEdit ? '重设私钥' : 'SSH 私钥'" prop="ssh_private_key">
          <el-input
            v-model="form.ssh_private_key"
            type="textarea"
            :rows="5"
            :placeholder="isEdit ? '留空则保留原有私钥' : '-----BEGIN OPENSSH PRIVATE KEY-----\n...\n-----END OPENSSH PRIVATE KEY-----'"
          />
        </el-form-item>
        <el-form-item label="备份目录" prop="backup_path">
          <el-input v-model="form.backup_path" placeholder="例如 /www/backup" />
        </el-form-item>
      </template>

      <el-form-item label="备注" prop="remark">
        <el-input v-model="form.remark" type="textarea" :rows="2" placeholder="选填" maxlength="500" show-word-limit />
      </el-form-item>
    </el-form>

    <template #footer>
      <el-button @click="close">取消</el-button>
      <el-button type="primary" :loading="saving" @click="onSubmit">{{ isEdit ? '保存' : '添加' }}</el-button>
    </template>
  </el-dialog>
</template>
