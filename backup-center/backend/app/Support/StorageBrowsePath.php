<?php

namespace App\Support;

use App\Models\Storage;
use App\Models\Website;

/**
 * VPS 备份文件浏览的路径纯函数集合（不触达任何远端）。
 *
 * 路径口径与 Agent 上传端逐字对齐（agent/internal/uploader/uploader.go 的
 * remoteTargetPath / sanitizePathSegment + agent/incremental.go 的快照布局）：
 * 站点目录名 = sanitizePathSegment(网站名)；S3 的 base_path 是 bucket 内 prefix
 * （无前导 /），SFTP/FTP/WebDAV 的 base_path 保留前导 /（绝对路径语义）。
 */
class StorageBrowsePath
{
    /** Go sanitizePathSegment 替换的九个危险字符（uploader.go:299-310） */
    private const UNSAFE_CHARS = ['/', '\\', ':', '*', '?', '"', '<', '>', '|'];

    /**
     * 规范化浏览路径：拒 ".." 段（返回 null）；collapse "//"；去尾 "/"；
     * S3 去前导 "/"（key 相对语义），其余保留前导 "/"（绝对路径语义）。
     * 空/null/纯斜杠 → ''（远端根）。
     */
    public static function normalize(?string $path, bool $isS3): ?string
    {
        if ($path === null) {
            return '';
        }
        // 任一路段等于 ".." 一律拒绝，防止上级目录逃逸
        foreach (explode('/', $path) as $segment) {
            if ($segment === '..') {
                return null;
            }
        }
        // "//" 折叠成 "/"，再去尾斜杠（纯斜杠归约为 '' = 远端根）
        $out = rtrim((string) preg_replace('#/+#', '/', $path), '/');
        return $isS3 ? ltrim($out, '/') : $out;
    }

    /** Go sanitizePathSegment 的 PHP 复刻：九个危险字符 → "_"，trim，空串 → "default"，中文原样保留 */
    public static function sanitizeSegment(string $name): string
    {
        $s = trim(str_replace(self::UNSAFE_CHARS, '_', $name));
        return $s === '' ? 'default' : $s;
    }

    /**
     * 计算初始浏览路径：
     *  - path 非空 → normalize(path)
     *  - websiteId 非空且 website.incremental_storage_id === storage.id
     *      → normalize(base_path 去尾斜杠 + "/" + sanitizeSegment(website.name))
     *  - 否则 → normalize(storage.base_path)
     * 返回 null 表示 path 非法（含 ".."）。
     */
    public static function resolveInitial(Storage $storage, ?string $path, ?int $websiteId): ?string
    {
        $isS3 = $storage->type === Storage::TYPE_S3;

        // 显式 path 优先（前端逐级下钻时层层传参）
        if ($path !== null && $path !== '') {
            return self::normalize($path, $isS3);
        }

        // 带了 website_id 且该站的增量备份目标正是当前存储：直达快照根
        // <base_path>/<站点目录名>（增量快照布局见 incremental.go:111-112）
        if ($websiteId !== null && $websiteId > 0) {
            $website = Website::find($websiteId);
            if ($website !== null && (int) $website->incremental_storage_id === (int) $storage->id) {
                $base = rtrim((string) $storage->base_path, '/');
                $site = self::sanitizeSegment((string) $website->name);
                return self::normalize($base !== '' ? $base . '/' . $site : $site, $isS3);
            }
        }

        // 否则回落到存储自身的 base_path（S3 即 bucket 内 prefix）
        return self::normalize((string) $storage->base_path, $isS3);
    }

    /**
     * 生成 rclone target（加密走 _c 包裹层）：
     *  S3 未加密：bc_<id>:<bucket>/<path>（path 空则仅 bucket）
     *  S3 加密：  bc_<id>_c:<path>（bucket 已在 crypt section 的 remote 里）
     *  其余：     bc_<id>:<path> / bc_<id>_c:<path>
     */
    public static function target(Storage $storage, string $path): string
    {
        $remote = 'bc_' . $storage->id;

        $enc = $storage->config['encryption'] ?? [];
        $encrypted = ! empty($enc['enabled']) && ($enc['key_mode'] ?? 'center') === 'center';
        if ($encrypted) {
            return $remote . '_c:' . $path;
        }

        if ($storage->type === Storage::TYPE_S3) {
            // S3 未加密：bucket 作为 path 第一段（与 Agent 端 remoteTargetPath 一致）
            $bucket = trim((string) ($storage->config['bucket'] ?? ''), '/');
            $suffix = ltrim($path, '/');
            if ($bucket === '') {
                return $remote . ':' . $suffix;
            }
            return $remote . ':' . $bucket . ($suffix !== '' ? '/' . $suffix : '');
        }

        return $remote . ':' . $path;
    }

    /** 父目录（去掉最后一段）；根（'' 或单段）返回 null */
    public static function parent(?string $path): ?string
    {
        if ($path === null) {
            return null;
        }
        $p = rtrim($path, '/');
        if ($p === '') {
            return null;
        }
        $pos = strrpos($p, '/');
        // 单段路径（无 '/'，或 '/' 仅在首位如 '/backup'）即根，没有上一级
        if ($pos === false || $pos === 0) {
            return null;
        }
        return substr($p, 0, $pos);
    }

    /**
     * 目录 + 相对段拼接（S3 的 base 无前导斜杠，非 S3 保留）：
     * base 为空（远端根）时原样返回相对段；否则 rtrim 后拼一段。
     */
    public static function join(string $base, string $relative): string
    {
        $relative = ltrim($relative, '/');
        $base = rtrim($base, '/');
        return $base === '' ? $relative : $base . '/' . $relative;
    }

    /**
     * lsjson 原始条目 → 前端条目：目录优先、同组按名称排序；截断至 $limit。
     *
     * $base 是当前所列目录（规范化后的远端路径）。rclone lsjson 官方文档明确：
     * 不加 --recursive 时 "the Path will always be the same as Name" —— 拿到的只是
     * 条目名，必须把 $base 拼回，前端下钻传 path 才能定位到正确层级。
     *
     * @return array{entries: list<array>, dir_count: int, file_count: int, truncated: bool}
     */
    public static function parseEntries(array $raw, string $base = '', int $limit = 500): array
    {
        $dirs = [];
        $files = [];

        foreach ($raw as $item) {
            if (! is_array($item)) {
                continue;
            }
            $name = (string) ($item['Name'] ?? '');
            $path = (string) ($item['Path'] ?? '');
            $isDir = (bool) ($item['IsDir'] ?? false);
            // lsjson 对单层列目录时 Path 可能为空串，用 Name 兜底
            $relative = $path !== '' ? $path : $name;
            $entry = [
                'name'     => $name,
                // 拼回当前目录：Path 只是相对所列目录的名字（无 --recursive 时 === Name）
                'path'     => self::join($base, $relative),
                'is_dir'   => $isDir,
                // 目录的 Size 忽略（lsjson 常给 -1）
                'size'     => $isDir ? null : (int) ($item['Size'] ?? 0),
                'modified' => $item['ModTime'] ?? null,
            ];
            if ($isDir) {
                $dirs[] = $entry;
            } else {
                $files[] = $entry;
            }
        }

        usort($dirs, static fn ($a, $b) => strcmp($a['name'], $b['name']));
        usort($files, static fn ($a, $b) => strcmp($a['name'], $b['name']));

        // dir_count/file_count 记全量（不随截断丢失），前端提示条据此展示
        $all = array_merge($dirs, $files);
        $truncated = count($all) > $limit;

        return [
            'entries'    => array_values($truncated ? array_slice($all, 0, $limit) : $all),
            'dir_count'  => count($dirs),
            'file_count' => count($files),
            'truncated'  => $truncated,
        ];
    }
}
