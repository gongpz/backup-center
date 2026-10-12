<script setup>
import { ref, reactive, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { FolderOpened, Clock } from '@element-plus/icons-vue'
import { systemApi } from '@/api/settings'
import { systemApi as sysBackupApi } from '@/api/websites'

const form = reactive({ system_backup_enabled: false, system_backup_time: '03:00' })
const loading = ref(false)
const savingSched = ref(false)
const backingUp = ref(false)

const load = async () => {
  loading.value = true
  try {
    const d = await systemApi.get()
    form.system_backup_enabled = !!d.system_backup_enabled
    form.system_backup_time = d.system_backup_time || '03:00'
  } finally { loading.value = false }
}
const saveBackupSchedule = async () => {
  savingSched.value = true
  try {
    await systemApi.update({
      system_backup_enabled: form.system_backup_enabled,
      system_backup_time: form.system_backup_time,
    })
    ElMessage.success(form.system_backup_enabled
      ? `已启用定时自动备份：每天 ${form.system_backup_time}`
      : '已关闭定时自动备份')
  } finally { savingSched.value = false }
}
const fmtBytes = (n) => {
  if (!n) return '0 B'
  const u = ['B', 'KB', 'MB', 'GB']; let v = Number(n), i = 0
  while (v >= 1024 && i < u.length - 1) { v /= 1024; i++ }
  return v.toFixed(1) + ' ' + u[i]
}
const backupNow = async () => {
  if (backingUp.value) return
  try {
    await ElMessageBox.confirm(
      '将把 Backup Center 系统自身（SQLite 数据库 + .env + Laravel storage）打包，上传到所有"强制备份"存储。\n\n预计几秒到几十秒。',
      '立即备份 Backup Center 系统',
      { type: 'info', confirmButtonText: '立即备份', cancelButtonText: '取消' },
    )
  } catch (_) { return }
  backingUp.value = true
  try {
    const res = await sysBackupApi.backupNow()
    const uploaded = res.uploaded || []
    const failed = res.failed || []
    const parts = [`✓ 备份包：${res.filename} · ${fmtBytes(res.size)}`]
    if (uploaded.length) { parts.push('', '已上传：'); uploaded.forEach(u => parts.push(`  • ${u.storage_name}  →  ${u.remote_path}`)) }
    if (failed.length) { parts.push('', '失败：'); failed.forEach(f => parts.push(`  • ${f.storage_name}：${f.error}`)) }
    ElMessageBox.alert(parts.join('\n'), res.ok ? '系统备份完成' : '系统备份部分失败', {
      type: res.ok ? (failed.length ? 'warning' : 'success') : 'error',
      customStyle: { 'white-space': 'pre-wrap', 'text-align': 'left' },
      confirmButtonText: '知道了',
    })
  } catch (_) {} finally { backingUp.value = false }
}
onMounted(load)
</script>

<template>
  <div class="section-grid">
    <div class="s-card action-card">
      <div class="s-card-head"><el-icon><FolderOpened /></el-icon><span>立即备份</span></div>
      <p class="s-hint">把 Center 系统本身（SQLite + <code>.env</code> + Laravel storage）打包上传到<b>所有"强制备份"存储</b>。<br />
        <b>丢了 .env 里的 APP_KEY，所有存储密码将无法解密</b>。</p>
      <el-button type="primary" size="large" :loading="backingUp" @click="backupNow" class="big-btn">
        <el-icon><FolderOpened /></el-icon>
        <span style="margin-left: 6px">立即备份 Backup Center</span>
      </el-button>
    </div>

    <div class="s-card">
      <div class="s-card-head"><el-icon><Clock /></el-icon><span>定时自动备份</span></div>
      <p class="s-hint">启用后每天到点自动执行一次"立即备份"。靠 Laravel Scheduler（容器 cron 每分钟触发）确保定时。</p>
      <el-form v-loading="loading" label-width="90px" label-position="right">
        <el-form-item label="启用">
          <el-switch v-model="form.system_backup_enabled" />
          <span class="s-hint" style="margin-left: 10px">
            {{ form.system_backup_enabled ? '每天到点自动备份' : '关闭（仅手动）' }}
          </span>
        </el-form-item>
        <el-form-item label="时间">
          <el-time-select v-model="form.system_backup_time"
            :disabled="!form.system_backup_enabled"
            start="00:00" step="00:10" end="23:50" placeholder="选择时间" style="width: 140px" />
          <span class="s-hint" style="margin-left: 10px">北京时间</span>
        </el-form-item>
        <el-form-item>
          <el-button type="primary" :loading="savingSched" @click="saveBackupSchedule">保存定时设置</el-button>
        </el-form-item>
      </el-form>
    </div>
  </div>
</template>

<style scoped>
.section-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
@media (max-width: 1080px) { .section-grid { grid-template-columns: 1fr; } }
.s-card { background: var(--bc-bg-card); border: 1px solid var(--bc-border-light); border-radius: 12px; padding: 20px 24px; }
.s-card-head { display: flex; align-items: center; gap: 8px; font-size: 15px; font-weight: 600; color: var(--bc-text-primary); margin-bottom: 8px; }
.s-card-head .el-icon { color: var(--bc-primary); }
.s-hint { color: var(--bc-text-secondary); font-size: 13px; margin: 0 0 16px; line-height: 1.6; }
.action-card { background: linear-gradient(135deg, var(--bc-primary-light) 0%, var(--bc-primary-100) 100%); border-color: var(--bc-primary-100); }
.big-btn { width: 100%; height: 44px; font-size: 14px; }
</style>
