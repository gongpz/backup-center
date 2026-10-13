<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 通用 KV 系统设置。
 *   SystemSetting::get('system_name', 'backup center')
 *   SystemSetting::set('system_name', 'My Backup')
 */
class SystemSetting extends Model
{
    public $timestamps = true;

    protected $fillable = ['key', 'value'];

    public static function get(string $key, ?string $default = null): ?string
    {
        return static::where('key', $key)->value('value') ?? $default;
    }

    public static function set(string $key, ?string $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value]);
    }

    /** 一次拿多个键，返回关联数组 */
    public static function many(array $keys, array $defaults = []): array
    {
        $found = static::whereIn('key', $keys)->pluck('value', 'key')->toArray();
        $out = [];
        foreach ($keys as $k) {
            $out[$k] = $found[$k] ?? ($defaults[$k] ?? null);
        }
        return $out;
    }
}
