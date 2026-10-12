<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BackupUpload extends Model
{
    public const STATUS_PENDING   = 'pending';
    public const STATUS_UPLOADING = 'uploading';
    public const STATUS_UPLOADED  = 'uploaded';
    public const STATUS_FAILED    = 'failed';

    protected $fillable = [
        'backup_log_id',
        'storage_id',
        'status',
        'remote_path',
        'uploaded_bytes',
        'duration_seconds',
        'error_message',
        'started_at',
        'finished_at',
        'attempts',
    ];

    protected function casts(): array
    {
        return [
            'uploaded_bytes' => 'integer',
            'duration_seconds' => 'integer',
            'attempts' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function backupLog(): BelongsTo
    {
        return $this->belongsTo(BackupLog::class);
    }

    public function storage(): BelongsTo
    {
        return $this->belongsTo(Storage::class);
    }
}
