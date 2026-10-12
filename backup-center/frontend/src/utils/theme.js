/**
 * 主题管理：light / dark / auto
 * - 存 localStorage
 * - 应用时给 <html> 加/去 class="dark"
 */
const KEY = 'bc_theme'

export function currentTheme() {
  return localStorage.getItem(KEY) || 'light'
}

export function applyTheme(theme) {
  const effective = theme === 'auto'
    ? (window.matchMedia?.('(prefers-color-scheme: dark)').matches ? 'dark' : 'light')
    : theme
  const el = document.documentElement
  if (effective === 'dark') {
    el.classList.add('dark')
  } else {
    el.classList.remove('dark')
  }
}

export function setTheme(theme) {
  localStorage.setItem(KEY, theme)
  applyTheme(theme)
}

// auto 模式下监听系统偏好变化
if (typeof window !== 'undefined' && window.matchMedia) {
  window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => {
    if (currentTheme() === 'auto') applyTheme('auto')
  })
}
