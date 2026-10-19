<?php

namespace App\Http\Resources;

use App\Models\Storage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StorageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Storage $this */
        // 编辑详情时把敏感字段以占位符 •••• 返回，列表则可选
        $includeConfig = (bool) $request->boolean('with_config', $request->route()?->getName() === 'storages.show');

        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type,
            'base_path' => $this->base_path,
            'quota_bytes' => $this->quota_bytes,
            'used_bytes' => (int) $this->usedBytes(),
            'upload_count' => (int) $this->uploadCount(),
            'status' => $this->status,
            'is_mandatory' => (bool) $this->is_mandatory,
            'remark' => $this->remark,
            'access_url' => $this->access_url,
            'config' => $this->when($includeConfig, fn () => $this->safeConfig()),
            // 增量备份筛选用布尔脱敏字段（P5）：只告知"配没配"，不泄露任何密钥内容。
            // 前端增量目标下拉：type=sftp && private_key_set && !encryption_enabled
            'private_key_set' => ! empty(($this->config ?? [])['private_key']),
            'encryption_enabled' => ! empty(($this->config ?? [])['encryption']['enabled']),
            'last_test_status' => $this->last_test_status,
            'last_test_message' => $this->last_test_message,
            'last_tested_at' => $this->last_tested_at?->toIso8601String(),
            'websites_count' => $this->when(
                isset($this->websites_count),
                fn () => $this->websites_count,
            ),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
