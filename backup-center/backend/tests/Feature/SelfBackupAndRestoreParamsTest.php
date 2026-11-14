<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\RestoreController;
use App\Http\Controllers\Api\TaskController;
use App\Models\AgentCommand;
use App\Models\BackupLog;
use App\Models\BackupTask;
use App\Models\BackupUpload;
use App\Models\Server;
use App\Models\Storage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * P3 目录打包 / P4 高级恢复的后端指令契约聚焦测试（直接调 Controller，绕开 auth 中间件）：
 *
 * - packNow：agent+self 双重校验、pending/dispatched 去重、params 只含 task_id/task_name（无凭据）
 * - restore store()：advanced 追加 restore_mode+task_id+origin_path 且 local_dir 走临时中转目录、
 *   恢复目标=origin_path；lite 保持 dirname(full_path) 且不追加任何键；
 *   dedup 键含 restore_mode（lite/advanced 不互斥）；
 *   origin_path 为空的记录不允许 advanced（提示改用留档恢复）
 */
class SelfBackupAndRestoreParamsTest extends TestCase
{
    use RefreshDatabase;

    /** AgentCommand.params / restore params 中绝不允许出现的凭据类键 */
    private const CREDENTIAL_KEYS = ['db_password', 'db_host', 'db_port', 'db_name', 'db_user', 'db_type', 'web_root', 'password', 'private_key', 'config', 'encryption'];

    private function agentServer(): Server
    {
        return Server::create([
            'name'         => 'Agent 服务器',
            'connect_type' => Server::CONNECT_AGENT,
            'status'       => Server::STATUS_ONLINE,
        ]);
    }

    private function makeTask(
        Server $server,
        string $source = BackupTask::BACKUP_SOURCE_SELF,
        string $restoreMode = BackupTask::RESTORE_MODE_LITE,
    ): BackupTask {
        return BackupTask::create([
            'server_id'      => $server->id,
            'name'           => '演示任务',
            'backup_paths'   => [
                ['path' => '/var/www/demo', 'pattern' => '', 'label' => '演示目录'],
            ],
            'backup_source'  => $source,
            'restore_mode'   => $restoreMode,
            'status'         => BackupTask::STATUS_ENABLED,
        ]);
    }

    private function makeLogWithUpload(BackupTask $task, string $filename, string $fullPath, string $remotePath, ?string $originPath = null): BackupLog
    {
        $storage = Storage::create([
            'name'   => '测试存储',
            'type'   => Storage::TYPE_SFTP,
            'config' => ['host' => '127.0.0.1'],
        ]);

        $log = BackupLog::create([
            'task_id'     => $task->id,
            'server_id'   => $task->server_id,
            'filename'    => $filename,
            'full_path'   => $fullPath,
            'origin_path' => $originPath,
            'status'      => BackupLog::STATUS_SUCCESS,
            'backup_at'   => now(),
        ]);
        BackupUpload::create([
            'backup_log_id' => $log->id,
            'storage_id'    => $storage->id,
            'status'        => BackupUpload::STATUS_UPLOADED,
            'remote_path'   => $remotePath,
        ]);

        return $log;
    }

    /* ---------------- P3：packNow ---------------- */

    public function test_pack_now_creates_command_with_only_safe_params(): void
    {
        $task = $this->makeTask($this->agentServer());

        $resp = (new TaskController())->packNow($task);

        $this->assertSame(200, $resp->getStatusCode());
        $payload = $resp->getData(true);
        $this->assertArrayHasKey('command_id', $payload);

        $cmd = AgentCommand::latest('id')->first();
        $this->assertNotNull($cmd);
        $this->assertSame(AgentCommand::TYPE_PACK_BACKUP, $cmd->type);
        $this->assertSame(AgentCommand::STATUS_PENDING, $cmd->status);
        $this->assertSame($task->server_id, $cmd->server_id);
        $this->assertSame(['task_id' => $task->id, 'task_name' => '演示任务'], $cmd->params);

        // 红线：params 绝不出现任何凭据 / 连接信息
        foreach (self::CREDENTIAL_KEYS as $key) {
            $this->assertArrayNotHasKey($key, $cmd->params);
        }
    }

    public function test_pack_now_reuses_pending_command_for_same_task(): void
    {
        $task = $this->makeTask($this->agentServer());
        $controller = new TaskController();

        $first  = $controller->packNow($task)->getData(true);
        $second = $controller->packNow($task)->getData(true);

        $this->assertSame($first['command_id'], $second['command_id']);
        $this->assertSame(1, AgentCommand::where('type', AgentCommand::TYPE_PACK_BACKUP)->count());
    }

    public function test_pack_now_rejects_non_agent_server(): void
    {
        $server = Server::create(['name' => 'SSH 服务器', 'connect_type' => Server::CONNECT_SSH]);
        $task = $this->makeTask($server);

        $resp = (new TaskController())->packNow($task);

        $this->assertSame(422, $resp->getStatusCode());
        $this->assertSame(0, AgentCommand::count());
    }

    public function test_pack_now_rejects_external_source(): void
    {
        $task = $this->makeTask($this->agentServer(), BackupTask::BACKUP_SOURCE_EXTERNAL);

        $resp = (new TaskController())->packNow($task);

        $this->assertSame(422, $resp->getStatusCode());
        $this->assertSame('该任务未启用目录打包（需 backup_source=self）', $resp->getData(true)['message']);
        $this->assertSame(0, AgentCommand::count());
    }

    /* ---------------- P4：restore store() ---------------- */

    public function test_restore_advanced_appends_mode_and_uses_temp_local_dir(): void
    {
        $task = $this->makeTask($this->agentServer(), BackupTask::BACKUP_SOURCE_SELF, BackupTask::RESTORE_MODE_ADVANCED);
        // self 产物的 full_path 在 Agent 临时目录（P3），不能把归档拉回 /tmp 当目标；
        // 高级恢复目标 = origin_path（被打包目录）
        $log = $this->makeLogWithUpload(
            $task,
            'demo-dir-20261014-101010.tar.gz',
            sys_get_temp_dir() . '/bc-self-1-x/demo-dir-20261014-101010.tar.gz',
            'stor:demo/demo-dir-20261014-101010.tar.gz',
            '/var/www/demo',
        );

        $resp = (new RestoreController())->store(new Request(['backup_log_ids' => [$log->id]]));

        $this->assertSame(200, $resp->getStatusCode());
        $cmd = AgentCommand::where('type', AgentCommand::TYPE_RESTORE)->latest('id')->first();
        $this->assertNotNull($cmd);
        $this->assertSame('advanced', $cmd->params['restore_mode']);
        $this->assertSame($task->id, $cmd->params['task_id']);
        $this->assertSame('/var/www/demo', $cmd->params['origin_path']);
        $this->assertStringStartsWith(
            sys_get_temp_dir() . '/bc-restore-' . $task->id . '-',
            $cmd->params['local_dir'],
        );

        // 红线：params 不出现任何凭据，Agent 自行从 tasks() 内存通道解析
        foreach (self::CREDENTIAL_KEYS as $key) {
            $this->assertArrayNotHasKey($key, $cmd->params);
        }
    }

    public function test_restore_advanced_without_origin_path_is_rejected(): void
    {
        $task = $this->makeTask($this->agentServer(), BackupTask::BACKUP_SOURCE_SELF, BackupTask::RESTORE_MODE_ADVANCED);
        // 无 origin_path 的记录（如旧版本 Agent 上报 / 手工造数）：不允许高级恢复
        $log = $this->makeLogWithUpload(
            $task,
            'demo-dir-20261014-101010.tar.gz',
            sys_get_temp_dir() . '/bc-self-1-x/demo-dir-20261014-101010.tar.gz',
            'stor:demo/demo-dir-20261014-101010.tar.gz',
            null,
        );

        $resp = (new RestoreController())->store(new Request(['backup_log_ids' => [$log->id]]));

        $this->assertSame(422, $resp->getStatusCode());
        $payload = $resp->getData(true);
        $this->assertStringContainsString('origin_path', $payload['skipped'][0]['reason']);
        $this->assertStringContainsString('留档恢复', $payload['skipped'][0]['reason']);
        $this->assertSame(0, AgentCommand::where('type', AgentCommand::TYPE_RESTORE)->count());
    }

    public function test_restore_lite_keeps_dirname_local_dir_without_extra_params(): void
    {
        $task  = $this->makeTask($this->agentServer(), BackupTask::BACKUP_SOURCE_EXTERNAL, BackupTask::RESTORE_MODE_LITE);
        $fullPath = '/data/backup/site/demo-dir.tar.gz';
        $log = $this->makeLogWithUpload($task, 'demo-dir.tar.gz', $fullPath, 'stor:demo/demo-dir.tar.gz');

        $resp = (new RestoreController())->store(new Request(['backup_log_ids' => [$log->id]]));

        $this->assertSame(200, $resp->getStatusCode());
        $cmd = AgentCommand::where('type', AgentCommand::TYPE_RESTORE)->latest('id')->first();
        $this->assertNotNull($cmd);
        $this->assertSame(dirname($fullPath), $cmd->params['local_dir']);
        $this->assertArrayNotHasKey('restore_mode', $cmd->params);
        $this->assertArrayNotHasKey('task_id', $cmd->params);
        $this->assertArrayNotHasKey('origin_path', $cmd->params);
    }

    public function test_restore_dedup_key_includes_restore_mode(): void
    {
        $task = $this->makeTask($this->agentServer(), BackupTask::BACKUP_SOURCE_SELF, BackupTask::RESTORE_MODE_ADVANCED);
        $log = $this->makeLogWithUpload(
            $task,
            'demo-dir-20261014-101010.tar.gz',
            sys_get_temp_dir() . '/bc-self-1-x/demo-dir-20261014-101010.tar.gz',
            'stor:demo/demo-dir-20261014-101010.tar.gz',
            '/var/www/demo',
        );
        $controller = new RestoreController();

        // 第一次 advanced 正常下发
        $controller->store(new Request(['backup_log_ids' => [$log->id]]));

        // 同一 upload 再次 advanced：被去重跳过（无新命令，整体 422 + skipped 原因）
        $resp2 = $controller->store(new Request(['backup_log_ids' => [$log->id]]));
        $this->assertSame(422, $resp2->getStatusCode());
        $payload2 = $resp2->getData(true);
        $this->assertSame('该文件已有恢复任务在进行中', $payload2['skipped'][0]['reason']);
        $this->assertSame(1, AgentCommand::where('type', AgentCommand::TYPE_RESTORE)->count());

        // 切到 lite：pending 的 advanced 不阻塞 lite（dedup 键含 restore_mode，二者不互斥）
        $task->update(['restore_mode' => BackupTask::RESTORE_MODE_LITE]);
        $resp3 = $controller->store(new Request(['backup_log_ids' => [$log->id]]));
        $this->assertSame(200, $resp3->getStatusCode());
        $this->assertSame(2, AgentCommand::where('type', AgentCommand::TYPE_RESTORE)->count());
    }
}
