package sysinfo

import (
	"os"
	"runtime"
	"strconv"
	"strings"
	"syscall"
	"time"
)

type BasicInfo struct {
	Hostname string
	OSType   string
	Kernel   string
	Arch     string
	IP       string
}

type Metrics struct {
	CPU    float64
	Memory float64
	Disk   float64
	Uptime int64
	Disks  []DiskInfo
}

type DiskInfo struct {
	Mount     string
	FS        string
	Total     uint64
	Used      uint64
	Available uint64
}

// Basic 采集运行不变的基本信息（Linux 专用）
func Basic() *BasicInfo {
	return &BasicInfo{
		Hostname: getHostname(),
		OSType:   getOSType(),
		Kernel:   getKernel(),
		Arch:     runtime.GOARCH,
		IP:       "", // Center 从连接来源 IP 自动检测
	}
}

// Collect 采集当前系统指标
func Collect() *Metrics {
	return &Metrics{
		CPU:    getCPU(),
		Memory: getMemory(),
		Disk:   getDisk("/"),
		Uptime: getUptime(),
		Disks:  CollectDisks(),
	}
}

// 真实文件系统白名单：其他 fs (tmpfs/proc/sys/cgroup/overlay/squashfs 等) 都过滤掉
var realFileSystems = map[string]bool{
	"ext2": true, "ext3": true, "ext4": true,
	"xfs": true, "btrfs": true, "zfs": true,
	"f2fs": true, "jfs": true, "reiserfs": true,
	"ntfs": true, "ntfs3": true,
	"vfat": true, "msdos": true, "exfat": true,
	"fuseblk": true, "iso9660": true,
}

// 过滤小于此值的挂载点（boot 分区常常几百 MB，用户不关心）
const minDiskBytes uint64 = 128 * 1024 * 1024

// CollectDisks 遍历 /proc/mounts 采集所有真实分区的容量
func CollectDisks() []DiskInfo {
	data, err := os.ReadFile("/proc/mounts")
	if err != nil {
		return nil
	}
	var out []DiskInfo
	seen := make(map[string]bool)
	for _, line := range strings.Split(string(data), "\n") {
		fields := strings.Fields(line)
		if len(fields) < 3 {
			continue
		}
		device, mount, fs := fields[0], fields[1], fields[2]
		if !realFileSystems[fs] {
			continue
		}
		if strings.HasPrefix(device, "/dev/loop") {
			// snap / squashfs image 之类，跳过
			continue
		}
		mount = unescapeMount(mount)
		if seen[mount] {
			continue
		}
		var stat syscall.Statfs_t
		if err := syscall.Statfs(mount, &stat); err != nil {
			continue
		}
		total := stat.Blocks * uint64(stat.Bsize)
		avail := stat.Bavail * uint64(stat.Bsize)
		if total < minDiskBytes {
			continue
		}
		used := total - avail
		out = append(out, DiskInfo{
			Mount: mount, FS: fs,
			Total: total, Used: used, Available: avail,
		})
		seen[mount] = true
	}
	return out
}

// /proc/mounts 里空格等特殊字符用 \040 之类的八进制转义，解回来
func unescapeMount(s string) string {
	if !strings.Contains(s, `\`) {
		return s
	}
	var b strings.Builder
	for i := 0; i < len(s); i++ {
		if s[i] == '\\' && i+3 < len(s) {
			if n, err := strconv.ParseInt(s[i+1:i+4], 8, 32); err == nil {
				b.WriteByte(byte(n))
				i += 3
				continue
			}
		}
		b.WriteByte(s[i])
	}
	return b.String()
}

func getHostname() string {
	h, err := os.Hostname()
	if err != nil {
		return ""
	}
	return h
}

// getOSType 读 /etc/os-release 的 ID 字段
func getOSType() string {
	data, err := os.ReadFile("/etc/os-release")
	if err != nil {
		return runtime.GOOS
	}
	for _, line := range strings.Split(string(data), "\n") {
		if strings.HasPrefix(line, "ID=") {
			return strings.Trim(strings.TrimPrefix(line, "ID="), "\"")
		}
	}
	return runtime.GOOS
}

func getKernel() string {
	data, err := os.ReadFile("/proc/sys/kernel/osrelease")
	if err != nil {
		return ""
	}
	return strings.TrimSpace(string(data))
}

// ---------- CPU（两次采样求差） ----------

var (
	lastCPUTotal uint64
	lastCPUIdle  uint64
)

func getCPU() float64 {
	t1, i1 := readCPU()
	if t1 == 0 {
		return 0
	}
	if lastCPUTotal == 0 {
		// 首次采样，等 100ms 再取一次算差值
		time.Sleep(100 * time.Millisecond)
		t2, i2 := readCPU()
		lastCPUTotal, lastCPUIdle = t2, i2
		return calcCPU(t1, i1, t2, i2)
	}
	usage := calcCPU(lastCPUTotal, lastCPUIdle, t1, i1)
	lastCPUTotal, lastCPUIdle = t1, i1
	return usage
}

func calcCPU(t1, i1, t2, i2 uint64) float64 {
	dt := t2 - t1
	di := i2 - i1
	if dt == 0 {
		return 0
	}
	return round2(100.0 * (1.0 - float64(di)/float64(dt)))
}

func readCPU() (total, idle uint64) {
	data, err := os.ReadFile("/proc/stat")
	if err != nil {
		return 0, 0
	}
	for _, line := range strings.Split(string(data), "\n") {
		if strings.HasPrefix(line, "cpu ") {
			fields := strings.Fields(line)[1:]
			for i, f := range fields {
				v, _ := strconv.ParseUint(f, 10, 64)
				total += v
				if i == 3 { // idle
					idle = v
				}
			}
			return
		}
	}
	return 0, 0
}

// ---------- 内存 ----------

func getMemory() float64 {
	data, err := os.ReadFile("/proc/meminfo")
	if err != nil {
		return 0
	}
	var total, avail uint64
	for _, line := range strings.Split(string(data), "\n") {
		fields := strings.Fields(line)
		if len(fields) < 2 {
			continue
		}
		switch fields[0] {
		case "MemTotal:":
			total, _ = strconv.ParseUint(fields[1], 10, 64)
		case "MemAvailable:":
			avail, _ = strconv.ParseUint(fields[1], 10, 64)
		}
	}
	if total == 0 {
		return 0
	}
	return round2(100.0 * (1.0 - float64(avail)/float64(total)))
}

// ---------- 磁盘（根分区） ----------

func getDisk(path string) float64 {
	var stat syscall.Statfs_t
	if err := syscall.Statfs(path, &stat); err != nil {
		return 0
	}
	total := stat.Blocks * uint64(stat.Bsize)
	free := stat.Bavail * uint64(stat.Bsize)
	if total == 0 {
		return 0
	}
	return round2(100.0 * float64(total-free) / float64(total))
}

// ---------- 系统启动时间 ----------

func getUptime() int64 {
	data, err := os.ReadFile("/proc/uptime")
	if err != nil {
		return 0
	}
	fields := strings.Fields(string(data))
	if len(fields) < 1 {
		return 0
	}
	v, _ := strconv.ParseFloat(fields[0], 64)
	return int64(v)
}

func round2(f float64) float64 {
	return float64(int(f*100+0.5)) / 100
}
