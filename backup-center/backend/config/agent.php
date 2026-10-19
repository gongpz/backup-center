<?php

return [
    // 分发给用户的 Agent 最新版本号（部署新 agent-dist 时同步 bump）
    // 0.14.4：tar --unlink-first 修复（advanced 恢复解压 .user.ini EEXIST 假失败）
    'latest_version' => env('BACKUP_AGENT_LATEST_VERSION', '0.14.4'),
];
