<script setup>
import { ref, reactive, computed, watch, onBeforeUnmount } from 'vue'
import { ElMessage } from 'element-plus'
import { Key, Download, CopyDocument, Position, Search, RefreshRight, CircleCheck } from '@element-plus/icons-vue'
import { websitesApi } from '@/api/websites'
import { copyText } from '@/utils/clipboard'

const props = defineProps({
  modelValue: { type: Boolean, default: false },
  website: { type: Object, default: null },
})
const emit = defineEmits(['update:modelValue', 'saved'])

const isAgentServer = computed(() => props.website?.server?.connect_type === 'agent')
const keyFileName = computed(() => `.bc-bind-${props.website?.bind_token || ''}`)

// 绑定状态：idle=待下发 / waiting=等待 Agent / done=绑定成功 / failed=失败
const phase = ref('idle')
const pollTimer = ref(null)
const elapsed = ref(0)
const tickTimer = ref(null)
const failReason = ref('')

const form = reactive({
  mode: 'search', // search=Agent 有界搜索 / direct=直接校验路径
  direct_path: '',
  search_bases: '/www/wwwroot',
})

const stopTimers = () => {
  if (pollTimer.value) { clearInterval(pollTimer.value); pollTimer.value = null }
  if (tickTimer.value) { clearInterval(tickTimer.value); tickTimer.value = null }
}

const resetState = () => {
  stopTimers()
  phase.value = props.website?.web_root ? 'done' : 'idle'
  elapsed.value = 0
  failReason.value = ''
  form.mode = 'search'
  form.direct_path = ''
  form.search_bases = '/www/wwwroot'
}

// 超时预算：搜索模式要 Agent 遍历整棵目录树（/www/wwwroot 级动辄数十万文件），
// 叠加最长 60 秒的心跳等待，90 秒不够用；直接指定路径只做一次 stat，90 秒充裕。
const timeoutSec = computed(() => (form.mode === 'search' ? 180 : 90))

watch(() => props.modelValue, (v) => { if (v) resetState() }, { immediate: true })
onBeforeUnmount(stopTimers)

const copyName = async () => {
  const ok = await copyText(keyFileName.value)
  if (ok) ElMessage.success('密钥文件名已复制')
  else ElMessage.warning('复制失败，请手动选择文件名复制')
}

const downloadKey = () => {
  const blob = new Blob([props.website?.bind_token || ''], { type: 'text/plain' })
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = keyFileName.value
  document.body.appendChild(a)
  a.click()
  a.remove()
  URL.revokeObjectURL(url)
  ElMessage.success('密钥文件已下载，请上传到网站根目录')
}

const submit = async () => {
  const bases = form.mode === 'search'
    ? form.search_bases.split('\n').map((s) => s.trim()).filter(Boolean).slice(0, 10)
    : []
  if (form.mode === 'search' && bases.length === 0) {
    ElMessage.warning('请至少填写一个搜索目录')
    return
  }
  if (form.mode === 'direct' && !form.direct_path.trim()) {
    ElMessage.warning('请填写网站根目录路径')
    return
  }

  phase.value = 'waiting'
  elapsed.value = 0
  try {
    await websitesApi.bindRoot(props.website.id, {
      direct_path: form.mode === 'direct' ? form.direct_path.trim() : null,
      search_bases: bases,
    })
    ElMessage.success('绑定指令已下发，等待 Agent 执行')
  } catch (_) {
    phase.value = 'idle'
    return
  }

  tickTimer.value = setInterval(() => { elapsed.value += 1 }, 1000)
  pollTimer.value = setInterval(pollBindResult, 4000)
}

const pollBindResult = async () => {
  try {
    const row = await websitesApi.get(props.website.id)
    if (row.web_root) {
      stopTimers()
      phase.value = 'done'
      ElMessage.success('根目录绑定成功')
      emit('saved', row)
      return
    }
  } catch (_) { /* 轮询失败不打断，下一轮再试 */ }
  // Agent 已回传失败：直接显示真实原因（如密钥文件名不符），不用干等满超时
  try {
    const res = await websitesApi.bindStatus(props.website.id)
    const cmd = res.command
    if (cmd && cmd.status === 'failed') {
      stopTimers()
      failReason.value = cmd.error || 'Agent 执行失败（未返回原因）'
      phase.value = 'failed'
      return
    }
  } catch (_) { /* 同上 */ }
  if (elapsed.value >= timeoutSec.value) {
    stopTimers()
    failReason.value = ''
    phase.value = 'failed'
  }
}

const close = () => emit('update:modelValue', false)
</script>

<template>
  <el-dialog
    :model-value="modelValue"
    title="绑定网站根目录"
    width="580px"
    :close-on-click-modal="false"
    @update:model-value="(v) => emit('update:modelValue', v)"
  >
    <div v-if="!website" class="bind-empty">未选择网站</div>
    <template v-else>
      <!-- 已绑定 -->
      <div v-if="phase === 'done' && website.web_root" class="bind-state bind-state-ok">
        <el-icon class="bs-icon"><CircleCheck /></el-icon>
        <div class="bs-text">
          <div class="bs-title">已绑定根目录</div>
          <div class="bs-path mono">{{ website.web_root }}</div>
          <div class="bs-sub">绑定时间：{{ website.root_bound_at ? new Date(website.root_bound_at).toLocaleString() : '—' }}</div>
        </div>
      </div>

      <template v-else>
        <div v-if="!isAgentServer" class="bind-notice">
          该网站所属服务器不是 Agent 模式，无法执行根目录绑定。请先将服务器接入 Agent。
        </div>

        <!-- 第一步：放置密钥文件 -->
        <div class="bind-step">
          <div class="step-head">
            <span class="step-no">1</span>
            <span class="step-title">放置密钥文件到网站根目录</span>
          </div>
          <p class="step-desc">
            下载下方密钥文件，通过 FTP / 宝塔文件管理等工具，将它上传到目标网站的<b>根目录</b>（存放 <span class="mono">index.php</span> / <span class="mono">index.html</span> 的那一层）。Agent 会依据它定位根目录。
          </p>
          <div class="key-file-box">
            <el-icon class="kf-icon"><Key /></el-icon>
            <span class="kf-name mono">{{ keyFileName }}</span>
            <el-button size="small" :icon="CopyDocument" @click="copyName">复制名称</el-button>
            <el-button size="small" type="primary" :icon="Download" @click="downloadKey">下载文件</el-button>
          </div>
          <div class="key-alt">
            <span class="key-alt-label">也可以在服务器上直接生成：</span>
            <code class="mono">cd 网站根目录 &amp;&amp; echo '{{ website.bind_token }}' &gt; {{ keyFileName }}</code>
          </div>
          <p class="step-warn">
            注意：部分工具（如 Windows 下载后经宝塔在线上传）会去掉文件名开头的小点或自动补上 <span class="mono">.txt</span>，导致名称不符。服务器上的最终文件名必须与上方完全一致——<b>以点开头、无扩展名</b>。拿不准时，建议直接用上面的命令在服务器上生成。
          </p>
        </div>

        <!-- 第二步：定位方式 -->
        <div class="bind-step">
          <div class="step-head">
            <span class="step-no">2</span>
            <span class="step-title">选择根目录定位方式</span>
          </div>
          <el-radio-group v-model="form.mode" class="mode-radio">
            <el-radio-button value="search">
              <el-icon><Search /></el-icon> Agent 搜索
            </el-radio-button>
            <el-radio-button value="direct">
              <el-icon><Position /></el-icon> 直接指定路径
            </el-radio-button>
          </el-radio-group>

          <div v-if="form.mode === 'search'" class="mode-body">
            <p class="step-desc">
              Agent 在下列目录中逐层查找密钥文件（最多 6 层，自动跳过系统目录），找到即回传所在目录为网站根目录。每行一个目录。
            </p>
            <el-input
              v-model="form.search_bases"
              type="textarea"
              :rows="3"
              placeholder="例如：&#10;/www/wwwroot&#10;/home/www"
              class="bases-input"
            />
          </div>
          <div v-else class="mode-body">
            <p class="step-desc">
              已知根目录路径时选此项，Agent 仅校验密钥文件是否存在于该目录，速度最快。
            </p>
            <el-input
              v-model="form.direct_path"
              placeholder="例如：/www/wwwroot/example.com"
              class="direct-input"
            />
          </div>
        </div>

        <!-- 等待 / 失败 -->
        <div v-if="phase === 'waiting'" class="bind-state bind-state-wait">
          <el-icon class="bs-icon is-loading"><RefreshRight /></el-icon>
          <div class="bs-text">
            <div class="bs-title">等待 Agent 执行（{{ elapsed }}s / {{ timeoutSec }}s）</div>
            <div class="bs-sub">指令已下发，Agent 心跳最长 60 秒内会执行；{{ form.mode === 'search' ? '大目录搜索可能需要额外时间，请耐心等待' : '请确认密钥文件已放置到位' }}。</div>
          </div>
        </div>
        <div v-else-if="phase === 'failed'" class="bind-state bind-state-fail">
          <div class="bs-text">
            <div class="bs-title">{{ failReason ? '绑定失败' : `等待超时（${timeoutSec} 秒）` }}</div>
            <div v-if="failReason" class="bs-reason mono">{{ failReason }}</div>
            <div class="bs-sub">请确认：① 密钥文件已放在根目录且名称完全一致；② 服务器在线且 Agent 正常运行；③ 搜索目录填写正确。可修正后重新下发。</div>
          </div>
        </div>
      </template>
    </template>

    <template #footer>
      <el-button @click="close">{{ phase === 'done' && website?.web_root ? '关闭' : '取消' }}</el-button>
      <el-button
        v-if="!(phase === 'done' && website?.web_root)"
        type="primary"
        :loading="phase === 'waiting'"
        :disabled="!isAgentServer"
        @click="submit"
      >
        {{ phase === 'failed' ? '重新绑定' : '开始绑定' }}
      </el-button>
    </template>
  </el-dialog>
</template>

<style scoped>
.bind-empty { padding: 20px 0; text-align: center; color: var(--bc-text-secondary); font-size: 13px; }

.bind-notice {
  background: var(--bc-warning-bg);
  border: 1px solid var(--bc-warning-border);
  border-radius: 8px;
  padding: 10px 12px;
  margin-bottom: 14px;
  font-size: 12px;
  color: var(--bc-warning);
}

.bind-step { margin-bottom: 18px; }
.step-head { display: flex; align-items: center; gap: 8px; margin-bottom: 8px; }
.step-no {
  width: 20px; height: 20px; border-radius: 50%;
  background: var(--bc-primary); color: var(--bc-on-accent);
  font-size: 12px; font-weight: 600;
  display: inline-flex; align-items: center; justify-content: center;
  flex-shrink: 0;
}
.step-title { font-size: 14px; font-weight: 600; color: var(--bc-text-primary); }
.step-desc { margin: 0 0 10px; font-size: 12px; line-height: 1.7; color: var(--bc-text-secondary); }
.step-desc b { color: var(--bc-text-primary); font-weight: 500; }

.mono { font-family: 'JetBrains Mono', ui-monospace, Consolas, monospace; }

.key-file-box {
  display: flex; align-items: center; gap: 10px;
  border: 1px solid var(--bc-primary-100);
  background: var(--bc-primary-light);
  border-radius: 10px;
  padding: 10px 12px;
}
.kf-icon { color: var(--bc-primary); font-size: 16px; flex-shrink: 0; }
.kf-name { flex: 1; font-size: 13px; color: var(--bc-primary); font-weight: 600; word-break: break-all; }

.key-alt { margin-top: 8px; font-size: 12px; color: var(--bc-text-secondary); }
.key-alt-label { display: block; margin-bottom: 4px; }
.key-alt code {
  display: block;
  background: var(--bc-bg-inset);
  border: 1px solid var(--bc-border-light);
  border-radius: 6px;
  padding: 8px 10px;
  font-size: 12px;
  color: var(--bc-text-primary);
  word-break: break-all;
}

.mode-radio { margin-bottom: 10px; }
.mode-body .step-desc { margin-bottom: 8px; }
.bases-input :deep(.el-textarea__inner),
.direct-input :deep(.el-input__inner) { font-family: 'JetBrains Mono', ui-monospace, Consolas, monospace; font-size: 12px; }

.step-warn {
  margin: 8px 0 0;
  font-size: 12px;
  line-height: 1.7;
  color: var(--bc-warning);
  background: var(--bc-warning-bg);
  border: 1px solid var(--bc-warning-border);
  border-radius: 8px;
  padding: 8px 10px;
}
.step-warn b { font-weight: 600; }
.bs-reason {
  font-size: 12px;
  color: var(--bc-danger);
  margin-top: 6px;
  padding: 8px 10px;
  background: var(--bc-bg-inset);
  border: 1px solid var(--bc-border-light);
  border-radius: 6px;
  word-break: break-all;
  white-space: pre-wrap;
}

.bind-state {
  display: flex; align-items: flex-start; gap: 10px;
  border-radius: 10px;
  padding: 12px 14px;
  margin-top: 4px;
}
.bind-state-ok { background: var(--bc-success-bg); border: 1px solid var(--bc-success-border); }
.bind-state-wait { background: var(--bc-primary-light); border: 1px solid var(--bc-primary-100); }
.bind-state-fail { background: var(--bc-danger-bg); border: 1px solid var(--bc-danger-border); }
.bs-icon { font-size: 20px; margin-top: 2px; }
.bind-state-ok .bs-icon { color: var(--bc-success); }
.bind-state-wait .bs-icon { color: var(--bc-primary); }
.bs-title { font-size: 13px; font-weight: 600; color: var(--bc-text-primary); }
.bind-state-wait .bs-title { color: var(--bc-primary); }
.bs-path { font-size: 13px; color: var(--bc-text-primary); margin-top: 4px; word-break: break-all; }
.bs-sub { font-size: 12px; color: var(--bc-text-secondary); margin-top: 4px; line-height: 1.6; }
</style>
