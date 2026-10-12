/**
 * 备份计划工具：type + time + day <=> cron 字符串
 *
 * 支持的 4 种模式：
 *   off     -> null
 *   daily   -> "MM HH * * *"
 *   weekly  -> "MM HH * * D"   (D: 0-6，0=周日)
 *   monthly -> "MM HH D * *"   (D: 1-31)
 */

export const scheduleTypes = [
  { value: 'off',     label: '关闭' },
  { value: 'daily',   label: '每天' },
  { value: 'weekly',  label: '每周' },
  { value: 'monthly', label: '每月' },
]

export const weekDays = [
  { value: 1, label: '周一' },
  { value: 2, label: '周二' },
  { value: 3, label: '周三' },
  { value: 4, label: '周四' },
  { value: 5, label: '周五' },
  { value: 6, label: '周六' },
  { value: 0, label: '周日' },
]

/** 把表单值编码成 cron 字符串。type=off 返回 null */
export function encodeSchedule({ type, time, weekDay, monthDay }) {
  if (type === 'off' || !type) return null
  const [hh, mm] = (time || '03:00').split(':').map((x) => parseInt(x, 10))
  const M = mm || 0
  const H = hh || 0
  switch (type) {
    case 'daily':
      return `${M} ${H} * * *`
    case 'weekly':
      return `${M} ${H} * * ${weekDay ?? 1}`
    case 'monthly':
      return `${M} ${H} ${monthDay ?? 1} * *`
    default:
      return null
  }
}

/** 反解析 cron 字符串到表单值 */
export function decodeSchedule(cron) {
  const empty = { type: 'off', time: '03:00', weekDay: 1, monthDay: 1 }
  if (!cron || typeof cron !== 'string') return empty

  const parts = cron.trim().split(/\s+/)
  if (parts.length !== 5) return empty

  const [m, h, dom, mon, dow] = parts

  const M = parseInt(m, 10)
  const H = parseInt(h, 10)
  if (Number.isNaN(M) || Number.isNaN(H)) return empty
  const time = `${String(H).padStart(2, '0')}:${String(M).padStart(2, '0')}`

  if (mon === '*' && dow === '*' && dom !== '*') {
    return { type: 'monthly', time, weekDay: 1, monthDay: parseInt(dom, 10) || 1 }
  }
  if (mon === '*' && dom === '*' && dow !== '*') {
    return { type: 'weekly', time, weekDay: parseInt(dow, 10) || 0, monthDay: 1 }
  }
  if (mon === '*' && dom === '*' && dow === '*') {
    return { type: 'daily', time, weekDay: 1, monthDay: 1 }
  }
  // 其他自定义 cron 就归为 off（暂不支持）
  return empty
}

/** 生成人类可读的描述 */
export function humanize(cron) {
  const { type, time, weekDay, monthDay } = decodeSchedule(cron)
  if (type === 'off') return '未启用'
  const wd = weekDays.find((d) => d.value === weekDay)?.label || '周一'
  switch (type) {
    case 'daily':   return `每天 ${time}`
    case 'weekly':  return `每${wd} ${time}`
    case 'monthly': return `每月 ${monthDay} 号 ${time}`
    default:        return '未启用'
  }
}
