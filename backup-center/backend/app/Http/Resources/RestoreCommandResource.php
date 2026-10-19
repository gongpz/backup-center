<?php

namespace App\Http\Resources;

use App\Models\AgentCommand;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 仪表盘「最近恢复记录」：一条 restore 命令 = 一次文件级恢复。
 *
 * 关联关系不在 AgentCommand 模型上（params.backup_log_id 是 JSON 字段），
 * 由调用方把 BackupLog 挂到 restoreLog 关系上；未挂载时 website/size 字段省略。
 */
class RestoreCommandResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var AgentCommand $this */
        $params = $this->params ?? [];

        return [
            'id' => $this->id,
            'filename' => $params['filename'] ?? basename((string) ($params['remote_path'] ?? '')),
            'size' => $this->whenLoaded('restoreLog', fn () => $this->restoreLog->size),
            'status' => $this->status,
            // 耗时 = completed_at - dispatched_at；排队中/执行中未完结时无耗时。
            // 传 $absolute=true：Carbon 3 默认有符号，completed 早于 dispatched（时钟偏差/
            // 补偿写入）时会算出负耗时，取绝对值兜底。
            'duration_seconds' => $this->dispatched_at && $this->completed_at
                ? $this->completed_at->diffInSeconds($this->dispatched_at, true)
                : null,
            'created_at' => $this->created_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'website' => $this->whenLoaded('restoreLog', fn () => [
                'id' => $this->restoreLog->website?->id,
                'name' => $this->restoreLog->website?->name,
                'domain' => $this->restoreLog->website?->domain,
            ]),
        ];
    }
}
