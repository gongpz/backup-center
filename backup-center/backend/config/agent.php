<?php

return [
    // 分发给用户的 Agent 最新版本号（部署新 agent-dist 时同步 bump）
    'latest_version' => env('BACKUP_AGENT_LATEST_VERSION', '0.14.3'),
];
