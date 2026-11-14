<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\RestoreController;
use App\Models\BackupLog;
use App\Models\BackupTask;
use App\Models\BackupUpload;
use App\Models\Server;
use App\Models\Storage;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * 恢复页套件合并回归测试（RestoreController::backups / buildSuites）。
 *
 * 策略演进：
 * 1. 旧版纯 30 分钟时间窗口 + Carbon 3 diffInSeconds 有符号值 bug → 窗口恒真，
 *    同任务全部文件并成一个大套件，「19日卡片混入 18/17 日记录」（4265f44 修 bug）；
 * 2. 旧「站点目录↔数据库」类型配对 + 5 分钟窗口：web/db 成对合并——数据库导出
 *    能力移除后已废弃；
 * 3. 现行策略：batch_code 精确分组（主动备份一次指令 = 一码）＋ 无编码目录归档
 *    各自独立成组（相邻两场 external 归档不互并）＋ 无编码快照目录日志按
 *    5 分钟窗口兜底归并。窗口 300s 的依据（用户生产库实测）：
 *    同场次多文件间隔 ≤45s（宝塔两任务先后跑；主动备份两文件同秒），
 *    不同场次间隔 ≥600s（00:55 / 01:05 两次手动备份）。
 *
 * 数据形态取自用户生产库真实 12 条记录的等价改造，日期改为相对生成保证测试随时间稳定。
 */
class RestoreSuiteWindowTest extends TestCase
{
    use RefreshDatabase;

    private function makeTask(): BackupTask
    {
        $server = Server::create([
            'name'         => 'Agent 服务器',
            'connect_type' => Server::CONNECT_AGENT,
            'status'       => Server::STATUS_ONLINE,
        ]);

        return BackupTask::create([
            'server_id'      => $server->id,
            'name'           => 'hiimask',
            'backup_paths'   => [
                ['path' => '/www/backup', 'pattern' => '*.tar.gz', 'label' => '归档目录'],
            ],
            'backup_source'  => BackupTask::BACKUP_SOURCE_EXTERNAL,
            'restore_mode'   => BackupTask::RESTORE_MODE_LITE,
            'status'         => BackupTask::STATUS_ENABLED,
        ]);
    }

    private function makeRestorableLog(BackupTask $task, string $filename, Carbon $at, string $batchCode = ''): BackupLog
    {
        $storage = Storage::create([
            'name'   => '测试存储',
            'type'   => Storage::TYPE_SFTP,
            'config' => ['host' => '127.0.0.1'],
        ]);

        $log = BackupLog::create([
            'task_id'    => $task->id,
            'server_id'  => $task->server_id,
            'filename'   => $filename,
            'full_path'  => '/www/backup/' . $filename,
            'size'       => 1024,
            'status'     => BackupLog::STATUS_SUCCESS,
            'backup_at'  => $at,
            'batch_code' => $batchCode,
        ]);
        BackupUpload::create([
            'backup_log_id' => $log->id,
            'storage_id'    => $storage->id,
            'status'        => BackupUpload::STATUS_UPLOADED,
            'remote_path'   => '/remote/' . $filename,
        ]);

        return $log;
    }

    /**
     * 造出与生产库同构的 6 场备份（每场同一指令编码的 2 个目录归档）：
     *   今天 01:05 / 00:55（主动打包，两文件同秒；两场间隔 10 分钟 → 必须分开）
     *   昨天 04:41 / 03:05（external 扫描，第二文件晚 8s / 20s；两场间隔 96 分钟）
     *   2 天前 09:19（晚 11s）、4 天前 15:00（晚 45s，跨分钟边界）
     */
    private function seedProductionShape(BackupTask $task): void
    {
        $batches = [
            [Carbon::today()->setTime(1, 5, 31), 0,  'run1', 'demo-dir-www-010531.tar.gz',               'demo-dir-data-010531.tar.gz'],
            [Carbon::today()->setTime(0, 55, 31), 0,  'run2', 'demo-dir-www-005531.tar.gz',               'demo-dir-data-005531.tar.gz'],
            [Carbon::yesterday()->setTime(4, 41, 38), 8,  'run3', 'bt-archive-20261018_044137.tar.gz', 'bt-archive-20261018_044145.tar.gz'],
            [Carbon::yesterday()->setTime(3, 5, 17), 20, 'run4', 'bt-archive-20261018_030516.tar.gz', 'bt-archive-20261018_030537.tar.gz'],
            [Carbon::now()->subDays(2)->setTime(9, 19, 38), 11, 'run5', 'bt-archive-20261017_091937.tar.gz', 'bt-archive-20261017_091948.tar.gz'],
            [Carbon::now()->subDays(4)->setTime(15, 0, 50), 45, 'run6', 'bt-archive-20261015_150050.tar.gz', 'bt-archive-20261015_150135.tar.gz'],
        ];
        foreach ($batches as [$at, $gap, $code, $f1, $f2]) {
            $this->makeRestorableLog($task, $f1, $at, $code);
            $this->makeRestorableLog($task, $f2, $at->copy()->addSeconds($gap), $code);
        }
    }

    private function fetchGroups(BackupTask $task): array
    {
        $resp = (new RestoreController())->backups(new Request([
            'task_id' => $task->id,
            'range'      => 'month',
        ]));
        $this->assertSame(200, $resp->getStatusCode());

        return $resp->getData(true)['data'];
    }

    private function fetchSuites(BackupTask $task): array
    {
        return collect($this->fetchGroups($task))->pluck('suites')->flatten(1)->all();
    }

    public function test_production_shape_yields_one_suite_per_batch_code(): void
    {
        $task = $this->makeTask();
        $this->seedProductionShape($task);

        $suites = $this->fetchSuites($task);

        // 6 场备份 = 6 个套件（旧 30 分钟窗口会把今天两场糊成 1 个 → 只有 5 个套件）
        $this->assertCount(6, $suites);

        foreach ($suites as $suite) {
            // 每个套件恰好是同一指令编码的 2 个归档，且不跨天
            $this->assertCount(2, $suite['files'], 'suite_id=' . $suite['suite_id']);
            $codes = array_unique(array_column($suite['files'], 'batch_code'));
            $this->assertCount(1, $codes, '单个套件不得混编码；suite_id=' . $suite['suite_id']);
            $dates = array_unique(array_map(
                fn ($f) => Carbon::parse($f['backup_at'])->format('Y-m-d'),
                $suite['files']
            ));
            $this->assertCount(1, $dates, '单个套件不得跨天；suite_id=' . $suite['suite_id']);
        }

        // match：主动备份两文件 backup_at 同秒 → exact；external 第二文件晚 8–45s → window
        $this->assertSame('exact', $suites[0]['match']);  // 今天 01:05
        $this->assertSame('exact', $suites[1]['match']);  // 今天 00:55
        $this->assertSame('window', $suites[2]['match']); // 昨天 04:41（晚 8s）
        $this->assertSame('window', $suites[5]['match']); // 4 天前 15:00（晚 45s）

        // 今天：两场主动备份间隔 10 分钟 > 窗口 → 2 个套件（用户核心诉求）
        $todayGroup = collect($this->fetchGroups($task))->firstWhere('date', Carbon::today()->format('Y-m-d'));
        $this->assertCount(2, $todayGroup['suites']);
        $this->assertSame(2, $todayGroup['suites'][0]['total_count']);
        $this->assertSame(2, $todayGroup['suites'][1]['total_count']);

        // 套件倒序：最新场次（今天 01:05）在最前
        $this->assertSame('01:05:31', Carbon::parse($suites[0]['backup_at'])->format('H:i:s'));
    }

    public function test_coded_runs_close_in_time_do_not_merge(): void
    {
        $task = $this->makeTask();
        // 两场主动备份只隔 1 分钟（在 5 分钟窗口内！）但指令编码不同 → 必须 2 个套件。
        // 纯时间窗口会把它们糊成一组——用户担心的「窗口长了糊进别的任务」
        $this->makeRestorableLog($task, 'r1-dir-a.tar.gz', Carbon::now()->subMinutes(2), '101');
        $this->makeRestorableLog($task, 'r1-dir-b.tar.gz', Carbon::now()->subMinutes(2), '101');
        $this->makeRestorableLog($task, 'r2-dir-a.tar.gz', Carbon::now()->subMinutes(1), '102');
        $this->makeRestorableLog($task, 'r2-dir-b.tar.gz', Carbon::now()->subMinutes(1), '102');

        $suites = $this->fetchSuites($task);

        $this->assertCount(2, $suites);
        // 较新场次（编码 102）在前；每组同编码 2 文件
        $this->assertSame(['102', '102'], array_column($suites[0]['files'], 'batch_code'));
        $this->assertSame(['101', '101'], array_column($suites[1]['files'], 'batch_code'));
    }

    public function test_same_batch_code_merges_across_time(): void
    {
        $task = $this->makeTask();
        // 同一指令编码的两个归档相隔 10 分钟（远超窗口）→ 仍为 1 个套件。
        // 编码是确定性信号：一次指令产出的文件必然同场次
        //（大目录打包慢导致第二个归档上报晚于第一个是真实场景）
        $this->makeRestorableLog($task, 'slow-dir-a.tar.gz', Carbon::now()->subMinutes(20), '205');
        $this->makeRestorableLog($task, 'slow-dir-b.tar.gz', Carbon::now()->subMinutes(10), '205');

        $suites = $this->fetchSuites($task);

        $this->assertCount(1, $suites);
        $this->assertCount(2, $suites[0]['files']);
        $this->assertSame(['205', '205'], array_column($suites[0]['files'], 'batch_code'));
    }

    public function test_uncoded_archives_each_own_suite_even_when_close(): void
    {
        $task = $this->makeTask();
        // 两场 external 归档只隔 4 分钟：目录归档之间没有可靠的成对信号，
        // 不得靠时间窗口互并（旧 5 分钟窗口会把相邻两场糊成一组）
        $this->makeRestorableLog($task, 'a-dir.tar.gz', Carbon::now()->subMinutes(8));
        $this->makeRestorableLog($task, 'b-dir.tar.gz', Carbon::now()->subMinutes(4));

        $suites = $this->fetchSuites($task);

        $this->assertCount(2, $suites);
        foreach ($suites as $suite) {
            $this->assertCount(1, $suite['files']);
        }
        // 较新的 b 在前
        $this->assertSame('suite-' . BackupLog::where('filename', 'b-dir.tar.gz')->value('id'), $suites[0]['suite_id']);
    }

    public function test_uncoded_snapshot_logs_group_by_window(): void
    {
        $task = $this->makeTask();
        // 仅快照目录日志（无编码）：走窗口兜底合并
        $this->makeSnapshotLog($task, Carbon::now()->subMinutes(20));
        $this->makeSnapshotLog($task, Carbon::now()->subMinutes(17)); // 距上一场 3 分钟 → 合并
        $this->makeSnapshotLog($task, Carbon::now()->subMinutes(7));   // 距组内最早 13 分钟 → 独立

        $suites = $this->fetchSuites($task);

        // 套件按组内最新文件倒序：最近（7 分钟前）在前；
        // 20 分钟前 + 17 分钟前相隔 3 分钟 ≤ 窗口 → 合并为第二组
        $this->assertCount(2, $suites);
        $this->assertCount(1, $suites[0]['files']);
        $this->assertCount(2, $suites[1]['files']);
    }

    public function test_coded_run_merges_across_minute_boundary(): void
    {
        $task = $this->makeTask();
        // 宝塔式 external 也能带编码：两文件 15:00:50 + 15:01:35，跨分钟边界 45s
        $this->makeRestorableLog($task, 'site-dir-150050.tar.gz', Carbon::now()->subMinutes(30)->setTime(15, 0, 50), '601');
        $this->makeRestorableLog($task, 'site-dir-150135.tar.gz', Carbon::now()->subMinutes(30)->setTime(15, 1, 35), '601');

        $suites = $this->fetchSuites($task);

        $this->assertCount(1, $suites);
        $this->assertCount(2, $suites[0]['files']);
    }

    public function test_uncoded_far_apart_files_stay_separate(): void
    {
        $task = $this->makeTask();
        // 无编码归档彼此相距再近也独立成组；3 小时前的旧归档更不可能并进来
        $this->makeRestorableLog($task, 'a-dir.tar.gz', Carbon::now()->subMinutes(10));
        $this->makeRestorableLog($task, 'b-dir.tar.gz', Carbon::now()->subMinutes(4));
        $this->makeRestorableLog($task, 'old-dir.tar.gz', Carbon::now()->subHours(3));

        $suites = $this->fetchSuites($task);

        $this->assertCount(3, $suites);
        $this->assertSame('suite-' . BackupLog::where('filename', 'b-dir.tar.gz')->value('id'), $suites[0]['suite_id']);
    }

    public function test_coded_and_uncoded_files_do_not_mix(): void
    {
        $task = $this->makeTask();
        // 主动备份（带编码）与 external 扫描（无编码）混在同一任务：两条路径互不干扰
        $this->makeRestorableLog($task, 'self-dir-a.tar.gz', Carbon::now()->subMinutes(3), '301');
        $this->makeRestorableLog($task, 'self-dir-b.tar.gz', Carbon::now()->subMinutes(3), '301');
        $this->makeRestorableLog($task, 'bt-dir-20261019.tar.gz', Carbon::now()->subMinutes(2));

        $suites = $this->fetchSuites($task);

        $this->assertCount(2, $suites);
        $withCode = array_values(array_filter($suites, fn ($s) => $s['files'][0]['batch_code'] !== ''));
        $this->assertCount(1, $withCode);
        $this->assertCount(2, $withCode[0]['files']);
        $this->assertSame(['301', '301'], array_column($withCode[0]['files'], 'batch_code'));
    }

    public function test_day_groups_only_contain_that_days_files(): void
    {
        $task = $this->makeTask();
        $this->seedProductionShape($task);

        $groups = $this->fetchGroups($task);

        $this->assertSame([
            Carbon::today()->format('Y-m-d'),
            Carbon::yesterday()->format('Y-m-d'),
            Carbon::now()->subDays(2)->format('Y-m-d'),
            Carbon::now()->subDays(4)->format('Y-m-d'),
        ], array_column($groups, 'date'));

        foreach ($groups as $group) {
            foreach ($group['suites'] as $suite) {
                foreach ($suite['files'] as $f) {
                    $this->assertSame($group['date'], Carbon::parse($f['backup_at'])->format('Y-m-d'));
                }
            }
        }
    }

    /** 无编码快照目录日志（filename 命中 -snap-.*-\d{8}-\d{6}$，无上传） */
    private function makeSnapshotLog(BackupTask $task, Carbon $at): BackupLog
    {
        return BackupLog::create([
            'task_id'    => $task->id,
            'server_id'  => $task->server_id,
            'filename'   => 'demo-snap-www-' . $at->format('Ymd-His'),
            'full_path'  => '/backup/demo/' . $at->format('Ymd-His'),
            'size'       => 4096,
            'status'     => BackupLog::STATUS_SUCCESS,
            'backup_at'  => $at,
        ]);
    }
}
