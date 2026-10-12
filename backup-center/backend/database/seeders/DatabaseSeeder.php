<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // 不再植入任何默认账号 —— 公网暴露默认口令是重大风险。
        // 首次部署后访问登录页，若系统检测到 User 表为空，会引导创建第一个管理员
        // （POST /api/auth/bootstrap · 表非空即 403）。
    }
}
