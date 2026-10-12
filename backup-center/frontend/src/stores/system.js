import { defineStore } from 'pinia'
import { systemApi } from '@/api/settings'

export const useSystemStore = defineStore('system', {
  state: () => ({
    systemName: 'Backup Center',
    loaded: false,
  }),
  actions: {
    async load(force = false) {
      if (this.loaded && !force) return
      try {
        const d = await systemApi.get()
        this.systemName = d.system_name || 'Backup Center'
        document.title = `${this.systemName} - 备份管理中心`
        this.loaded = true
      } catch (_) {
        // 未登录时会 401，忽略
      }
    },
    setName(name) {
      this.systemName = name || 'Backup Center'
      document.title = `${this.systemName} - 备份管理中心`
    },
  },
})
