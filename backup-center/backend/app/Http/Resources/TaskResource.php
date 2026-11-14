<?php

namespace App\Http\Resources;

use App\Models\BackupTask;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TaskResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var BackupTask $this */
        return [
            'id' => $this->id,
            'server_id' => $this->server_id,
            'server' => $this->whenLoaded('server', function () {
                return [
                    'id' => $this->server->id,
                    'name' => $this->server->name,
                    'status' => $this->server->computedStatus(),
                    'connect_type' => $this->server->connect_type,
                ];
            }),
            'storages' => $this->whenLoaded('storages', fn () => $this->storages->map(fn ($s) => [
                'id' => $s->id,
                'name' => $s->name,
                'type' => $s->type,
            ])),
            'storage_ids' => $this->whenLoaded('storages', fn () => $this->storages->pluck('id')->toArray()),
            'name' => $this->name,
            'backup_paths' => $this->backup_paths ?: [],
            'retention_days' => $this->retention_days,
            'schedule' => $this->schedule,
            'sync_mode' => $this->sync_mode ?: 'auto',
            'backup_source' => $this->backup_source ?: 'external',
            // 备份方式（P5）：full=目录打包；incremental=rsync 硬链快照（仅 backup_source=self 可选）
            'backup_mode' => $this->backup_mode ?: 'full',
            'incremental_storage_id' => $this->incremental_storage_id,
            'restore_mode' => $this->restore_mode ?: 'lite',

            'status' => $this->status,
            'remark' => $this->remark,

            'last_backup_at' => $this->last_backup_at?->toIso8601String(),
            'last_backup_status' => $this->last_backup_status,
            'last_backup_size' => $this->last_backup_size,
            'last_backup_filename' => $this->last_backup_filename,
            'recent_backups' => $this->recentBackupsByPath(),
            'recent_self_backups' => $this->when($this->backup_source === \App\Models\BackupTask::BACKUP_SOURCE_SELF, fn () => $this->recentSelfBackups()),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
