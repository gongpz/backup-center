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

	"github.com/gongpz/backup-center/agent/internal/client"
)

// Uploader 管理 rclone 配置 + 执行上传 + 上报状态
type Uploader struct {
	client     *client.Client
	configPath string // rclone.conf 路径，例如 /etc/backup-agent/rclone.conf

	mu       sync.Mutex
	storages map[int64]client.Storage
	// cryptSections 记录哪些存储成功渲染了 crypt 包裹节（C1）。
	// remoteTargetPath 据此决定上传前缀 bc_<id>_c:；加密启用但 section 缺失时
	// 返回错误，禁止静默降级为明文 remote。
	cryptSections map[int64]bool
}

func New(c *client.Client, configPath string) *Uploader {
	return &Uploader{
		client:        c,
		configPath:    configPath,
		storages:      make(map[int64]client.Storage),
		cryptSections: make(map[int64]bool),
	}
}

// UpdateStorages 更新存储清单并重写 rclone.conf
func (u *Uploader) UpdateStorages(storages []client.Storage) error {
	u.mu.Lock()
	defer u.mu.Unlock()

	u.storages = make(map[int64]client.Storage, len(storages))
	valid := make(map[int64]bool, len(storages))
	for _, s := range storages {
		u.storages[s.ID] = s
		valid[s.ID] = true
	}

	if err := u.writeRcloneConf(); err != nil {
		return err
	}

	// C5：只在成功写入新 conf 后清理残留（与 conf 写入同一把锁）——
	// 删除不在本次存储集合中的 bc_<id>/bc_<id>_c section 与 keys/bc_<id>.key；
	// 本次集合内的 id 一律不删。
	pruneStaleSections(u.configPath, valid)
	cleanupStaleKeyFiles(valid)
	return nil
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

	remoteTarget, err := u.remoteTargetPath(storage, websiteName, batchDir, filepath.Base(localFile))
	if err != nil {
		// 加密启用但 crypt section 缺失等场景：拒绝上传并上报，绝不静默降级明文 remote
		slog.Warn("目标路径生成失败，跳过上传", "log_id", logID, "storage", storage.Name, "err", err)
		u.report(logID, storageID, client.UploadReportRequest{
			BackupLogID:  logID,
			StorageID:    storageID,
			Status:       "failed",
			ErrorMessage: err.Error(),
		})
		return
	}

	u.uploadWithTarget(logID, storageID, localFile, remoteTarget, storage)
}

// UploadToExactPath 把本地文件上传到**精确远端路径**（增量快照的 db dump 用：
// 快照目录由 rsync 直接创建，不走 remoteTargetPath 的 <site>/<batchDir>/ 布局）。
// 上传旗标与 uploadOne 完全一致（--s3-no-head-object --no-check-dest 等）。
// storageIDs 通常只有增量目标存储一个元素；逐个上传、互不影响、逐个上报。
// 注意：本方法不做加密判断（remoteTarget 由调用方直拼，无法经 crypt 中转），
// 加密存储的显式拒绝在增量入口做（见 incremental.go performIncrementalBackup）。
func (u *Uploader) UploadToExactPath(logID int64, localFile, remoteTarget string, storageIDs []int64) {
	for _, sid := range storageIDs {
		storage, ok := u.StorageByID(sid)
		if !ok {
			slog.Warn("上传目标存储不存在", "log_id", logID, "storage_id", sid)
			u.report(logID, sid, client.UploadReportRequest{
				BackupLogID:  logID,
				StorageID:    sid,
				Status:       "failed",
				ErrorMessage: "storage not found in agent config (拉配置延迟或该存储已删除)",
			})
			continue
		}
		u.uploadWithTarget(logID, sid, localFile, remoteTarget, storage)
	}
}

// uploadWithTarget 执行一次 rclone copyto 上传并上报 uploading/uploaded/failed。
// uploadOne 与 UploadToExactPath 的唯一差别是 remoteTarget 的来源
// （remoteTargetPath 的 <site>/<batchDir>/ 布局 vs 调用方直拼），
// 旗标、截断、重试语义在两类路径上必须完全一致，故抽到这里共用。
func (u *Uploader) uploadWithTarget(logID, storageID int64, localFile, remoteTarget string, storage client.Storage) {
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
	//
	// --s3-no-head-object（根因修复）：cstcloud 对"有子对象的目录键"的 HEAD 返回 200
	// （虚拟目录可探测），rclone S3 后端 NewFs 据此误判目标目录是文件 → fs.ErrorIsFile
	// → "destination is a file"，传输还没开始就失败（s3.go NewFs 的 !opt.NoHeadObject
	// 分支）。该旗标跳过目录键探测，ErrorIsFile 不再触发。
	//
	// --no-check-dest（堵虚假成功）：跳过目标对象存在性检查，NeedTransfer(nil, src)
	// 恒为真 → 每次都真实 PUT，退出码 0 即真的上传成功。没有它时，若 rclone 判定
	// 目标已存在且一致会静默跳过（退出 0 但不传输），Agent 会误报 uploaded。
	//
	// 注意：仅上传路径加这两个旗标。恢复路径（main.go performRestore 的 copyto
	// 远程→本地）依赖 ErrorIsFile 分支识别"远程路径是文件"，严禁添加。
	cmd := exec.Command("rclone",
		"copyto",
		"--config", u.configPath,
		"--transfers", "1",
		"--retries", "3",
		"--low-level-retries", "5",
		"--stats", "0",
		"--s3-no-head-object",
		"--no-check-dest",
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
// 加密存储（C1）前缀为 bc_<id>_c:（crypt 包裹节），路径其余部分一字不动。
// batchDir 是时间戳子目录（按小时归组），空串则不加时间戳层（保底兼容）
// 加密启用但 crypt section 缺失（密码不可用/obscure 失败）时返回错误，禁止降级明文。
func (u *Uploader) remoteTargetPath(s client.Storage, websiteName, batchDir, filename string) (string, error) {
	remote := remoteName(s.ID)
	encrypted := encryptionEnabled(s.Config)
	if encrypted {
		u.mu.Lock()
		hasCrypt := u.cryptSections[s.ID]
		u.mu.Unlock()
		if !hasCrypt {
			return "", fmt.Errorf("存储 %q（ID %d）已启用加密，但 rclone.conf 中缺少 %s_c 配置节（加密密码不可用或 obscure 失败），已拒绝降级为明文上传", s.Name, s.ID, remote)
		}
		remote = remote + "_c"
	}
	site := sanitizePathSegment(websiteName)

	// S3：base_path 是 bucket 内 prefix。非加密时 bucket 作为 path 第一段；
	// 加密时 bucket 已放进 crypt remote 的 base（renderCryptSection：remote=bc_<id>:bucket），
	// path 不能再带 bucket —— 否则 crypt 把 bucket 名一起加密，S3 把加密串当 bucket → object not found。
	if s.Type == "s3" {
		parts := []string{}
		if !encrypted {
			if b, ok := s.Config["bucket"].(string); ok && b != "" {
				parts = append(parts, strings.Trim(b, "/"))
			}
		}
		if base := strings.Trim(s.BasePath, "/"); base != "" {
			parts = append(parts, base)
		}
		parts = append(parts, site)
		if batchDir != "" {
			parts = append(parts, batchDir)
		}
		parts = append(parts, filename)
		return remote + ":" + strings.Join(parts, "/"), nil
	}

	// SFTP/FTP/WebDAV：base_path 前导 / 必须保留 —— 相对路径会被 rclone 拼登录目录导致"双重路径"
	base := strings.TrimRight(s.BasePath, "/")
	prefix := ""
	if base != "" {
		prefix = base + "/"
	}
	if batchDir != "" {
		return remote + ":" + prefix + site + "/" + batchDir + "/" + filename, nil
	}
	return remote + ":" + prefix + site + "/" + filename, nil
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
