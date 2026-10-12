import { defineStore } from 'pinia'
import { authApi } from '@/api/auth'

const TOKEN_KEY = 'bc_token'

export const useAuthStore = defineStore('auth', {
  state: () => ({
    token: localStorage.getItem(TOKEN_KEY) || '',
    user: null,
  }),
  getters: {
    isAuthenticated: (state) => !!state.token,
  },
  actions: {
    async login(payload) {
      const data = await authApi.login(payload)
      this.token = data.token
      this.user = data.user
      localStorage.setItem(TOKEN_KEY, data.token)
      return data
    },
    async bootstrap(payload) {
      const data = await authApi.bootstrap(payload)
      this.token = data.token
      this.user = data.user
      localStorage.setItem(TOKEN_KEY, data.token)
      return data
    },
    async fetchMe() {
      const user = await authApi.me()
      this.user = user
      return user
    },
    async logout() {
      try {
        await authApi.logout()
      } catch (_) {
        // 后端已失效也无所谓，本地清一下
      }
      this.clear()
    },
    clear() {
      this.token = ''
      this.user = null
      localStorage.removeItem(TOKEN_KEY)
    },
  },
})
