import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const routes = [
  {
    path: '/login',
    name: 'login',
    component: () => import('@/views/Login.vue'),
    meta: { public: true },
  },
  {
    path: '/',
    component: () => import('@/layouts/DefaultLayout.vue'),
    redirect: { name: 'dashboard' },
    children: [
      {
        path: 'dashboard',
        name: 'dashboard',
        component: () => import('@/views/Dashboard.vue'),
      },
      {
        path: 'servers',
        name: 'servers',
        component: () => import('@/views/Servers.vue'),
      },
      {
        path: 'websites',
        name: 'websites',
        component: () => import('@/views/Websites.vue'),
      },
      {
        path: 'backup-logs',
        name: 'backup-logs',
        component: () => import('@/views/BackupLogs.vue'),
      },
      {
        path: 'storages',
        name: 'storages',
        component: () => import('@/views/Storages.vue'),
      },
      {
        path: 'resource-library',
        name: 'resource-library',
        component: () => import('@/views/ResourceLibrary.vue'),
      },
      {
        path: 'help',
        name: 'help',
        component: () => import('@/views/Help.vue'),
      },
      {
        path: 'settings',
        name: 'settings',
        component: () => import('@/views/Settings.vue'),
      },
    ],
  },
  {
    path: '/:pathMatch(.*)*',
    redirect: { name: 'dashboard' },
  },
]

const router = createRouter({
  history: createWebHistory(),
  routes,
})

router.beforeEach(async (to) => {
  const auth = useAuthStore()

  if (to.meta.public) {
    if (to.name === 'login' && auth.isAuthenticated) {
      return { name: 'dashboard' }
    }
    return true
  }

  if (!auth.isAuthenticated) {
    return { name: 'login', query: { redirect: to.fullPath } }
  }

  if (!auth.user) {
    try {
      await auth.fetchMe()
    } catch (_) {
      return { name: 'login' }
    }
  }

  return true
})

export default router
