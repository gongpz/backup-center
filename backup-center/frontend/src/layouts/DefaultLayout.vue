<script setup>
import { computed, ref, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ElMessage, ElMessageBox } from 'element-plus'
import { useAuthStore } from '@/stores/auth'
import { useSystemStore } from '@/stores/system'
import { currentTheme, setTheme } from '@/utils/theme'
import Footer from '@/components/Footer.vue'
const footerRef = ref(null)

const auth = useAuthStore()
const system = useSystemStore()
const route = useRoute()
const router = useRouter()

onMounted(() => system.load())

const collapsed = ref(false)

/* 顶栏主题按钮：只在浅色/深色间切换（auto 模式可以在设置页选） */
const themeRef = ref(currentTheme())
const isDark = computed(() => {
  if (themeRef.value === 'auto') {
    return window.matchMedia?.('(prefers-color-scheme: dark)').matches ?? false
  }
  return themeRef.value === 'dark'
})
const toggleTheme = () => {
  const next = isDark.value ? 'light' : 'dark'
  themeRef.value = next
  setTheme(next)
}
const themeTooltip = computed(() => isDark.value ? '切换到浅色主题' : '切换到深色主题')

const gotoBackupLogs = () => router.push({ path: '/backup-logs', query: { status: 'failed' }})

const activeMenu = computed(() => route.path)

const menusAll = [
  { path: '/dashboard', title: '仪表盘', icon: 'Odometer' },
  { path: '/servers', title: '服务器', icon: 'Monitor' },
  { path: '/websites', title: '网站', icon: 'Cpu' },
  { path: '/backup-logs', title: '备份记录', icon: 'Files' },
  { path: '/restore', title: '数据恢复', icon: 'RefreshLeft' },
  { path: '/storages', title: '存储', icon: 'Cloudy' },
  { path: '/resource-library', title: '资源库', icon: 'FolderOpened' },
  { path: '/help', title: '帮助中心', icon: 'QuestionFilled' },
  { path: '/settings', title: '设置', icon: 'Setting' },
]

const pageTitle = computed(() => {
  const found = menusAll.find((m) => m.path === route.path)
  return found?.title || 'Backup Center'
})

const handleLogout = () => {
  ElMessageBox.confirm('确定退出登录吗？', '提示', {
    confirmButtonText: '退出',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      await auth.logout()
      ElMessage.success('已退出')
      router.replace({ name: 'login' })
    })
    .catch(() => {})
}
</script>

<template>
  <div class="layout-wrap">
  <el-container class="layout">
    <el-aside :width="collapsed ? '64px' : '224px'" class="aside">
      <div class="brand" :class="{ 'brand-collapsed': collapsed }">
        <div class="brand-icon">
          <el-icon><DataAnalysis /></el-icon>
        </div>
        <span v-show="!collapsed" class="brand-name">{{ system.systemName }}</span>
      </div>

      <el-menu
        :default-active="activeMenu"
        :router="true"
        :collapse="collapsed"
        :collapse-transition="false"
        class="side-menu"
      >
        <el-menu-item v-for="m in menusAll" :key="m.path" :index="m.path">
          <el-icon><component :is="m.icon" /></el-icon>
          <template #title>{{ m.title }}</template>
        </el-menu-item>
      </el-menu>

    </el-aside>

    <el-container>
      <el-header class="header" height="60px">
        <div class="header-left">
          <button class="collapse-btn" @click="collapsed = !collapsed" :aria-label="'折叠侧边栏'">
            <el-icon size="18">
              <component :is="collapsed ? 'Expand' : 'Fold'" />
            </el-icon>
          </button>
          <div class="page-title">
            <h1>{{ pageTitle }}</h1>
            <p>{{ system.systemName }} 统一备份管理中心</p>
          </div>
        </div>
        <div class="header-right">
          <el-tooltip :content="themeTooltip" placement="bottom">
            <button class="icon-btn" :aria-label="themeTooltip" @click="toggleTheme">
              <el-icon size="18">
                <component :is="isDark ? 'Sunny' : 'Moon'" />
              </el-icon>
            </button>
          </el-tooltip>
          <el-tooltip content="查看备份失败记录" placement="bottom">
            <button class="icon-btn" aria-label="备份记录" @click="gotoBackupLogs">
              <el-icon size="18"><Bell /></el-icon>
            </button>
          </el-tooltip>
          <el-dropdown trigger="click">
            <span class="user-info">
              <div class="avatar avatar-sm">
                {{ (auth.user?.username || 'A').charAt(0).toUpperCase() }}
              </div>
              <span class="user-name">{{ auth.user?.name || auth.user?.username }}</span>
              <el-icon size="14"><ArrowDown /></el-icon>
            </span>
            <template #dropdown>
              <el-dropdown-menu>
                <el-dropdown-item disabled>
                  <el-icon><User /></el-icon>
                  个人设置
                </el-dropdown-item>
                <el-dropdown-item divided @click="handleLogout">
                  <el-icon><SwitchButton /></el-icon>
                  退出登录
                </el-dropdown-item>
              </el-dropdown-menu>
            </template>
          </el-dropdown>
        </div>
      </el-header>

      <el-main class="main">
        <transition name="bc-page" mode="out-in" appear>
          <router-view />
        </transition>
      </el-main>
    </el-container>
  </el-container>
  <Footer ref="footerRef" />
  </div>
</template>

<style scoped>
.layout-wrap {
  display: flex;
  flex-direction: column;
  height: 100vh;
  background: var(--bc-bg-page);
}
.layout {
  flex: 1;
  min-height: 0;
  background: var(--bc-bg-page);
}

/* ---------- 侧栏 ---------- */
.aside {
  background: var(--bc-bg-sidebar);
  border-right: 1px solid var(--bc-border-light);
  display: flex;
  flex-direction: column;
  transition: width 0.2s ease;
  overflow: hidden;
}

.brand {
  display: flex;
  align-items: center;
  gap: 10px;
  height: 60px;
  padding: 0 18px;
  border-bottom: 1px solid var(--bc-border-light);
  color: var(--bc-text-primary);
  flex-shrink: 0;
}
.brand-collapsed {
  justify-content: center;
  padding: 0;
}
.brand-icon {
  width: 32px;
  height: 32px;
  border-radius: var(--bc-radius);
  background: var(--bc-primary);
  color: var(--bc-on-accent);
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}
.brand-name {
  font-size: 15px;
  font-weight: 600;
  letter-spacing: 0.2px;
  white-space: nowrap;
}

/* 侧栏菜单 */
.side-menu {
  flex: 1;
  border-right: none;
  padding: 12px 10px;
  background: transparent;
  overflow-y: auto;
}
:deep(.side-menu .el-menu-item) {
  position: relative;
  height: 40px;
  line-height: 40px;
  border-radius: var(--bc-radius);
  margin-bottom: 4px;
  color: var(--bc-sidebar-text);
  font-size: 14px;
  padding-left: 12px !important;
}
:deep(.side-menu .el-menu-item .el-icon) {
  color: var(--bc-sidebar-text);
  font-size: 18px;
}
:deep(.side-menu .el-menu-item:hover) {
  background: var(--bc-bg-hover);
  color: var(--bc-sidebar-text-hover);
}
:deep(.side-menu .el-menu-item:hover .el-icon) {
  color: var(--bc-sidebar-text-hover);
}
:deep(.side-menu .el-menu-item.is-active) {
  background: var(--bc-sidebar-active-bg);
  color: var(--bc-sidebar-active-text);
  font-weight: 500;
}
/* 当前项左侧滑动指示条 */
:deep(.side-menu .el-menu-item.is-active)::before {
  content: '';
  position: absolute;
  left: 0;
  top: 22%;
  bottom: 22%;
  width: 3px;
  border-radius: 0 3px 3px 0;
  background: var(--bc-primary);
  transform-origin: center;
  animation: bc-indicator .35s var(--bc-ease-out);
}
:deep(.side-menu .el-menu-item.is-active .el-icon) {
  color: var(--bc-sidebar-active-text);
}
:deep(.el-menu--collapse .el-menu-item) {
  padding-left: 20px !important;
}

/* 侧栏底部用户卡已删除（R84） */

.avatar {
  width: 32px;
  height: 32px;
  border-radius: 50%;
  background: linear-gradient(135deg, var(--bc-primary-400) 0%, var(--bc-primary) 100%);
  color: var(--bc-on-accent);
  font-weight: 600;
  font-size: 13px;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}
.avatar-sm {
  width: 28px;
  height: 28px;
  font-size: 12px;
}

/* ---------- 顶栏 ---------- */
.header {
  background: var(--bc-bg-header);
  border-bottom: 1px solid var(--bc-border-light);
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0 24px;
}
.header-left {
  display: flex;
  align-items: center;
  gap: 16px;
}
.collapse-btn,
.icon-btn {
  width: 34px;
  height: 34px;
  border-radius: var(--bc-radius);
  border: none;
  background: transparent;
  color: var(--bc-text-regular);
  display: inline-flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  transition: background 0.15s;
  position: relative;
}
.collapse-btn:hover,
.icon-btn:hover {
  background: var(--bc-bg-hover);
  color: var(--bc-text-primary);
}
.icon-btn .dot {
  position: absolute;
  top: 8px;
  right: 8px;
  width: 6px;
  height: 6px;
  border-radius: 50%;
  background: var(--bc-danger);
}
.page-title h1 {
  margin: 0;
  font-size: 15px;
  font-weight: 600;
  color: var(--bc-text-primary);
  line-height: 1.2;
}
.page-title p {
  margin: 2px 0 0;
  font-size: 12px;
  color: var(--bc-text-secondary);
}

.header-right {
  display: flex;
  align-items: center;
  gap: 8px;
}
.user-info {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  padding: 4px 10px 4px 4px;
  border-radius: var(--bc-radius);
  cursor: pointer;
  transition: background 0.15s;
}
.user-info:hover {
  background: var(--bc-bg-hover);
}
.user-name {
  font-size: 13px;
  color: var(--bc-text-primary);
  font-weight: 500;
}

/* ---------- 主区 ---------- */
.main {
  background: var(--bc-bg-page);
  padding: 24px;
}
</style>
