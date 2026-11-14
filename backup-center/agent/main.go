package main

import (
	"context"
	"encoding/json"
	"flag"
	"fmt"
	"io"
	"log/slog"
	"net/http"
	"os"
	"os/exec"
	"os/signal"
	"path"
	"path/filepath"
	"runtime"
	"strconv"
	"strings"
	"sync"
	"syscall"
	"time"

	"github.com/SinGyoZ/backup-center/agent/internal/client"
	"github.com/SinGyoZ/backup-center/agent/internal/config"
	"github.com/SinGyoZ/backup-center/agent/internal/scanner"
	"github.com/SinGyoZ/backup-center/agent/internal/sysinfo"
	"github.com/SinGyoZ/backup-center/agent/internal/uploader"
)

// AgentVersion 随修复 bump：面板据版本号变化判断是否需要自更新。
// 0.14.4：高级恢复解压加 --unlink-first，
//
//	修复面板 .user.ini 触发 GNU tar EEXIST 导致恢复「假失败」（exit 2）。
//
// 0.14.5：打包备份上报 task batch_code（pack_backup 指令 ID），
//
//	Center 据此对高级模式备份按编码精确分组，不再依赖时间窗口。
//
// 0.15.0：增量备份（backup_mode=incremental）：rsync --link-dest 硬链快照 +
//
//	incremental_backup 指令 + 快照恢复（path_type=dir）+ retention 目录
//	purge（条项 is_dir）。
//
// 0.15.2：保留期清理（cleanup_retention）的 rclone 调用加单条超时（cleanupRcloneTimeout=10m），
//
//	后端卡死不再永久挂起清理指令与 Agent 命令循环；超时该条按失败保留日志行下轮重派。
//
// 0.15.3：修复高级恢复「目录覆盖」解压必然失败，
//
//	去掉 tar --unlink-first（-U 会对每个目录也尝试 unlink，非空目录必然
//	"Cannot unlink: Directory not empty" → tar exit 2），改为普通覆盖解压；
//	并在解压前 chattr -i 解除 .user.ini 等不可变文件属性（宝塔等面板会加
//	chattr +i，tar 覆盖写会 EPERM "Operation not permitted"），解压后按原样
//	chattr +i 恢复。恢复结果上报不再静默吞错，失败原因截断到 1800 字符。
//
// 0.16.0：目录备份改造——实体 Website→Task（备份任务）、指令 self_backup→pack_backup；
//
//	pack_backup 多目录打包、快照多目录逐个执行（对任务 backup_paths 逐目录）；
//	上报新增 origin_path（备份来源目录），advanced 恢复解压目标改为 origin_path
//	的父目录；删除数据库导出/导入（mysqldump/pg_dump 全链）与根目录绑定功能。
//
// 0.17.0：目录打包精细勾选——
//
//	① 新增 browse_dir 指令：列目录项元数据（name/is_dir/size/mtime），供 Center
//	  网页「浏览备份目录」弹窗使用（不读文件内容）；
//	② 新增指令快速拉取循环：每 3 秒轻量 GET /api/agent/commands/pending（不带
//	  系统指标），交互指令（浏览/打包/恢复）秒级到达；60s 心跳仍保留（指标上报
//	  + mirror 同步，双通道消费同一队列，Center 原子标记 dispatched 无重复）；
//	③ pack_backup 支持文件：backup_paths 项按 os.Stat 分流——目录走原逻辑；
//	  文件按「保留父目录层」打包（-C 祖父目录 <父目录名>/<文件名>，origin_path
//	  记父目录），归档名 -file- 标记，恢复与目录归档同构（预备份+覆盖解压落回原位）；
//	④ advanced 恢复分流：文件名含 -dir- 或 -file- 均走 performAdvancedDirRestore。
//
// 0.18.0：压缩包改用备份时间命名 + 存储端去日期层——
//
//	⑤ 打包产物命名 <safeTask>-<stamp>.tar.gz（如 FC2-20260809-040000.tar.gz），
//	  多路径任务追加 -<label> 段、同批次重名追加序号；文件名不再携带 -dir-/-file-
//	  标记，改由 report 新增的 source_type 字段（dir/file）告知 Center 来源类型；
//	⑥ 上传布局去掉按日目录：打包产物直接落 <base_path>/<任务名>/（归档收集
//	  的 <任务名>/<日期>/ 布局与快照布局保持不变）；
//	⑦ advanced 恢复分流新增 path_type=self_archive（Center 依 source_key 下发），
//	  老文件名标记 -dir-/-file- 保留兜底（旧归档仍可恢复）。
const AgentVersion = "0.18.0"

// runtimeConfigPath 记录当前进程的配置文件路径，供心跳里更新 mirror 时回写
var runtimeConfigPath string

const rcloneConfPath = "/etc/backup-agent/rclone.conf"

// restoreMu 保证同一时刻 Agent 上只有一条 restore 命令真正在执行。
// 背景：restore 内部是 rclone copyto 大文件下载，advanced 模式下载成功后还有
// 预备份 + tar 解压覆盖备份来源目录，全程重占带宽与磁盘 IO。
// 两条 restore 并发会同时跑多个 rclone，互相抢占带宽和磁盘，可能打满整机 IO、
// 拖慢线上服务——这是前端防重（同时只允许提交一条恢复）之外的后端兜底。
//
// 排队策略：Center 每次心跳只派发一条命令，新 restore 发现锁被占用时在
// performRestore 入口排队等待前一条彻底结束（含 advanced 的 tar 解压）再执行，
// 而不是丢弃让 Center 重派——Center 的 stale sweep 显式排除 restore 不会标
// failed，直接丢弃会让命令永远卡在 dispatched 状态无人执行。
var restoreMu sync.Mutex

func main() {
	var (
		configPath = flag.String("config", "/etc/backup-agent/config.json", "配置文件路径")
		verbose    = flag.Bool("v", false, "详细日志（debug 级别）")
		force      = flag.Bool("f", false, "配合 update 使用：强制升级（即使版本相同）")
	)
	flag.Parse()

	logLevel := slog.LevelInfo
	if *verbose {
		logLevel = slog.LevelDebug
	}
	slog.SetDefault(slog.New(slog.NewTextHandler(os.Stderr, &slog.HandlerOptions{Level: logLevel})))

	args := flag.Args()
	if len(args) == 0 {
		args = []string{"run"}
	}

	switch args[0] {
	case "run":
		cmdRun(*configPath)
	case "test":
		cmdTest(*configPath)
	case "update":
		cmdUpdate(*configPath, *force)
	case "version":
		fmt.Printf("backup-agent v%s\n", AgentVersion)
	default:
		fmt.Fprintf(os.Stderr, "用法: backup-agent [-config PATH] [-f] [run|test|update|version]\n")
		os.Exit(2)
	}
}

func cmdRun(configPath string) {
	cfg, err := config.Load(configPath)
	if err != nil {
		slog.Error("加载配置失败", "err", err)
		os.Exit(1)
	}
	runtimeConfigPath = configPath

	c := client.New(cfg.CenterURL, cfg.AgentToken, AgentVersion)

	// ---------- 注册（失败重试） ----------
	var regResp *client.RegisterResponse
	for attempt := 0; ; attempt++ {
		info := sysinfo.Basic()
		slog.Info("向 Center 注册", "url", cfg.CenterURL, "hostname", info.Hostname, "attempt", attempt+1)
		resp, err := c.Register(info)
		if err == nil {
			slog.Info("注册成功", "server_id", resp.ServerID, "server_name", resp.ServerName)
			regResp = resp
			break
		}
		slog.Warn("注册失败，将重试", "err", err)
		if client.IsAuthError(err) {
			slog.Error("Token 无效，退出")
			os.Exit(3)
		}
		time.Sleep(backoff(attempt))
	}

	heartbeatInterval := durSecs(regResp.Config.HeartbeatInterval, 60)
	taskRefreshInterval := durSecs(regResp.Config.TaskRefreshInterval, 300)
	scanInterval := durSecs(regResp.Config.ScanInterval, 120)

	up := uploader.New(c, rcloneConfPath)
	scn := scanner.New(c, up)

	// 首次拉任务+存储列表
	if data, err := c.TasksFull(); err != nil {
		slog.Warn("首次拉任务列表失败", "err", err)
	} else {
		if err := up.UpdateStorages(data.Storages); err != nil {
			slog.Warn("首次生成 rclone 配置失败", "err", err)
		}
		scn.SetTasks(data.Tasks)
		slog.Info("加载配置", "tasks", len(data.Tasks), "storages", len(data.Storages))
	}

	// ---------- 信号 / context ----------
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	sig := make(chan os.Signal, 1)
	signal.Notify(sig, syscall.SIGINT, syscall.SIGTERM)
	go func() {
		s := <-sig
		slog.Info("收到停止信号", "signal", s.String())
		cancel()
	}()

	// ---------- 三个 goroutine ----------
	var wg sync.WaitGroup

	// 心跳
	wg.Add(1)
	go func() {
		defer wg.Done()
		doHeartbeat(c, cfg, scn, up)
		ticker := time.NewTicker(heartbeatInterval)
		defer ticker.Stop()
		for {
			select {
			case <-ctx.Done():
				return
			case <-ticker.C:
				doHeartbeat(c, cfg, scn, up)
			}
		}
	}()

	// 指令快速拉取（v0.17.0）：3 秒一次轻量 GET，让浏览/打包/恢复等交互指令
	// 秒级到达（60s 心跳只负责指标上报与 mirror 同步）。与心跳双通道消费同一
	// 队列：Center 端 UPDATE ... WHERE status='pending' 原子标记 dispatched，
	// 两个通道不可能重复拿到同一条指令。
	wg.Add(1)
	go func() {
		defer wg.Done()
		ticker := time.NewTicker(3 * time.Second)
		defer ticker.Stop()
		for {
			select {
			case <-ctx.Done():
				return
			case <-ticker.C:
				pullPendingCommand(c, cfg, scn, up)
			}
		}
	}()

	// 定期刷新任务列表
	wg.Add(1)
	go func() {
		defer wg.Done()
		ticker := time.NewTicker(taskRefreshInterval)
		defer ticker.Stop()
		for {
			select {
			case <-ctx.Done():
				return
			case <-ticker.C:
				// 心跳节奏不变：仍是每 taskRefreshInterval 秒刷一次；失败只记日志
				_ = refreshTasks(c, scn, up)
			}
		}
	}()

	// 定期扫描目录 + 上报
	wg.Add(1)
	go func() {
		defer wg.Done()
		// 启动 5 秒后先扫一次
		time.Sleep(5 * time.Second)
		scn.ScanAll()
		ticker := time.NewTicker(scanInterval)
		defer ticker.Stop()
		for {
			select {
			case <-ctx.Done():
				return
			case <-ticker.C:
				scn.ScanAll()
			}
		}
	}()

	slog.Info("Agent 已启动",
		"heartbeat", heartbeatInterval,
		"task_refresh", taskRefreshInterval,
		"scan", scanInterval,
	)

	<-ctx.Done()
	wg.Wait()
	slog.Info("Agent 已退出")
}

func doHeartbeat(c *client.Client, cfg *config.Config, scn *scanner.Scanner, up *uploader.Uploader) {
	metrics := sysinfo.Collect()
	resp, err := c.Heartbeat(metrics)
	if err != nil {
		if client.IsAuthError(err) {
			slog.Error("心跳失败: Token 无效", "err", err)
			return
		}
		slog.Warn("心跳失败", "err", err)
		return
	}
	slog.Debug("心跳成功", "cpu", metrics.CPU, "mem", metrics.Memory, "disk", metrics.Disk)

	// Center 下发的 mirror URL 跟本地 config 不一致 → 更新 config（in-memory + 回写文件）
	if resp != nil && resp.AgentMirrorURL != cfg.MirrorURL {
		oldMirror := cfg.MirrorURL
		cfg.MirrorURL = resp.AgentMirrorURL
		if runtimeConfigPath != "" {
			if err := config.Save(runtimeConfigPath, cfg); err != nil {
				slog.Warn("回写 mirror 到 config 失败", "err", err)
			} else {
				slog.Info("mirror URL 已更新", "old", oldMirror, "new", cfg.MirrorURL)
			}
		}
	}

	if resp != nil && resp.PendingCommand != nil {
		go handleAgentCommand(resp.PendingCommand, cfg, c, scn, up)
	}
}

// pullPendingCommand 指令快速拉取通道（v0.17.0）：轻量 GET 拉一条 pending 指令，
// 有则异步处理（与心跳通道同一套 handleAgentCommand）。失败只记 debug 日志——
// 该通道 3 秒一次，Center 短暂抖动不应刷屏。
func pullPendingCommand(c *client.Client, cfg *config.Config, scn *scanner.Scanner, up *uploader.Uploader) {
	cmd, err := c.PullPendingCommand()
	if err != nil {
		slog.Debug("快速拉取指令失败", "err", err)
		return
	}
	if cmd == nil {
		return
	}
	go handleAgentCommand(cmd, cfg, c, scn, up)
}

// performBrowseDir（v0.17.0）：列出服务器目录内容，供 Center 网页「浏览备份目录」
// 弹窗使用。只返回目录项元数据（name/is_dir/size/mtime），不读文件内容。
// 超大目录截断到 1000 项（truncated=true），防止指令 output 爆掉。
func performBrowseDir(cmd *client.PendingCommand) (string, error) {
	path := "/"
	if p := cmd.Params; p != nil {
		if raw, ok := p["path"].(string); ok && raw != "" {
			path = raw
		}
	}
	clean := filepath.Clean(path)
	info, err := os.Stat(clean)
	if err != nil {
		return "", fmt.Errorf("路径不存在或不可访问: %s（%v）", path, err)
	}
	if !info.IsDir() {
		return "", fmt.Errorf("不是目录: %s（手输路径请指向文件夹，或去掉末尾文件名）", path)
	}
	entries, err := os.ReadDir(clean)
	if err != nil {
		return "", fmt.Errorf("读取目录失败: %s（%v）", path, err)
	}

	const maxEntries = 1000
	type entry struct {
		Name  string `json:"name"`
		IsDir bool   `json:"is_dir"`
		Size  int64  `json:"size"`
		MTime string `json:"mtime"`
	}
	list := make([]entry, 0, len(entries))
	truncated := false
	for _, e := range entries {
		if len(list) >= maxEntries {
			truncated = true
			break
		}
		item := entry{Name: e.Name(), IsDir: e.IsDir()}
		if fi, ferr := e.Info(); ferr == nil {
			item.Size = fi.Size()
			item.MTime = fi.ModTime().Format(time.RFC3339)
		}
		list = append(list, item)
	}
	payload, err := json.Marshal(struct {
		Path      string  `json:"path"`
		Entries   []entry `json:"entries"`
		Truncated bool    `json:"truncated"`
	}{Path: clean, Entries: list, Truncated: truncated})
	if err != nil {
		return "", fmt.Errorf("目录列表序列化失败: %w", err)
	}
	return string(payload), nil
}

// refreshTasks 从 Center 拉取最新的任务 + 存储清单并写入 scanner / uploader。
// 心跳之外的「敏感命令」（打包备份、高级恢复）也会在执行前调用它，避免用陈旧配置。
// 并发安全：c.TasksFull 走共享 http.Client（本身并发安全），
// up.UpdateStorages 与 scn.SetTasks 各自持有互斥锁。
func refreshTasks(c *client.Client, scn *scanner.Scanner, up *uploader.Uploader) error {
	data, err := c.TasksFull()
	if err != nil {
		slog.Warn("刷新任务列表失败", "err", err)
		return err
	}
	if err := up.UpdateStorages(data.Storages); err != nil {
		slog.Warn("更新 rclone 配置失败", "err", err)
	}
	scn.SetTasks(data.Tasks)
	slog.Debug("刷新配置", "tasks", len(data.Tasks), "storages", len(data.Storages))
	return nil
}

// resolveTaskForCommand 执行敏感命令前强制刷新一次任务清单，再按 ID 取配置。
// 背景：任务清单原本只在心跳（每 300s）刷新，用户刚在 Center 配好 backup_paths
// 就下发「打包备份」或「高级恢复」时，Agent 内存里还是旧值（backup_paths 可能为空），
// 导致本该成功的操作报「任务未配置备份目录」或恢复到错误目录。
// 刷新失败时按当前内存配置继续（保留旧行为）；刷新成功但仍查不到，说明该任务
// 确实不在 Center 的清单里，给出与「配置缺失」可区分的提示。
func resolveTaskForCommand(c *client.Client, scn *scanner.Scanner, up *uploader.Uploader, taskID int64, action string) (client.Task, error) {
	if err := refreshTasks(c, scn, up); err != nil {
		slog.Warn(action+"前刷新任务列表失败，将按当前内存中的配置继续", "err", err, "task_id", taskID)
	}
	t, ok := scn.TaskByID(taskID)
	if !ok {
		return client.Task{}, fmt.Errorf("任务列表刷新后仍未找到该任务（ID %d），请确认该任务未被删除、且 Agent 已成功连接 Center", taskID)
	}
	return t, nil
}

// cmdUpdate 是终端手动触发的入口：走 performUpdate，然后打印结果并 exit。
func cmdUpdate(configPath string, force bool) {
	cfg, err := config.Load(configPath)
	if err != nil {
		fmt.Fprintf(os.Stderr, "读配置失败: %v\n", err)
		os.Exit(1)
	}
	if os.Geteuid() != 0 {
		fmt.Fprintln(os.Stderr, "错误: update 需要 root 权限")
		os.Exit(1)
	}
	output, err := performUpdate(cfg, force, true)
	fmt.Print(output)
	if err != nil {
		fmt.Fprintf(os.Stderr, "错误: %v\n", err)
		os.Exit(1)
	}
}

// performUpdate 下载新二进制 + 校验，再触发 detachedRestart 交给独立进程去做 stop/rename/start。
// 返回成功时，独立进程已 dispatch，父 Agent 可以正常返回；~1-2 秒后父 Agent 会被 systemctl 停掉。
// interactive=true：终端手动触发（cmdUpdate），可以同步走 stop/rename/start（因为调用方不是 daemon）
// interactive=false：daemon 内触发（handleAgentCommand），必须走 detached，否则 daemon 自己 stop 自己会连带杀死本进程
func performUpdate(cfg *config.Config, force bool, interactive bool) (string, error) {
	var log strings.Builder
	logf := func(f string, a ...any) { fmt.Fprintf(&log, f+"\n", a...) }

	var arch string
	switch runtime.GOARCH {
	case "amd64", "arm64":
		arch = runtime.GOARCH
	default:
		return log.String(), fmt.Errorf("不支持的架构: %s", runtime.GOARCH)
	}

	self, err := os.Executable()
	if err != nil {
		return log.String(), fmt.Errorf("无法定位自身二进制: %w", err)
	}
	if resolved, err := filepath.EvalSymlinks(self); err == nil {
		self = resolved
	}
	tmpPath := self + ".new"

	// 下载源优先级：cfg.MirrorURL > cfg.CenterURL/agent/
	// 如果 mirror 下载失败，自动回退到 Center 源
	centerURL := strings.TrimRight(cfg.CenterURL, "/") + "/agent/backup-agent-linux-" + arch
	var downloadErr error
	if cfg.MirrorURL != "" {
		mirrorURL := strings.TrimRight(cfg.MirrorURL, "/") + "/backup-agent-linux-" + arch
		logf("下载(mirror): %s", mirrorURL)
		if err := downloadFile(mirrorURL, tmpPath); err != nil {
			logf("mirror 下载失败: %v → 回退到 Center 源", err)
			_ = os.Remove(tmpPath)
			logf("下载(center): %s", centerURL)
			downloadErr = downloadFile(centerURL, tmpPath)
		}
	} else {
		logf("下载: %s", centerURL)
		downloadErr = downloadFile(centerURL, tmpPath)
	}
	if downloadErr != nil {
		_ = os.Remove(tmpPath)
		return log.String(), fmt.Errorf("下载失败: %w", downloadErr)
	}
	if err := os.Chmod(tmpPath, 0755); err != nil {
		_ = os.Remove(tmpPath)
		return log.String(), fmt.Errorf("chmod 失败: %w", err)
	}

	newVersion, err := probeVersion(tmpPath)
	if err != nil {
		_ = os.Remove(tmpPath)
		return log.String(), fmt.Errorf("新二进制无法运行: %w", err)
	}
	logf("当前版本: v%s，新版本: v%s", AgentVersion, newVersion)

	if newVersion == AgentVersion && !force {
		logf("已是最新版本，无需升级（用 -f 强制升级）")
		_ = os.Remove(tmpPath)
		return log.String(), nil
	}

	if interactive {
		return interactiveSwap(self, tmpPath, newVersion, &log, logf)
	}
	return detachedSwap(self, tmpPath, newVersion, &log, logf)
}

// interactiveSwap 终端命令模式：调用方不是被管理的 daemon，可以直接 stop → rename → start
func interactiveSwap(self, tmpPath, newVersion string, log *strings.Builder, logf func(string, ...any)) (string, error) {
	hasSystemctl := true
	if _, err := exec.LookPath("systemctl"); err != nil {
		hasSystemctl = false
	}
	if hasSystemctl {
		logf("停止 backup-agent 服务...")
		if out, err := exec.Command("systemctl", "stop", "backup-agent").CombinedOutput(); err != nil {
			logf("systemctl stop 失败: %v (%s) — 若非 systemd 托管可忽略", err, strings.TrimSpace(string(out)))
		}
	}
	if err := os.Rename(tmpPath, self); err != nil {
		if hasSystemctl {
			_ = exec.Command("systemctl", "start", "backup-agent").Run()
		}
		return log.String(), fmt.Errorf("替换二进制失败: %w", err)
	}
	if hasSystemctl {
		logf("启动 backup-agent 服务...")
		if out, err := exec.Command("systemctl", "start", "backup-agent").CombinedOutput(); err != nil {
			return log.String(), fmt.Errorf("systemctl start 失败: %v (%s)", err, strings.TrimSpace(string(out)))
		}
	}
	logf("✅ 升级完成: v%s → v%s", AgentVersion, newVersion)
	return log.String(), nil
}

// detachedSwap daemon 模式：不能让 daemon 自己 stop 自己（SIGTERM 会杀掉当前 update goroutine 所在的进程）。
// 用 systemd-run 创建独立 transient unit，在 backup-agent.service 的 cgroup 之外执行 stop→rename→start。
// 父进程立即返回，~1-2 秒后被独立 unit 停掉。
func detachedSwap(self, tmpPath, newVersion string, log *strings.Builder, logf func(string, ...any)) (string, error) {
	if _, err := exec.LookPath("systemd-run"); err != nil {
		return log.String(), fmt.Errorf("daemon 模式升级需要 systemd-run，未找到: %w", err)
	}

	// on-active=2s 给父进程一点时间返回响应和记录日志
	// --collect 让 transient unit 完成后自动清理，不留残余
	// unit 名带 pid 避免撞名
	unitName := fmt.Sprintf("bc-agent-upgrade-%d", os.Getpid())
	swapCmd := fmt.Sprintf(
		"sleep 2 && systemctl stop backup-agent && mv %s %s && systemctl start backup-agent",
		shellQuote(tmpPath), shellQuote(self),
	)
	logf("触发独立 transient unit 完成 stop→rename→start: %s", unitName)
	cmd := exec.Command("systemd-run",
		"--unit="+unitName,
		"--on-active=2",
		"--timer-property=AccuracySec=100ms",
		"--collect",
		"--description=Backup Agent Upgrade",
		"/bin/sh", "-c", swapCmd,
	)
	if out, err := cmd.CombinedOutput(); err != nil {
		_ = os.Remove(tmpPath)
		return log.String(), fmt.Errorf("systemd-run 失败: %v (%s)", err, strings.TrimSpace(string(out)))
	}
	logf("✅ 升级已排队: v%s → v%s（Agent 将在 ~2 秒后被独立进程停止并替换）", AgentVersion, newVersion)
	return log.String(), nil
}

func shellQuote(s string) string {
	// 简易 shell 单引号转义，覆盖典型路径。performUpdate 里的路径来自 os.Executable + ".new"，不含奇怪字符。
	return "'" + strings.ReplaceAll(s, "'", `'\''`) + "'"
}

// handleAgentCommand 处理心跳里收到的一条指令。成功不 report（Center 靠 version 变化自判 for update）；
// 一般命令走 CommandResult 汇报。
func handleAgentCommand(cmd *client.PendingCommand, cfg *config.Config, c *client.Client, scn *scanner.Scanner, up *uploader.Uploader) {
	if cmd == nil {
		return
	}
	slog.Info("收到指令", "id", cmd.ID, "type", cmd.Type)
	switch cmd.Type {
	case "update":
		output, err := performUpdate(cfg, true, false)
		if err != nil {
			slog.Warn("升级失败", "err", err, "id", cmd.ID)
			_ = c.CommandResult(&client.CommandResultRequest{
				CommandID: cmd.ID,
				Status:    "failed",
				Output:    output,
				Error:     err.Error(),
			})
			return
		}
		slog.Info("升级已排队等待独立进程接管", "id", cmd.ID, "output", output)

	case "sync_now":
		// 一键同步：立即扫全部任务的 backup_paths，未上传的文件走上传队列
		if scn == nil {
			_ = c.CommandResult(&client.CommandResultRequest{
				CommandID: cmd.ID,
				Status:    "failed",
				Error:     "scanner 未初始化",
			})
			return
		}
		start := time.Now()
		slog.Info("触发一键同步扫描（强制清 seen map，全量重扫，含 scheduled 任务）", "id", cmd.ID)
		scn.ClearSeen()
		st := scn.ForceScanAll()
		took := time.Since(start)
		output := fmt.Sprintf("已完成一次全量扫描，用时 %.2fs：扫描 %d 个任务", took.Seconds(), st.Tasks)
		if st.Skipped > 0 {
			output += fmt.Sprintf("（%d 个未配置备份目录已跳过）", st.Skipped)
		}
		output += fmt.Sprintf("，上报 %d 个文件，触发 %d 个上传", st.Files, st.Uploads)
		_ = c.CommandResult(&client.CommandResultRequest{
			CommandID: cmd.ID,
			Status:    "success",
			Output:    output,
		})

	case "restore":
		// 远程恢复：lite 模式仅把云端归档 rclone copy 回本地目录留档，由管理员手动处理；
		// advanced 模式（params.restore_mode=advanced）在下载成功后自动预备份 +
		// 解压覆盖备份来源目录（origin_path 的父目录）
		output, err := performRestore(cmd, c, scn, up)
		if err != nil {
			slog.Warn("恢复失败", "err", err, "id", cmd.ID)
			// tar 失败可能输出成百上千行，截断到 1800 字符（与 performSnapshotRestore 同风格）
			// 再上报，避免超长错误串影响请求。
			msg := err.Error()
			if len(msg) > 1800 {
				msg = msg[:1800] + "…"
			}
			// 上报失败必须记录：否则 Center 侧该指令会永远停在 dispatched，
			// 前端 /api/restore/status 每 4 秒轮询导致界面无限转圈。
			if rerr := c.CommandResult(&client.CommandResultRequest{
				CommandID: cmd.ID,
				Status:    "failed",
				Error:     msg,
			}); rerr != nil {
				slog.Warn("恢复失败结果上报失败（Center 侧指令可能永久停留 dispatched）", "err", rerr, "id", cmd.ID)
			}
			return
		}
		if rerr := c.CommandResult(&client.CommandResultRequest{
			CommandID: cmd.ID,
			Status:    "success",
			Output:    output,
		}); rerr != nil {
			slog.Warn("恢复成功结果上报失败（Center 侧指令可能永久停留 dispatched）", "err", rerr, "id", cmd.ID)
		}

	case "pack_backup":
		output, err := performPackBackup(cmd, c, up, scn)
		if err != nil {
			slog.Warn("打包备份失败", "err", err, "id", cmd.ID)
			_ = c.CommandResult(&client.CommandResultRequest{
				CommandID: cmd.ID,
				Status:    "failed",
				Error:     err.Error(),
			})
			return
		}
		_ = c.CommandResult(&client.CommandResultRequest{
			CommandID: cmd.ID,
			Status:    "success",
			Output:    output,
		})

	case "incremental_backup":
		// 增量备份：rsync --link-dest 硬链快照推任务的各备份目录到 SFTP 目标
		// 存储（多目录逐个快照，按 label 分层，见 incremental.go）
		output, err := performIncrementalBackup(cmd, c, up, scn)
		if err != nil {
			slog.Warn("增量备份失败", "err", err, "id", cmd.ID)
			_ = c.CommandResult(&client.CommandResultRequest{CommandID: cmd.ID, Status: "failed", Error: err.Error()})
			return
		}
		_ = c.CommandResult(&client.CommandResultRequest{CommandID: cmd.ID, Status: "success", Output: output})

	case "cleanup_retention":
		// 保留期清理：删超期归档的云端文件，指令只带 log_id + remote_path，不含任何凭据
		output, err := performRetentionCleanup(cmd, c)
		if err != nil {
			slog.Warn("保留期清理失败", "err", err, "id", cmd.ID)
			_ = c.CommandResult(&client.CommandResultRequest{
				CommandID: cmd.ID,
				Status:    "failed",
				Output:    output,
				Error:     err.Error(),
			})
			return
		}
		_ = c.CommandResult(&client.CommandResultRequest{
			CommandID: cmd.ID,
			Status:    "success",
			Output:    output,
		})

	case "browse_dir":
		// 目录浏览（v0.17.0）：列出目录项元数据（name/is_dir/size/mtime），
		// params: {path}，不读文件内容。轻量指令，无需刷新任务/存储配置。
		output, err := performBrowseDir(cmd)
		if err != nil {
			slog.Warn("目录浏览失败", "err", err, "id", cmd.ID)
			_ = c.CommandResult(&client.CommandResultRequest{
				CommandID: cmd.ID,
				Status:    "failed",
				Error:     err.Error(),
			})
			return
		}
		_ = c.CommandResult(&client.CommandResultRequest{
			CommandID: cmd.ID,
			Status:    "success",
			Output:    output,
		})

	default:
		slog.Warn("未知指令类型，忽略", "type", cmd.Type, "id", cmd.ID)
		_ = c.CommandResult(&client.CommandResultRequest{
			CommandID: cmd.ID,
			Status:    "failed",
			Error:     "未支持的指令类型: " + cmd.Type,
		})
	}
}

// cleanupProgressInterval 保留期清理每处理多少条上报一次进度
const cleanupProgressInterval = 20

// cleanupRcloneTimeout 单条保留期清理的 rclone 调用超时。后端（SFTP/S3）响应慢、
// 目录巨大或网络卡死时，无超时会让 rc.Run() 永久阻塞，进而挂死整个清理指令与
// Agent 命令循环——备份日志行永远删不掉、反复重派又反复卡死。超时后杀掉 rclone，
// 该条按失败处理（日志行保留待下轮重派），保证清理指令不会无限挂起。
const cleanupRcloneTimeout = 10 * time.Minute

// performRetentionCleanup 删除超期备份的云端文件（C4）。
// params.files 为数组，元素 {log_id: number, remote_path: string, is_dir?: bool}；
// remote_path 原样取自库里存的值（形如 bc_14:bucket/... 或加密后的 bc_14_c:...），
// 直接用本地 rclone.conf 驱动，全程不接触任何存储凭据——这也是本指令存在的意义：
// Center 不再需要持存储密码跑 rclone。
// is_dir=true（v0.15 增量快照目录）时用 rclone purge 删整个目录；缺省/文件走
// deletefile。purge 对"目录不存在"报 directory not found（含 "not found"），与
// deletefile 的 not found 走同一幂等分支，所以同一快照目录下多个日志条目
// 无论先删哪个都收敛（先 purge 目录 → 后续文件条目的 deletefile 记 missing）。
//
// 幂等语义是契约的关键：Center 只在指令 success 后才删 backup_logs 行，failed 时行保留下轮重派，
// 所以 rclone 报 "not found" 必须计入"已不存在"（视为已删除），否则同一条会永远失败、行永远删不掉。
// 有任何一条真失败时返回 error，让 Center 保留行重派。
func performRetentionCleanup(cmd *client.PendingCommand, c *client.Client) (string, error) {
	if cmd.Params == nil {
		return "", fmt.Errorf("保留期清理指令缺少参数")
	}
	rawFiles, ok := cmd.Params["files"].([]interface{})
	if !ok || len(rawFiles) == 0 {
		return "", fmt.Errorf("无待清理文件")
	}
	if _, err := exec.LookPath("rclone"); err != nil {
		return "", fmt.Errorf("未检测到 rclone，无法执行保留期清理: %w", err)
	}

	total := len(rawFiles)
	var deleted, missing, failed int
	var failedPaths []string

	// 进度行与既有 restore 进度一致：超 300 字节就截断
	report := func(done int) {
		line := fmt.Sprintf("[清理 %d/%d] 删除 %d，已不存在 %d，失败 %d", done, total, deleted, missing, failed)
		if len(line) > 300 {
			line = line[:300] + "…"
		}
		_ = c.CommandProgress(&client.CommandProgressRequest{CommandID: cmd.ID, Output: line})
	}

	for i, item := range rawFiles {
		entry, ok := item.(map[string]interface{})
		if !ok {
			failed++
			failedPaths = append(failedPaths, fmt.Sprintf("第 %d 条：参数格式错误", i+1))
			continue
		}
		remotePath, _ := entry["remote_path"].(string)
		if remotePath == "" {
			failed++
			failedPaths = append(failedPaths, fmt.Sprintf("第 %d 条：remote_path 为空", i+1))
			continue
		}
		// 前缀守卫：只接受 Agent 自己渲染的 bc_<id>: / bc_<id>_c: remote，
		// 避免被篡改的指令拿本地 conf 去删任意远端。
		if !isManagedRemotePath(remotePath) {
			failed++
			failedPaths = append(failedPaths, remotePath+"（remote 前缀不受管，已拒绝）")
			continue
		}
		logID, _ := parseInt64Param(entry["log_id"]) // 仅用于日志定位，删除动作不需要

		// is_dir=true（v0.15 增量快照目录，后端按 filename 命中 -snap-<stamp> 标记）：
		// rclone purge 递归删整个目录；缺省按普通文件 deletefile
		// 每条调用独立上下文 + 超时：超时即杀 rclone，该条按失败处理（日志行保留重派）。
		ctx, cancel := context.WithTimeout(context.Background(), cleanupRcloneTimeout)
		var rc *exec.Cmd
		if isDir, _ := entry["is_dir"].(bool); isDir {
			rc = exec.CommandContext(ctx, "rclone", "purge",
				"--config", rcloneConfPath,
				"--retries", "2",
				remotePath,
			)
		} else {
			rc = exec.CommandContext(ctx, "rclone", "deletefile",
				"--config", rcloneConfPath,
				"--retries", "2",
				remotePath,
			)
		}
		stderr := &strings.Builder{}
		rc.Stderr = stderr
		runErr := rc.Run()
		timedOut := ctx.Err() == context.DeadlineExceeded // 必须在 cancel 前捕获
		cancel()                                          // 立即释放上下文，避免泄漏
		if runErr == nil {
			deleted++
		} else {
			msg := strings.TrimSpace(stderr.String())
			if msg == "" {
				msg = runErr.Error()
			}
			lower := strings.ToLower(msg)
			if timedOut {
				// 后端卡死：无法确定是否删完，按失败保留日志行，下轮重派。
				failed++
				if len(msg) > 120 {
					msg = msg[:120] + "…"
				}
				failedPaths = append(failedPaths, fmt.Sprintf("%s（清理超时 %s，已中止该条：%s）", remotePath, cleanupRcloneTimeout, msg))
				slog.Warn("保留期清理：rclone 调用超时，已中止该条",
					"log_id", logID, "remote_path", remotePath, "timeout", cleanupRcloneTimeout)
			} else if strings.Contains(lower, "not found") {
				// 已删除 / 本来就不存在：算成功，保证重派幂等
				missing++
				slog.Info("保留期清理：云端文件已不存在，按已删除处理",
					"log_id", logID, "remote_path", remotePath)
			} else {
				failed++
				if len(msg) > 120 {
					msg = msg[:120] + "…"
				}
				failedPaths = append(failedPaths, fmt.Sprintf("%s（%s）", remotePath, msg))
				slog.Warn("保留期清理：删除云端文件失败",
					"log_id", logID, "remote_path", remotePath, "err", msg)
			}
		}

		if (i+1)%cleanupProgressInterval == 0 {
			report(i + 1)
		}
	}
	report(total)

	summary := fmt.Sprintf("保留期清理完成：删除 %d，已不存在 %d，失败 %d", deleted, missing, failed)
	if len(failedPaths) > 0 {
		list := strings.Join(failedPaths, "; ")
		if len(list) > 600 {
			list = list[:600] + "…"
		}
		summary += "\n失败清单：" + list
	}
	if failed > 0 {
		return summary, fmt.Errorf("保留期清理未完成：%d 个云端文件删除失败（已删除 %d，已不存在 %d），日志行保留待下轮重派",
			failed, deleted, missing)
	}
	return summary, nil
}

// isManagedRemotePath 判断 remote_path 是否落在 Agent 自己渲染的 remote 上（bc_<id>: / bc_<id>_c:）
func isManagedRemotePath(p string) bool {
	colon := strings.Index(p, ":")
	if colon <= 0 {
		return false
	}
	name := p[:colon]
	idStr := strings.TrimSuffix(strings.TrimPrefix(name, "bc_"), "_c")
	if idStr == name { // 不是 bc_ 开头
		return false
	}
	_, err := strconv.ParseInt(idStr, 10, 64)
	return err == nil
}

// performRestore 把云端归档 rclone copy 回本地目录。
// remote_path 形如 bc_14:bucket/backups/任务/20260809/file.tar.gz（已含 rclone remote 前缀），
// 因此无需再解析 storage_id，直接复用 rclone.conf 里的 bc_<id> remote 即可。
// lite 模式：仅下载归档，由管理员手动处理（解压 tar 到目标目录）；
// advanced 模式：下载成功后按文件名分流，目录归档（-dir-*.tar.gz）自动预备份 +
// 解压覆盖备份来源目录（origin_path 由指令下发，落地目标 = filepath.Dir(origin_path)，
// 见 performAdvancedDirRestore，任一步失败均附带预备份路径便于回滚）。
// 长任务期间每 5s 通过 c.CommandProgress 上报 rclone 最新进度行（status 保持 dispatched）。
func performRestore(cmd *client.PendingCommand, c *client.Client, scn *scanner.Scanner, up *uploader.Uploader) (string, error) {
	p := cmd.Params
	if p == nil {
		return "", fmt.Errorf("恢复指令缺少参数")
	}
	remotePath, _ := p["remote_path"].(string)
	localDir, _ := p["local_dir"].(string)
	filename, _ := p["filename"].(string)
	// origin_path（v0.16 新增）：备份来源目录。advanced 恢复的落地目标 = 其父目录。
	originPath, _ := p["origin_path"].(string)

	if remotePath == "" || localDir == "" || filename == "" {
		return "", fmt.Errorf("恢复参数不完整（remote_path/local_dir/filename 不能为空）")
	}

	// restore 串行执行：等前一条恢复完整结束（含 advanced 的预备份/tar 解压）再继续，
	// 避免多条 restore 的 rclone 并发抢占带宽/磁盘。排队而非丢弃的理由见 restoreMu 声明处注释。
	// 参数非法的命令在上面已直接返回，不会等待这把锁，失败结果可即时上报 Center；
	// defer Unlock 覆盖后续所有 return 路径（下载失败 / advanced 各步骤失败均正常释放）。
	if !restoreMu.TryLock() {
		slog.Info("已有恢复任务在执行，本恢复排队等待", "id", cmd.ID, "from", remotePath)
		restoreMu.Lock()
	}
	defer restoreMu.Unlock()

	if err := os.MkdirAll(localDir, 0755); err != nil {
		return "", fmt.Errorf("创建本地目录失败 %s: %w", localDir, err)
	}
	dest := filepath.Join(localDir, filename)

	slog.Info("开始远程恢复", "id", cmd.ID, "from", remotePath, "to", dest)

	// 快照恢复分支（path_type=dir：后端只在快照日志的恢复指令里带此参数）：
	// remote_path 指向远端**目录**（rsync 快照根），不能走下面面向单文件的 copyto
	// 下载——交给 performSnapshotRestore 用 rclone copy 整目录拉回后再按
	// advanced/lite 分流。task_id 仅 advanced 模式会下发（lite 拿不到也不需要），
	// 解析失败按指令损坏处理；advanced 的落地目标由 origin_path 指定，不再依赖
	// 任务配置本身。
	if fmt.Sprint(p["path_type"]) == "dir" {
		if raw, ok := p["task_id"]; ok && raw != nil {
			taskID, perr := parseInt64Param(raw)
			if perr != nil {
				return "", fmt.Errorf("快照恢复 task_id 解析失败: %w", perr)
			}
			if _, rerr := resolveTaskForCommand(c, scn, up, taskID, "快照恢复"); rerr != nil {
				return "", rerr
			}
		}
		return performSnapshotRestore(cmd, c, dest, originPath)
	}

	rcmd := exec.Command("rclone",
		"copyto",
		"--config", rcloneConfPath,
		"--transfers", "1",
		"--retries", "3",
		"--low-level-retries", "5",
		"--stats", "5s", // 每 5s 打一次进度
		"--stats-one-line",      // 单行进度（便于抓取）
		"--log-level", "NOTICE", // 压掉逐文件 INFO 日志，保留进度行与错误
		remotePath, dest,
	)
	// 进度回报 + 完整错误缓冲统一下沉到 runRcloneWithProgress（快照恢复的 copy
	// 复用同一 helper）：这里只按既有约定把失败信息截到头 1800 字节。
	if err := runRcloneWithProgress(rcmd, cmd.ID, c); err != nil {
		msg := err.Error()
		if len(msg) > 1800 {
			msg = msg[:1800] + "…"
		}
		return "", fmt.Errorf("rclone 恢复失败: %s", msg)
	}

	// ---------- advanced 模式：下载成功后自动预备份 + 覆盖 ----------
	if fmt.Sprint(cmd.Params["restore_mode"]) == "advanced" {
		// 目录/文件归档（self:dir / self:file 的打包产物）：解压覆盖 origin_path
		// 的父目录。restore 需要 origin_path 定位落地目标，缺失时不能盲解压。
		// v0.18.0：新命名 <task>-<stamp>.tar.gz 不再携带 -dir-/-file- 文件名标记，
		// Center 依 source_key 显式下发 path_type=self_archive 作为主判定；
		// 老归档（≤v0.17 命名含 -dir-/-file-）仍按文件名标记兜底识别。
		isSelfArchive := fmt.Sprint(cmd.Params["path_type"]) == "self_archive"
		isLegacyMarked := (strings.Contains(filename, "-dir-") || strings.Contains(filename, "-file-")) && strings.HasSuffix(filename, ".tar.gz")
		if isSelfArchive || isLegacyMarked {
			if originPath == "" {
				return "", fmt.Errorf("advanced 目录恢复缺少 origin_path（备份来源目录），无法定位解压目标；请改用 lite 模式（仅下载留档）")
			}
			taskID, perr := parseInt64Param(cmd.Params["task_id"])
			if perr != nil {
				return "", fmt.Errorf("advanced 恢复 task_id 解析失败: %w", perr)
			}
			if _, terr := resolveTaskForCommand(c, scn, up, taskID, "advanced 恢复"); terr != nil {
				return "", terr
			}
			bak, err := performAdvancedDirRestore(cmd, c, originPath, dest)
			if err != nil {
				return "", err
			}
			return fmt.Sprintf("高级恢复完成：目录已覆盖（预备份 %s）", bak), nil
		}
		// 非 -dir- 归档文件名：退回 lite 行为（仅下载留档）
		_ = c.CommandProgress(&client.CommandProgressRequest{
			CommandID: cmd.ID,
			Output:    "[提示] advanced 模式未识别目录/文件归档（self 打包产物），按 lite 处理（仅下载留档）",
		})
	}

	return fmt.Sprintf("已恢复到本地: %s", dest), nil
}

// performAdvancedDirRestore 对 advanced 模式的目录归档执行"预备份 + 覆盖解压"。
// originPath 是备份来源目录（归档顶层条目 = Base(originPath)，见 performPackBackup
// 的 tar 约定）：解压目标 = filepath.Dir(originPath)，tar -C parent 覆盖解压恰为
// origin_path 本身。originPath 为空时报错，提示改用 lite 留档恢复。
// 返回预备份路径，供调用方在成功输出 / 失败回滚提示中使用。
func performAdvancedDirRestore(cmd *client.PendingCommand, c *client.Client, originPath, dest string) (string, error) {
	if originPath == "" {
		return "", fmt.Errorf("缺少 origin_path（备份来源目录），无法执行目录恢复；请改用 lite 模式（仅下载留档）")
	}
	parent := filepath.Dir(originPath)
	base := filepath.Base(originPath)
	ts := time.Now().Format("20060102-150405")
	bakPath := originPath + ".bak-" + ts + ".tar.gz"

	_ = c.CommandProgress(&client.CommandProgressRequest{
		CommandID: cmd.ID,
		Output:    "[预备份] " + originPath + " → " + bakPath,
	})
	bc := exec.Command("tar", "-czf", bakPath, "-C", parent, base)
	var berr strings.Builder
	bc.Stderr = &berr
	if err := bc.Run(); err != nil {
		return "", fmt.Errorf("目录预备份失败: %v (%s)", err, strings.TrimSpace(berr.String()))
	}

	_ = c.CommandProgress(&client.CommandProgressRequest{
		CommandID: cmd.ID,
		Output:    "[解压] 正在覆盖备份来源目录…",
	})
	// 覆盖解压前先解除 origin_path 下所有 immutable（chattr +i）属性：宝塔等面板会给
	// .user.ini 打 +i，tar 覆盖写该文件会 EPERM(Operation not permitted) 或
	// EEXIST(File exists) 导致整包失败。
	// 解压成功后再按原样 chattr +i 恢复回去（失败则保持解除状态，让提示里的手动回滚
	// tar -xzf 能正常覆盖 .user.ini）。
	immutablePaths := listImmutablePaths(originPath)
	if len(immutablePaths) > 0 {
		slog.Info("解除 immutable 属性以允许覆盖解压", "count", len(immutablePaths), "id", cmd.ID)
		for _, p := range immutablePaths {
			chattrToggle(p, false)
		}
	}

	// 不能用 --unlink-first：-U 会对每一个目录也尝试 unlink，而非空目录无法 unlink →
	// "Cannot unlink: Directory not empty"，tar 以 exit 2 失败。普通 tar 覆盖解压本身
	// 就能覆盖同名文件/目录。
	xc := exec.Command("tar", "-xzf", dest, "-C", parent)
	var xerr strings.Builder
	xc.Stderr = &xerr
	if err := xc.Run(); err != nil {
		// 兜底：属性探测可能因竞态（解压过程中面板又给文件打 +i）或探测盲区漏掉个别
		// immutable 条目。此时对整棵树强制 chattr -R -i 后重试一次，仍失败才报错。
		if isImmutableOverwriteErr(xerr.String()) {
			slog.Info("解压疑似被 immutable 阻断，整树强制解除后重试", "err", err, "id", cmd.ID)
			chattrRecursive(originPath, false)
			var rerr strings.Builder
			rxc := exec.Command("tar", "-xzf", dest, "-C", parent)
			rxc.Stderr = &rerr
			if rerr2 := rxc.Run(); rerr2 != nil {
				return bakPath, fmt.Errorf("目录解压覆盖失败: %v (%s)；可手动回滚: tar -xzf %s -C %s",
					rerr2, strings.TrimSpace(rerr.String()), bakPath, parent)
			}
			return bakPath, nil
		}
		return bakPath, fmt.Errorf("目录解压覆盖失败: %v (%s)；可手动回滚: tar -xzf %s -C %s", err, strings.TrimSpace(xerr.String()), bakPath, parent)
	}
	// 解压成功：把之前解除的 immutable 属性按原样恢复回去
	for _, p := range immutablePaths {
		chattrToggle(p, true)
	}
	return bakPath, nil
}

// isImmutableOverwriteErr 判断 tar 解压报错是否由 immutable（+i）属性导致。
func isImmutableOverwriteErr(stderr string) bool {
	return strings.Contains(stderr, "Operation not permitted") ||
		strings.Contains(stderr, "File exists")
}

// chattrRecursive 对 root 整棵树递归设置(+i)/取消(-i) immutable。失败一律忽略。
func chattrRecursive(root string, immutable bool) {
	flag := "-i"
	if immutable {
		flag = "+i"
	}
	cmd := exec.Command("chattr", flag, "-R", root)
	_ = cmd.Run()
}

// listImmutablePaths 列出 root 下所有带 immutable（不可变）属性的路径（文件/目录）。
// 输出形如"----i---------e------- /abs/path"；属性串含 i（文件）或 I（目录，
// 即 lsattr 对 immutable 目录的显示形式）即为不可变。
//
// 这里必须用 "find <root> -exec lsattr -d -- {} +"，不能用 "lsattr -R <root>"：
// lsattr 的 -R 递归输出不包含点文件（宝塔防跨站攻击用的 .user.ini 恰好是点文件，
// 实测 lsattr -R 输出 480 行里 grep -c 'user\.ini' = 0），会导致关键文件漏检、
// tar 覆盖时以 "Cannot open: File exists" 失败。find 枚举本身包含点文件。
// find 的深度优先顺序保证父目录行先于子项出现。
//
// find 不可用/输出为空时回退 lsattr -R（不覆盖点文件，但优于完全不探测）。
// 任何异常（lsattr 不存在、文件系统不支持属性、部分条目无权限等）都忽略：只返回已解析
// 到的部分，绝不因为属性探测失败而让恢复流程失败。
func listImmutablePaths(root string) []string {
	abs, err := filepath.Abs(root)
	if err != nil {
		abs = root
	}
	out := runCombinedOutput("find", abs, "-exec", "lsattr", "-d", "--", "{}", "+")
	if strings.TrimSpace(out) == "" {
		out = runCombinedOutput("lsattr", "-R", abs)
	}
	return parseImmutablePaths(out, abs)
}

// runCombinedOutput 执行命令并返回 stdout；stderr 丢弃、退出码忽略。
func runCombinedOutput(name string, args ...string) string {
	cmd := exec.Command(name, args...)
	var b strings.Builder
	cmd.Stdout = &b
	_ = cmd.Run()
	return b.String()
}

// parseImmutablePaths 从 lsattr -R 的输出中解析出所有带 immutable 属性的路径。
// base 用于把相对路径补成绝对路径。
// lsattr -R 是深度优先输出，父目录行一定出现在其子项之前，因此结果天然是「先父后子」：
// 解除属性时父目录先解除，子项的 chattr 才不会因父目录仍不可变而失败。
func parseImmutablePaths(out string, base string) []string {
	var res []string
	for _, line := range strings.Split(out, "\n") {
		line = strings.TrimRight(line, "\r")
		sp := strings.IndexAny(line, " \t")
		if sp <= 0 {
			continue
		}
		attrs, p := line[:sp], strings.TrimLeft(line[sp:], " \t")
		if p == "" || !strings.ContainsAny(attrs, "iI") {
			continue
		}
		// lsattr 输出的始终是 POSIX 路径，故用 path 包（非 filepath）判断/拼接绝对路径
		if !path.IsAbs(p) {
			p = path.Join(base, p)
		}
		res = append(res, p)
	}
	return res
}

// chattrToggle 设置(+i)/取消(-i) 指定路径的 immutable 属性。
// 失败一律忽略：文件已不存在、文件系统不支持属性、权限不足等都不应导致恢复失败。
func chattrToggle(path string, immutable bool) {
	flag := "-i"
	if immutable {
		flag = "+i"
	}
	cmd := exec.Command("chattr", flag, path)
	// stderr 丢弃
	_ = cmd.Run()
}

// performPackBackup 打包备份：对任务 backup_paths 的每条目录逐个 tar -czf 归档，
// 复用既有 report/upload 管线上传。与 external 模式（扫描已存在文件）不同，
// 这里由 Agent 主动生成备份产物。
// 部分成功语义：任一目录成功即命令成功（output 汇总成功/跳过明细）；
// 全部目录失败才返回 error。
func performPackBackup(cmd *client.PendingCommand, c *client.Client, up *uploader.Uploader, scn *scanner.Scanner) (string, error) {
	p := cmd.Params
	if p == nil {
		return "", fmt.Errorf("打包备份指令缺少参数")
	}
	taskID, err := parseInt64Param(p["task_id"])
	if err != nil {
		return "", fmt.Errorf("task_id 解析失败: %w", err)
	}
	t, err := resolveTaskForCommand(c, scn, up, taskID, "打包备份")
	if err != nil {
		return "", err
	}
	if len(t.BackupPaths) == 0 {
		return "", fmt.Errorf("任务未配置备份目录，无法打包备份")
	}

	// 工具前置检查
	if _, err := exec.LookPath("tar"); err != nil {
		return "", fmt.Errorf("服务器缺少 tar 工具，无法打包备份")
	}

	tmpDir, err := os.MkdirTemp("", "bc-pack-"+strconv.FormatInt(taskID, 10)+"-")
	if err != nil {
		return "", fmt.Errorf("创建临时目录失败: %w", err)
	}
	defer os.RemoveAll(tmpDir)

	stamp := time.Now().Format("20060102-150405")
	backupAt := time.Now()
	safe := safeName(t.Name)
	// v0.18.0：压缩包用备份时间命名。单路径任务 → <safeTask>-<stamp>.tar.gz（如
	// FC2-20260809-040000.tar.gz）；多路径任务→ 追加 -<label> 段；同批次仍重名时
	// 追加序号。文件名不再携带 -dir-/-file- 标记（恢复分流改由 Center 依
	// source_key 下发 path_type 参数，老文件名标记保留兜底）。
	multi := len(t.BackupPaths) > 1
	usedNames := make(map[string]bool)
	uniqueArcName := func(label string) string {
		base := safe + "-" + stamp
		if multi {
			base += "-" + label
		}
		name := base + ".tar.gz"
		for n := 2; usedNames[name]; n++ {
			name = fmt.Sprintf("%s-%d.tar.gz", base, n)
		}
		usedNames[name] = true
		return name
	}

	var okDirs, badDirs []string
	for i, bp := range t.BackupPaths {
		info, serr := os.Stat(bp.Path)
		if serr != nil {
			badDirs = append(badDirs, bp.Path+"（路径不存在）")
			continue
		}
		label := safeName(dirLabel(bp.Label, i))

		if !info.IsDir() {
			// ---------- 文件分支（v0.17.0）：单文件打包，包内保留父目录层 ----------
			// 勾选 /mnt/BBC/1.txt → tar -C /mnt BBC/1.txt（包内带父目录层），
			// origin_path 记父目录 /mnt/BBC：恢复（advanced）与目录归档完全同构，
			// ——预备份整个父目录 + 解压到 Dir(origin_path)，落回原位。
			// 根下文件（/x.txt）退化 -C / 打包文件名（无更多层级可保留）。
			parentDir := filepath.Dir(bp.Path) // origin_path：/mnt/BBC
			tarRoot := filepath.Dir(parentDir) // tar -C 根：/mnt（parentDir=="/" 时同为 /）
			fileBase := filepath.Base(bp.Path) // 1.txt
			var arcEntry string
			if parentDir == "/" {
				arcEntry = fileBase
			} else {
				arcEntry = filepath.Base(parentDir) + "/" + fileBase // BBC/1.txt
			}
			arcName := uniqueArcName(label)
			arcPath := filepath.Join(tmpDir, arcName)
			slog.Info("打包备份：打包文件", "id", cmd.ID, "from", bp.Path, "to", arcPath)
			tcmd := exec.Command("tar", "-czf", arcPath, "-C", tarRoot, arcEntry)
			if out, terr := tcmd.CombinedOutput(); terr != nil {
				msg := strings.TrimSpace(string(out))
				if len(msg) > 300 {
					msg = "…" + msg[len(msg)-300:]
				}
				badDirs = append(badDirs, bp.Path+"（tar 失败: "+msg+"）")
				continue
			}
			var size int64
			if fi, ferr := os.Stat(arcPath); ferr == nil {
				size = fi.Size()
			}
			if uerr := reportAndUpload(c, up, cmd.ID, taskID, t, arcName, arcPath, parentDir, size, backupAt, t.StorageIDs, "file"); uerr != nil {
				slog.Warn("上报/上传文件归档失败", "err", uerr, "file", bp.Path)
				badDirs = append(badDirs, bp.Path+"（"+uerr.Error()+"）")
				continue
			}
			_ = c.CommandProgress(&client.CommandProgressRequest{CommandID: cmd.ID, Output: "文件归档已上传: " + arcName})
			okDirs = append(okDirs, "文件归档 "+arcName+"（"+humanizeBytes(size)+"）")
			continue
		}

		// ---------- 目录分支（原有行为，v0.18.0 起改名与 flat 布局）----------
		parent := filepath.Dir(bp.Path)
		base := filepath.Base(bp.Path)
		arcName := uniqueArcName(label)
		arcPath := filepath.Join(tmpDir, arcName)
		slog.Info("打包备份：打包目录", "id", cmd.ID, "from", bp.Path, "to", arcPath)
		tcmd := exec.Command("tar", "-czf", arcPath, "-C", parent, base)
		if out, terr := tcmd.CombinedOutput(); terr != nil {
			msg := strings.TrimSpace(string(out))
			if len(msg) > 300 {
				msg = "…" + msg[len(msg)-300:]
			}
			badDirs = append(badDirs, bp.Path+"（tar 失败: "+msg+"）")
			continue
		}
		var size int64
		if fi, ferr := os.Stat(arcPath); ferr == nil {
			size = fi.Size()
		}
		if uerr := reportAndUpload(c, up, cmd.ID, taskID, t, arcName, arcPath, bp.Path, size, backupAt, t.StorageIDs, "dir"); uerr != nil {
			slog.Warn("上报/上传目录归档失败", "err", uerr, "dir", bp.Path)
			badDirs = append(badDirs, bp.Path+"（"+uerr.Error()+"）")
			continue
		}
		_ = c.CommandProgress(&client.CommandProgressRequest{CommandID: cmd.ID, Output: "目录归档已上传: " + arcName})
		okDirs = append(okDirs, "目录归档 "+arcName+"（"+humanizeBytes(size)+"）")
	}

	if len(okDirs) == 0 {
		return "", fmt.Errorf("打包备份失败：所有目录均未成功（%s）", strings.Join(badDirs, "；"))
	}
	summary := fmt.Sprintf("打包备份完成：成功 %d 项（%s）", len(okDirs), strings.Join(okDirs, " · "))
	if len(badDirs) > 0 {
		summary += fmt.Sprintf(" · 跳过/失败 %d 个（%s）", len(badDirs), strings.Join(badDirs, "；"))
	}
	if len(t.StorageIDs) == 0 {
		summary += "（未配置存储目标，仅生成本地记录）"
	} else {
		summary += fmt.Sprintf("，已推送至 %d 个存储", len(t.StorageIDs))
	}
	return summary, nil
}

func parseInt64Param(v interface{}) (int64, error) {
	switch t := v.(type) {
	case float64:
		return int64(t), nil
	case int64:
		return t, nil
	case int:
		return int64(t), nil
	case json.Number:
		return t.Int64()
	default:
		return 0, fmt.Errorf("非预期类型 %T", v)
	}
}

func safeName(name string) string {
	var b strings.Builder
	for _, r := range name {
		if (r >= 'a' && r <= 'z') || (r >= 'A' && r <= 'Z') || (r >= '0' && r <= '9') || r == '.' || r == '_' || r == '-' {
			b.WriteRune(r)
		} else {
			b.WriteRune('_')
		}
	}
	s := strings.TrimSpace(b.String())
	if s == "" {
		return "task"
	}
	return s
}

// dirLabel 取目录条目的显示名：label 非空用 label，空则按序号 d1/d2… 兜底。
// pack 归档名（<safeTask>-dir-<safeLabel>-<stamp>.tar.gz）与快照远端目录/上报名
// 的 label 段都以此为准（Center↔Agent 两端同口径）。
func dirLabel(label string, idx int) string {
	if s := strings.TrimSpace(label); s != "" {
		return s
	}
	return fmt.Sprintf("d%d", idx+1)
}

func humanizeBytes(b int64) string {
	const unit = 1024
	if b < unit {
		return fmt.Sprintf("%d B", b)
	}
	div := float64(unit)
	for _, u := range []string{"KB", "MB", "GB", "TB"} {
		if b < int64(div*unit) {
			return fmt.Sprintf("%.1f %s", float64(b)/div, u)
		}
		div *= unit
	}
	return fmt.Sprintf("%.1f TB", float64(b)/div)
}

// reportAndUpload 上报一条备份记录并（同步）上传到指定目标存储。
// storageIDs 由调用方给：打包备份传 t.StorageIDs（任务配置的全部存储）。
// 增量备份的产物落在快照目录而非标准布局，改用 incremental.go 的
// reportIncrementalLog + 手工 upload-report 直报，不走本函数。
// OriginPath 记录备份来源目录（bp.Path 或其父目录）：Center 据此在 advanced
// 恢复时定位解压目标（origin_path 的父目录）。
// sourceType（v0.18.0）＝ "dir" | "file"：打包产物的来源类型。新命名的压缩包
// 文件名不再携带 -dir-/-file- 标记，Center 靠此字段直写 source_key（self:dir /
// self:file），不再依赖文件名正则反推。
func reportAndUpload(c *client.Client, up *uploader.Uploader, cmdID, taskID int64, t client.Task, filename, fullPath, originPath string, size int64, backupAt time.Time, storageIDs []int64, sourceType string) error {
	resp, err := c.Report(&client.ReportRequest{
		TaskID:     taskID,
		Filename:   filename,
		FullPath:   fullPath,
		OriginPath: originPath,
		Size:       size,
		BackupAt:   backupAt.Format(time.RFC3339),
		Status:     "success",
		// 任务编码 = pack_backup 指令 ID：同一次指令产出的多个目录归档带同一编码，
		// Center 按编码精确分组（一次指令 = 一场备份，零时间窗口猜测）。
		// 本函数只被 performPackBackup 调用（每目录一次，cmdID 相同）。
		BatchCode: strconv.FormatInt(cmdID, 10),
		SourceType: sourceType,
	})
	if err != nil {
		return fmt.Errorf("上报备份记录失败: %w", err)
	}
	// v0.18.0：打包产物平铺到 <base_path>/<任务名>/，不落按日目录（ProcessBackupFlat）。
	// external 扫描（归档收集）仍走 ProcessBackup 的 <任务名>/<日期>/ 归档布局不变。
	up.ProcessBackupFlat(resp.LogID, fullPath, t.Name, storageIDs)
	return nil
}

// progressTracker 从 rclone stderr 抓取"最新一行非空进度"（--stats-one-line 用 \r 覆盖刷新，
// 日志用 \n 分隔；按 \r/\n 切分取最后一个非空片段）。
type progressTracker struct {
	mu   sync.Mutex
	line string
}

func (t *progressTracker) Write(p []byte) (int, error) {
	parts := strings.FieldsFunc(string(p), func(r rune) bool { return r == '\r' || r == '\n' })
	for i := len(parts) - 1; i >= 0; i-- {
		if s := strings.TrimSpace(parts[i]); s != "" {
			t.mu.Lock()
			t.line = s
			t.mu.Unlock()
			break
		}
	}
	return len(p), nil
}

func (t *progressTracker) last() string {
	t.mu.Lock()
	defer t.mu.Unlock()
	return t.line
}

// syncBuf 带锁的 strings.Builder：exec.Cmd 的 Stdout/Stderr 由各自 goroutine
// 并发拷贝（rclone 只写 stderr；rsync 的 progress2 走 stdout），直接共用
// strings.Builder 会数据竞争，包一层互斥锁即可安全复用。
type syncBuf struct {
	mu  sync.Mutex
	buf strings.Builder
}

func (s *syncBuf) Write(p []byte) (int, error) {
	s.mu.Lock()
	defer s.mu.Unlock()
	return s.buf.Write(p)
}

func (s *syncBuf) String() string {
	s.mu.Lock()
	defer s.mu.Unlock()
	return s.buf.String()
}

// startProgressLoop 起一个 5s ticker：把 tracker 的最新进度行推给 Center
// （截断 300 字节，status 保持 dispatched，长任务专用）。
// 返回 stop：关闭通道并等待 goroutine 退出——必须等它停下再读错误缓冲，
// 否则与抓取 goroutine 构成数据竞争。
func startProgressLoop(commandID int64, c *client.Client, tracker *progressTracker) func() {
	stop := make(chan struct{})
	var wg sync.WaitGroup
	wg.Add(1)
	go func() {
		defer wg.Done()
		t := time.NewTicker(5 * time.Second)
		defer t.Stop()
		last := ""
		for {
			select {
			case <-stop:
				return
			case <-t.C:
				line := tracker.last()
				if line == "" || line == last {
					continue
				}
				last = line
				if len(line) > 300 {
					line = line[:300] + "…"
				}
				_ = c.CommandProgress(&client.CommandProgressRequest{
					CommandID: commandID,
					Output:    line,
				})
			}
		}
	}()
	return func() {
		close(stop)
		wg.Wait()
	}
}

// runRcloneWithProgress 跑一条长任务 rclone 命令（restore 的 copyto / 快照恢复的
// copy）：stdout+stderr 合并抓取，期间每 5s 上报最新进度行。失败时返回的错误
// 信息即完整输出文本（可能很长），由调用方按约定截断（restore/copy 路径取头
// 1800 字节）。
func runRcloneWithProgress(rcmd *exec.Cmd, commandID int64, c *client.Client) error {
	buf := &syncBuf{}
	tracker := &progressTracker{}
	out := io.MultiWriter(buf, tracker)
	rcmd.Stdout = out
	rcmd.Stderr = out

	stop := startProgressLoop(commandID, c, tracker)
	runErr := rcmd.Run()
	stop()

	if runErr != nil {
		msg := strings.TrimSpace(buf.String())
		if msg == "" {
			msg = runErr.Error()
		}
		return fmt.Errorf("%s", msg)
	}
	return nil
}

func downloadFile(url, dest string) error {
	// 30 分钟：跨洲下载 5-10 MB 二进制在慢线路（中欧之间）需要更长 timeout。
	// 5MB @ 3KB/s = 28 分钟，加冗余。
	c := &http.Client{Timeout: 30 * time.Minute}
	resp, err := c.Get(url)
	if err != nil {
		return err
	}
	defer resp.Body.Close()
	if resp.StatusCode != http.StatusOK {
		return fmt.Errorf("HTTP %s", resp.Status)
	}
	f, err := os.OpenFile(dest, os.O_CREATE|os.O_WRONLY|os.O_TRUNC, 0755)
	if err != nil {
		return err
	}
	defer f.Close()
	if _, err := io.Copy(f, resp.Body); err != nil {
		return err
	}
	return nil
}

func probeVersion(path string) (string, error) {
	out, err := exec.Command(path, "version").Output()
	if err != nil {
		return "", err
	}
	s := strings.TrimSpace(string(out))
	const prefix = "backup-agent v"
	if !strings.HasPrefix(s, prefix) {
		return "", fmt.Errorf("意外输出: %q", s)
	}
	return strings.TrimPrefix(s, prefix), nil
}

func cmdTest(configPath string) {
	cfg, err := config.Load(configPath)
	if err != nil {
		fmt.Fprintf(os.Stderr, "读配置失败: %v\n", err)
		os.Exit(1)
	}
	c := client.New(cfg.CenterURL, cfg.AgentToken, AgentVersion)
	info := sysinfo.Basic()
	resp, err := c.Register(info)
	if err != nil {
		fmt.Fprintf(os.Stderr, "连接测试失败: %v\n", err)
		os.Exit(1)
	}
	fmt.Printf("连接 OK — server_id=%d server_name=%s\n", resp.ServerID, resp.ServerName)
}

func durSecs(sec, defaultSec int) time.Duration {
	if sec <= 0 {
		sec = defaultSec
	}
	return time.Duration(sec) * time.Second
}

func backoff(attempt int) time.Duration {
	d := time.Duration(1<<uint(min(attempt, 8))) * time.Second
	if d > 5*time.Minute {
		d = 5 * time.Minute
	}
	return d
}

func min(a, b int) int {
	if a < b {
		return a
	}
	return b
}
