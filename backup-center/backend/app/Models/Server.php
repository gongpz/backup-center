<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Server extends Model
{
    /* 状态常量 */
    public const STATUS_PENDING = 'pending';
    public const STATUS_ONLINE  = 'online';
    public const STATUS_OFFLINE = 'offline';
    public const STATUS_ERROR   = 'error';

    /* 连接类型常量 */
    public const CONNECT_AGENT = 'agent';
    public const CONNECT_SSH   = 'ssh';

    /* Agent 在线判定阈值（秒），超过则视为离线 */
    public const HEARTBEAT_TIMEOUT = 120;

    protected $fillable = [
        'name', 'ip', 'os_type', 'remark', 'connect_type',
        'agent_token', 'agent_version', 'hostname', 'kernel', 'arch',
        'cpu_usage', 'mem_usage', 'disk_usage', 'disks', 'uptime',
        'ssh_port', 'ssh_user', 'ssh_private_key', 'backup_path',
        'status', 'status_message', 'last_seen_at', 'registered_at',
    ];

    protected $hidden = [
        'ssh_private_key',
    ];

    protected function casts(): array
    {
        return [
            'cpu_usage' => 'float',
            'mem_usage' => 'float',
            'disk_usage' => 'float',
            'disks' => 'array',
            'uptime' => 'integer',
            'ssh_port' => 'integer',
            'last_seen_at' => 'datetime',
            'registered_at' => 'datetime',
        ];
    }

    /* ---------- 生成 Agent Token ---------- */
    public static function generateAgentToken(): string
    {
        return 'bca_' . Str::random(60);
    }

    public function websites(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Website::class);
    }

    public function agentCommands(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(AgentCommand::class);
    }

    /**
     * 返回该服务器"活跃或刚完成"的升级命令 —— 用于前端展示升级进度。
     * - pending/dispatched：正在进行中
     * - 90 秒内变成 success/failed：让前端能弹一次 toast
     */
    public function activeOrRecentAgentCommand(): ?AgentCommand
    {
        return $this->agentCommands()
            ->where('type', AgentCommand::TYPE_UPDATE)
            ->where(function ($q) {
                $q->whereIn('status', [AgentCommand::STATUS_PENDING, AgentCommand::STATUS_DISPATCHED])
                  ->orWhere(function ($qq) {
                      $qq->whereIn('status', [AgentCommand::STATUS_SUCCESS, AgentCommand::STATUS_FAILED])
                         ->where('completed_at', '>', now()->subSeconds(90));
                  });
            })
            ->latest('id')
            ->first();
    }

    /* ---------- 计算实时状态 ---------- */
    public function computedStatus(): string
    {
        if ($this->connect_type === self::CONNECT_SSH) {
            return $this->status;
        }
        if ($this->status === self::STATUS_ERROR) {
            return self::STATUS_ERROR;
        }
        if ($this->last_seen_at === null) {
            return self::STATUS_PENDING;
        }
        $diff = now()->diffInSeconds($this->last_seen_at, true);
        return $diff <= self::HEARTBEAT_TIMEOUT ? self::STATUS_ONLINE : self::STATUS_OFFLINE;
    }
}
