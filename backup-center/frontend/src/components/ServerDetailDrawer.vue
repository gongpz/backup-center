<script setup>
import { ref, computed, watch } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { CopyDocument, Refresh, Upload, Loading } from '@element-plus/icons-vue'
import { serversApi } from '@/api/servers'
import { copyText } from '@/utils/clipboard'

const props = defineProps({
  modelValue: { type: Boolean, default: false },
  serverId: { type: Number, default: null },
})
const emit = defineEmits(['update:modelValue', 'refreshed'])

const detail = ref(null)
const loading = ref(false)

const agentMirrorUrl = ref('')
const agentMirrorUrlR2 = ref('')

const load = async () => {
  if (!props.serverId) return
  loading.value = true
  try {
    const [d, sys] = await Promise.all([
      serversApi.get(props.serverId),
      (await import('@/api/settings')).systemApi.get().catch(() => ({})),
    ])
    detail.value = d
    agentMirrorUrl.value = sys?.agent_mirror_url || ''
    agentMirrorUrlR2.value = sys?.agent_mirror_url_r2 || ''
  } finally {
    loading.value = false
  }
}

// 国内加速安装（走 COS mirror） —— 只在设置了 mirror 时展示
const installCmdMirror = computed(() => {
  if (!detail.value?.agent_token || !agentMirrorUrl.value) return ''
  const origin = `${window.location.protocol}//${window.location.host}`
  return `curl -fsSL ${origin}/agent/install.sh | \\
  BC_URL="${origin}" \\
  BC_MIRROR="${agentMirrorUrl.value}" \\
  BC_TOKEN="${detail.value.agent_token}" \\
  sh`
})

// 境外加速安装（走 R2 mirror） —— 只在设置了 R2 URL 时展示
const installCmdMirrorR2 = computed(() => {
  if (!detail.value?.agent_token || !agentMirrorUrlR2.value) return ''
  const origin = `${window.location.protocol}//${window.location.host}`
  return `curl -fsSL ${origin}/agent/install.sh | \\
  BC_URL="${origin}" \\
  BC_MIRROR="${agentMirrorUrlR2.value}" \\
  BC_TOKEN="${detail.value.agent_token}" \\
  sh`
})

watch(
  () => props.modelValue,
  (v) => {
    if (v) { load(); loadLatestVersion() }
    else detail.value = null
  },
)

// ---------- Agent 升级（与列表页一致） ----------
const latestVersion = ref('')
const latestVersionError = ref(false)
const dispatchingIds = ref(new Set())

const loadLatestVersion = async () => {
  latestVersionError.value = false
  try {
    const res = await serversApi.agentLatestVersion()
    latestVersion.value = res.version || ''
  } catch (_) {
    latestVersionError.value = true
    // 静默失败会让升级入口隐身，用户会以为该机器已是最新版
    console.warn('获取最新 Agent 版本失败，详情页升级按钮已隐藏')
  }
}

const isUpgrading = (d) => {
  const c = d?.pending_agent_command
  return c && (c.status === 'pending' || c.status === 'dispatched')
}
const isAgentOutdated = (d) =>
  d?.connect_type === 'agent' &&
  d?.status === 'online' &&
  !!latestVersion.value &&
  d?.agent_version &&
  d.agent_version !== latestVersion.value &&
  !isUpgrading(d)

// 下发后加密轮询，让用户实时看到"升级中 → 完成/失败"
const pollAfterDispatch = () => {
  ;[5000, 15000, 30000, 60000, 90000].forEach((delay) => {
    setTimeout(() => load().catch(() => {}), delay)
  })
}

const dispatchUpdate = (d) => {
  if (!d || dispatchingIds.value.has(d.id)) return
  ElMessageBox.confirm(
    `确认给"${d.name}"下发升级到 v${latestVersion.value}？Agent 将在 60 秒内收到并执行，期间会短暂重启。\n\n注意：Agent 版本 < v0.7.0 的老机器不支持远程升级，需要 SSH 到目标机器手动执行 backup-agent update 或重装。`,
    '升级 Agent',
    { type: 'warning', confirmButtonText: '下发', cancelButtonText: '取消' },
  )
    .then(async () => {
      const newSet = new Set(dispatchingIds.value)
      newSet.add(d.id)
      dispatchingIds.value = newSet
      try {
        const res = await serversApi.dispatchAgentUpdate(d.id)
        ElMessage.success(res.message || '已下发，请关注升级进度')
        await load()
        pollAfterDispatch()
      } catch (_) { /* 拦截器处理 */ } finally {
        const s = new Set(dispatchingIds.value)
        s.delete(d.id)
        dispatchingIds.value = s
      }
    })
    .catch(() => {})
}

// 通用安装命令（境外机器 / Center 本地 —— 走 Center 源）
const installCmd = computed(() => {
  if (!detail.value?.agent_token) return ''
  const origin = `${window.location.protocol}//${window.location.host}`
  return `curl -fsSL ${origin}/agent/install.sh | \\
  BC_URL="${origin}" \\
  BC_TOKEN="${detail.value.agent_token}" \\
  sh`
})

const origin = computed(() => `${window.location.protocol}//${window.location.host}`)

const copy = async (text) => {
  const ok = await copyText(text)
  if (ok) ElMessage.success('已复制')
  else ElMessage.error('复制失败，请手动复制')
}

const regenerate = () => {
  ElMessageBox.confirm(
    '重生后旧 Token 立即失效，已装的 Agent 需要用新 Token 重新配置。确认继续？',
    '重生 Agent Token',
    { type: 'warning', confirmButtonText: '重生', cancelButtonText: '取消' },
  )
    .then(async () => {
      const res = await serversApi.regenerateToken(props.serverId)
      ElMessage.success('已重生 Token')
      await load()
      emit('refreshed')
    })
    .catch(() => {})
}

const statusMap = {
  online:  { text: '在线',  cls: 'ok' },
  offline: { text: '离线',  cls: 'gray' },
  pending: { text: '待连接', cls: 'warn' },
  error:   { text: '异常',  cls: 'fail' },
}

const fmtBytes = (n) => {
  if (n == null) return '—'
  const units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB']
  let v = Number(n), i = 0
  while (v >= 1024 && i < units.length - 1) { v /= 1024; i++ }
  return v.toFixed(v >= 100 || i === 0 ? 0 : 1) + ' ' + units[i]
}
const pctOf = (d) => {
  if (!d?.total) return 0
  return Math.round((Number(d.used) / Number(d.total)) * 100)
}
const usageClass = (d) => {
  const p = pctOf(d)
  if (p >= 90) return 'crit'
  if (p >= 75) return 'warn'
  return 'ok'
}
</script>

<template>
  <el-drawer
    :model-value="modelValue"
    title="服务器详情"
    size="520px"
    :close-on-click-modal="true"
    @update:model-value="(v) => emit('update:modelValue', v)"
  >
    <div v-loading="loading" class="detail" v-if="detail">
      <!-- 概要 -->
      <div class="info-card">
        <div class="info-head">
          <div>
            <div class="info-title">{{ detail.name }}</div>
            <div class="info-sub">
              {{ detail.connect_type === 'agent' ? 'Agent 模式' : 'SSH 模式' }} ·
              {{ detail.os_type || '未知系统' }}
              {{ detail.ip ? ` · ${detail.ip}` : '' }}
            </div>
          </div>
          <span :class="['badge', statusMap[detail.status]?.cls]">
            {{ statusMap[detail.status]?.text }}
          </span>
        </div>
        <div v-if="detail.remark" class="remark">{{ detail.remark }}</div>
      </div>

      <!-- Agent 信息 -->
      <template v-if="detail.connect_type === 'agent'">
        <div class="section-title">Agent 信息</div>
        <div class="kv-grid">
          <div class="kv kv-version">
            <div class="k">Agent 版本</div>
            <div class="v ver-line">
              <span>{{ detail.agent_version || '—' }}</span>
              <span
                v-if="isUpgrading(detail)"
                class="upg-chip"
                :title="`升级中 → v${detail.pending_agent_command.to_version}`"
              >
                <el-icon class="upg-spin"><Loading /></el-icon>
                升级中 v{{ detail.pending_agent_command.to_version }}
              </span>
              <el-button
                v-else-if="isAgentOutdated(detail)"
                size="small"
                type="primary"
                :icon="Upload"
                :loading="dispatchingIds.has(detail.id)"
                :disabled="dispatchingIds.has(detail.id)"
                @click="dispatchUpdate(detail)"
              >升级</el-button>
              <el-button
                v-else-if="latestVersionError"
                size="small"
                text
                type="danger"
                @click="loadLatestVersion"
              >版本获取失败·重试</el-button>
            </div>
          </div>
          <div class="kv"><div class="k">主机名</div><div class="v">{{ detail.hostname || '—' }}</div></div>
          <div class="kv"><div class="k">内核</div><div class="v">{{ detail.kernel || '—' }}</div></div>
          <div class="kv"><div class="k">架构</div><div class="v">{{ detail.arch || '—' }}</div></div>
          <div class="kv"><div class="k">CPU</div><div class="v">{{ detail.cpu_usage ?? '—' }}%</div></div>
          <div class="kv"><div class="k">内存</div><div class="v">{{ detail.mem_usage ?? '—' }}%</div></div>
          <div class="kv"><div class="k">根分区</div><div class="v">{{ detail.disk_usage ?? '—' }}%</div></div>
          <div class="kv"><div class="k">最后心跳</div><div class="v">{{ detail.last_seen_at ? new Date(detail.last_seen_at).toLocaleString() : '—' }}</div></div>
        </div>

        <!-- 磁盘分区列表（v0.6+ Agent 上报） -->
        <template v-if="detail.disks?.length">
          <div class="section-title">
            磁盘分区
            <span class="hint">{{ detail.disks.length }} 个</span>
          </div>
          <div class="disks">
            <div v-for="d in detail.disks" :key="d.mount" class="disk">
              <div class="disk-head">
                <span class="disk-mount">{{ d.mount }}</span>
                <span class="disk-fs">{{ d.fs }}</span>
                <span class="disk-usage" :class="usageClass(d)">{{ pctOf(d) }}%</span>
              </div>
              <div class="disk-bar">
                <div class="disk-bar-fill" :class="usageClass(d)" :style="{ width: pctOf(d) + '%' }"></div>
              </div>
              <div class="disk-sub">
                已用 {{ fmtBytes(d.used) }} / 总 {{ fmtBytes(d.total) }} · 剩余 {{ fmtBytes(d.available) }}
              </div>
            </div>
          </div>
        </template>

        <!-- Token -->
        <div class="section-title">
          Agent Token
          <el-button type="danger" size="small" text @click="regenerate">
            <el-icon><Refresh /></el-icon>
            重生 Token
          </el-button>
        </div>
        <div class="token-box">
          <code>{{ detail.agent_token }}</code>
          <el-button size="small" text @click="copy(detail.agent_token)">
            <el-icon><CopyDocument /></el-icon>
            复制
          </el-button>
        </div>

        <!-- 安装命令：国内 mirror 优先展示（有配置时），后面跟通用版 -->
        <div class="section-title">
          安装命令
          <span class="hint">在目标服务器上以 root 执行 · 按机器所在地选一个</span>
        </div>

        <div v-if="installCmdMirror" class="cmd-block">
          <div class="cmd-label">
            <span class="chip chip-red">🇨🇳 国内加速版（COS）</span>
            <span class="cmd-hint">大陆机器用这个（走你配的 Agent 镜像池，通常是腾讯云 COS）</span>
          </div>
          <div class="cmd-box">
            <pre>{{ installCmdMirror }}</pre>
            <el-button size="small" text @click="copy(installCmdMirror)">
              <el-icon><CopyDocument /></el-icon>
              复制
            </el-button>
          </div>
        </div>

        <div v-if="installCmdMirrorR2" class="cmd-block">
          <div class="cmd-label">
            <span class="chip chip-purple">🌐 境外加速版（R2）</span>
            <span class="cmd-hint">境外机器/分发给他人用（走 Cloudflare R2，免费出站）</span>
          </div>
          <div class="cmd-box">
            <pre>{{ installCmdMirrorR2 }}</pre>
            <el-button size="small" text @click="copy(installCmdMirrorR2)">
              <el-icon><CopyDocument /></el-icon>
              复制
            </el-button>
          </div>
        </div>

        <div class="cmd-block">
          <div class="cmd-label">
            <span class="chip chip-blue">🌍 通用版</span>
            <span class="cmd-hint">无 CDN 时用（走 Center 源，跨海慢）</span>
          </div>
          <div class="cmd-box">
            <pre>{{ installCmd }}</pre>
            <el-button size="small" text @click="copy(installCmd)">
              <el-icon><CopyDocument /></el-icon>
              复制
            </el-button>
          </div>
        </div>

      </template>

      <!-- SSH 信息 -->
      <template v-else>
        <div class="section-title">SSH 配置</div>
        <div class="kv-grid">
          <div class="kv"><div class="k">IP</div><div class="v">{{ detail.ip || '—' }}</div></div>
          <div class="kv"><div class="k">端口</div><div class="v">{{ detail.ssh_port }}</div></div>
          <div class="kv"><div class="k">用户</div><div class="v">{{ detail.ssh_user || '—' }}</div></div>
          <div class="kv"><div class="k">私钥</div><div class="v">{{ detail.has_ssh_key ? '已配置' : '未配置' }}</div></div>
          <div class="kv kv-full"><div class="k">备份目录</div><div class="v">{{ detail.backup_path || '—' }}</div></div>
        </div>
      </template>
    </div>
  </el-drawer>
</template>

<style scoped>
.detail { padding: 4px; }

.info-card {
  background: var(--bc-bg-page);
  border-radius: var(--bc-radius-lg);
  padding: 16px;
  margin-bottom: 20px;
}
.info-head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 12px;
}
.info-title {
  font-size: 16px;
  font-weight: 600;
  color: var(--bc-text-primary);
}
.info-sub {
  font-size: 12px;
  color: var(--bc-text-secondary);
  margin-top: 4px;
}
.remark {
  margin-top: 12px;
  padding-top: 12px;
  border-top: 1px solid var(--bc-border-light);
  font-size: 13px;
  color: var(--bc-text-regular);
}

.section-title {
  display: flex;
  align-items: center;
  justify-content: space-between;
  font-size: 13px;
  font-weight: 600;
  color: var(--bc-text-primary);
  margin: 20px 0 10px;
}
.section-title .hint {
  font-weight: normal;
  font-size: 12px;
  color: var(--bc-text-secondary);
}

.kv-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 10px 20px;
}
.kv-full { grid-column: 1 / -1; }
.kv .k { font-size: 12px; color: var(--bc-text-secondary); margin-bottom: 2px; }
.kv .v { font-size: 13px; color: var(--bc-text-primary); font-weight: 500; word-break: break-all; }

/* 详情页 Agent 版本行：版本 + 升级按钮 / 升级中 chip（与列表页一致） */
.ver-line {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}
.upg-chip {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 1px 12px;
  border-radius: 999px;
  font-size: 11px;
  font-weight: 500;
  background: var(--bc-primary-light);
  color: var(--bc-primary);
  border: 1px solid var(--bc-primary-100);
  line-height: 1.6;
  letter-spacing: 0.2px;
}
.upg-spin {
  animation: upg-rotate 1s linear infinite;
  font-size: 12px;
}
@keyframes upg-rotate {
  from { transform: rotate(0deg); }
  to   { transform: rotate(360deg); }
}

.badge {
  display: inline-flex;
  align-items: center;
  padding: 1px 12px;
  border-radius: 999px;
  font-size: 11px;
  font-weight: 500;
  line-height: 1.6;
  letter-spacing: 0.2px;
  white-space: nowrap;
  flex-shrink: 0;
}
.badge.ok   { background: var(--bc-success-bg); color: var(--bc-success); border: 1px solid var(--bc-success-border); }
.badge.gray { background: var(--bc-bg-hover); color: var(--bc-text-secondary); border: 1px solid var(--bc-border-light); }
.badge.warn { background: var(--bc-warning-bg); color: var(--bc-warning); border: 1px solid var(--bc-warning-border); }
.badge.fail { background: var(--bc-danger-bg); color: var(--bc-danger); border: 1px solid var(--bc-danger-border); }

.cmd-block {
  margin-bottom: 12px;
}
.cmd-label {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 6px;
  flex-wrap: wrap;
}
.cmd-hint {
  font-size: 11px;
  color: var(--bc-text-secondary);
}
.chip {
  display: inline-flex;
  align-items: center;
  padding: 1px 12px;
  border-radius: 999px;
  font-size: 11px;
  font-weight: 500;
  line-height: 1.6;
  letter-spacing: 0.2px;
  white-space: nowrap;
}
.chip-blue   { background: var(--bc-primary-light); color: var(--bc-primary); border: 1px solid var(--bc-primary-100); }
.chip-red    { background: var(--bc-danger-bg); color: var(--bc-danger); border: 1px solid var(--bc-danger-border); }
.chip-purple { background: var(--bc-info-bg); color: var(--bc-info); border: 1px solid var(--bc-info-border); }
.token-box, .cmd-box {
  background: var(--bc-bg-page);
  border: 1px solid var(--bc-border-light);
  border-radius: var(--bc-radius);
  padding: 12px;
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 10px;
}
.token-box code {
  font-family: 'JetBrains Mono', ui-monospace, Consolas, monospace;
  font-size: 12px;
  color: var(--bc-text-primary);
  word-break: break-all;
  flex: 1;
}
.cmd-box pre {
  margin: 0;
  font-family: 'JetBrains Mono', ui-monospace, Consolas, monospace;
  font-size: 12px;
  color: var(--bc-text-primary);
  white-space: pre-wrap;
  word-break: break-all;
  flex: 1;
  line-height: 1.6;
}

/* removed */
.__unused__ {
  display: none;
  user-select: all;
}

/* 磁盘分区 */
.disks {
  display: flex;
  flex-direction: column;
  gap: 12px;
}
.disk {
  border: 1px solid var(--bc-border-light);
  border-radius: var(--bc-radius);
  padding: 10px 12px;
  background: var(--bc-bg-page);
}
.disk-head {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 6px;
}
.disk-mount {
  font-family: 'JetBrains Mono', ui-monospace, Consolas, monospace;
  font-size: 13px;
  font-weight: 500;
  color: var(--bc-text-primary);
  flex: 1;
  word-break: break-all;
}
.disk-fs {
  font-size: 11px;
  padding: 1px 6px;
  border-radius: 4px;
  background: var(--bc-bg-card);
  border: 1px solid var(--bc-border-light);
  color: var(--bc-text-secondary);
  flex-shrink: 0;
}
.disk-usage {
  font-size: 12px;
  font-weight: 600;
  min-width: 42px;
  text-align: right;
}
.disk-usage.ok   { color: var(--bc-success); }
.disk-usage.warn { color: var(--bc-warning); }
.disk-usage.crit { color: var(--bc-danger); }

.disk-bar {
  height: 6px;
  background: var(--bc-border-light);
  border-radius: 999px;
  overflow: hidden;
}
.disk-bar-fill {
  height: 100%;
  border-radius: 999px;
  transition: width .2s;
}
.disk-bar-fill.ok   { background: var(--bc-success); }
.disk-bar-fill.warn { background: var(--bc-warning); }
.disk-bar-fill.crit { background: var(--bc-danger); }

.disk-sub {
  margin-top: 6px;
  font-size: 12px;
  color: var(--bc-text-secondary);
}
</style>
