<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentCommand extends Model
{
    public const STATUS_PENDING    = 'pending';
    public const STATUS_DISPATCHED = 'dispatched';
    public const STATUS_SUCCESS    = 'success';
    public const STATUS_FAILED     = 'failed';

    public const TYPE_UPDATE   = 'update';
    public const TYPE_SYNC_NOW = 'sync_now';
    public const TYPE_RESTORE  = 'restore';
    public const TYPE_BIND_ROOT = 'bind_root';
    public const TYPE_SELF_BACKUP = 'self_backup';
    // 保留期清理（C4/T2）：Center 按 retention_days 算出超期文件清单，Agent 用本地
    // rclone.conf 删云端（encryption+agent_local 时中心无密码，只能由 Agent 执行）。
    // params: {files: [{log_id, remote_path}, ...]} —— 绝不携带任何凭据。
    public const TYPE_CLEANUP_RETENTION = 'cleanup_retention';

    protected $fillable = [
        'server_id',
        'type',
        'params',
        'status',
        'output',
        'error',
        'from_version',
        'to_version',
        'dispatched_at',
        'completed_at',
        'reconciled_at',
    ];

    /** dispatched 超过多少分钟视为 stale（sweeper 用；也用于放宽 exists 检查） */
    public const STALE_MINUTES = 10;

    protected function casts(): array
    {
        return [
            'params'        => 'array',
            'dispatched_at' => 'datetime',
            'completed_at'  => 'datetime',
            'reconciled_at' => 'datetime',
        ];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }
}
