<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class NotificationSetting extends Model
{
    protected $table = 'notification_settings';

    protected $fillable = [
        'serverchan_sendkey',
        'notify_backup_success',
        'notify_backup_failed',
        'notify_agent_offline',
    ];

    protected $hidden = ['serverchan_sendkey'];

    protected function casts(): array
    {
        return [
            'notify_backup_success' => 'boolean',
            'notify_backup_failed' => 'boolean',
            'notify_agent_offline' => 'boolean',
        ];
    }

    /** SendKey 存储时加密，读取时解密 */
    protected function serverchanSendkey(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $value === null ? null : Crypt::decryptString($value),
            set: fn ($value) => empty($value) ? null : Crypt::encryptString($value),
        );
    }

    /** 单例：始终返回 id=1 的行 */
    public static function current(): self
    {
        return static::firstOrCreate(['id' => 1]);
    }

    public function hasSendkey(): bool
    {
        return ! empty($this->serverchan_sendkey);
    }
}
