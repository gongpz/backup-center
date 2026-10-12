import http from '@/utils/http'

export const backupLogsApi = {
  list(params = {}) {
    return http.get('/backup-logs', { params }).then((r) => r.data)
  },
  get(id) {
    return http.get(`/backup-logs/${id}`).then((r) => r.data.data)
  },
  destroy(id) {
    return http.delete(`/backup-logs/${id}`).then((r) => r.data)
  },
}
