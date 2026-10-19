package main

// 增量备份（v0.15）：rsync --link-dest 硬链快照 + incremental_backup 指令 + 快照恢复。
// 契约唯一事实源：.workbuddy/incremental-backup-spec.md §3.3/§3.4。

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

	"github.com/gongpz/backup-center/agent/internal/client"
	"github.com/gongpz/backup-center/agent/internal/scanner"
	"github.com/gongpz/backup-center/agent/internal/uploader"
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
// 只认严格形态，防止把站点目录下误建的杂目录误当成 link-dest 目标。
var snapshotStampRe = regexp.MustCompile(`^\d{8}-\d{6}$`)

// performIncrementalBackup 增量备份（v0.15）：rsync --link-dest 硬链快照。
// 路线选择（与用户拍板，见规格 §0）：
//   - 目标只能是 SFTP 存储（rsync 经 SSH 推 VPS）；对象存储无增量语义，不做；
//   - 快照目录 = 整站镜像 + 全量 db dump：数据库无增量，每次全量进同一快照目录，
//     保证单目录自包含、可整体恢复；
//   - --link-dest 指向上一个快照：未变化文件以硬链落地、只传输变化部分；
//     删除旧快照不破坏新快照（retention 走 rclone purge，见 performRetentionCleanup）。
func performIncrementalBackup(cmd *client.PendingCommand, c *client.Client, up *uploader.Uploader, scn *scanner.Scanner) (string, error) {
	p := cmd.Params
	if p == nil {
		return "", fmt.Errorf("增量备份指令缺少参数")
	}
	websiteID, err := parseInt64Param(p["website_id"])
	if err != nil {
		return "", fmt.Errorf("website_id 解析失败: %w", err)
	}
	w, err := resolveWebsiteForCommand(c, scn, up, websiteID, "增量备份")
	if err != nil {
		return "", err
	}
	if w.BackupMode != "incremental" {
		return "", fmt.Errorf("该网站未启用增量备份（backup_mode=incremental）")
	}
	if w.WebRoot == "" {
		return "", fmt.Errorf("未绑定网站根目录，无法增量备份（db-only 站不支持快照）")
	}
	if w.IncrementalStorageID <= 0 {
		return "", fmt.Errorf("未配置增量备份目标存储")
	}
	storage, ok := up.StorageByID(w.IncrementalStorageID)
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
	// 远端路径也无法经 crypt 层中转。UploadToExactPath 内部同样不做加密判断，
	// 所以这一步的显式校验是必须的。
	if storageEncryptionEnabled(storage.Config) {
		return "", fmt.Errorf("增量目标存储开启了加密，拒绝执行（rsync 明文写入与加密层不兼容，请在 Center 关闭该存储加密或改用普通全量备份）")
	}
	keyPath := filepath.Join(sshKeysDir, fmt.Sprintf("bc_%d.key", w.IncrementalStorageID))
	if _, err := os.Stat(keyPath); err != nil {
		return "", fmt.Errorf("SSH 私钥文件缺失 %s（存储刚配置时等待一次网站列表刷新，约 5 分钟内自动生成）: %w", keyPath, err)
	}

	// ---------- 远端目录布局 ----------
	stamp := time.Now().Format("20060102-150405")
	safe := safeName(w.Name)
	// 快照根与 rclone 全量上传的站点目录同名（uploader.remoteTargetPath 用同一个
	// sanitizePathSegment 生成 <site> 段）：同一网站在同一存储上的全量与增量产物
	// 共处一个站点根目录，retention 清理 / 人工排查时口径统一。
	site := sanitizePathSegment(w.Name)
	remoteSiteDir := trimTrailingSlash(storage.BasePath) + "/" + site // base_path 为空时就是 site
	remoteSnapDir := remoteSiteDir + "/" + stamp
	remote := fmt.Sprintf("bc_%d:", w.IncrementalStorageID)

	// 上一个快照：站点目录下字典序最大的 YYYYMMDD-HHMMSS。
	// lsf 失败（首跑目录不存在 / 网络抖动）退化为全量（prev=""），安全优先——
	// link-dest 指向错误位置会让 rsync 静默重传甚至数据错乱，宁可多传。
	prev := findLatestSnapshot(w.IncrementalStorageID, remoteSiteDir)

	// ---------- rsync 快照同步（exec.Command 直传参数，无 shell） ----------
	// 旗标语义：
	//   -a               归档模式（递归+权限+时间等）；与 --no-o/--no-g 配合见下
	//   --delete         保持快照是 web_root 的忠实镜像（删掉远端已不存在的文件）
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
		// 相对 link-dest 相对 destination 解析：../<prev>/ 即同一站点根下的上一个
		// 快照，天然指向兄弟目录，无需知道远端绝对根路径。
		args = append(args, "--link-dest=../"+prev+"/")
	}
	// source 带尾斜杠 = 同步 web_root 内容本身；destination 带尾斜杠 = 落入快照目录
	args = append(args,
		w.WebRoot+"/",
		sshUser+"@"+host+":"+remoteSnapDir+"/",
	)
	rcmd := exec.Command("rsync", args...)

	slog.Info("增量备份：rsync 快照同步开始", "id", cmd.ID, "src", w.WebRoot, "dst", remoteSnapDir, "link_dest", prev)
	_ = c.CommandProgress(&client.CommandProgressRequest{
		CommandID: cmd.ID,
		Output:    fmt.Sprintf("[快照] rsync 同步 %s → %s（%s）…", w.WebRoot, remoteSnapDir, linkDestDesc(prev)),
	})
	// progress2 在 stdout 上以 \r 刷新，错误/告警在 stderr：两路都喂进度抓取器，
	// 另汇入同一带锁缓冲（stdout/stderr 由 exec 各起 goroutine 并发拷贝，直接共用
	// strings.Builder 会数据竞争）。
	buf := &syncBuf{}
	tracker := &progressTracker{}
	w2 := io.MultiWriter(buf, tracker)
	rcmd.Stdout = w2
	rcmd.Stderr = w2
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
		return "", fmt.Errorf("rsync 快照同步失败: %s", msg)
	}
	// 收尾 progress2 行形如 "  1,234,567 100%  6.05MB/s    0:00:02"，首字段即本次传输字节
	transferred := parseRsyncTransferred(tracker.last())

	// 快照体积 best-effort：rclone size 失败只记 0（仅用于记录展示，不影响成败）
	snapSize := remoteDirSize(remote + remoteSnapDir)

	// ---------- 记录上报 ----------
	backupAt := time.Now()
	batchCode := strconv.FormatInt(cmd.ID, 10) // 与 self_backup 同规则：指令 ID 即任务编码
	snapName := fmt.Sprintf("%s-snap-%s", safe, stamp)

	// 1) 快照日志：full_path 是**不带 bc_ 前缀的远端目录**（恢复页/retention 按它
	//    定位快照根）。注意这里不是本地路径——快照内容从不在 Agent 落盘。
	logIDSnap, err := reportIncrementalLog(c, websiteID, snapName, remoteSnapDir, snapSize, backupAt, batchCode)
	if err != nil {
		return "", err
	}
	// rsync 经 SSH 推送，没有 rclone copyto 自然产出 upload 记录——补一条 uploaded：
	// remote_path 带 bc_<sid>: 前缀，恢复页凭它判定可恢复、取远端目录并按 dir 恢复。
	if _, err := c.UploadReport(&client.UploadReportRequest{
		BackupLogID:   logIDSnap,
		StorageID:     w.IncrementalStorageID,
		Status:        "uploaded",
		RemotePath:    remote + remoteSnapDir,
		UploadedBytes: snapSize,
	}); err != nil {
		// 非致命：备份本体已成功，最坏是恢复页暂不可见，后续备份会带新记录
		slog.Warn("增量备份：快照 upload 记录上报失败", "err", err, "log_id", logIDSnap)
	}

	// 2) db dump：全量导出并压进快照目录（与自主备份共用 dumpDatabaseGz，产物同构，
	//    恢复侧 performAdvancedDBRestore 无需区分来源）。
	dbName, dbSize := "", int64(0)
	if w.DBType != "" {
		tmpDir, terr := os.MkdirTemp("", "bc-inc-"+strconv.FormatInt(websiteID, 10)+"-")
		if terr != nil {
			return "", fmt.Errorf("创建临时目录失败: %w", terr)
		}
		defer os.RemoveAll(tmpDir)

		gzPath, size, derr := dumpDatabaseGz(w, tmpDir, safe, stamp)
		if derr != nil {
			return "", derr
		}
		dbName = filepath.Base(gzPath) // <safe>-db-<stamp>.sql.gz
		dbSize = size

		// db 文件必须落在快照目录内：单目录自包含，恢复时整个目录拉回即含数据库。
		// remoteTarget 由这里直拼（不走 <site>/<batchDir>/ 标准布局），交给
		// UploadToExactPath 以上传（旗标与普通上传完全一致）。
		dbRemote := remote + remoteSnapDir + "/" + dbName
		// db 日志的 full_path 是本地 tmp 路径（与 self 备份一致）
		logIDDb, rerr := reportIncrementalLog(c, websiteID, dbName, gzPath, dbSize, backupAt, batchCode)
		if rerr != nil {
			return "", rerr
		}
		slog.Info("增量备份：上传数据库导出", "id", cmd.ID, "to", dbRemote)
		up.UploadToExactPath(logIDDb, gzPath, dbRemote, []int64{w.IncrementalStorageID})
		_ = c.CommandProgress(&client.CommandProgressRequest{
			CommandID: cmd.ID,
			Output:    "[快照] 数据库已上传: " + dbName,
		})
	}

	if dbName == "" {
		return fmt.Sprintf("增量备份完成：快照 %s（传输 %s，快照体积 %s）· 该站未配置数据库，已推送至 %s",
			stamp, humanizeBytes(transferred), humanizeBytes(snapSize), storage.Name), nil
	}
	return fmt.Sprintf("增量备份完成：快照 %s（传输 %s，快照体积 %s）· 数据库 %s，已推送至 %s",
		stamp, humanizeBytes(transferred), humanizeBytes(snapSize), humanizeBytes(dbSize), storage.Name), nil
}

// reportIncrementalLog 上报一条带 batch_code 的备份记录，返回 log_id。
// 请求字段与 reportAndUpload 完全一致，但不上传：增量产物不进
// <site>/<batchDir>/ 标准布局，改由 performIncrementalBackup 按"快照目录"
// 语义自行驱动 UploadToExactPath / 手工 upload-report，所以拆开只用 c.Report。
func reportIncrementalLog(c *client.Client, websiteID int64, filename, fullPath string, size int64, backupAt time.Time, batchCode string) (int64, error) {
	resp, err := c.Report(&client.ReportRequest{
		WebsiteID: websiteID,
		Filename:  filename,
		FullPath:  fullPath,
		Size:      size,
		BackupAt:  backupAt.Format(time.RFC3339),
		Status:    "success",
		// 任务编码 = incremental_backup 指令 ID：同一次指令的快照+db 带同一编码，
		// Center 按编码精确分组（一次指令 = 一场备份，零时间窗口猜测）。
		BatchCode: batchCode,
	})
	if err != nil {
		return 0, fmt.Errorf("上报备份记录失败: %w", err)
	}
	return resp.LogID, nil
}

// findLatestSnapshot 列出远端站点目录，返回字典序最大的快照名（YYYYMMDD-HHMMSS）。
// rclone lsf 失败（目录不存在/网络问题）返回空串：调用方据此退化为全量备份。
func findLatestSnapshot(storageID int64, remoteSiteDir string) string {
	remote := fmt.Sprintf("bc_%d:%s/", storageID, remoteSiteDir)
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
// uploader.remoteTargetPath 用同一函数生成 <site> 目录名，这里复制而非改动
// uploader 导出面）。快照根必须与全量上传的站点目录同名，口径才统一。
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

// performSnapshotRestore 恢复一个增量快照（v0.15）：remote_path 指向远端**目录**
// （rsync 快照根），先把整个目录 rclone copy 回本地 dest，再按模式分流：
//   - lite（restore_mode != advanced 或拿不到 website）：只下载留档，返回落地路径；
//   - advanced：把快照目录改名成 Base(web_root) 后打包成与 self 备份同构的
//     <base>.tar.gz，**逐字复用** performAdvancedWebRestore / performAdvancedDBRestore
//     （预备备 + --unlink-first 解压覆盖 / DROP+CREATE+导库）——恢复语义与普通
//     self 备份完全一致，不另造一套链路；快照目录自包含 db dump，glob 命中即导。
//
// dest 由 performRestore 拼好（local_dir/<远端目录名>），本函数只负责消费它。
func performSnapshotRestore(cmd *client.PendingCommand, c *client.Client, w *client.Website, dest string) (string, error) {
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

	// lite 模式：website_id 只有 advanced 才会下发（见后端 RestoreController::store），
	// 拿不到网站配置时只下载留档，由管理员手动处理
	if w == nil || fmt.Sprint(p["restore_mode"]) != "advanced" {
		return fmt.Sprintf("快照已下载到 %s，请手动处理", dest), nil
	}

	// ---------- advanced：中转打包后复用既有高级链路 ----------
	// 快照目录内容就是 web_root 的内容（rsync webRoot/ → 快照根/），所以把它改名成
	// Base(web_root) 再打包，归档顶层条目恰为 <base>/，与 self 备份的
	// tar -czf arc -C parent base 完全同构——performAdvancedWebRestore 解压
	// -C parent 即可覆盖回原位（见该函数的 tar 约定注释）。
	safe := safeName(w.Name)
	var webBak, dbGzPath string

	if w.WebRoot != "" {
		base := filepath.Base(w.WebRoot)
		stageDir := filepath.Join(filepath.Dir(dest), "stage-"+filepath.Base(dest))
		if err := os.MkdirAll(stageDir, 0755); err != nil {
			return "", fmt.Errorf("创建中转目录失败 %s: %w", stageDir, err)
		}
		if err := os.Rename(dest, filepath.Join(stageDir, base)); err != nil {
			return "", fmt.Errorf("移动快照目录到中转目录失败: %w", err)
		}
		stageSnap := filepath.Join(stageDir, base)
		webTar := filepath.Join(filepath.Dir(stageDir), base+".tar.gz")
		tcmd := exec.Command("tar", "-czf", webTar, "-C", stageDir, base)
		var terr strings.Builder
		tcmd.Stderr = &terr
		if err := tcmd.Run(); err != nil {
			return "", fmt.Errorf("打包快照网站目录失败: %v (%s)", err, strings.TrimSpace(terr.String()))
		}
		// 整个中转根（下载目录 + stage + tar）在函数退出时清理：webTar 随后即被
		// 解压消费，stage 里的 db dump 也马上用完。只清 os.TempDir() 下的路径兜底
		// 防误删——恢复指令的 local_dir 由后端统一下发到 /tmp，不会落到别处。
		if strings.HasPrefix(filepath.Dir(stageDir), os.TempDir()) {
			defer os.RemoveAll(filepath.Dir(stageDir))
		}
		bak, err := performAdvancedWebRestore(cmd, c, *w, webTar)
		if err != nil {
			return "", err
		}
		webBak = bak
		if matches, _ := filepath.Glob(filepath.Join(stageSnap, safe+"-db-*.sql.gz")); len(matches) > 0 {
			dbGzPath = matches[0] // 同一次备份只可能有一个 db dump；Glob 结果有序
		}
	} else {
		_ = c.CommandProgress(&client.CommandProgressRequest{
			CommandID: cmd.ID,
			Output:    "[提示] 未绑定网站根目录，仅恢复数据库",
		})
		if matches, _ := filepath.Glob(filepath.Join(dest, safe+"-db-*.sql.gz")); len(matches) > 0 {
			dbGzPath = matches[0]
		}
	}

	var parts []string
	if w.WebRoot != "" {
		parts = append(parts, "网站目录已恢复（预备份 "+webBak+"）")
	} else {
		parts = append(parts, "网站恢复跳过（未绑定 web_root）")
	}
	if dbGzPath != "" {
		dbBak, err := performAdvancedDBRestore(cmd, c, *w, dbGzPath)
		if err != nil {
			return "", err
		}
		parts = append(parts, "数据库已重建导入（预备备 "+dbBak+"）")
	} else {
		_ = c.CommandProgress(&client.CommandProgressRequest{
			CommandID: cmd.ID,
			Output:    "[提示] 快照中无数据库导出，跳过数据库恢复",
		})
		parts = append(parts, "数据库恢复跳过（快照中无导出）")
	}
	return "快照恢复完成：" + strings.Join(parts, " · "), nil
}
