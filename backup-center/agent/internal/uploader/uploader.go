package uploader

import (
	"fmt"
	"log/slog"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"sync"
	"time"

	"github.com/imask/backup-agent/internal/client"
)

// Uploader 管理 rclone 配置 + 执行上传 + 上报状态
type Uploader struct {
	client     *client.Client
	configPath string // rclone.conf 路径，例如 /etc/backup-agent/rclone.conf

	mu       sync.Mutex
	storages map[int64]client.Storage
}

func New(c *client.Client, configPath string) *Uploader {
	return &Uploader{
		client:     c,
		configPath: configPath,
		storages:   make(map[int64]client.Storage),
	}
}

// UpdateStorages 更新存储清单并重写 rclone.conf
func (u *Uploader) UpdateStorages(storages []client.Storage) error {
	u.mu.Lock()
	defer u.mu.Unlock()

	u.storages = make(map[int64]client.Storage, len(storages))
	for _, s := range storages {
		u.storages[s.ID] = s
	}

	return u.writeRcloneConf()
}

// StorageByID 查询存储
func (u *Uploader) StorageByID(id int64) (client.Storage, bool) {
	u.mu.Lock()
	defer u.mu.Unlock()
	s, ok := u.storages[id]
	return s, ok
}

// ProcessBackup 对一个 backup_log 的所有目标存储发起上传（顺序）
// logID: 服务端的 backup_log_id
// localFile: 本地绝对路径
// websiteName: 用于分子目录
// backupAt: 备份文件的 mtime，用于生成时间戳子目录（按小时归组）
// storageIDs: 该网站的有效存储 ID 列表
func (u *Uploader) ProcessBackup(logID int64, localFile, websiteName string, backupAt time.Time, storageIDs []int64) {
	if len(storageIDs) == 0 {
		slog.Debug("无存储目标，跳过上传", "log_id", logID, "file", localFile)
		return
	}
	batchDir := batchDirFromTime(backupAt)
	for _, sid := range storageIDs {
		u.uploadOne(logID, sid, localFile, websiteName, batchDir)
	}
}

// batchDirFromTime 把 mtime 向下取整到"日"，格式 20260809
// 同一天内的所有备份文件（网站 tar + 数据库 sql，即使不同时间打包）都落到同一个日期目录
func batchDirFromTime(t time.Time) string {
	if t.IsZero() {
		t = time.Now()
	}
	local := t.Local()
	return local.Format("20060102")
}

func (u *Uploader) uploadOne(logID, storageID int64, localFile, websiteName, batchDir string) {
	storage, ok := u.StorageByID(storageID)
	if !ok {
		slog.Warn("上传目标存储不存在", "log_id", logID, "storage_id", storageID)
		u.report(logID, storageID, client.UploadReportRequest{
			BackupLogID:  logID,
			StorageID:    storageID,
			Status:       "failed",
			ErrorMessage: "storage not found in agent config (拉配置延迟或该存储已删除)",
		})
		return
	}

	remoteTarget := u.remoteTargetPath(storage, websiteName, batchDir, filepath.Base(localFile))

	// 报 uploading
	u.report(logID, storageID, client.UploadReportRequest{
		BackupLogID: logID,
		StorageID:   storageID,
		Status:      "uploading",
		RemotePath:  remoteTarget,
	})

	slog.Info("开始上传", "storage", storage.Name, "file", localFile, "→", remoteTarget)
	start := time.Now()

	// 调 rclone copyto
	cmd := exec.Command("rclone",
		"copyto",
		"--config", u.configPath,
		"--transfers", "1",
		"--retries", "3",
		"--low-level-retries", "5",
		"--stats", "0",
		localFile, remoteTarget,
	)
	// 采集 stderr 内容作为错误信息
	stderr := &strings.Builder{}
	cmd.Stderr = stderr

	err := cmd.Run()
	duration := int(time.Since(start).Seconds())

	fileSize := int64(0)
	if fi, ferr := os.Stat(localFile); ferr == nil {
		fileSize = fi.Size()
	}

	if err != nil {
		errMsg := strings.TrimSpace(stderr.String())
		if errMsg == "" {
			errMsg = err.Error()
		}
		// stderr 可能很长，截断
		if len(errMsg) > 1800 {
			errMsg = errMsg[:1800] + "…"
		}
		slog.Warn("上传失败", "storage", storage.Name, "err", errMsg)
		u.report(logID, storageID, client.UploadReportRequest{
			BackupLogID:     logID,
			StorageID:       storageID,
			Status:          "failed",
			RemotePath:      remoteTarget,
			DurationSeconds: duration,
			ErrorMessage:    errMsg,
		})
		return
	}

	slog.Info("上传成功", "storage", storage.Name, "duration", duration, "size", fileSize)
	u.report(logID, storageID, client.UploadReportRequest{
		BackupLogID:     logID,
		StorageID:       storageID,
		Status:          "uploaded",
		RemotePath:      remoteTarget,
		UploadedBytes:   fileSize,
		DurationSeconds: duration,
	})
}

func (u *Uploader) report(logID, storageID int64, req client.UploadReportRequest) {
	req.BackupLogID = logID
	req.StorageID = storageID
	if _, err := u.client.UploadReport(&req); err != nil {
		slog.Warn("上报上传状态失败", "log_id", logID, "storage_id", storageID, "err", err)
	}
}

// remoteTargetPath 生成 rclone 目标路径
// 例如 bc_14:bucket/backups/博客/20260809_07/file.tar.gz
// batchDir 是时间戳子目录（按小时归组），空串则不加时间戳层（保底兼容）
func (u *Uploader) remoteTargetPath(s client.Storage, websiteName, batchDir, filename string) string {
	remote := remoteName(s.ID)
	site := sanitizePathSegment(websiteName)

	// S3：base_path 是 bucket 内 prefix，去掉两侧 /，然后 bucket + base + site + batch + file
	if s.Type == "s3" {
		parts := []string{}
		if b, ok := s.Config["bucket"].(string); ok && b != "" {
			parts = append(parts, strings.Trim(b, "/"))
		}
		if base := strings.Trim(s.BasePath, "/"); base != "" {
			parts = append(parts, base)
		}
		parts = append(parts, site)
		if batchDir != "" {
			parts = append(parts, batchDir)
		}
		parts = append(parts, filename)
		return remote + ":" + strings.Join(parts, "/")
	}

	// SFTP/FTP/WebDAV：base_path 前导 / 必须保留 —— 相对路径会被 rclone 拼登录目录导致"双重路径"
	base := strings.TrimRight(s.BasePath, "/")
	prefix := ""
	if base != "" {
		prefix = base + "/"
	}
	if batchDir != "" {
		return remote + ":" + prefix + site + "/" + batchDir + "/" + filename
	}
	return remote + ":" + prefix + site + "/" + filename
}

// remoteName 生成 rclone remote 名称
func remoteName(storageID int64) string {
	return fmt.Sprintf("bc_%d", storageID)
}

// sanitizePathSegment 清理路径段：去掉危险字符
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
