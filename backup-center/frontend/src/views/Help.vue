<script setup>
import { ref, computed } from 'vue'
import { ElMessage } from 'element-plus'
import { CopyDocument, QuestionFilled } from '@element-plus/icons-vue'
import { useAuthStore } from '@/stores/auth'
import { copyText } from '@/utils/clipboard'

const activeTab = ref('storage')

const origin = computed(() => `${window.location.protocol}//${window.location.host}`)

// 按存储类型分配 chip 颜色
const typeChipClass = (type) => {
  if (type === 'S3 兼容') return 'chip-s3'
  if (type === 'SFTP')     return 'chip-sftp'
  if (type === 'WebDAV')   return 'chip-webdav'
  return 'chip-s3'
}

const copy = async (text) => {
  const ok = await copyText(text)
  if (ok) ElMessage.success('已复制')
  else ElMessage.error('复制失败，请手动选中复制')
}

// 复用的命令片段
const installCmd = computed(() => (
  `curl -fsSL ${origin.value}/agent/install.sh | \\\n` +
  `  BC_URL="${origin.value}" \\\n` +
  `  BC_TOKEN="bca_从服务器详情复制" \\\n` +
  `  sh`
))
const reinstallCmd = computed(() => (
  `curl -fsSL ${origin.value}/agent/install.sh | \\\n` +
  `  BC_URL="${origin.value}" \\\n` +
  `  BC_TOKEN="bca_新token" \\\n` +
  `  sh`
))
const statusCmd = `systemctl status backup-agent`
const lifecycleCmd = `systemctl restart backup-agent`
const upgradeCmd = `backup-agent update`
// 单行 && 串联 —— SSH 多行粘贴常被截断，改成一条复合命令粘贴一次跑完
const manualUpgradeCmd = computed(() => (
  `curl -fsSL ${origin.value}/agent/backup-agent-linux-amd64 -o /tmp/ba && ` +
  `chmod +x /tmp/ba && /tmp/ba version && ` +
  `systemctl stop backup-agent && ` +
  `mv /tmp/ba /opt/backup-agent/backup-agent && ` +
  `systemctl start backup-agent && ` +
  `backup-agent version`
))
const uninstallCmd = (
  `systemctl stop backup-agent\n` +
  `systemctl disable backup-agent\n` +
  `rm -f /opt/backup-agent/backup-agent\n` +
  `rm -f /etc/systemd/system/backup-agent.service\n` +
  `rm -rf /etc/backup-agent /var/log/backup-agent\n` +
  `systemctl daemon-reload`
)

// 存储配置示例
const storageExamples = [
  {
    key: 'r2',
    name: 'Cloudflare R2',
    type: 'S3 兼容',
    intro: 'Cloudflare R2 免费额度 10 GB / 月，出流量免费，是备份的性价比选择。',
    fields: [
      { label: '类型', value: 'S3 兼容' },
      { label: 'endpoint', value: 'https://<account-id>.r2.cloudflarestorage.com', mono: true },
      { label: 'region', value: 'auto', mono: true },
      { label: 'bucket', value: '你在 R2 后台创建的 bucket 名', mono: true },
      { label: 'access_key_id', value: '32 位十六进制字符串（不是 cfat_ 开头的令牌！）', mono: false },
      { label: 'secret_access_key', value: '64 位十六进制字符串', mono: false },
      { label: 'path_style', value: 'true', mono: true },
    ],
    tip: '⚠️ 最容易踩的坑：Cloudflare 有 3 种"钥匙"，别搞混：\n① 账户 API 令牌（cfat_ 开头）—— 用于 Cloudflare 主 API，不能当 S3 凭证\n② R2 Access Key ID（32 位十六进制）—— 才是 access_key_id\n③ R2 Secret Access Key（64 位十六进制）—— 才是 secret_access_key\n② 和 ③ 都要在【R2 → 管理 R2 API 令牌 → 创建】里生成，一次生成后成对给你。\nendpoint 里的 <account-id> 是账户 ID，登录 R2 首页右上角能看到。',
  },
  {
    key: 'qiniu',
    name: '七牛 Kodo',
    type: 'S3 兼容',
    intro: '七牛对象存储有 10 GB 免费空间，国内访问速度好。',
    fields: [
      { label: '类型', value: 'S3 兼容' },
      { label: 'endpoint', value: 's3.<region>.qiniucs.com', mono: true },
      { label: 'region', value: '华南：cn-south-1；华东：cn-east-1；华北：cn-north-1', mono: false },
      { label: 'bucket', value: '在七牛控制台先创建 bucket', mono: false },
      { label: 'access_key_id / secret_access_key', value: '七牛"个人中心 → 密钥管理"', mono: false },
      { label: 'path_style', value: 'true', mono: true },
    ],
    tip: '⚠️ 七牛 bucket 需要先在控制台手动创建，Center 不会自动建。',
  },
  {
    key: 'cstcloud',
    name: '中科院数据胶囊',
    type: 'S3 兼容',
    intro: '中科院数据胶囊（cstcloud）对科研 / 个人用户免费，教育网访问快。',
    fields: [
      { label: '类型', value: 'S3 兼容' },
      { label: 'endpoint', value: 'https://s3.cstcloud.cn', mono: true },
      { label: 'region', value: 'us-east-1', mono: true },
      { label: 'bucket', value: '数据胶囊控制台的 bucket 名', mono: false },
      { label: 'access_key_id / secret_access_key', value: '数据胶囊控制台生成的密钥对', mono: false },
      { label: 'path_style', value: 'true', mono: true },
    ],
    tip: '',
  },
  {
    key: 's3generic',
    name: '通用 S3（MinIO / B2 / Wasabi 等）',
    type: 'S3 兼容',
    intro: 'MinIO 自建、Backblaze B2、Wasabi、DigitalOcean Spaces、腾讯云 COS 等所有 S3 兼容服务都能用。',
    fields: [
      { label: 'endpoint', value: '服务商提供的 endpoint 完整 URL', mono: false },
      { label: 'region', value: '不同服务商不同：MinIO 一般 us-east-1；B2 见后台；DO Spaces = nyc3/sgp1 等', mono: false },
      { label: 'bucket', value: '目标 bucket 名', mono: false },
      { label: 'access_key_id / secret_access_key', value: '服务商生成的密钥对', mono: false },
      { label: 'path_style', value: '第三方几乎都用 true；AWS 官方 S3 用 false', mono: true },
    ],
    tip: '⚠️ path_style 是最容易踩坑的点。上传失败 400 Bad Request，八成是这个字段设错。',
  },
  {
    key: 'sftp',
    name: 'SFTP',
    type: 'SFTP',
    intro: 'SFTP 适合把备份推到另一台自己的服务器（比如另一家云的机器）。',
    fields: [
      { label: 'host', value: '目标机器 IP 或域名', mono: false },
      { label: 'port', value: '22（默认）', mono: true },
      { label: 'user', value: 'SSH 用户名', mono: false },
      { label: '密码 / 私钥', value: '二选一。生产环境强烈建议用私钥', mono: false },
      { label: '目标路径', value: '/backups（Agent 上传的 tar/sql 文件会放在此目录下）', mono: true },
    ],
    tip: '💡 目标机器需要预先创建目录并给用户写权限。',
  },
  {
    key: 'webdav',
    name: 'WebDAV',
    type: 'WebDAV',
    intro: 'WebDAV 适合 Nextcloud / OwnCloud / 坚果云 等自建或商用云盘服务。',
    fields: [
      { label: 'url', value: 'https://your-cloud.com/remote.php/dav/files/username/', mono: true },
      { label: 'vendor', value: 'nextcloud / owncloud / other（可选，rclone 会针对性优化）', mono: false },
      { label: 'user', value: '登录用户名', mono: false },
      { label: 'password', value: '密码；Nextcloud 建议用"应用密码"而非登录密码', mono: false },
    ],
    tip: '',
  },
]

// SSH 命令
const auth = useAuthStore()
</script>

<template>
  <div class="help-page">
    <div class="toolbar">
      <div>
        <h2 class="page-h">
          <el-icon><QuestionFilled /></el-icon>
          帮助中心
        </h2>
        <p class="page-sub">配置指南 · 服务器接入 · Agent 命令参考</p>
      </div>
    </div>

    <el-tabs v-model="activeTab" class="help-tabs">
      <!-- ============ 关于项目 ============ -->
      <el-tab-pane label="关于项目" name="about">
        <div class="tab-intro">
          <b>Backup Center</b> 是一套自托管的服务器备份管理中心：把散在各处的网站与数据库备份，统一收集到你自己掌控的存储里，出事了能按次找回、一键恢复。
        </div>

        <el-collapse>
          <el-collapse-item name="what">
            <template #title>
              <div class="col-title">
                <span class="strong">解决什么问题</span>
                <span class="chip chip-blue">定位</span>
              </div>
            </template>
            <p class="intro">
              服务器上的备份往往靠面板或脚本各管各的：散落多台机器、格式不一、过期不清理，真出事了找不到是哪一份、也不敢乱恢复。
              <br /><br />
              Backup Center 把这件事统一管起来：<b>自动备份 → 多存储冗余 → 到期清理 → 按次整合、可挑可恢复</b>。整套系统部署在你自己控制的服务器上，备份数据只进你自己指定的存储，不经过任何第三方。
            </p>
          </el-collapse-item>

          <el-collapse-item name="arch">
            <template #title>
              <div class="col-title">
                <span class="strong">系统组成</span>
                <span class="chip chip-purple">架构</span>
              </div>
            </template>
            <p class="intro">
              <b>Center（管理端）</b>：部署在你的一台 VPS 上，也就是你现在使用的这个 Web 界面。负责任务调度、存储管理、加密密钥托管与恢复编排。
              <br /><br />
              <b>Agent（被控端）</b>：跑在每台被监控服务器上的 Go 单二进制程序（约 5MB、内存占用低），由 Center 下发指令，在目标机器上完成目录扫描、打包压缩、数据库导出与 rsync 快照。
              <br /><br />
              <b>rclone（传输层）</b>：Center 与 Agent 均内置，负责向 SFTP / FTP / WebDAV / S3 等存储传输文件，并可叠加 rclone crypt 加密层——云端只存密文，恢复时自动解密。
            </p>
          </el-collapse-item>

          <el-collapse-item name="modes">
            <template #title>
              <div class="col-title">
                <span class="strong">三种备份模式</span>
                <span class="chip chip-sftp">核心概念</span>
              </div>
            </template>
            <table class="kv-table">
              <tr>
                <td class="k">轻便模式</td>
                <td class="v">外部压缩：监控服务器上已有的备份产物（面板 / 脚本生成），发现新文件即上传，无需绑定根目录</td>
              </tr>
              <tr>
                <td class="k">高级模式</td>
                <td class="v">自主压缩：由 Agent 直接在服务器上打包网站目录、导出数据库后上传，需绑定网站根目录</td>
              </tr>
              <tr>
                <td class="k">增量模式</td>
                <td class="v">rsync 硬链快照到 VPS，仅传输变化部分——空间占用接近一份全量，传输量只有增量，需绑定网站根目录</td>
              </tr>
            </table>
            <p class="tip">每个网站三选一，在「添加 / 编辑网站」弹窗顶部的模式卡片中切换。</p>
          </el-collapse-item>

          <el-collapse-item name="restore-modes">
            <template #title>
              <div class="col-title">
                <span class="strong">两种恢复模式</span>
                <span class="chip chip-purple">恢复</span>
              </div>
            </template>
            <table class="kv-table">
              <tr>
                <td class="k">轻便恢复</td>
                <td class="v">远程拉回备份文件到服务器备份目录，由管理员手动解压、导入数据库。</td>
              </tr>
              <tr>
                <td class="k">高级恢复</td>
                <td class="v">全自动恢复：自动预备份（网站目录打包、数据库导出）→ 解压覆盖网站根目录 → 重建并导入数据库。</td>
              </tr>
            </table>
            <p class="tip">在「添加 / 编辑网站」弹窗的恢复模式中选择；高级恢复已支持（预备备、覆盖、导库全自动），恢复前自动预备份，可回滚。</p>
          </el-collapse-item>

          <el-collapse-item name="who">
            <template #title>
              <div class="col-title">
                <span class="strong">适用人群</span>
                <span class="chip chip-blue">场景</span>
              </div>
            </template>
            <p class="intro">
              运维个人站点与中小团队服务的站长、运维：手里有几台到几十台服务器，希望备份这件事<b>自动跑、看得见、找得回、不浪费</b>——增量模式省流量与存储，多存储冗余防单点，恢复前自动预备份防手滑。
            </p>
          </el-collapse-item>
        </el-collapse>
      </el-tab-pane>

      <!-- ============ 存储配置 ============ -->
      <el-tab-pane label="存储配置" name="storage">
        <div class="tab-intro">
          Center 支持 3 种存储类型：<b>S3 兼容 / SFTP / WebDAV</b>。下面按具体服务商列出实测可用的配置。
          <br />
          <span class="muted">操作路径：存储 → 添加存储 → 选类型 → 按下方参数填。</span>
        </div>

        <el-collapse>
          <el-collapse-item name="mandatory">
            <template #title>
              <div class="col-title">
                <span class="strong">强制备份（系统自动推送）</span>
                <span class="chip chip-blue">推送规则</span>
              </div>
            </template>
            <p class="intro">
              存储上开启「强制备份」后，<b>所有</b>网站的备份都会自动推送到此存储——不用挨个网站配。
              在「添加 / 编辑网站」弹窗的「推送到」一栏中，这类存储以锁定形式展示，无需选择；
              其余存储仍可按网站自由勾选。
            </p>
          </el-collapse-item>
          <el-collapse-item
            v-for="ex in storageExamples"
            :key="ex.key"
            :name="ex.key"
          >
            <template #title>
              <div class="col-title">
                <span class="strong">{{ ex.name }}</span>
                <span class="chip" :class="typeChipClass(ex.type)">{{ ex.type }}</span>
              </div>
            </template>
            <p class="intro">{{ ex.intro }}</p>
            <table class="kv-table">
              <tr v-for="f in ex.fields" :key="f.label">
                <td class="k">{{ f.label }}</td>
                <td class="v"><span :class="{ mono: f.mono }">{{ f.value }}</span></td>
              </tr>
            </table>
            <p v-if="ex.tip" class="tip">{{ ex.tip }}</p>
          </el-collapse-item>
        </el-collapse>
      </el-tab-pane>

      <!-- ============ 添加服务器 ============ -->
      <el-tab-pane label="添加服务器" name="server">
        <div class="tab-intro">
          Center 有两种接入方式：<b>Agent 模式（推荐）</b> 让目标机器主动上报；<b>SSH 模式</b> 让 Center 主动去拉。
        </div>

        <el-collapse>
          <el-collapse-item name="agent">
            <template #title>
              <div class="col-title">
                <span class="strong">Agent 模式（推荐）</span>
                <span class="chip chip-blue">主动上报</span>
              </div>
            </template>
            <p class="intro">Agent 是一个跑在目标机器上的 Go 程序（5MB 单二进制、7MB 内存占用），通过 systemd 托管。</p>
            <ol class="steps">
              <li>点击左侧 <b>服务器 → 添加服务器</b>，连接方式选 <b>Agent</b>，填名字（如 "香港-01"）和备注。</li>
              <li>保存后回到列表，点该行 <b>详情</b>，抽屉里有一段"安装命令"。</li>
              <li>SSH 到目标机器，以 <b>root</b> 执行那段命令（含 curl 一键脚本）。</li>
              <li>脚本自动安装 rclone + Agent + systemd unit，测试连接，启动服务。</li>
              <li>返回 Center，60 秒内该服务器状态变成"在线"。</li>
            </ol>
            <div class="code-box">
              <pre>curl -fsSL {{ origin }}/agent/install.sh | \
  BC_URL="{{ origin }}" \
  BC_TOKEN="bca_从服务器详情复制" \
  sh</pre>
              <el-button size="small" text @click="copy(installCmd)">
                <el-icon><CopyDocument /></el-icon>
                复制
              </el-button>
            </div>
            <p class="tip">💡 目标机器只需能访问 {{ origin }}，无需开放任何入站端口。适合防火墙严格的家宽 / NAT 后的机器。</p>
          </el-collapse-item>

          <el-collapse-item name="ssh">
            <template #title>
              <div class="col-title">
                <span class="strong">SSH 模式</span>
                <span class="chip chip-purple">Center 主动</span>
              </div>
            </template>
            <p class="intro">Center 通过 SSH 直连目标机器拉备份。适合"Center 和目标机器在同一内网 / 云 VPC" 的场景。</p>
            <ol class="steps">
              <li>点击 <b>服务器 → 添加服务器</b>，连接方式选 <b>SSH</b>。</li>
              <li>填目标机器的 <b>IP、SSH 端口、用户名</b>。</li>
              <li>粘贴 <b>私钥</b>（该私钥的公钥必须已加到目标机器 <code>~/.ssh/authorized_keys</code>）。</li>
              <li>填"备份目录"—— Center 会 SSH 过去扫这个目录。</li>
              <li>保存。目前 SSH 模式的自动抓取功能待完善，MVP 阶段建议优先用 Agent 模式。</li>
            </ol>
            <p class="tip">⚠️ Center 部署在公网时，SSH 直连每台目标机器都需要目标机器暴露 SSH，通常不安全。首选 Agent 模式。</p>
          </el-collapse-item>
        </el-collapse>
      </el-tab-pane>

      <!-- ============ SSH 命令 ============ -->
      <el-tab-pane label="SSH 命令" name="ssh">
        <div class="tab-intro">
          在装了 Agent 的目标机器上（以 <b>root</b> 或 <b>sudo</b>）执行以下命令。所有命令都可点右侧复制按钮。
        </div>

        <el-collapse>
          <el-collapse-item title="查看状态 / 版本 / 日志" name="status">
            <div class="code-box">
              <pre># 查看 systemd 服务状态
systemctl status backup-agent

# 查看 Agent 版本
backup-agent version

# 查看实时日志（Ctrl+C 退出）
journalctl -u backup-agent -f

# 查看最近 100 行日志
journalctl -u backup-agent -n 100

# 只看错误
journalctl -u backup-agent -p err</pre>
              <el-button size="small" text @click="copy(statusCmd)">
                <el-icon><CopyDocument /></el-icon>
                复制
              </el-button>
            </div>
          </el-collapse-item>

          <el-collapse-item title="启动 / 停止 / 重启" name="lifecycle">
            <div class="code-box">
              <pre>systemctl start backup-agent      # 启动
systemctl stop backup-agent       # 停止
systemctl restart backup-agent    # 重启
systemctl enable backup-agent     # 设为开机自启（安装脚本已做）
systemctl disable backup-agent    # 取消开机自启</pre>
              <el-button size="small" text @click="copy(lifecycleCmd)">
                <el-icon><CopyDocument /></el-icon>
                复制
              </el-button>
            </div>
          </el-collapse-item>

          <el-collapse-item title="升级到最新版本" name="upgrade">
            <p class="intro">Agent v0.5+ 自带 <code>update</code> 命令，一条即可升级。Center 也可在网页点"升级"远程下发（要求 Agent 已经 ≥ v0.7）。</p>
            <div class="code-box">
              <pre># v0.5+：一条命令自动升级（读配置 → 下载 → 校验 → 原子替换 → 重启）
backup-agent update

# 强制升级（版本相同也走一遍，用于回滚或修复）
backup-agent -f update</pre>
              <el-button size="small" text @click="copy(upgradeCmd)">
                <el-icon><CopyDocument /></el-icon>
                复制
              </el-button>
            </div>

            <p class="intro" style="margin-top: 14px">若当前 Agent 版本 &lt; v0.5（没有 <code>update</code> 命令），用单行复合命令一次升级（<b>请整段复制粘贴</b>，避免 SSH 客户端多行粘贴时被截断）：</p>
            <div class="code-box">
              <pre>curl -fsSL {{ origin }}/agent/backup-agent-linux-amd64 -o /tmp/ba && \
chmod +x /tmp/ba && /tmp/ba version && \
systemctl stop backup-agent && \
mv /tmp/ba /opt/backup-agent/backup-agent && \
systemctl start backup-agent && \
backup-agent version</pre>
              <el-button size="small" text @click="copy(manualUpgradeCmd)">
                <el-icon><CopyDocument /></el-icon>
                复制
              </el-button>
            </div>
          </el-collapse-item>

          <el-collapse-item title="重装 Agent（用新 Token 或重新绑定服务器）" name="reinstall">
            <p class="intro">场景：把这台机器换绑到 Center 里的另一台服务器记录；或 Token 泄露重生后需要用新 Token。</p>
            <div class="code-box">
              <pre>curl -fsSL {{ origin }}/agent/install.sh | \
  BC_URL="{{ origin }}" \
  BC_TOKEN="bca_新token" \
  sh</pre>
              <el-button size="small" text @click="copy(reinstallCmd)">
                <el-icon><CopyDocument /></el-icon>
                复制
              </el-button>
            </div>
            <p class="tip">💡 install.sh 幂等：重复执行会自动 stop → 覆盖 → 换配置 → 重启。</p>
          </el-collapse-item>

          <el-collapse-item title="卸载 Agent" name="uninstall">
            <p class="intro">彻底清理该目标机器上的 Agent 相关文件。</p>
            <div class="code-box">
              <pre>systemctl stop backup-agent
systemctl disable backup-agent
rm -f /opt/backup-agent/backup-agent
rm -f /etc/systemd/system/backup-agent.service
rm -rf /etc/backup-agent /var/log/backup-agent
systemctl daemon-reload
# 可选：如果不再用 rclone 也一起卸
# rm -f /usr/bin/rclone</pre>
              <el-button size="small" text @click="copy(uninstallCmd)">
                <el-icon><CopyDocument /></el-icon>
                复制
              </el-button>
            </div>
            <p class="tip">⚠️ 卸载不会删除已上传到远端存储的备份文件，也不会删 Center 里的服务器记录。</p>
          </el-collapse-item>

          <el-collapse-item title="测试连接 / 配置文件位置" name="paths">
            <p class="intro">调试连接问题时用得上。</p>
            <div class="code-box">
              <pre># 用配置文件跑一次注册测试（不启动 daemon）
backup-agent -config /etc/backup-agent/config.json test

# 查看配置文件
cat /etc/backup-agent/config.json</pre>
            </div>
            <table class="kv-table" style="margin-top: 12px">
              <tr>
                <td class="k">Agent 二进制</td>
                <td class="v mono">/opt/backup-agent/backup-agent</td>
              </tr>
              <tr>
                <td class="k">配置文件</td>
                <td class="v mono">/etc/backup-agent/config.json</td>
              </tr>
              <tr>
                <td class="k">systemd unit</td>
                <td class="v mono">/etc/systemd/system/backup-agent.service</td>
              </tr>
              <tr>
                <td class="k">日志</td>
                <td class="v mono">journalctl -u backup-agent</td>
              </tr>
              <tr>
                <td class="k">rclone 配置</td>
                <td class="v mono">/etc/backup-agent/rclone.conf（Agent 自动生成，勿手改）</td>
              </tr>
            </table>
          </el-collapse-item>
        </el-collapse>
      </el-tab-pane>

      <!-- ============ 版本特性 ============ -->
      <el-tab-pane label="版本特性" name="whatsnew">
        <div class="tab-intro">
          当前版本 <b>v1.2.24</b> 的核心特性导览，含 v1.2.17 引入的增量备份体系。
        </div>

        <el-collapse>
          <el-collapse-item name="restore-coupling-polish" title="恢复模式联动文案优化 · 高级/增量隐藏附加备份目录（v1.2.24）">
            <p class="intro">
              在 v1.2.23 恢复模式联动锁定的基础上做了两处界面收敛：<br /><br />
              ① <b>锁定提示文案精简</b>：左侧恢复模式锁定说明由「已锁定为「轻便恢复」：」简化为「已锁定轻便恢复：」、「已锁定为「高级恢复」：」简化为「已锁定高级恢复：」，去掉冗余标点，阅读更顺；同时移除轻便模式下原本不再适用的「高级恢复须先绑定…」注释，避免误导。<br /><br />
              ② <b>隐藏冗余编辑器</b>：高级模式与增量模式下，自主备份（高级压缩 / rsync 快照）直接打包 <code>web_root</code>，并不读取「附加备份目录」字段，该编辑器此前仅为选填且无实际作用。现仅在<b>轻便模式</b>保留必填的「备份目录」（供 Agent 监控第三方产物），高级 / 增量模式不再展示「附加备份目录」，界面更聚焦。存量高级 / 增量站点保存时原值仍被保留，无数据丢失。
            </p>
          </el-collapse-item>

          <el-collapse-item name="restore-mode-coupling" title="恢复模式与备份模式联动锁定（v1.2.23 · 需 Agent v0.15.4+）">
            <p class="intro">
              编辑网站时，左侧「恢复模式」不再自由独立设定，而是与右侧备份模式<b>联动</b>：选<b>轻便</b>备份→锁定「轻便恢复」不可更改（外部压缩产物命名不保证符合 advanced 解析）；选<b>高级</b>备份→默认「高级恢复」，可改回轻便；选<b>增量</b>备份→锁定「高级恢复」不可更改（rsync 快照天然走全量回滚）。下方按场景显示说明，并提示「高级恢复须先绑定网站目录并填写数据库连接，否则恢复将直接失败」。
              <br /><br />
              Agent v0.15.4 修复了高级恢复「网站目录」解压必然失败的根因：原先用 <code>lsattr -R</code> 探测 immutable 文件会漏掉点文件（宝塔的 <code>.user.ini</code> 恰好是点文件），导致 <code>chattr +i</code> 未被解除、tar 覆盖写失败；现改为 <code>find + lsattr</code> 枚举并新增整树强制解除兜底重试。v0.15.3 还去掉了会令非空目录 unlink 失败的 <code>--unlink-first</code>，并修复了恢复失败结果被吞、Center 侧指令永久 <code>dispatched</code> 的问题。
            </p>
          </el-collapse-item>

          <el-collapse-item name="storage-browse-retention" title="存储远端浏览 · 保留期清理加固（v1.2.22 · 需 Agent v0.15.2+）">
            <p class="intro">
              存储表单（SSH / SFTP）新增<b>「浏览」按钮</b>：新增或编辑存储时即可连接远端预览目录树，直接点选回填目标路径，不必先保存再反复试错。网站页「浏览备份」同步修正了初始路径拼接与 Agent 端不一致的问题——此前会拼成 home 相对路径，导致增量备份后误报「目录不存在」，现已与 Agent 的快照根布局对齐。
              <br /><br />
              Agent 侧两项加固：<b>增量备份前自动预建目标目录</b>（首次备份到一个全新的 base 路径，不再因父目录不存在而必失败）；<b>保留期清理加超时保护</b>——单条 rclone 删除超过 10 分钟即中止，该条按失败处理并保留数据库记录待下轮重派，避免后端卡死时清理指令永久挂起、堵住 Agent 命令循环。
            </p>
          </el-collapse-item>

          <el-collapse-item name="focus-polish" title="输入框聚焦与配对细化（v1.2.21）">
            <p class="intro">
              输入框、多行框与下拉框聚焦时不再出现彩色聚焦框——原先的主色描边与焦点环已整体移除，聚焦后保持与默认一致的 1px 描边，不变色、不加粗，输入位置由光标本身指示；必填校验的红色错误描边保留。
              <br /><br />
              存储弹窗配对字段宽度重平衡：主机 216→176px、用户名 203→163px，端口与密码 162→202px，同行两个输入框间距保持 54px，凭据区更紧凑。
            </p>
          </el-collapse-item>

          <el-collapse-item name="storage-polish" title="存储弹窗细节打磨（v1.2.20）">
            <p class="intro">
              同行配对字段（「主机 + 端口」「用户名 + 密码」）标签改为自动宽度，输入框加宽至 152–216px，两个输入框之间的间距由 128px 收窄到 54px，凭据区更紧凑。
              <br /><br />
              输入框聚焦时的提示环整体移除，输入过程中不再显示彩色描边；悬停的浅色边框保留。
              <br /><br />
              SFTP 的连接字段与 FTP 统一为「主机 + 端口」「用户名 + 密码」两行配对（原为用户名与端口同行、密码单独一行），四种存储类型的连接区布局一致。
            </p>
          </el-collapse-item>

          <el-collapse-item name="modes-ui" title="备份模式三选一 · 弹窗横版化（v1.2.19）">
            <p class="intro">
              「添加 / 编辑网站」弹窗改为横版双区：左侧基本信息，右侧<b>备份模式卡片三选一</b>——轻便模式（外部压缩）、高级模式（自主压缩）、增量模式（rsync 硬链快照），选中后直接在下方配置该模式专属项，模式说明内置在卡片中。
              <br /><br />
              存储弹窗同样改为横版：类型在顶部整行选择，连接字段按「主机 + 端口」「用户名 + 密码」成组一行显示；强制备份的存储在网站弹窗中锁定展示。
              <br /><br />
              数据恢复页按「网站 + 日期」呈现<b>备份套件卡片</b>：一次备份产生的网站目录与数据库转储自动整合为一套，跨天套件按天拆分，每张卡显示可恢复文件数、总大小与恢复状态，可整套或按文件勾选恢复。
            </p>
          </el-collapse-item>

          <el-collapse-item name="browse" title="备份文件在线浏览（v1.2.18）">
            <p class="intro">
              <b>入口</b>：存储 → 操作列「浏览」；增量备份的网站 → 操作列「浏览备份」。
              <br /><br />
              弹窗内以面包屑逐级下钻远端目录，只读展示文件与子目录清单，并实时统计当前目录的递归用量（文件数 + 总大小）。加密存储由 Center 在服务端解密后列出，密钥不进入前端；S3 / WebDAV / FTP / SFTP 全类型存储均可浏览。
              <br /><br />
              配合增量备份，可以直接在后台确认每一份快照是否落盘、占用多少空间，不必再登录 VPS 执行 rclone 命令核对。
            </p>
          </el-collapse-item>

          <el-collapse-item name="incremental" title="增量备份（v1.2.17 · 需 Agent v0.15.0+）">
            <p class="intro">
              网站的第三种备份能力：<b>rsync --link-dest 硬链快照</b>。每次备份只传输变化部分，未改动的文件以硬链保留——空间占用接近一份全量，传输量却只有增量。
              <br /><br />
              <b>配置要求</b>：目标存储须为 SFTP 类型、配置 SSH 私钥、未开启加密（rsync 明文写入，与加密层不兼容）；被监控服务器需安装 rsync / ssh；Agent 版本 ≥ 0.15.0 才会识别并执行该指令。
              <br /><br />
              数据库仍每次全量导出，并随快照一同存档，恢复时网站文件与数据库保持同一时间点。网站列表可一键触发「增量备份」，并通过「浏览备份」查看快照内容。
            </p>
          </el-collapse-item>

          <el-collapse-item name="batchcode" title="备份记录按次整合（v1.2.15-v1.2.16）">
            <p class="intro">
              备份记录页按<b>任务编码（batch_code）</b>把一次备份产生的所有文件（网站 tar + 数据库 sql）整合为一张卡片，跨天套件按天拆分，恢复时勾选同一批次即可整套处理。
              <br /><br />
              详情弹窗展示每个文件的上传结果（成功/部分失败/失败及原因），点「同步」可查看 Agent 实际扫描与上传的文件数。
            </p>
          </el-collapse-item>
        </el-collapse>
      </el-tab-pane>

      <!-- ============ 一键部署 ============ -->
      <el-tab-pane name="deploy">
        <template #label>
          <span>🚀 一键部署</span>
        </template>

        <div class="tab-intro">
          在一台干净的 VPS 上跑一条命令即可完成安装。脚本会自动装 Docker、拉部署包、签证书、起容器。
        </div>

        <el-collapse>
          <el-collapse-item name="vps-compat" title="⚠ VPS 兼容性 · 必读">
            <p class="intro">
              <b>Docker 对 VPS 虚拟化类型敏感 —— 不是所有便宜 VPS 都能装。</b>
            </p>
            <table class="doc-table">
              <thead><tr><th>虚拟化</th><th>Docker</th><th>一键部署</th></tr></thead>
              <tbody>
                <tr><td>KVM</td><td>✅ 完美</td><td>✅ 默认 80/443 直接用</td></tr>
                <tr><td>XEN HVM / PV</td><td>✅ 完美</td><td>✅ 直接用</td></tr>
                <tr><td>LXC</td><td>⚠ sysctl 有限制</td><td>⚠ 脚本自动切高端口（8080/8443）</td></tr>
                <tr><td>OpenVZ 7</td><td>❌ 常见故障</td><td>❌ 脚本会拒绝，需 BC_FORCE_OPENVZ=1 硬试</td></tr>
                <tr><td>OpenVZ 6</td><td>❌ 内核太老</td><td>❌ 装不上</td></tr>
              </tbody>
            </table>
            <p class="intro">
              <b>用户装机前先跑这条检测：</b>
              <br />
              <code style="user-select: all">sudo bash -c 'systemd-detect-virt; [ -d /proc/vz ] && echo "⚠ OpenVZ" || echo "✓ 非 OpenVZ"; uname -r'</code>
              <br /><br />
              返回 <code>kvm</code> / <code>xen</code> 直接装；返回 <code>lxc</code> 脚本会自动处理；返回 OpenVZ 或 <code>/proc/vz</code> 存在则劝退。
              <br /><br />
              <b>OpenVZ 常见问题</b>：无法改 sysctl（80/443 挂）· iptables 被锁 · 存储驱动退到 vfs 极慢 · cgroup / capability 受限 · Docker daemon 可能起不来。<b>劝告用户换 KVM VPS</b>（Vultr / Hetzner / RackNerd KVM 套餐等）。
            </p>
          </el-collapse-item>

          <el-collapse-item name="cmds" title="一键部署命令">
            <p class="intro">SSH 到目标 VPS 后跑这一条：</p>
            <div class="cmd-block">
              <div class="cmd-label"><span class="chip chip-blue">🌍 安装</span></div>
              <pre class="cmd-pre">curl -fsSL https://raw.githubusercontent.com/gongpz/backup-center/main/install-scripts/bc-install.sh | sudo bash</pre>
            </div>
            <p class="intro">
              部署包默认从本项目的 <b>GitHub Release</b> 拉取。想换成自建镜像源，用 <code>BC_DEPLOY_URL</code> 覆盖（见下方环境变量表）。
            </p>
          </el-collapse-item>

          <el-collapse-item name="interact" title="交互式部署流程（3 步）">
            <p class="intro">
              执行命令后脚本自动走 3 步询问：<br /><br />
              <b>第 1 步 · 访问地址</b> —— 输入域名（如 <code>bak.example.com</code>）或 IP（如 <code>1.2.3.4</code>），脚本自动识别<br />
              <b>第 2 步 · SSL 证书方式</b> —— 域名会问"Let's Encrypt HTTPS / HTTP-only"；IP 会问"自签名 HTTPS / HTTP-only"<br />
              <b>第 3 步 · LE 邮箱</b> —— 只有选 Let's Encrypt 时才问<br /><br />
              汇总确认后 8 步自动执行：端口检查 → Docker 装 → 拉部署包 → 签证书 → 展开 → APP_KEY → 起容器 → 装 certbot 续期钩子 → 冒烟测试
            </p>
          </el-collapse-item>

          <el-collapse-item name="envvars" title="环境变量（跳过交互）">
            <table class="doc-table">
              <thead><tr><th>变量</th><th>说明</th></tr></thead>
              <tbody>
                <tr><td><code>BC_ACCESS</code></td><td>访问地址（域名或 IP）</td></tr>
                <tr><td><code>BC_EMAIL</code></td><td>LE 通知邮箱（域名 + LE 时必填）</td></tr>
                <tr><td><code>BC_HTTP_ONLY</code></td><td>设 <code>1</code> 强制 HTTP-only</td></tr>
                <tr><td><code>BC_DEPLOY_URL</code></td><td>覆盖默认部署包 URL（用自己 R2 时用）</td></tr>
                <tr><td><code>BC_FORCE</code></td><td>设 <code>1</code> 覆盖已有安装</td></tr>
              </tbody>
            </table>
          </el-collapse-item>

          <el-collapse-item name="agent-publish" title="发布新 Agent 版本">
            <p class="intro">
              交叉编译 Agent 二进制 → 替换 <code>/opt/backup-center/agent-dist/backup-agent-linux-{amd64,arm64}</code> → 重打 <code>bc-deploy.tar.gz</code> → 上传到 GitHub Release。
              <br /><br />
              <b>本机 Agent 升级</b>：<code>cp /opt/backup-center/agent-dist/backup-agent-linux-amd64 /opt/backup-agent/backup-agent &amp;&amp; systemctl restart backup-agent</code>
              <br />
              <b>远程 Agent 升级</b>：网页点服务器详情里的"升级"按钮，Agent 从设定的 mirror URL 拉新版
            </p>
          </el-collapse-item>

          <el-collapse-item name="uninstall" title="🗑 一键卸载脚本">
            <p class="intro">
              不想再用了？一条命令卸干净：
            </p>
            <div class="cmd-block">
              <div class="cmd-label"><span class="chip chip-red">卸载（交互式）</span></div>
              <pre class="cmd-pre">curl -fsSL https://raw.githubusercontent.com/gongpz/backup-center/main/install-scripts/bc-uninstall.sh | sudo bash</pre>
            </div>
            <p class="intro">
              <b>脚本会做的事</b>：
              <br />
              &nbsp;&nbsp;· 自动检测已装组件（容器/卷/镜像/网络/systemd/LE 证书/swap）
              <br />
              &nbsp;&nbsp;· 问用户"保留数据库？删 LE 证书？删 swap？"（默认都保留敏感资产）
              <br />
              &nbsp;&nbsp;· 停容器 → 删镜像 → 删卷 → 删 systemd → 删 <code>/opt/bc</code>
              <br />
              &nbsp;&nbsp;· <b>不会</b>动 Docker 本身（可能你别的东西还用着）
              <br /><br />
              <b>自动化选项</b>（环境变量）：
            </p>
            <table class="doc-table">
              <thead><tr><th>变量</th><th>说明</th></tr></thead>
              <tbody>
                <tr><td><code>BC_KEEP_DATA=1</code></td><td>保留数据库 + storage 卷</td></tr>
                <tr><td><code>BC_REMOVE_LE=1</code></td><td>同时删 LE 证书（默认保留）</td></tr>
                <tr><td><code>BC_REMOVE_SWAP=1</code></td><td>同时删 install.sh 装的 /swapfile</td></tr>
                <tr><td><code>BC_FORCE=1</code></td><td>跳过所有确认（自动化用）</td></tr>
                <tr><td><code>BC_TARGET_DIR=/opt/bc</code></td><td>自定义安装路径</td></tr>
              </tbody>
            </table>
            <p class="intro">
              <b>示例</b>：<code>curl -fsSL https://raw.githubusercontent.com/gongpz/backup-center/main/install-scripts/bc-uninstall.sh | BC_KEEP_DATA=1 BC_FORCE=1 sudo -E bash</code>
              <br />
              保留数据库、无交互直接卸载。想重装时跑一键部署即可自动重挂原数据库。
            </p>
          </el-collapse-item>
        </el-collapse>
      </el-tab-pane>
    </el-tabs>
  </div>
</template>

<style scoped>
.help-page {
  padding-bottom: 24px;
}
.toolbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 20px;
}
.page-h {
  margin: 0;
  font-size: 20px;
  font-weight: 600;
  color: var(--bc-text-primary);
  display: flex;
  align-items: center;
  gap: 8px;
}
.page-h .el-icon { color: var(--bc-primary); }
.page-sub {
  margin: 4px 0 0;
  font-size: 12px;
  color: var(--bc-text-secondary);
}

.help-tabs {
  background: var(--bc-bg-card);
  border-radius: var(--bc-radius-lg);
  padding: 8px 20px 20px;
  border: 1px solid var(--bc-border);
  box-shadow: var(--bc-shadow-sm);
}
.tab-intro {
  padding: 14px 16px;
  margin-bottom: 12px;
  background: var(--bc-bg-inset);
  border: 1px solid var(--bc-border-light);
  border-radius: var(--bc-radius);
  font-size: 13px;
  color: var(--bc-text-regular);
  line-height: 1.7;
}
.tab-intro b { color: var(--bc-text-primary); }
.muted { color: var(--bc-text-secondary); }

.col-title {
  display: flex;
  align-items: center;
  gap: 10px;
}
.col-title .strong {
  font-size: 14px;
  font-weight: 500;
  color: var(--bc-text-primary);
}
.chip {
  display: inline-flex;
  align-items: center;
  padding: 1px 12px;
  border-radius: 999px;
  font-size: 11px;
  font-weight: 500;
  letter-spacing: 0.2px;
  line-height: 1.6;
  white-space: nowrap;
}
.chip-blue    { background: var(--bc-primary-light); color: var(--bc-primary); border: 1px solid var(--bc-primary-100); }
.chip-purple  { background: var(--bc-info-bg); color: var(--bc-info); border: 1px solid var(--bc-info-bg); }
.chip-red     { background: var(--bc-danger-bg); color: var(--bc-danger); border: 1px solid var(--bc-danger-border); }
.chip-s3      { background: var(--bc-primary-light); color: var(--bc-primary); border: 1px solid var(--bc-primary-100); }
.chip-sftp    { background: var(--bc-info-bg); color: var(--bc-info); border: 1px solid var(--bc-info-bg); }
.chip-webdav  { background: rgba(46, 155, 118, .14); color: var(--bc-chart-5); border: 1px solid rgba(46, 155, 118, .22); }
.doc-table { width: 100%; border-collapse: collapse; margin: 8px 0; font-size: 13px; }
.doc-table th, .doc-table td { padding: 6px 10px; border: 1px solid var(--bc-border-light); text-align: left; }
.doc-table th { background: var(--bc-bg-page); font-weight: 600; }
.doc-table code { background: var(--bc-bg-inset); padding: 1px 4px; border-radius: 3px; font-size: 12px; }
.cmd-block { margin: 8px 0; }
.cmd-label { margin-bottom: 4px; }
.cmd-pre { background: var(--bc-bg-page); border: 1px solid var(--bc-border-light); border-radius: 6px; padding: 10px 12px; font-family: 'JetBrains Mono', ui-monospace, Consolas, monospace; font-size: 12px; overflow-x: auto; margin: 0; }
.link { color: var(--bc-primary); text-decoration: underline; }

.intro {
  margin: 4px 0 12px;
  font-size: 13px;
  color: var(--bc-text-regular);
  line-height: 1.7;
}
.intro code, .steps code {
  font-family: 'JetBrains Mono', ui-monospace, Consolas, monospace;
  font-size: 12px;
  padding: 1px 6px;
  background: var(--bc-border-light);
  border-radius: 4px;
  color: var(--bc-text-primary);
}

.steps {
  margin: 4px 0 12px;
  padding-left: 20px;
  font-size: 13px;
  color: var(--bc-text-regular);
  line-height: 1.9;
}
.steps b { color: var(--bc-text-primary); }

.kv-table {
  width: 100%;
  border-collapse: collapse;
  margin-top: 6px;
  font-size: 13px;
}
.kv-table td {
  padding: 8px 10px;
  border-bottom: 1px solid var(--bc-border-light);
  vertical-align: top;
}
.kv-table tr:last-child td { border-bottom: none; }
.kv-table .k {
  width: 30%;
  color: var(--bc-text-secondary);
  font-weight: 500;
  white-space: nowrap;
}
.kv-table .v {
  color: var(--bc-text-primary);
}
.mono {
  font-family: 'JetBrains Mono', ui-monospace, Consolas, monospace;
  font-size: 12px;
  color: var(--bc-text-primary);
}

.tip {
  margin-top: 12px;
  padding: 10px 12px;
  background: var(--bc-warning-bg);
  border: 1px solid var(--bc-warning-border);
  border-radius: var(--bc-radius);
  font-size: 12px;
  color: var(--bc-warning);
  line-height: 1.7;
  white-space: pre-line;
}

.code-box {
  position: relative;
  background: var(--bc-bg-page);
  border: 1px solid var(--bc-border-light);
  border-radius: var(--bc-radius);
  padding: 12px 14px;
  margin: 6px 0;
}
.code-box pre {
  margin: 0;
  font-family: 'JetBrains Mono', ui-monospace, Consolas, monospace;
  font-size: 12px;
  color: var(--bc-text-primary);
  white-space: pre-wrap;
  word-break: break-all;
  line-height: 1.7;
  padding-right: 70px;
}
.code-box .el-button {
  position: absolute;
  top: 8px;
  right: 8px;
}
</style>
