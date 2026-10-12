package config

import (
	"encoding/json"
	"fmt"
	"os"
)

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
	if c.AgentToken == "" {
		return nil, fmt.Errorf("配置缺少 agent_token")
	}
	return &c, nil
}
