<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\AgentController;
use App\Http\Controllers\Api\RestoreController;
use App\Models\AgentCommand;
use App\Models\BackupLog;
use App\Models\BackupTask;
use App\Models\BackupUpload;
use App\Models\Server;
use App\Models\Storage;
use App\Support\BackupSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * v1.1.3 打包产物「备份时间命名 + 存储端去日期层」的后端契约测试：
 * - report：Agent v0.18+ 上报携带 source_type（dir/file）→ source_key 直写
 *   self:dir / self:file（新命名 <task>-<stamp>.tar.gz 无 -dir-/-file- 文件名标记，
 *   文件名推导无法识别）；不带 source_type（external 扫描 / 老版 Agent）→
 *   BackupSource::derive 文件名推导兜底，老行为不变。
 * - restore store()：advanced 模式对 self:dir / self:file 日志下发
 *   path_type=self_archive（Agent 依此分流预备份+覆盖解压，不再依赖文件名标记）；
 *   external 日志不携带该参数（保持留档语义）。
 * - recentSelfBackups：任务列表「最近备份」按 source_key 匹配新命名产物
 *   （文件勾选产物标「文件归档」），修正此前按 -dir- 文件名匹配漏掉
 *   -file- 产物的问题。
 */
class PackArchiveNamingTest extends TestCase
{
    use RefreshDatabase;

    private function agentServer(): Server
    {
        return Server::create([
            'name'         => 'Agent 服务器',
            'connect_type' => Server::CONNECT_AGENT,
            'status'       => Server::STATUS_ONLINE,
        ]);
    }

    private function makeTask(Server $server, string $restoreMode = BackupTask::RESTORE_MODE_LITE): BackupTask
    {
        return BackupTask::create([
            'server_id'      => $server->id,
            'name'           => 'FC2',
            'backup_paths'   => [
                ['path' => '/mnt/FC2', 'pattern' => '', 'label' => 'FC2'],
            ],
            'backup_source'  => BackupTask::BACKUP_SOURCE_SELF,
            'restore_mode'   => $restoreMode,
            'status'         => BackupTask::STATUS_ENABLED,
        ]);
    }

    /** 模拟 Agent v0.18+ 的 report 请求（直接调控制器，绕开 agent 中间件） */
    private function reportAs(Server $server, array $payload)
    {
        $request = Request::create('/api/agent/report', 'POST', $payload);
        $request->attributes->set('server', $server);

        return (new AgentController())->report($request);
    }

    /* ---------------- report：source_type 直写 source_key ---------------- */

    public function test_report_with_source_type_writes_self_key_directly(): void
    {
        $server = $this->agentServer();
        $task = $this->makeTask($server);

        // 新命名的单文件归档：文件名无 -file- 标记，全靠 source_type 识别
        $resp = $this->reportAs($server, [
            'task_id'     => $task->id,
            'filename'    => 'FC2-20260809-040000.tar.gz',
            'full_path'   => sys_get_temp_dir() . '/bc-pack-' . $task->id . '-x/FC2-20260809-040000.tar.gz',
            'size'        => 1024,
            'backup_at'   => now()->toIso8601String(),
            'status'      => 'success',
            'origin_path' => '/mnt/FC2',
            'batch_code'  => '101',
            'source_type' => 'file',
        ]);

        $this->assertSame(200, $resp->getStatusCode());
        $log = BackupLog::where('task_id', $task->id)->first();
        $this->assertNotNull($log);
        $this->assertSame(BackupSource::SELF_FILE, $log->source_key);
        $this->assertSame('/mnt/FC2', $log->origin_path);

        // 整目录归档：source_type=dir
        $this->reportAs($server, [
            'task_id'     => $task->id,
            'filename'    => 'FC2-20260809-050000.tar.gz',
            'full_path'   => sys_get_temp_dir() . '/bc-pack-' . $task->id . '-x/FC2-20260809-050000.tar.gz',
            'size'        => 2048,
            'backup_at'   => now()->toIso8601String(),
            'status'      => 'success',
            'origin_path' => '/mnt/FC2',
            'batch_code'  => '102',
            'source_type' => 'dir',
        ]);
        $dirLog = BackupLog::where('filename', 'FC2-20260809-050000.tar.gz')->first();
        $this->assertSame(BackupSource::SELF_DIR, $dirLog->source_key);
    }

    public function test_report_without_source_type_falls_back_to_filename_derivation(): void
    {
        $server = $this->agentServer();
        $task = $this->makeTask($server);

        // external 扫描 / 老版 Agent：不带 source_type，走文件名推导
        $this->reportAs($server, [
            'task_id'   => $task->id,
            'filename'  => 'demo-dir-FC2-20260809-040000.tar.gz',
            'full_path' => '/data/backup/site/demo-dir-FC2-20260809-040000.tar.gz',
            'size'      => 512,
            'backup_at' => now()->toIso8601String(),
            'status'    => 'success',
        ]);

        $log = BackupLog::where('task_id', $task->id)->first();
        $this->assertNotNull($log);
        $this->assertSame('self:dir', $log->source_key);
    }

    /* ---------------- restore：path_type=self_archive 下发 ---------------- */

    private function makeLogWithUpload(BackupTask $task, string $sourceKey, string $filename): BackupLog
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
            'full_path'   => sys_get_temp_dir() . '/bc-pack-' . $task->id . '-x/' . $filename,
            'origin_path' => '/mnt/FC2',
            'source_key'  => $sourceKey,
            'status'      => BackupLog::STATUS_SUCCESS,
            'backup_at'   => now(),
        ]);
        BackupUpload::create([
            'backup_log_id' => $log->id,
            'storage_id'    => $storage->id,
            'status'        => BackupUpload::STATUS_UPLOADED,
            'remote_path'   => 'bc_1:/root/BC/FC2/' . $filename,
        ]);

        return $log;
    }

    public function test_restore_advanced_carries_self_archive_path_type(): void
    {
        $server = $this->agentServer();
        $task = $this->makeTask($server, BackupTask::RESTORE_MODE_ADVANCED);
        $log = $this->makeLogWithUpload($task, BackupSource::SELF_DIR, 'FC2-20260809-040000.tar.gz');

        $resp = (new RestoreController())->store(new Request(['backup_log_ids' => [$log->id]]));

        $this->assertSame(200, $resp->getStatusCode());
        $cmd = AgentCommand::where('type', AgentCommand::TYPE_RESTORE)->latest('id')->first();
        $this->assertNotNull($cmd);
        $this->assertSame('advanced', $cmd->params['restore_mode']);
        $this->assertSame('/mnt/FC2', $cmd->params['origin_path']);
        // 新命名归档：Agent 依此参数分流（不再依赖文件名 -dir-/-file- 标记）
        $this->assertSame('self_archive', $cmd->params['path_type']);
        // 文件归档（self:file）同样携带
        $fileLog = $this->makeLogWithUpload($task, BackupSource::SELF_FILE, 'FC2-20260809-060000.tar.gz');
        (new RestoreController())->store(new Request(['backup_log_ids' => [$fileLog->id]]));
        $fileCmd = AgentCommand::where('type', AgentCommand::TYPE_RESTORE)->latest('id')->first();
        $this->assertSame('self_archive', $fileCmd->params['path_type']);
    }

    public function test_restore_external_log_does_not_carry_path_type(): void
    {
        $server = $this->agentServer();
        $task = $this->makeTask($server, BackupTask::RESTORE_MODE_ADVANCED);
        // external 归档：无 path_type（Agent 侧不会有文件名标记 → 自动落到留档提示）
        $log = $this->makeLogWithUpload($task, 'ext:/data/backup/site', 'thirdparty_20260809.tar.gz');

        $resp = (new RestoreController())->store(new Request(['backup_log_ids' => [$log->id]]));

        $this->assertSame(200, $resp->getStatusCode());
        $cmd = AgentCommand::where('type', AgentCommand::TYPE_RESTORE)->latest('id')->first();
        $this->assertArrayNotHasKey('path_type', $cmd->params);
    }

    /* ---------------- 任务列表「最近备份」按 source_key 匹配 ---------------- */

    public function test_recent_self_backups_matches_new_naming_by_source_key(): void
    {
        $server = $this->agentServer();
        $task = $this->makeTask($server);

        // 新命名的文件归档（v1.1.2 按文件名匹配会漏掉它）
        $this->makeLogWithUpload($task, BackupSource::SELF_FILE, 'FC2-20260809-040000.tar.gz');

        $out = $task->fresh()->recentSelfBackups();

        $this->assertCount(1, $out);
        $this->assertSame('文件归档', $out[0]['label']);
        $this->assertSame('/mnt/FC2', $out[0]['path']);

        // 目录归档 → 目录归档标签
        $this->makeLogWithUpload($task, BackupSource::SELF_DIR, 'FC2-20260810-040000.tar.gz');
        $out2 = $task->fresh()->recentSelfBackups();
        $this->assertSame('目录归档', $out2[0]['label']);
    }
}
