<?php

namespace App\Http\Middleware;

use App\Models\Server;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AgentAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->header('X-Agent-Token')
            ?? $request->bearerToken();

        if (! $token) {
            return response()->json([
                'code' => 'AGENT_TOKEN_REQUIRED',
                'message' => '缺少 Agent Token',
            ], 401);
        }

        $server = Server::where('agent_token', $token)
            ->where('connect_type', Server::CONNECT_AGENT)
            ->first();

        if (! $server) {
            return response()->json([
                'code' => 'AGENT_TOKEN_INVALID',
                'message' => 'Agent Token 无效',
            ], 401);
        }

        $request->attributes->set('server', $server);

        return $next($request);
    }
}
