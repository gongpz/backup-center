<script setup>
import { ref } from 'vue'
import SystemSection from '@/components/settings/SystemSection.vue'
import SystemBackupSection from '@/components/settings/SystemBackupSection.vue'
import ResourcePoolSection from '@/components/settings/ResourcePoolSection.vue'
import AccountSection from '@/components/settings/AccountSection.vue'
import NotificationSection from '@/components/settings/NotificationSection.vue'
import FooterSection from '@/components/settings/FooterSection.vue'
import {
  Setting, FolderOpened, Cloudy, User, Bell, Menu,
} from '@element-plus/icons-vue'

const activeTab = ref('system')
</script>

<template>
  <div>
    <div class="toolbar">
      <div>
        <h2 class="page-h">设置</h2>
        <p class="page-sub">系统 · 备份 · 资源池 · 账户 · 通知 · 页脚</p>
      </div>
    </div>

    <div class="bc-card settings-shell">
      <el-tabs v-model="activeTab" class="settings-tabs">
        <el-tab-pane name="system">
          <template #label>
            <span class="tab-lbl"><el-icon><Setting /></el-icon>系统</span>
          </template>
          <SystemSection />
        </el-tab-pane>
        <el-tab-pane name="sysbackup">
          <template #label>
            <span class="tab-lbl"><el-icon><FolderOpened /></el-icon>系统备份</span>
          </template>
          <SystemBackupSection />
        </el-tab-pane>
        <el-tab-pane name="pools">
          <template #label>
            <span class="tab-lbl"><el-icon><Cloudy /></el-icon>资源池</span>
          </template>
          <ResourcePoolSection />
        </el-tab-pane>
        <el-tab-pane name="account">
          <template #label>
            <span class="tab-lbl"><el-icon><User /></el-icon>账户</span>
          </template>
          <AccountSection />
        </el-tab-pane>
        <el-tab-pane name="notification">
          <template #label>
            <span class="tab-lbl"><el-icon><Bell /></el-icon>通知</span>
          </template>
          <NotificationSection />
        </el-tab-pane>
        <el-tab-pane name="footer">
          <template #label>
            <span class="tab-lbl"><el-icon><Menu /></el-icon>页脚</span>
          </template>
          <FooterSection />
        </el-tab-pane>
      </el-tabs>
    </div>
  </div>
</template>

<style scoped>
.toolbar {
  display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px;
}
.page-h { margin: 0; font-size: 20px; font-weight: 600; color: var(--bc-text-primary); }
.page-sub { margin: 4px 0 0; font-size: 12px; color: var(--bc-text-secondary); }

.settings-shell { padding: 0; overflow: hidden; }
.tab-lbl { display: inline-flex; align-items: center; gap: 8px; }
.tab-lbl .el-icon { font-size: 15px; }

:deep(.el-tabs__header) {
  margin: 0;
  padding: 0 20px;
  border-bottom: 1px solid var(--bc-border-light);
  background: linear-gradient(180deg, rgba(15, 110, 140, .04) 0%, transparent 100%);
}
:deep(.el-tabs__nav-wrap::after) { display: none; }
:deep(.el-tabs__item) {
  height: 52px;
  line-height: 52px;
  font-size: 14px;
  color: var(--bc-text-regular);
  padding: 0 22px;
}
:deep(.el-tabs__item:hover) {
  color: var(--bc-text-primary);
}
:deep(.el-tabs__item.is-active) {
  color: var(--bc-primary);
  font-weight: 600;
}
:deep(.el-tabs__active-bar) {
  background: var(--bc-primary);
  height: 3px;
}
:deep(.el-tabs__content) { padding: 24px 28px; background: var(--bc-bg-page); min-height: 600px; }
</style>
