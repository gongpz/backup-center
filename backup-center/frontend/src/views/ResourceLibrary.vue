<script setup>
import { ref, computed, onMounted, watch, reactive } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { UploadFilled, Delete, Refresh, CopyDocument, Link, EditPen } from '@element-plus/icons-vue'
import { resourcePoolApi } from '@/api/resourcePools'
import { copyText } from '@/utils/clipboard'

const pools = ref([])
const currentPoolId = ref(null)
const files = ref([])
const loading = ref(false)
const uploading = ref(false)
const uploadProgress = ref(0)
const fileInput = ref(null)

const currentPool = computed(() => pools.value.find(p => p.id === currentPoolId.value))

const loadPools = async () => {
  pools.value = await resourcePoolApi.list()
  if (!currentPoolId.value && pools.value.length) {
    currentPoolId.value = pools.value[0].id
  }
}
const loadFiles = async () => {
  if (!currentPoolId.value) return
  loading.value = true
  try {
    const res = await resourcePoolApi.listFiles(currentPoolId.value)
    if (!res.ok) {
      ElMessage.warning(res.message || '拉取失败')
      files.value = []
    } else {
      files.value = res.files || []
    }
  } finally { loading.value = false }
}
watch(currentPoolId, loadFiles)

const fmtBytes = (n) => {
  if (!n) return '0 B'
  const u = ['B', 'KB', 'MB', 'GB']; let v = Number(n), i = 0
  while (v >= 1024 && i < u.length - 1) { v /= 1024; i++ }
  return v.toFixed(1) + ' ' + u[i]
}
const fmtTime = (iso) => iso ? new Date(iso).toLocaleString() : '—'

const triggerUpload = () => fileInput.value?.click()
const onFileSelected = async (e) => {
  const file = e.target.files?.[0]
  e.target.value = ''
  if (!file) return
  if (file.size > 100 * 1024 * 1024) return ElMessage.warning('文件超过 100 MB 限制')
  uploading.value = true
  uploadProgress.value = 0
  try {
    const res = await resourcePoolApi.uploadFile(currentPoolId.value, file, null, (p) => {
      if (p.total) uploadProgress.value = Math.round((p.loaded / p.total) * 100)
    })
    if (res.ok) { ElMessage.success(`上传成功：${res.file.name}`); await loadFiles() }
    else ElMessage.error(res.message || '上传失败')
  } finally { uploading.value = false; uploadProgress.value = 0 }
}
const copyLink = async (url) => {
  const ok = await copyText(url)
  if (ok) ElMessage.success('已复制到剪贴板')
  else ElMessage.warning('复制失败，请手动复制')
}
const removeFile = async (f) => {
  try {
    await ElMessageBox.confirm(`确定删除文件"${f.name}"？此操作从 bucket 里物理删除。`, '删除文件', {
      type: 'warning', confirmButtonText: '删除', cancelButtonText: '取消',
    })
  } catch (_) { return }
  const res = await resourcePoolApi.deleteFile(currentPoolId.value, f.path)
  if (res.ok) { ElMessage.success('已删除'); await loadFiles() }
  else ElMessage.error(res.message || '删除失败')
}

// ---- 备注编辑 ----
const remarkDialogVisible = ref(false)
const remarkForm = reactive({ path: '', name: '', remark: '' })
const savingRemark = ref(false)

const openRemarkDialog = (row) => {
  remarkForm.path = row.path
  remarkForm.name = row.name
  remarkForm.remark = row.remark || ''
  remarkDialogVisible.value = true
}
const saveRemark = async () => {
  savingRemark.value = true
  try {
    const res = await resourcePoolApi.updateFileRemark(currentPoolId.value, remarkForm.path, remarkForm.remark)
    if (res.ok) {
      ElMessage.success('已保存')
      // 前端就地更新，避免整表重拉
      const f = files.value.find(x => x.path === remarkForm.path)
      if (f) f.remark = res.remark || null
      remarkDialogVisible.value = false
    } else {
      ElMessage.error('保存失败')
    }
  } finally { savingRemark.value = false }
}

onMounted(async () => { await loadPools(); await loadFiles() })
</script>

<template>
  <div>
    <div class="toolbar">
      <div>
        <h2 class="page-h">资源库</h2>
        <p class="page-sub">上传/管理放在对象存储上的静态资源（工具二进制、脚本、图片等），可以生成公开链接</p>
      </div>
      <div class="tools">
        <el-select v-if="pools.length" v-model="currentPoolId" style="width: 220px">
          <el-option v-for="p in pools" :key="p.id" :label="p.name" :value="p.id" />
        </el-select>
        <el-button :icon="Refresh" @click="loadFiles" :loading="loading">刷新</el-button>
        <el-button type="primary" :icon="UploadFilled" :disabled="!currentPoolId || uploading" @click="triggerUpload">
          上传文件（≤100 MB）
        </el-button>
        <input ref="fileInput" type="file" style="display: none" @change="onFileSelected" />
      </div>
    </div>

    <div v-if="!pools.length" class="bc-card empty">
      <p>还没有资源池。先去 <router-link to="/settings" class="link">设置 → 资源池</router-link> 新增一个（腾讯云 COS / 阿里云 OSS / Cloudflare R2）。</p>
    </div>

    <div v-else class="bc-card">
      <div v-if="currentPool" class="pool-head">
        <div>
          <div class="pool-name">{{ currentPool.name }} <span class="muted">{{ currentPool.bucket }}</span></div>
          <div class="pool-url"><el-icon><Link /></el-icon>{{ currentPool.public_url_prefix }}</div>
        </div>
        <span v-if="currentPool.is_agent_mirror" class="badge active">Agent 镜像池</span>
      </div>

      <div v-if="uploading" class="upload-progress">
        <span>上传中…</span>
        <el-progress :percentage="uploadProgress" :stroke-width="8" />
      </div>

      <el-table :data="files" v-loading="loading" class="resource-table" style="width: 100%; margin-top: 12px" empty-text="bucket 里还没有文件">
        <el-table-column label="文件" min-width="220">
          <template #default="{ row }">
            <a :href="row.public_url" target="_blank" class="link">{{ row.name }}</a>
          </template>
        </el-table-column>
        <el-table-column label="备注" min-width="220">
          <template #default="{ row }">
            <div class="remark-cell">
              <span v-if="row.remark" class="remark-text" :title="row.remark">{{ row.remark }}</span>
              <span v-else class="remark-empty">— 尚未添加备注</span>
              <el-button size="small" text :icon="EditPen" class="remark-edit" @click="openRemarkDialog(row)">编辑</el-button>
            </div>
          </template>
        </el-table-column>
        <el-table-column label="大小" width="90">
          <template #default="{ row }">{{ fmtBytes(row.size) }}</template>
        </el-table-column>
        <el-table-column label="修改时间" width="160">
          <template #default="{ row }">{{ fmtTime(row.modified) }}</template>
        </el-table-column>
        <el-table-column label="公开链接" min-width="240">
          <template #default="{ row }">
            <div class="url-row">
              <code class="url">{{ row.public_url }}</code>
              <el-button size="small" text :icon="CopyDocument" @click="copyLink(row.public_url)">复制</el-button>
            </div>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="90">
          <template #default="{ row }">
            <el-button size="small" type="danger" plain :icon="Delete" @click="removeFile(row)">删除</el-button>
          </template>
        </el-table-column>
      </el-table>
    </div>

    <!-- 备注编辑弹窗 -->
    <el-dialog v-model="remarkDialogVisible" title="编辑备注" width="460px">
      <el-form label-width="80px" label-position="right">
        <el-form-item label="文件">
          <span class="mono">{{ remarkForm.name }}</span>
        </el-form-item>
        <el-form-item label="备注">
          <el-input v-model="remarkForm.remark" type="textarea" :rows="4" maxlength="500"
                    show-word-limit placeholder="用途说明、下载来源、使用场景等（留空表示删除备注）" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="remarkDialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="savingRemark" @click="saveRemark">保存</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped>
/* 列序：1 文件 2 备注 3 大小 4 修改时间 5 公开链接 6 操作(始终保留) */
.toolbar { display: flex; align-items: flex-start; justify-content: space-between; margin-bottom: 20px; gap: 16px; }
.page-h { margin: 0; font-size: 20px; font-weight: 600; color: var(--bc-text-primary); }
.page-sub { margin: 4px 0 0; font-size: 12px; color: var(--bc-text-secondary); }
.tools { display: flex; gap: 8px; align-items: center; }
.empty { padding: 40px; text-align: center; color: var(--bc-text-secondary); }
.empty .link { color: var(--bc-primary); text-decoration: underline; }
.pool-head { display: flex; align-items: center; justify-content: space-between; padding-bottom: 16px; border-bottom: 1px solid var(--bc-border-light); margin-bottom: 8px; }
.pool-name { font-size: 15px; font-weight: 600; color: var(--bc-text-primary); }
.pool-url { display: flex; align-items: center; gap: 4px; font-size: 12px; color: var(--bc-text-secondary); margin-top: 4px; }
.pool-url .el-icon { color: var(--bc-primary); }
.muted { color: var(--bc-text-secondary); font-weight: normal; font-size: 12px; margin-left: 6px; }
.upload-progress { padding: 12px 16px; background: var(--bc-primary-light); border-radius: 8px; margin: 12px 0; }
.link { color: var(--bc-primary); text-decoration: none; }
.link:hover { text-decoration: underline; }
.url-row { display: flex; align-items: center; gap: 6px; }
.url { font-family: 'JetBrains Mono', ui-monospace, Consolas, monospace; font-size: 11px; color: var(--bc-text-regular); background: var(--bc-bg-page); padding: 2px 6px; border-radius: 4px; word-break: break-all; flex: 1; }
.badge { padding: 4px 12px; border-radius: 999px; font-size: 12px; font-weight: 500; background: var(--bc-primary-100); color: var(--bc-primary-hover); }
.remark-cell { display: flex; align-items: center; gap: 6px; }
.remark-text { font-size: 12px; color: var(--bc-text-regular); flex: 1; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.remark-empty { font-size: 12px; color: var(--bc-text-placeholder); flex: 1; }
.remark-edit { flex-shrink: 0; }
.mono { font-family: 'JetBrains Mono', ui-monospace, Consolas, monospace; font-size: 12px; color: var(--bc-text-regular); }
</style>
