/**
 * 复制文本到剪贴板（带 HTTP 降级）。
 *
 * 背景：navigator.clipboard 只在"安全上下文"（HTTPS 或 localhost）下可用；
 * 纯 HTTP 部署（例如直接用 http://IP 访问后台）时该 API 不存在，直接调用会抛异常，
 * 导致页面提示"复制失败"。这里在 API 不可用或失败时，回退到隐藏 textarea +
 * document.execCommand('copy')，保证 HTTP 下也能正常复制。
 *
 * @param {string} text 要复制的文本
 * @returns {Promise<boolean>} 是否复制成功
 */
export async function copyText(text) {
  const str = String(text ?? '')

  // 1) 优先 Clipboard API（安全上下文）
  if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
    try {
      await navigator.clipboard.writeText(str)
      return true
    } catch (_) {
      // 落到下面的 execCommand 兜底
    }
  }

  // 2) 兜底：临时 textarea + execCommand（兼容旧浏览器与 HTTP 场景）
  try {
    const ta = document.createElement('textarea')
    ta.value = str
    ta.setAttribute('readonly', '')
    ta.style.position = 'fixed'
    ta.style.top = '0'
    ta.style.left = '-9999px'
    document.body.appendChild(ta)
    ta.select()
    ta.setSelectionRange(0, str.length)
    const ok = document.execCommand('copy')
    document.body.removeChild(ta)
    return ok
  } catch (_) {
    return false
  }
}
