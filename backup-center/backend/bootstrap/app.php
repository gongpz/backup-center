<?php

use App\Http\Middleware\AgentAuth;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'agent' => AgentAuth::class,
        ]);
        // 我们在 Docker 里跑 nginx → php-fpm，nginx 是唯一前置代理。
        // 信任所有代理 IP，让 $request->ip() 返回 X-Forwarded-For 里的真实客户端 IP
        $middleware->trustProxies(at: '*');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
