<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TaskResource;
use App\Models\AgentCommand;
use App\Models\BackupTask;
use App\Models\Server;
use App\Models\Storage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class TaskController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = BackupTask::with(['server', 'storages'])->orderByDesc('id');

        if ($keyword = $request->string('keyword')->trim()->toString()) {
            $query->where(function ($q) use ($keyword) {
                $q->where('name', 'like', "%{$keyword}%")
                  ->orWhere('backup_paths', 'like', "%{$keyword}%")
                  ->orWhere('remark', 'like', "%{$keyword}%");
            });
        }

        if ($status = $request->string('status')->toString()) {
            $query->where('status', $status);
        }

        if ($serverId = $request->integer('server_id')) {
            $query->where('server_id', $serverId);
        }

        return TaskResource::collection($query->paginate(20));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $this->validateIncrementalConfig($data);
        $storageIds = $this->extractStorageIds($data);

        $task = BackupTask::create($data);
        if ($storageIds !== null) {
            $task->storages()->sync($storageIds);
        }
        $task = $task->fresh()->load(['server', 'storages']);

        return (new TaskResource($task))
            ->additional(['message' => '添加成功'])
            ->response()
            ->setStatusCode(201);
    }

    public function show(BackupTask $task): TaskResource
    {
        return new TaskResource($task->load(['server', 'storages']));
    }

    public function update(BackupTask $task, Request $request): TaskResource
    {
        $data = $this->validated($request);
        // 局部更新兼容：用「库中现值 ∪ 本次提交」取生效值做交叉校验
        $this->validateIncrementalConfig(array_merge(
            $task->only(['backup_source', 'backup_mode', 'incremental_storage_id']),
            $data,
        ));
        $storageIds = $this->extractStorageIds($data);

        $task->update($data);
        if ($storageIds !== null) {
            $task->storages()->sync($storageIds);
        }

        return new TaskResource($task->fresh()->load(['server', 'storages']));
    }

    public function destroy(BackupTask $task): JsonResponse
    {
        $task->delete();
        return response()->json(['message' => '已删除']);
    }

    /**
     * 一键同步：立刻让该任务所属服务器的 Agent 扫一次备份目录，把未上传的文件推到各存储。
     * 走 agent_commands 通道（复用 update/sync_now），下一个心跳（≤60s）Agent 会收到。
     */
    public function syncNow(BackupTask $task): JsonResponse
    {
        $server = $task->server;
        if (! $server || $server->connect_type !== Server::CONNECT_AGENT) {
            return response()->json(['message' => '该任务所属服务器不是 Agent 模式'], 422);
        }

        // 复用一次即可：如已有 pending/dispatched 的 sync_now，直接返回它
        $existing = $server->agentCommands()
            ->where('type', AgentCommand::TYPE_SYNC_NOW)
            ->whereIn('status', [AgentCommand::STATUS_PENDING, AgentCommand::STATUS_DISPATCHED])
            ->latest('id')
            ->first();
        if ($existing) {
            return response()->json([
                'message'    => '已有同步任务在队列中，最长 60 秒内 Agent 会执行',
                'command_id' => $existing->id,
            ]);
        }

        $cmd = AgentCommand::create([
            'server_id'   => $server->id,
            'type'        => AgentCommand::TYPE_SYNC_NOW,
            'params'      => ['task_id' => $task->id, 'task_name' => $task->name],
            'status'      => AgentCommand::STATUS_PENDING,
        ]);

        return response()->json([
            'message'    => '已下发同步指令，最长 60 秒后 Agent 会扫描并上传未同步的备份',
            'command_id' => $cmd->id,
        ]);
    }

    /**
     * 目录打包：立刻让该任务所属服务器的 Agent 按 backup_paths 逐目录 tar 打包并上传（P3）。
     * 走 agent_commands 通道（type=pack_backup），下一个心跳（≤60s）Agent 会收到。
     */
    public function packNow(BackupTask $task): JsonResponse
    {
        $server = $task->server;
        if (! $server || $server->connect_type !== Server::CONNECT_AGENT) {
            return response()->json(['message' => '该任务所属服务器不是 Agent 模式，无法打包备份'], 422);
        }

        if ($task->backup_source !== BackupTask::BACKUP_SOURCE_SELF) {
            return response()->json(['message' => '该任务未启用目录打包（需 backup_source=self）'], 422);
        }

        // 复用一次即可：如已有 pending/dispatched 的 pack_backup（同一任务），直接返回它
        $existing = AgentCommand::where('server_id', $server->id)
            ->where('type', AgentCommand::TYPE_PACK_BACKUP)
            ->whereIn('status', [AgentCommand::STATUS_PENDING, AgentCommand::STATUS_DISPATCHED])
            ->whereJsonContains('params', ['task_id' => $task->id])
            ->latest('id')
            ->first();
        if ($existing) {
            return response()->json([
                'message'    => '已有打包备份任务在队列中，最长 60 秒内 Agent 会执行',
                'command_id' => $existing->id,
            ]);
        }

        $cmd = AgentCommand::create([
            'server_id'   => $server->id,
            'type'        => AgentCommand::TYPE_PACK_BACKUP,
            'params'      => ['task_id' => $task->id, 'task_name' => $task->name],
            'status'      => AgentCommand::STATUS_PENDING,
        ]);

        return response()->json([
            'message'    => '已下发打包备份指令，最长 60 秒后 Agent 会执行（打包所选目录并上传）',
            'command_id' => $cmd->id,
        ]);
    }

    /**
     * 目录快照：立刻让该任务所属服务器的 Agent 执行 rsync --link-dest 硬链快照，
     * 推送到 incremental_storage_id 指定的 SFTP 存储（P5）。
     * 走 agent_commands 通道（type=incremental_backup），下一个心跳（≤60s）Agent 会收到。
     */
    public function incrementalBackupNow(BackupTask $task): JsonResponse
    {
        $server = $task->server;
        if (! $server || $server->connect_type !== Server::CONNECT_AGENT) {
            return response()->json(['message' => '该任务所属服务器不是 Agent 模式，无法执行目录快照'], 422);
        }
        if ($task->backup_source !== BackupTask::BACKUP_SOURCE_SELF) {
            return response()->json(['message' => '该任务未启用目录快照（需 backup_source=self）'], 422);
        }
        if (($task->backup_mode ?? 'full') !== BackupTask::BACKUP_MODE_INCREMENTAL) {
            return response()->json(['message' => '该任务未启用目录快照（backup_mode=incremental）'], 422);
        }
        if (! $task->incremental_storage_id) {
            return response()->json(['message' => '未配置目录快照目标存储，请在编辑任务时选择'], 422);
        }
        // 防重：同任务已有 pending/dispatched 的 incremental_backup 则复用
        $existing = AgentCommand::where('server_id', $server->id)
            ->where('type', AgentCommand::TYPE_INCREMENTAL_BACKUP)
            ->whereIn('status', [AgentCommand::STATUS_PENDING, AgentCommand::STATUS_DISPATCHED])
            ->whereJsonContains('params', ['task_id' => $task->id])
            ->latest('id')
            ->first();
        if ($existing) {
            return response()->json(['message' => '已有目录快照任务在队列中，最长 60 秒内 Agent 会执行', 'command_id' => $existing->id]);
        }
        $cmd = AgentCommand::create([
            'server_id'   => $server->id,
            'type'        => AgentCommand::TYPE_INCREMENTAL_BACKUP,
            'params'      => ['task_id' => $task->id, 'task_name' => $task->name],
            'status'      => AgentCommand::STATUS_PENDING,
        ]);
        return response()->json([
            'message'    => '已下发目录快照指令，最长 60 秒后 Agent 会执行（rsync 硬链快照并推送）',
            'command_id' => $cmd->id,
        ]);
    }

    /**
     * 目录快照配置交叉校验（store/update 共用；调用方传入「生效值」——update 时是
     * 库中现值与本次提交的合并，兼容局部更新）。
     * 校验失败统一 abort(422, ...)，由框架转成 JSON 错误响应。
     */
    private function validateIncrementalConfig(array $effective): void
    {
        if (($effective['backup_mode'] ?? 'full') !== BackupTask::BACKUP_MODE_INCREMENTAL) {
            return; // full 不校验（incremental_storage_id 允许滞留，切回 full 即失效）
        }
        if (($effective['backup_source'] ?? 'external') !== BackupTask::BACKUP_SOURCE_SELF) {
            abort(422, '目录快照需要先启用主动备份（backup_source=self）');
        }
        $storageId = $effective['incremental_storage_id'] ?? null;
        if (! $storageId) {
            abort(422, '请选择目录快照目标存储');
        }
        $storage = Storage::find($storageId);
        if (! $storage) {
            abort(422, '目录快照目标存储不存在');
        }
        if ($storage->type !== Storage::TYPE_SFTP) {
            abort(422, '目录快照目标必须是 SFTP 类型存储（rsync 经 SSH 推送到 VPS）');
        }
        $config = $storage->config ?? [];
        if (empty($config['private_key'])) {
            abort(422, '目录快照目标存储必须配置 SSH 私钥（rsync 不支持交互式密码输入）');
        }
        if (! empty($config['encryption']['enabled'])) {
            abort(422, '目录快照目标存储不能开启加密（rsync 明文写入，与加密层不兼容）');
        }
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'server_id' => ['required', 'integer', Rule::exists('servers', 'id')],
            'name' => ['required', 'string', 'max:64'],
            // 备份目录：对所有 backup_source 必填（external=扫描目录；self=打包/快照目录）
            'backup_paths' => ['required', 'array', 'max:20'],
            'backup_paths.*.label' => ['nullable', 'string', 'max:64'],
            'backup_paths.*.path' => ['required', 'string', 'max:255'],
            // self 模式 pattern 可空（Agent 打包整个目录，不做文件筛选）
            'backup_paths.*.pattern' => ['nullable', 'string', 'max:128'],
            'retention_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'schedule' => ['nullable', 'string', 'max:64', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            // 同步方式：auto=自动扫描（现行为），scheduled=只在 schedule 设定时间由 Center 派发 sync_now
            'sync_mode' => ['nullable', 'string', Rule::in([BackupTask::SYNC_MODE_AUTO, BackupTask::SYNC_MODE_SCHEDULED])],
            // 备份来源：external=归档收集；self=主动备份（目录打包/目录快照）
            'backup_source' => ['nullable', 'string', Rule::in([BackupTask::BACKUP_SOURCE_EXTERNAL, BackupTask::BACKUP_SOURCE_SELF])],
            // 备份方式：full=目录打包；incremental=rsync 硬链快照（仅 backup_source=self 可选）
            'backup_mode' => ['nullable', 'string', Rule::in([BackupTask::BACKUP_MODE_FULL, BackupTask::BACKUP_MODE_INCREMENTAL])],
            'incremental_storage_id' => ['nullable', 'integer', Rule::exists('storages', 'id')],
            'restore_mode' => ['nullable', 'string', Rule::in([BackupTask::RESTORE_MODE_LITE, BackupTask::RESTORE_MODE_ADVANCED])],
            'status' => ['nullable', Rule::in([BackupTask::STATUS_ENABLED, BackupTask::STATUS_DISABLED])],
            'remark' => ['nullable', 'string', 'max:500'],
            'storage_ids' => ['nullable', 'array'],
            'storage_ids.*' => ['integer', Rule::exists('storages', 'id')],
        ]);
    }

    /**
     * 从 $data 中取出 storage_ids（并从 $data 里删掉，避免 mass assignment）。
     * 返回 null 表示请求里没有这个字段（意思：保持关联不变）
     */
    private function extractStorageIds(array &$data): ?array
    {
        if (! array_key_exists('storage_ids', $data)) {
            return null;
        }
        $ids = $data['storage_ids'];
        unset($data['storage_ids']);
        return is_array($ids) ? $ids : [];
    }
}
