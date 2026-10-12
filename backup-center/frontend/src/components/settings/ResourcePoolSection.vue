<script setup>
import { ref, reactive, onMounted, computed } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Cloudy, Plus, Delete, Refresh, Edit, Link } from '@element-plus/icons-vue'
import { resourcePoolApi } from '@/api/resourcePools'
import { systemApi } from '@/api/settings'

const pools = ref([])
const loading = ref(false)
const dialogVisible = ref(false)
const editing = ref(false)
const savingPool = ref(false)
const testingId = ref(null)

const providers = [
  { value: 'tencent_cos', label: '腾讯云 COS', endpoint: 'cos.ap-guangzhou.myqcloud.com' },
  { value: 'aliyun_oss', label: '阿里云 OSS', endpoint: 'oss-cn-hangzhou.aliyuncs.com' },
  { value: 's3', label: 'S3 兼容', endpoint: '' },
]

const form = reactive({
  id: null, name: '', provider: 'tencent_cos', endpoint: '', region: '',
  bucket: '', access_key_id: '', secret_access_key: '', public_domain: '',
  is_agent_mirror: false, status: 'enabled', remark: '',
})

const providerLabel = (v) => providers.find(p => p.value === v)?.label || v

const load = async () => {
  loading.value = true
  try { pools.value = await resourcePoolApi.list() } finally { loading.value = false }
}
const openCreate = () => {
  editing.value = false
  Object.assign(form, {
    id: null, name: '', provider: 'tencent_cos', endpoint: 'cos.ap-guangzhou.myqcloud.com',
    region: 'ap-guangzhou', bucket: '', access_key_id: '', secret_access_key: '',
    public_domain: '', is_agent_mirror: false, status: 'enabled', remark: '',
  })
  dialogVisible.value = true
}
const openEdit = (p) => {
  editing.value = true
  Object.assign(form, {
    id: p.id, name: p.name, provider: p.provider, endpoint: p.endpoint || '',
    region: p.region || '', bucket: p.bucket, access_key_id: '', secret_access_key: '',
    public_domain: p.public_domain || '', is_agent_mirror: !!p.is_agent_mirror,
    status: p.status, remark: p.remark || '',
  })
  dialogVisible.value = true
}
const onProviderChange = (v) => {
  const preset = providers.find(x => x.value === v)
  if (preset && !form.endpoint) form.endpoint = preset.endpoint
}
const savePool = async () => {
  if (!form.name || !form.bucket) return ElMessage.warning('名称和 bucket 必填')
  savingPool.value = true
  try {
    const payload = { ...form }
    if (!payload.access_key_id) delete payload.access_key_id
    if (!payload.secret_access_key) delete payload.secret_access_key
    if (editing.value) await resourcePoolApi.update(form.id, payload)
    else await resourcePoolApi.create(payload)
    ElMessage.success('已保存')
    dialogVisible.value = false
    await load()
  } finally { savingPool.value = false }
}
const testPool = async (p) => {
  testingId.value = p.id
  try {
    const res = await resourcePoolApi.test(p.id)
    ElMessage({ type: res.ok ? 'success' : 'warning', message: res.ok ? '连接成功' : (res.message || '失败'), duration: 5000 })
    await load()
  } finally { testingId.value = null }
}
const deletePool = async (p) => {
  try {
    await ElMessageBox.confirm(`确定删除资源池"${p.name}"？bucket 里的文件不会被删除。`, '删除资源池', {
      type: 'warning', confirmButtonText: '删除', cancelButtonText: '取消',
    })
  } catch (_) { return }
  await resourcePoolApi.destroy(p.id)
  ElMessage.success('已删除')
  await load()
}
// R2 镜像 URL —— 独立于池子，直接存 system_settings
const r2MirrorUrl = ref('')
const savingR2 = ref(false)
const loadR2 = async () => {
  try { const d = await systemApi.get(); r2MirrorUrl.value = d.agent_mirror_url_r2 || '' } catch (_) {}
}
const saveR2 = async () => {
  const url = (r2MirrorUrl.value || '').trim()
  if (url && !/^https?:\/\//.test(url)) return ElMessage.warning('URL 必须以 http:// 或 https:// 开头')
  savingR2.value = true
  try {
    await systemApi.update({ agent_mirror_url_r2: url })
    ElMessage.success(url ? '已保存 R2 镜像 URL' : '已清除 R2 镜像 URL')
  } finally { savingR2.value = false }
}

onMounted(async () => { await load(); await loadR2() })

const mirrorPool = computed(() => pools.value.find(p => p.is_agent_mirror))
</script>

<template>
  <div>
    <div class="head-row">
      <div>
        <div class="s-hint" style="margin: 0 0 6px">
          管理对象存储凭证（腾讯云 COS、阿里云 OSS 等）。
          <b>Agent 二进制的国内下载镜像</b>也从这里指定 —— 把某个池子设为"Agent Mirror"即可。
        </div>
        <div v-if="mirrorPool" class="mirror-hint">
          <el-icon><Link /></el-icon>
          当前 Agent 镜像 = <b>{{ mirrorPool.name }}</b> · {{ mirrorPool.public_url_prefix }}
        </div>
        <div v-else class="mirror-hint warn">
          <el-icon><Link /></el-icon>
          未设置 Agent 镜像池 —— 境内机器 Agent 升级会走 Center 源（慢）
        </div>

        <div class="r2-mirror-row">
          <span class="r2-label">🌐 境外 R2 镜像（可选）</span>
          <el-input v-model="r2MirrorUrl" placeholder="https://mirror.example.com（境外镜像域名，Agent 二进制的备用下载源）" size="small" style="width: 400px" clearable />
          <el-button size="small" :loading="savingR2" @click="saveR2">保存</el-button>
          <span class="r2-hint">配好后服务器详情弹窗会自动生成一条 R2 版本的 Agent 安装命令</span>
        </div>

      </div>
      <el-button type="primary" :icon="Plus" @click="openCreate">新增资源池</el-button>
    </div>

    <el-table :data="pools" v-loading="loading" style="width: 100%; margin-top: 16px" size="default">
      <el-table-column label="名称" min-width="84">
        <template #default="{ row }">
          <div style="font-weight: 500">{{ row.name }}</div>
          <div class="muted">{{ providerLabel(row.provider) }}</div>
        </template>
      </el-table-column>
      <el-table-column label="Bucket" min-width="84">
        <template #default="{ row }">
          <div>{{ row.bucket }}</div>
          <div class="muted">{{ row.region || row.endpoint }}</div>
        </template>
      </el-table-column>
      <el-table-column label="公开域名" min-width="120">
        <template #default="{ row }">
          <a v-if="row.public_url_prefix" :href="row.public_url_prefix" target="_blank" class="link">
            {{ row.public_url_prefix }}
          </a>
          <span v-else class="muted">—</span>
        </template>
      </el-table-column>
      <el-table-column label="Agent Mirror" width="120" align="center">
        <template #default="{ row }">
          <span v-if="row.is_agent_mirror" class="badge active">当前镜像</span>
          <span v-else class="muted">—</span>
        </template>
      </el-table-column>
      <el-table-column label="连接测试" width="140">
        <template #default="{ row }">
          <span v-if="row.last_test_status === 'ok'" class="badge ok">通过</span>
          <span v-else-if="row.last_test_status === 'failed'" class="badge fail">失败</span>
          <span v-else class="muted">未测</span>
        </template>
      </el-table-column>
      <el-table-column label="凭证" width="80" align="center">
        <template #default="{ row }">
          <span :class="['badge', row.has_credentials ? 'ok' : 'gray']">
            {{ row.has_credentials ? '已配' : '未配' }}
          </span>
        </template>
      </el-table-column>
      <el-table-column label="操作" width="330" fixed="right">
        <template #default="{ row }">
          <el-button size="small" :icon="Refresh" :loading="testingId === row.id" @click="testPool(row)">测试</el-button>
          <el-button size="small" :icon="Edit" @click="openEdit(row)">编辑</el-button>
          <el-button size="small" :icon="Delete" type="danger" plain @click="deletePool(row)">删除</el-button>
        </template>
      </el-table-column>
    </el-table>

    <el-dialog v-model="dialogVisible" :title="editing ? '编辑资源池' : '新增资源池'" width="560px">
      <el-form label-width="110px" label-position="right">
        <el-form-item label="名称">
          <el-input v-model="form.name" placeholder="例如：腾讯云 COS - 广州" maxlength="64" />
        </el-form-item>
        <el-form-item label="服务商">
          <el-select v-model="form.provider" @change="onProviderChange" style="width: 100%">
            <el-option v-for="p in providers" :key="p.value" :label="p.label" :value="p.value" />
          </el-select>
        </el-form-item>
        <el-form-item label="Endpoint">
          <el-input v-model="form.endpoint" placeholder="cos.ap-guangzhou.myqcloud.com" />
        </el-form-item>
        <el-form-item label="Region">
          <el-input v-model="form.region" placeholder="ap-guangzhou / oss-cn-hangzhou" />
        </el-form-item>
        <el-form-item label="Bucket">
          <el-input v-model="form.bucket" placeholder="my-bucket-1234567890" />
        </el-form-item>
        <el-form-item label="SecretId">
          <el-input v-model="form.access_key_id" :placeholder="editing ? '留空 = 不更新' : 'AKID...'" />
        </el-form-item>
        <el-form-item label="SecretKey">
          <el-input v-model="form.secret_access_key" type="password" show-password
                    :placeholder="editing ? '留空 = 不更新' : ''" />
        </el-form-item>
        <el-form-item label="自定义域名">
          <el-input v-model="form.public_domain" placeholder="https://cdn.example.com（可选，需以 http/https 开头）" />
        </el-form-item>
        <el-form-item label="Agent Mirror">
          <el-switch v-model="form.is_agent_mirror" />
          <span class="s-hint" style="margin-left: 10px">Agent 二进制的国内下载镜像用这个池子</span>
        </el-form-item>
        <el-form-item label="备注">
          <el-input v-model="form.remark" type="textarea" :rows="2" maxlength="500" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="dialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="savingPool" @click="savePool">保存</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped>
.head-row { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; }
.s-hint { color: var(--bc-text-secondary); font-size: 13px; line-height: 1.6; }
.mirror-hint { display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px; background: rgba(37,99,235,0.08); border-radius: 8px; color: var(--bc-primary); font-size: 12px; margin-top: 6px; }
.mirror-hint.warn { background: rgba(245,158,11,0.08); color: #b45309; }
.r2-mirror-row { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin-top: 10px; }
.r2-label { font-size: 12px; color: var(--bc-text-primary); font-weight: 500; }
.r2-hint { font-size: 11px; color: var(--bc-text-placeholder); }
.sync-result { width: 100%; margin-top: 8px; padding: 8px 12px; background: var(--bc-bg-page); border-radius: 6px; }
.sync-pool { font-size: 12px; margin: 4px 0; }
.sync-file { display: inline-block; margin-right: 12px; font-family: 'JetBrains Mono', ui-monospace, Consolas, monospace; }
.sync-file.ok { color: #059669; }
.sync-file.fail { color: #dc2626; text-decoration: underline dotted; }
.sync-fail { color: #dc2626; }
.link { color: var(--bc-primary); text-decoration: none; word-break: break-all; font-size: 12px; }
.link:hover { text-decoration: underline; }
.muted { color: var(--bc-text-secondary); font-size: 12px; }
.badge { display: inline-flex; padding: 2px 10px; border-radius: 999px; font-size: 11px; font-weight: 500; }
.badge.ok { background: #ecfdf5; color: #059669; }
.badge.fail { background: #fef2f2; color: #dc2626; }
.badge.gray { background: #f1f5f9; color: #64748b; }
.badge.active { background: #dbeafe; color: #1d4ed8; }
</style>
