<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BackupLog extends Model
{
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED  = 'failed';
    public const STATUS_RUNNING = 'running';

    public const UPLOAD_PENDING       = 'pending';
    public const UPLOAD_UPLOADING     = 'uploading';
    public const UPLOAD_UPLOADED      = 'uploaded';
    public const UPLOAD_FAILED        = 'upload_failed';

    protected $fillable = [
        'website_id',
        'server_id',
        'filename',
        'full_path',
        'size',
        'status',
        'error_message',
        'backup_at',
        'duration_seconds',
        'upload_status',
        'upload_target',
        'uploaded_at',
    ];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'duration_seconds' => 'integer',
            'backup_at' => 'datetime',
            'uploaded_at' => 'datetime',
        ];
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function uploads(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(BackupUpload::class);
    }
}
