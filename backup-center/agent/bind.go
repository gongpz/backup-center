package main

import (
	"encoding/json"
	"fmt"
	"log/slog"
	"os"
	"path/filepath"
	"strings"

	"github.com/gongpz/backup-center/agent/internal/client"
)

// bindFilePrefix 密钥文件名前缀：完整文件名 = bindFilePrefix + token
// 用户把该文件放进网站根目录，Agent 据此定位/校验根目录。
const bindFilePrefix = ".bc-bind-"

// performBindRoot 根目录绑定：
//   - direct_path：用户直接填根目录，Agent 校验密钥文件是否在该目录（快而可靠）
//   - search_bases：用户在若干基址内让 Agent 有界搜索密钥文件（备选）
// 成功返回 JSON {"web_root": "..."}；失败返回 error。
func performBindRoot(cmd *client.PendingCommand) (string, error) {
	p := cmd.Params
	if p == nil {
		return "", fmt.Errorf("绑定指令缺少参数")
	}
	token, _ := p["bind_token"].(string)
	if token == "" {
		return "", fmt.Errorf("缺少 bind_token")
	}
	keyName := bindFilePrefix + token

	directPath, _ := p["direct_path"].(string)
	rawBases, _ := p["search_bases"].([]interface{})

	var (
		webRoot string
		err     error
	)
	switch {
	case strings.TrimSpace(directPath) != "":
		webRoot, err = verifyDirectRoot(directPath, keyName)
	case len(rawBases) > 0:
		webRoot, err = searchBindFile(rawBases, keyName)
	default:
		err = fmt.Errorf("请提供网站根目录路径或搜索基址")
	}
	if err != nil {
		return "", err
	}

	out, merr := json.Marshal(map[string]string{"web_root": webRoot})
	if merr != nil {
		return "", merr
	}
	slog.Info("根目录绑定成功", "id", cmd.ID, "web_root", webRoot)
	return string(out), nil
}

// verifyDirectRoot 校验密钥文件是否存在于用户指定的根目录。
func verifyDirectRoot(dir, keyName string) (string, error) {
	abs, err := filepath.Abs(strings.TrimSpace(dir))
	if err != nil {
		return "", err
	}
	info, err := os.Stat(abs)
	if err != nil || !info.IsDir() {
		return "", fmt.Errorf("目录不存在或不是目录: %s", dir)
	}
	if _, err := os.Stat(filepath.Join(abs, keyName)); err != nil {
		return "", fmt.Errorf("未在该目录找到密钥文件 %s，请先把密钥文件放入网站根目录", keyName)
	}
	return abs, nil
}

// searchBindFile 在给定基址内有界搜索密钥文件，返回其所在目录（第一个命中）。
func searchBindFile(bases []interface{}, keyName string) (string, error) {
	const maxDepth = 6
	for _, b := range bases {
		base, _ := b.(string)
		base = strings.TrimSpace(base)
		if base == "" {
			continue
		}
		abs, err := filepath.Abs(base)
		if err != nil {
			continue
		}
		baseDepth := strings.Count(filepath.Clean(abs), string(os.PathSeparator))
		found := ""
		_ = filepath.WalkDir(abs, func(path string, d os.DirEntry, walkErr error) error {
			if walkErr != nil {
				return nil // 忽略无权限/IO 错误，继续搜其它分支
			}
			if found != "" {
				return filepath.SkipAll
			}
			if d.IsDir() {
				if path != abs {
					switch d.Name() {
					case "proc", "sys", "dev", "node_modules", ".git":
						return filepath.SkipDir
					}
					depth := strings.Count(filepath.Clean(path), string(os.PathSeparator)) - baseDepth
					if depth >= maxDepth {
						return filepath.SkipDir
					}
				}
				return nil
			}
			if d.Name() == keyName {
				found = filepath.Dir(path)
				return filepath.SkipAll
			}
			return nil
		})
		if found != "" {
			return found, nil
		}
	}
	return "", fmt.Errorf("在给定搜索基址内未找到密钥文件 %s", keyName)
}
