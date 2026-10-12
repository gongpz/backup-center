import http from '@/utils/http'

export const dashboardApi = {
  get(range = 'month') {
    return http.get('/dashboard', { params: { range } }).then((r) => r.data)
  },
}
