<?php

namespace App\Http\Resources;

use App\Models\BackupLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BackupLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var BackupLog $this */
        return [
            'id' => $this->id,
            'website_id' => $this->website_id,
            'server_id' => $this->server_id,
            'website' => $this->whenLoaded('website', fn () => [
                'id' => $this->website->id,
                'name' => $this->website->name,
                'domain' => $this->website->domain,
            ]),
            'server' => $this->whenLoaded('server', fn () => [
                'id' => $this->server->id,
                'name' => $this->server->name,
            ]),
            'filename' => $this->filename,
            'full_path' => $this->full_path,
            'batch_code' => $this->batch_code,
            'size' => $this->size,
            'status' => $this->status,
            'error_message' => $this->error_message,
            'backup_at' => $this->backup_at?->toIso8601String(),
            'duration_seconds' => $this->duration_seconds,
            'upload_status' => $this->upload_status,
            'upload_target' => $this->upload_target,
            'uploaded_at' => $this->uploaded_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'uploads' => $this->whenLoaded('uploads', fn () => $this->uploads->map(fn ($u) => [
                'id'               => $u->id,
                'storage_id'       => $u->storage_id,
                'storage_name'     => $u->storage?->name ?: '（存储已删除）',
                'storage_type'     => $u->storage?->type,
                'status'           => $u->status,
                'remote_path'      => $u->remote_path,
                'uploaded_bytes'   => $u->uploaded_bytes,
                'duration_seconds' => $u->duration_seconds,
                'error_message'    => $u->error_message,
                'attempts'         => $u->attempts,
                'started_at'       => $u->started_at?->toIso8601String(),
                'finished_at'      => $u->finished_at?->toIso8601String(),
            ])),
        ];
    }
}
