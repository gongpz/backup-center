<script setup>
import { ref, reactive, computed, watch, onMounted } from 'vue'
import { ElMessage } from 'element-plus'
import { Check, FolderOpened, Box, Files, Key } from '@element-plus/icons-vue'
import { websitesApi } from '@/api/websites'
import { serversApi } from '@/api/servers'
import { storagesApi } from '@/api/storages'
import BackupPathsEditor from './BackupPathsEditor.vue'

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
  // 备份方式（v1.2.17）：full=全量（现行为）；incremental=rsync 硬链快照（仅 backup_source=self 可选）
  backup_mode: 'full',
  // 增量备份目标存储（单值；必须是 SFTP + SSH 私钥 + 未加密存储，由 incrementalStorageOptions 约束）
  incremental_storage_id: null,
  // 恢复模式：lite=拉回备份目录，手动恢复；advanced=系统全自动恢复线上（自动预备份+解压覆盖+导库，P4 已落地）
  restore_mode: 'lite',

  // 数据库连接（自主压缩 self 用；密码留空表示不修改）
  db_type: 'mysql',
  db_host: '127.0.0.1',
  db_port: 3306,
  db_name: '',
  db_user: '',
  db_password: '',
})

/* ---------- 三种备份模式（前端单一概念）↔ 后端两字段映射 ----------
 *   simple      轻便模式 → backup_source=external + backup_mode=full      （外部压缩，无需绑定根目录）
 *   advanced    高级模式 → backup_source=self     + backup_mode=full      （自主压缩，需绑定根目录）
 *   incremental 增量模式 → backup_source=self     + backup_mode=incremental（增量备份，需绑定根目录）
 * 数据层仍是 backup_source × backup_mode 两字段，后端契约零改动；
 * 这里收敛为单一「模式」概念，切换时同步复位依赖字段（离开增量模式清空目标存储）。 */
const modeCards = [
  { key: 'simple', name: '轻便模式', method: '外部压缩', desc: '监控服务器上已有的备份产物，发现新文件即上传', icon: FolderOpened },
  { key: 'advanced', name: '高级模式', method: '自主压缩', desc: '系统在服务器上直接打包网站目录与数据库后上传', icon: Box },
  { key: 'incremental', name: '增量模式', method: '增量备份', desc: 'rsync 硬链快照到 VPS，仅传输变化部分', icon: Files },
]

const backupModeKey = computed({
  get: () => {
    if (form.backup_source !== 'self') return 'simple'
    return form.backup_mode === 'incremental' ? 'incremental' : 'advanced'
  },
  set: (key) => {
    if (key === 'simple') {
      form.backup_source = 'external'
      form.backup_mode = 'full'
      form.incremental_storage_id = null
    } else if (key === 'advanced') {
      form.backup_source = 'self'
      form.backup_mode = 'full'
      form.incremental_storage_id = null
    } else {
      form.backup_source = 'self'
      form.backup_mode = 'incremental'
    }
  },
})

const selectMode = (key) => { backupModeKey.value = key }

const rules = {
  server_id: [{ required: true, message: '请选择所属服务器', trigger: 'change' }],
  name: [{ required: true, message: '请输入网站名称', trigger: 'blur' }],
  retention_days: [{ required: true, message: '请输入保留天数', trigger: 'blur' }],
}

const onDbTypeChange = () => {
  form.db_port = form.db_type === 'postgresql' ? 5432 : 3306
}

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
// 增量备份目标候选（v1.2.17）：对象存储无增量语义，仅 SFTP + SSH 私钥 + 未加密；
// private_key_set / encryption_enabled 为后端脱敏布尔字段（StorageResource）
const incrementalStorageOptions = computed(() =>
  storageOptions.value.filter((s) => s.type === 'sftp' && s.private_key_set && ! s.encryption_enabled),
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
        backup_mode: 'full',
        incremental_storage_id: null,
        restore_mode: 'lite',
        db_type: 'mysql',
        db_host: '127.0.0.1',
        db_port: 3306,
        db_name: '',
        db_user: '',
        db_password: '',
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
          backup_mode: props.website.backup_mode || 'full',
          incremental_storage_id: props.website.incremental_storage_id || null,
          restore_mode: props.website.restore_mode || 'lite',
          db_type: props.website.db_type || 'mysql',
          db_host: props.website.db_host || '127.0.0.1',
          db_port: props.website.db_port || 3306,
          db_name: props.website.db_name || '',
          db_user: props.website.db_user || '',
          db_password: '', // 密码永不回填明文
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

    // 轻便模式（外部压缩）必须至少一个备份目录；高级/增量模式可留空（打包网站根目录）
    if (cleanedPaths.length === 0 && backupModeKey.value === 'simple') {
      ElMessage.warning('请至少填写一个备份目录')
      return
    }

    // 增量模式必须选定目标存储（后端同规则 422，前端先拦一道）
    if (form.backup_mode === 'incremental' && !form.incremental_storage_id) {
      ElMessage.warning('请选择增量备份目标存储')
      return
    }

    saving.value = true
    try {
      const payload = {
        server_id: form.server_id,
        name: form.name,
        domain: form.domain,
        retention_days: form.retention_days,
        status: form.status,
        remark: form.remark,
        storage_ids: form.storage_ids,
        sync_mode: form.sync_mode,
        // schedule 存 HH:MM，仅在 scheduled 模式下有意义
        schedule: form.sync_mode === 'scheduled' ? form.schedule_time : null,
        backup_source: form.backup_source,
        // 备份方式（v1.2.17）：非 incremental 时不下发目标存储（后端对 full 忽略该字段）
        backup_mode: form.backup_mode,
        incremental_storage_id: form.backup_mode === 'incremental' ? form.incremental_storage_id : null,
        restore_mode: form.restore_mode,
        // 数据库连接（自主压缩 self 用；空密码=不改）
        db_type: form.db_type,
        db_host: form.db_host,
        db_port: form.db_port,
        db_name: form.db_name,
        db_user: form.db_user,
        db_password: form.db_password,
        // 轻便模式（external）必传；self 且未填目录时可不发送该键（后端局部更新保持原值）
        ...(form.backup_source === 'external' || cleanedPaths.length > 0
          ? { backup_paths: cleanedPaths }
          : {}),
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
    width="960px"
    :close-on-click-modal="false"
    @update:model-value="(v) => emit('update:modelValue', v)"
  >
    <el-form
      ref="formRef"
      :model="form"
      :rules="rules"
      label-width="88px"
      label-position="right"
      require-asterisk-position="left"
    >
      <div class="wf-grid">
        <!-- 左列：基本信息 -->
        <div class="wf-col wf-col-left">
          <div class="wf-col-title">基本信息</div>

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
            <div class="wf-stack">
              <el-input-number v-model="form.retention_days" :min="1" :max="3650" style="width: 160px" />
              <span class="mp-hint">超过天数的备份会被清理</span>
            </div>
          </el-form-item>

          <el-form-item label="恢复模式" prop="restore_mode">
            <div class="wf-stack">
              <el-radio-group v-model="form.restore_mode">
                <el-radio value="lite">轻便恢复</el-radio>
                <el-radio value="advanced">
                  高级恢复
                  <span class="mode-tag mode-tag-ready">已支持</span>
                </el-radio>
              </el-radio-group>
              <span class="mp-hint">
                <template v-if="form.restore_mode === 'lite'">
                  远程拉取备份文件回到服务器备份目录，由管理员手动解压、导入数据库。
                </template>
                <template v-else>
                  系统全自主恢复：自动创建恢复前备份（网站目录打包、数据库导出），随后解压覆盖线上网站根目录，并重建（先 DROP 再 CREATE）导入数据库，全程无需人工干预。
                </template>
              </span>
            </div>
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
        </div>

        <!-- 右列：备份模式（三选一）+ 该模式的配置 -->
        <div class="wf-col wf-col-right">
          <div class="wf-col-title">备份模式</div>

          <div class="mode-cards">
            <button
              v-for="mc in modeCards"
              :key="mc.key"
              type="button"
              class="mode-card"
              :class="{ active: backupModeKey === mc.key }"
              @click="selectMode(mc.key)"
            >
              <span class="mc-check"><el-icon><Check /></el-icon></span>
              <span class="mc-icon"><el-icon><component :is="mc.icon" /></el-icon></span>
              <span class="mc-name">{{ mc.name }}</span>
              <span class="mc-method">{{ mc.method }}</span>
              <span class="mc-desc">{{ mc.desc }}</span>
            </button>
          </div>

          <div class="mode-panel">
            <!-- 轻便模式：外部压缩，监控已有备份产物 -->
            <template v-if="backupModeKey === 'simple'">
              <div class="mp-block">
                <div class="mp-label">备份目录 <em class="mp-req">*</em></div>
                <BackupPathsEditor v-model="form.backup_paths" />
                <p class="mp-hint">Agent 扫描这些目录，发现新文件即上传到存储。</p>
              </div>

              <div class="mp-block">
                <div class="mp-label">同步方式</div>
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
                <p class="mp-hint">
                  <template v-if="form.sync_mode === 'auto'">
                    Agent 每 <b>2 分钟</b>扫描一次备份目录，一发现新文件立刻上传。
                  </template>
                  <template v-else>
                    Agent 平时<b>不主动扫描</b>；每天到点由 Center 派发一次 sync_now。
                  </template>
                </p>
              </div>
            </template>

            <!-- 高级模式：自主压缩（self + full），需绑定根目录 -->
            <template v-if="backupModeKey === 'advanced'">
              <div class="mp-notice">
                <el-icon class="mp-notice-icon"><Key /></el-icon>
                <div class="mp-notice-body">
                  <b>需要绑定网站根目录</b>
                  <p>保存后在网站列表点击「绑定」，由 Agent 查找并写入网站根目录；自主压缩将直接打包根目录与数据库。</p>
                  <p v-if="isEdit && props.website?.web_root" class="mp-notice-ok">
                    已绑定：<code>{{ props.website.web_root }}</code>
                    <span v-if="props.website.root_bound_at">（{{ new Date(props.website.root_bound_at).toLocaleString() }}）</span>
                  </p>
                </div>
              </div>

              <div class="mp-block">
                <div class="mp-label">数据库配置</div>
                <div class="db-cfg">
                  <div class="db-cfg-row">
                    <el-select v-model="form.db_type" style="width: 150px" @change="onDbTypeChange">
                      <el-option label="MySQL / MariaDB" value="mysql" />
                      <el-option label="PostgreSQL" value="postgresql" />
                    </el-select>
                    <el-input v-model="form.db_host" placeholder="主机" style="width: 160px" />
                    <el-input v-model.number="form.db_port" placeholder="端口" style="width: 110px" />
                  </div>
                  <div class="db-cfg-row">
                    <el-input v-model="form.db_name" placeholder="数据库名" style="width: 200px" />
                    <el-input v-model="form.db_user" placeholder="用户名" style="width: 160px" />
                    <el-input v-model="form.db_password" type="password" show-password placeholder="密码（留空表示不修改）" style="width: 220px" />
                  </div>
                  <p class="mp-hint">
                    仅用于 Agent 在服务器上执行 <code>mysqldump</code> / <code>pg_dump</code>；密码经加密存储，列表与接口均不回传明文。
                  </p>
                </div>
              </div>

              <div class="mp-block">
                <div class="mp-label">附加备份目录 <em class="mp-opt">选填</em></div>
                <BackupPathsEditor v-model="form.backup_paths" />
                <p class="mp-hint">除网站根目录外，额外需要 Agent 扫描同步的目录；留空表示仅处理网站根目录。</p>
              </div>
            </template>

            <!-- 增量模式：rsync 硬链快照（self + incremental），需绑定根目录 -->
            <template v-if="backupModeKey === 'incremental'">
              <div class="mp-notice">
                <el-icon class="mp-notice-icon"><Key /></el-icon>
                <div class="mp-notice-body">
                  <b>需要绑定网站根目录</b>
                  <p>保存后在网站列表点击「绑定」，由 Agent 查找并写入网站根目录；rsync 将以根目录为源做硬链快照。</p>
                  <p v-if="isEdit && props.website?.web_root" class="mp-notice-ok">
                    已绑定：<code>{{ props.website.web_root }}</code>
                    <span v-if="props.website.root_bound_at">（{{ new Date(props.website.root_bound_at).toLocaleString() }}）</span>
                  </p>
                </div>
              </div>

              <div class="mp-block">
                <div class="mp-label">数据库配置</div>
                <div class="db-cfg">
                  <div class="db-cfg-row">
                    <el-select v-model="form.db_type" style="width: 150px" @change="onDbTypeChange">
                      <el-option label="MySQL / MariaDB" value="mysql" />
                      <el-option label="PostgreSQL" value="postgresql" />
                    </el-select>
                    <el-input v-model="form.db_host" placeholder="主机" style="width: 160px" />
                    <el-input v-model.number="form.db_port" placeholder="端口" style="width: 110px" />
                  </div>
                  <div class="db-cfg-row">
                    <el-input v-model="form.db_name" placeholder="数据库名" style="width: 200px" />
                    <el-input v-model="form.db_user" placeholder="用户名" style="width: 160px" />
                    <el-input v-model="form.db_password" type="password" show-password placeholder="密码（留空表示不修改）" style="width: 220px" />
                  </div>
                  <p class="mp-hint">
                    增量快照只传文件变化部分；数据库仍每次全量导出并随快照存档。
                  </p>
                </div>
              </div>

              <div class="mp-block">
                <div class="mp-label">增量目标存储 <em class="mp-req">*</em></div>
                <el-select
                  v-model="form.incremental_storage_id"
                  placeholder="选择增量备份目标存储（VPS）"
                  filterable
                  style="width: 100%"
                >
                  <el-option
                    v-for="s in incrementalStorageOptions"
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
                <p v-if="incrementalStorageOptions.length === 0" class="mp-hint mp-hint-warn">
                  请先创建 SFTP 类型、配置 SSH 私钥、未开启加密的存储（VPS）
                </p>
                <p class="mp-hint">
                  rsync 硬链快照到 VPS，仅传输变化部分。需要目标存储为 SFTP + SSH 私钥且未加密，服务器已安装 rsync / ssh。
                </p>
              </div>

              <div class="mp-block">
                <div class="mp-label">附加备份目录 <em class="mp-opt">选填</em></div>
                <BackupPathsEditor v-model="form.backup_paths" />
                <p class="mp-hint">除网站根目录外，额外需要 Agent 扫描同步的目录；留空表示仅处理网站根目录。</p>
              </div>
            </template>
          </div>
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
/* ---------- 横版两列布局 ---------- */
.wf-grid {
  display: grid;
  grid-template-columns: 340px 1fr;
  gap: 20px;
  align-items: start;
}
.wf-col { min-width: 0; }
.wf-col-title {
  font-size: 13px;
  font-weight: 600;
  color: var(--bc-text-primary);
  margin-bottom: 14px;
  display: flex;
  align-items: center;
  gap: 8px;
}
.wf-col-title::before {
  content: '';
  width: 3px;
  height: 14px;
  border-radius: 2px;
  background: var(--bc-primary);
  flex-shrink: 0;
}
.wf-stack {
  width: 100%;
  display: flex;
  flex-direction: column;
  gap: 4px;
}
.wf-stack .mp-hint { line-height: 1.6; }

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

/* ---------- 三种备份模式卡片 ---------- */
.mode-cards {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 10px;
}
.mode-card {
  position: relative;
  text-align: left;
  border: 1px solid var(--bc-border);
  border-radius: 12px;
  background: var(--bc-bg-card);
  padding: 14px 14px 12px;
  cursor: pointer;
  display: flex;
  flex-direction: column;
  gap: 3px;
  transition: border-color .15s, background .15s, box-shadow .15s;
  font-family: inherit;
}
.mode-card:hover {
  border-color: var(--bc-primary-200);
  background: var(--bc-primary-light);
}
.mode-card.active {
  border-color: var(--bc-primary);
  background: var(--bc-primary-light);
  box-shadow: 0 0 0 1px var(--bc-primary);
}
.mc-check {
  position: absolute;
  top: 10px;
  right: 10px;
  width: 18px;
  height: 18px;
  border-radius: 50%;
  border: 1px solid var(--bc-border);
  display: inline-flex;
  align-items: center;
  justify-content: center;
  color: transparent;
  font-size: 12px;
  transition: all .15s;
}
.mode-card.active .mc-check {
  background: var(--bc-primary);
  border-color: var(--bc-primary);
  color: #fff;
}
.mc-icon {
  color: var(--bc-text-secondary);
  font-size: 18px;
  line-height: 1;
  margin-bottom: 4px;
}
.mode-card.active .mc-icon { color: var(--bc-primary); }
.mc-name {
  font-size: 14px;
  font-weight: 600;
  color: var(--bc-text-primary);
}
.mc-method {
  font-size: 11px;
  font-weight: 500;
  color: var(--bc-primary);
}
.mc-desc {
  font-size: 11px;
  color: var(--bc-text-secondary);
  line-height: 1.5;
  margin-top: 2px;
}

/* ---------- 模式配置面板 ---------- */
.mode-panel {
  margin-top: 14px;
  display: flex;
  flex-direction: column;
  gap: 16px;
}
.mp-block { width: 100%; }
.mp-label {
  font-size: 13px;
  font-weight: 500;
  color: var(--bc-text-primary);
  margin-bottom: 8px;
}
.mp-req {
  font-style: normal;
  color: var(--bc-danger);
  margin-left: 2px;
}
.mp-opt {
  font-style: normal;
  font-size: 11px;
  font-weight: 400;
  color: var(--bc-text-secondary);
  margin-left: 6px;
}
.mp-hint {
  font-size: 12px;
  color: var(--bc-text-secondary);
  line-height: 1.6;
  margin: 6px 0 0;
}
.mp-hint code { font-family: ui-monospace, Consolas, monospace; font-size: 12px; }
.mp-hint-warn { color: var(--bc-warning); }

.mp-notice {
  display: flex;
  gap: 10px;
  align-items: flex-start;
  background: var(--bc-warning-bg);
  border: 1px solid var(--bc-warning-border);
  border-radius: 10px;
  padding: 10px 12px;
}
.mp-notice-icon {
  color: var(--bc-warning);
  font-size: 16px;
  margin-top: 2px;
  flex-shrink: 0;
}
.mp-notice-body { min-width: 0; }
.mp-notice-body b {
  font-size: 13px;
  color: var(--bc-text-primary);
}
.mp-notice-body p {
  font-size: 12px;
  color: var(--bc-text-secondary);
  line-height: 1.6;
  margin: 4px 0 0;
}
.mp-notice-ok code {
  font-family: ui-monospace, Consolas, monospace;
  color: var(--bc-success);
}

.sync-mode-row { width: 100%; }

.db-cfg { width: 100%; display: flex; flex-direction: column; gap: 10px; }
.db-cfg-row { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
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
.mode-tag-ready {
  background: var(--bc-success-bg, #eaf7f0);
  border: 1px solid var(--bc-success-border, #bfe6d0);
  color: var(--bc-success, #2f9e63);
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
</style>
