<?php

use App\Http\Controllers\Api\AgentController;
use App\Http\Controllers\Api\AgentUpdateController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BackupLogController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\NotificationSettingController;
use App\Http\Controllers\Api\ResourcePoolController;
use App\Http\Controllers\Api\ServerController;
use App\Http\Controllers\Api\StorageController;
use App\Http\Controllers\Api\SystemSettingController;
use App\Http\Controllers\Api\WebsiteController;
use Illuminate\Support\Facades\Route;

/* ---------- 用户认证 ---------- */
Route::post('/auth/login', [AuthController::class, 'login']);
// 首次安装：没有任何用户时创建管理员（系统自己判断，User 表非空即 403）
Route::get('/auth/bootstrap-status', [AuthController::class, 'bootstrapStatus']);
Route::post('/auth/bootstrap', [AuthController::class, 'bootstrap']);

/* ---------- Agent 通信（Token via header, 不是 Sanctum） ---------- */
Route::prefix('agent')->middleware('agent')->group(function () {
    Route::post('/register', [AgentController::class, 'register']);
    Route::post('/heartbeat', [AgentController::class, 'heartbeat']);
    Route::get('/websites', [AgentController::class, 'websites']);
    Route::post('/report', [AgentController::class, 'report']);
    Route::post('/upload-report', [AgentController::class, 'uploadReport']);
    Route::post('/command-result', [AgentController::class, 'commandResult']);
    Route::post('/command-progress', [AgentController::class, 'commandProgress']);
});

/* ---------- Web 管理后台（Sanctum token） ---------- */
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);

    // 用户资料
    Route::put('/auth/profile', [AuthController::class, 'updateProfile']);
    Route::put('/auth/password', [AuthController::class, 'updatePassword']);
    Route::get('/auth/login-logs', [AuthController::class, 'loginLogs']);

    // 系统设置（系统名、时区等）
    Route::get('/system-settings', [SystemSettingController::class, 'show']);
    Route::put('/system-settings', [SystemSettingController::class, 'update']);
    Route::get('/system/footer-stats', [SystemSettingController::class, 'footerStats']);

    // Dashboard 汇总
    Route::get('/dashboard', [DashboardController::class, 'index']);

    // Agent 版本 + 升级下发（注意：bulk 路由必须在 apiResource 之前）
    Route::get('/agent-latest-version', [AgentUpdateController::class, 'latestVersion']);
    Route::post('/servers/bulk-agent-update', [AgentUpdateController::class, 'bulkDispatch']);
    Route::post('/servers/{server}/agent-update', [AgentUpdateController::class, 'dispatchOne']);

    // 服务器管理
    Route::apiResource('servers', ServerController::class);
    Route::post('/servers/{server}/regenerate-token', [ServerController::class, 'regenerateToken']);

    // 网站管理
    Route::apiResource('websites', WebsiteController::class);
    Route::post('/websites/{website}/sync-now', [WebsiteController::class, 'syncNow']);

    // 系统一键备份（把 Backup Center 自己打包上传到强制备份存储）
    Route::post('/system/backup', [SystemSettingController::class, 'backupNow']);

    // 备份记录
    Route::get('/backup-logs', [BackupLogController::class, 'index']);
    Route::get('/backup-logs/{backupLog}', [BackupLogController::class, 'show']);
    Route::post('/backup-logs/{backupLog}/restore', [BackupLogController::class, 'restore']);
    Route::get('/backup-logs/{backupLog}/restore-status', [BackupLogController::class, 'restoreStatus']);
    Route::delete('/backup-logs/{backupLog}', [BackupLogController::class, 'destroy']);

    // 存储（Rclone 配置）
    Route::apiResource('storages', StorageController::class);
    Route::post('/storages/{storage}/test', [StorageController::class, 'test']);
    Route::get('/storages-mandatory', [StorageController::class, 'mandatory']);

    // 资源池（COS/OSS 对象存储，用于 Agent 二进制分发 + 用户上传的静态资源）
    Route::apiResource('resource-pools', ResourcePoolController::class);
    Route::post('/resource-pools/{resourcePool}/test', [ResourcePoolController::class, 'test']);
    Route::get('/resource-pools/{resourcePool}/files', [ResourcePoolController::class, 'listFiles']);
    Route::post('/resource-pools/{resourcePool}/files', [ResourcePoolController::class, 'uploadFile']);
    Route::post('/resource-pools/{resourcePool}/files/remark', [ResourcePoolController::class, 'updateFileRemark']);
    Route::delete('/resource-pools/{resourcePool}/files', [ResourcePoolController::class, 'deleteFile']);


    // 通知设置
    Route::get('/notification-settings', [NotificationSettingController::class, 'show']);
    Route::put('/notification-settings', [NotificationSettingController::class, 'update']);
    Route::post('/notification-settings/test', [NotificationSettingController::class, 'test']);
});
