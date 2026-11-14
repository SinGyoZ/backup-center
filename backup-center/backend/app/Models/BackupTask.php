<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BackupTask extends Model
{
    public const STATUS_ENABLED  = 'enabled';
    public const STATUS_DISABLED = 'disabled';

    public const BACKUP_SUCCESS = 'success';
    public const BACKUP_FAILED  = 'failed';
    public const BACKUP_RUNNING = 'running';

    // 备份来源：external=归档收集（扫描第三方归档目录）；self=主动备份（目录打包/目录快照）
    public const BACKUP_SOURCE_EXTERNAL = 'external';
    public const BACKUP_SOURCE_SELF     = 'self';

    // 备份方式：full=目录打包；incremental=rsync 硬链快照（仅 backup_source=self 可选）
    public const BACKUP_MODE_FULL         = 'full';
    public const BACKUP_MODE_INCREMENTAL  = 'incremental';

    // 同步方式：auto=Agent 自动扫描（默认）；scheduled=Center 按 schedule 派发 sync_now
    public const SYNC_MODE_AUTO      = 'auto';
    public const SYNC_MODE_SCHEDULED = 'scheduled';

    // 恢复模式：lite=留档（拉回复原目录，手动处理）；advanced=高级自动恢复
    public const RESTORE_MODE_LITE      = 'lite';
    public const RESTORE_MODE_ADVANCED  = 'advanced';

    protected $fillable = [
        'server_id',
        'name',
        'backup_paths',
        'retention_days',
        'schedule',
        'sync_mode',
        'backup_source',
        'backup_mode',
        'incremental_storage_id',
        'restore_mode',
        'status',
        'remark',
        'last_backup_at',
        'last_backup_status',
        'last_backup_size',
        'last_backup_filename',
    ];

    protected function casts(): array
    {
        return [
            'server_id' => 'integer',
            'retention_days' => 'integer',
            'backup_paths' => 'array',
            'last_backup_at' => 'datetime',
            'last_backup_size' => 'integer',
        ];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function backupLogs(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        // 外键必须显式声明：backup_logs 表的列名是 task_id（不是 Laravel 约定的 backup_task_id）
        return $this->hasMany(BackupLog::class, 'task_id');
    }

    public function storages(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        // 外键必须显式声明：迁移里 pivot 列名是 task_id（不是 Laravel 约定的 backup_task_id），
        // 只传表名时 Laravel 会按约定推断出 backup_task_id，导致 SQLSTATE[42S22] Unknown column
        return $this->belongsToMany(Storage::class, 'backup_task_storages', 'task_id', 'storage_id')->withTimestamps();
    }

    /**
     * 按每个 backup_paths 项聚合最新的 backup_log + uploads 情况。
     * 前端任务列表用这个字段展示"目录归档 成功 · 快照 失败(七牛)"这类分行结果。
     */
    public function recentBackupsByPath(): array
    {
        $paths = $this->backup_paths ?? [];
        if (! is_array($paths) || empty($paths)) return [];

        $result = [];
        foreach ($paths as $idx => $bp) {
            $path = $bp['path'] ?? '';
            $label = $bp['label'] ?? ('备份目录' . ($idx + 1));
            if ($path === '') {
                $result[] = ['label' => $label, 'path' => '', 'log' => null];
                continue;
            }
            // full_path 前缀匹配（Agent 上报的文件都在这个目录下）
            $log = $this->backupLogs()
                ->where('full_path', 'like', $path . '%')
                ->orderByDesc('backup_at')
                ->orderByDesc('id')
                ->with(['uploads.storage:id,name,type'])
                ->first();
            if (! $log) {
                $result[] = [
                    'label' => $label, 'path' => $path,
                    'log' => null,
                ];
                continue;
            }
            $result[] = [
                'label' => $label,
                'path'  => $path,
                'log'   => $this->buildLogSummary($log),
            ];
        }
        return $result;
    }

    /**
     * 把一条 BackupLog 整理成前端展示用的摘要（成功/失败的上传、时间等）。
     * recentBackupsByPath 与 recentSelfBackups 共用。
     */
    private function buildLogSummary(\App\Models\BackupLog $log): array
    {
        $failed = $log->uploads
            ->filter(fn ($u) => $u->status === \App\Models\BackupUpload::STATUS_FAILED)
            ->map(fn ($u) => [
                'storage_id'   => $u->storage_id,
                'storage_name' => $u->storage?->name ?: '（存储已删除）',
                'error'        => $u->error_message,
            ])->values()->all();
        $succeeded = $log->uploads
            ->filter(fn ($u) => $u->status === \App\Models\BackupUpload::STATUS_UPLOADED)
            ->map(fn ($u) => [
                'storage_id'   => $u->storage_id,
                'storage_name' => $u->storage?->name ?: '（存储已删除）',
            ])->values()->all();
        return [
            'id'            => $log->id,
            'filename'      => $log->filename,
            'status'        => $log->status,
            'upload_status' => $log->upload_status,
            'size'          => $log->size,
            'backup_at'     => $log->backup_at?->toIso8601String(),
            'synced_at'     => $log->created_at?->toIso8601String(),
            'succeeded'     => $succeeded,
            'failed'        => $failed,
        ];
    }

    /**
     * 主动备份任务（backup_source=self）的最近一次产物：
     * 打包归档（目录/单文件，来源目录=origin_path）
     * 与目录快照(xxx-snap-<ts>) 各取最新一条。
     * 前端任务列表「最近备份」列在 self 模式下回落到这里展示。
     *
     * v1.1.3：打包产物改用 <任务名>-<时间>.tar.gz 命名（不再含 -dir-/-file- 标记），
     * 匹配改按 source_key（self:dir / self:file，上报时直写）；文件名 like '-dir-'
     * 仅兜底 source_key 为空的极老历史行。快照命名带 -snap- 不变，两种口径都可靠。
     */
    public function recentSelfBackups(): array
    {
        $packLog = $this->backupLogs()
            ->where(function ($q) {
                $q->whereIn('source_key', [
                    \App\Support\BackupSource::SELF_DIR,
                    \App\Support\BackupSource::SELF_FILE,
                ])->orWhere('filename', 'like', '%-dir-%.tar.gz');
            })
            ->orderByDesc('backup_at')->orderByDesc('id')
            ->with(['uploads.storage:id,name,type'])->first();
        $snapLog = $this->backupLogs()
            ->where('filename', 'like', '%-snap-%')
            ->orderByDesc('backup_at')->orderByDesc('id')
            ->with(['uploads.storage:id,name,type'])->first();
        $out = [];
        if ($packLog) {
            // 文件勾选产物标「文件归档」，整目录产物标「目录归档」（与恢复页口径一致）
            $isFile = (string) $packLog->source_key === \App\Support\BackupSource::SELF_FILE;
            $out[] = ['label' => $isFile ? '文件归档' : '目录归档', 'path' => $packLog->origin_path ?: '', 'log' => $this->buildLogSummary($packLog)];
        }
        if ($snapLog) {
            // 目录快照：full_path 是远端快照目录（Agent 上报约定），直接展示
            $out[] = ['label' => '快照', 'path' => $snapLog->full_path, 'log' => $this->buildLogSummary($snapLog)];
        }
        return $out;
    }
}
