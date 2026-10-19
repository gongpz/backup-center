import http from '@/utils/http'

export const storagesApi = {
  list(params = {}) {
    return http.get('/storages', { params }).then((r) => r.data)
  },
  get(id) {
    return http.get(`/storages/${id}`, { params: { with_config: 1 } }).then((r) => r.data.data)
  },
  create(payload) {
    return http.post('/storages', payload).then((r) => r.data.data)
  },
  update(id, payload) {
    return http.put(`/storages/${id}`, payload).then((r) => r.data.data)
  },
  destroy(id) {
    return http.delete(`/storages/${id}`).then((r) => r.data)
  },
  test(id) {
    return http.post(`/storages/${id}/test`).then((r) => r.data)
  },
  // 只读浏览存储内的备份文件：path = 远端目录（可空），website_id = 增量网站（可空，直达该站快照根）
  browseFiles(id, params = {}) {
    return http.get(`/storages/${id}/files`, { params }).then((r) => r.data)
  },
}
