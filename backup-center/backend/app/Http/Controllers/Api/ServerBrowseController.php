<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AgentCommand;
use App\Models\Server;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 备份目录浏览（v1.1.2 / Agent v0.17.0）：
 * 网页任务表单里的「浏览」按钮 → 下发 browse_dir 指令 → Agent（3 秒快速拉取通道）
 * 列出目录项元数据上报 → 前端轮询 GET /api/agent-commands/{id} 拿结果（AgentCommandController::show）。
 *
 * 安全口径与 pack_backup/restore 一致：params 只带 path（不带凭据），
 * Agent 只返回目录项元数据（name/is_dir/size/mtime），不读文件内容。
 */
class ServerBrowseController extends Controller
{
    public function store(Request $request, Server $server): JsonResponse
    {
        if (! $server || $server->connect_type !== Server::CONNECT_AGENT) {
            return response()->json(['message' => '该服务器不是 Agent 模式，无法浏览目录'], 422);
        }

        $data = $request->validate([
            // 浏览起始路径：空 → 根 /；不做存在性校验（存在性由 Agent os.Stat 判定并回报）
            'path' => ['nullable', 'string', 'max:4096'],
        ]);
        $path = trim((string) ($data['path'] ?? ''));
        if ($path === '') {
            $path = '/';
        }
        // 简单规约：拒绝 ../ 上跳段（与 StorageBrowsePath::normalize 的防逃逸口径一致，
        // 浏览语义上无需上跳——从 / 起步逐级下钻）
        foreach (explode('/', $path) as $segment) {
            if ($segment === '..') {
                return response()->json(['message' => '路径不能包含 .. 段'], 422);
            }
        }

        // 防指令堆积：同一服务器还挂着 pending 的 browse_dir 时直接作废旧指令，
        // 复用新指令（浏览是交互操作，旧请求已无人等待）。
        AgentCommand::where('server_id', $server->id)
            ->where('type', AgentCommand::TYPE_BROWSE_DIR)
            ->where('status', AgentCommand::STATUS_PENDING)
            ->update([
                'status'      => AgentCommand::STATUS_FAILED,
                'error'       => '已被新的浏览请求取代',
                'completed_at' => now(),
            ]);

        $cmd = AgentCommand::create([
            'server_id' => $server->id,
            'type'       => AgentCommand::TYPE_BROWSE_DIR,
            'params'     => ['path' => $path],
            'status'     => AgentCommand::STATUS_PENDING,
        ]);

        return response()->json([
            'message'    => '已下发目录浏览指令，Agent 3 秒内会响应（结果请轮询 command）',
            'command_id' => $cmd->id,
        ]);
    }
}
