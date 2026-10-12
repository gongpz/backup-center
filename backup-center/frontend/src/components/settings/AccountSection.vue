<script setup>
import { ref, reactive, onMounted } from 'vue'
import { ElMessage } from 'element-plus'
import { User, Lock } from '@element-plus/icons-vue'
import { profileApi } from '@/api/settings'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()

const profileForm = reactive({ username: '', name: '' })
const profileRef = ref(null)
const savingProfile = ref(false)
const initProfile = () => {
  profileForm.username = auth.user?.username || ''
  profileForm.name = auth.user?.name || ''
}
const saveProfile = () => {
  profileRef.value?.validate(async (valid) => {
    if (!valid) return
    savingProfile.value = true
    try {
      const res = await profileApi.updateProfile(profileForm)
      if (auth.user && res.user) { auth.user.username = res.user.username; auth.user.name = res.user.name }
      ElMessage.success('资料已更新')
    } finally { savingProfile.value = false }
  })
}
const profileRules = {
  username: [
    { required: true, message: '请输入用户名', trigger: 'blur' },
    { min: 3, max: 32, message: '用户名 3-32 字符', trigger: 'blur' },
  ],
}

const pwForm = reactive({ current_password: '', new_password: '', new_password_confirmation: '' })
const pwRef = ref(null)
const savingPw = ref(false)
const changePassword = () => {
  pwRef.value?.validate(async (valid) => {
    if (!valid) return
    savingPw.value = true
    try {
      await profileApi.updatePassword(pwForm)
      ElMessage.success('密码已更新，其他会话已注销')
      pwForm.current_password = ''; pwForm.new_password = ''; pwForm.new_password_confirmation = ''
    } finally { savingPw.value = false }
  })
}
const pwRules = {
  current_password: [{ required: true, message: '请输入当前密码', trigger: 'blur' }],
  new_password: [
    { required: true, message: '请输入新密码', trigger: 'blur' },
    { min: 8, max: 64, message: '密码长度 8-64', trigger: 'blur' },
  ],
  new_password_confirmation: [
    { required: true, message: '请再次输入新密码', trigger: 'blur' },
    { validator: (_, v, cb) => v === pwForm.new_password ? cb() : cb(new Error('两次输入的密码不一致')), trigger: 'blur' },
  ],
}
onMounted(initProfile)
</script>

<template>
  <div class="section-grid">
    <div class="s-card">
      <div class="s-card-head"><el-icon><User /></el-icon><span>个人资料</span></div>
      <p class="s-hint">用户名用于登录，显示名用于页面上展示。</p>
      <el-form ref="profileRef" :model="profileForm" :rules="profileRules"
               label-width="80px" label-position="right" require-asterisk-position="left">
        <el-form-item label="用户名" prop="username">
          <el-input v-model="profileForm.username" placeholder="登录用" />
        </el-form-item>
        <el-form-item label="显示名" prop="name">
          <el-input v-model="profileForm.name" placeholder="页面显示的名字，可留空" maxlength="64" />
        </el-form-item>
        <el-form-item>
          <el-button type="primary" :loading="savingProfile" @click="saveProfile">保存资料</el-button>
        </el-form-item>
      </el-form>
    </div>

    <div class="s-card">
      <div class="s-card-head"><el-icon><Lock /></el-icon><span>修改密码</span></div>
      <p class="s-hint">修改后其他登录会话将被自动注销。</p>
      <el-form ref="pwRef" :model="pwForm" :rules="pwRules"
               label-width="90px" label-position="right" require-asterisk-position="left">
        <el-form-item label="当前密码" prop="current_password">
          <el-input v-model="pwForm.current_password" type="password" show-password autocomplete="current-password" />
        </el-form-item>
        <el-form-item label="新密码" prop="new_password">
          <el-input v-model="pwForm.new_password" type="password" show-password autocomplete="new-password" />
        </el-form-item>
        <el-form-item label="确认密码" prop="new_password_confirmation">
          <el-input v-model="pwForm.new_password_confirmation" type="password" show-password autocomplete="new-password" />
        </el-form-item>
        <el-form-item>
          <el-button type="primary" :loading="savingPw" @click="changePassword">修改密码</el-button>
        </el-form-item>
      </el-form>
    </div>
  </div>
</template>

<style scoped>
.section-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
@media (max-width: 1080px) { .section-grid { grid-template-columns: 1fr; } }
.s-card { background: var(--bc-bg-card); border: 1px solid var(--bc-border-light); border-radius: 12px; padding: 20px 24px; }
.s-card-head { display: flex; align-items: center; gap: 8px; font-size: 15px; font-weight: 600; color: var(--bc-text-primary); margin-bottom: 8px; }
.s-card-head .el-icon { color: var(--bc-primary); }
.s-hint { color: var(--bc-text-secondary); font-size: 13px; margin: 0 0 16px; line-height: 1.6; }
</style>
