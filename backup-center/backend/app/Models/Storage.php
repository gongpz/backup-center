<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Crypt;

class Storage extends Model
{
    public const TYPE_SFTP   = 'sftp';
    public const TYPE_FTP    = 'ftp';
    public const TYPE_WEBDAV = 'webdav';
    public const TYPE_S3     = 's3';

    public const STATUS_ENABLED  = 'enabled';
    public const STATUS_DISABLED = 'disabled';

    public const TEST_OK     = 'ok';
    public const TEST_FAILED = 'failed';

    protected $fillable = [
        'name', 'type', 'config', 'base_path', 'quota_bytes',
        'status', 'is_mandatory', 'remark', 'access_url',
        'last_test_status', 'last_test_message', 'last_tested_at',
    ];

    protected $hidden = ['config'];

    protected function casts(): array
    {
        return [
            'is_mandatory' => 'boolean',
            'quota_bytes' => 'integer',
            'last_tested_at' => 'datetime',
        ];
    }

    /**
     * 从 backup_uploads 汇总该存储已用字节数（uploaded 状态）
     */
    public function usedBytes(): int
    {
        return (int) $this->hasMany(BackupUpload::class)
            ->where('status', BackupUpload::STATUS_UPLOADED)
            ->sum('uploaded_bytes');
    }

    /**
     * 该存储成功承载的备份文件数（backup_uploads 里 status=uploaded 的记录数）
     */
    public function uploadCount(): int
    {
        return (int) $this->hasMany(BackupUpload::class)
            ->where('status', BackupUpload::STATUS_UPLOADED)
            ->count();
    }

    /** 所有强制备份的存储 */
    public static function mandatoryEnabled()
    {
        return static::where('is_mandatory', true)
            ->where('status', self::STATUS_ENABLED);
    }

    /**
     * config 字段：存储时加密为字符串，读取时反序列化为数组
     */
    protected function config(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $value === null ? [] : json_decode(Crypt::decryptString($value), true),
            set: fn ($value) => Crypt::encryptString(is_array($value) ? json_encode($value) : $value),
        );
    }

    public function websites(): BelongsToMany
    {
        return $this->belongsToMany(Website::class, 'website_storages')->withTimestamps();
    }

    /**
     * 返回一份"安全"版本的 config（把敏感字段替换成占位符），用于 API 返回给前端。
     */
    public function safeConfig(): array
    {
        $c = $this->config;
        foreach (['password', 'private_key', 'secret_access_key'] as $key) {
            if (! empty($c[$key])) {
                $c[$key] = '••••••••';
            }
        }
        // 加密密码（center 托管模式中心持有）按运营需求在后台明文可见：
        // config 整体有 Crypt 加密保护数据库层，面板仅管理员可访问；
        // 前端编辑时回填真实密码，提交空串才表示"不修改"
        // （勿恢复此处打码——恢复页要核对加密密码时必须能看到明文）
        return $c;
    }
}
