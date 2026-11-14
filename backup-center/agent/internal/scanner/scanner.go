package scanner

import (
	"log/slog"
	"os"
	"path/filepath"
	"sync"
	"time"

	"github.com/SinGyoZ/backup-center/agent/internal/client"
)

// UploadDispatcher 是 Uploader 需实现的接口（避免循环依赖）
type UploadDispatcher interface {
	ProcessBackup(logID int64, localFile, taskName string, backupAt time.Time, storageIDs []int64)
}

// Scanner 定期扫描每个任务的备份目录，发现新文件时上报到 Center 并转交 Uploader
type Scanner struct {
	client   *client.Client
	uploader UploadDispatcher

	mu    sync.Mutex
	tasks []client.Task
	// seen[taskID][完整文件路径] = 是否已上报过。
	// 必须是完整路径 —— 同一任务配了多个备份目录时，
	// 不同目录下的同名文件按文件名去重会被误判为重复。
	seen map[int64]map[string]bool
}

func New(c *client.Client, uploader UploadDispatcher) *Scanner {
	return &Scanner{
		client:   c,
		uploader: uploader,
		seen:     make(map[int64]map[string]bool),
	}
}

// ClearSeen 清空 seen map，让所有本地文件在下次 ScanAll 里被重新发现 + 重新上报
// 用于手动同步：DB 端可能被清过，Agent 需要重新回填
func (s *Scanner) ClearSeen() {
	s.mu.Lock()
	defer s.mu.Unlock()
	s.seen = make(map[int64]map[string]bool)
}

// SetTasks 更新监控任务清单
func (s *Scanner) SetTasks(tasks []client.Task) {
	s.mu.Lock()
	defer s.mu.Unlock()
	s.tasks = tasks

	active := make(map[int64]bool, len(tasks))
	for _, t := range tasks {
		active[t.ID] = true
	}
	for id := range s.seen {
		if !active[id] {
			delete(s.seen, id)
		}
	}
}

// TaskByID 从已加载的任务清单里按 ID 取任务配置（pack_backup 用）
func (s *Scanner) TaskByID(id int64) (client.Task, bool) {
	s.mu.Lock()
	defer s.mu.Unlock()
	for _, t := range s.tasks {
		if t.ID == id {
			return t, true
		}
	}
	return client.Task{}, false
}

// ScanAll 扫描所有任务的备份目录，上报新发现的文件 + 触发上传
// sync_mode = "scheduled" 的任务会被跳过（等 Center 派 sync_now 或用户手动触发）
func (s *Scanner) ScanAll() {
	s.mu.Lock()
	tasks := make([]client.Task, len(s.tasks))
	copy(tasks, s.tasks)
	s.mu.Unlock()

	for _, t := range tasks {
		if t.SyncMode == "scheduled" {
			continue
		}
		s.scanOne(t)
	}
}

// ScanStats 一次强制扫描的汇总（sync_now 用）：让 Center 侧看到「到底扫了什么」，
// 避免用户点了同步却看不到任何记录、无法判断是没扫到还是任务没配备份目录。
type ScanStats struct {
	Tasks   int // 参与扫描的任务数
	Skipped int // 未配置备份目录（backup_paths 为空）而跳过的任务数
	Files   int // 上报成功的备份文件数（含已存在记录的）
	Uploads int // 触发上传的文件数
}

// ForceScanAll 强制扫描所有任务（含 scheduled 模式）。sync_now 命令用这个。
func (s *Scanner) ForceScanAll() ScanStats {
	s.mu.Lock()
	tasks := make([]client.Task, len(s.tasks))
	copy(tasks, s.tasks)
	s.mu.Unlock()

	var st ScanStats
	for _, t := range tasks {
		st.Tasks++
		if len(t.EffectivePaths()) == 0 {
			st.Skipped++
			continue
		}
		files, uploads := s.scanOne(t)
		st.Files += files
		st.Uploads += uploads
	}
	return st
}

func (s *Scanner) scanOne(t client.Task) (files, uploads int) {
	paths := t.EffectivePaths()
	if len(paths) == 0 {
		return 0, 0
	}

	for _, bp := range paths {
		if bp.Path == "" {
			continue
		}
		pattern := bp.Pattern
		if pattern == "" {
			pattern = "*"
		}

		fullPattern := filepath.Join(bp.Path, pattern)
		matches, err := filepath.Glob(fullPattern)
		if err != nil {
			slog.Warn("扫描 glob 失败", "task", t.Name, "pattern", fullPattern, "err", err)
			continue
		}

		for _, path := range matches {
			info, err := os.Stat(path)
			if err != nil || info.IsDir() {
				continue
			}
			filename := filepath.Base(path)

			// 用完整路径去重：同一任务的不同备份目录里可能存在同名文件，
			// 按文件名去重会让第二个目录的备份永远上报不上来
			//（表现为恢复界面只显示一个可恢复文件）。
			//
			// 原子占位（检查即标记）：周期 ScanAll 与 sync_now 的 ForceScanAll
			// （ClearSeen 后重扫全部）可能并发跑同一任务，旧的
			// wasSeen→Report→markSeen 三步非原子——两个扫描都通过 wasSeen，
			// 同一文件被重复上报 + 重复触发上传。
			// claimSeen 在锁内一步完成检查与占位。
			if !s.claimSeen(t.ID, path) {
				continue
			}

			req := &client.ReportRequest{
				TaskID:   t.ID,
				Filename: filename,
				FullPath: path,
				// OriginPath 备份来源目录：external 模式下即发现文件的所在目录，
				// Center 据此在 advanced 恢复时定位解压目标（origin_path 的父目录）。
				OriginPath: filepath.Dir(path),
				Size:       info.Size(),
				BackupAt:   info.ModTime().UTC().Format(time.RFC3339),
				Status:     "success",
			}
			resp, err := s.client.Report(req)
			if err != nil {
				slog.Warn("上报失败", "task", t.Name, "file", filename, "err", err)
				// 上报失败：释放占位，让下轮扫描重新发现该文件（保持重试语义）
				s.releaseClaim(t.ID, path)
				continue
			}
			files++

			if resp.Created {
				slog.Info("发现新备份", "task", t.Name, "file", filename, "size", info.Size(), "log_id", resp.LogID)
			} else {
				slog.Debug("上报（已存在）", "task", t.Name, "file", filename)
			}

			// 触发上传（如果配了 uploader 和该任务有存储目标）
			if s.uploader != nil && len(t.StorageIDs) > 0 {
				logID := resp.LogID
				storageIDs := append([]int64(nil), t.StorageIDs...)
				backupAt := info.ModTime()
				uploads++
				go s.uploader.ProcessBackup(logID, path, t.Name, backupAt, storageIDs)
			}
		}
	}
	return files, uploads
}

// claimSeen / releaseClaim 的 key 是「完整文件路径」，不是文件名。
//
// claimSeen 原子地检查并占位：已占位（已见过或正在上报中）返回 false。
// 取代旧的 wasSeen + markSeen 两步非原子操作，杜绝并发扫描下同一文件
// 被重复触发上传。ForceScanAll 前的 ClearSeen 会清空整表，手动同步不受影响。
func (s *Scanner) claimSeen(taskID int64, path string) bool {
	s.mu.Lock()
	defer s.mu.Unlock()
	if m, ok := s.seen[taskID]; ok && m[path] {
		return false
	}
	if _, ok := s.seen[taskID]; !ok {
		s.seen[taskID] = make(map[string]bool)
	}
	s.seen[taskID][path] = true
	return true
}

// releaseClaim 释放占位——仅上报失败时调用，让下轮扫描可以重新发现该文件
func (s *Scanner) releaseClaim(taskID int64, path string) {
	s.mu.Lock()
	defer s.mu.Unlock()
	if m, ok := s.seen[taskID]; ok {
		delete(m, path)
	}
}
