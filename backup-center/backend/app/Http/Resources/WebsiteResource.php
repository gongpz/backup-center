<?php

namespace App\Http\Resources;

use App\Models\Website;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WebsiteResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Website $this */
        return [
            'id' => $this->id,
            'server_id' => $this->server_id,
            'server' => $this->whenLoaded('server', function () {
                return [
                    'id' => $this->server->id,
                    'name' => $this->server->name,
                    'status' => $this->server->computedStatus(),
                    'connect_type' => $this->server->connect_type,
                ];
            }),
            'storages' => $this->whenLoaded('storages', fn () => $this->storages->map(fn ($s) => [
                'id' => $s->id,
                'name' => $s->name,
                'type' => $s->type,
            ])),
            'storage_ids' => $this->whenLoaded('storages', fn () => $this->storages->pluck('id')->toArray()),
            'name' => $this->name,
            'domain' => $this->domain,
            'backup_paths' => $this->backup_paths ?: [],
            'retention_days' => $this->retention_days,
            'schedule' => $this->schedule,
            'sync_mode' => $this->sync_mode ?: 'auto',
            'backup_source' => $this->backup_source ?: 'external',
            'restore_mode' => $this->restore_mode ?: 'lite',

            // 根目录绑定
            'bind_token' => $this->bind_token,
            'web_root' => $this->web_root,
            'root_bound_at' => $this->root_bound_at?->toIso8601String(),

            // 数据库连接（P3/P4 用；密码不回传明文，仅告知是否已配置）
            'db_type' => $this->db_type,
            'db_host' => $this->db_host,
            'db_port' => $this->db_port,
            'db_name' => $this->db_name,
            'db_user' => $this->db_user,
            'db_password_set' => ! empty($this->db_password),

            'status' => $this->status,
            'remark' => $this->remark,

            'last_backup_at' => $this->last_backup_at?->toIso8601String(),
            'last_backup_status' => $this->last_backup_status,
            'last_backup_size' => $this->last_backup_size,
            'last_backup_filename' => $this->last_backup_filename,
            'recent_backups' => $this->recentBackupsByPath(),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
