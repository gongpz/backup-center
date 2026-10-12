import http from '@/utils/http'

export const notificationApi = {
  get() {
    return http.get('/notification-settings').then((r) => r.data.data)
  },
  update(payload) {
    return http.put('/notification-settings', payload).then((r) => r.data.data)
  },
  test(sendkey) {
    return http.post('/notification-settings/test', sendkey ? { sendkey } : {}).then((r) => r.data)
  },
}

export const systemApi = {
  get() {
    return http.get('/system-settings').then((r) => r.data.data)
  },
  update(payload) {
    return http.put('/system-settings', payload).then((r) => r.data.data)
  },
}

export const profileApi = {
  updateProfile(payload) {
    return http.put('/auth/profile', payload).then((r) => r.data)
  },
  updatePassword(payload) {
    return http.put('/auth/password', payload).then((r) => r.data)
  },
  loginLogs() {
    return http.get('/auth/login-logs').then((r) => r.data.data)
  },
}
