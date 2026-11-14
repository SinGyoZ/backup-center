<?php

namespace App\Support;

/**
 * backup_logs.source_key 的唯一推导实现。
 *
 * 设计要点：
 * - 纯函数、不查库：写入点（AgentController::report）、后续消费方
 *   都必须调用它，避免多处逻辑漂移。
 * - 判定顺序必须先 filename、后 full_path：主动备份（self）的 full_path 是
 *   Agent 的临时目录（os.MkdirTemp("", "bc-self-<id>-")）或远端快照目录，
 *   永远前缀匹配不上配置的备份目录，若先做前缀匹配会全部漏成 ext:。
 */
class BackupSource
{
    /** self 目录打包归档（≤v0.17 旧命名）：<task>-dir-<label>-<ts>.tar.gz。
     *  v0.18 起改为 <task>-<ts>.tar.gz，来源由 report 的 source_type 字段直写，
     *  此正则仅做旧数据兜底。 */
    public const SELF_DIR = 'self:dir';

    /** self 单文件归档（≤v0.17 精细勾选的旧命名）：<task>-file-<label>-<ts>.tar.gz。
     *  v0.18 起与目录归档同命名，来源由 report 的 source_type 字段直写，
     *  此正则仅做旧数据兜底。 */
    public const SELF_FILE = 'self:file';

    /** self 目录快照日志：xxx-snap-<ts>（无扩展名；full_path 是远端快照目录） */
    public const SELF_SNAPSHOT = 'self:snapshot';

    /** external：full_path 前缀命中 backup_tasks.backup_paths[i].path 时的前缀 */
    public const EXT_PREFIX = 'ext:';

    /**
     * 推导一条 backup_log 的来源键。
     *
     * @param string      $filename    备份文件名（不含路径）
     * @param string|null $fullPath    备份完整路径（self 备份这里是临时目录/远端快照目录）
     * @param array       $backupPaths backup_tasks.backup_paths（每项 ['path'=>..,'pattern'=>..,'label'=>..]）
     * @return string self:dir / self:file / self:snapshot / ext:<命中的 path> / ext:（external 但匹配不到目录）
     */
    public static function derive(string $filename, ?string $fullPath, array $backupPaths): string
    {
        // ① self：文件名模式优先
        if (preg_match('/-dir-.*\.tar\.gz$/', $filename)) {
            return self::SELF_DIR;
        }
        // v0.17.0：单文件归档（目录打包精细勾选）；与 -dir- 同为 Agent 主动产物，
        // 必须在 ext 前缀匹配之前判定（full_path 是 Agent 临时目录，匹配不上备份目录）
        if (preg_match('/-file-.*\.tar\.gz$/', $filename)) {
            return self::SELF_FILE;
        }
        if (preg_match('/-snap-.*-\d{8}-\d{6}$/', $filename)) {
            return self::SELF_SNAPSHOT;
        }

        // ② external：full_path 前缀匹配配置的备份目录
        if ($fullPath !== null && $fullPath !== '') {
            foreach ($backupPaths as $bp) {
                $p = is_array($bp) ? ($bp['path'] ?? null) : null;
                if ($p !== null && $p !== '' && str_starts_with($fullPath, $p)) {
                    return self::EXT_PREFIX . $p;
                }
            }
        }

        return self::EXT_PREFIX;
    }
}
