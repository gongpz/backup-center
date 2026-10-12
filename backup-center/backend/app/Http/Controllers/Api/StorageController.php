<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\StorageResource;
use App\Models\Storage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class StorageController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Storage::query()
            ->withCount('websites')
            ->orderByDesc('id');

        if ($keyword = $request->string('keyword')->trim()->toString()) {
            $query->where(function ($q) use ($keyword) {
                $q->where('name', 'like', "%{$keyword}%")
                  ->orWhere('remark', 'like', "%{$keyword}%");
            });
        }
        if ($type = $request->string('type')->toString()) {
            $query->where('type', $type);
        }
        if ($status = $request->string('status')->toString()) {
            $query->where('status', $status);
        }

        return StorageResource::collection($query->paginate(20));
    }

    public function show(Storage $storage): StorageResource
    {
        $storage->loadCount('websites');
        return new StorageResource($storage);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        // 校验并按类型规整 config
        $data['config'] = $this->normalizeConfig($data['type'], $data['config'] ?? [], null);

        $storage = Storage::create($data)->fresh();
        $storage->loadCount('websites');
        return (new StorageResource($storage))
            ->additional(['message' => '添加成功'])
            ->response()
            ->setStatusCode(201);
    }

    public function update(Storage $storage, Request $request): StorageResource
    {
        $data = $this->validated($request);

        // config 只在请求真的带了它时才 normalize；否则保留旧值，避免"只改备注/access_url"
        // 之类的 partial update 把凭证清空
        if ($request->has('config')) {
            $data['config'] = $this->normalizeConfig(
                $data['type'],
                $data['config'] ?? [],
                $storage->config,
            );
        } else {
            unset($data['config']);
        }

        $storage->update($data);
        $storage->loadCount('websites');
        return new StorageResource($storage->fresh());
    }

    public function destroy(Storage $storage): JsonResponse
    {
        $storage->delete();
        return response()->json(['message' => '已删除']);
    }

    /**
     * 获取所有强制备份存储（供网站表单显示提示用）
     */
    public function mandatory(): JsonResponse
    {
        $list = Storage::mandatoryEnabled()->get(['id', 'name', 'type']);
        return response()->json(['data' => $list]);
    }

    /**
     * 连接测试：调用 rclone lsjson 用配置真实连接远端一次
     */
    public function test(Storage $storage): JsonResponse
    {
        $missing = $this->missingRequiredFields($storage->type, $storage->config);
        if (! empty($missing)) {
            $msg = '缺少必填字段: '.implode(', ', $missing);
            $storage->update([
                'last_test_status'  => Storage::TEST_FAILED,
                'last_test_message' => $msg,
                'last_tested_at'    => now(),
            ]);
            return response()->json(['ok' => false, 'message' => $msg], 422);
        }

        [$ok, $message] = $this->rcloneProbe($storage);

        $storage->update([
            'last_test_status'  => $ok ? Storage::TEST_OK : Storage::TEST_FAILED,
            'last_test_message' => $message,
            'last_tested_at'    => now(),
        ]);

        return response()->json([
            'ok'      => $ok,
            'message' => $message,
        ], $ok ? 200 : 422);
    }

    /**
     * 用 rclone 尝试连接一次远端。返回 [ok(bool), message(string)]
     */
    private function rcloneProbe(Storage $storage): array
    {
        if (! is_executable('/usr/bin/rclone') && trim((string) shell_exec('command -v rclone 2>/dev/null')) === '') {
            return [false, 'rclone 未安装在 Center 后端容器（请重建 bc-app 镜像）'];
        }

        $remote = 'probe_' . $storage->id;
        $tempFiles = [];
        $confPath = tempnam(sys_get_temp_dir(), 'rcloneconf_');
        $tempFiles[] = $confPath;
        try {
            $confContent = $this->buildRcloneConf($remote, $storage, $tempFiles);
            file_put_contents($confPath, $confContent);
            chmod($confPath, 0600);

            // S3 必须测到 bucket（因为凭证权限只在 bucket 层判定）；
            // SFTP/FTP/WebDAV 只测"能不能连"—— base_path 通常是给备份新建的目录，还不存在很正常，
            // Agent 实际上传时 rclone copyto 会自动 mkdir，测试阶段不必强求 base_path 已存在。
            if ($storage->type === Storage::TYPE_S3) {
                $basePath = trim((string) $storage->base_path);
                $bucket = $storage->config['bucket'] ?? '';
                $prefix = trim($bucket . '/' . ltrim($basePath, '/'), '/');
                $target = $remote . ':' . $prefix;
            } else {
                // SFTP: remote: = 家目录；FTP: remote: = 登录目录；WebDAV: remote: = URL 根
                $target = $remote . ':';
            }

            $cmd = 'rclone lsjson --config ' . escapeshellarg($confPath)
                . ' --max-depth 1 --dirs-only'
                . ' --contimeout 10s --timeout 15s --retries 1 --low-level-retries 1'
                . ' ' . escapeshellarg($target)
                . ' 2>&1';

            $output = [];
            $exitCode = 0;
            exec($cmd, $output, $exitCode);

            if ($exitCode === 0) {
                return [true, 'rclone 连接成功（能列出远端目录）'];
            }

            // 摘最后一条含"error"或"failed"的行
            $errLine = '';
            foreach (array_reverse($output) as $line) {
                $t = trim($line);
                if ($t === '' || stripos($t, 'NOTICE') !== false) continue;
                if (stripos($t, 'error') !== false || stripos($t, 'failed') !== false || stripos($t, 'fatal') !== false) {
                    $errLine = $t;
                    break;
                }
                if ($errLine === '') $errLine = $t;
            }
            // 去掉时间戳前缀、Failed to xxx 之类冗长前缀
            $errLine = preg_replace('/^\d{4}\/\d{2}\/\d{2}\s+\d{2}:\d{2}:\d{2}\s+/', '', $errLine ?: '');
            $errLine = preg_replace('/^.*Failed to (create|lsjson|new)[\w ]*:\s*/i', '', $errLine);
            $msg = $errLine !== '' ? $errLine : '连接失败（exit=' . $exitCode . '）';
            return [false, mb_substr($msg, 0, 480)];
        } finally {
            foreach ($tempFiles as $f) {
                if ($f && file_exists($f)) @unlink($f);
            }
        }
    }

    /**
     * 按 Storage 类型生成一段 rclone.conf 内容。&$tempFiles 收集临时私钥文件，caller 负责清理
     */
    private function buildRcloneConf(string $remote, Storage $storage, array &$tempFiles): string
    {
        $c = $storage->config;
        $lines = ["[{$remote}]"];
        switch ($storage->type) {
            case Storage::TYPE_SFTP:
                $lines[] = 'type = sftp';
                $lines[] = 'host = ' . ($c['host'] ?? '');
                if (! empty($c['port'])) $lines[] = 'port = ' . $c['port'];
                $lines[] = 'user = ' . ($c['user'] ?? '');
                if (! empty($c['password'])) $lines[] = 'pass = ' . $this->rcloneObscure($c['password']);
                if (! empty($c['private_key'])) {
                    $keyPath = tempnam(sys_get_temp_dir(), 'sshkey_');
                    $tempFiles[] = $keyPath;
                    file_put_contents($keyPath, $c['private_key']);
                    chmod($keyPath, 0600);
                    $lines[] = 'key_file = ' . $keyPath;
                }
                break;
            case Storage::TYPE_FTP:
                $lines[] = 'type = ftp';
                $lines[] = 'host = ' . ($c['host'] ?? '');
                if (! empty($c['port'])) $lines[] = 'port = ' . $c['port'];
                $lines[] = 'user = ' . ($c['user'] ?? '');
                if (! empty($c['password'])) $lines[] = 'pass = ' . $this->rcloneObscure($c['password']);
                if (! empty($c['tls'])) $lines[] = 'tls = true';
                if (! empty($c['explicit_tls'])) $lines[] = 'explicit_tls = true';
                if (! empty($c['no_check_certificate'])) $lines[] = 'no_check_certificate = true';
                break;
            case Storage::TYPE_WEBDAV:
                $lines[] = 'type = webdav';
                $lines[] = 'url = ' . ($c['url'] ?? '');
                if (! empty($c['vendor'])) $lines[] = 'vendor = ' . $c['vendor'];
                $lines[] = 'user = ' . ($c['user'] ?? '');
                if (! empty($c['password'])) $lines[] = 'pass = ' . $this->rcloneObscure($c['password']);
                break;
            case Storage::TYPE_S3:
                $lines[] = 'type = s3';
                $lines[] = 'provider = Other';
                $lines[] = 'endpoint = ' . ($c['endpoint'] ?? '');
                $lines[] = 'region = ' . ($c['region'] ?? 'us-east-1');
                $lines[] = 'access_key_id = ' . ($c['access_key_id'] ?? '');
                $lines[] = 'secret_access_key = ' . ($c['secret_access_key'] ?? '');
                if (! empty($c['path_style'])) $lines[] = 'force_path_style = true';
                // 关键：绕过 CreateBucket 权限探测（R2/COS/OSS 的 IAM 一般不给）
                $lines[] = 'no_check_bucket = true';
                break;
        }
        return implode("\n", $lines) . "\n";
    }

    /**
     * rclone 密码需要 obscure。这里用 rclone obscure 命令生成，失败时退回明文（大多数场景仍可用）
     */
    private function rcloneObscure(string $plain): string
    {
        $cmd = 'rclone obscure ' . escapeshellarg($plain) . ' 2>/dev/null';
        $out = trim((string) shell_exec($cmd));
        return $out !== '' ? $out : $plain;
    }

    /* -------------------------------------------------------- */

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:64'],
            'type' => ['required', Rule::in([
                Storage::TYPE_SFTP, Storage::TYPE_FTP, Storage::TYPE_WEBDAV, Storage::TYPE_S3,
            ])],
            'config' => ['nullable', 'array'],
            'base_path' => ['nullable', 'string', 'max:255'],
            // quota_bytes: 存储配额（字节），0 或 null = 不限
            'quota_bytes' => ['nullable', 'integer', 'min:0'],
            'status' => ['nullable', Rule::in([Storage::STATUS_ENABLED, Storage::STATUS_DISABLED])],
            'is_mandatory' => ['nullable', 'boolean'],
            'remark' => ['nullable', 'string', 'max:500'],
            // 存储管理面板 URL，比如 https://xxx.myshopify.com（可选）
            'access_url' => ['nullable', 'string', 'max:255', 'regex:/^https?:\/\//'],
        ]);
    }

    /**
     * 按 type 规整 config，剔除无关字段。编辑时如果字段是 ••••••••（占位符），
     * 表示"不变"，从旧配置里补回来。
     */
    private function normalizeConfig(string $type, array $input, ?array $old): array
    {
        $placeholder = '••••••••';
        $allowed = $this->allowedFields($type);
        $out = [];
        foreach ($allowed as $key) {
            $val = $input[$key] ?? null;
            if ($val === $placeholder && is_array($old) && array_key_exists($key, $old)) {
                $out[$key] = $old[$key];
                continue;
            }
            if ($val !== null && $val !== '') {
                $out[$key] = $val;
            }
        }
        return $out;
    }

    private function allowedFields(string $type): array
    {
        return match ($type) {
            Storage::TYPE_SFTP   => ['host', 'port', 'user', 'password', 'private_key'],
            Storage::TYPE_FTP    => ['host', 'port', 'user', 'password', 'tls', 'explicit_tls', 'no_check_certificate'],
            Storage::TYPE_WEBDAV => ['url', 'vendor', 'user', 'password'],
            Storage::TYPE_S3     => ['endpoint', 'region', 'bucket', 'access_key_id', 'secret_access_key', 'path_style'],
            default              => [],
        };
    }

    private function missingRequiredFields(string $type, array $config): array
    {
        $required = match ($type) {
            Storage::TYPE_SFTP   => ['host', 'user'],
            Storage::TYPE_FTP    => ['host', 'user', 'password'],
            Storage::TYPE_WEBDAV => ['url', 'user'],
            Storage::TYPE_S3     => ['endpoint', 'bucket', 'access_key_id', 'secret_access_key'],
            default              => [],
        };
        $missing = [];
        foreach ($required as $key) {
            if (empty($config[$key])) {
                $missing[] = $key;
            }
        }
        return $missing;
    }
}
