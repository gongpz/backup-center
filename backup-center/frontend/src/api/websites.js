import http from '@/utils/http'

export const websitesApi = {
  list(params = {}) {
    return http.get('/websites', { params }).then((r) => r.data)
  },
  get(id) {
    return http.get(`/websites/${id}`).then((r) => r.data.data)
  },
  create(payload) {
    return http.post('/websites', payload).then((r) => r.data.data)
  },
  update(id, payload) {
    return http.put(`/websites/${id}`, payload).then((r) => r.data.data)
  },
  destroy(id) {
    return http.delete(`/websites/${id}`).then((r) => r.data)
  },
  syncNow(id) {
    return http.post(`/websites/${id}/sync-now`).then((r) => r.data)
  },
  bindRoot(id, payload) {
    return http.post(`/websites/${id}/bind-root`, payload).then((r) => r.data)
  },
}

export const systemApi = {
  backupNow() {
    return http.post('/system/backup', {}, { timeout: 180000 }).then((r) => r.data)
  },
}
