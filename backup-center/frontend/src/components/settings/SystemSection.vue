<script setup>
import { ref, reactive, onMounted } from 'vue'
import { ElMessage } from 'element-plus'
import { Setting, Sunny, Moon, Monitor, SuccessFilled } from '@element-plus/icons-vue'
import { systemApi } from '@/api/settings'
import { useSystemStore } from '@/stores/system'
import { currentTheme, setTheme } from '@/utils/theme'

const system = useSystemStore()
const form = reactive({ system_name: 'backup center' })
const loading = ref(false)
const saving = ref(false)

const load = async () => {
  loading.value = true
  try {
    const d = await systemApi.get()
    form.system_name = d.system_name || 'backup center'
  } finally { loading.value = false }
}
const save = async () => {
  if (!form.system_name?.trim()) return ElMessage.warning('系统名称不能为空')
  saving.value = true
  try {
    const d = await systemApi.update({ system_name: form.system_name.trim() })
    system.setName(d.system_name)
    ElMessage.success('系统名称已更新')
  } finally { saving.value = false }
}

const theme = ref('light')
const themeOptions = [
  { value: 'light', label: '浅色', icon: Sunny, desc: '白底 + 深字，白天/办公室' },
  { value: 'dark',  label: '深色', icon: Moon,  desc: '深底 + 亮字，夜间/护眼' },
  { value: 'auto',  label: '跟随系统', icon: Monitor, desc: '按操作系统主题自动切换' },
]
const pickTheme = (v) => { theme.value = v; setTheme(v) }

onMounted(() => { load(); theme.value = currentTheme() })
</script>

<template>
  <div class="stack">
    <div class="section-grid">
      <div class="s-card">
        <div class="s-card-head">
          <el-icon><Setting /></el-icon>
          <span>系统信息</span>
        </div>
        <p class="s-hint">系统名称显示在侧栏 Logo、浏览器标题栏。</p>
        <el-form v-loading="loading" label-width="100px" label-position="right">
          <el-form-item label="系统名称">
            <el-input v-model="form.system_name" placeholder="例如：我的备份中心"
                      maxlength="64" show-word-limit />
          </el-form-item>
          <el-form-item>
            <el-button type="primary" :loading="saving" @click="save">保存</el-button>
          </el-form-item>
        </el-form>
      </div>

      <div class="s-card s-info">
        <div class="s-card-head">
          <el-icon><Setting /></el-icon>
          <span>关于本站</span>
        </div>
        <div class="kv-row"><span class="k">系统版本</span><span class="v">Backup Center · MVP</span></div>
        <div class="kv-row"><span class="k">Agent 版本</span><span class="v">v0.13.0</span></div>
        <div class="kv-row"><span class="k">时区</span><span class="v">Asia/Shanghai（北京时间）</span></div>
        <div class="kv-row"><span class="k">部署</span><span class="v">Docker Compose · Laravel 12 + Vue 3</span></div>
      </div>
    </div>

    <div class="s-card">
      <div class="s-card-head">
        <el-icon><Sunny /></el-icon>
        <span>主题外观</span>
      </div>
      <p class="s-hint">切换后立即生效，浏览器会记住选择。也可用顶部导航栏的按钮快速切换。</p>
      <div class="theme-grid">
        <div v-for="o in themeOptions" :key="o.value"
          :class="['theme-card', { active: theme === o.value }]" @click="pickTheme(o.value)">
          <div class="theme-preview" :data-mode="o.value">
            <div class="preview-sidebar"></div>
            <div class="preview-main">
              <div class="preview-bar"></div>
              <div class="preview-card"></div>
              <div class="preview-card"></div>
            </div>
          </div>
          <div class="theme-meta">
            <div class="theme-name">
              <el-icon size="14"><component :is="o.icon" /></el-icon>
              {{ o.label }}
            </div>
            <div class="theme-desc">{{ o.desc }}</div>
          </div>
          <el-icon v-if="theme === o.value" class="theme-check" size="18"><SuccessFilled /></el-icon>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.stack { display: flex; flex-direction: column; gap: 20px; }
.section-grid { display: grid; grid-template-columns: minmax(0, 1.4fr) minmax(0, 1fr); gap: 20px; }
@media (max-width: 1080px) { .section-grid { grid-template-columns: 1fr; } }
.s-card { background: var(--bc-bg-card); border: 1px solid var(--bc-border-light); border-radius: 12px; padding: 20px 24px; }
.s-card-head { display: flex; align-items: center; gap: 8px; font-size: 15px; font-weight: 600; color: var(--bc-text-primary); margin-bottom: 8px; }
.s-card-head .el-icon { color: var(--bc-primary); }
.s-hint { color: var(--bc-text-secondary); font-size: 13px; margin: 0 0 16px; line-height: 1.6; }
.s-info { background: linear-gradient(135deg, var(--bc-primary-light) 0%, var(--bc-primary-100) 100%); border-color: var(--bc-primary-100); }
.kv-row { display: flex; justify-content: space-between; align-items: center; padding: 8px 0; border-bottom: 1px dashed var(--bc-border-light); font-size: 13px; }
.kv-row:last-child { border-bottom: none; }
.k { color: var(--bc-text-secondary); }
.v { color: var(--bc-text-primary); font-weight: 500; }

.theme-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; }
@media (max-width: 900px) { .theme-grid { grid-template-columns: 1fr; } }
.theme-card { position: relative; border: 2px solid var(--bc-border-light); border-radius: 10px; padding: 14px; cursor: pointer; transition: all 0.15s; background: var(--bc-bg-card); }
.theme-card:hover { border-color: var(--bc-primary); }
.theme-card.active { border-color: var(--bc-primary); box-shadow: var(--bc-ring); }
.theme-preview { display: flex; height: 80px; border-radius: 5px; overflow: hidden; border: 1px solid var(--bc-border-light); margin-bottom: 12px; }
.preview-sidebar { width: 30%; background: #ffffff; border-right: 1px solid var(--bc-border-light); }
.preview-main { flex: 1; padding: 6px; display: flex; flex-direction: column; gap: 4px; background: var(--bc-bg-inset); }
.preview-bar { height: 8px; background: #ffffff; border-radius: 2px; }
.preview-card { flex: 1; background: #ffffff; border-radius: 2px; border: 1px solid #eef0f3; }
.theme-preview[data-mode="dark"] .preview-sidebar { background: var(--bc-text-primary); border-right-color: var(--bc-text-regular); }
.theme-preview[data-mode="dark"] .preview-main { background: var(--bc-text-primary); }
.theme-preview[data-mode="dark"] .preview-bar,
.theme-preview[data-mode="dark"] .preview-card { background: var(--bc-text-primary); border-color: var(--bc-text-regular); }
.theme-preview[data-mode="auto"] .preview-sidebar { background: linear-gradient(135deg, #ffffff 50%, var(--bc-text-primary) 50%); }
.theme-preview[data-mode="auto"] .preview-main { background: linear-gradient(135deg, var(--bc-bg-inset) 50%, var(--bc-text-primary) 50%); }
.theme-preview[data-mode="auto"] .preview-bar,
.theme-preview[data-mode="auto"] .preview-card { background: linear-gradient(135deg, #ffffff 50%, var(--bc-text-primary) 50%); }
.theme-name { display: inline-flex; align-items: center; gap: 6px; font-size: 14px; font-weight: 500; color: var(--bc-text-primary); }
.theme-desc { font-size: 12px; color: var(--bc-text-secondary); margin-top: 4px; line-height: 1.5; }
.theme-check { position: absolute; top: 12px; right: 12px; color: var(--bc-primary); }
</style>
