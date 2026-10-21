<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Storage;
use App\Models\Website;
use App\Services\RcloneConfBuilder;
use App\Support\StorageBrowsePath;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 存储文件浏览（只读）：Center 侧直连 rclone 列出备份目标上的文件 + 递归空间用量。
 * 技术路线与 ResourcePoolController::rcloneList 一致：Center 容器自带 rclone，
 * 且持 Storage config 解密能力，无需经 Agent。
 */
class StorageFileController extends Controller
{
    /** GET /api/storages/{storage}/files?path=&website_id= */
    public function index(Request $request, Storage $storage): JsonResponse
    {
        // 1) Center 容器内没有 rclone 时直接说明原因（前端提示重建 bc-app 镜像）
        if (! RcloneConfBuilder::available()) {
            return response()->json([
                'ok'      => false,
                'message' => 'Center 后端容器内未检测到 rclone（请重建 bc-app 镜像）',
            ]);
        }

        $isS3 = $storage->type === Storage::TYPE_S3;

        // 2) path 非法（含 ".." 上级目录逃逸）
        $path = $request->input('path');
        $path = is_string($path) ? $path : null;
        if ($path !== null && $path !== '' && StorageBrowsePath::normalize($path, $isS3) === null) {
            return response()->json(['message' => '路径不合法（不允许 .. 上级目录）'], 422);
        }

        // 3) website_id 存在时：该网站的增量备份目标必须是当前存储
        $websiteId = null;
        if ($request->filled('website_id')) {
            $websiteId = (int) $request->input('website_id');
            $website = Website::find($websiteId);
            if ($website === null || (int) $website->incremental_storage_id !== (int) $storage->id) {
                return response()->json(['message' => '该网站的增量备份目标不是当前存储'], 422);
            }
        }

        // 4) 计算初始浏览路径（为 null 即 path 非法，同分支 2 文案）
        $path = StorageBrowsePath::resolveInitial($storage, $path, $websiteId);
        if ($path === null) {
            return response()->json(['message' => '路径不合法（不允许 .. 上级目录）'], 422);
        }

        // 5) Agent 本地密钥加密：中心无密码、无法解密文件名，明确拒绝浏览
        $enc = $storage->config['encryption'] ?? [];
        if (! empty($enc['enabled']) && ($enc['key_mode'] ?? 'center') === 'agent_local') {
            return response()->json([
                'ok'      => false,
                'message' => '该存储启用了「Agent 本地密钥」加密，中心无法解密文件名，暂不支持浏览',
            ]);
        }

        $tempFiles = [];
        try {
            // 6) 构建 rclone conf（obscure 失败 → 拒绝浏览，绝不明文降级）
            $r = RcloneConfBuilder::build($storage);
            $tempFiles = array_merge($tempFiles, $r['tempFiles']);
            if ($r['warnings'] !== []) {
                return response()->json([
                    'ok'      => false,
                    'message' => implode('；', $r['warnings']),
                ]);
            }

            $confPath = tempnam(sys_get_temp_dir(), 'browse_rcloneconf_');
            $tempFiles[] = $confPath;
            file_put_contents($confPath, $r['conf']);
            chmod($confPath, 0600);

            // 7) 列当前层（只一层，进入子目录由前端换 path 重新请求）
            $target = StorageBrowsePath::target($storage, $path);
            $cmd = 'rclone lsjson --config ' . escapeshellarg($confPath)
                . ' --max-depth 1 --contimeout 10s --timeout 30s --retries 1'
                . ' ' . escapeshellarg($target)
                . ' 2>&1';
            $output = [];
            $exitCode = 0;
            exec($cmd, $output, $exitCode);
            if ($exitCode !== 0) {
                return response()->json([
                    'ok'      => false,
                    'message' => $this->errorLine($output, $exitCode),
                ]);
            }

            // 8) 成功：转成前端条目结构（目录优先 / 排序 / 500 条截断）。
            //    条目 path 拼上当前目录：lsjson 无 --recursive 时 Path 只是裸名字，
            //    前端下钻要拿完整远端路径重新请求（parseEntries 内拼接）。
            $decoded = $this->decodeLsjson($output);
            if ($decoded === null) {
                // 2>&1 混入的 NOTICE 行可能污染 JSON——解析失败时明确报错，
                // 绝不静默显示「空目录」（那会让用户误以为没有备份）
                return response()->json([
                    'ok'      => false,
                    'message' => '目录列表解析失败（rclone 输出异常，请重试或检查存储连接）',
                ]);
            }
            $parsed = StorageBrowsePath::parseEntries($decoded, $path, 500);

            // 9) best-effort 递归用量统计：失败或超时只降级 null，不影响主响应
            [$recursiveSize, $recursiveCount] = $this->recursiveStats($confPath, $target);

            return response()->json([
                'ok' => true,
                'storage' => [
                    'id'        => $storage->id,
                    'name'      => $storage->name,
                    'type'      => $storage->type,
                    // agent_local 走不到这里；此位表示经 crypt 包裹层（_c）浏览
                    'encrypted' => ! empty($enc['enabled']) && ($enc['key_mode'] ?? 'center') === 'center',
                    'base_path' => $storage->base_path,
                ],
                'path'            => $path,
                'parent'          => StorageBrowsePath::parent($path),
                'entries'         => $parsed['entries'],
                'truncated'       => $parsed['truncated'],
                'dir_count'       => $parsed['dir_count'],
                'file_count'      => $parsed['file_count'],
                'recursive_size'  => $recursiveSize,
                'recursive_count' => $recursiveCount,
            ]);
        } finally {
            // 10) 统一清理 conf + SSH 私钥临时文件
            foreach ($tempFiles as $f) {
                if ($f && file_exists($f)) {
                    @unlink($f);
                }
            }
        }
    }

    /**
     * 递归空间用量：rclone size 输出
     * "Total objects: 152\nTotal size: 145.5 Mi (152838201 Bytes)"，
     * 解析法与 Agent 端 remoteDirSize（incremental.go:329-354）一致。
     * 失败/解析不到 → [null, null]，仅影响展示，绝不影响主响应。
     *
     * @return array{0: int|null, 1: int|null} [字节数, 对象数]
     */
    private function recursiveStats(string $confPath, string $target): array
    {
        $cmd = 'rclone size --config ' . escapeshellarg($confPath)
            . ' --contimeout 10s --timeout 30s'
            . ' ' . escapeshellarg($target)
            . ' 2>&1';
        $output = [];
        $exitCode = 0;
        exec($cmd, $output, $exitCode);
        if ($exitCode !== 0) {
            return [null, null];
        }

        $size = null;
        $count = null;
        foreach ($output as $line) {
            $t = trim($line);
            if (str_starts_with($t, 'Total objects:')) {
                $count = (int) str_replace(',', '', trim(substr($t, strlen('Total objects:'))));
            } elseif (str_starts_with($t, 'Total size:')) {
                // 形如 "Total size: 45.2 Mi (47382738 Bytes)"，取括号内字节数
                if (preg_match('/\((\d[\d,]*)\s+Bytes\)/i', $t, $m) === 1) {
                    $size = (int) str_replace(',', '', $m[1]);
                }
            }
        }

        return [$size, $count];
    }

    /** 从 rclone 输出里摘一条可读的错误行（去时间戳/冗长前缀，截断；复刻 StorageController::rcloneErrorLine） */
    private function errorLine(array $output, int $code): string
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
        $msg = $errLine !== '' ? $errLine : '连接失败（exit=' . $code . '）';
        // 目录不存在时补一句人话：多为该位置从未成功备份过（如首次增量备份前的快照根）
        if (stripos($msg, 'directory not found') !== false) {
            $msg .= '（目录不存在：该位置可能从未成功备份过）';
        }
        return mb_substr($msg, 0, 480);
    }

    /**
     * 解析 lsjson 输出。exec 用了 2>&1，stderr 的 NOTICE 行会混进 stdout，
     * 直接 json_decode 整个输出会失败——先按整体解，失败再抽取首个 '[' 到
     * 末个 ']' 之间的数组体。仍失败返回 null（调用方明确报错，不静默显示空目录）。
     */
    private function decodeLsjson(array $output): ?array
    {
        $raw = implode("\n", $output);
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return $decoded;
        }
        $start = strpos($raw, '[');
        $end = strrpos($raw, ']');
        if ($start === false || $end === false || $end <= $start) {
            return null;
        }
        $decoded = json_decode(substr($raw, $start, $end - $start + 1), true);

        return is_array($decoded) ? $decoded : null;
    }
}
