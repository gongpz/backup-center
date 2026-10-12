<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class ResourcePool extends Model
{
    public const PROVIDER_TENCENT_COS = 'tencent_cos';
    public const PROVIDER_ALIYUN_OSS  = 'aliyun_oss';
    public const PROVIDER_S3          = 's3';

    public const STATUS_ENABLED  = 'enabled';
    public const STATUS_DISABLED = 'disabled';

    public const TEST_OK     = 'ok';
    public const TEST_FAILED = 'failed';

    protected $fillable = [
        'name', 'provider', 'endpoint', 'region', 'bucket',
        'config', 'public_domain', 'is_agent_mirror', 'status',
        'last_test_status', 'last_test_message', 'last_tested_at',
        'remark',
    ];

    protected $hidden = ['config'];

    protected function casts(): array
    {
        return [
            'is_agent_mirror' => 'boolean',
            'last_tested_at'  => 'datetime',
        ];
    }

    protected function config(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $value === null ? [] : json_decode(Crypt::decryptString($value), true),
            set: fn ($value) => Crypt::encryptString(is_array($value) ? json_encode($value) : $value),
        );
    }

    /**
     * 公开访问的 URL 前缀（不含对象 key）
     * 优先用自定义域名，否则用 <bucket>.<endpoint> 虚拟主机风格
     */
    public function publicUrlPrefix(): string
    {
        if (! empty($this->public_domain)) {
            return rtrim($this->public_domain, '/');
        }
        if (empty($this->endpoint) || empty($this->bucket)) {
            return '';
        }
        $ep = preg_replace('#^https?://#', '', $this->endpoint);
        return 'https://' . $this->bucket . '.' . rtrim($ep, '/');
    }

    public function publicUrlFor(string $objectKey): string
    {
        $prefix = $this->publicUrlPrefix();
        return $prefix === '' ? '' : $prefix . '/' . ltrim($objectKey, '/');
    }
}
