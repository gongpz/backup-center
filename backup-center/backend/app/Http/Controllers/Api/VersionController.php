<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class VersionController extends Controller
{
    /**
     * 返回当前 Center 部署包的版本信息。
     *
     * 数据来自构建时由 scripts/build-release.sh 写入的 backend/VERSION。
     * 该文件同时被一键部署脚本打印、前端页脚读取，解决"装了哪个版本看不出来"的问题：
     * 之前页脚把系统版本硬编码成 v1.1.0，导致即便部署了新版，界面仍显示旧版本号。
     */
    public function show(): JsonResponse
    {
        $map = [
            'BC_VERSION'      => 'version',
            'BC_COMMIT'       => 'commit',
            'BC_COMMIT_SHORT' => 'commit_short',
            'BC_BUILD_DATE'   => 'build_date',
            'BC_CHANNEL'      => 'channel',
        ];

        $data = [
            'version'      => null,
            'commit'       => null,
            'commit_short' => null,
            'build_date'   => null,
            'channel'      => null,
        ];

        $path = base_path('VERSION');
        if (is_file($path)) {
            foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                if (strpos($line, '=') === false) {
                    continue;
                }
                [$key, $value] = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value);
                if (isset($map[$key])) {
                    $data[$map[$key]] = $value;
                }
            }
        }

        return response()->json($data);
    }
}
