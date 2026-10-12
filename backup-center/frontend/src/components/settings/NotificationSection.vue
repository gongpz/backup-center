<script setup>
import { ref, reactive, onMounted } from 'vue'
import { ElMessage } from 'element-plus'
import { Bell, Check, Clock } from '@element-plus/icons-vue'
import { notificationApi, profileApi } from '@/api/settings'

const loading = ref(false)
const saving = ref(false)
const testing = ref(false)

const form = reactive({
  serverchan_sendkey: '',
  notify_backup_success: false,
  notify_backup_failed: true,
  notify_agent_offline: true,
})
const state = reactive({ hasSendkey: false, sendkeyHint: null, updatedAt: null })
const lastTestRaw = ref(null)

const loadingLogs = ref(false)
const logs = ref([])

const load = async () => {
  loading.value = true
  try {
    const d = await notificationApi.get()
    state.hasSendkey = d.has_sendkey
    state.sendkeyHint = d.sendkey_hint
    state.updatedAt = d.updated_at
    form.serverchan_sendkey = d.sendkey_hint || ''
    form.notify_backup_success = d.notify_backup_success
    form.notify_backup_failed = d.notify_backup_failed
    form.notify_agent_offline = d.notify_agent_offline
  } finally {
    loading.value = false
  }
}
const loadLogs = async () => {
  loadingLogs.value = true
  try {
    logs.value = await profileApi.loginLogs()
  } finally {
    loadingLogs.value = false
  }
}
const fmtTime = (iso) => (iso ? new Date(iso).toLocaleString() : '—')

onMounted(() => { load(); loadLogs() })

const save = async () => {
  saving.value = true
  try {
    await notificationApi.update({
      serverchan_sendkey: form.serverchan_sendkey,
      notify_backup_success: form.notify_backup_success,
      notify_backup_failed: form.notify_backup_failed,
      notify_agent_offline: form.notify_agent_offline,
    })
    ElMessage.success('已保存')
    load()
  } finally {
    saving.value = false
  }
}

const testNotify = async () => {
  testing.value = true
  lastTestRaw.value = null
  try {
    const key = form.serverchan_sendkey?.includes('••••') ? '' : form.serverchan_sendkey
    const res = await notificationApi.test(key)
    ElMessage({ type: res.ok ? 'success' : 'warning', message: res.message, duration: 6000 })
    if (res.raw) lastTestRaw.value = res.raw
  } catch (_) {} finally {
    testing.value = false
  }
}
</script>

<template>
  <div>
    <div class="section-title">
      <el-icon size="18" style="color: var(--bc-primary)"><Bell /></el-icon>
      <span>Server酱通知</span>
      <span v-if="state.hasSendkey" class="badge ok">已配置</span>
      <span v-else class="badge gray">未配置</span>
    </div>
    <p class="hint">
      Server酱 = 微信推送服务。前往
      <a href="https://sct.ftqq.com/" target="_blank" rel="noopener">sct.ftqq.com</a>
      注册并复制 SendKey，粘贴到下方。
    </p>

    <el-form v-loading="loading" label-width="140px" label-position="right">
      <el-form-item label="Server酱 SendKey">
        <el-input
          v-model="form.serverchan_sendkey"
          placeholder="SCTxxxxxxxxxxxxxxxxxxxxxxxxxx"
          clearable
          show-password
        />
        <div class="field-hint">留空并保存可移除配置</div>
      </el-form-item>

      <el-form-item label="通知事件">
        <div class="events">
          <div class="event-row">
            <el-switch v-model="form.notify_backup_success" />
            <div>
              <div class="event-name">备份成功</div>
              <div class="event-desc">每次 Agent 上报新备份都推送（可能刷屏）</div>
            </div>
          </div>
          <div class="event-row">
            <el-switch v-model="form.notify_backup_failed" />
            <div>
              <div class="event-name">备份失败</div>
              <div class="event-desc">备份文件缺失或 Agent 上报失败时推送</div>
            </div>
          </div>
          <div class="event-row">
            <el-switch v-model="form.notify_agent_offline" />
            <div>
              <div class="event-name">Agent 离线</div>
              <div class="event-desc">Agent 心跳超过 120 秒无响应触发推送</div>
            </div>
          </div>
        </div>
      </el-form-item>

      <el-form-item>
        <el-button type="primary" :loading="saving" @click="save">保存</el-button>
        <el-button :icon="Check" :loading="testing" @click="testNotify">发测试通知</el-button>
      </el-form-item>

      <el-form-item v-if="lastTestRaw" label="测试返回">
        <pre class="raw-response">{{ typeof lastTestRaw === 'string' ? lastTestRaw : JSON.stringify(lastTestRaw, null, 2) }}</pre>
      </el-form-item>

      <el-form-item v-if="state.updatedAt" label=" ">
        <span class="muted">上次修改：{{ new Date(state.updatedAt).toLocaleString() }}</span>
      </el-form-item>
    </el-form>

    <el-divider />

    <!-- 登录记录 -->
    <div class="section-title" style="margin-top: 10px;">
      <el-icon size="18" style="color: var(--bc-primary)"><Clock /></el-icon>
      <span>登录记录</span>
      <span class="hint" style="font-weight: normal;">最新 30 条</span>
    </div>
    <el-table :data="logs" v-loading="loadingLogs" style="width: 100%; max-width: 900px" size="small">
      <el-table-column label="时间" width="180">
        <template #default="{ row }">{{ fmtTime(row.created_at) }}</template>
      </el-table-column>
      <el-table-column label="IP" prop="ip_address" width="140" />
      <el-table-column label="结果" width="80">
        <template #default="{ row }">
          <span :class="['badge', row.success ? 'ok' : 'fail']">
            {{ row.success ? '成功' : '失败' }}
          </span>
        </template>
      </el-table-column>
      <el-table-column label="说明" prop="message" min-width="200" />
    </el-table>
  </div>
</template>

<style scoped>
.section-title {
  display: flex; align-items: center; gap: 10px;
  font-size: 16px; font-weight: 600; color: var(--bc-text-primary);
  margin-bottom: 6px;
}
.hint { color: var(--bc-text-secondary); font-size: 13px; margin: 0 0 20px; line-height: 1.6; }
.hint a { color: var(--bc-primary); text-decoration: underline; }

.field-hint { font-size: 12px; color: var(--bc-text-placeholder); margin-top: 4px; }

.events { display: flex; flex-direction: column; gap: 14px; width: 100%; }
.event-row { display: flex; align-items: flex-start; gap: 12px; }
.event-name { font-size: 14px; font-weight: 500; color: var(--bc-text-primary); }
.event-desc { font-size: 12px; color: var(--bc-text-secondary); margin-top: 2px; }

.muted { color: var(--bc-text-secondary); font-size: 12px; }

.badge { display: inline-flex; align-items: center; padding: 1px 12px; border-radius: 999px; font-size: 11px; font-weight: 500; line-height: 1.6; letter-spacing: 0.2px; white-space: nowrap; }
.badge.ok   { background: var(--bc-success-bg); color: var(--bc-success); border: 1px solid var(--bc-success-border); }
.badge.gray { background: var(--bc-bg-hover); color: var(--bc-text-secondary); border: 1px solid var(--bc-border-light); }
.badge.fail { background: var(--bc-danger-bg); color: var(--bc-danger); border: 1px solid var(--bc-danger-border); }

:deep(.el-divider) {
  margin: 24px 0;
  border-color: var(--bc-border-light);
}

.raw-response {
  width: 100%; padding: 10px 12px;
  background: var(--bc-bg-page); border: 1px solid var(--bc-border-light); border-radius: 6px;
  font-family: 'JetBrains Mono', ui-monospace, Consolas, monospace;
  font-size: 12px; line-height: 1.5; color: var(--bc-text-regular);
  overflow-x: auto; margin: 0; white-space: pre-wrap; word-break: break-all;
}
</style>
