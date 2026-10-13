<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;

class Website extends Model
{
    public const STATUS_ENABLED  = 'enabled';
    public const STATUS_DISABLED = 'disabled';

    public const BACKUP_SUCCESS = 'success';
    public const BACKUP_FAILED  = 'failed';
    public const BACKUP_RUNNING = 'running';

    // 备份来源：external=第三方压缩/监控文件夹；self=系统自主压缩（P3）
    public const BACKUP_SOURCE_EXTERNAL = 'external';
    public const BACKUP_SOURCE_SELF     = 'self';

    // 恢复模式：lite=轻便（拉回备份目录手动恢复）；advanced=高级自动恢复（P4）
    public const RESTORE_MODE_LITE      = 'lite';
    public const RESTORE_MODE_ADVANCED  = 'advanced';

    // 数据库类型（P3/P4）
    public const DB_TYPE_MYSQL      = 'mysql';
    public const DB_TYPE_POSTGRESQL = 'postgresql';

    protected $fillable = [
        'server_id',
        'name',
        'domain',
        'backup_paths',
        'retention_days',
        'schedule',
        'sync_mode',
        'backup_source',
        'restore_mode',
        'bind_token',
        'web_root',
        'root_bound_at',
        'db_type',
        'db_host',
        'db_port',
        'db_name',
        'db_user',
        'db_password',
        'status',
        'remark',
        'last_backup_at',
        'last_backup_status',
        'last_backup_size',
        'last_backup_filename',
    ];

    protected function casts(): array
    {
        return [
            'server_id' => 'integer',
            'retention_days' => 'integer',
            'backup_paths' => 'array',
            'last_backup_at' => 'datetime',
            'last_backup_size' => 'integer',
            'root_bound_at' => 'datetime',
        ];
    }

    /**
     * 数据库密码：存储时加密、读取时解密（仿 Storage.config）。
     */
    protected function dbPassword(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => ($value === null || $value === '') ? null : Crypt::decryptString($value),
            set: fn ($value) => ($value === null || $value === '') ? null : Crypt::encryptString($value),
        );
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function backupLogs(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(BackupLog::class);
    }

    public function storages(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Storage::class, 'website_storages')->withTimestamps();
    }

    /**
     * 按每个 backup_paths 项聚合最新的 backup_log + uploads 情况。
     * 前端网站列表用这个字段展示"网站目录 成功 · 数据库目录 失败(七牛)"这类分行结果。
     */
    public function recentBackupsByPath(): array
    {
        $paths = $this->backup_paths ?? [];
        if (! is_array($paths) || empty($paths)) return [];

        $result = [];
        foreach ($paths as $idx => $bp) {
            $path = $bp['path'] ?? '';
            $label = $bp['label'] ?? ('备份目录' . ($idx + 1));
            if ($path === '') {
                $result[] = ['label' => $label, 'path' => '', 'log' => null];
                continue;
            }
            // full_path 前缀匹配（Agent 上报的文件都在这个目录下）
            $log = $this->backupLogs()
                ->where('full_path', 'like', $path . '%')
                ->orderByDesc('backup_at')
                ->orderByDesc('id')
                ->with(['uploads.storage:id,name,type'])
                ->first();
            if (! $log) {
                $result[] = [
                    'label' => $label, 'path' => $path,
                    'log' => null,
                ];
                continue;
            }
            $failed = $log->uploads
                ->filter(fn ($u) => $u->status === BackupUpload::STATUS_FAILED)
                ->map(fn ($u) => [
                    'storage_id'   => $u->storage_id,
                    'storage_name' => $u->storage?->name ?: '（存储已删除）',
                    'error'        => $u->error_message,
                ])->values()->all();
            $succeeded = $log->uploads
                ->filter(fn ($u) => $u->status === BackupUpload::STATUS_UPLOADED)
                ->map(fn ($u) => [
                    'storage_id'   => $u->storage_id,
                    'storage_name' => $u->storage?->name ?: '（存储已删除）',
                ])->values()->all();
            $result[] = [
                'label' => $label,
                'path'  => $path,
                'log'   => [
                    'id'            => $log->id,
                    'filename'      => $log->filename,
                    'status'        => $log->status,
                    'upload_status' => $log->upload_status,
                    'size'          => $log->size,
                    'backup_at'     => $log->backup_at?->toIso8601String(),
                    // synced_at = Center 收到 Agent 上报的时间（DB 记录创建时间）
                    // 与 backup_at 区分：backup_at = 源文件 mtime（宝塔打包时间），synced_at = 同步到 Center 的时间
                    'synced_at'     => $log->created_at?->toIso8601String(),
                    'succeeded'     => $succeeded,
                    'failed'        => $failed,
                ],
            ];
        }
        return $result;
    }
}
