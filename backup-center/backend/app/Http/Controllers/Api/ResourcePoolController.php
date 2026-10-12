<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ResourcePool;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ResourcePoolController extends Controller
{
    public function index(): JsonResponse
    {
        $pools = ResourcePool::orderByDesc('is_agent_mirror')->orderBy('id')->get();
        return response()->json([
            'data' => $pools->map(fn ($p) => $this->present($p))->values(),
        ]);
    }

    public function show(ResourcePool $resourcePool): JsonResponse
    {
        return response()->json(['data' => $this->present($resourcePool, true)]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $pool = new ResourcePool();
        $this->applyToModel($pool, $data);
        $pool->save();
        $this->enforceSingleMirror($pool);
        return response()->json(['data' => $this->present($pool->fresh())], 201);
    }

    public function update(Request $request, ResourcePool $resourcePool): JsonResponse
    {
        $data = $this->validated($request, $resourcePool);
        $this->applyToModel($resourcePool, $data);
        $resourcePool->save();
        $this->enforceSingleMirror($resourcePool);
        return response()->json(['data' => $this->present($resourcePool->fresh())]);
    }

    public function destroy(ResourcePool $resourcePool): JsonResponse
    {
        $resourcePool->delete();
        return response()->json(['ok' => true]);
    }

    /**
     * 连接测试：调 rclone lsjson --max-depth 1 验证凭证 + 网络
     */
    public function test(ResourcePool $resourcePool): JsonResponse
    {
        [$ok, $msg] = $this->rcloneList($resourcePool, '', 1);
        $resourcePool->last_test_status = $ok ? ResourcePool::TEST_OK : ResourcePool::TEST_FAILED;
        $resourcePool->last_test_message = mb_substr($msg, 0, 500);
        $resourcePool->last_tested_at = now();
        $resourcePool->save();
        return response()->json([
            'ok' => $ok,
            'message' => $msg,
            'data' => $this->present($resourcePool),
        ]);
    }

    /**
     * 列出该池子里的文件
     */
    public function listFiles(ResourcePool $resourcePool): JsonResponse
    {
        [$ok, $out] = $this->rcloneList($resourcePool, '', 1, true);
        if (! $ok) {
            return response()->json(['ok' => false, 'message' => $out, 'files' => []], 200);
        }
        $lines = json_decode($out, true) ?: [];

        // 一次性拿到此池子所有备注（LEFT JOIN 手动版：小规模数据 map lookup 即可）
        $noteMap = \App\Models\PoolResourceNote::where('pool_id', $resourcePool->id)
            ->pluck('remark', 'path')
            ->toArray();

        $files = [];
        foreach ($lines as $l) {
            if (! ($l['IsDir'] ?? false)) {
                $path = $l['Path'] ?? '';
                $files[] = [
                    'name'       => $l['Name'] ?? $path,
                    'path'       => $path,
                    'size'       => (int) ($l['Size'] ?? 0),
                    'mime'       => $l['MimeType'] ?? null,
                    'modified'   => $l['ModTime'] ?? null,
                    'public_url' => $resourcePool->publicUrlFor($path),
                    'remark'     => $noteMap[$path] ?? null,
                ];
            }
        }
        return response()->json(['ok' => true, 'files' => $files]);
    }

    /**
     * 更新文件备注（POST /resource-pools/{id}/files/remark）
     */
    public function updateFileRemark(Request $request, ResourcePool $resourcePool): JsonResponse
    {
        $data = $request->validate([
            'path'   => ['required', 'string', 'max:500'],
            'remark' => ['nullable', 'string', 'max:500'],
        ]);
        $remark = trim((string) ($data['remark'] ?? ''));

        if ($remark === '') {
            \App\Models\PoolResourceNote::where('pool_id', $resourcePool->id)
                ->where('path', $data['path'])
                ->delete();
        } else {
            \App\Models\PoolResourceNote::updateOrCreate(
                ['pool_id' => $resourcePool->id, 'path' => $data['path']],
                ['remark' => $remark]
            );
        }
        return response()->json(['ok' => true, 'remark' => $remark]);
    }

    /**
     * 上传文件（≤100 MB）
     */
    public function uploadFile(Request $request, ResourcePool $resourcePool): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:102400'],
            'name' => ['nullable', 'string', 'max:255'],
        ]);
        $file = $request->file('file');
        $objectKey = trim($request->input('name') ?: $file->getClientOriginalName());
        if ($objectKey === '' || preg_match('#[\\\\/]|\\.\\.#', $objectKey)) {
            return response()->json(['ok' => false, 'message' => '文件名不合法'], 422);
        }
        $tmpLocal = $file->getRealPath();
        [$ok, $msg] = $this->rcloneCopyTo($resourcePool, $tmpLocal, $objectKey);
        if (! $ok) {
            return response()->json(['ok' => false, 'message' => $msg], 500);
        }
        return response()->json([
            'ok' => true,
            'file' => [
                'name'       => $objectKey,
                'path'       => $objectKey,
                'size'       => $file->getSize(),
                'mime'       => $file->getMimeType(),
                'public_url' => $resourcePool->publicUrlFor($objectKey),
            ],
        ]);
    }

    public function deleteFile(Request $request, ResourcePool $resourcePool): JsonResponse
    {
        $path = trim((string) $request->input('path'));
        if ($path === '' || preg_match('#\\.\\.#', $path)) {
            return response()->json(['ok' => false, 'message' => '路径不合法'], 422);
        }
        [$ok, $msg] = $this->rcloneDelete($resourcePool, $path);
        return response()->json(['ok' => $ok, 'message' => $msg]);
    }

    private function validated(Request $request, ?ResourcePool $existing = null): array
    {
        return $request->validate([
            'name'            => ['required', 'string', 'max:64'],
            'provider'        => ['required', Rule::in([
                ResourcePool::PROVIDER_TENCENT_COS,
                ResourcePool::PROVIDER_ALIYUN_OSS,
                ResourcePool::PROVIDER_S3,
            ])],
            'endpoint'        => ['nullable', 'string', 'max:191'],
            'region'          => ['nullable', 'string', 'max:64'],
            'bucket'          => ['required', 'string', 'max:128'],
            'access_key_id'   => ['nullable', 'string', 'max:191'],
            'secret_access_key' => ['nullable', 'string', 'max:191'],
            'public_domain'   => ['nullable', 'string', 'max:191', 'regex:/^https?:\\/\\//'],
            'is_agent_mirror' => ['sometimes', 'boolean'],
            'status'          => ['sometimes', Rule::in(['enabled', 'disabled'])],
            'remark'          => ['nullable', 'string', 'max:500'],
        ]);
    }

    private function applyToModel(ResourcePool $pool, array $data): void
    {
        $config = $pool->config ?: [];
        foreach (['name', 'provider', 'endpoint', 'region', 'bucket',
                  'public_domain', 'is_agent_mirror', 'status', 'remark'] as $k) {
            if (array_key_exists($k, $data)) {
                $pool->{$k} = $data[$k];
            }
        }
        // 凭证：如果传了才更新，避免"编辑时不重复填写"覆盖为空
        if (array_key_exists('access_key_id', $data) && $data['access_key_id'] !== null && $data['access_key_id'] !== '') {
            $config['access_key_id'] = $data['access_key_id'];
        }
        if (array_key_exists('secret_access_key', $data) && $data['secret_access_key'] !== null && $data['secret_access_key'] !== '') {
            $config['secret_access_key'] = $data['secret_access_key'];
        }
        if ($config) {
            $pool->config = $config;
        }
    }

    /**
     * 保证同一时刻只有一条 is_agent_mirror=true
     */
    private function enforceSingleMirror(ResourcePool $current): void
    {
        if (! $current->is_agent_mirror) return;
        DB::table('resource_pools')
            ->where('id', '!=', $current->id)
            ->where('is_agent_mirror', true)
            ->update(['is_agent_mirror' => false]);
    }

    private function present(ResourcePool $p, bool $withSecretHint = false): array
    {
        $cfg = $p->config ?: [];
        $arr = [
            'id'               => $p->id,
            'name'             => $p->name,
            'provider'         => $p->provider,
            'endpoint'         => $p->endpoint,
            'region'           => $p->region,
            'bucket'           => $p->bucket,
            'public_domain'    => $p->public_domain,
            'is_agent_mirror'  => (bool) $p->is_agent_mirror,
            'status'           => $p->status,
            'remark'           => $p->remark,
            'has_credentials'  => ! empty($cfg['access_key_id']) && ! empty($cfg['secret_access_key']),
            'access_key_hint'  => isset($cfg['access_key_id']) ? mb_substr($cfg['access_key_id'], 0, 4) . '****' . mb_substr($cfg['access_key_id'], -4) : null,
            'public_url_prefix' => $p->publicUrlPrefix(),
            'last_test_status' => $p->last_test_status,
            'last_test_message' => $p->last_test_message,
            'last_tested_at'   => $p->last_tested_at?->toIso8601String(),
            'created_at'       => $p->created_at?->toIso8601String(),
        ];
        return $arr;
    }

    /**
     * rclone.conf 片段（仅内存传给 --config，不落盘长久）
     */
    private function buildRcloneConf(ResourcePool $p): string
    {
        $cfg = $p->config ?: [];
        $ak = $cfg['access_key_id'] ?? '';
        $sk = $cfg['secret_access_key'] ?? '';
        $ep = $p->endpoint ?: '';
        $region = $p->region ?: 'us-east-1';
        $providerLine = match ($p->provider) {
            ResourcePool::PROVIDER_TENCENT_COS => "provider = TencentCOS",
            ResourcePool::PROVIDER_ALIYUN_OSS  => "provider = Alibaba",
            default                            => "provider = Other",
        };
        return "[pool_{$p->id}]\n"
            . "type = s3\n"
            . $providerLine . "\n"
            . "access_key_id = {$ak}\n"
            . "secret_access_key = {$sk}\n"
            . "endpoint = {$ep}\n"
            . "region = {$region}\n"
            . "force_path_style = false\n"
            . "no_check_bucket = true\n"
            . "acl = public-read\n";
    }

    private function rcloneList(ResourcePool $p, string $prefix, int $maxDepth = 1, bool $json = true): array
    {
        $confPath = tempnam(sys_get_temp_dir(), 'rp_');
        try {
            file_put_contents($confPath, $this->buildRcloneConf($p));
            chmod($confPath, 0600);
            $target = "pool_{$p->id}:{$p->bucket}" . ($prefix !== '' ? '/' . ltrim($prefix, '/') : '');
            $cmd = 'rclone lsjson --config ' . escapeshellarg($confPath)
                . ' --max-depth ' . $maxDepth
                . ' ' . escapeshellarg($target)
                . ' 2>&1';
            exec($cmd, $out, $code);
            $joined = implode("\n", $out);
            if ($code === 0) return [true, $joined];
            return [false, mb_substr($joined, 0, 500)];
        } finally {
            @unlink($confPath);
        }
    }

    private function rcloneCopyTo(ResourcePool $p, string $localFile, string $objectKey): array
    {
        $confPath = tempnam(sys_get_temp_dir(), 'rp_');
        try {
            file_put_contents($confPath, $this->buildRcloneConf($p));
            chmod($confPath, 0600);
            $target = "pool_{$p->id}:{$p->bucket}/" . ltrim($objectKey, '/');
            $cmd = 'rclone copyto --config ' . escapeshellarg($confPath)
                . ' --contimeout 15s --timeout 300s --retries 2'
                . ' --s3-acl public-read'
                . ' ' . escapeshellarg($localFile)
                . ' ' . escapeshellarg($target)
                . ' 2>&1';
            exec($cmd, $out, $code);
            $joined = implode("\n", $out);
            if ($code === 0) return [true, 'ok'];
            return [false, mb_substr($joined, 0, 500)];
        } finally {
            @unlink($confPath);
        }
    }

    private function rcloneDelete(ResourcePool $p, string $objectKey): array
    {
        $confPath = tempnam(sys_get_temp_dir(), 'rp_');
        try {
            file_put_contents($confPath, $this->buildRcloneConf($p));
            chmod($confPath, 0600);
            $target = "pool_{$p->id}:{$p->bucket}/" . ltrim($objectKey, '/');
            $cmd = 'rclone deletefile --config ' . escapeshellarg($confPath)
                . ' ' . escapeshellarg($target)
                . ' 2>&1';
            exec($cmd, $out, $code);
            $joined = implode("\n", $out);
            if ($code === 0) return [true, 'ok'];
            return [false, mb_substr($joined, 0, 500)];
        } finally {
            @unlink($confPath);
        }
    }
}
