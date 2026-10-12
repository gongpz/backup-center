<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\BackupLogResource;
use App\Models\BackupLog;
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
}
