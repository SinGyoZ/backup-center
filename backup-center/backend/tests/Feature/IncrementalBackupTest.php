<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\AgentController;
use App\Http\Controllers\Api\RestoreController;
use App\Http\Controllers\Api\TaskController;
use App\Models\AgentCommand;
use App\Models\BackupLog;
use App\Models\BackupTask;
use App\Models\BackupUpload;
use App\Models\Server;
use App\Models\Storage;
use App\Support\BackupSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * 目录快照（P5）后端契约聚焦测试（直接调 Controller，绕开 auth 中间件）：
 *
 * 1. incremental_backup 端点：external 任务 422；full 模式 422；无目标存储 422；
 *    正常 → 命令 type/params 正确；二次调用复用 command_id。
 * 2. 任务 store/update 校验矩阵：incremental+external 422；incremental+非 sftp 422；
 *    incremental+sftp 无私钥 422；incremental+加密 sftp 422；happy path 落库。
 * 3. AgentController::tasks：响应含 backup_mode/incremental_storage_id；backup_paths 带
 *    label；无 web_root/db_*；快照目标存储出现在顶层 storages（即使不在 pivot）。
 * 4. RestoreController::backups：快照日志（带 batch_code）独立成组、type=snapshot、
 *    label='快照'、日期分组正确、无上传时 restorable=false。
 * 5. RestoreController::store：快照日志 → params 含 path_type=dir 且 local_dir 在
 *    sys_get_temp_dir() 下；同编码的目录归档日志 → 普通文件参数（无 path_type）。
 * 6. BackupSource::derive：-snap-<seg>-<stamp> → self:snapshot；-dir-*.tar.gz → self:dir。
 * 7. 既有套件回归（RestoreSuiteWindowTest 等）由全量 phpunit 覆盖。
 *
 * 附：BackupTask::recentSelfBackups 目录归档 + 快照项。
 */
class IncrementalBackupTest extends TestCase
{
    use RefreshDatabase;

    /* ----------------  fixtures ---------------- */

    private function agentServer(): Server
    {
        return Server::create([
            'name'         => 'Agent 服务器',
            'connect_type' => Server::CONNECT_AGENT,
            'status'       => Server::STATUS_ONLINE,
        ]);
    }

    /** SFTP 存储；默认带 SSH 私钥、未加密（合法快照目标） */
    private function sftpStorage(array $overrides = []): Storage
    {
        return Storage::create($overrides + [
            'name'   => '快照目标 VPS',
            'type'   => Storage::TYPE_SFTP,
            'config' => ['host' => '10.0.0.9', 'user' => 'root', 'private_key' => "-----BEGIN KEY-----\nxx"],
        ]);
    }

    private function makeTask(
        Server $server,
        string $source = BackupTask::BACKUP_SOURCE_SELF,
        string $backupMode = BackupTask::BACKUP_MODE_FULL,
        ?int $incrementalStorageId = null,
    ): BackupTask {
        return BackupTask::create([
            'server_id'              => $server->id,
            'name'                   => '演示任务',
            'backup_paths'           => [
                ['path' => '/var/www/demo', 'pattern' => '', 'label' => '演示目录'],
            ],
            'backup_source'          => $source,
            'backup_mode'            => $backupMode,
            'incremental_storage_id' => $incrementalStorageId,
            'status'                 => BackupTask::STATUS_ENABLED,
        ]);
    }

    private function makeLogWithUpload(
        BackupTask $task,
        string $filename,
        string $fullPath,
        string $remotePath,
        string $batchCode = '',
    ): BackupLog {
        $storage = $this->sftpStorage(['name' => '测试存储']);

        $log = BackupLog::create([
            'task_id'    => $task->id,
            'server_id'  => $task->server_id,
            'filename'   => $filename,
            'full_path'  => $fullPath,
            'size'       => 2048,
            'status'     => BackupLog::STATUS_SUCCESS,
            'backup_at'  => now(),
            'batch_code' => $batchCode,
        ]);
        BackupUpload::create([
            'backup_log_id' => $log->id,
            'storage_id'    => $storage->id,
            'status'        => BackupUpload::STATUS_UPLOADED,
            'remote_path'   => $remotePath,
        ]);

        return $log;
    }

    /** abort(422, ...) 断言辅助：捕获 HttpException 并校验状态码与文案 */
    private function assertAborts422(callable $fn, string $message): void
    {
        try {
            $fn();
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertSame($message, $e->getMessage());
            return;
        }
        $this->fail('预期 abort(422, ' . $message . ')，但调用正常返回了');
    }

    /* ---------------- 1. incremental_backup 端点 ---------------- */

    public function test_incremental_backup_now_rejects_non_agent_server(): void
    {
        $server  = Server::create(['name' => 'SSH 服务器', 'connect_type' => Server::CONNECT_SSH]);
        $task = $this->makeTask($server, BackupTask::BACKUP_SOURCE_SELF, BackupTask::BACKUP_MODE_INCREMENTAL, $this->sftpStorage()->id);

        $resp = (new TaskController())->incrementalBackupNow($task);

        $this->assertSame(422, $resp->getStatusCode());
        $this->assertSame('该任务所属服务器不是 Agent 模式，无法执行目录快照', $resp->getData(true)['message']);
        $this->assertSame(0, AgentCommand::count());
    }

    public function test_incremental_backup_now_rejects_external_source(): void
    {
        $task = $this->makeTask($this->agentServer(), BackupTask::BACKUP_SOURCE_EXTERNAL, BackupTask::BACKUP_MODE_INCREMENTAL);

        $resp = (new TaskController())->incrementalBackupNow($task);

        $this->assertSame(422, $resp->getStatusCode());
        $this->assertSame('该任务未启用目录快照（需 backup_source=self）', $resp->getData(true)['message']);
        $this->assertSame(0, AgentCommand::count());
    }

    public function test_incremental_backup_now_rejects_full_mode(): void
    {
        $task = $this->makeTask($this->agentServer(), BackupTask::BACKUP_SOURCE_SELF, BackupTask::BACKUP_MODE_FULL);

        $resp = (new TaskController())->incrementalBackupNow($task);

        $this->assertSame(422, $resp->getStatusCode());
        $this->assertSame('该任务未启用目录快照（backup_mode=incremental）', $resp->getData(true)['message']);
        $this->assertSame(0, AgentCommand::count());
    }

    public function test_incremental_backup_now_rejects_missing_target_storage(): void
    {
        $task = $this->makeTask($this->agentServer(), BackupTask::BACKUP_SOURCE_SELF, BackupTask::BACKUP_MODE_INCREMENTAL, null);

        $resp = (new TaskController())->incrementalBackupNow($task);

        $this->assertSame(422, $resp->getStatusCode());
        $this->assertSame('未配置目录快照目标存储，请在编辑任务时选择', $resp->getData(true)['message']);
        $this->assertSame(0, AgentCommand::count());
    }

    public function test_incremental_backup_now_creates_command_and_reuses_pending(): void
    {
        $task = $this->makeTask(
            $this->agentServer(),
            BackupTask::BACKUP_SOURCE_SELF,
            BackupTask::BACKUP_MODE_INCREMENTAL,
            $this->sftpStorage()->id,
        );
        $controller = new TaskController();

        $first = $controller->incrementalBackupNow($task);
        $this->assertSame(200, $first->getStatusCode());
        $firstData = $first->getData(true);
        $this->assertArrayHasKey('command_id', $firstData);

        $cmd = AgentCommand::latest('id')->first();
        $this->assertNotNull($cmd);
        $this->assertSame(AgentCommand::TYPE_INCREMENTAL_BACKUP, $cmd->type);
        $this->assertSame(AgentCommand::STATUS_PENDING, $cmd->status);
        $this->assertSame($task->server_id, $cmd->server_id);
        $this->assertSame(['task_id' => $task->id, 'task_name' => '演示任务'], $cmd->params);

        // 二次调用复用同一条 pending 指令，不重复创建
        $second = $controller->incrementalBackupNow($task);
        $this->assertSame(200, $second->getStatusCode());
        $this->assertSame($firstData['command_id'], $second->getData(true)['command_id']);
        $this->assertSame(1, AgentCommand::where('type', AgentCommand::TYPE_INCREMENTAL_BACKUP)->count());
    }

    /* ---------------- 2. store/update 校验矩阵 ---------------- */

    public function test_store_incremental_with_external_source_gets_422(): void
    {
        $server = $this->agentServer();
        $this->assertAborts422(function () use ($server) {
            (new TaskController())->store(new Request([
                'server_id' => $server->id,
                'name'      => '归档收集任务',
                'backup_source' => BackupTask::BACKUP_SOURCE_EXTERNAL,
                'backup_paths'  => [['path' => '/data/backup', 'pattern' => '*']],
                'backup_mode'   => BackupTask::BACKUP_MODE_INCREMENTAL,
                'incremental_storage_id' => $this->sftpStorage()->id,
            ]));
        }, '目录快照需要先启用主动备份（backup_source=self）');
        $this->assertSame(0, BackupTask::count());
    }

    public function test_store_incremental_with_non_sftp_storage_gets_422(): void
    {
        $server = $this->agentServer();
        $s3 = Storage::create([
            'name'   => '对象存储',
            'type'   => Storage::TYPE_S3,
            'config' => ['endpoint' => 'https://s3.example.com'],
        ]);
        $this->assertAborts422(function () use ($server, $s3) {
            (new TaskController())->store(new Request([
                'server_id' => $server->id,
                'name'      => '快照任务',
                'backup_paths' => [['path' => '/var/www/demo', 'pattern' => '']],
                'backup_source' => BackupTask::BACKUP_SOURCE_SELF,
                'backup_mode'   => BackupTask::BACKUP_MODE_INCREMENTAL,
                'incremental_storage_id' => $s3->id,
            ]));
        }, '目录快照目标必须是 SFTP 类型存储（rsync 经 SSH 推送到 VPS）');
        $this->assertSame(0, BackupTask::count());
    }

    public function test_store_incremental_sftp_without_private_key_gets_422(): void
    {
        $server = $this->agentServer();
        $noKey = Storage::create([
            'name'   => '无密钥 VPS',
            'type'   => Storage::TYPE_SFTP,
            'config' => ['host' => '10.0.0.10', 'user' => 'root'],
        ]);
        $this->assertAborts422(function () use ($server, $noKey) {
            (new TaskController())->store(new Request([
                'server_id' => $server->id,
                'name'      => '快照任务',
                'backup_paths' => [['path' => '/var/www/demo', 'pattern' => '']],
                'backup_source' => BackupTask::BACKUP_SOURCE_SELF,
                'backup_mode'   => BackupTask::BACKUP_MODE_INCREMENTAL,
                'incremental_storage_id' => $noKey->id,
            ]));
        }, '目录快照目标存储必须配置 SSH 私钥（rsync 不支持交互式密码输入）');
        $this->assertSame(0, BackupTask::count());
    }

    public function test_store_incremental_with_encrypted_sftp_gets_422(): void
    {
        $server = $this->agentServer();
        $encrypted = $this->sftpStorage([
            'name'   => '加密 VPS',
            'config' => [
                'host' => '10.0.0.11', 'user' => 'root', 'private_key' => "-----BEGIN KEY-----\nxx",
                'encryption' => ['enabled' => true, 'key_mode' => 'center', 'password' => 'pw'],
            ],
        ]);
        $this->assertAborts422(function () use ($server, $encrypted) {
            (new TaskController())->store(new Request([
                'server_id' => $server->id,
                'name'      => '快照任务',
                'backup_paths' => [['path' => '/var/www/demo', 'pattern' => '']],
                'backup_source' => BackupTask::BACKUP_SOURCE_SELF,
                'backup_mode'   => BackupTask::BACKUP_MODE_INCREMENTAL,
                'incremental_storage_id' => $encrypted->id,
            ]));
        }, '目录快照目标存储不能开启加密（rsync 明文写入，与加密层不兼容）');
        $this->assertSame(0, BackupTask::count());
    }

    public function test_store_incremental_happy_path_persists(): void
    {
        $server  = $this->agentServer();
        $storage = $this->sftpStorage();

        $resp = (new TaskController())->store(new Request([
            'server_id' => $server->id,
            'name'      => '快照任务',
            'backup_paths' => [['path' => '/var/www/demo', 'pattern' => '', 'label' => '演示目录']],
            'backup_source' => BackupTask::BACKUP_SOURCE_SELF,
            'backup_mode'   => BackupTask::BACKUP_MODE_INCREMENTAL,
            'incremental_storage_id' => $storage->id,
        ]));

        $this->assertSame(201, $resp->getStatusCode());
        // TaskResource 响应包在 data 里，透出两个新字段
        $payload = $resp->getData(true)['data'];
        $this->assertSame('incremental', $payload['backup_mode']);
        $this->assertSame($storage->id, $payload['incremental_storage_id']);

        $this->assertDatabaseHas('backup_tasks', [
            'name'                    => '快照任务',
            'backup_source'           => BackupTask::BACKUP_SOURCE_SELF,
            'backup_mode'             => BackupTask::BACKUP_MODE_INCREMENTAL,
            'incremental_storage_id'  => $storage->id,
        ]);
    }

    public function test_store_full_mode_skips_incremental_validation(): void
    {
        $server  = $this->agentServer();
        $s3 = Storage::create(['name' => '对象存储', 'type' => Storage::TYPE_S3, 'config' => ['endpoint' => 'x']]);

        // full 模式不校验 incremental_storage_id（允许滞留，切回 full 即失效）
        $resp = (new TaskController())->store(new Request([
            'server_id' => $server->id,
            'name'      => '打包任务',
            'backup_paths' => [['path' => '/var/www/demo', 'pattern' => '']],
            'backup_source' => BackupTask::BACKUP_SOURCE_SELF,
            'backup_mode'   => BackupTask::BACKUP_MODE_FULL,
            'incremental_storage_id' => $s3->id,
        ]));

        $this->assertSame(201, $resp->getStatusCode());
        $this->assertSame('full', $resp->getData(true)['data']['backup_mode']);
    }

    public function test_update_incremental_merges_existing_values_for_partial_update(): void
    {
        $server  = $this->agentServer();
        $storage = $this->sftpStorage();
        $task = $this->makeTask($server, BackupTask::BACKUP_SOURCE_SELF, BackupTask::BACKUP_MODE_INCREMENTAL, $storage->id);
        $controller = new TaskController();

        // 局部更新（只改名字；backup_mode/incremental_storage_id 不随请求提交）：
        // 用库中现值合并后仍合法，不得 422
        $resp = $controller->update($task, new Request([
            'server_id' => $server->id,
            'name'      => '改名任务',
            'backup_paths' => [['path' => '/var/www/demo', 'pattern' => '']],
        ]));
        $this->assertSame('改名任务', $resp->resolve()['name']);
        $this->assertDatabaseHas('backup_tasks', [
            'id' => $task->id,
            'backup_mode' => BackupTask::BACKUP_MODE_INCREMENTAL,
            'incremental_storage_id' => $storage->id,
        ]);

        // 切回 full：incremental_storage_id 允许滞留（切回 full 即失效）
        $resp2 = $controller->update($task->fresh(), new Request([
            'server_id' => $server->id, 'name' => '改名任务',
            'backup_paths' => [['path' => '/var/www/demo', 'pattern' => '']],
            'backup_mode' => BackupTask::BACKUP_MODE_FULL,
        ]));
        $this->assertSame('full', $resp2->resolve()['backup_mode']);
        $this->assertDatabaseHas('backup_tasks', [
            'id' => $task->id,
            'backup_mode' => BackupTask::BACKUP_MODE_FULL,
            'incremental_storage_id' => $storage->id,
        ]);

        // 无存储的打包任务开快照：生效值缺 incremental_storage_id → 422
        $plain = $this->makeTask($server, BackupTask::BACKUP_SOURCE_SELF, BackupTask::BACKUP_MODE_FULL, null);
        $this->assertAborts422(function () use ($controller, $plain, $server) {
            $controller->update($plain, new Request([
                'server_id' => $server->id, 'name' => '演示任务',
                'backup_paths' => [['path' => '/var/www/demo', 'pattern' => '']],
                'backup_mode' => BackupTask::BACKUP_MODE_INCREMENTAL,
            ]));
        }, '请选择目录快照目标存储');
    }

    public function test_update_enabling_incremental_happy_path(): void
    {
        $server  = $this->agentServer();
        $storage = $this->sftpStorage();
        $task = $this->makeTask($server, BackupTask::BACKUP_SOURCE_SELF, BackupTask::BACKUP_MODE_FULL, null);

        $resp = (new TaskController())->update($task, new Request([
            'server_id' => $server->id, 'name' => '演示任务',
            'backup_paths' => [['path' => '/var/www/demo', 'pattern' => '']],
            'backup_mode'   => BackupTask::BACKUP_MODE_INCREMENTAL,
            'incremental_storage_id' => $storage->id,
        ]));

        $this->assertSame('incremental', $resp->resolve()['backup_mode']);
        $this->assertDatabaseHas('backup_tasks', [
            'id' => $task->id,
            'backup_mode' => BackupTask::BACKUP_MODE_INCREMENTAL,
            'incremental_storage_id' => $storage->id,
        ]);
    }

    /* ---------------- 3. AgentController::tasks ---------------- */

    public function test_agent_tasks_payload_contract(): void
    {
        $server  = $this->agentServer();
        $storage = $this->sftpStorage();
        $incTask = $this->makeTask($server, BackupTask::BACKUP_SOURCE_SELF, BackupTask::BACKUP_MODE_INCREMENTAL, $storage->id);
        $fullTask = $this->makeTask($server, BackupTask::BACKUP_SOURCE_EXTERNAL, BackupTask::BACKUP_MODE_FULL, null);
        $fullTask->update(['name' => '归档收集任务']);

        $request = new Request();
        $request->attributes->set('server', $server);
        $resp = (new AgentController())->tasks($request);

        $this->assertSame(200, $resp->getStatusCode());
        $data = $resp->getData(true);

        $this->assertArrayHasKey('tasks', $data);

        $byId = collect($data['tasks'])->keyBy('id');
        $this->assertSame('incremental', $byId[$incTask->id]['backup_mode']);
        $this->assertSame($storage->id, $byId[$incTask->id]['incremental_storage_id']);
        $this->assertSame('full', $byId[$fullTask->id]['backup_mode']);
        $this->assertNull($byId[$fullTask->id]['incremental_storage_id']);

        // 契约 8：任务 item 的键集合（label 必须下发；无 web_root/db_*；无兼容旧字段）
        $expectedKeys = ['id', 'name', 'backup_paths', 'retention_days', 'sync_mode', 'storage_ids', 'backup_source', 'backup_mode', 'incremental_storage_id'];
        $this->assertSame($expectedKeys, array_keys($byId[$incTask->id]));
        $this->assertSame('演示目录', $byId[$incTask->id]['backup_paths'][0]['label']);
        $this->assertSame('/var/www/demo', $byId[$incTask->id]['backup_paths'][0]['path']);
        foreach (['web_root', 'db_type', 'db_host', 'db_port', 'db_name', 'db_user', 'db_password', 'backup_path', 'file_pattern'] as $gone) {
            $this->assertArrayNotHasKey($gone, $byId[$incTask->id]);
        }

        // 快照目标存储不在 backup_task_storages pivot 里，也必须出现在顶层 storages
        // （Agent 据此渲染 rclone.conf section 并落地 SSH 私钥）
        $this->assertContains($storage->id, array_column($data['storages'], 'id'));
    }

    /* ---------------- 4. RestoreController::backups 快照分组 ---------------- */

    public function test_restore_backups_snapshot_suite_type_label_and_restorable(): void
    {
        $task = $this->makeTask($this->agentServer(), BackupTask::BACKUP_SOURCE_EXTERNAL, BackupTask::BACKUP_MODE_FULL, null);
        // 锚定当天 02:00，不用 now()->subHours(2)：应用时区是 UTC，后者在
        // UTC 00:00-02:59 之间跑会跨到前一天，与下面默认 now() 的旧文件分成
        // 两个日期组，assertCount(1) 必炸（CI 每天在这个窗口都会踩）
        $at = now()->startOfDay()->addHours(2);

        // 快照日志：带 batch_code、无上传 → restorable=false；full_path 是远端快照目录
        $snap = BackupLog::create([
            'task_id'    => $task->id,
            'server_id'  => $task->server_id,
            'filename'   => 'demo-snap-www-20261019-143000',
            'full_path'  => '/backup/demo/20261019-143000',
            'size'       => 4096,
            'status'     => BackupLog::STATUS_SUCCESS,
            'backup_at'  => $at,
            'batch_code' => '777',
        ]);
        // 同一次快照指令的目录归档文件：带 batch_code、有上传 → restorable=true
        $dirLog = $this->makeLogWithUpload(
            $task,
            'demo-dir-20261019-143000.tar.gz',
            '/backup/demo/demo-dir-20261019-143000.tar.gz',
            'bc_1:/backup/demo/demo-dir-20261019-143000.tar.gz',
            '777',
        );
        $dirLog->update(['backup_at' => $at]);
        // 无编码的旧归档：不得与编码快照组合并
        $this->makeLogWithUpload(
            $task,
            'old-dir-20261018.tar.gz',
            '/backup/demo/old-dir-20261018.tar.gz',
            'bc_1:/backup/demo/old-dir-20261018.tar.gz',
        );

        $resp = (new RestoreController())->backups(new Request([
            'task_id' => $task->id,
            'range'      => 'month',
        ]));
        $this->assertSame(200, $resp->getStatusCode());
        $groups = $resp->getData(true)['data'];

        // 日期分组：全部文件都在「2 小时前」当天 → 单一日期组
        $this->assertCount(1, $groups);
        $this->assertSame($at->format('Y-m-d'), $groups[0]['date']);

        $suites = $groups[0]['suites'];
        // 编码快照套件（snap+dir，2 文件）与无编码旧归档（1 文件）各自独立成组
        $this->assertCount(2, $suites);

        $snapSuite = null;
        foreach ($suites as $suite) {
            if (collect($suite['files'])->contains(fn ($f) => $f['backup_log_id'] === $snap->id)) {
                $snapSuite = $suite;
            }
        }
        $this->assertNotNull($snapSuite, '快照日志应独立成组');
        $this->assertCount(2, $snapSuite['files']);
        $this->assertSame(['777', '777'], array_column($snapSuite['files'], 'batch_code'));

        $snapFile = collect($snapSuite['files'])->firstWhere('backup_log_id', $snap->id);
        $this->assertSame('snapshot', $snapFile['type']);
        $this->assertSame('快照', $snapFile['label']);
        $this->assertFalse($snapFile['restorable']); // 无上传 → 不可恢复

        $dirFile = collect($snapSuite['files'])->firstWhere('backup_log_id', $dirLog->id);
        $this->assertSame('archive', $dirFile['type']);
        $this->assertSame('目录归档', $dirFile['label']);
        $this->assertTrue($dirFile['restorable']);
    }

    /* ---------------- 5. RestoreController::store 快照下发 ---------------- */

    public function test_restore_store_snapshot_uses_dir_path_type_and_temp_local_dir(): void
    {
        $task = $this->makeTask($this->agentServer(), BackupTask::BACKUP_SOURCE_SELF, BackupTask::BACKUP_MODE_FULL, null);
        $snap = $this->makeLogWithUpload(
            $task,
            'demo-snap-www-20261019-143000',
            '/backup/demo/20261019-143000',
            'bc_1:/backup/demo/20261019-143000',
        );
        // 同套件内的目录归档文件日志：普通文件参数（无 path_type）
        $dirLog = $this->makeLogWithUpload(
            $task,
            'demo-dir-20261019-143000.tar.gz',
            '/data/backup/demo/demo-dir-20261019-143000.tar.gz',
            'bc_1:/backup/demo/demo-dir-20261019-143000.tar.gz',
        );

        $resp = (new RestoreController())->store(new Request(['backup_log_ids' => [$snap->id, $dirLog->id]]));

        $this->assertSame(200, $resp->getStatusCode());
        $commands = AgentCommand::where('type', AgentCommand::TYPE_RESTORE)->get()->keyBy(fn ($c) => $c->params['backup_log_id']);
        $this->assertCount(2, $commands);

        $snapCmd = $commands[$snap->id];
        $this->assertSame('dir', $snapCmd->params['path_type']);
        $this->assertStringStartsWith(sys_get_temp_dir() . '/bc-restore-inc-', $snapCmd->params['local_dir']);
        $this->assertSame('bc_1:/backup/demo/20261019-143000', $snapCmd->params['remote_path']);
        $this->assertArrayNotHasKey('restore_mode', $snapCmd->params); // lite 不追加

        $dirCmd = $commands[$dirLog->id];
        $this->assertArrayNotHasKey('path_type', $dirCmd->params);
        $this->assertSame('/data/backup/demo', $dirCmd->params['local_dir']);
    }

    /* ---------------- 附：retention 清理 is_dir 派发（规格 §2.9） ---------------- */

    public function test_cleanup_retention_dispatches_is_dir_for_snapshot_and_file(): void
    {
        $server  = $this->agentServer();
        $storage = $this->sftpStorage(['name' => '清理存储']);
        $task = $this->makeTask($server, BackupTask::BACKUP_SOURCE_SELF, BackupTask::BACKUP_MODE_INCREMENTAL, $storage->id);
        $task->update(['retention_days' => 7]);
        $prefix = 'bc_' . $storage->id . ':';

        $makeLog = function (string $filename, string $fullPath, string $sourceKey, string $at, string $remotePath) use ($task, $storage) {
            $log = BackupLog::create([
                'task_id'    => $task->id,
                'server_id'  => $task->server_id,
                'filename'   => $filename,
                'full_path'  => $fullPath,
                'source_key' => $sourceKey,
                'size'       => 1024,
                'status'     => BackupLog::STATUS_SUCCESS,
                'backup_at'  => $at,
            ]);
            BackupUpload::create([
                'backup_log_id' => $log->id,
                'storage_id'    => $storage->id,
                'status'        => BackupUpload::STATUS_UPLOADED,
                'remote_path'   => $remotePath,
            ]);
            return $log;
        };

        // 超期快照目录日志 + 同 source_key 的较新 keeper（护栏 4 保留 keeper，派发超期那条）
        $snapOld = $makeLog('demo-snap-www-20261001-120000', '/backup/demo/20261001-120000', BackupSource::SELF_SNAPSHOT, now()->subDays(40), $prefix . '/backup/demo/20261001-120000');
        $makeLog('demo-snap-www-20261019-120000', '/backup/demo/20261019-120000', BackupSource::SELF_SNAPSHOT, now()->subDay(), $prefix . '/backup/demo/20261019-120000');
        // 超期普通文件日志 + keeper
        $fileOld = $makeLog('demo-old.tar.gz', '/data/backup/demo-old.tar.gz', 'ext:/data/backup', now()->subDays(40), $prefix . 'demo/old.tar.gz');
        $makeLog('demo-new.tar.gz', '/data/backup/demo-new.tar.gz', 'ext:/data/backup', now()->subDay(), $prefix . 'demo/new.tar.gz');

        $this->artisan('bc:cleanup-retention')->assertExitCode(0);

        $cmd = AgentCommand::where('type', AgentCommand::TYPE_CLEANUP_RETENTION)->firstOrFail();
        $this->assertSame($server->id, $cmd->server_id);
        $files = collect($cmd->params['files'])->keyBy('log_id');
        $this->assertCount(2, $files);
        // 快照目录日志 → is_dir=true（Agent 走 rclone purge）；普通文件 → false（deletefile）
        $this->assertTrue($files[$snapOld->id]['is_dir']);
        $this->assertSame($prefix . '/backup/demo/20261001-120000', $files[$snapOld->id]['remote_path']);
        $this->assertFalse($files[$fileOld->id]['is_dir']);

        // 先删云端后删 DB：指令未上报成功，行必须还在
        $this->assertDatabaseHas('backup_logs', ['id' => $snapOld->id]);
        $this->assertDatabaseHas('backup_logs', ['id' => $fileOld->id]);
    }

    /* ---------------- 6. BackupSource::derive ---------------- */

    public function test_backup_source_derive_dir_and_snapshot(): void
    {
        // 目录快照日志（无扩展名，-snap-<seg>-<stamp>）→ self:snapshot
        $this->assertSame(
            BackupSource::SELF_SNAPSHOT,
            BackupSource::derive('demo-snap-www-20261019-143000', '/backup/demo/20261019-143000', [])
        );
        $this->assertSame('self:snapshot', BackupSource::derive('demo-snap-www-20261019-143000', null, []));

        // 目录打包归档 → self:dir（优先于 full_path 前缀匹配，临时目录也拦得住）
        $this->assertSame(
            BackupSource::SELF_DIR,
            BackupSource::derive('demo-dir-20261019-143000.tar.gz', '/tmp/bc-self-1-x/demo-dir-20261019-143000.tar.gz', [])
        );
        $this->assertSame('self:dir', BackupSource::derive('demo-dir-20261019-143000.tar.gz', '/data/backup/demo-dir-20261019-143000.tar.gz', [['path' => '/data/backup', 'pattern' => '*']]));
    }

    /* ---------------- 附：recentSelfBackups 目录归档 + 快照项（规格 §1.2） ---------------- */

    public function test_recent_self_backups_includes_dir_and_snapshot_entries(): void
    {
        $task = $this->makeTask($this->agentServer(), BackupTask::BACKUP_SOURCE_SELF, BackupTask::BACKUP_MODE_INCREMENTAL, $this->sftpStorage()->id);
        $dir = BackupLog::create([
            'task_id'     => $task->id,
            'server_id'   => $task->server_id,
            'filename'    => 'demo-dir-20261019-143000.tar.gz',
            'full_path'   => '/tmp/bc-self-9-x/demo-dir-20261019-143000.tar.gz',
            'origin_path' => '/var/www/demo',
            'size'        => 8192,
            'status'      => BackupLog::STATUS_SUCCESS,
            'backup_at'   => now()->subHour(),
        ]);
        $snap = BackupLog::create([
            'task_id'    => $task->id,
            'server_id'  => $task->server_id,
            'filename'   => 'demo-snap-www-20261019-143000',
            'full_path'  => '/backup/demo/20261019-143000',
            'size'       => 4096,
            'status'     => BackupLog::STATUS_SUCCESS,
            'backup_at'  => now()->subHour(),
        ]);

        $recent = $task->recentSelfBackups();

        $dirEntry = collect($recent)->firstWhere('label', '目录归档');
        $this->assertNotNull($dirEntry, 'recentSelfBackups 应含目录归档项');
        $this->assertSame('/var/www/demo', $dirEntry['path']);
        $this->assertSame($dir->id, $dirEntry['log']['id']);

        $snapEntry = collect($recent)->firstWhere('label', '快照');
        $this->assertNotNull($snapEntry, 'recentSelfBackups 应含快照项');
        $this->assertSame($snap->full_path, $snapEntry['path']);
        $this->assertSame($snap->id, $snapEntry['log']['id']);
    }
}
