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
	"path/filepath"
	"runtime"
	"strconv"
	"strings"
	"sync"
	"syscall"
	"time"

	"github.com/gongpz/backup-center/agent/internal/client"
	"github.com/gongpz/backup-center/agent/internal/config"
	"github.com/gongpz/backup-center/agent/internal/scanner"
	"github.com/gongpz/backup-center/agent/internal/sysinfo"
	"github.com/gongpz/backup-center/agent/internal/uploader"
)

const AgentVersion = "0.14.0"

// runtimeConfigPath 记录当前进程的配置文件路径，供心跳里更新 mirror 时回写
var runtimeConfigPath string

const rcloneConfPath = "/etc/backup-agent/rclone.conf"

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
	websiteRefreshInterval := durSecs(regResp.Config.WebsiteRefreshInterval, 300)
	scanInterval := durSecs(regResp.Config.ScanInterval, 120)

	up := uploader.New(c, rcloneConfPath)
	scn := scanner.New(c, up)

	// 首次拉网站+存储列表
	if data, err := c.WebsitesFull(); err != nil {
		slog.Warn("首次拉网站列表失败", "err", err)
	} else {
		if err := up.UpdateStorages(data.Storages); err != nil {
			slog.Warn("首次生成 rclone 配置失败", "err", err)
		}
		scn.SetWebsites(data.Websites)
		slog.Info("加载配置", "websites", len(data.Websites), "storages", len(data.Storages))
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

	// 定期刷新网站列表
	wg.Add(1)
	go func() {
		defer wg.Done()
		ticker := time.NewTicker(websiteRefreshInterval)
		defer ticker.Stop()
		for {
			select {
			case <-ctx.Done():
				return
			case <-ticker.C:
				refreshWebsites(c, scn, up)
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
		"website_refresh", websiteRefreshInterval,
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

func refreshWebsites(c *client.Client, scn *scanner.Scanner, up *uploader.Uploader) {
	data, err := c.WebsitesFull()
	if err != nil {
		slog.Warn("刷新网站列表失败", "err", err)
		return
	}
	if err := up.UpdateStorages(data.Storages); err != nil {
		slog.Warn("更新 rclone 配置失败", "err", err)
	}
	scn.SetWebsites(data.Websites)
	slog.Debug("刷新配置", "websites", len(data.Websites), "storages", len(data.Storages))
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
		// 一键同步：立即扫全部网站的 backup_paths，未上传的文件走上传队列
		if scn == nil {
			_ = c.CommandResult(&client.CommandResultRequest{
				CommandID: cmd.ID,
				Status:    "failed",
				Error:     "scanner 未初始化",
			})
			return
		}
		start := time.Now()
		slog.Info("触发一键同步扫描（强制清 seen map，全量重扫，含 scheduled 网站）", "id", cmd.ID)
		scn.ClearSeen()
		scn.ForceScanAll()
		took := time.Since(start)
		_ = c.CommandResult(&client.CommandResultRequest{
			CommandID: cmd.ID,
			Status:    "success",
			Output:    fmt.Sprintf("已完成一次全量扫描，用时 %.2fs", took.Seconds()),
		})

	case "restore":
		// 远程恢复（最小可用版）：把云端归档 rclone copy 回原本地目录，
		// 不做解包 / 不自动导入数据库，由管理员手动处理（解压 tar / 导入 .sql）
		output, err := performRestore(cmd, c)
		if err != nil {
			slog.Warn("恢复失败", "err", err, "id", cmd.ID)
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

	case "bind_root":
		// 根目录绑定：校验/搜索密钥文件(.bc-bind-<token>)，回传网站根目录给 Center
		output, err := performBindRoot(cmd)
		if err != nil {
			slog.Warn("根目录绑定失败", "err", err, "id", cmd.ID)
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

	case "self_backup":
		output, err := performSelfBackup(cmd, c, up, scn)
		if err != nil {
			slog.Warn("自主备份失败", "err", err, "id", cmd.ID)
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

// performRestore 把云端归档 rclone copy 回原本地目录（最小可用版）。
// remote_path 形如 bc_14:bucket/backups/站点/20260809/file.tar.gz（已含 rclone remote 前缀），
// 因此无需再解析 storage_id，直接复用 rclone.conf 里的 bc_<id> remote 即可。
// 仅下载归档，不自动解包 / 不自动导入数据库。
// 长任务期间每 5s 通过 c.CommandProgress 上报 rclone 最新进度行（status 保持 dispatched）。
func performRestore(cmd *client.PendingCommand, c *client.Client) (string, error) {
	p := cmd.Params
	if p == nil {
		return "", fmt.Errorf("恢复指令缺少参数")
	}
	remotePath, _ := p["remote_path"].(string)
	localDir, _ := p["local_dir"].(string)
	filename, _ := p["filename"].(string)

	if remotePath == "" || localDir == "" || filename == "" {
		return "", fmt.Errorf("恢复参数不完整（remote_path/local_dir/filename 不能为空）")
	}

	if err := os.MkdirAll(localDir, 0755); err != nil {
		return "", fmt.Errorf("创建本地目录失败 %s: %w", localDir, err)
	}
	dest := filepath.Join(localDir, filename)

	slog.Info("开始远程恢复", "id", cmd.ID, "from", remotePath, "to", dest)
	rcmd := exec.Command("rclone",
		"copyto",
		"--config", rcloneConfPath,
		"--transfers", "1",
		"--retries", "3",
		"--low-level-retries", "5",
		"--stats", "5s",         // 每 5s 打一次进度
		"--stats-one-line",      // 单行进度（便于抓取）
		"--log-level", "NOTICE", // 压掉逐文件 INFO 日志，保留进度行与错误
		remotePath, dest,
	)
	// stderr 同时写入完整错误缓冲 + 进度抓取器：失败时用前者做错误信息，运行期用后者上报进度
	fullErr := &strings.Builder{}
	tracker := &progressTracker{}
	rcmd.Stderr = io.MultiWriter(fullErr, tracker)

	// 长任务进度回报：每 5s 把 rclone 最新进度行推给 Center（status 保持 dispatched）
	stop := make(chan struct{})
	var progWg sync.WaitGroup
	progWg.Add(1)
	go func() {
		defer progWg.Done()
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
					CommandID: cmd.ID,
					Output:    line,
				})
			}
		}
	}()

	runErr := rcmd.Run()
	close(stop)
	progWg.Wait()

	if runErr != nil {
		msg := strings.TrimSpace(fullErr.String())
		if msg == "" {
			msg = runErr.Error()
		}
		if len(msg) > 1800 {
			msg = msg[:1800] + "…"
		}
		return "", fmt.Errorf("rclone 恢复失败: %s", msg)
	}

	return fmt.Sprintf("已恢复到本地: %s", dest), nil
}

// performSelfBackup 自主压缩：tar 网站根目录 + 导出数据库，复用既有 report/upload 管线上传。
// 与 external 模式（扫描已存在文件）不同，这里由 Agent 主动生成备份产物。
func performSelfBackup(cmd *client.PendingCommand, c *client.Client, up *uploader.Uploader, scn *scanner.Scanner) (string, error) {
	p := cmd.Params
	if p == nil {
		return "", fmt.Errorf("自主备份指令缺少参数")
	}
	websiteID, err := parseInt64Param(p["website_id"])
	if err != nil {
		return "", fmt.Errorf("website_id 解析失败: %w", err)
	}
	w, ok := scn.WebsiteByID(websiteID)
	if !ok {
		return "", fmt.Errorf("网站 %d 不在 Agent 已加载的清单中", websiteID)
	}
	if w.WebRoot == "" && w.DBType == "" {
		return "", fmt.Errorf("未配置网站根目录与数据库，无法自主备份（请先在 Center 绑定 web_root 或填写数据库连接）")
	}

	// 工具前置检查
	if _, err := exec.LookPath("tar"); err != nil {
		return "", fmt.Errorf("服务器缺少 tar 工具，无法自主压缩")
	}
	if w.DBType != "" {
		switch w.DBType {
		case "mysql":
			if _, e := exec.LookPath("mysqldump"); e != nil {
				if _, e2 := exec.LookPath("mariadb-dump"); e2 != nil {
					return "", fmt.Errorf("数据库类型为 mysql 但服务器缺少 mysqldump/mariadb-dump 工具")
				}
			}
		case "postgresql":
			if _, e := exec.LookPath("pg_dump"); e != nil {
				return "", fmt.Errorf("数据库类型为 postgresql 但服务器缺少 pg_dump 工具")
			}
		default:
			return "", fmt.Errorf("不支持的数据库类型: %s", w.DBType)
		}
	}

	tmpDir, err := os.MkdirTemp("", "bc-self-"+strconv.FormatInt(websiteID, 10)+"-")
	if err != nil {
		return "", fmt.Errorf("创建临时目录失败: %w", err)
	}
	defer os.RemoveAll(tmpDir)

	stamp := time.Now().Format("20060102-150405")
	backupAt := time.Now()
	safe := safeName(w.Name)

	var webSize, dbSize int64
	var products []string

	// 1) 网站根目录 tar
	if w.WebRoot != "" {
		parent := filepath.Dir(w.WebRoot)
		base := filepath.Base(w.WebRoot)
		arcName := fmt.Sprintf("%s-web-%s.tar.gz", safe, stamp)
		arcPath := filepath.Join(tmpDir, arcName)
		slog.Info("自主备份：打包网站目录", "id", cmd.ID, "from", w.WebRoot, "to", arcPath)
		tcmd := exec.Command("tar", "-czf", arcPath, "-C", parent, base)
		if out, terr := tcmd.CombinedOutput(); terr != nil {
			return "", fmt.Errorf("tar 网站目录失败: %v\n%s", terr, string(out))
		}
		if fi, ferr := os.Stat(arcPath); ferr == nil {
			webSize = fi.Size()
			products = append(products, "网站目录 "+humanizeBytes(webSize))
		}
		if err := reportAndUpload(c, up, cmd.ID, websiteID, w, arcName, arcPath, webSize, backupAt); err != nil {
			slog.Warn("上报/上传网站目录失败", "err", err)
		} else {
			_ = c.CommandProgress(&client.CommandProgressRequest{CommandID: cmd.ID, Output: "网站目录已上传: " + arcName})
		}
	}

	// 2) 数据库导出
	if w.DBType != "" {
		sqlName := fmt.Sprintf("%s-db-%s.sql", safe, stamp)
		sqlPath := filepath.Join(tmpDir, sqlName)
		if w.DBType == "mysql" {
			cnfPath := filepath.Join(tmpDir, fmt.Sprintf("my-%d.cnf", os.Getpid()))
			cnf := fmt.Sprintf("[client]\nuser=%s\npassword=%s\nhost=%s\nport=%d\n",
				w.DBUser, w.DBPassword, w.DBHost, dbPortOrDefault(w.DBPort, "mysql"))
			if werr := os.WriteFile(cnfPath, []byte(cnf), 0600); werr != nil {
				return "", fmt.Errorf("写入 mysqldump 配置失败: %w", werr)
			}
			dump := exec.Command("mysqldump", "--defaults-extra-file="+cnfPath, "--single-transaction", "--routines", "--events", w.DBName)
			if path, lerr := exec.LookPath("mariadb-dump"); lerr == nil {
				dump = exec.Command(path, "--defaults-extra-file="+cnfPath, "--single-transaction", w.DBName)
			}
			outF, oerr := os.Create(sqlPath)
			if oerr != nil {
				return "", fmt.Errorf("创建数据库导出文件失败: %w", oerr)
			}
			dump.Stdout = outF
			stderr := &strings.Builder{}
			dump.Stderr = stderr
			if rerr := dump.Run(); rerr != nil {
				outF.Close()
				return "", fmt.Errorf("mysqldump 失败: %v\n%s", rerr, stderr.String())
			}
			outF.Close()
		} else if w.DBType == "postgresql" {
			dump := exec.Command("pg_dump", "-h", w.DBHost, "-p", strconv.Itoa(dbPortOrDefault(w.DBPort, "postgresql")), "-U", w.DBUser, "-d", w.DBName, "-f", sqlPath)
			dump.Env = append(os.Environ(), "PGPASSWORD="+w.DBPassword)
			stderr := &strings.Builder{}
			dump.Stderr = stderr
			if rerr := dump.Run(); rerr != nil {
				return "", fmt.Errorf("pg_dump 失败: %v\n%s", rerr, stderr.String())
			}
		}
		gzPath := sqlPath + ".gz"
		if gerr := exec.Command("gzip", "-f", sqlPath).Run(); gerr != nil {
			return "", fmt.Errorf("压缩数据库导出失败: %w", gerr)
		}
		if fi, ferr := os.Stat(gzPath); ferr == nil {
			dbSize = fi.Size()
			products = append(products, "数据库 "+humanizeBytes(dbSize))
		}
		if err := reportAndUpload(c, up, cmd.ID, websiteID, w, filepath.Base(gzPath), gzPath, dbSize, backupAt); err != nil {
			slog.Warn("上报/上传数据库失败", "err", err)
		} else {
			_ = c.CommandProgress(&client.CommandProgressRequest{CommandID: cmd.ID, Output: "数据库已上传: " + filepath.Base(gzPath)})
		}
	}

	if len(products) == 0 {
		return "", fmt.Errorf("没有任何产物生成（网站根目录与数据库均未配置）")
	}
	summary := fmt.Sprintf("自主备份完成：%s，已推送至 %d 个存储", strings.Join(products, " · "), len(w.StorageIDs))
	if len(w.StorageIDs) == 0 {
		summary += "（未配置存储目标，仅生成本地记录）"
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

func dbPortOrDefault(p int, dbType string) int {
	if p > 0 {
		return p
	}
	if dbType == "postgresql" {
		return 5432
	}
	return 3306
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
		return "site"
	}
	return s
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

// reportAndUpload 上报一条备份记录并（同步）上传到所有目标存储
func reportAndUpload(c *client.Client, up *uploader.Uploader, cmdID, websiteID int64, w client.Website, filename, fullPath string, size int64, backupAt time.Time) error {
	resp, err := c.Report(&client.ReportRequest{
		WebsiteID: websiteID,
		Filename:  filename,
		FullPath:  fullPath,
		Size:      size,
		BackupAt:  backupAt.Format(time.RFC3339),
		Status:    "success",
	})
	if err != nil {
		return fmt.Errorf("上报备份记录失败: %w", err)
	}
	up.ProcessBackup(resp.LogID, fullPath, w.Name, backupAt, w.StorageIDs)
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
