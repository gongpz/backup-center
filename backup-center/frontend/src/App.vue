<script setup>
const isDemo = import.meta.env.VITE_DEMO === '1'
</script>

<template>
  <router-view />
  <div v-if="isDemo" class="demo-badge">演示环境 · 数据为示例</div>
</template>

<style scoped>
.demo-badge {
  position: fixed;
  right: 18px;
  bottom: 14px;
  z-index: 9999;
  pointer-events: none;
  user-select: none;
  padding: 4px 12px;
  border: 1px solid var(--bc-border-light);
  border-radius: var(--bc-radius-full);
  background: var(--bc-glass-bg);
  backdrop-filter: var(--bc-glass-blur);
  box-shadow: var(--bc-shadow-xs);
  font-size: var(--bc-fs-2xs);
  line-height: var(--bc-lh-snug);
  letter-spacing: var(--bc-tracking-wide);
  color: var(--bc-text-secondary);
  opacity: .68;
}
</style>
