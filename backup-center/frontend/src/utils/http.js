import axios from 'axios'
import { ElMessage } from 'element-plus'
import router from '@/router'
import { useAuthStore } from '@/stores/auth'

const http = axios.create({
  baseURL: '/api',
  timeout: 15000,
  headers: {
    Accept: 'application/json',
  },
})

http.interceptors.request.use((config) => {
  const auth = useAuthStore()
  if (auth.token) {
    config.headers.Authorization = `Bearer ${auth.token}`
  }
  return config
})

http.interceptors.response.use(
  (response) => response,
  (error) => {
    const status = error.response?.status
    const data = error.response?.data

    if (status === 401) {
      const auth = useAuthStore()
      auth.clear()
      if (router.currentRoute.value.name !== 'login') {
        router.push({ name: 'login' })
      }
    } else if (status === 422 && data?.errors) {
      const first = Object.values(data.errors)[0]
      ElMessage.error(Array.isArray(first) ? first[0] : String(first))
    } else if (data?.message) {
      ElMessage.error(data.message)
    } else {
      ElMessage.error('网络异常，请稍后再试')
    }

    return Promise.reject(error)
  },
)

export default http
