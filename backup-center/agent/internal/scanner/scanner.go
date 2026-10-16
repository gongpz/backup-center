package scanner

import (
	"log/slog"
	"os"
	"path/filepath"
	"sync"
	"time"

	"github.com/gongpz/backup-center/agent/internal/client"
)

// UploadDispatcher 是 Uploader 需实现的接口（避免循环依赖）
type UploadDispatcher interface {
	ProcessBackup(logID int64, localFile, websiteName string, backupAt time.Time, storageIDs []int64)
}

// Scanner 定期扫描每个网站的备份目录，发现新文件时上报到 Center 并转交 Uploader
type Scanner struct {
	client   *client.Client
	uploader UploadDispatcher

	mu    sync.Mutex
	sites []client.Website
	seen  map[int64]map[string]bool
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

// SetWebsites 更新监控网站清单
func (s *Scanner) SetWebsites(sites []client.Website) {
	s.mu.Lock()
	defer s.mu.Unlock()
	s.sites = sites

	active := make(map[int64]bool, len(sites))
	for _, w := range sites {
		active[w.ID] = true
	}
	for id := range s.seen {
		if !active[id] {
			delete(s.seen, id)
		}
	}
}

// WebsiteByID 从已加载的网站清单里按 ID 取网站配置（self_backup 用）
func (s *Scanner) WebsiteByID(id int64) (client.Website, bool) {
	s.mu.Lock()
	defer s.mu.Unlock()
	for _, w := range s.sites {
		if w.ID == id {
			return w, true
		}
	}
	return client.Website{}, false
}

// ScanAll 扫描所有网站的备份目录，上报新发现的文件 + 触发上传
// sync_mode = "scheduled" 的网站会被跳过（等 Center 派 sync_now 或用户手动触发）
func (s *Scanner) ScanAll() {
	s.mu.Lock()
	sites := make([]client.Website, len(s.sites))
	copy(sites, s.sites)
	s.mu.Unlock()

	for _, w := range sites {
		if w.SyncMode == "scheduled" {
			continue
		}
		s.scanOne(w)
	}
}

// ScanStats 一次强制扫描的汇总（sync_now 用）：让 Center 侧看到「到底扫了什么」，
// 避免用户点了同步却看不到任何记录、无法判断是没扫到还是网站没配备份目录。
type ScanStats struct {
	Websites int // 参与扫描的网站数
	Skipped  int // 未配置备份目录（backup_paths 为空）而跳过的网站数
	Files    int // 上报成功的备份文件数（含已存在记录的）
	Uploads  int // 触发上传的文件数
}

// ForceScanAll 强制扫描所有网站（含 scheduled 模式）。sync_now 命令用这个。
func (s *Scanner) ForceScanAll() ScanStats {
	s.mu.Lock()
	sites := make([]client.Website, len(s.sites))
	copy(sites, s.sites)
	s.mu.Unlock()

	var st ScanStats
	for _, w := range sites {
		st.Websites++
		if len(w.EffectivePaths()) == 0 {
			st.Skipped++
			continue
		}
		files, uploads := s.scanOne(w)
		st.Files += files
		st.Uploads += uploads
	}
	return st
}

func (s *Scanner) scanOne(w client.Website) (files, uploads int) {
	paths := w.EffectivePaths()
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
			slog.Warn("扫描 glob 失败", "website", w.Name, "pattern", fullPattern, "err", err)
			continue
		}

		for _, path := range matches {
			info, err := os.Stat(path)
			if err != nil || info.IsDir() {
				continue
			}
			filename := filepath.Base(path)

			if s.wasSeen(w.ID, filename) {
				continue
			}

			req := &client.ReportRequest{
				WebsiteID: w.ID,
				Filename:  filename,
				FullPath:  path,
				Size:      info.Size(),
				BackupAt:  info.ModTime().UTC().Format(time.RFC3339),
				Status:    "success",
			}
		resp, err := s.client.Report(req)
		if err != nil {
			slog.Warn("上报失败", "website", w.Name, "file", filename, "err", err)
			continue
		}
		s.markSeen(w.ID, filename)
		files++

		if resp.Created {
			slog.Info("发现新备份", "website", w.Name, "file", filename, "size", info.Size(), "log_id", resp.LogID)
		} else {
			slog.Debug("上报（已存在）", "website", w.Name, "file", filename)
		}

		// 触发上传（如果配了 uploader 和该网站有存储目标）
		if s.uploader != nil && len(w.StorageIDs) > 0 {
			logID := resp.LogID
			storageIDs := append([]int64(nil), w.StorageIDs...)
			backupAt := info.ModTime()
			uploads++
		go s.uploader.ProcessBackup(logID, path, w.Name, backupAt, storageIDs)
	}
	}
	}
	return files, uploads
}

func (s *Scanner) wasSeen(websiteID int64, filename string) bool {
	s.mu.Lock()
	defer s.mu.Unlock()
	if m, ok := s.seen[websiteID]; ok {
		return m[filename]
	}
	return false
}

func (s *Scanner) markSeen(websiteID int64, filename string) {
	s.mu.Lock()
	defer s.mu.Unlock()
	if _, ok := s.seen[websiteID]; !ok {
		s.seen[websiteID] = make(map[string]bool)
	}
	s.seen[websiteID][filename] = true
}
