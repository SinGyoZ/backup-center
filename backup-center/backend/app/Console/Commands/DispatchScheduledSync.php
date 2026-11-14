<?php

namespace App\Console\Commands;

use App\Models\AgentCommand;
use App\Models\BackupTask;
use App\Models\Server;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 每分钟运行一次。找到所有 schedule=当前 HH:MM 的启用任务并派发命令：
 *
 * 1. sync_mode=scheduled（归档收集/定时同步）：给该任务的 server 派发一条 sync_now
 *    命令（Agent 收到后扫描全部任务的备份目录）。
 * 2. backup_source=self（主动备份）：按 backup_mode 分流派发——
 *    full          → pack_backup（目录打包）
 *    incremental   → incremental_backup（目录快照，rsync 硬链）
 *    注意：旧实现不区分 backup_mode、一律派打包指令，快照任务被错误地打成了
 *    全量归档——本版修正。
 */
class DispatchScheduledSync extends Command
{
    protected $signature = 'tasks:dispatch-scheduled-sync';
    protected $description = '按每个任务的 schedule（HH:MM）派发同步 / 打包 / 快照命令';

    public function handle(): int
    {
        $now = now();
        $currentHM = $now->format('H:i');

        $scheduledTasks = BackupTask::where('sync_mode', BackupTask::SYNC_MODE_SCHEDULED)
            ->where('status', BackupTask::STATUS_ENABLED)
            ->where('schedule', $currentHM)
            ->with('server')
            ->get();

        // 同一个 server 只派一条 sync_now（Agent 会扫全部任务），去重
        $serverIds = $scheduledTasks->pluck('server_id')->unique();
        $dispatched = 0;

        foreach ($serverIds as $sid) {
            DB::transaction(function () use ($sid, &$dispatched) {
                // 60 秒内已派过 sync_now 的服务器跳过（防止 minute cron 双触发）
                $recent = AgentCommand::where('server_id', $sid)
                    ->where('type', AgentCommand::TYPE_SYNC_NOW)
                    ->where('created_at', '>', now()->subSeconds(60))
                    ->exists();
                if ($recent) return;

                AgentCommand::create([
                    'server_id' => $sid,
                    'type'      => AgentCommand::TYPE_SYNC_NOW,
                    'status'    => AgentCommand::STATUS_PENDING,
                ]);
                $dispatched++;
            });
        }

        // 主动备份任务（backup_source=self）：按各自 schedule 逐任务派发（不按 server 去重），
        // 并按 backup_mode 分流：full → pack_backup；incremental → incremental_backup
        $selfTasks = BackupTask::where('backup_source', BackupTask::BACKUP_SOURCE_SELF)
            ->where('status', BackupTask::STATUS_ENABLED)
            ->where('schedule', $currentHM)
            ->with('server')
            ->get();
        foreach ($selfTasks as $t) {
            if (! $t->server || $t->server->connect_type !== Server::CONNECT_AGENT) {
                continue;
            }
            $type = (($t->backup_mode ?? BackupTask::BACKUP_MODE_FULL) === BackupTask::BACKUP_MODE_INCREMENTAL)
                ? AgentCommand::TYPE_INCREMENTAL_BACKUP
                : AgentCommand::TYPE_PACK_BACKUP;
            $recent = AgentCommand::where('server_id', $t->server_id)
                ->where('type', $type)
                ->whereJsonContains('params', ['task_id' => $t->id])
                ->where('created_at', '>', now()->subSeconds(60))
                ->exists();
            if ($recent) continue;
            AgentCommand::create([
                'server_id' => $t->server_id,
                'type'      => $type,
                'status'    => AgentCommand::STATUS_PENDING,
                'params'    => ['task_id' => $t->id, 'task_name' => $t->name],
            ]);
            $dispatched++;
        }

        if ($dispatched > 0) {
            $this->info("已派发 {$dispatched} 条命令（涉及定时同步任务 " . $scheduledTasks->count() . " 个，触发时刻 {$currentHM}）");
        }

        return self::SUCCESS;
    }
}
