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
