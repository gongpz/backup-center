package config

import (
	"encoding/json"
	"fmt"
	"net"
	"net/url"
	"os"
	"strings"
)

// allowHTTPEnv 逃生开关：内网自建（自签证书 / 本机反代）场景设 BC_ALLOW_HTTP=1 显式放行 http
const allowHTTPEnv = "BC_ALLOW_HTTP"

type Config struct {
	CenterURL         string `json:"center_url"`
	AgentToken        string `json:"agent_token"`
	HeartbeatInterval int    `json:"heartbeat_interval,omitempty"`
	// MirrorURL：Agent 二进制 CDN 镜像前缀（可选）。设置后 update 时先从这里下，失败回退 CenterURL
	// 由 install.sh 首次写入，也可以由 Center 心跳响应动态刷新
	MirrorURL string `json:"mirror_url,omitempty"`
}

// Save 把 Config 序列化并原子写回原文件
func Save(path string, c *Config) error {
	data, err := json.MarshalIndent(c, "", "  ")
	if err != nil {
		return err
	}
	tmp := path + ".tmp"
	if err := os.WriteFile(tmp, data, 0o600); err != nil {
		return err
	}
	return os.Rename(tmp, path)
}

func Load(path string) (*Config, error) {
	data, err := os.ReadFile(path)
	if err != nil {
		return nil, fmt.Errorf("读取配置文件 %s: %w", path, err)
	}
	var c Config
	if err := json.Unmarshal(data, &c); err != nil {
		return nil, fmt.Errorf("解析 JSON 配置: %w", err)
	}
	if c.CenterURL == "" {
		return nil, fmt.Errorf("配置缺少 center_url")
	}
	// C3：Agent 与 Center 之间传的是 agent_token、存储凭据与数据库密码，
	// 明文 http 等于把这些全放在链路上裸奔，所以默认强制 https。
	if err := validateTransportSecurity(c.CenterURL); err != nil {
		return nil, err
	}
	if c.AgentToken == "" {
		return nil, fmt.Errorf("配置缺少 agent_token")
	}
	return &c, nil
}

// validateTransportSecurity 校验 center_url 的传输安全（C3）：
// scheme 非 https 且 host 非 loopback 时拒绝启动；BC_ALLOW_HTTP=1 显式放行。
// 只校验 scheme，不触碰 TLS 校验行为（保持 http.Client 默认，不引入 InsecureSkipVerify）。
func validateTransportSecurity(rawURL string) error {
	if os.Getenv(allowHTTPEnv) == "1" {
		return nil
	}
	u, err := url.Parse(rawURL)
	if err != nil {
		return fmt.Errorf("center_url 无法解析: %w", err)
	}
	// https 放行；其它或缺失 scheme（如 "10.0.0.5:8080"）交给 HTTP 客户端自行报错，这里不断言
	if !strings.EqualFold(u.Scheme, "http") {
		return nil
	}
	if isLoopbackHost(u.Hostname()) {
		return nil
	}
	return fmt.Errorf("检测到 center_url 为 http（%s），凭据与备份数据将明文传输；内网自建请设置 %s=1 显式放行", rawURL, allowHTTPEnv)
}

// isLoopbackHost 127.0.0.1 / localhost / ::1 等本机地址：同机部署时明文走回环，风险可控
func isLoopbackHost(host string) bool {
	if strings.EqualFold(host, "localhost") {
		return true
	}
	if ip := net.ParseIP(host); ip != nil {
		return ip.IsLoopback()
	}
	return false
}
