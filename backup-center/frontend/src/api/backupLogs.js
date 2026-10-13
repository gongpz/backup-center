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
  restore(id, uploadId) {
    return http.post(`/backup-logs/${id}/restore`, { upload_id: uploadId }).then((r) => r.data)
  },
}
