<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import { ElMessageBox } from 'element-plus'
import SocialIcon from './SocialIcon.vue'
import { systemApi } from '@/api/settings'
import http from '@/utils/http'

const config = ref({
  enabled: true, copyright: '', disclaimer: '', custom_html: '',
  show_versions: true, show_stats: true, icp_beian: '',
  contacts: [],
})
const stats = ref(null)
const versions = ref({ system: '', agent: '' })

const load = async () => {
  try {
    const d = await systemApi.get()
    if (d.footer_config) config.value = { ...config.value, ...d.footer_config }
    // Agent 版本（展示用静态值）
    versions.value.agent = 'v0.14.0'
  } catch (_) {}
  // 系统版本：读取真实部署版本（构建时写入 backend/VERSION，经 /api/version 暴露）。
  // 失败则保持为空，页脚版本块会自动隐藏，不会显示过期的硬编码值（如旧版写死的 v1.1.0）。
  try {
    const v = await http.get('/version').then(x => x.data)
    if (v?.version) versions.value.system = v.version
  } catch (_) {}
  if (config.value.show_stats) {
    try {
      const r = await http.get('/system/footer-stats').then(x => x.data)
      if (r?.ok) stats.value = r.stats
    } catch (_) {}
  }
}

const fmtBytes = (n) => {
  if (!n) return '0 B'
  const u = ['B', 'KB', 'MB', 'GB', 'TB']; let v = Number(n), i = 0
  while (v >= 1024 && i < u.length - 1) { v /= 1024; i++ }
  return v.toFixed(1) + ' ' + u[i]
}

const showDisclaimer = () => {
  if (!config.value.disclaimer) return
  ElMessageBox.alert(config.value.disclaimer, '免责声明', {
    confirmButtonText: '知道了',
    customStyle: { 'white-space': 'pre-wrap', 'text-align': 'left' },
  })
}

const knownTypes = ['github','telegram','x','instagram','facebook','wechat','qq','email','phone','website','custom']
const iconType = (c) => knownTypes.includes(c.type) ? c.type : 'custom'
const isUrlLike = (v) => /^https?:\/\//i.test(v || '')
const contactHref = (c) => {
  if (isUrlLike(c.value)) return c.value
  if (c.type === 'email' && c.value) return `mailto:${c.value}`
  if (c.type === 'phone' && c.value) return `tel:${c.value.replace(/\s/g, '')}`
  return null
}
const displayValue = (c) => c.value

onMounted(load)
// 允许外部触发刷新（比如设置改完后）
defineExpose({ refresh: load })
</script>

<template>
  <footer v-if="config.enabled" class="bc-footer">
    <div class="footer-inner">
      <div class="footer-left">
        <span v-if="config.copyright" class="copyright">{{ config.copyright }}</span>
        <a class="author" href="https://github.com/gongpz/" target="_blank" rel="noopener" title="GitHub @gongpz">By：Mask</a>
        <span v-if="config.icp_beian" class="icp">{{ config.icp_beian }}</span>
        <a v-if="config.disclaimer" @click.prevent="showDisclaimer" class="link">免责声明</a>
      </div>

      <div class="footer-center">
        <span v-if="config.show_versions && (versions.system || versions.agent)" class="chip-info">
          <template v-if="versions.system">系统 {{ versions.system }}</template><template v-if="versions.system && versions.agent"> · </template><template v-if="versions.agent">Agent {{ versions.agent }}</template>
        </span>
        <span v-if="config.show_stats && stats" class="chip-info">
          {{ stats.servers }} 服务器（{{ stats.online_servers }} 在线）·
          {{ stats.websites }} 网站 ·
          {{ stats.backup_logs.toLocaleString() }} 备份 ·
          {{ fmtBytes(stats.total_size) }}
        </span>
      </div>

      <div class="footer-right">
        <template v-for="(c, i) in config.contacts" :key="i">
          <el-popover trigger="click" placement="top" :width="240">
            <template #reference>
              <a v-if="contactHref(c)" :href="contactHref(c)" target="_blank" rel="noopener" class="contact-btn" :title="c.label || c.type" @click.stop>
                <SocialIcon :type="iconType(c)" />
              </a>
              <button v-else class="contact-btn" :title="c.label || c.type">
                <SocialIcon :type="iconType(c)" />
              </button>
            </template>
            <div class="contact-pop">
              <div class="contact-label">{{ c.label || c.type }}</div>
              <div class="contact-value">{{ displayValue(c) }}</div>
            </div>
          </el-popover>
        </template>
      </div>
    </div>
    <div v-if="config.custom_html" class="footer-custom" v-html="config.custom_html"></div>
  </footer>
</template>

<style scoped>
.bc-footer {
  width: 100%;
  border-top: 1px solid var(--bc-border-light);
  background: var(--bc-bg-card);
  color: var(--bc-text-secondary);
  font-size: 12px;
  padding: 12px 72px;
  margin-top: 0;
  flex-shrink: 0;
}
@media (max-width: 900px) {
  .bc-footer { padding: 12px 24px; }
}
.footer-inner {
  display: flex; align-items: center; justify-content: space-between; gap: 24px; flex-wrap: wrap;
}
.footer-left, .footer-center, .footer-right {
  display: inline-flex; align-items: center; gap: 12px; flex-wrap: wrap;
}
.footer-center { flex: 1; justify-content: center; }
.copyright { font-weight: 500; color: var(--bc-text-regular); }
.icp { color: var(--bc-text-placeholder); }
.link { color: var(--bc-primary); cursor: pointer; text-decoration: none; }
.link:hover { text-decoration: underline; }
.author { color: var(--bc-text-placeholder); text-decoration: none; transition: color .15s; }
.author:hover { color: var(--bc-primary); }
.chip-info {
  padding: 3px 10px; border-radius: 999px;
  background: var(--bc-primary-light); color: var(--bc-text-regular);
  font-size: 11px; letter-spacing: 0.2px;
}
.contact-btn {
  display: inline-flex; align-items: center; justify-content: center;
  width: 28px; height: 28px; border-radius: 6px;
  background: var(--bc-bg-page);
  color: var(--bc-text-secondary);
  cursor: pointer; transition: all .15s;
  border: 1px solid var(--bc-border-light);
  text-decoration: none;
}
.contact-btn:hover { background: var(--bc-primary-light); color: var(--bc-primary); border-color: var(--bc-primary); }
.contact-pop { font-size: 13px; }
.contact-label { color: var(--bc-text-secondary); font-size: 11px; margin-bottom: 4px; }
.contact-value {
  color: var(--bc-text-primary); font-family: 'JetBrains Mono', ui-monospace, Consolas, monospace;
  font-size: 12px; word-break: break-all;
}
.footer-custom {
  margin-top: 8px; padding-top: 8px; border-top: 1px dashed var(--bc-border-light);
  font-size: 11px; color: var(--bc-text-placeholder);
}
</style>
