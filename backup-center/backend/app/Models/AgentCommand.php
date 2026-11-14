<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentCommand extends Model
{
    public const STATUS_PENDING    = 'pending';
    public const STATUS_DISPATCHED = 'dispatched';
    public const STATUS_SUCCESS    = 'success';
    public const STATUS_FAILED     = 'failed';

    public const TYPE_UPDATE   = 'update';
    public const TYPE_SYNC_NOW = 'sync_now';
    public const TYPE_RESTORE  = 'restore';
    // 目录打包（P3）：Agent 按 backup_paths 逐目录 tar 打包并上传。
    // params: {task_id, task_name} —— 绝不携带任何凭据。
    public const TYPE_PACK_BACKUP = 'pack_backup';
    // 目录快照（P5）：rsync --link-dest 硬链快照，推送至 SFTP 存储。
    // params: {task_id, task_name} —— 与 pack_backup 一致，绝不携带任何凭据。
    public const TYPE_INCREMENTAL_BACKUP = 'incremental_backup';
    // 保留期清理（C4/T2）：Center 按 retention_days 算出超期文件清单，Agent 用本地
    // rclone.conf 删云端（encryption+agent_local 时中心无密码，只能由 Agent 执行）。
    // params: {files: [{log_id, remote_path, is_dir}, ...]} —— 绝不携带任何凭据。
    public const TYPE_CLEANUP_RETENTION = 'cleanup_retention';
    // 目录浏览（v0.17.0）：Agent 列目录项元数据（name/is_dir/size/mtime），
    // 供网页「浏览备份目录」弹窗使用；params: {path} —— 不读文件内容、不带凭据。
    public const TYPE_BROWSE_DIR = 'browse_dir';

    protected $fillable = [
        'server_id',
        'type',
        'params',
        'status',
        'output',
        'error',
        'from_version',
        'to_version',
        'dispatched_at',
        'completed_at',
        'reconciled_at',
    ];

    /** dispatched 超过多少分钟视为 stale（sweeper 用；也用于放宽 exists 检查） */
    public const STALE_MINUTES = 10;

    protected function casts(): array
    {
        return [
            'params'        => 'array',
            'dispatched_at' => 'datetime',
            'completed_at'  => 'datetime',
            'reconciled_at' => 'datetime',
        ];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }
}
