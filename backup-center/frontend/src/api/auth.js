import http from '@/utils/http'

export const authApi = {
  login(payload) {
    return http.post('/auth/login', payload).then((r) => r.data)
  },
  me() {
    return http.get('/auth/me').then((r) => r.data)
  },
  logout() {
    return http.post('/auth/logout').then((r) => r.data)
  },
  bootstrapStatus() {
    return http.get('/auth/bootstrap-status').then((r) => r.data)
  },
  bootstrap(payload) {
    return http.post('/auth/bootstrap', payload).then((r) => r.data)
  },
}
