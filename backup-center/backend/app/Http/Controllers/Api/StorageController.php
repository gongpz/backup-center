<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\StorageResource;
use App\Models\Storage;
use App\Services\RcloneConfBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class StorageController extends Controller
{
    /** 加密密钥托管模式：center=中心托管（密码随 config 下发 Agent） */
    public const ENC_KEY_MODE_CENTER = 'center';
    /** agent_local=Agent 本地密钥（/etc/backup-agent/backup.key，中心不保存密码） */
    public const ENC_KEY_MODE_AGENT_LOCAL = 'agent_local';

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
     *
     * 加密存储（encryption.enabled && key_mode=center）升级为 round-trip 验证：
     * 写已知内容 → copyto 到 crypt remote → 下载回来 → 内容比对 → 清理探测文件。
     * 加密密码错误时下载解密失败即探测失败（故意设计：证明密码可用）。
     * key_mode=agent_local 时中心无密码，只探测底层 remote。
     */
    private function rcloneProbe(Storage $storage): array
    {
        if (! RcloneConfBuilder::available()) {
            return [false, 'rclone 未安装在 Center 后端容器（请重建 bc-app 镜像）'];
        }

        $remote = 'probe_' . $storage->id;
        $tempFiles = [];
        $confPath = tempnam(sys_get_temp_dir(), 'rcloneconf_');
        $tempFiles[] = $confPath;
        try {
            // obscure 失败（如容器里 rclone 版本异常）时禁止明文降级：跳过该存储并告警
            $warnings = [];
            $confContent = $this->buildRcloneConf($remote, $storage, $tempFiles, $warnings);
            file_put_contents($confPath, $confContent);
            chmod($confPath, 0600);

            if ($warnings !== []) {
                return [false, implode('；', $warnings)];
            }

            $enc = $storage->config['encryption'] ?? [];
            $encEnabled = ! empty($enc['enabled']);
            $encMode = $enc['key_mode'] ?? self::ENC_KEY_MODE_CENTER;

            if ($encEnabled && $encMode === self::ENC_KEY_MODE_CENTER) {
                return $this->cryptRoundTripProbe($confPath, $remote, $storage, $tempFiles);
            }

            [$ok, $message] = $this->probeList($confPath, $remote, $storage);

            // agent_local：底层 remote 连通只证明存储可用，加密密钥在 Agent 主机上，
            // 中心无密码、无法验证密码正确性
            if ($ok && $encEnabled && $encMode === self::ENC_KEY_MODE_AGENT_LOCAL) {
                $message .= '；加密密钥由 Agent 本地管理，未验证密码正确性';
            }
            return [$ok, $message];
        } finally {
            foreach ($tempFiles as $f) {
                if ($f && file_exists($f)) @unlink($f);
            }
        }
    }

    /**
     * 加密存储的 round-trip 验证：写已知内容 → copyto 到 probe_<id>_c:<随机名> →
     * 下载回来 → 内容比对 → deletefile 清理。密码错误时下载解密失败即失败。
     *
     * @return array{0: bool, 1: string}
     */
    private function cryptRoundTripProbe(string $confPath, string $remote, Storage $storage, array &$tempFiles): array
    {
        // 探测文件落在 base_path。S3 的 bucket 已放进 crypt remote 的 base（buildRcloneConf：remote=probe_<id>:bucket），
        // 所以这里 path 只放 base_path，绝不能再带 bucket —— 否则 crypt 会把 bucket 名一起加密，
        // S3 把加密后的名字当 bucket → object not found。
        $prefix = trim((string) $storage->base_path, '/');
        $rand = bin2hex(random_bytes(6));
        $target = $remote . '_c:' . ($prefix !== '' ? $prefix . '/' : '') . $rand;

        $payload = 'bc-rclone-crypt-probe-' . $rand;
        $localFile = tempnam(sys_get_temp_dir(), 'bcprobe_');
        $tempFiles[] = $localFile;
        file_put_contents($localFile, $payload);
        $backFile = tempnam(sys_get_temp_dir(), 'bcprobe_back_');
        $tempFiles[] = $backFile;

        $common = '--config ' . escapeshellarg($confPath)
            . ' --contimeout 10s --timeout 30s --retries 1 --low-level-retries 1';

        // 1) 上传（经 crypt 加密后落底层 remote）
        exec('rclone copyto ' . $common . ' ' . escapeshellarg($localFile) . ' ' . escapeshellarg($target) . ' 2>&1', $out, $code);
        if ($code !== 0) {
            return [false, '加密上传失败：' . $this->rcloneErrorLine($out, $code)];
        }

        // 2) 下载回来（经 crypt 解密；密码错误时这一步失败）
        exec('rclone copyto ' . $common . ' ' . escapeshellarg($target) . ' ' . escapeshellarg($backFile) . ' 2>&1', $out, $code);
        if ($code !== 0) {
            $this->cleanupProbeFile($confPath, $target);
            return [false, '加密下载/解密失败（请检查加密密码）：' . $this->rcloneErrorLine($out, $code)];
        }

        // 3) 内容比对
        if (file_get_contents($backFile) !== $payload) {
            $this->cleanupProbeFile($confPath, $target);
            return [false, '解密后的内容与原文不一致，加密配置可能有误'];
        }

        // 4) 清理探测文件
        $this->cleanupProbeFile($confPath, $target);

        return [true, '加密 round-trip 验证成功（上传→下载→内容比对一致），云端只落密文'];
    }

    /** 删除 round-trip 探测遗留的远端文件；失败只记日志，不影响探测结论 */
    private function cleanupProbeFile(string $confPath, string $target): void
    {
        exec('rclone deletefile --config ' . escapeshellarg($confPath)
            . ' --timeout 30s ' . escapeshellarg($target) . ' 2>&1', $out, $code);
        if ($code !== 0) {
            Log::warning('rclone 探测文件清理失败', ['target' => $target]);
        }
    }

    /**
     * 底层 remote 连通性探测：lsjson 列一层目录。
     * S3 必须测到 bucket（凭证权限只在 bucket 层判定）；SFTP/FTP/WebDAV 只测"能不能连"——
     * base_path 通常是给备份新建的目录，还不存在很正常，Agent 实际上传时 rclone copyto
     * 会自动 mkdir，测试阶段不必强求 base_path 已存在。
     *
     * @return array{0: bool, 1: string}
     */
    private function probeList(string $confPath, string $remote, Storage $storage): array
    {
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
        return [false, $this->rcloneErrorLine($output, $exitCode)];
    }

    /** 从 rclone 输出里摘一条可读的错误行（去时间戳/冗长前缀，截断） */
    private function rcloneErrorLine(array $output, int $exitCode): string
    {
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
        return mb_substr($msg, 0, 480);
    }

    /**
     * 按 Storage 类型生成一段 rclone.conf 内容。&$tempFiles 收集临时私钥文件，caller 负责清理。
     * &$warnings 收集"无法安全生成配置"的原因（如 obscure 失败）——出现 warning 时调用方
     * 必须跳过该存储，绝不降级明文。
     *
     * 实际构建逻辑收敛在 RcloneConfBuilder（与保留期清理兜底共用一份），这里只做
     * 返回结构到引用参数的适配，保持既有调用方无感。
     */
    private function buildRcloneConf(string $remote, Storage $storage, array &$tempFiles, array &$warnings = []): string
    {
        $r = RcloneConfBuilder::build($storage, $remote);
        $tempFiles = array_merge($tempFiles, $r['tempFiles']);
        $warnings = array_merge($warnings, $r['warnings']);
        return $r['conf'];
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
            if ($key === 'encryption') continue;   // 嵌套结构，下面单独处理
            $val = $input[$key] ?? null;
            if ($val === $placeholder && is_array($old) && array_key_exists($key, $old)) {
                $out[$key] = $old[$key];
                continue;
            }
            if ($val !== null && $val !== '') {
                $out[$key] = $val;
            }
        }

        // encryption：请求完全没带这个键、但旧配置有时，以旧配置为准（避免 partial update
        // （例如只改备注）把加密悄悄关掉）；两边都没有时补一份全默认块，保证下游形状稳定。
        $oldEnc = is_array($old['encryption'] ?? null) ? $old['encryption'] : null;
        if (! array_key_exists('encryption', $input) && $oldEnc !== null) {
            $out['encryption'] = $this->normalizeEncryption($oldEnc, null, $placeholder);
            return $out;
        }
        $inEnc = is_array($input['encryption'] ?? null) ? $input['encryption'] : [];
        $out['encryption'] = $this->normalizeEncryption($inEnc, $oldEnc, $placeholder);

        return $out;
    }

    /**
     * 规整并校验 encryption 块（rclone crypt 包裹层参数）。
     *
     * @param array      $in      请求里的 encryption（可能缺字段）
     * @param array|null $oldEnc  旧配置里的 encryption（编辑回填用）
     * @param string     $placeholder 密码占位符，等于"不改"
     */
    private function normalizeEncryption(array $in, ?array $oldEnc, string $placeholder): array
    {
        $enabled = $this->truthy($in['enabled'] ?? false);
        $keyMode = (string) ($in['key_mode'] ?? ($oldEnc['key_mode'] ?? self::ENC_KEY_MODE_CENTER));
        $filenameEnc = (string) ($in['filename_encryption'] ?? ($oldEnc['filename_encryption'] ?? 'standard'));
        $dirEnc = $this->truthy($in['directory_name_encryption'] ?? ($oldEnc['directory_name_encryption'] ?? true));

        // 密码缺失/占位符都回落到旧值；真的没有就是空串（下面按规则决定是否报错）
        $password = array_key_exists('password', $in)
            ? (string) ($in['password'] ?? '')
            : (string) ($oldEnc['password'] ?? '');
        if ($password === $placeholder && $oldEnc !== null) {
            $password = (string) ($oldEnc['password'] ?? '');
        }

        if ($enabled) {
            if (! in_array($keyMode, [self::ENC_KEY_MODE_CENTER, self::ENC_KEY_MODE_AGENT_LOCAL], true)) {
                throw ValidationException::withMessages([
                    'config.encryption.key_mode' => '加密密钥模式必须是 center（中心托管）或 agent_local（Agent 本地密钥）',
                ]);
            }
            if (! in_array($filenameEnc, ['standard', 'off'], true)) {
                throw ValidationException::withMessages([
                    'config.encryption.filename_encryption' => '文件名加密必须是 standard 或 off',
                ]);
            }
            if ($dirEnc && $filenameEnc !== 'standard') {
                throw ValidationException::withMessages([
                    'config.encryption.directory_name_encryption' => '开启目录名加密必须先开启文件名加密（filename_encryption=standard）',
                ]);
            }
            if ($keyMode === self::ENC_KEY_MODE_AGENT_LOCAL) {
                // T2：密钥在 Agent 主机（/etc/backup-agent/backup.key），中心永不保存密码
                $password = '';
            } elseif ($password === '') {
                throw ValidationException::withMessages([
                    'config.encryption.password' => '中心托管模式（key_mode=center）必须填写加密密码',
                ]);
            }
        } else {
            if ($keyMode === self::ENC_KEY_MODE_AGENT_LOCAL) {
                $password = '';
            }
        }

        return [
            'enabled'                    => $enabled,
            'key_mode'                   => $keyMode,
            'password'                   => $password,
            'filename_encryption'        => $filenameEnc,
            'directory_name_encryption'  => $dirEnc,
        ];
    }

    /** 布尔化：JSON 传过来的 true/false，表单也是 '1'/'0'/'true' 这类字符串 */
    private function truthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'on', 'yes'], true);
        }
        return (bool) $value;
    }

    private function allowedFields(string $type): array
    {
        return match ($type) {
            Storage::TYPE_SFTP   => ['host', 'port', 'user', 'password', 'private_key', 'encryption'],
            Storage::TYPE_FTP    => ['host', 'port', 'user', 'password', 'tls', 'explicit_tls', 'no_check_certificate', 'encryption'],
            Storage::TYPE_WEBDAV => ['url', 'vendor', 'user', 'password', 'encryption'],
            Storage::TYPE_S3     => ['endpoint', 'region', 'bucket', 'access_key_id', 'secret_access_key', 'path_style', 'encryption'],
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
