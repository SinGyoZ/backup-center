<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backup_logs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('task_id')->constrained('backup_tasks')->cascadeOnDelete();
            $table->foreignId('server_id')->constrained('servers')->cascadeOnDelete();

            $table->string('filename');                    // 备份文件名（不含路径）
            $table->string('full_path', 1024)->nullable();  // 备份完整路径（本地或远端快照目录，与上报校验 max:1024 对齐）
            // 来源键（App\Support\BackupSource::derive 推导）：
            // self:dir=目录打包归档；self:snapshot=目录快照；ext:<path>=归档收集。
            // 默认 ''（而非 NULL）：避免 GROUP BY / JOIN 时 NULL 的语义坑（NULL 分组能分，
            // 但 JOIN ON source_key = source_key 对 NULL 不成立）。
            // 长度 300：external 的取值是 'ext:' + 备份目录，backup_paths.*.path 上限 255，
            // 拼上 4 字符前缀后最大 259，超过 255 会在严格模式下报 "Data too long"。
            $table->string('source_key', 300)->nullable()->default('');
            // 任务编码：pack_backup / incremental_backup 指令 ID，同编码必同场次；
            // 归档收集扫描上报不带编码（保持 ''），走窗口兜底合并。默认 '' 避免 NULL 分组语义坑。
            $table->string('batch_code', 64)->nullable()->default('');
            // 来源目录：归档收集=扫描文件父目录；目录打包=被打包目录；目录快照=被快照目录
            //（与 full_path 同宽 1024；高级恢复时作为 Agent 侧的恢复目标）
            $table->string('origin_path', 1024)->nullable();
            $table->unsignedBigInteger('size')->nullable();// 字节数
            $table->enum('status', ['success', 'failed', 'running'])->default('success');
            $table->string('error_message')->nullable();

            $table->timestamp('backup_at');                // 备份文件的 mtime（真正的备份完成时刻）
            $table->unsignedInteger('duration_seconds')->nullable(); // 备份用时（如果 Agent 能推算）

            // Rclone 上传状态（后续 MVP ⑤ 用）
            $table->enum('upload_status', ['pending', 'uploading', 'uploaded', 'upload_failed'])
                ->default('pending');
            $table->string('upload_target')->nullable();   // 存储目标名字（阿里云盘等）
            $table->timestamp('uploaded_at')->nullable();

            $table->timestamps();

            // 幂等：同一任务的同一完整路径只有一条记录。
            // 刻意不建唯一索引（应用层 AgentController::report 的 firstOrNew 保证）。
            // 也不建 (task_id, full_path) 复合索引：full_path 加宽到 1024 后
            // utf8mb4 下复合索引会超出 MySQL 3072 字节键长限制；
            // task_id 前缀查询由下方 (task_id, source_key, backup_at) 等索引覆盖。

            $table->index(['task_id', 'source_key', 'backup_at']);
            $table->index(['task_id', 'batch_code']);
            $table->index(['task_id', 'backup_at']);
            $table->index('status');
            $table->index('upload_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_logs');
    }
};
