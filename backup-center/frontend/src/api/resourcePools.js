import http from '@/utils/http'

export const resourcePoolApi = {
  list() {
    return http.get('/resource-pools').then((r) => r.data.data)
  },
  create(payload) {
    return http.post('/resource-pools', payload).then((r) => r.data.data)
  },
  update(id, payload) {
    return http.put(`/resource-pools/${id}`, payload).then((r) => r.data.data)
  },
  destroy(id) {
    return http.delete(`/resource-pools/${id}`).then((r) => r.data)
  },
  test(id) {
    return http.post(`/resource-pools/${id}/test`).then((r) => r.data)
  },
  listFiles(id) {
    return http.get(`/resource-pools/${id}/files`).then((r) => r.data)
  },
  uploadFile(id, file, name, onProgress) {
    const form = new FormData()
    form.append('file', file)
    if (name) form.append('name', name)
    return http.post(`/resource-pools/${id}/files`, form, {
      headers: { 'Content-Type': 'multipart/form-data' },
      onUploadProgress: onProgress,
    }).then((r) => r.data)
  },
  deleteFile(id, path) {
    return http.delete(`/resource-pools/${id}/files`, { data: { path } }).then((r) => r.data)
  },
  updateFileRemark(id, path, remark) {
    return http.post(`/resource-pools/${id}/files/remark`, { path, remark }).then((r) => r.data)
  },
}
