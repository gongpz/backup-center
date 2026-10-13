<script setup>
import { reactive, ref, computed, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ElMessage } from 'element-plus'
import { User, Lock, DataAnalysis } from '@element-plus/icons-vue'
import { useAuthStore } from '@/stores/auth'
import { authApi } from '@/api/auth'

const auth = useAuthStore()
const router = useRouter()
const route = useRoute()

const isDemo = import.meta.env.VITE_DEMO === '1'

const formRef = ref(null)
const loading = ref(false)
const checking = ref(true)
const needSetup = ref(false)

const form = reactive({
  username: '',
  password: '',
  password_confirmation: '',
  name: '',
})

const rules = computed(() => {
  const base = {
    username: [{ required: true, message: '请输入用户名', trigger: 'blur' }],
    password: [
      { required: true, message: '请输入密码', trigger: 'blur' },
      ...(needSetup.value
        ? [{ min: 8, message: '密码至少 8 位', trigger: 'blur' }]
        : []),
    ],
  }
  if (needSetup.value) {
    base.password_confirmation = [
      { required: true, message: '请再次输入密码', trigger: 'blur' },
    ]
  }
  return base
})

onMounted(async () => {
  try {
    const d = await authApi.bootstrapStatus()
    needSetup.value = !!d.need_bootstrap
  } catch (_) {
    needSetup.value = false
  } finally {
    checking.value = false
  }
})

const finish = () => {
  ElMessage.success(needSetup.value ? '管理员已创建' : '登录成功')
  const redirect = typeof route.query.redirect === 'string' ? route.query.redirect : '/'
  router.replace(redirect)
}

const onSubmit = () => {
  formRef.value?.validate(async (valid) => {
    if (!valid) return
    loading.value = true
    try {
      if (needSetup.value) {
        await auth.bootstrap({
          username: form.username,
          password: form.password,
          password_confirmation: form.password_confirmation,
          name: form.name || form.username,
        })
      } else {
        await auth.login({ username: form.username, password: form.password })
      }
      finish()
    } catch (_) {
      // 拦截器已弹错误提示
    } finally {
      loading.value = false
    }
  })
}
</script>

<template>
  <div class="login-page">
    <div class="login-card">
      <div class="brand">
        <div class="brand-icon">
          <el-icon size="22"><DataAnalysis /></el-icon>
        </div>
        <h1>Backup Center</h1>
        <p>{{ needSetup ? '初始化 · 创建管理员账号' : '统一备份管理中心' }}</p>
      </div>
      <el-form
        v-if="!checking"
        ref="formRef"
        :model="form"
        :rules="rules"
        size="large"
        @submit.prevent="onSubmit"
      >
        <el-form-item v-if="needSetup" prop="name">
          <el-input
            v-model="form.name"
            placeholder="姓名（可选）"
            :prefix-icon="User"
          />
        </el-form-item>
        <el-form-item prop="username">
          <el-input
            v-model="form.username"
            placeholder="用户名"
            :prefix-icon="User"
            autocomplete="username"
          />
        </el-form-item>
        <el-form-item prop="password">
          <el-input
            v-model="form.password"
            type="password"
            placeholder="密码"
            :prefix-icon="Lock"
            show-password
            :autocomplete="needSetup ? 'new-password' : 'current-password'"
            @keyup.enter="onSubmit"
          />
        </el-form-item>
        <el-form-item v-if="needSetup" prop="password_confirmation">
          <el-input
            v-model="form.password_confirmation"
            type="password"
            placeholder="确认密码（至少 8 位）"
            :prefix-icon="Lock"
            show-password
            autocomplete="new-password"
            @keyup.enter="onSubmit"
          />
        </el-form-item>
        <el-form-item>
          <el-button
            type="primary"
            :loading="loading"
            class="login-btn"
            @click="onSubmit"
          >
            {{ needSetup ? '创建管理员' : '登 录' }}
          </el-button>
        </el-form-item>
        <p v-if="isDemo" class="demo-tip">演示环境 · 任意用户名与密码均可登录</p>
      </el-form>
    </div>
    <div class="footer-tip">
      © Backup Center — 备份管理中心 ·
      <a class="author-link" href="https://github.com/gongpz/" target="_blank" rel="noopener" title="GitHub @gongpz">By：Mask</a>
    </div>
  </div>
</template>

<style scoped>
.login-page {
  min-height: 100vh;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  background:
    radial-gradient(1200px 600px at 20% 10%, var(--bc-primary-light) 0%, transparent 60%),
    radial-gradient(900px 500px at 90% 90%, var(--bc-info-bg) 0%, transparent 60%),
    var(--bc-bg-inset);
}
.login-card {
  width: 400px;
  padding: 40px 36px 28px;
  background: var(--bc-bg-card);
  border-radius: var(--bc-radius-xl);
  border: 1px solid var(--bc-border-light);
  box-shadow: var(--bc-shadow-lg);
  animation: bc-rise .6s var(--bc-ease-out) backwards;
}
.brand {
  text-align: center;
  margin-bottom: 28px;
}
.brand-icon {
  width: 52px;
  height: 52px;
  border-radius: 14px;
  background: linear-gradient(135deg, var(--bc-primary-400) 0%, var(--bc-primary) 100%);
  color: var(--bc-on-accent);
  display: inline-flex;
  align-items: center;
  justify-content: center;
  margin-bottom: 16px;
  box-shadow: 0 8px 20px rgba(15, 110, 140, 0.25);
  animation: bc-float 4.5s ease-in-out .5s infinite;
}
.brand h1 {
  margin: 0;
  font-size: 22px;
  font-weight: 600;
  color: var(--bc-text-primary);
  letter-spacing: 0.3px;
}
.brand p {
  margin: 6px 0 0;
  color: var(--bc-text-secondary);
  font-size: 13px;
}
.login-btn {
  width: 100%;
  height: 44px;
  font-size: 15px;
  font-weight: 500;
}
.tip {
  margin-top: 10px;
  text-align: center;
  color: var(--bc-text-placeholder);
  font-size: 12px;
}
.demo-tip {
  margin: -4px 0 0;
  text-align: center;
  color: var(--bc-text-placeholder);
  font-size: 12px;
}
.footer-tip {
  margin-top: 20px;
  color: var(--bc-text-placeholder);
  font-size: 12px;
}
.footer-tip .author-link {
  color: var(--bc-text-placeholder);
  text-decoration: none;
  transition: color .15s;
}
.footer-tip .author-link:hover { color: var(--bc-primary); }
</style>
