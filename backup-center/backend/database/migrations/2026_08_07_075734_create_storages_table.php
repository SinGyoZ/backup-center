<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('storages', function (Blueprint $table) {
            $table->id();

            $table->string('name');                 // 显示名，如"HostBrr Storage Box"
            $table->string('type', 32);             // sftp | webdav | s3
            $table->text('config');                 // 加密的 JSON，含账号/密码/密钥等敏感信息
            $table->string('base_path')->nullable();// 备份文件的目标目录（可选，默认为 /）

            $table->enum('status', ['enabled', 'disabled'])->default('enabled');
            $table->text('remark')->nullable();

            $table->enum('last_test_status', ['ok', 'failed'])->nullable();
            $table->string('last_test_message', 500)->nullable();
            $table->timestamp('last_tested_at')->nullable();

            $table->timestamps();
            $table->index(['type', 'status']);
        });

        Schema::create('backup_task_storages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained('backup_tasks')->cascadeOnDelete();
            $table->foreignId('storage_id')->constrained('storages')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['task_id', 'storage_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_task_storages');
        Schema::dropIfExists('storages');
    }
};
