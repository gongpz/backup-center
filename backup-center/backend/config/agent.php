<?php

return [
    // 分发给用户的 Agent 最新版本号（部署新 agent-dist 时同步 bump）
    // 0.14.4：tar --unlink-first 修复（advanced 恢复解压 .user.ini EEXIST 假失败）
    // 0.14.5：自主备份上报 batch_code（指令 ID），高级模式按编码精确分组
    // 0.15.0：增量备份（rsync --link-dest 硬链快照）+ 快照恢复 + retention 目录 purge
    // 0.15.1：增量备份前用 rclone mkdir 预建站点根目录，首次备份到全新 base 不再必失败
    // 0.15.2：保留期清理（cleanup_retention）的 rclone 调用加单条超时（10m），
    //         后端卡死不再永久挂起清理指令与 Agent 命令循环
    'latest_version' => env('BACKUP_AGENT_LATEST_VERSION', '0.15.2'),
];
