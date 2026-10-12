import { onMounted, onBeforeUnmount, ref } from 'vue'

/**
 * 列表页自动刷新（无感版）
 *
 * 特点：
 *   - 首次加载 + 手动点"刷新"：显示 loading 状态
 *   - 定时轮询：完全静默，不显示 loading，不闪烁
 *   - 标签页隐藏时暂停轮询（省电、省服务器请求）
 *   - 标签页切回可见时立即拉一次
 *   - 组件卸载时清理定时器和事件监听
 *
 * @param {() => Promise<any>} fetcher   实际拉数据的函数（内部更新自己的 list）
 * @param {number}             intervalMs 静默轮询间隔（毫秒），默认 30000
 * @returns {{ loading: Ref<boolean>, manualRefresh: () => Promise<void> }}
 */
export function useAutoRefresh(fetcher, intervalMs = 30000) {
  const loading = ref(false)
  let timer = null
  let inFlight = false

  const call = async (silent) => {
    if (inFlight) return
    inFlight = true
    if (!silent) loading.value = true
    try {
      await fetcher()
    } finally {
      inFlight = false
      if (!silent) loading.value = false
    }
  }

  const scheduleNext = () => {
    if (timer) clearTimeout(timer)
    timer = setTimeout(async () => {
      if (document.visibilityState === 'visible') {
        await call(true) // 静默刷新
      }
      scheduleNext()
    }, intervalMs)
  }

  const onVisibility = () => {
    if (document.visibilityState === 'visible') {
      call(true) // 切回可见立刻拉一次（静默）
    }
  }

  const manualRefresh = () => call(false)

  onMounted(() => {
    call(false) // 首次加载显示 loading
    scheduleNext()
    document.addEventListener('visibilitychange', onVisibility)
  })

  onBeforeUnmount(() => {
    if (timer) clearTimeout(timer)
    document.removeEventListener('visibilitychange', onVisibility)
  })

  return { loading, manualRefresh }
}
