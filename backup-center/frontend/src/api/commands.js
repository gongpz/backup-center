import http from '@/utils/http'

// 指令（bind_root / sync_now / self_backup …）经 Agent 心跳异步执行，
// 下发后轮询此接口拿 Agent 回传的 output/error，把真实执行情况反馈给用户。
export const commandsApi = {
  get(id) {
    return http.get(`/agent-commands/${id}`).then((r) => r.data)
  },
}
