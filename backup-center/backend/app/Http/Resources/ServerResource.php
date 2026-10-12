<?php

namespace App\Http\Resources;

use App\Models\Server;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ServerResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Server $this */
        $withToken = (bool) $request->boolean('with_token');

        return [
            'id' => $this->id,
            'name' => $this->name,
            'ip' => $this->ip,
            'os_type' => $this->os_type,
            'remark' => $this->remark,
            'connect_type' => $this->connect_type,

            'agent_version' => $this->agent_version,
            'hostname' => $this->hostname,
            'kernel' => $this->kernel,
            'arch' => $this->arch,
            'cpu_usage' => $this->cpu_usage,
            'mem_usage' => $this->mem_usage,
            'disk_usage' => $this->disk_usage,
            'disks' => $this->disks ?: [],
            'uptime' => $this->uptime,

            'ssh_port' => $this->ssh_port,
            'ssh_user' => $this->ssh_user,
            'has_ssh_key' => ! empty($this->ssh_private_key),
            'backup_path' => $this->backup_path,

            'pending_agent_command' => (function () {
                $c = $this->activeOrRecentAgentCommand();
                if (! $c) return null;
                return [
                    'id'            => $c->id,
                    'status'        => $c->status,
                    'from_version'  => $c->from_version,
                    'to_version'    => $c->to_version,
                    'dispatched_at' => $c->dispatched_at?->toIso8601String(),
                    'completed_at'  => $c->completed_at?->toIso8601String(),
                    'error'         => $c->error,
                ];
            })(),

            'status' => $this->computedStatus(),
            'raw_status' => $this->status,
            'status_message' => $this->status_message,
            'last_seen_at' => $this->last_seen_at?->toIso8601String(),
            'registered_at' => $this->registered_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),

            'agent_token' => $this->when($withToken, $this->agent_token),
        ];
    }
}
