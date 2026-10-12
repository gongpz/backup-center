import http from '@/utils/http'

export const serversApi = {
  list(params = {}) {
    return http.get('/servers', { params }).then((r) => r.data)
  },
  get(id) {
    return http.get(`/servers/${id}`).then((r) => r.data.data)
  },
  create(payload) {
    return http.post('/servers', payload).then((r) => r.data.data)
  },
  update(id, payload) {
    return http.put(`/servers/${id}`, payload).then((r) => r.data.data)
  },
  destroy(id) {
    return http.delete(`/servers/${id}`).then((r) => r.data)
  },
  regenerateToken(id) {
    return http.post(`/servers/${id}/regenerate-token`).then((r) => r.data)
  },
  agentLatestVersion() {
    return http.get('/agent-latest-version').then((r) => r.data)
  },
  dispatchAgentUpdate(id) {
    return http.post(`/servers/${id}/agent-update`).then((r) => r.data)
  },
  bulkAgentUpdate(serverIds) {
    return http.post('/servers/bulk-agent-update', { server_ids: serverIds }).then((r) => r.data)
  },
}
