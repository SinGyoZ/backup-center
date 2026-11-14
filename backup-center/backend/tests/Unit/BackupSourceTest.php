<?php

namespace Tests\Unit;

use App\Support\BackupSource;
use PHPUnit\Framework\TestCase;

class BackupSourceTest extends TestCase
{
    private function paths(): array
    {
        return [
            ['path' => '/data/backup/site', 'pattern' => '*.tar.gz', 'label' => '站点归档'],
            ['path' => '/data/backup/db', 'pattern' => '*.sql.gz', 'label' => '数据库归档'],
        ];
    }

    /** ① self 目录打包归档：文件名 -dir-*.tar.gz */
    public function test_self_dir_filename(): void
    {
        $this->assertSame(
            'self:dir',
            BackupSource::derive('mysite-dir-20260809.tar.gz', '/tmp/bc-self-12-abc/mysite-dir-20260809.tar.gz', $this->paths())
        );
    }

    /**
     * self 备份的 full_path 是 Agent 的临时目录（bc-self-<id>-），不是配置的备份目录。
     * 这个 case 锁死「filename 匹配优先于 full_path 前缀匹配」的顺序。
     */
    public function test_self_dir_wins_over_path_prefix_in_temp_dir(): void
    {
        $tmp = sys_get_temp_dir() . '/bc-self-12-abc/foo-dir-123.tar.gz';

        $this->assertSame('self:dir', BackupSource::derive('foo-dir-123.tar.gz', $tmp, $this->paths()));
    }

    /** self 目录快照：<task>-snap-<label>-<ts> 结尾（无扩展名；full_path 是远端快照目录；格式见 incremental.go:212） */
    public function test_self_snapshot_filename(): void
    {
        $this->assertSame(
            BackupSource::SELF_SNAPSHOT,
            BackupSource::derive('mysite-snap-web-20260809-143000', '/backup/mysite/20260809-143000', [])
        );
        $this->assertSame('self:snapshot', BackupSource::derive('mysite-snap-web-20260809-143000', null, []));
    }

    /** self 单文件归档（v0.17.0 精细勾选）：-file-*.tar.gz，包内保留父目录层 */
    public function test_self_file_filename(): void
    {
        $this->assertSame(
            BackupSource::SELF_FILE,
            BackupSource::derive('mysite-file-web-20260809-143000.tar.gz', '/tmp/bc-pack-12-abc/mysite-file-web-20260809-143000.tar.gz', $this->paths())
        );
        $this->assertSame('self:file', BackupSource::derive('conf-file-etc-20260809-143000.tar.gz', null, $this->paths()));
    }

    /**
     * v1.1.3 新命名 <task>-<stamp>.tar.gz 不再携带 -dir-/-file- 标记：
     * 文件名推导回落 ext:（来源改由 report 请求的 source_type 字段直写，
     * 见 AgentController::report 与 PackArchiveNamingTest）。
     */
    public function test_new_flat_naming_is_not_self_by_filename(): void
    {
        $this->assertSame('ext:', BackupSource::derive('FC2-20260809-040000.tar.gz', null, $this->paths()));
        $this->assertSame('ext:/mnt/FC2', BackupSource::derive('FC2-20260809-040000.tar.gz', '/mnt/FC2/x.tar.gz', [['path' => '/mnt/FC2', 'pattern' => '', 'label' => '']]));
    }

    /** ② external：full_path 前缀命中 backup_paths[i].path → ext:<path> */
    public function test_external_matched_path(): void
    {
        $this->assertSame(
            'ext:/data/backup/site',
            BackupSource::derive('thirdparty_20260809.tar.gz', '/data/backup/site/thirdparty_20260809.tar.gz', $this->paths())
        );
        $this->assertSame(
            'ext:/data/backup/db',
            BackupSource::derive('dump_20260809.sql.gz', '/data/backup/db/dump_20260809.sql.gz', $this->paths())
        );
    }

    /** ③ external 但匹配不到任何配置目录 → ext:（仍属于 external 组） */
    public function test_external_unmatched_path(): void
    {
        $this->assertSame('ext:', BackupSource::derive('someone.tar.gz', '/var/backups/other/someone.tar.gz', $this->paths()));
        $this->assertSame('ext:', BackupSource::derive('no_full_path.tar.gz', null, $this->paths()));
        $this->assertSame('ext:', BackupSource::derive('empty_full_path.tar.gz', '', $this->paths()));
    }

    /** ④ 无任何 backup_paths 配置（老数据 / 纯 self 站）→ 落进兜底组 ext: */
    public function test_legacy_rows_without_paths_fall_back(): void
    {
        $this->assertSame('ext:', BackupSource::derive('legacy_20260101.tar.gz', '/data/backup/site/legacy_20260101.tar.gz', []));
        $this->assertSame('ext:', BackupSource::derive('legacy_no_path.txt', null, []));
    }

    /** backup_paths 项缺 path 键时不能炸，直接跳过 */
    public function test_malformed_backup_paths_are_skipped(): void
    {
        $this->assertSame('ext:', BackupSource::derive('x.tar.gz', '/data/backup/site/x.tar.gz', [['label' => '坏数据'], null]));
    }
}
