package client

import (
	"bytes"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"strings"
	"time"

	"github.com/gongpz/backup-center/agent/internal/sysinfo"
)

type Client struct {
	baseURL string
	token   string
	version string
	http    *http.Client
}

func New(baseURL, token, version string) *Client {
	return &Client{
		baseURL: strings.TrimRight(baseURL, "/"),
		token:   token,
		version: version,
		http: &http.Client{
			Timeout: 30 * time.Second,
		},
	}
}

// ---------- 数据类型（Agent 侧共用） ----------

type BackupPath struct {
	Path    string `json:"path"`
	Pattern string `json:"pattern"`
}

type Website struct {
	ID            int64        `json:"id"`
	Name          string       `json:"name"`
	BackupPaths   []BackupPath `json:"backup_paths"`
	// 保留字段（v0.3 协议兼容）：新代码不用，仅在 backup_paths 为空且服务端只下发老字段时作为 fallback
	BackupPath    string  `json:"backup_path"`
	FilePattern   string  `json:"file_pattern"`
	RetentionDays int     `json:"retention_days"`
	StorageIDs    []int64 `json:"storage_ids"`
	// 同步方式：auto=Agent 每 2 分钟自动扫描（默认）；scheduled=Agent 不主动扫，等 Center 派 sync_now
	SyncMode string `json:"sync_mode"`
}

// EffectivePaths 返回实际扫描用的路径列表：优先用 BackupPaths，为空则回退到老字段。
func (w *Website) EffectivePaths() []BackupPath {
	if len(w.BackupPaths) > 0 {
		return w.BackupPaths
	}
	if w.BackupPath != "" {
		return []BackupPath{{Path: w.BackupPath, Pattern: w.FilePattern}}
	}
	return nil
}

type Storage struct {
	ID          int64                  `json:"id"`
	Name        string                 `json:"name"`
	Type        string                 `json:"type"` // sftp | webdav | s3
	IsMandatory bool                   `json:"is_mandatory"`
	BasePath    string                 `json:"base_path"`
	Config      map[string]interface{} `json:"config"`
}

type WebsitesData struct {
	Websites []Website `json:"websites"`
	Storages []Storage `json:"storages"`
}

// ---------- 请求 / 响应 ----------

type registerRequest struct {
	AgentVersion string `json:"agent_version"`
	Hostname     string `json:"hostname,omitempty"`
	OSType       string `json:"os_type,omitempty"`
	Kernel       string `json:"kernel,omitempty"`
	Arch         string `json:"arch,omitempty"`
	IP           string `json:"ip,omitempty"`
}

type RegisterResponse struct {
	OK         bool   `json:"ok"`
	ServerID   int64  `json:"server_id"`
	ServerName string `json:"server_name"`
	Config     struct {
		HeartbeatInterval       int    `json:"heartbeat_interval"`
		WebsiteRefreshInterval  int    `json:"website_refresh_interval"`
		ScanInterval            int    `json:"scan_interval"`
		ReportEndpoint          string `json:"report_endpoint"`
	} `json:"config"`
}

type heartbeatRequest struct {
	AgentVersion string      `json:"agent_version"`
	CPUUsage     float64     `json:"cpu_usage"`
	MemUsage     float64     `json:"mem_usage"`
	DiskUsage    float64     `json:"disk_usage"`
	Uptime       int64       `json:"uptime,omitempty"`
	Disks        []diskEntry `json:"disks,omitempty"`
}

type PendingCommand struct {
	ID     int64                  `json:"id"`
	Type   string                 `json:"type"`
	Params map[string]interface{} `json:"params"`
}

type HeartbeatResponse struct {
	OK              bool            `json:"ok"`
	ServerTime      string          `json:"server_time"`
	NextHeartbeatIn int             `json:"next_heartbeat_in"`
	PendingCommand  *PendingCommand `json:"pending_command,omitempty"`
	// MirrorURL：Center 下发的 Agent 二进制 CDN 镜像 URL 前缀（可空表示"清空 mirror"）
	// 每次心跳都携带；Agent 端拿到跟本地 config 不一致时更新 config
	AgentMirrorURL string `json:"agent_mirror_url"`
}

type CommandResultRequest struct {
	CommandID int64  `json:"command_id"`
	Status    string `json:"status"` // success | failed
	Output    string `json:"output,omitempty"`
	Error     string `json:"error,omitempty"`
}

type diskEntry struct {
	Mount     string `json:"mount"`
	FS        string `json:"fs"`
	Total     uint64 `json:"total"`
	Used      uint64 `json:"used"`
	Available uint64 `json:"available"`
}

type websitesResponse struct {
	OK       bool      `json:"ok"`
	Websites []Website `json:"websites"`
	Storages []Storage `json:"storages"`
}

type UploadReportRequest struct {
	BackupLogID     int64  `json:"backup_log_id"`
	StorageID       int64  `json:"storage_id"`
	Status          string `json:"status"` // pending|uploading|uploaded|failed
	RemotePath      string `json:"remote_path,omitempty"`
	UploadedBytes   int64  `json:"uploaded_bytes,omitempty"`
	DurationSeconds int    `json:"duration_seconds,omitempty"`
	ErrorMessage    string `json:"error_message,omitempty"`
}

type UploadReportResponse struct {
	OK       bool  `json:"ok"`
	UploadID int64 `json:"upload_id"`
	Created  bool  `json:"created"`
}

type ReportRequest struct {
	WebsiteID       int64  `json:"website_id"`
	Filename        string `json:"filename"`
	FullPath        string `json:"full_path,omitempty"`
	Size            int64  `json:"size,omitempty"`
	BackupAt        string `json:"backup_at"` // ISO 8601
	Status          string `json:"status,omitempty"`
	DurationSeconds int    `json:"duration_seconds,omitempty"`
}

type ReportResponse struct {
	OK      bool  `json:"ok"`
	LogID   int64 `json:"log_id"`
	Created bool  `json:"created"`
}

// 注：Report 端点已支持返回 log_id（backend/AgentController::report）

// ---------- 错误 ----------

type APIError struct {
	Status  int
	Code    string
	Message string
}

func (e *APIError) Error() string {
	return fmt.Sprintf("api error status=%d code=%s: %s", e.Status, e.Code, e.Message)
}

func IsAuthError(err error) bool {
	var apiErr *APIError
	if errors.As(err, &apiErr) {
		return apiErr.Status == 401
	}
	return false
}

// ---------- API 方法 ----------

func (c *Client) Register(info *sysinfo.BasicInfo) (*RegisterResponse, error) {
	req := registerRequest{
		AgentVersion: c.version,
		Hostname:     info.Hostname,
		OSType:       info.OSType,
		Kernel:       info.Kernel,
		Arch:         info.Arch,
		IP:           info.IP,
	}
	var resp RegisterResponse
	if err := c.post("/api/agent/register", req, &resp); err != nil {
		return nil, err
	}
	return &resp, nil
}

func (c *Client) Heartbeat(m *sysinfo.Metrics) (*HeartbeatResponse, error) {
	req := heartbeatRequest{AgentVersion: c.version}
	if m != nil {
		req.CPUUsage = m.CPU
		req.MemUsage = m.Memory
		req.DiskUsage = m.Disk
		req.Uptime = m.Uptime
		for _, d := range m.Disks {
			req.Disks = append(req.Disks, diskEntry{
				Mount:     d.Mount,
				FS:        d.FS,
				Total:     d.Total,
				Used:      d.Used,
				Available: d.Available,
			})
		}
	}
	var resp HeartbeatResponse
	if err := c.post("/api/agent/heartbeat", req, &resp); err != nil {
		return nil, err
	}
	return &resp, nil
}

func (c *Client) CommandResult(req *CommandResultRequest) error {
	return c.post("/api/agent/command-result", req, nil)
}

func (c *Client) Websites() ([]Website, error) {
	data, err := c.WebsitesFull()
	if err != nil {
		return nil, err
	}
	return data.Websites, nil
}

// WebsitesFull 拉网站和存储清单（v0.3 新增）
func (c *Client) WebsitesFull() (*WebsitesData, error) {
	var resp websitesResponse
	if err := c.get("/api/agent/websites", &resp); err != nil {
		return nil, err
	}
	return &WebsitesData{Websites: resp.Websites, Storages: resp.Storages}, nil
}

func (c *Client) UploadReport(req *UploadReportRequest) (*UploadReportResponse, error) {
	var resp UploadReportResponse
	if err := c.post("/api/agent/upload-report", req, &resp); err != nil {
		return nil, err
	}
	return &resp, nil
}

func (c *Client) Report(req *ReportRequest) (*ReportResponse, error) {
	var resp ReportResponse
	if err := c.post("/api/agent/report", req, &resp); err != nil {
		return nil, err
	}
	return &resp, nil
}

// ---------- HTTP 底层 ----------

func (c *Client) post(path string, body any, out any) error {
	buf, err := json.Marshal(body)
	if err != nil {
		return err
	}
	return c.do(http.MethodPost, path, bytes.NewReader(buf), out)
}

func (c *Client) get(path string, out any) error {
	return c.do(http.MethodGet, path, nil, out)
}

func (c *Client) do(method, path string, body io.Reader, out any) error {
	req, err := http.NewRequest(method, c.baseURL+path, body)
	if err != nil {
		return err
	}
	if body != nil {
		req.Header.Set("Content-Type", "application/json")
	}
	req.Header.Set("Accept", "application/json")
	req.Header.Set("X-Agent-Token", c.token)
	req.Header.Set("User-Agent", "backup-agent/"+c.version)

	resp, err := c.http.Do(req)
	if err != nil {
		return err
	}
	defer resp.Body.Close()

	data, _ := io.ReadAll(resp.Body)

	if resp.StatusCode >= 400 {
		var e struct {
			Code    string `json:"code"`
			Message string `json:"message"`
		}
		_ = json.Unmarshal(data, &e)
		return &APIError{Status: resp.StatusCode, Code: e.Code, Message: e.Message}
	}
	if out != nil {
		if err := json.Unmarshal(data, out); err != nil {
			return fmt.Errorf("解析响应 JSON: %w", err)
		}
	}
	return nil
}
