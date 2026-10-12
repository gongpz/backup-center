<script setup>
import { ref, reactive, onMounted } from 'vue'
import { ElMessage } from 'element-plus'
import { Plus, Delete, ArrowUp, ArrowDown } from '@element-plus/icons-vue'
import { systemApi } from '@/api/settings'
import SocialIcon from '@/components/SocialIcon.vue'

const CONTACT_TYPES = [
  { value: 'github', label: 'GitHub' },
  { value: 'wechat', label: '微信' },
  { value: 'qq', label: 'QQ' },
  { value: 'telegram', label: 'Telegram' },
  { value: 'x', label: 'X (Twitter)' },
  { value: 'instagram', label: 'Instagram' },
  { value: 'facebook', label: 'Facebook' },
  { value: 'email', label: '邮箱' },
  { value: 'phone', label: '电话' },
  { value: 'website', label: '个人主页' },
  { value: 'custom', label: '自定义' },
]

const cfg = reactive({
  enabled: true, copyright: '© Backup Center', disclaimer: '',
  custom_html: '', show_versions: true, show_stats: true, icp_beian: '',
  contacts: [],
})
const loading = ref(false)
const saving = ref(false)

const load = async () => {
  loading.value = true
  try {
    const d = await systemApi.get()
    if (d.footer_config) Object.assign(cfg, d.footer_config)
    if (!Array.isArray(cfg.contacts)) cfg.contacts = []
  } finally { loading.value = false }
}
const save = async () => {
  saving.value = true
  try {
    const clean = { ...cfg, contacts: cfg.contacts.filter(c => c.type && c.value) }
    await systemApi.update({ footer_config: clean })
    ElMessage.success('已保存，刷新页面查看效果')
  } finally { saving.value = false }
}
const addContact = () => cfg.contacts.push({ type: 'github', value: '', label: '' })
const removeContact = (i) => cfg.contacts.splice(i, 1)
const moveContact = (i, dir) => {
  const j = i + dir
  if (j < 0 || j >= cfg.contacts.length) return
  const t = cfg.contacts[i]; cfg.contacts[i] = cfg.contacts[j]; cfg.contacts[j] = t
}
onMounted(load)
</script>

<template>
  <div class="stack" v-loading="loading">
    <div class="s-card">
      <div class="s-card-head">
        <span>页脚开关 · 基本信息</span>
      </div>
      <el-form label-width="100px" label-position="right">
        <el-form-item label="启用页脚">
          <el-switch v-model="cfg.enabled" />
        </el-form-item>
        <el-form-item label="版权文字">
          <el-input v-model="cfg.copyright" placeholder="© 2026 Your Site" maxlength="200" />
        </el-form-item>
        <el-form-item label="ICP 备案">
          <el-input v-model="cfg.icp_beian" placeholder="如：京 ICP 备 xxx 号" maxlength="100" />
        </el-form-item>
        <el-form-item label="免责声明">
          <el-input v-model="cfg.disclaimer" type="textarea" :rows="4" maxlength="5000" show-word-limit
                    placeholder="留空则页脚不显示'免责声明'链接。多行文本，弹窗展示。" />
        </el-form-item>
        <el-form-item label="自定义 HTML">
          <el-input v-model="cfg.custom_html" type="textarea" :rows="3" maxlength="2000" show-word-limit
                    placeholder="展示在页脚底部（支持 HTML；<a>、<span>、<br/> 等）" />
          <div class="s-hint">谨慎：会原样渲染到页脚，别放不可信内容</div>
        </el-form-item>
        <el-form-item label="显示版本">
          <el-switch v-model="cfg.show_versions" />
          <span class="s-hint" style="margin-left: 10px">页脚中间显示"系统 vX · Agent vY"</span>
        </el-form-item>
        <el-form-item label="显示统计">
          <el-switch v-model="cfg.show_stats" />
          <span class="s-hint" style="margin-left: 10px">页脚中间显示"服务器数 · 网站数 · 备份数 · 数据总量"</span>
        </el-form-item>
      </el-form>
    </div>

    <div class="s-card">
      <div class="s-card-head">
        <span>联系方式</span>
        <el-button size="small" :icon="Plus" type="primary" plain @click="addContact" style="margin-left: auto">添加</el-button>
      </div>
      <p class="s-hint">图标显示在页脚右侧，点击弹出内容。可点击 URL 直接跳转（http/https/邮箱/电话自动识别）。</p>

      <div v-if="!cfg.contacts.length" class="empty-tip">还没有联系方式，点右上"添加"新增一条。</div>
      <div v-for="(c, i) in cfg.contacts" :key="i" class="contact-row">
        <div class="contact-preview">
          <SocialIcon :type="CONTACT_TYPES.some(t=>t.value===c.type)?c.type:'custom'" />
        </div>
        <el-select v-model="c.type" style="width: 140px" size="small">
          <el-option v-for="t in CONTACT_TYPES" :key="t.value" :value="t.value" :label="t.label" />
        </el-select>
        <el-input v-model="c.value" size="small" style="flex: 2"
                  placeholder="URL / 邮箱 / 电话 / 微信号等" />
        <el-input v-model="c.label" size="small" style="flex: 1"
                  placeholder="悬浮标题（可选）" />
        <el-button size="small" :icon="ArrowUp" text @click="moveContact(i, -1)" :disabled="i === 0" />
        <el-button size="small" :icon="ArrowDown" text @click="moveContact(i, 1)" :disabled="i === cfg.contacts.length - 1" />
        <el-button size="small" :icon="Delete" type="danger" text @click="removeContact(i)" />
      </div>
    </div>

    <div class="s-card save-bar">
      <el-button type="primary" :loading="saving" size="large" @click="save">保存页脚设置</el-button>
      <span class="s-hint" style="margin-left: 12px">保存后刷新任意页面即可看到新的页脚</span>
    </div>
  </div>
</template>

<style scoped>
.stack { display: flex; flex-direction: column; gap: 20px; }
.s-card { background: var(--bc-bg-card); border: 1px solid var(--bc-border-light); border-radius: 12px; padding: 20px 24px; }
.s-card-head { display: flex; align-items: center; gap: 8px; font-size: 15px; font-weight: 600; color: var(--bc-text-primary); margin-bottom: 12px; }
.s-hint { color: var(--bc-text-secondary); font-size: 12px; line-height: 1.6; }
.empty-tip { padding: 24px; text-align: center; color: var(--bc-text-placeholder); font-size: 13px; }
.contact-row { display: flex; align-items: center; gap: 8px; padding: 8px 0; border-bottom: 1px dashed var(--bc-border-light); }
.contact-row:last-child { border-bottom: none; }
.contact-preview {
  width: 32px; height: 32px; display: inline-flex; align-items: center; justify-content: center;
  background: var(--bc-bg-page); border: 1px solid var(--bc-border-light); border-radius: 6px;
  color: var(--bc-text-secondary); flex-shrink: 0;
}
.save-bar { display: flex; align-items: center; }
</style>
