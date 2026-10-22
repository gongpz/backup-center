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

// AgentVersion 随修复 bump：面板据版本号变化判断是否需要自更新。
// 0.14.4：performAdvancedWebRestore 解压加 --unlink-first，
//
//	修复宝塔 .user.ini 触发 GNU tar EEXIST 导致恢复「假失败」（exit 2）。
//
// 0.14.5：自主备份上报 task batch_code（self_backup 指令 ID），
//
//	Center 据此对高级模式备份按编码精确分组，不再依赖时间窗口。
//
// 0.15.0：增量备份（backup_mode=incremental）：rsync --link-dest 硬链快照 +
//
//	incremental_backup 指令 + 快照恢复（path_type=dir）+ retention 目录
//	purge（条项 is_dir）；数据库 dump 抽成 dumpDatabaseGz 与自主备份共用。
//
// 0.15.2：保留期清理（cleanup_retention）的 rclone 调用加单条超时（cleanupRcloneTimeout=10m），
//
//	后端卡死不再永久挂起清理指令与 Agent 命令循环；超时该条按失败保留 db 行下轮重派。
const AgentVersion = "0.15.2"

// runtimeConfigPath 记录当前进程的配置文件路径，供心跳里更新 mirror 时回写
var runtimeConfigPath string

const rcloneConfPath = "/etc/backup-agent/rclone.conf"

// restoreMu 保证同一时刻 Agent 上只有一条 restore 命令真正在执行。
// 背景：restore 内部是 rclone copyto 大文件下载，advanced 模式下载成功后还有
// 预备份 + tar 解压覆盖网站根目录 / 数据库 DROP+CREATE+导入，全程重占带宽与磁盘 IO。
// 两条 restore 并发会同时跑多个 rclone，互相抢占带宽和磁盘，可能打满整机 IO、
// 拖慢线上网站服务——这是前端防重（同时只允许提交一条恢复）之外的后端兜底。
//
// 排队策略：Center 每次心跳只派发一条命令，新 restore 发现锁被占用时在
// performRestore 入口排队等待前一条彻底结束（含 advanced 的 tar/导库）再执行，
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
				// 心跳节奏不变：仍是每 websiteRefreshInterval 秒刷一次；失败只记日志
				_ = refreshWebsites(c, scn, up)
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

// refreshWebsites 从 Center 拉取最新的网站 + 存储清单并写入 scanner / uploader。
// 心跳之外的「敏感命令」（自主压缩、高级恢复）也会在执行前调用它，避免用陈旧配置。
// 并发安全：c.WebsitesFull 走共享 http.Client（本身并发安全），
// up.UpdateStorages 与 scn.SetWebsites 各自持有互斥锁。
func refreshWebsites(c *client.Client, scn *scanner.Scanner, up *uploader.Uploader) error {
	data, err := c.WebsitesFull()
	if err != nil {
		slog.Warn("刷新网站列表失败", "err", err)
		return err
	}
	if err := up.UpdateStorages(data.Storages); err != nil {
		slog.Warn("更新 rclone 配置失败", "err", err)
	}
	scn.SetWebsites(data.Websites)
	slog.Debug("刷新配置", "websites", len(data.Websites), "storages", len(data.Storages))
	return nil
}

// resolveWebsiteForCommand 执行敏感命令前强制刷新一次网站清单，再按 ID 取配置。
// 背景：网站清单原本只在心跳（每 300s）刷新，用户刚在 Center 配好 web_root / db_*
// 就下发「自主压缩」或「高级恢复」时，Agent 内存里还是旧值（web_root 可能为空），
// 导致本该成功的操作报「未配置网站根目录与数据库」或恢复到错误目录。
// 刷新失败时按当前内存配置继续（保留旧行为）；刷新成功但仍查不到，说明该网站
// 确实不在 Center 的清单里，给出与「配置缺失」可区分的提示。
func resolveWebsiteForCommand(c *client.Client, scn *scanner.Scanner, up *uploader.Uploader, siteID int64, action string) (client.Website, error) {
	if err := refreshWebsites(c, scn, up); err != nil {
		slog.Warn(action+"前刷新网站列表失败，将按当前内存中的配置继续", "err", err, "website_id", siteID)
	}
	w, ok := scn.WebsiteByID(siteID)
	if !ok {
		return client.Website{}, fmt.Errorf("网站列表刷新后仍未找到该网站（ID %d），请确认该网站未被删除、且 Agent 已成功连接 Center", siteID)
	}
	return w, nil
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
		st := scn.ForceScanAll()
		took := time.Since(start)
		output := fmt.Sprintf("已完成一次全量扫描，用时 %.2fs：扫描 %d 个网站", took.Seconds(), st.Websites)
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
		// advanced 模式（params.restore_mode=advanced）在下载成功后自动预备份 + 解压覆盖网站根目录 / 重建导入数据库
		output, err := performRestore(cmd, c, scn, up)
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

	case "incremental_backup":
		// 增量备份（v0.15）：rsync --link-dest 硬链快照推网站根目录到 SFTP 目标
		// 存储，数据库全量 dump 随快照存档（单目录自包含，见 incremental.go）
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
// Agent 命令循环——db 行永远删不掉、反复重派又反复卡死。超时后杀掉 rclone，
// 该条按失败处理（db 行保留待下轮重派），保证清理指令不会无限挂起。
const cleanupRcloneTimeout = 10 * time.Minute

// performRetentionCleanup 删除超期备份的云端文件（C4）。
// params.files 为数组，元素 {log_id: number, remote_path: string, is_dir?: bool}；
// remote_path 原样取自库里存的值（形如 bc_14:bucket/... 或加密后的 bc_14_c:...），
// 直接用本地 rclone.conf 驱动，全程不接触任何存储凭据——这也是本指令存在的意义：
// Center 不再需要持存储密码跑 rclone。
// is_dir=true（v0.15 增量快照目录）时用 rclone purge 删整个目录；缺省/文件走
// deletefile。purge 对"目录不存在"报 directory not found（含 "not found"），与
// deletefile 的 not found 走同一幂等分支，所以同快照的 db 文件日志与快照目录
// 日志无论先删哪个都收敛（先 purge 目录 → db 的 deletefile 记 missing）。
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
		// 每条调用独立上下文 + 超时：超时即杀 rclone，该条按失败处理（db 行保留重派）。
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
				// 后端卡死：无法确定是否删完，按失败保留 db 行，下轮重派。
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
		return summary, fmt.Errorf("保留期清理未完成：%d 个云端文件删除失败（已删除 %d，已不存在 %d），数据库行保留待下轮重派",
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
// remote_path 形如 bc_14:bucket/backups/站点/20260809/file.tar.gz（已含 rclone remote 前缀），
// 因此无需再解析 storage_id，直接复用 rclone.conf 里的 bc_<id> remote 即可。
// lite 模式：仅下载归档，由管理员手动处理（解压 tar / 导入 .sql）；
// advanced 模式：下载成功后按文件名分类，自动预备份 + 解压覆盖 / DROP+CREATE+导库
// （见 performAdvancedWebRestore / performAdvancedDBRestore，任一步失败均附带预备份路径便于回滚）。
// 长任务期间每 5s 通过 c.CommandProgress 上报 rclone 最新进度行（status 保持 dispatched）。
func performRestore(cmd *client.PendingCommand, c *client.Client, scn *scanner.Scanner, up *uploader.Uploader) (string, error) {
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

	// restore 串行执行：等前一条恢复完整结束（含 advanced 的预备份/tar/导库）再继续，
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

	// v0.15 快照恢复分支（path_type=dir：后端只在快照日志的恢复指令里带此参数）：
	// remote_path 指向远端**目录**（rsync 快照根），不能走下面面向单文件的 copyto
	// 下载——交给 performSnapshotRestore 用 rclone copy 整目录拉回后再按
	// advanced/lite 分流。website_id 仅 advanced 模式会下发（lite 拿不到也不需要），
	// 解析失败按指令损坏处理。
	if fmt.Sprint(p["path_type"]) == "dir" {
		var w *client.Website
		if raw, ok := p["website_id"]; ok && raw != nil {
			siteID, perr := parseInt64Param(raw)
			if perr != nil {
				return "", fmt.Errorf("快照恢复 website_id 解析失败: %w", perr)
			}
			rw, rerr := resolveWebsiteForCommand(c, scn, up, siteID, "快照恢复")
			if rerr != nil {
				return "", rerr
			}
			w = &rw
		}
		return performSnapshotRestore(cmd, c, w, dest)
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

	// ---------- advanced 模式：下载成功后自动预备份 + 覆盖 + 重建导入 ----------
	if fmt.Sprint(cmd.Params["restore_mode"]) == "advanced" {
		if strings.Contains(filename, "-web-") && strings.HasSuffix(filename, ".tar.gz") {
			siteID, perr := parseInt64Param(cmd.Params["website_id"])
			if perr != nil {
				return "", fmt.Errorf("advanced 恢复 website_id 解析失败: %w", perr)
			}
			w, err := resolveWebsiteForCommand(c, scn, up, siteID, "advanced 恢复")
			if err != nil {
				return "", err
			}
			bak, err := performAdvancedWebRestore(cmd, c, w, dest)
			if err != nil {
				return "", err
			}
			return fmt.Sprintf("高级恢复完成：网站目录已覆盖（预备份 %s）", bak), nil
		}
		if strings.Contains(filename, "-db-") && strings.HasSuffix(filename, ".sql.gz") {
			siteID, perr := parseInt64Param(cmd.Params["website_id"])
			if perr != nil {
				return "", fmt.Errorf("advanced 恢复 website_id 解析失败: %w", perr)
			}
			w, err := resolveWebsiteForCommand(c, scn, up, siteID, "advanced 恢复")
			if err != nil {
				return "", err
			}
			bak, err := performAdvancedDBRestore(cmd, c, w, dest)
			if err != nil {
				return "", err
			}
			return fmt.Sprintf("高级恢复完成：数据库已重建导入（预备份 %s）", bak), nil
		}
		// 非 -web-/-db- 文件名：退回 lite 行为（仅下载留档）
		_ = c.CommandProgress(&client.CommandProgressRequest{
			CommandID: cmd.ID,
			Output:    "[提示] advanced 模式未识别文件类型，按 lite 处理（仅下载留档）",
		})
	}

	return fmt.Sprintf("已恢复到本地: %s", dest), nil
}

// performAdvancedWebRestore 对 advanced 模式的网站归档执行"预备份 + 覆盖解压"。
// 返回预备份路径，供调用方在成功输出 / 失败回滚提示中使用。
func performAdvancedWebRestore(cmd *client.PendingCommand, c *client.Client, w client.Website, dest string) (string, error) {
	if w.WebRoot == "" {
		return "", fmt.Errorf("网站未配置 web_root，无法执行目录恢复")
	}
	parent := filepath.Dir(w.WebRoot)
	base := filepath.Base(w.WebRoot)
	ts := time.Now().Format("20060102-150405")
	bakPath := w.WebRoot + ".bak-" + ts + ".tar.gz"

	_ = c.CommandProgress(&client.CommandProgressRequest{
		CommandID: cmd.ID,
		Output:    "[预备份] 网站目录 → " + bakPath,
	})
	bc := exec.Command("tar", "-czf", bakPath, "-C", parent, base)
	var berr strings.Builder
	bc.Stderr = &berr
	if err := bc.Run(); err != nil {
		return "", fmt.Errorf("网站目录预备份失败: %v (%s)", err, strings.TrimSpace(berr.String()))
	}

	_ = c.CommandProgress(&client.CommandProgressRequest{
		CommandID: cmd.ID,
		Output:    "[解压] 正在覆盖网站根目录…",
	})
	// --unlink-first（-U）：解压每个文件前先删除已存在的同名路径。
	// 宝塔等面板会在 web_root 留 .user.ini（软链或占位文件），tar 默认解压遇到已存在的
	// 符号链接会报 "Cannot open: File exists" 并 exit 2——其他文件其实已逐个解压成功，
	// 但非零退出码让整个恢复被误判为失败。-U 先 unlink 再创建，彻底消除 EEXIST。
	xc := exec.Command("tar", "--unlink-first", "-xzf", dest, "-C", parent)
	var xerr strings.Builder
	xc.Stderr = &xerr
	if err := xc.Run(); err != nil {
		return bakPath, fmt.Errorf("网站目录解压覆盖失败: %v (%s)；可手动回滚: tar -xzf %s -C %s", err, strings.TrimSpace(xerr.String()), bakPath, parent)
	}
	return bakPath, nil
}

// performAdvancedDBRestore 对 advanced 模式的数据库归档执行"预备份(dump) + 解压 + DROP/CREATE + 导入"。
// 返回预备份路径（.sql 文件），任一步失败均携带该路径以便管理员手动回滚。
func performAdvancedDBRestore(cmd *client.PendingCommand, c *client.Client, w client.Website, dest string) (string, error) {
	if w.DBType == "" || w.DBName == "" {
		return "", fmt.Errorf("网站未配置数据库（db_type/db_name），无法执行数据库恢复")
	}
	ts := time.Now().Format("20060102-150405")
	bakPath := w.DBName + ".bak-" + ts + ".sql"

	tmpDir, err := os.MkdirTemp("", "bc-restore-db-")
	if err != nil {
		return "", fmt.Errorf("创建临时目录失败: %w", err)
	}
	defer os.RemoveAll(tmpDir)
	tmpSQL := filepath.Join(tmpDir, "restore.sql")

	// 1) 预备份：导出当前线上数据库
	_ = c.CommandProgress(&client.CommandProgressRequest{
		CommandID: cmd.ID,
		Output:    "[预备份] 数据库 → " + bakPath,
	})
	if w.DBType == "mysql" {
		cnfPath := filepath.Join(tmpDir, fmt.Sprintf("my-%d.cnf", os.Getpid()))
		cnf := fmt.Sprintf("[client]\nuser=%s\npassword=%s\nhost=%s\nport=%d\n", w.DBUser, w.DBPassword, w.DBHost, dbPortOrDefault(w.DBPort, "mysql"))
		if werr := os.WriteFile(cnfPath, []byte(cnf), 0600); werr != nil {
			return "", fmt.Errorf("写入数据库配置失败: %w", werr)
		}
		dump := exec.Command("mysqldump", "--defaults-extra-file="+cnfPath, w.DBName)
		if path, lerr := exec.LookPath("mariadb-dump"); lerr == nil {
			dump = exec.Command(path, "--defaults-extra-file="+cnfPath, w.DBName)
		}
		outF, oerr := os.Create(bakPath)
		if oerr != nil {
			return "", fmt.Errorf("创建数据库预备份文件失败: %w", oerr)
		}
		dump.Stdout = outF
		var berr strings.Builder
		dump.Stderr = &berr
		if rerr := dump.Run(); rerr != nil {
			outF.Close()
			return "", fmt.Errorf("数据库预备份(mysqldump)失败: %v (%s)", rerr, strings.TrimSpace(berr.String()))
		}
		outF.Close()
	} else if w.DBType == "postgresql" {
		dump := exec.Command("pg_dump", "-h", w.DBHost, "-p", strconv.Itoa(dbPortOrDefault(w.DBPort, "postgresql")), "-U", w.DBUser, "-d", w.DBName, "-f", bakPath)
		dump.Env = append(os.Environ(), "PGPASSWORD="+w.DBPassword)
		var berr strings.Builder
		dump.Stderr = &berr
		if rerr := dump.Run(); rerr != nil {
			return "", fmt.Errorf("数据库预备份(pg_dump)失败: %v (%s)", rerr, strings.TrimSpace(berr.String()))
		}
	} else {
		return "", fmt.Errorf("不支持的数据库类型: %s", w.DBType)
	}

	// 2) 解压 .sql.gz → tmpSQL
	_ = c.CommandProgress(&client.CommandProgressRequest{
		CommandID: cmd.ID,
		Output:    "[导入] 解压数据库归档…",
	})
	gz := exec.Command("gzip", "-dc", dest)
	outF, oerr := os.Create(tmpSQL)
	if oerr != nil {
		return bakPath, fmt.Errorf("创建解压目标文件失败: %w；预备份: %s", oerr, bakPath)
	}
	gz.Stdout = outF
	var gerr strings.Builder
	gz.Stderr = &gerr
	if rerr := gz.Run(); rerr != nil {
		outF.Close()
		return bakPath, fmt.Errorf("解压数据库归档失败: %v (%s)；可手动回滚（预备份: %s）", rerr, strings.TrimSpace(gerr.String()), bakPath)
	}
	outF.Close()

	// 3) DROP + CREATE + 导入
	if w.DBType == "mysql" {
		cnfPath := filepath.Join(tmpDir, fmt.Sprintf("my-%d.cnf", os.Getpid()))
		cnf := fmt.Sprintf("[client]\nuser=%s\npassword=%s\nhost=%s\nport=%d\n", w.DBUser, w.DBPassword, w.DBHost, dbPortOrDefault(w.DBPort, "mysql"))
		if werr := os.WriteFile(cnfPath, []byte(cnf), 0600); werr != nil {
			return bakPath, fmt.Errorf("写入数据库配置失败: %w；预备份: %s", werr, bakPath)
		}
		drop := exec.Command("mysql", "--defaults-extra-file="+cnfPath, "-e",
			fmt.Sprintf("DROP DATABASE IF EXISTS `%s`; CREATE DATABASE `%s`;", w.DBName, w.DBName))
		var derr strings.Builder
		drop.Stderr = &derr
		if rerr := drop.Run(); rerr != nil {
			return bakPath, fmt.Errorf("重建数据库失败: %v (%s)；可手动回滚（预备份: %s）", rerr, strings.TrimSpace(derr.String()), bakPath)
		}
		inF, ierr := os.Open(tmpSQL)
		if ierr != nil {
			return bakPath, fmt.Errorf("打开解压 SQL 失败: %w；预备份: %s", ierr, bakPath)
		}
		defer inF.Close()
		imp := exec.Command("mysql", "--defaults-extra-file="+cnfPath, w.DBName)
		imp.Stdin = inF
		var ierrb strings.Builder
		imp.Stderr = &ierrb
		_ = c.CommandProgress(&client.CommandProgressRequest{
			CommandID: cmd.ID,
			Output:    "[导入] 重建并导入数据库…",
		})
		if rerr := imp.Run(); rerr != nil {
			return bakPath, fmt.Errorf("导入数据库失败: %v (%s)；可手动回滚（预备份: %s）", rerr, strings.TrimSpace(ierrb.String()), bakPath)
		}
	} else if w.DBType == "postgresql" {
		portStr := strconv.Itoa(dbPortOrDefault(w.DBPort, "postgresql"))
		env := append(os.Environ(), "PGPASSWORD="+w.DBPassword)
		drop := exec.Command("psql", "-h", w.DBHost, "-p", portStr, "-U", w.DBUser, "-c",
			fmt.Sprintf("DROP DATABASE IF EXISTS \"%s\";", w.DBName))
		drop.Env = env
		var derr strings.Builder
		drop.Stderr = &derr
		if rerr := drop.Run(); rerr != nil {
			return bakPath, fmt.Errorf("删除数据库失败: %v (%s)；可手动回滚（预备份: %s）", rerr, strings.TrimSpace(derr.String()), bakPath)
		}
		create := exec.Command("psql", "-h", w.DBHost, "-p", portStr, "-U", w.DBUser, "-c",
			fmt.Sprintf("CREATE DATABASE \"%s\";", w.DBName))
		create.Env = env
		var cerr strings.Builder
		create.Stderr = &cerr
		if rerr := create.Run(); rerr != nil {
			return bakPath, fmt.Errorf("创建数据库失败: %v (%s)；可手动回滚（预备份: %s）", rerr, strings.TrimSpace(cerr.String()), bakPath)
		}
		imp := exec.Command("psql", "-h", w.DBHost, "-p", portStr, "-U", w.DBUser, "-d", w.DBName, "-f", tmpSQL)
		imp.Env = env
		var ierrb strings.Builder
		imp.Stderr = &ierrb
		_ = c.CommandProgress(&client.CommandProgressRequest{
			CommandID: cmd.ID,
			Output:    "[导入] 重建并导入数据库…",
		})
		if rerr := imp.Run(); rerr != nil {
			return bakPath, fmt.Errorf("导入数据库失败: %v (%s)；可手动回滚（预备份: %s）", rerr, strings.TrimSpace(ierrb.String()), bakPath)
		}
	}
	return bakPath, nil
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
	w, err := resolveWebsiteForCommand(c, scn, up, websiteID, "自主备份")
	if err != nil {
		return "", err
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
		if err := reportAndUpload(c, up, cmd.ID, websiteID, w, arcName, arcPath, webSize, backupAt, w.StorageIDs); err != nil {
			slog.Warn("上报/上传网站目录失败", "err", err)
		} else {
			_ = c.CommandProgress(&client.CommandProgressRequest{CommandID: cmd.ID, Output: "网站目录已上传: " + arcName})
		}
	}

	// 2) 数据库导出（dump 逻辑与增量备份共用，见 dumpDatabaseGz：增量快照内的 db
	//    文件与 self 备份同名同构，恢复侧 performAdvancedDBRestore 不区分来源）
	if w.DBType != "" {
		gzPath, size, derr := dumpDatabaseGz(w, tmpDir, safe, stamp)
		if derr != nil {
			return "", derr
		}
		dbSize = size
		products = append(products, "数据库 "+humanizeBytes(dbSize))
		if err := reportAndUpload(c, up, cmd.ID, websiteID, w, filepath.Base(gzPath), gzPath, dbSize, backupAt, w.StorageIDs); err != nil {
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

// dumpDatabaseGz 导出数据库并 gzip 压缩，产出 <safe>-db-<stamp>.sql.gz。
// 自主备份（performSelfBackup）与增量备份（performIncrementalBackup）共用的 db dump
// 逻辑：mysqldump/mariadb-dump/pg_dump + gzip -f 逐行保持原 self_backup 实现。
// 增量快照里的 db 文件与 self 备份同名同构，恢复侧 performAdvancedDBRestore 无需
// 区分来源；w.DBType 为空（纯静态站）时返回空串、不生成任何文件。
// 返回 (gzPath, dbSize, error)。
func dumpDatabaseGz(w client.Website, tmpDir, safe, stamp string) (string, int64, error) {
	if w.DBType == "" {
		return "", 0, nil
	}
	sqlName := fmt.Sprintf("%s-db-%s.sql", safe, stamp)
	sqlPath := filepath.Join(tmpDir, sqlName)
	if w.DBType == "mysql" {
		cnfPath := filepath.Join(tmpDir, fmt.Sprintf("my-%d.cnf", os.Getpid()))
		cnf := fmt.Sprintf("[client]\nuser=%s\npassword=%s\nhost=%s\nport=%d\n",
			w.DBUser, w.DBPassword, w.DBHost, dbPortOrDefault(w.DBPort, "mysql"))
		if werr := os.WriteFile(cnfPath, []byte(cnf), 0600); werr != nil {
			return "", 0, fmt.Errorf("写入 mysqldump 配置失败: %w", werr)
		}
		dump := exec.Command("mysqldump", "--defaults-extra-file="+cnfPath, "--single-transaction", "--routines", "--events", w.DBName)
		if path, lerr := exec.LookPath("mariadb-dump"); lerr == nil {
			dump = exec.Command(path, "--defaults-extra-file="+cnfPath, "--single-transaction", w.DBName)
		}
		outF, oerr := os.Create(sqlPath)
		if oerr != nil {
			return "", 0, fmt.Errorf("创建数据库导出文件失败: %w", oerr)
		}
		dump.Stdout = outF
		stderr := &strings.Builder{}
		dump.Stderr = stderr
		if rerr := dump.Run(); rerr != nil {
			outF.Close()
			return "", 0, fmt.Errorf("mysqldump 失败: %v\n%s", rerr, stderr.String())
		}
		outF.Close()
	} else if w.DBType == "postgresql" {
		dump := exec.Command("pg_dump", "-h", w.DBHost, "-p", strconv.Itoa(dbPortOrDefault(w.DBPort, "postgresql")), "-U", w.DBUser, "-d", w.DBName, "-f", sqlPath)
		dump.Env = append(os.Environ(), "PGPASSWORD="+w.DBPassword)
		stderr := &strings.Builder{}
		dump.Stderr = stderr
		if rerr := dump.Run(); rerr != nil {
			return "", 0, fmt.Errorf("pg_dump 失败: %v\n%s", rerr, stderr.String())
		}
	}
	gzPath := sqlPath + ".gz"
	if gerr := exec.Command("gzip", "-f", sqlPath).Run(); gerr != nil {
		return "", 0, fmt.Errorf("压缩数据库导出失败: %w", gerr)
	}
	var dbSize int64
	if fi, ferr := os.Stat(gzPath); ferr == nil {
		dbSize = fi.Size()
	}
	return gzPath, dbSize, nil
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

// reportAndUpload 上报一条备份记录并（同步）上传到指定目标存储。
// storageIDs 由调用方给：自主备份传 w.StorageIDs（网站配置的全部存储）；
// 增量备份只推 SFTP 目标存储（不走 website.storage_ids，避免全量产物重复推
// VPS）——但增量的产物落在快照目录而非标准布局，实际上改用 incremental.go 的
// reportIncrementalLog + UploadToExactPath 直报，不走本函数。
func reportAndUpload(c *client.Client, up *uploader.Uploader, cmdID, websiteID int64, w client.Website, filename, fullPath string, size int64, backupAt time.Time, storageIDs []int64) error {
	resp, err := c.Report(&client.ReportRequest{
		WebsiteID: websiteID,
		Filename:  filename,
		FullPath:  fullPath,
		Size:      size,
		BackupAt:  backupAt.Format(time.RFC3339),
		Status:    "success",
		// 任务编码 = self_backup 指令 ID：同一次指令产出的 web+db 带同一编码，
		// Center 按编码精确分组（一次指令 = 一场备份，零时间窗口猜测）。
		// 本函数只被 performSelfBackup 调用（web/db 各一次，cmdID 相同）。
		BatchCode: strconv.FormatInt(cmdID, 10),
	})
	if err != nil {
		return fmt.Errorf("上报备份记录失败: %w", err)
	}
	up.ProcessBackup(resp.LogID, fullPath, w.Name, backupAt, storageIDs)
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
