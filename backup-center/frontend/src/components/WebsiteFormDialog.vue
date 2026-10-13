<script setup>
import { ref, reactive, watch, computed, onMounted } from 'vue'
import { ElMessage } from 'element-plus'
import { Plus, Close } from '@element-plus/icons-vue'
import { websitesApi } from '@/api/websites'
import { serversApi } from '@/api/servers'
import { storagesApi } from '@/api/storages'

const props = defineProps({
  modelValue: { type: Boolean, default: false },
  website: { type: Object, default: null },
})
const emit = defineEmits(['update:modelValue', 'saved'])

const isEdit = computed(() => !!props.website?.id)
const title = computed(() => (isEdit.value ? '编辑网站' : '添加网站'))

const formRef = ref(null)
const saving = ref(false)
const serverOptions = ref([])
const storageOptions = ref([])
const loadingServers = ref(false)

const emptyBackupPath = (idx = 0) => ({
  label: `备份目录${idx + 1}`,
  path: '',
  pattern: '',
})

const form = reactive({
  server_id: null,
  name: '',
  domain: '',
  backup_paths: [emptyBackupPath(0)],
  retention_days: 30,
  status: 'enabled',
  remark: '',
  storage_ids: [],

  // 同步方式（R79）：auto=Agent 自动扫描；scheduled=按 schedule_time 每天派 sync_now
  sync_mode: 'auto',
  schedule_time: '03:00',

  // 备份来源（P1）：external=沿用服务器上的第三方/外部压缩产物；self=由本系统自主压缩（P3 支持）
  backup_source: 'external',
  // 恢复模式（P1）：lite=拉回备份目录，手动恢复；advanced=系统全自动恢复线上（P4 支持）
  restore_mode: 'lite',
})

const rules = {
  server_id: [{ required: true, message: '请选择所属服务器', trigger: 'change' }],
  name: [{ required: true, message: '请输入网站名称', trigger: 'blur' }],
  retention_days: [{ required: true, message: '请输入保留天数', trigger: 'blur' }],
}

const addBackupPath = () => {
  form.backup_paths.push(emptyBackupPath(form.backup_paths.length))
}
const removeBackupPath = (idx) => {
  form.backup_paths.splice(idx, 1)
  if (form.backup_paths.length === 0) {
    form.backup_paths.push(emptyBackupPath(0))
  }
}

const monthDays = Array.from({ length: 31 }, (_, i) => i + 1)

const loadServers = async () => {
  loadingServers.value = true
  try {
    const res = await serversApi.list({ per_page: 100 })
    serverOptions.value = res.data || []
  } finally {
    loadingServers.value = false
  }
}

const loadStorages = async () => {
  const res = await storagesApi.list({ per_page: 100, status: 'enabled' })
  storageOptions.value = res.data || []
}

const mandatoryStorages = computed(() =>
  storageOptions.value.filter((s) => s.is_mandatory),
)
const optionalStorages = computed(() =>
  storageOptions.value.filter((s) => ! s.is_mandatory),
)

onMounted(() => { loadServers(); loadStorages() })

watch(
  () => props.modelValue,
  (v) => {
    if (v) {
      // 重置
      Object.assign(form, {
        server_id: null,
        name: '',
        domain: '',
        backup_paths: [emptyBackupPath(0)],
        retention_days: 30,
        status: 'enabled',
        remark: '',
        storage_ids: [],
        sync_mode: 'auto',
        schedule_time: '03:00',
        backup_source: 'external',
        restore_mode: 'lite',
      })
      if (props.website) {
        const existing = Array.isArray(props.website.backup_paths) ? props.website.backup_paths : []
        const paths = existing.length > 0
          ? existing.map((p, i) => ({
              label: p.label || `备份目录${i + 1}`,
              path: p.path || '',
              pattern: p.pattern || '',
            }))
          : [emptyBackupPath(0)]
        // schedule 字段：R79 起用于存 HH:MM（sync_mode=scheduled 时）。老数据可能是 cron，兼容取 HH:MM
        let hm = '03:00'
        if (typeof props.website.schedule === 'string') {
          const m = props.website.schedule.match(/^(\d{1,2}):(\d{2})/)
          if (m) hm = `${m[1].padStart(2, '0')}:${m[2]}`
          else {
            // 老 cron 格式 "min hour * * *"，尝试解析
            const c = props.website.schedule.split(/\s+/)
            if (c.length >= 2 && /^\d+$/.test(c[0]) && /^\d+$/.test(c[1])) {
              hm = `${c[1].padStart(2, '0')}:${c[0].padStart(2, '0')}`
            }
          }
        }
        Object.assign(form, {
          server_id: props.website.server_id,
          name: props.website.name || '',
          domain: props.website.domain || '',
          backup_paths: paths,
          retention_days: props.website.retention_days || 30,
          status: props.website.status || 'enabled',
          remark: props.website.remark || '',
          storage_ids: props.website.storage_ids || [],
          sync_mode: props.website.sync_mode || 'auto',
          schedule_time: hm,
          backup_source: props.website.backup_source || 'external',
          restore_mode: props.website.restore_mode || 'lite',
        })
      }
      loadServers()
      loadStorages()
    }
  },
)

const close = () => emit('update:modelValue', false)

const onSubmit = () => {
  formRef.value?.validate(async (valid) => {
    if (!valid) return

    // 过滤空 path 的行，同时至少留一条
    const cleanedPaths = form.backup_paths
      .map((p, i) => ({
        label: (p.label || '').trim() || `备份目录${i + 1}`,
        path: (p.path || '').trim(),
        pattern: (p.pattern || '').trim(),
      }))
      .filter((p) => p.path !== '')

    if (cleanedPaths.length === 0) {
      ElMessage.warning('请至少填写一个备份目录')
      return
    }

    saving.value = true
    try {
      const payload = {
        server_id: form.server_id,
        name: form.name,
        domain: form.domain,
        backup_paths: cleanedPaths,
        retention_days: form.retention_days,
        status: form.status,
        remark: form.remark,
        storage_ids: form.storage_ids,
        sync_mode: form.sync_mode,
        // schedule 存 HH:MM，仅在 scheduled 模式下有意义
        schedule: form.sync_mode === 'scheduled' ? form.schedule_time : null,
        backup_source: form.backup_source,
        restore_mode: form.restore_mode,
      }
      const saved = isEdit.value
        ? await websitesApi.update(props.website.id, payload)
        : await websitesApi.create(payload)
      ElMessage.success(isEdit.value ? '已更新' : '已添加')
      emit('saved', saved)
      close()
    } catch (_) {
      // 拦截器处理错误
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
      <el-form-item label="所属服务器" prop="server_id">
        <el-select
          v-model="form.server_id"
          placeholder="选择一台服务器"
          :loading="loadingServers"
          filterable
          style="width: 100%"
        >
          <el-option
            v-for="s in serverOptions"
            :key="s.id"
            :value="s.id"
            :label="`${s.name}${s.ip ? ' (' + s.ip + ')' : ''}`"
          >
            <div class="server-opt">
              <span>{{ s.name }}</span>
              <span class="server-opt-meta">
                {{ s.connect_type === 'agent' ? 'Agent' : 'SSH' }}
                <span v-if="s.status === 'online'" class="dot-ok" title="在线" />
                <span v-else-if="s.status === 'offline'" class="dot-gray" title="离线" />
                <span v-else class="dot-warn" title="待连接" />
              </span>
            </div>
          </el-option>
        </el-select>
      </el-form-item>

      <el-form-item label="网站名称" prop="name">
        <el-input v-model="form.name" placeholder="例如：我的博客" />
      </el-form-item>

      <el-form-item label="域名" prop="domain">
        <el-input v-model="form.domain" placeholder="选填，例如 blog.example.com" />
      </el-form-item>

      <el-form-item label="备份目录" required>
        <div class="bp-list">
          <div v-for="(bp, idx) in form.backup_paths" :key="idx" class="bp-card">
            <div class="bp-card-head">
              <el-input
                v-model="bp.label"
                :placeholder="`备份目录${idx + 1}`"
                class="bp-label-input"
                maxlength="64"
              />
              <button
                v-if="form.backup_paths.length > 1"
                type="button"
                class="bp-remove"
                title="移除此目录"
                @click="removeBackupPath(idx)"
              >
                <el-icon><Close /></el-icon>
              </button>
            </div>
            <el-input
              v-model="bp.path"
              placeholder="Agent 扫描此目录，例如 /www/backup/site"
              class="bp-path-input"
            />
            <el-input
              v-model="bp.pattern"
              placeholder="可选，文件名通配，例如 *.tar.gz（留空匹配全部）"
              class="bp-pattern-input"
            />
          </div>

          <button
            type="button"
            class="bp-add"
            title="添加备份目录"
            @click="addBackupPath"
          >
            <el-icon><Plus /></el-icon>
            <span>添加备份目录</span>
          </button>
        </div>
      </el-form-item>

      <!-- 同步方式 -->
      <el-form-item label="同步方式" prop="sync_mode">
        <div class="sync-mode-row">
          <el-radio-group v-model="form.sync_mode">
            <el-radio value="auto">自动检测</el-radio>
            <el-radio value="scheduled">
              每天定时
              <el-time-select
                v-if="form.sync_mode === 'scheduled'"
                v-model="form.schedule_time"
                start="00:00"
                step="00:10"
                end="23:50"
                placeholder="HH:MM"
                style="width: 130px; margin-left: 8px; vertical-align: middle"
                @click.stop
              />
            </el-radio>
          </el-radio-group>
        </div>
        <div class="sched-detail">
          <span class="sched-hint">
            <template v-if="form.sync_mode === 'auto'">
              Agent 每 <b>2 分钟</b>扫描一次备份目录，一发现新文件立刻上传。
            </template>
            <template v-else>
              Agent 平时<b>不主动扫描</b>；每天到点由 Center 派发一次 sync_now。
            </template>
          </span>
        </div>
      </el-form-item>

      <!-- 备份来源（P1：接口预留，自主压缩 P3 落地） -->
      <el-form-item label="备份来源" prop="backup_source">
        <div class="mode-row">
          <el-radio-group v-model="form.backup_source">
            <el-radio value="external">外部压缩备份</el-radio>
            <el-radio value="self">
              自主压缩备份
              <span class="mode-tag mode-tag-pending">P3</span>
            </el-radio>
          </el-radio-group>
        </div>
        <div class="mode-detail">
          <span class="mode-hint">
            <template v-if="form.backup_source === 'external'">
              监控服务器上已有的备份产物（如面板/宝塔/脚本生成的压缩文件），发现新文件即上传。
            </template>
            <template v-else>
              由本系统直接在服务器上完成网站目录与数据库压缩后再上传（后续版本支持）。
            </template>
          </span>
        </div>
      </el-form-item>

      <!-- 恢复模式（P1：接口预留，高级恢复 P4 落地） -->
      <el-form-item label="恢复模式" prop="restore_mode">
        <div class="mode-row">
          <el-radio-group v-model="form.restore_mode">
            <el-radio value="lite">轻便恢复</el-radio>
            <el-radio value="advanced">
              高级恢复
              <span class="mode-tag mode-tag-pending">P4</span>
            </el-radio>
          </el-radio-group>
        </div>
        <div class="mode-detail">
          <span class="mode-hint">
            <template v-if="form.restore_mode === 'lite'">
              远程拉取备份文件回到服务器备份目录，由管理员手动解压、导入数据库。
            </template>
            <template v-else>
              系统全自主恢复：按绑定的网站根目录自动解压覆盖、自动导入数据库，无需人工干预（后续版本支持）。
            </template>
          </span>
        </div>
      </el-form-item>

      <el-form-item label="推送到" prop="storage_ids">
        <div style="width: 100%">
          <div v-if="mandatoryStorages.length" class="mandatory-notice">
            <span class="mn-title">🔒 强制备份到（系统自动推送，无需选择）</span>
            <div class="mn-list">
              <span
                v-for="s in mandatoryStorages"
                :key="s.id"
                class="mn-chip"
              >{{ s.name }} <span class="mn-type">· {{ s.type }}</span></span>
            </div>
          </div>

          <el-select
            v-model="form.storage_ids"
            multiple
            filterable
            collapse-tags
            collapse-tags-tooltip
            :placeholder="mandatoryStorages.length ? '还可选其他存储目标（可留空）' : '选择一个或多个存储目标'"
            style="width: 100%"
          >
            <el-option
              v-for="s in optionalStorages"
              :key="s.id"
              :value="s.id"
              :label="s.name"
            >
              <div style="display: flex; justify-content: space-between; gap: 10px">
                <span>{{ s.name }}</span>
                <span style="font-size: 12px; color: var(--bc-text-secondary)">{{ s.type }}</span>
              </div>
            </el-option>
          </el-select>

        </div>
      </el-form-item>

      <el-form-item label="保留天数" prop="retention_days">
        <el-input-number v-model="form.retention_days" :min="1" :max="3650" style="width: 160px" />
        <span class="hint">超过天数的备份会被清理</span>
      </el-form-item>

      <el-form-item label="状态" prop="status">
        <el-radio-group v-model="form.status">
          <el-radio value="enabled">启用</el-radio>
          <el-radio value="disabled">停用</el-radio>
        </el-radio-group>
      </el-form-item>

      <el-form-item label="备注" prop="remark">
        <el-input v-model="form.remark" type="textarea" :rows="2" placeholder="选填" maxlength="500" show-word-limit />
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
.server-opt {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 12px;
}
.server-opt-meta {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-size: 12px;
  color: var(--bc-text-secondary);
}
.dot-ok, .dot-gray, .dot-warn {
  display: inline-block;
  width: 6px;
  height: 6px;
  border-radius: 50%;
}
.dot-ok   { background: var(--bc-success); }
.dot-gray { background: var(--bc-text-secondary); }
.dot-warn { background: var(--bc-warning); }

.hint {
  margin-left: 10px;
  font-size: 12px;
  color: var(--bc-text-secondary);
}

.sched-row {
  width: 100%;
}
.sched-detail {
  margin-top: 8px;
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
}
.sched-hint {
  font-size: 12px;
  color: var(--bc-text-secondary);
}

/* --- 备份来源 / 恢复模式（P1） --- */
.mode-row {
  width: 100%;
}
.mode-detail {
  margin-top: 8px;
}
.mode-hint {
  font-size: 12px;
  color: var(--bc-text-secondary);
}
.mode-tag {
  display: inline-flex;
  align-items: center;
  margin-left: 6px;
  padding: 0 6px;
  border-radius: 4px;
  font-size: 11px;
  font-weight: 600;
  line-height: 18px;
  vertical-align: middle;
}
.mode-tag-pending {
  background: var(--bc-warning-bg, #fdf6ec);
  border: 1px solid var(--bc-warning-border, #f5dab1);
  color: var(--bc-warning);
}

/* --- 备份目录 repeater --- */
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

/* 强制备份提示区 */
.mandatory-notice {
  background: var(--bc-danger-bg);
  border: 1px solid var(--bc-danger-border);
  border-radius: 8px;
  padding: 10px 12px;
  margin-bottom: 10px;
}
.mn-title {
  font-size: 12px;
  color: var(--bc-danger);
  font-weight: 500;
}
.mn-list {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  margin-top: 6px;
}
.mn-chip {
  display: inline-flex;
  align-items: center;
  padding: 1px 12px;
  border-radius: 999px;
  background: var(--bc-bg-card);
  border: 1px solid var(--bc-danger-border);
  font-size: 11px;
  color: var(--bc-danger);
  line-height: 1.6;
  letter-spacing: 0.2px;
  white-space: nowrap;
}
.mn-type {
  color: var(--bc-text-secondary);
  margin-left: 4px;
}

/* 对话框留白规范已放到 style.css 全局，此处不再需要覆盖 */
</style>
