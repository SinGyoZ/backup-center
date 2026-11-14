<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backup_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained('servers')->cascadeOnDelete();

            $table->string('name');                       // 任务名（如"我的博客目录"）
            $table->json('backup_paths')->nullable();     // 备份目录列表 [{label,path,pattern}]
            $table->unsignedSmallInteger('retention_days')->default(30);
            $table->string('schedule')->nullable();       // cron 表达式（策略，MVP ⑤ 使用）

            // 同步方式：auto = Agent 每 2 分钟自动扫描（默认）；
            // scheduled = Agent 不主动扫，Center 每天到点派发 sync_now
            $table->string('sync_mode', 16)->default('auto');

            // 备份来源：external=归档收集（扫描第三方归档目录）；self=主动备份（P3）
            $table->string('backup_source', 16)->default('external');
            // 备份方式：full=目录打包；incremental=rsync 硬链快照（仅 backup_source=self 可选）
            $table->string('backup_mode', 16)->default('full');
            // 目录快照目标存储（单值；必须是 SFTP + SSH 私钥 + 未加密存储）
            $table->unsignedBigInteger('incremental_storage_id')->nullable();
            // 恢复模式：lite=留档（拉回复原目录，手动处理）；advanced=高级（自动恢复，P4 落地）
            $table->string('restore_mode', 16)->default('lite');

            $table->enum('status', ['enabled', 'disabled'])->default('enabled');
            $table->text('remark')->nullable();

            // Agent 上报的最新备份信息
            $table->timestamp('last_backup_at')->nullable();
            $table->enum('last_backup_status', ['success', 'failed', 'running'])->nullable();
            $table->unsignedBigInteger('last_backup_size')->nullable();
            $table->string('last_backup_filename')->nullable();

            $table->timestamps();

            $table->index(['server_id', 'status']);
            $table->index('last_backup_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_tasks');
    }
};
