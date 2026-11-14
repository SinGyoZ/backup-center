package main

// 增量备份：rsync --link-dest 硬链快照 + incremental_backup 指令 + 快照恢复。
// v0.16 起对任务的 backup_paths 多目录逐个快照，按 label 分层存放；
// 快照恢复的 advanced 落地目标由 origin_path 指定。

import (
	"fmt"
	"io"
	"log/slog"
	"os"
	"os/exec"
	"path/filepath"
	"regexp"
	"strconv"
	"strings"
	"time"

	"github.com/SinGyoZ/backup-center/agent/internal/client"
	"github.com/SinGyoZ/backup-center/agent/internal/scanner"
	"github.com/SinGyoZ/backup-center/agent/internal/uploader"
)

// ---------- 远端 SSH 相关常量 ----------

// SSH 私钥由 uploader.writeKeyFile（rcloneconf.go）在渲染 rclone.conf 时落到
// keys/bc_<id>.key（0600）；known_hosts 固定同目录——cleanupStaleKeyFiles 只删
// bc_<数字>.key，不会误伤它。
const (
	sshKeysDir    = "/etc/backup-agent/keys"
	sshKnownHosts = "/etc/backup-agent/keys/known_hosts"
)

// snapshotStampRe 快照目录名形态：time.Now().Format("20060102-150405")。
// 只认严格形态，防止把快照目录下误建的杂目录误当成 link-dest 目标。
var snapshotStampRe = regexp.MustCompile(`^\d{8}-\d{6}$`)

// performIncrementalBackup 增量备份：rsync --link-dest 硬链快照。
// 路线选择（与用户拍板，见规格 §0）：
//   - 目标只能是 SFTP 存储（rsync 经 SSH 推 VPS）；对象存储无增量语义，不做；
//   - 快照目录 = 目录镜像：任务的每条备份目录独立快照，按 label 分层存放
//     （<base_path>/<任务名>/<label>/<stamp>/，label 空用 d1/d2…）；
//   - --link-dest 指向该目录的上一个快照：未变化文件以硬链落地、只传输变化部分；
//     删除旧快照不破坏新快照（retention 走 rclone purge，见 performRetentionCleanup）。
// 部分成功语义：任一目录成功即命令成功（output 汇总成功/跳过明细）；
// 全部目录失败才返回 error。
func performIncrementalBackup(cmd *client.PendingCommand, c *client.Client, up *uploader.Uploader, scn *scanner.Scanner) (string, error) {
	p := cmd.Params
	if p == nil {
		return "", fmt.Errorf("增量备份指令缺少参数")
	}
	taskID, err := parseInt64Param(p["task_id"])
	if err != nil {
		return "", fmt.Errorf("task_id 解析失败: %w", err)
	}
	t, err := resolveTaskForCommand(c, scn, up, taskID, "增量备份")
	if err != nil {
		return "", err
	}
	if t.BackupMode != "incremental" {
		return "", fmt.Errorf("该任务未启用增量备份（backup_mode=incremental）")
	}
	if len(t.BackupPaths) == 0 {
		return "", fmt.Errorf("任务未配置备份目录，无法增量备份")
	}
	if t.IncrementalStorageID <= 0 {
		return "", fmt.Errorf("未配置增量备份目标存储")
	}
	storage, ok := up.StorageByID(t.IncrementalStorageID)
	if !ok {
		return "", fmt.Errorf("增量目标存储不在配置中（请检查 Center 存储配置）")
	}

	// 工具前置检查：rsync 与 ssh 客户端缺失时给出对应包管理器的安装提示
	if _, err := exec.LookPath("rsync"); err != nil {
		return "", fmt.Errorf("服务器缺少 rsync（Debian/Ubuntu: apt-get install rsync；CentOS/RHEL: yum install rsync），无法增量备份: %w", err)
	}
	if _, err := exec.LookPath("ssh"); err != nil {
		return "", fmt.Errorf("服务器缺少 ssh 客户端（Debian/Ubuntu: apt-get install openssh-client；CentOS/RHEL: yum install openssh-clients），无法增量备份: %w", err)
	}

	// ---------- SSH 参数（storage.Type 必须 == "sftp"） ----------
	if storage.Type != "sftp" {
		return "", fmt.Errorf("增量备份目标必须是 SFTP 类型存储（当前 %s）：rsync 经 SSH 推送到 VPS，对象存储无增量语义", storage.Type)
	}
	host := configStr(storage.Config, "host")
	sshUser := configStr(storage.Config, "user")
	port := configStr(storage.Config, "port")
	if host == "" || sshUser == "" {
		return "", fmt.Errorf("增量目标存储的 SSH 配置不完整（host/user 不能为空）")
	}
	if configStr(storage.Config, "private_key") == "" {
		return "", fmt.Errorf("增量目标存储未配置 SSH 私钥（rsync 不支持交互式密码输入，请改用私钥认证）")
	}
	// 加密存储显式拒绝（与后端 validateIncrementalConfig 双保险）：rsync 明文经 SSH
	// 写入远端原始文件，会绕开 rclone crypt 的 filename/dir 加密约定，且这里直拼
	// 远端路径也无法经 crypt 层中转。所以这一步的显式校验是必须的。
	if storageEncryptionEnabled(storage.Config) {
		return "", fmt.Errorf("增量目标存储开启了加密，拒绝执行（rsync 明文写入与加密层不兼容，请在 Center 关闭该存储加密或改用普通全量备份）")
	}
	keyPath := filepath.Join(sshKeysDir, fmt.Sprintf("bc_%d.key", t.IncrementalStorageID))
	if _, err := os.Stat(keyPath); err != nil {
		return "", fmt.Errorf("SSH 私钥文件缺失 %s（存储刚配置时等待一次任务列表刷新，约 5 分钟内自动生成）: %w", keyPath, err)
	}

	// ---------- 远端目录布局 ----------
	stamp := time.Now().Format("20060102-150405")
	safe := safeName(t.Name)
	// 快照根与 rclone 全量上传的任务目录同名（uploader.remoteTargetPath 用同一个
	// sanitizePathSegment 生成 <任务名> 段）：同一任务在同一存储上的全量与增量产物
	// 共处一个任务根目录，retention 清理 / 人工排查时口径统一。
	// 每条备份目录再按 label 分层：<base_path>/<任务名>/<label>/<stamp>/。
	remoteTaskDir := trimTrailingSlash(storage.BasePath) + "/" + sanitizePathSegment(t.Name) // base_path 为空时就是 /<任务名>
	remote := fmt.Sprintf("bc_%d:", t.IncrementalStorageID)

	backupAt := time.Now()
	batchCode := strconv.FormatInt(cmd.ID, 10) // 与 pack_backup 同规则：指令 ID 即任务编码

	var okDirs, badDirs []string
	for i, bp := range t.BackupPaths {
		// 目录必须存在且为目录：缺失/为文件时跳过并记录明细
		if info, serr := os.Stat(bp.Path); serr != nil || !info.IsDir() {
			badDirs = append(badDirs, bp.Path+"（目录不存在或不是目录）")
			continue
		}
		label := dirLabel(bp.Label, i)
		remoteLabelDir := remoteTaskDir + "/" + sanitizePathSegment(label)
		remoteSnapDir := remoteLabelDir + "/" + stamp

		// 预建远端 label 目录（首次备份兜底）：rsync 不会创建 destination 的父级目录
		// （未用 --mkpath），远端 label 目录缺失时 mkdir ENOENT、首次备份必败。
		// rclone mkdir 幂等（目录已存在时无操作且成功），sftp 后端会连带创建缺失的
		// 父级。失败仅告警不阻断：让 rsync 保持成败的唯一裁决者，告警保留 rclone 侧
		// 细节供排查。
		if out, merr := exec.Command("rclone", "mkdir", "--config", rcloneConfPath, remote+remoteLabelDir).CombinedOutput(); merr != nil {
			slog.Warn("预建远端快照目录失败（继续尝试 rsync）", "remote", remote+remoteLabelDir, "err", merr, "out", strings.TrimSpace(string(out)))
		}

		// 上一个快照：该 label 目录下字典序最大的 YYYYMMDD-HHMMSS。
		// lsf 失败（首跑目录不存在 / 网络抖动）退化为全量（prev=""），安全优先——
		// link-dest 指向错误位置会让 rsync 静默重传甚至数据错乱，宁可多传。
		prev := findLatestSnapshot(t.IncrementalStorageID, remoteLabelDir)

		// ---------- rsync 快照同步（exec.Command 直传参数，无 shell） ----------
		// 旗标语义：
		//   -a               归档模式（递归+权限+时间等）；与 --no-o/--no-g 配合见下
		//   --delete         保持快照是源目录的忠实镜像（删掉远端已不存在的文件）
		//   --info=progress2 单行总进度（stdout，\r 刷新），喂给进度上报
		//   --link-dest      未变化文件从上一个快照硬链落地，不重复传输
		args := []string{
			"-a",
			"--delete",
			"--info=progress2",
			"-e", buildSSHCommand(keyPath, port),
		}
		// 非 root 登录远端时，-a 自带的 -o/-g（保留属主/属组）会在远端 chown 失败 →
		// exit 23（部分传输），整次备份被误判失败。加 --no-o --no-g 跳过属主保留。
		if sshUser != "root" {
			args = append(args, "--no-o", "--no-g")
		}
		if prev != "" {
			// link-dest 相对 destination 解析：../<prev>/ 即同一 label 目录下的上一个
			// 快照，天然指向兄弟目录，无需知道远端绝对根路径。
			args = append(args, "--link-dest=../"+prev+"/")
		}
		// source 带尾斜杠 = 同步目录内容本身；destination 带尾斜杠 = 落入快照目录
		args = append(args,
			bp.Path+"/",
			sshUser+"@"+host+":"+remoteSnapDir+"/",
		)
		rcmd := exec.Command("rsync", args...)

		slog.Info("增量备份：rsync 快照同步开始", "id", cmd.ID, "src", bp.Path, "dst", remoteSnapDir, "link_dest", prev)
		_ = c.CommandProgress(&client.CommandProgressRequest{
			CommandID: cmd.ID,
			Output:    fmt.Sprintf("[快照] rsync 同步 %s → %s（%s）…", bp.Path, remoteSnapDir, linkDestDesc(prev)),
		})
		// progress2 在 stdout 上以 \r 刷新，错误/告警在 stderr：两路都喂进度抓取器，
		// 另汇入同一带锁缓冲（stdout/stderr 由 exec 各起 goroutine 并发拷贝，直接共用
		// strings.Builder 会数据竞争）。
		buf := &syncBuf{}
		tracker := &progressTracker{}
		w := io.MultiWriter(buf, tracker)
		rcmd.Stdout = w
		rcmd.Stderr = w
		stop := startProgressLoop(cmd.ID, c, tracker)
		runErr := rcmd.Run()
		stop()
		if runErr != nil {
			msg := strings.TrimSpace(buf.String())
			if msg == "" {
				msg = runErr.Error()
			}
			// 取尾部 1800：progress2 全程刷屏，真正的失败原因永远在最后
			if len(msg) > 1800 {
				msg = "…" + msg[len(msg)-1800:]
			}
			badDirs = append(badDirs, bp.Path+"（rsync 失败: "+msg+"）")
			continue
		}
		// 收尾 progress2 行形如 "  1,234,567 100%  6.05MB/s    0:00:02"，首字段即本次传输字节
		transferred := parseRsyncTransferred(tracker.last())

		// 快照体积 best-effort：rclone size 失败只记 0（仅用于记录展示，不影响成败）
		snapSize := remoteDirSize(remote + remoteSnapDir)

		// ---------- 记录上报 ----------
		// 快照日志：filename=<safeTask>-snap-<safeLabel>-<stamp>；
		// full_path 是**不带 bc_ 前缀的远端目录**（恢复页/retention 按它定位快照根）。
		// 注意这里不是本地路径——快照内容从不在 Agent 落盘。
		// OriginPath=本地源目录：advanced 快照恢复按它定位落地目标（父目录）。
		snapName := fmt.Sprintf("%s-snap-%s-%s", safe, safeName(label), stamp)
		logID, rerr := reportIncrementalLog(c, taskID, snapName, remoteSnapDir, bp.Path, snapSize, backupAt, batchCode)
		if rerr != nil {
			badDirs = append(badDirs, bp.Path+"（"+rerr.Error()+"）")
			continue
		}
		// rsync 经 SSH 推送，没有 rclone copyto 自然产出 upload 记录——补一条 uploaded：
		// remote_path 带 bc_<sid>: 前缀，恢复页凭它判定可恢复、取远端目录并按 dir 恢复。
		if _, uerr := c.UploadReport(&client.UploadReportRequest{
			BackupLogID:   logID,
			StorageID:     t.IncrementalStorageID,
			Status:        "uploaded",
			RemotePath:    remote + remoteSnapDir,
			UploadedBytes: snapSize,
		}); uerr != nil {
			// 非致命：备份本体已成功，最坏是恢复页暂不可见，后续备份会带新记录
			slog.Warn("增量备份：快照 upload 记录上报失败", "err", uerr, "log_id", logID)
		}
		okDirs = append(okDirs, snapName+"（传输 "+humanizeBytes(transferred)+"，快照体积 "+humanizeBytes(snapSize)+"）")
	}

	// 部分成功语义：任一目录成功即命令成功；全部失败才返回 error
	if len(okDirs) == 0 {
		return "", fmt.Errorf("增量备份失败：所有目录均未成功（%s）", strings.Join(badDirs, "；"))
	}
	summary := fmt.Sprintf("增量备份完成：成功 %d 个目录（%s）", len(okDirs), strings.Join(okDirs, " · "))
	if len(badDirs) > 0 {
		summary += fmt.Sprintf(" · 跳过/失败 %d 个（%s）", len(badDirs), strings.Join(badDirs, "；"))
	}
	summary += "，已推送至 " + storage.Name
	return summary, nil
}

// reportIncrementalLog 上报一条带 batch_code 的备份记录，返回 log_id。
// 请求字段与 reportAndUpload 一致（含 origin_path），但不上传：增量产物不进
// <任务名>/<batchDir>/ 标准布局，改由 performIncrementalBackup 按"快照目录"
// 语义自行驱动 rsync 推送 + 手工 upload-report，所以拆开只用 c.Report。
func reportIncrementalLog(c *client.Client, taskID int64, filename, fullPath, originPath string, size int64, backupAt time.Time, batchCode string) (int64, error) {
	resp, err := c.Report(&client.ReportRequest{
		TaskID:     taskID,
		Filename:   filename,
		FullPath:   fullPath,
		OriginPath: originPath,
		Size:       size,
		BackupAt:   backupAt.Format(time.RFC3339),
		Status:     "success",
		// 任务编码 = incremental_backup 指令 ID：同一次指令的全部快照带同一编码，
		// Center 按编码精确分组（一次指令 = 一场备份，零时间窗口猜测）。
		BatchCode: batchCode,
	})
	if err != nil {
		return 0, fmt.Errorf("上报备份记录失败: %w", err)
	}
	return resp.LogID, nil
}

// findLatestSnapshot 列出远端目录，返回字典序最大的快照名（YYYYMMDD-HHMMSS）。
// 传 remoteLabelDir（<base_path>/<任务名>/<label>）：多目录改造后基线按各备份
// 目录自己的快照序列查找，互不串目录。
// rclone lsf 失败（目录不存在/网络问题）返回空串：调用方据此退化为全量备份。
func findLatestSnapshot(storageID int64, remoteDir string) string {
	remote := fmt.Sprintf("bc_%d:%s/", storageID, remoteDir)
	out, err := exec.Command("rclone", "lsf",
		"--config", rcloneConfPath,
		"--dirs-only",
		remote,
	).Output()
	if err != nil {
		slog.Warn("列不出上一个快照，本次按全量传输", "remote", remote, "err", err)
		return ""
	}
	best := ""
	for _, line := range strings.Split(string(out), "\n") {
		name := strings.TrimSuffix(strings.TrimSpace(line), "/")
		if snapshotStampRe.MatchString(name) && name > best {
			best = name
		}
	}
	return best
}

// buildSSHCommand 组装 rsync -e 的 ssh 命令串。rsync 自行按空格切分 -e 串并
// 以 argv 形式起进程（不经本地 shell），所以除 key 路径/端口外不含用户数据。
// StrictHostKeyChecking=accept-new：首次连接自动接受并记入 known_hosts，之后
// 仍校验指纹防中间人；UserKnownHostsFile 固定 keys/ 目录；BatchMode=yes
// 杜绝任何交互式密码提示卡死 rsync（密钥认证失败应直接报错）。
func buildSSHCommand(keyPath, port string) string {
	parts := []string{"ssh", "-i", keyPath}
	if port != "" && port != "22" {
		parts = append(parts, "-p", port)
	}
	parts = append(parts,
		"-o", "StrictHostKeyChecking=accept-new",
		"-o", "UserKnownHostsFile="+sshKnownHosts,
		"-o", "BatchMode=yes",
	)
	return strings.Join(parts, " ")
}

// parseRsyncTransferred 从 rsync --info=progress2 的最后一行解析已传输字节数：
// 形如 "  1,234,567 100%  6.05MB/s    0:00:02 (xfr#12, to-chk=0/1500)"，
// 第一个字段（去千分位逗号）即累计传输字节。解析不到返回 0（仅影响成功文案）。
func parseRsyncTransferred(line string) int64 {
	f := strings.Fields(line)
	if len(f) == 0 {
		return 0
	}
	n, err := strconv.ParseInt(strings.ReplaceAll(f[0], ",", ""), 10, 64)
	if err != nil || n < 0 {
		return 0
	}
	return n
}

// remoteDirSize 取远端目录大小（字节）best-effort：rclone size 输出
// "Total size: 45.2 Mi (47382738 Bytes)"，取括号内字节数。失败返回 0——
// 仅用于记录展示，不影响备份成败。
func remoteDirSize(remote string) int64 {
	out, err := exec.Command("rclone", "size", "--config", rcloneConfPath, remote).Output()
	if err != nil {
		slog.Warn("取快照体积失败，记 0", "remote", remote, "err", err)
		return 0
	}
	for _, line := range strings.Split(string(out), "\n") {
		line = strings.TrimSpace(line)
		if !strings.HasPrefix(line, "Total size:") {
			continue
		}
		openP := strings.Index(line, "(")
		closeP := strings.LastIndex(line, ")")
		if openP < 0 || closeP <= openP {
			break
		}
		f := strings.Fields(line[openP+1 : closeP]) // "47382738 Bytes"
		if len(f) > 0 {
			if n, perr := strconv.ParseInt(f[0], 10, 64); perr == nil {
				return n
			}
		}
		break
	}
	return 0
}

// linkDestDesc 仅用于进度文案：说明本次是增量还是全量基线
func linkDestDesc(prev string) string {
	if prev == "" {
		return "未找到上一个快照，全量基线"
	}
	return "link-dest " + prev
}

// configStr 从 storage config 取字符串（容错 JSON 数字/布尔等非字符串形态）
func configStr(cfg map[string]interface{}, key string) string {
	v, ok := cfg[key]
	if !ok {
		return ""
	}
	if s, ok := v.(string); ok {
		return s
	}
	return fmt.Sprintf("%v", v)
}

// storageEncryptionEnabled 判断存储是否开启加密（复制自 uploader.encryptionEnabled，
// 那边是包私有函数、不为增量放开导出面）。逻辑必须与之一致，否则会出现 uploader
// 认为已加密、增量侧却放行的双标窗口。
func storageEncryptionEnabled(cfg map[string]interface{}) bool {
	enc, ok := cfg["encryption"].(map[string]interface{})
	if !ok {
		return false
	}
	switch t := enc["enabled"].(type) {
	case bool:
		return t
	case string:
		return t == "true" || t == "1"
	}
	return false
}

// sanitizePathSegment 清理路径段（复制自 uploader.sanitizePathSegment——
// uploader.remoteTargetPath 用同一函数生成 <任务名> 目录名，这里复制而非改动
// uploader 导出面）。快照根必须与全量上传的任务目录同名，口径才统一；
// 多目录改造后同一函数也用于 <label> 段，两处必须与 uploader 保持逐字一致。
func sanitizePathSegment(s string) string {
	repl := strings.NewReplacer(
		"/", "_", "\\", "_", ":", "_", "*", "_", "?", "_",
		"\"", "_", "<", "_", ">", "_", "|", "_",
	)
	s = repl.Replace(s)
	s = strings.TrimSpace(s)
	if s == "" {
		return "default"
	}
	return s
}

// trimTrailingSlash 去掉路径末尾斜杠（base_path 配置形态不统一：带或不带尾斜杠都要兼容）
func trimTrailingSlash(p string) string {
	return strings.TrimRight(p, "/")
}

// performSnapshotRestore 恢复一个增量快照：remote_path 指向远端**目录**
// （rsync 快照根），先把整个目录 rclone copy 回本地 dest，再按模式分流：
//   - lite（restore_mode != advanced）：只下载留档，返回落地路径；
//   - advanced：把快照目录改名成 Base(origin_path) 后打包成与 pack 备份同构的
//     <base>.tar.gz，**逐字复用** performAdvancedDirRestore（预备份 + 覆盖解压
//     到 filepath.Dir(origin_path)）——恢复语义与目录归档恢复完全一致，
//     不另造一套链路。origin_path 缺失时报错，提示改用 lite 留档恢复。
//
// dest 由 performRestore 拼好（local_dir/<远端目录名>），本函数只负责消费它。
func performSnapshotRestore(cmd *client.PendingCommand, c *client.Client, dest, originPath string) (string, error) {
	p := cmd.Params
	if p == nil {
		return "", fmt.Errorf("恢复指令缺少参数")
	}
	remotePath, _ := p["remote_path"].(string)
	if remotePath == "" {
		return "", fmt.Errorf("恢复参数不完整（remote_path 不能为空）")
	}
	if err := os.MkdirAll(dest, 0755); err != nil {
		return "", fmt.Errorf("创建本地目录失败 %s: %w", dest, err)
	}

	slog.Info("开始快照恢复", "id", cmd.ID, "from", remotePath, "to", dest)
	// 目录下载用 rclone copy（单文件下载用 copyto）：copy 的 source 是目录时
	// 直接把内容拉进 dest/，不需要远端路径带尾斜杠声明"这是目录"。
	rcmd := exec.Command("rclone",
		"copy",
		"--config", rcloneConfPath,
		"--transfers", "1",
		"--retries", "3",
		"--low-level-retries", "5",
		"--stats", "5s", // 每 5s 打一次进度
		"--stats-one-line",      // 单行进度（便于抓取）
		"--log-level", "NOTICE", // 压掉逐文件 INFO 日志，保留进度行与错误
		remotePath, dest+"/",
	)
	if err := runRcloneWithProgress(rcmd, cmd.ID, c); err != nil {
		msg := err.Error()
		if len(msg) > 1800 {
			msg = msg[:1800] + "…"
		}
		return "", fmt.Errorf("rclone 快照恢复失败: %s", msg)
	}

	// lite 模式：仅下载留档，由管理员手动处理（advanced 的落地目标由 origin_path 指定）
	if fmt.Sprint(p["restore_mode"]) != "advanced" {
		return fmt.Sprintf("快照已下载到 %s，请手动处理", dest), nil
	}
	if originPath == "" {
		return "", fmt.Errorf("advanced 快照恢复缺少 origin_path（备份来源目录），无法定位恢复目标；请改用 lite 模式（仅下载留档）")
	}

	// ---------- advanced：中转打包后复用既有高级链路 ----------
	// 快照目录内容就是 origin_path 的内容（rsync originPath/ → 快照根/），所以把它
	// 改名成 Base(origin_path) 再打包，归档顶层条目恰为 <base>/，与 pack 备份的
	// tar -czf arc -C parent base 完全同构——performAdvancedDirRestore 解压
	// -C parent 即可覆盖回原位（见该函数的 tar 约定注释）。
	base := filepath.Base(originPath)
	stageDir := filepath.Join(filepath.Dir(dest), "stage-"+filepath.Base(dest))
	if err := os.MkdirAll(stageDir, 0755); err != nil {
		return "", fmt.Errorf("创建中转目录失败 %s: %w", stageDir, err)
	}
	if err := os.Rename(dest, filepath.Join(stageDir, base)); err != nil {
		return "", fmt.Errorf("移动快照目录到中转目录失败: %w", err)
	}
	dirTar := filepath.Join(filepath.Dir(stageDir), base+".tar.gz")
	tcmd := exec.Command("tar", "-czf", dirTar, "-C", stageDir, base)
	var terr strings.Builder
	tcmd.Stderr = &terr
	if err := tcmd.Run(); err != nil {
		return "", fmt.Errorf("打包快照目录失败: %v (%s)", err, strings.TrimSpace(terr.String()))
	}
	// 整个中转根（下载目录 + stage + tar）在函数退出时清理：dirTar 随后即被
	// 解压消费。只清 os.TempDir() 下的子目录兜底防误删（中转根绝不能等于
	// os.TempDir() 本身，否则会把整个 /tmp 清掉）——恢复指令的 local_dir 由
	// 后端统一下发到 /tmp 下的子目录，不会落到别处。
	if localRoot := filepath.Dir(stageDir); strings.HasPrefix(localRoot, os.TempDir()) && localRoot != os.TempDir() {
		defer os.RemoveAll(localRoot)
	}
	bak, err := performAdvancedDirRestore(cmd, c, originPath, dirTar)
	if err != nil {
		return "", err
	}
	return fmt.Sprintf("快照恢复完成：目录已恢复（预备份 %s）", bak), nil
}
