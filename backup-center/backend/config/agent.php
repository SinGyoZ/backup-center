<?php

return [
    // 分发给用户的 Agent 最新版本号（部署新 agent-dist 时同步 bump）
    // 0.14.4：tar --unlink-first 修复（advanced 恢复解压 .user.ini EEXIST 假失败）
    // 0.14.5：自主备份上报 batch_code（指令 ID），高级模式按编码精确分组
    // 0.15.0：增量备份（rsync --link-dest 硬链快照）+ 快照恢复 + retention 目录 purge
    // 0.15.1：增量备份前用 rclone mkdir 预建站点根目录，首次备份到全新 base 不再必失败
    // 0.15.2：保留期清理（cleanup_retention）的 rclone 调用加单条超时（10m），
    //         后端卡死不再永久挂起清理指令与 Agent 命令循环
    // 0.15.3：高级恢复「目录归档」解压去掉 --unlink-first（非空目录 unlink 失败 exit 2）；
    //         恢复失败结果不再吞错（CommandResult 上报 failed，避免 Center 侧永久 dispatched）
    // 0.15.4：修复 lsattr -R 漏点文件导致 .user.ini 的 immutable 未被解除、tar 覆盖失败；
    //         改 find+lsattr 枚举 + 解压失败兜底整树 chattr -R -i 重试
    // 0.16.0：目录备份概念改版——实体 Website→Task（备份任务）、指令 self_backup→pack_backup；
    //         目录打包/目录快照支持 backup_paths 多目录；上报新增 origin_path；
    //         自动恢复目标改为备份时记录的来源目录；移除根目录绑定与数据库导出/导入
    // 0.17.0：目录打包精细勾选——新增 browse_dir 目录浏览指令 + 3 秒指令快速拉取；
    //         pack_backup 支持单文件勾选（-file- 命名，包内保留父目录层）；
    //         advanced 恢复分流接纳 -file- 文件归档（与 -dir- 同构）
    // 0.18.0：打包产物改用备份时间命名 + 存储端去日期层——
    //         压缩包命名 <任务名>-<时间戳>.tar.gz（多路径追加 -<标签>，重名追加序号），
    //         文件名不再携带 -dir-/-file- 标记，改由 report 的 source_type 字段标识来源；
    //         上传布局去掉按日目录，直接落 <存储目标目录>/<任务名>/；
    //         恢复分流新增 path_type=self_archive（Center 依 source_key 下发，
    //         老文件名标记保留兜底）
    'latest_version' => env('BACKUP_AGENT_LATEST_VERSION', '0.18.0'),
];
