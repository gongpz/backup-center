<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\BackupLogResource;
use App\Models\AgentCommand;
use App\Models\BackupLog;
use App\Models\BackupUpload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class BackupLogController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = BackupLog::with([
                'website:id,name,domain',
                'server:id,name',
                'uploads.storage:id,name,type',
            ])
            ->orderByDesc('backup_at')
            ->orderByDesc('id');

        if ($keyword = $request->string('keyword')->trim()->toString()) {
            $query->where(function ($q) use ($keyword) {
                $q->where('filename', 'like', "%{$keyword}%")
                  ->orWhere('full_path', 'like', "%{$keyword}%");
            });
        }
        if ($status = $request->string('status')->toString()) {
            $query->where('status', $status);
        }
        if ($websiteId = $request->integer('website_id')) {
            $query->where('website_id', $websiteId);
        }
        if ($serverId = $request->integer('server_id')) {
            $query->where('server_id', $serverId);
        }
        if ($since = $request->date('since')) {
            $query->where('backup_at', '>=', $since);
        }
        if ($until = $request->date('until')) {
            $query->where('backup_at', '<=', $until);
        }

        return BackupLogResource::collection($query->paginate(20));
    }

    public function show(BackupLog $backupLog): BackupLogResource
    {
        $backupLog->load([
            'website:id,name,domain',
            'server:id,name',
            'uploads.storage:id,name,type',
        ]);
        return new BackupLogResource($backupLog);
    }

    public function destroy(BackupLog $backupLog): JsonResponse
    {
        $backupLog->delete();
        return response()->json(['message' => '已删除']);
    }

    /**
     * 远程恢复（最小可用版）。
     * 选择某条"已上传"的云端副本，下发 restore 指令给 Agent：
     * Agent 用 rclone 把云端归档 copy 回该备份原本所在的本地目录（dirname(full_path)）。
     * 不自动解包、不自动导入数据库 —— 由管理员手动处理（解压 tar / 导入 .sql）。
     */
    public function restore(BackupLog $backupLog, Request $request): JsonResponse
    {
        $data = $request->validate([
            'upload_id' => ['required', 'integer'],
        ]);

        // 必须是本备份记录、且已成功上传到该存储的副本
        $upload = $backupLog->uploads()
            ->where('id', $data['upload_id'])
            ->where('status', BackupUpload::STATUS_UPLOADED)
            ->first();

        if (! $upload || empty($upload->remote_path)) {
            return response()->json([
                'message' => '该备份在此存储上尚未成功上传，无法恢复',
            ], 422);
        }

        $server = $backupLog->server;
        if (! $server) {
            return response()->json(['message' => '找不到所属服务器，无法下发指令'], 422);
        }

        // 本地目标目录 = 原备份文件所在目录；文件名沿用远端归档名
        $localDir = $backupLog->full_path ? dirname($backupLog->full_path) : '';
        if ($localDir === '' || $localDir === '.') {
            return response()->json([
                'message' => '备份记录缺少本地路径，无法定位恢复目录',
            ], 422);
        }
        $filename = basename($upload->remote_path);

        // 防止堆积：同一服务器已有进行中的 restore 则冲突
        $conflict = AgentCommand::where('server_id', $server->id)
            ->where('type', AgentCommand::TYPE_RESTORE)
            ->whereIn('status', [
                AgentCommand::STATUS_PENDING,
                AgentCommand::STATUS_DISPATCHED,
            ])
            ->exists();
        if ($conflict) {
            return response()->json([
                'message' => '该服务器已有一条恢复任务在执行中，请稍后再试',
            ], 409);
        }

        $cmd = AgentCommand::create([
            'server_id' => $server->id,
            'type'      => AgentCommand::TYPE_RESTORE,
            'status'    => AgentCommand::STATUS_PENDING,
            'params'    => [
                'backup_log_id' => $backupLog->id,
                'upload_id'     => $upload->id,
                'remote_path'   => $upload->remote_path,
                'local_dir'     => $localDir,
                'filename'      => $filename,
            ],
        ]);

        return response()->json([
            'message'    => '已下发恢复指令，Agent 将在下次心跳（≤60s）从云端拉取该备份回本地目录',
            'command_id' => $cmd->id,
            'local_dir'  => $localDir,
            'filename'   => $filename,
        ]);
    }
}
