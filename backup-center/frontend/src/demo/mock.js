/**
 * 演示模式（demo mode）· 浏览器内 mock 数据层
 *
 * 只在构建期环境变量 VITE_DEMO=1 时生效：src/utils/http.js 用动态 import 把本模块
 * 注册成 axios adapter，因此生产构建会把它判为死代码，不会打进产物。
 *
 * 所有写操作（增/改/删）都落在内存数组上，刷新页面即还原，方便反复演示。
 */
const GB = 1024 ** 3
const MB = 1024 ** 2

const NOW = Date.now()
const mins = (m) => new Date(NOW - m * 60000).toISOString()
const hours = (h) => new Date(NOW - h * 3600000).toISOString()

/* ============================================================
   仪表盘分桶逻辑（与后端 DashboardController::buildBuckets 同构）
   day=12×2h / week=7×逐日 / month=4×周 / 季半年年=月桶
   ============================================================ */
const RANGE_CFG = {
  day: { label: '近 24 小时' },
  week: { label: '近 7 天' },
  month: { label: '近 30 天' },
  quarter: { label: '近 3 个月' },
  halfyear: { label: '近 6 个月' },
  year: { label: '近 12 个月' },
}
const RANGE_STATS = {
  day: { period_success: 42, period_failed: 1, period_size: Math.round(3.2 * GB), period_count: 43, change: { success: 4.8, failed: -33.3, size: 2.1, count: 4.7 } },
  week: { period_success: 268, period_failed: 4, period_size: Math.round(21 * GB), period_count: 272, change: { success: 8.4, failed: -12.5, size: 15.2, count: 6.1 } },
  month: { period_success: 1284, period_failed: 17, period_size: Math.round(45 * GB), period_count: 1301, change: { success: 6.9, failed: -8.3, size: 11.4, count: 5.2 } },
  quarter: { period_success: 3902, period_failed: 61, period_size: Math.round(138 * GB), period_count: 3963, change: { success: 5.2, failed: -4.6, size: 9.4, count: 3.8 } },
  halfyear: { period_success: 7654, period_failed: 128, period_size: Math.round(271 * GB), period_count: 7782, change: { success: 6.7, failed: 3.1, size: 12.6, count: 5.5 } },
  year: { period_success: 15832, period_failed: 246, period_size: Math.round(612 * GB), period_count: 16078, change: { success: 11.2, failed: -4.6, size: 18.3, count: 8.9 } },
}

const startOfHour = (d) => { d.setMinutes(0, 0, 0); return d }
const startOfDay = (d) => { d.setHours(0, 0, 0, 0); return d }
const startOfWeek = (d) => { d.setDate(d.getDate() - ((d.getDay() + 6) % 7)); return startOfDay(d) }
const seeded = (i, salt) => { const x = Math.sin(i * 127.1 + salt * 311.7) * 43758.5453; return x - Math.floor(x) }

function buildBuckets(range) {
  const n = new Date(NOW)
  const buckets = []
  if (range === 'day') {
    const start = startOfHour(new Date(n.getTime() - 22 * 3600000))
    for (let i = 0; i < 12; i++) {
      const s = new Date(start.getTime() + i * 2 * 3600000)
      buckets.push({
        label: String(s.getHours()),
        count: Math.round(2 + seeded(i, 2) * 4),
        size: Math.round((0.2 + seeded(i, 6) * 0.5) * GB),
      })
    }
  } else if (range === 'week') {
    const start = startOfDay(new Date(n.getTime() - 6 * 86400000))
    const weekMap = ['一', '二', '三', '四', '五', '六', '日']
    for (let i = 0; i < 7; i++) {
      const s = new Date(start.getTime() + i * 86400000)
      const weekend = s.getDay() === 0 || s.getDay() === 6
      buckets.push({
        label: '周' + weekMap[(s.getDay() + 6) % 7],
        count: Math.round((30 + seeded(i, 1) * 20) * (weekend ? 1.3 : 1)),
        size: Math.round((1.2 + seeded(i, 5) * 2.6) * GB),
      })
    }
  } else if (range === 'month') {
    const start = startOfWeek(new Date(n.getTime() - 21 * 86400000))
    for (let i = 0; i < 4; i++) {
      buckets.push({
        label: `第${i + 1}周`,
        count: Math.round(240 + seeded(i, 3) * 120),
        size: Math.round((9 + seeded(i, 7) * 8) * GB),
      })
    }
  } else {
    const months = range === 'quarter' ? 3 : range === 'halfyear' ? 6 : 12
    const start = startOfDay(new Date(n.getFullYear(), n.getMonth() - (months - 1), 1))
    for (let i = 0; i < months; i++) {
      const s = new Date(start.getFullYear(), start.getMonth() + i, 1)
      buckets.push({
        label: `${s.getMonth() + 1}月`,
        count: Math.round(900 + seeded(i, 4) * 500),
        size: Math.round((38 + seeded(i, 8) * 25) * GB),
      })
    }
  }
  return buckets
}

/* ============================================================
   内存状态（演示数据）
   ============================================================ */
const randToken = (n = 32) => {
  const chars = 'abcdefghijklmnopqrstuvwxyz0123456789'
  return Array.from({ length: n }, () => chars[Math.floor(Math.random() * chars.length)]).join('')
}

// 提前声明：db.websites 的顶层初始化 IIFE（第 ~255 行）会调用二者，
// 若定义在其后会在模块直引（如 node 校验）时触发 TDZ ReferenceError。
const nextId = (key) => db.seq[key]++
const findById = (list, id) => list.find((x) => String(x.id) === String(id))

/* ---------- 存储加密（config.encryption）演示语义 ----------
   与后端 Storage::safeConfig / StorageController 对齐：
   - 列表/详情返回掩码形状：password 已设置则一律返回 ••••••••，永不明文下发
   - 增/改时 password 为空或占位符 •••••••• = 不修改，保留库中已存值 */
const ENC_PASSWORD_MASK = '••••••••'
const ENC_DEFAULTS = { enabled: false, key_mode: 'center', password: '', filename_encryption: 'standard', directory_name_encryption: true }

const safeStorageConfig = (config) => {
  if (!config?.encryption) return config
  const enc = { ...config.encryption }
  if (enc.password) enc.password = ENC_PASSWORD_MASK
  return { ...config, encryption: enc }
}
const safeStorage = (s) => (s ? {
  ...s,
  // 对齐 StorageResource（v1.2.17）：增量备份目标筛选用的布尔脱敏字段，不泄露任何密钥内容
  private_key_set: !!s.config?.private_key,
  encryption_enabled: !!s.config?.encryption?.enabled,
  config: safeStorageConfig(s.config),
} : s)
const resolveEncryption = (enc, existing) => {
  const next = { ...ENC_DEFAULTS, ...(enc || {}) }
  if (!next.password || next.password === ENC_PASSWORD_MASK) {
    next.password = existing?.password || ''
  }
  return next
}

const db = {
  // log 序号必须大于静态数据最大 id（15843）：P2 起静态记录直接写死 15823–15843，
  // 早期 seq=15833 会让预置自主备份日志与静态记录撞号（db.logs 出现重复 id，
  // 备份记录表 row-key="id" 行状态串扰、findById 解析到错误条目）。
  seq: { server: 7, website: 9, storage: 6, log: 15844, pool: 2, cmd: 100 },
  latestAgentVersion: '0.15.4',

  user: { id: 1, name: '张管理员', username: 'admin', email: 'admin@example.com', role: 'admin' },

  servers: [
    // agent_version 故意落后一个版本：让「升级 Agent」按钮与升级中 → 成功的状态流转可被演示
    { id: 1, name: 'hk-prod-01', remark: '香港 · 生产主节点', connect_type: 'agent', ip: '45.157.179.31', os_type: 'Debian 12', status: 'online', cpu_usage: 34, mem_usage: 61, disk_usage: 72, agent_version: '0.13.2', agent_token: randToken(), hostname: 'hk-prod-01', kernel: '6.1.0-18-amd64', arch: 'x86_64', pending_agent_command: null, last_seen_at: mins(1), disks: [{ mount: '/', fs: 'ext4', used: 260 * GB, total: 360 * GB, available: 100 * GB }, { mount: '/data', fs: 'xfs', used: 580 * GB, total: 1000 * GB, available: 420 * GB }] },
    { id: 2, name: 'sg-db-02', remark: '新加坡 · 数据库集群', connect_type: 'agent', ip: '128.199.64.10', os_type: 'Ubuntu 22.04', status: 'online', cpu_usage: 58, mem_usage: 74, disk_usage: 81, agent_version: '0.14.0', agent_token: randToken(), hostname: 'sg-db-02', kernel: '5.15.0-91-generic', arch: 'x86_64', pending_agent_command: null, last_seen_at: mins(1), disks: [{ mount: '/', fs: 'ext4', used: 648 * GB, total: 800 * GB, available: 152 * GB }] },
    { id: 3, name: 'jp-edge-03', remark: '东京 · 边缘节点', connect_type: 'ssh', ip: '133.242.8.90', os_type: 'AlmaLinux 9', status: 'online', cpu_usage: 12, mem_usage: 28, disk_usage: 35, agent_version: null, agent_token: null, hostname: 'jp-edge-03', kernel: null, arch: null, ssh_port: 22, ssh_user: 'root', has_ssh_key: true, backup_path: '/www/backup', pending_agent_command: null, last_seen_at: mins(3), disks: [] },
    { id: 4, name: 'us-backup-04', remark: '美西 · 冷备', connect_type: 'agent', ip: '172.105.33.7', os_type: 'Debian 11', status: 'offline', cpu_usage: 0, mem_usage: 0, disk_usage: 44, agent_version: '0.13.0', agent_token: randToken(), hostname: 'us-backup-04', kernel: '5.10.0-26-amd64', arch: 'x86_64', pending_agent_command: null, last_seen_at: mins(184), disks: [{ mount: '/', fs: 'ext4', used: 352 * GB, total: 800 * GB, available: 448 * GB }] },
    { id: 5, name: 'cn-relay-05', remark: '上海 · 中转', connect_type: 'ssh', ip: '101.87.22.5', os_type: 'Rocky 9', status: 'pending', cpu_usage: 0, mem_usage: 0, disk_usage: 0, agent_version: null, agent_token: null, hostname: null, kernel: null, arch: null, ssh_port: 22, ssh_user: 'root', has_ssh_key: false, backup_path: '', pending_agent_command: null, last_seen_at: null, disks: [] },
    { id: 6, name: 'de-monitor-06', remark: '法兰克福 · 监控', connect_type: 'agent', ip: '49.12.66.201', os_type: 'Debian 12', status: 'error', cpu_usage: 0, mem_usage: 0, disk_usage: 66, agent_version: '0.14.0', agent_token: randToken(), hostname: 'de-monitor-06', kernel: '6.1.0-18-amd64', arch: 'x86_64', pending_agent_command: null, last_seen_at: mins(9), disks: [{ mount: '/', fs: 'ext4', used: 132 * GB, total: 200 * GB, available: 68 * GB }] },
  ],

  websites: [
    { id: 1, name: '官网主站', domain: 'www.example.com', server_id: 1, server: { name: 'hk-prod-01', connect_type: 'agent' }, backup_paths: [{ label: '站点根目录', path: '/var/www/html', pattern: '' }, { label: 'Nginx 配置', path: '/etc/nginx', pattern: '' }], recent_backups: [{ log: mkLog(15832, '官网主站', 'hk-prod-01', 2.4 * GB, 'success', 4) }], schedule: '0 2 * * *', sync_mode: 'auto', retention_days: 30, status: 'enabled', remark: '对外主站（增量备份演示）', storage_ids: [1, 2], backup_source: 'self', backup_mode: 'incremental', incremental_storage_id: 5, restore_mode: 'lite', bind_token: randToken(), web_root: '/var/www/html', root_bound_at: hours(72), db_type: 'mysql', db_host: '127.0.0.1', db_port: 3306, db_name: 'main_site', db_user: 'root', db_password_set: true },
    { id: 2, name: '客户门户', domain: 'portal.example.com', server_id: 1, server: { name: 'hk-prod-01', connect_type: 'agent' }, backup_paths: [{ label: '备份目录1', path: '/srv/portal', pattern: '' }], recent_backups: [{ log: mkLog(15831, '客户门户', 'hk-prod-01', 1.8 * GB, 'success', 9) }], schedule: '0 3 * * *', sync_mode: 'auto', retention_days: 60, status: 'enabled', remark: '', storage_ids: [1], backup_source: 'external', backup_mode: 'full', restore_mode: 'lite', bind_token: randToken(), web_root: '/srv/portal', root_bound_at: hours(96), db_type: null, db_host: null, db_port: null, db_name: null, db_user: null, db_password_set: false },
    { id: 3, name: '电商平台', domain: 'shop.example.com', server_id: 2, server: { name: 'sg-db-02', connect_type: 'agent' }, backup_paths: [{ label: '商品数据', path: '/data/shop', pattern: '' }, { label: 'MySQL 转储', path: '/data/mysql', pattern: '*.sql.gz' }], recent_backups: [{ log: mkLog(15830, '电商平台', 'sg-db-02', 5.1 * GB, 'failed', 15) }], schedule: '0 */6 * * *', sync_mode: 'scheduled', retention_days: 90, status: 'enabled', remark: '核心业务', storage_ids: [1, 2, 3], backup_source: 'external', backup_mode: 'full', restore_mode: 'advanced', bind_token: randToken(), web_root: '/var/www/eshop', root_bound_at: hours(48), db_type: 'mysql', db_host: '127.0.0.1', db_port: 3306, db_name: 'shop_main', db_user: 'root', db_password_set: true },
    { id: 4, name: '数据中台', domain: 'data.example.internal', server_id: 2, server: { name: 'sg-db-02', connect_type: 'agent' }, backup_paths: [{ label: '数仓', path: '/data/warehouse', pattern: '' }], recent_backups: [{ log: mkLog(15829, '数据中台', 'sg-db-02', 3.6 * GB, 'success', 22) }], schedule: '30 1 * * 1', sync_mode: 'auto', retention_days: 180, status: 'enabled', remark: '', storage_ids: [2], backup_source: 'external', backup_mode: 'full', restore_mode: 'lite', bind_token: randToken(), web_root: '/data/warehouse', root_bound_at: hours(120), db_type: null, db_host: null, db_port: null, db_name: null, db_user: null, db_password_set: false },
    { id: 5, name: '营销落地页', domain: 'lp.example.com', server_id: 3, server: { name: 'jp-edge-03', connect_type: 'ssh' }, backup_paths: [{ label: '页面静态资源', path: '/usr/share/nginx/lp', pattern: '' }], recent_backups: [{ log: mkLog(15828, '营销落地页', 'jp-edge-03', 420 * MB, 'running', 26) }], schedule: '0 4 * * *', sync_mode: 'auto', retention_days: 14, status: 'enabled', remark: '', storage_ids: [2], backup_source: 'external', backup_mode: 'full', restore_mode: 'lite', bind_token: randToken(), web_root: null, root_bound_at: null, db_type: null, db_host: null, db_port: null, db_name: null, db_user: null, db_password_set: false },
    { id: 6, name: '内部 OA', domain: 'oa.example.internal', server_id: 1, server: { name: 'hk-prod-01', connect_type: 'agent' }, backup_paths: [{ label: 'OA 程序目录', path: '/opt/oa', pattern: '' }], recent_backups: [{ log: mkLog(15827, '内部 OA', 'hk-prod-01', 900 * MB, 'success', 38) }], schedule: '0 5 * * *', sync_mode: 'auto', retention_days: 30, status: 'enabled', remark: '自主压缩演示（高级模式）', storage_ids: [1], backup_source: 'self', backup_mode: 'full', restore_mode: 'lite', bind_token: randToken(), web_root: '/opt/oa', root_bound_at: hours(30), db_type: 'mysql', db_host: '127.0.0.1', db_port: 3306, db_name: 'oa_main', db_user: 'root', db_password_set: true },
    { id: 7, name: 'API 网关', domain: 'api.example.com', server_id: 3, server: { name: 'jp-edge-03', connect_type: 'ssh' }, backup_paths: [{ label: 'Kong 配置', path: '/etc/kong', pattern: '' }], recent_backups: [{ log: mkLog(15826, 'API 网关', 'jp-edge-03', 260 * MB, 'success', 52) }], schedule: '0 6 * * *', sync_mode: 'auto', retention_days: 30, status: 'enabled', remark: '', storage_ids: [2], backup_source: 'external', backup_mode: 'full', restore_mode: 'lite', bind_token: randToken(), web_root: null, root_bound_at: null, db_type: null, db_host: null, db_port: null, db_name: null, db_user: null, db_password_set: false },
    { id: 8, name: '旧版官网', domain: 'old.example.com', server_id: 4, server: { name: 'us-backup-04', connect_type: 'agent' }, backup_paths: [{ label: '旧站目录', path: '/var/www/old', pattern: '' }], recent_backups: [{ log: mkLog(15825, '旧版官网', 'us-backup-04', 1.2 * GB, 'success', 320) }], schedule: '0 2 * * 0', sync_mode: 'auto', retention_days: 7, status: 'disabled', remark: '已下线，保留归档', storage_ids: [1], backup_source: 'external', backup_mode: 'full', restore_mode: 'lite', bind_token: randToken(), web_root: null, root_bound_at: null, db_type: null, db_host: null, db_port: null, db_name: null, db_user: null, db_password_set: false },
  ],

  storages: [
    { id: 1, name: 'Hetzner 主存储', type: 'sftp', status: 'enabled', is_mandatory: true, quota_bytes: 500 * GB, used_bytes: 318 * GB, websites_count: 16, upload_count: 9820, base_path: '/backups/primary', last_test_status: 'ok', last_tested_at: mins(35), access_url: 'https://u12345.your-storagebox.de', remark: '欧洲主力存储盒', config: { host: 'u12345.your-storagebox.de', port: 23, user: 'u12345', password: '', encryption: { enabled: true, key_mode: 'agent_local', password: '', filename_encryption: 'standard', directory_name_encryption: true } } },
    { id: 2, name: 'Backblaze B2', type: 's3', status: 'enabled', is_mandatory: false, quota_bytes: 1000 * GB, used_bytes: 286 * GB, websites_count: 18, upload_count: 7412, base_path: 'bc-backups', last_test_status: 'ok', last_tested_at: mins(120), access_url: '', remark: '异地对象存储', config: { endpoint: 'https://s3.us-west-000.backblazeb2.com', region: 'us-west-000', bucket: 'bc-backups', access_key_id: '000b2c3d4e5f6000', secret_access_key: '', encryption: { enabled: true, key_mode: 'center', password: 'b2-demo-enc-pass', filename_encryption: 'standard', directory_name_encryption: true } } },
    { id: 3, name: 'Nextcloud 异地', type: 'webdav', status: 'enabled', is_mandatory: false, quota_bytes: 300 * GB, used_bytes: 264 * GB, websites_count: 9, upload_count: 3180, base_path: '/remote.php/dav', last_test_status: 'failed', last_tested_at: mins(280), access_url: 'https://cloud.example.com', remark: '最近连接异常', config: { url: 'https://cloud.example.com/remote.php/dav', vendor: 'nextcloud', user: 'backup', password: '' } },
    { id: 4, name: '本地 NAS', type: 'ftp', status: 'disabled', is_mandatory: false, quota_bytes: 0, used_bytes: 96 * GB, websites_count: 0, upload_count: 640, base_path: '/volume1/backup', last_test_status: 'ok', last_tested_at: mins(1440), access_url: '', remark: '机房内网', config: { host: '10.0.0.9', port: 21, user: 'backup', password: '' } },
    // v1.2.17 增量备份演示目标：SFTP + SSH 私钥 + 未加密（满足 incremental_storage_id 筛选条件）
    { id: 5, name: 'VPS 增量存储', type: 'sftp', status: 'enabled', is_mandatory: false, quota_bytes: 200 * GB, used_bytes: 96 * GB, websites_count: 1, upload_count: 1240, base_path: '/backup', last_test_status: 'ok', last_tested_at: mins(50), access_url: '', remark: 'rsync 硬链快照目标（SSH 私钥）', config: { host: 'vps.example.com', port: 22, user: 'backup', private_key: 'demo-private-key-placeholder', encryption: { enabled: false, key_mode: 'center', password: '', filename_encryption: 'standard', directory_name_encryption: true } } },
  ],

  logs: [],
  // 演示指令登记表：cmdId → { status, output }，供 GET /agent-commands/{id} 轮询
  commands: {},
  pools: [
    { id: 1, name: '默认资源池（腾讯云 COS）', provider: 'tencent_cos', endpoint: 'cos.ap-guangzhou.myqcloud.com', region: 'ap-guangzhou', bucket: 'bc-resource-1258000000', public_domain: '', public_url_prefix: 'https://bc-resource-1258000000.cos.ap-guangzhou.myqcloud.com', is_agent_mirror: true, status: 'enabled', has_credentials: true, last_test_status: 'ok', remark: 'Agent 二进制国内镜像' },
  ],
  files: {
    1: [
      { name: 'backup-agent-linux-amd64', path: 'agent/backup-agent-linux-amd64', size: 18.4 * MB, modified: hours(26), remark: 'Agent v0.14.0 主程序（Linux x86_64）', public_url: 'https://bc-resource-1258000000.cos.ap-guangzhou.myqcloud.com/agent/backup-agent-linux-amd64' },
      { name: 'backup-agent-linux-arm64', path: 'agent/backup-agent-linux-arm64', size: 17.1 * MB, modified: hours(26), remark: '', public_url: 'https://bc-resource-1258000000.cos.ap-guangzhou.myqcloud.com/agent/backup-agent-linux-arm64' },
      { name: 'install.sh', path: 'agent/install.sh', size: 4.2 * 1024, modified: hours(26), remark: '一键安装脚本', public_url: 'https://bc-resource-1258000000.cos.ap-guangzhou.myqcloud.com/agent/install.sh' },
    ],
  },

  notification: {
    has_sendkey: true,
    sendkey_hint: 'SCT••••••••••••9f3a',
    notify_backup_success: false,
    notify_backup_failed: true,
    notify_agent_offline: true,
    updated_at: mins(2880),
  },

  settings: {
    system_name: 'backup center',
    agent_mirror_url: 'https://bc-resource-1258000000.cos.ap-guangzhou.myqcloud.com/agent',
    agent_mirror_url_r2: 'https://mirror.example.com/agent',
    system_backup_enabled: true,
    system_backup_time: '03:00',
    footer_config: {
      enabled: true,
      copyright: '© backup center',
      disclaimer: '本站为演示环境，全部数据均为示例数据，不代表任何真实业务。',
      custom_html: '',
      show_versions: true,
      show_stats: true,
      icp_beian: '',
      contacts: [
        { type: 'github', value: 'https://github.com/example/backup-center', label: 'GitHub' },
        { type: 'email', value: 'admin@example.com', label: '联系管理员' },
      ],
    },
  },

  loginLogs: [
    { id: 1, created_at: mins(12), ip_address: '203.0.113.24', success: true, message: '登录成功' },
    { id: 2, created_at: mins(190), ip_address: '203.0.113.24', success: true, message: '登录成功' },
    { id: 3, created_at: mins(640), ip_address: '198.51.100.7', success: false, message: '密码错误' },
    { id: 4, created_at: mins(1520), ip_address: '203.0.113.24', success: true, message: '登录成功' },
  ],
}

/** 生成一条「最近备份」记录（网站卡片 + 备份记录列表共用） */
function mkLog(id, websiteName, serverName, size, status, minutesAgo, duration) {
  const at = mins(minutesAgo)
  const ok = status === 'success'
  const failed = status === 'failed'
  return {
    id,
    status,
    size,
    backup_at: at,
    synced_at: at,
    created_at: at,
    duration_seconds: duration || Math.round(size / (28 * MB)),
    error_message: failed ? 'rclone: 远端目录不可写（permission denied）' : null,
    succeeded: ok ? [{ storage_name: 'Hetzner 主存储' }, { storage_name: 'Backblaze B2' }] : [],
    failed: failed ? [{ storage_name: 'Nextcloud 异地', error: 'permission denied' }] : [],
  }
}

// 备份记录列表（最近 10 条与仪表盘一致，其余补齐到 20 条便于翻页/筛选演示）
db.logs = [
  { ...mkLog(15832, '官网主站', 'hk-prod-01', 2.4 * GB, 'success', 4, 96), website: { name: '官网主站', domain: 'www.example.com' }, server: { name: 'hk-prod-01' }, website_id: 1, server_id: 1, filename: 'html_20261013_020001.tar.gz', full_path: '/var/www/backup/html_20261013_020001.tar.gz', upload_status: 'uploaded' },
  { ...mkLog(15831, '客户门户', 'hk-prod-01', 1.8 * GB, 'success', 9, 71), website: { name: '客户门户', domain: 'portal.example.com' }, server: { name: 'hk-prod-01' }, website_id: 2, server_id: 1, filename: 'portal_20261013_030002.tar.gz', full_path: '/srv/portal/backup/portal_20261013_030002.tar.gz', upload_status: 'uploaded' },
  { ...mkLog(15830, '电商平台', 'sg-db-02', 5.1 * GB, 'failed', 15, 183), website: { name: '电商平台', domain: 'shop.example.com' }, server: { name: 'sg-db-02' }, website_id: 3, server_id: 2, filename: 'shop_20261013_040000.tar.gz', full_path: '/data/shop/backup/shop_20261013_040000.tar.gz', upload_status: 'upload_failed' },
  { ...mkLog(15829, '数据中台', 'sg-db-02', 3.6 * GB, 'success', 22, 142), website: { name: '数据中台', domain: 'data.example.internal' }, server: { name: 'sg-db-02' }, website_id: 4, server_id: 2, filename: 'warehouse_20261013_013000.tar.gz', full_path: '/data/warehouse/backup/warehouse_20261013_013000.tar.gz', upload_status: 'uploaded' },
  { ...mkLog(15828, '营销落地页', 'jp-edge-03', 420 * MB, 'running', 26, 28), website: { name: '营销落地页', domain: 'lp.example.com' }, server: { name: 'jp-edge-03' }, website_id: 5, server_id: 3, filename: 'lp_20261013_040000.zip', full_path: '/usr/share/nginx/lp/backup/lp_20261013_040000.zip', upload_status: 'uploading' },
  { ...mkLog(15827, '内部 OA', 'hk-prod-01', 900 * MB, 'success', 38, 54), website: { name: '内部 OA', domain: 'oa.example.internal' }, server: { name: 'hk-prod-01' }, website_id: 6, server_id: 1, filename: 'oa_20261013_050000.tar.gz', full_path: '/opt/oa/backup/oa_20261013_050000.tar.gz', upload_status: 'uploaded' },
  { ...mkLog(15826, 'API 网关', 'jp-edge-03', 260 * MB, 'success', 52, 19), website: { name: 'API 网关', domain: 'api.example.com' }, server: { name: 'jp-edge-03' }, website_id: 7, server_id: 3, filename: 'kong_20261013_060000.tar.gz', full_path: '/etc/kong/backup/kong_20261013_060000.tar.gz', upload_status: 'uploaded' },
  { ...mkLog(15825, '旧版官网', 'us-backup-04', 1.2 * GB, 'success', 320, 66), website: { name: '旧版官网', domain: 'old.example.com' }, server: { name: 'us-backup-04' }, website_id: 8, server_id: 4, filename: 'old_20261012_020000.tar.gz', full_path: '/var/www/old/backup/old_20261012_020000.tar.gz', upload_status: 'uploaded' },
  { ...mkLog(15824, '客户门户', 'hk-prod-01', 1.7 * GB, 'success', 96, 68), website: { name: '客户门户', domain: 'portal.example.com' }, server: { name: 'hk-prod-01' }, website_id: 2, server_id: 1, filename: 'portal_20261012_030001.tar.gz', full_path: '/srv/portal/backup/portal_20261012_030001.tar.gz', upload_status: 'uploaded' },
  { ...mkLog(15823, '数据中台', 'sg-db-02', 3.4 * GB, 'success', 128, 138), website: { name: '数据中台', domain: 'data.example.internal' }, server: { name: 'sg-db-02' }, website_id: 4, server_id: 2, filename: 'warehouse_20261012_013000.tar.gz', full_path: '/data/warehouse/backup/warehouse_20261012_013000.tar.gz', upload_status: 'uploaded' },
  /* P2 数据恢复演示：补齐「同一天一套（网站目录 + 数据库转储）、跨多天」的记录 */
  { ...mkLog(15842, '官网主站', 'hk-prod-01', 2.0 * GB, 'success', 4320, 92), website: { name: '官网主站', domain: 'www.example.com' }, server: { name: 'hk-prod-01' }, website_id: 1, server_id: 1, filename: 'html_20261010_020001.tar.gz', full_path: '/var/www/backup/html_20261010_020001.tar.gz', upload_status: 'uploaded' },
  { ...mkLog(15843, '官网主站', 'hk-prod-01', 610 * MB, 'success', 4320, 44), website: { name: '官网主站', domain: 'www.example.com' }, server: { name: 'hk-prod-01' }, website_id: 1, server_id: 1, filename: 'mysql_20261010_030000.sql.gz', full_path: '/data/mysql/backup/mysql_20261010_030000.sql.gz', upload_status: 'uploaded' },
  { ...mkLog(15833, '官网主站', 'hk-prod-01', 2.1 * GB, 'success', 1440, 88), website: { name: '官网主站', domain: 'www.example.com' }, server: { name: 'hk-prod-01' }, website_id: 1, server_id: 1, filename: 'html_20261012_020001.tar.gz', full_path: '/var/www/backup/html_20261012_020001.tar.gz', upload_status: 'uploaded' },
  { ...mkLog(15834, '官网主站', 'hk-prod-01', 380 * MB, 'success', 1440, 21), website: { name: '官网主站', domain: 'www.example.com' }, server: { name: 'hk-prod-01' }, website_id: 1, server_id: 1, filename: 'nginx_20261012_020002.tar.gz', full_path: '/etc/nginx/backup/nginx_20261012_020002.tar.gz', upload_status: 'uploaded' },
  { ...mkLog(15835, '官网主站', 'hk-prod-01', 620 * MB, 'success', 1440, 51), website: { name: '官网主站', domain: 'www.example.com' }, server: { name: 'hk-prod-01' }, website_id: 1, server_id: 1, filename: 'mysql_20261012_030000.sql.gz', full_path: '/data/mysql/backup/mysql_20261012_030000.sql.gz', upload_status: 'uploaded' },
  { ...mkLog(15836, '客户门户', 'hk-prod-01', 1.6 * GB, 'success', 2880, 63), website: { name: '客户门户', domain: 'portal.example.com' }, server: { name: 'hk-prod-01' }, website_id: 2, server_id: 1, filename: 'portal_20261011_030002.tar.gz', full_path: '/srv/portal/backup/portal_20261011_030002.tar.gz', upload_status: 'uploaded' },
  { ...mkLog(15837, '客户门户', 'hk-prod-01', 240 * MB, 'success', 2880, 17), website: { name: '客户门户', domain: 'portal.example.com' }, server: { name: 'hk-prod-01' }, website_id: 2, server_id: 1, filename: 'portal_mysql_20261011_030003.sql.gz', full_path: '/data/mysql/backup/portal_mysql_20261011_030003.sql.gz', upload_status: 'uploaded' },
  { ...mkLog(15838, '电商平台', 'sg-db-02', 4.8 * GB, 'success', 1440, 171), website: { name: '电商平台', domain: 'shop.example.com' }, server: { name: 'sg-db-02' }, website_id: 3, server_id: 2, filename: 'shop_20261012_040000.tar.gz', full_path: '/data/shop/backup/shop_20261012_040000.tar.gz', upload_status: 'uploaded' },
  { ...mkLog(15839, '电商平台', 'sg-db-02', 5.2 * GB, 'success', 1440, 205), website: { name: '电商平台', domain: 'shop.example.com' }, server: { name: 'sg-db-02' }, website_id: 3, server_id: 2, filename: 'shop_mysql_20261012_040001.sql.gz', full_path: '/data/mysql/backup/shop_mysql_20261012_040001.sql.gz', upload_status: 'uploaded' },
  { ...mkLog(15840, '数据中台', 'sg-db-02', 3.2 * GB, 'success', 2880, 131), website: { name: '数据中台', domain: 'data.example.internal' }, server: { name: 'sg-db-02' }, website_id: 4, server_id: 2, filename: 'warehouse_20261011_013000.tar.gz', full_path: '/data/warehouse/backup/warehouse_20261011_013000.tar.gz', upload_status: 'uploaded' },
  { ...mkLog(15841, '数据中台', 'sg-db-02', 700 * MB, 'success', 2880, 58), website: { name: '数据中台', domain: 'data.example.internal' }, server: { name: 'sg-db-02' }, website_id: 4, server_id: 2, filename: 'warehouse_mysql_20261011_013001.sql.gz', full_path: '/data/mysql/backup/warehouse_mysql_20261011_013001.sql.gz', upload_status: 'uploaded' },
].map((l) => ({
  ...l,
  uploads: (l.succeeded || []).map((s, i) => ({
    id: l.id * 10 + i + 1,
    storage_name: s.storage_name,
    storage_type: i === 0 ? 'sftp' : 's3',
    status: 'uploaded',
    uploaded_bytes: l.size,
    duration_seconds: 40 + i * 12,
    attempts: 1,
    finished_at: l.synced_at,
    error_message: null,
    remote_path: `/backups/${l.website.name}/${l.filename}`,
  })).concat((l.failed || []).map((f, i) => ({
    id: l.id * 10 + 50 + i,
    storage_name: f.storage_name,
    storage_type: 'webdav',
    status: 'failed',
    uploaded_bytes: 0,
    duration_seconds: 12,
    attempts: 3,
    finished_at: l.synced_at,
    error_message: f.error,
    remote_path: null,
  }))),
}))

// 演示用：官网主站（self 来源）预置两条自主备份，点亮「最近备份」里的 recent_self_backups
;(() => {
  const w = findById(db.websites, 1)
  if (w && w.backup_source === 'self') {
    const addSelfLog = (filename, size, batchCode) => {
      const id = nextId('log')
      const at = hours(2)
      db.logs.unshift({
        ...mkLog(id, w.name, w.server?.name, size, 'success', 120, 60),
        id,
        website: { name: w.name, domain: w.domain },
        server: w.server,
        website_id: w.id,
        server_id: w.server_id,
        ...(batchCode ? { batch_code: batchCode } : {}),
        filename,
        full_path: `${w.web_root}/backup/${filename}`,
        size,
        status: 'success',
        upload_status: 'uploaded',
        uploads: [{
          id: id * 10 + 1,
          storage_name: 'Hetzner 主存储',
          storage_type: 'sftp',
          status: 'uploaded',
          uploaded_bytes: size,
          duration_seconds: 40,
          attempts: 1,
          finished_at: at,
          error_message: null,
          remote_path: `/backups/${w.name}/${filename}`,
        }],
      })
    }
    // batch_code = self_backup 指令 ID：同一次自主压缩的两个产物共用（恢复页按编码精确成组）
    addSelfLog('main_site-web-20261014-020001.tar.gz', 2.2 * GB, 10185)
    addSelfLog('main_site-db-20261014-030000.sql.gz', 360 * MB, 10185)
  }
})()

// 演示用（v1.2.17）：预置一条增量快照日志（batch_code + 远端目录 full_path + bc_<sid>: upload），
// 点亮备份记录页「增量快照」chip 与恢复页的快照目录恢复流程
;(() => {
  const w = findById(db.websites, 1)
  const incStorage = findById(db.storages, 5)
  if (w && w.backup_mode === 'incremental' && incStorage) {
    const id = nextId('log')
    const at = hours(3)
    const safe = String(w.name || 'site').replace(/[^A-Za-z0-9._-]/g, '_')
    const snapDir = `${incStorage.base_path}/${safe}/20261019-143000`
    db.logs.unshift({
      ...mkLog(id, w.name, w.server?.name, 1.6 * GB, 'success', 180, 60),
      id,
      batch_code: 10186,
      website: { name: w.name, domain: w.domain },
      server: w.server,
      website_id: w.id,
      server_id: w.server_id,
      filename: `${safe}-snap-20261019-143000`,
      full_path: snapDir,
      size: 1.6 * GB,
      status: 'success',
      // 增量只推目标存储（不走 website.storage_ids），succeeded 仅含增量存储
      succeeded: [{ storage_name: incStorage.name }],
      failed: [],
      backup_at: at,
      synced_at: at,
      created_at: at,
      upload_status: 'uploaded',
      uploads: [{
        id: id * 10 + 1,
        storage_name: incStorage.name,
        storage_type: 'sftp',
        status: 'uploaded',
        uploaded_bytes: 1.6 * GB,
        duration_seconds: 55,
        attempts: 1,
        finished_at: at,
        error_message: null,
        remote_path: `bc_${incStorage.id}:${snapDir}`,
      }],
    })
  }
})()

/* ============================================================
   通用工具
   ============================================================ */

const paginate = (items, params) => {
  const page = Number(params.page) || 1
  const perPage = Number(params.per_page) || 20
  const start = (page - 1) * perPage
  return {
    data: items.slice(start, start + perPage),
    meta: { current_page: page, total: items.length, per_page: perPage },
  }
}

const matchKeyword = (kw, ...fields) => {
  if (!kw) return true
  const k = String(kw).toLowerCase()
  return fields.filter(Boolean).some((f) => String(f).toLowerCase().includes(k))
}

const removeById = (list, id) => {
  const i = list.findIndex((x) => String(x.id) === String(id))
  return i >= 0 ? list.splice(i, 1)[0] : null
}

const serverRef = (id) => {
  const s = findById(db.servers, id)
  return s ? { name: s.name, connect_type: s.connect_type } : null
}

// 时间戳 YYYYMMDD-HHMMSS：自主备份产物文件名与高级恢复预备份路径共用
const tsStamp = (d = new Date()) => {
  const p = (n) => String(n).padStart(2, '0')
  return `${d.getFullYear()}${p(d.getMonth() + 1)}${p(d.getDate())}-${p(d.getHours())}${p(d.getMinutes())}${p(d.getSeconds())}`
}

/* ---------- 数据恢复（P2）辅助 ---------- */
// range → 天数，与 RestoreController::backups 的映射一致
const RANGE_DAYS = { month: 30, week: 7, '3day': 3, '1day': 1 }

// 本地日期 Y-m-d（后端按 app 时区分组，演示用浏览器本地时区）
const localDate = (iso) => {
  if (!iso) return ''
  const d = new Date(iso)
  const p = (n) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`
}

// 最新一次成功上传（与后端闭包同序：uploaded + remote_path 非空 + id 倒序取首条）
const latestUpload = (log) => (log.uploads || [])
  .filter((u) => u.status === 'uploaded' && u.remote_path)
  .sort((a, b) => b.id - a.id)[0] || null

// 与后端 RestoreController::fileType 同规则：快照（-snap-<stamp>）优先于扩展名判定
const fileType = (filename) => {
  const n = String(filename || '')
  if (/-snap-\d{8}-\d{6}$/.test(n)) return 'snapshot'
  return /\.sql(\.|$)/i.test(n) ? 'database' : 'website'
}

const fileLabel = (log, website) => {
  const type = fileType(log.filename)
  // 快照日志的 full_path 是远端目录，backup_paths 前缀匹配无意义，直接返回固定标签
  if (type === 'snapshot') return '增量快照'
  const fallback = type === 'database' ? '数据库' : '网站目录'
  const fullPath = log.full_path || ''
  for (const bp of website.backup_paths || []) {
    if (bp.path && fullPath.startsWith(bp.path)) return bp.label || fallback
  }
  return fallback
}

/* ---------- 备份套件合并（与后端 RestoreController::buildSuites 同策略） ----------
 * ① batch_code 非空（self_backup 指令 ID）：同编码精确成组
 * ② 无编码文件（external 扫描发现）：每个 website 文件配窗口内时间最近的未配对 database 文件
 * ③ 窗口兜底：剩余文件归并进窗口内有空的组（与组内最早比较，防链式合并）
 * ④ 组内按 backup_at 倒序；suite_id 取组内最新文件的 backup_log_id（与旧约定一致） */
const SUITE_WINDOW_SECONDS = 300
const byBackupAtDesc = (a, b) => new Date(b.backup_at) - new Date(a.backup_at)
const buildSuites = (files, windowSeconds = SUITE_WINDOW_SECONDS) => {
  const groups = []
  const used = new Set()
  const paired = new Set()

  // ① batch_code 精确成组
  const coded = new Map()
  for (const f of files) {
    if (!f.batch_code) continue
    if (!coded.has(f.batch_code)) coded.set(f.batch_code, [])
    coded.get(f.batch_code).push(f)
    used.add(f.backup_log_id)
  }
  for (const list of coded.values()) groups.push(list)

  // ② 类型配对：website 配窗口内最近的未配对 database；未配上的 website 自成一组（不进兜底）
  const pool = files.filter((f) => !used.has(f.backup_log_id))
  for (const web of pool.filter((f) => f.type === 'website')) {
    const cand = pool
      .filter((f) => f.type === 'database' && !paired.has(f.backup_log_id))
      .map((f) => ({ f, diff: Math.abs(new Date(f.backup_at) - new Date(web.backup_at)) / 1000 }))
      .filter((x) => x.diff <= windowSeconds)
      .sort((a, b) => a.diff - b.diff)[0]
    paired.add(web.backup_log_id)
    if (cand) {
      paired.add(cand.f.backup_log_id)
      groups.push([web, cand.f])
    } else {
      groups.push([web])
    }
  }

  // ③ 窗口兜底：仅未配对 database + 非 website/database 文件（快照等）；
  // 只与「上一个兜底组」比较、且对组内最早元素比较，防链式合并（与后端逐行对齐）
  const leftoverGroups = []
  for (const f of pool.filter((x) => !paired.has(x.backup_log_id))) {
    const n = leftoverGroups.length
    let merged = false
    if (n > 0) {
      const prev = leftoverGroups[n - 1]
      const earliest = prev[prev.length - 1].backup_at
      if (earliest && Math.abs(new Date(earliest) - new Date(f.backup_at)) / 1000 <= windowSeconds) {
        prev.push(f)
        merged = true
      }
    }
    if (!merged) leftoverGroups.push([f])
  }
  for (const g of leftoverGroups) groups.push(g)

  const suites = groups.map((g) => {
    const list = [...g].sort(byBackupAtDesc)
    return { suite_id: `suite-${list[0].backup_log_id}`, files: list }
  })
  suites.sort((a, b) => byBackupAtDesc(a.files[0], b.files[0]))
  return suites
}

// 与后端 aggregateRestoreState 同优先级：failed > dispatched > pending > success
const aggregateRestoreState = (states) => {
  if (!states.length) return null
  if (states.includes('failed')) return 'failed'
  if (states.includes('dispatched')) return 'dispatched'
  if (states.includes('pending')) return 'pending'
  return 'success'
}

// 自主压缩（self）的最近备份：从 db.logs 里挑出 <name>-web-*.tar.gz 与 <name>-db-*.sql.gz 各最新一条，
// 包装成 [{ label:'网站目录', path, log }, { label:'数据库', path:'（数据库）', log }]（无则省略）。
const buildRecentSelfBackups = (website) => {
  const webLogs = db.logs.filter((l) => l.website_id === website.id)
  const pick = (re) => {
    const sorted = webLogs
      .filter((x) => re.test(x.filename || ''))
      .sort((a, b) => new Date(b.backup_at) - new Date(a.backup_at))
    return sorted[0] || null
  }
  const shape = (l) => ({
    id: l.id,
    filename: l.filename,
    status: l.status,
    upload_status: l.upload_status,
    size: l.size,
    backup_at: l.backup_at,
    synced_at: l.synced_at,
    succeeded: (l.succeeded || []).map((s) => ({ storage_id: 1, storage_name: s.storage_name })),
    failed: [],
  })
  const out = []
  const web = pick(/^.*-web-.*\.tar\.gz$/)
  if (web) out.push({ label: '网站目录', path: website.web_root || '（未绑定根目录）', log: shape(web) })
  // 注意：勿命名 db —— 会遮蔽模块级 db 并让上面的 db.logs 访问踩 TDZ
  const dbLog = pick(/^.*-db-.*\.sql\.gz$/)
  if (dbLog) out.push({ label: '数据库', path: '（数据库）', log: shape(dbLog) })
  // 增量快照（v1.2.17）：与后端 recentSelfBackups 第三项对齐，label='增量快照'，path=远端快照目录
  const snap = pick(/^.*-snap-\d{8}-\d{6}$/)
  if (snap) out.push({ label: '增量快照', path: snap.full_path || '（远端快照目录）', log: shape(snap) })
  return out
}


// 恢复命令队列：pending → dispatched(6s) → success(14s)，模拟 Agent 每次心跳逐条执行
db.restoreCommands = []
const scheduleRestore = (cmd) => {
  const advanced = cmd.params.restore_mode === 'advanced'
  // 按后缀判定：真实备份的数据库产物一律是 <name>-db-<ts>.sql.gz，
  // 但外部扫描到的可能是 mysqldump 直接产出的 <db>_<ts>.sql.gz（不含 -db-），
  // 因此不能用 -db- 中缀判断，否则数据库文件会被当成网站文件走「解压覆盖」分支。
  const isDb = /\.sql\.gz$/i.test(cmd.params.filename)
  // 预备份路径与真实 Agent 一致：<目标>.bak-<ts>（同一命令内固定一个 stamp）
  const bakTs = tsStamp()
  const bakPath = (ext) => `${cmd.params.local_dir}.bak-${bakTs}${ext}`
  const finish = () => {
    if (cmd.status !== 'pending' && cmd.status !== 'dispatched') return
    cmd.status = 'success'
    cmd.completed_at = new Date().toISOString()
    cmd.output = advanced
      ? (isDb
        ? `高级恢复完成：数据库已重建导入（预备份 ${bakPath('.sql')}）`
        : `高级恢复完成：网站目录已覆盖（预备份 ${bakPath('.tar.gz')}）`)
      : `已恢复 ${cmd.params.filename} → ${cmd.params.local_dir}`
  }
  setTimeout(() => {
    if (cmd.status === 'pending') {
      cmd.status = 'dispatched'
      cmd.dispatched_at = new Date().toISOString()
    }
  }, 6000)
  if (!advanced) {
    setTimeout(finish, 14000)
    return
  }
  // advanced：模拟 Agent 的多步执行（预备份 → 解压/导库），进度经 output 逐步透出
  const steps = isDb
    ? [`[预备份] 数据库 → ${bakPath('.sql')}`, '[导入] 解压数据库归档…', '[导入] 重建并导入数据库…']
    : [`[预备份] 网站目录 → ${bakPath('.tar.gz')}`, '[解压] 正在覆盖网站根目录…']
  steps.forEach((s, i) => setTimeout(() => { if (cmd.status !== 'success') cmd.output = s }, 8000 + i * 5000))
  setTimeout(finish, 8000 + steps.length * 5000)
}

/* ============================================================
   恢复演示数据（v1.2.18）：让「数据恢复」页呈现与生产一致的天级套件卡片
   - 电商平台（advanced 恢复模式）：今天 3 场次（全部可恢复 / 已恢复 / 1/2 可恢复）
     + 昨天 2 场次（其中一场预置 pending 命令，演示 待执行→执行中→已恢复 实时流转）
     + 2 天前 1 场次全部上传失败（禁用卡）
   - 内部 OA（self 高级模式）：今天 1 场次自主压缩（web/db 共用 batch_code，精确成组）
   - db.logs 统一按 backup_at 倒序（与后端 orderByDesc('backup_at') 一致）
   ============================================================ */
;(() => {
  const added = []
  const p2 = (n) => String(n).padStart(2, '0')
  // 距今年数 + 秒偏移：让同一场次的 web/db 两个产物落在 300s 合并窗口内
  const atWith = (h, sec) => new Date(NOW - h * 3600000 + sec * 1000).toISOString()
  // 外部扫描产物名：shop_20261021_040000.tar.gz / shop_mysql_20261021_040040.sql.gz
  const scanStamp = (iso) => {
    const d = new Date(iso)
    return `${d.getFullYear()}${p2(d.getMonth() + 1)}${p2(d.getDate())}_${p2(d.getHours())}${p2(d.getMinutes())}${p2(d.getSeconds())}`
  }
  // 自主压缩产物名：oa_main-web-20261021-050000.tar.gz（与官网主站预置同款格式）
  const selfStamp = (iso) => scanStamp(iso).replace('_', '-')

  // 造一条「外部扫描发现」的备份日志：succeeded/failed 派生 uploads（与静态种子同构）
  const addScanLog = (website, filename, size, at, dir, opts = {}) => {
    const id = nextId('log')
    const failed = opts.status === 'failed'
    const succeeded = failed ? [] : (opts.stores || [{ storage_name: 'Hetzner 主存储' }, { storage_name: 'Backblaze B2' }])
    const failedStores = failed ? [{ storage_name: 'Nextcloud 异地', error: 'permission denied' }] : []
    const log = {
      ...mkLog(id, website.name, website.server?.name, size, failed ? 'failed' : 'success', 0, Math.round(size / (28 * MB))),
      id,
      website: { name: website.name, domain: website.domain },
      server: website.server,
      website_id: website.id,
      server_id: website.server_id,
      filename,
      full_path: `${dir}/backup/${filename}`,
      size,
      status: failed ? 'failed' : 'success',
      backup_at: at,
      synced_at: at,
      created_at: at,
      upload_status: failed ? 'upload_failed' : 'uploaded',
      succeeded,
      failed: failedStores,
      uploads: succeeded.map((s, i) => ({
        id: id * 10 + i + 1,
        storage_name: s.storage_name,
        storage_type: i === 0 ? 'sftp' : 's3',
        status: 'uploaded',
        uploaded_bytes: size,
        duration_seconds: 40 + i * 12,
        attempts: 1,
        finished_at: at,
        error_message: null,
        remote_path: `/backups/${website.name}/${filename}`,
      })).concat(failedStores.map((f, i) => ({
        id: id * 10 + 50 + i,
        storage_name: f.storage_name,
        storage_type: 'webdav',
        status: 'failed',
        uploaded_bytes: 0,
        duration_seconds: 12,
        attempts: 3,
        finished_at: at,
        error_message: f.error,
        remote_path: null,
      }))),
    }
    added.push(log)
    return log
  }

  // 电商平台：external 扫描口径，一场次 = web 站点目录 + db 数据库转储（间隔 ≤45s）
  const shop = findById(db.websites, 3)
  const sessions = {}
  if (shop) {
    const webDir = shop.backup_paths?.[0]?.path || '/data/shop'
    const dbDir = shop.backup_paths?.[1]?.path || webDir
    const session = (key, h, opts = {}) => {
      const webAt = atWith(h, 0)
      const dbAt = atWith(h, opts.dbGap ?? 40)
      sessions[key] = {
        web: addScanLog(shop, `shop_${scanStamp(webAt)}.tar.gz`, opts.webSize ?? 2.5 * GB, webAt, webDir, { status: opts.webStatus, stores: opts.webStores }),
        db: addScanLog(shop, `shop_mysql_${scanStamp(dbAt)}.sql.gz`, opts.dbSize ?? 410 * MB, dbAt, dbDir, { status: opts.dbStatus }),
      }
    }
    session('a', 4)                                                                                  // 今天 04:00 前后 · 全部可恢复
    session('b', 6)                                                                                  // 今天 06:00 前后 · 预置已恢复命令
    session('c', 8, { webSize: 2.4 * GB, webStores: [{ storage_name: 'Hetzner 主存储' }], dbStatus: 'failed' }) // 今天 08:00 前后 · 1/2 可恢复
    session('d', 26)                                                                                 // 昨天 · 全部可恢复
    session('e', 28)                                                                                 // 昨天 · 预置 pending 命令（实时流转）
    session('f', 52, { webStatus: 'failed', dbStatus: 'failed' })                                    // 2 天前 · 全部上传失败
  }

  // 内部 OA：self 自主压缩口径，web/db 共用 batch_code 与同一 backup_at（精确成组，match=exact）
  const oa = findById(db.websites, 6)
  if (oa && oa.backup_source === 'self') {
    const at = atWith(5, 0)
    const addOaLog = (filename, size) => {
      const id = nextId('log')
      added.push({
        ...mkLog(id, oa.name, oa.server?.name, size, 'success', 0, 60),
        id,
        website: { name: oa.name, domain: oa.domain },
        server: oa.server,
        website_id: oa.id,
        server_id: oa.server_id,
        batch_code: 10201,
        filename,
        full_path: `${oa.web_root}/backup/${filename}`,
        size,
        status: 'success',
        backup_at: at,
        synced_at: at,
        created_at: at,
        upload_status: 'uploaded',
        uploads: [{
          id: id * 10 + 1,
          storage_name: 'Hetzner 主存储',
          storage_type: 'sftp',
          status: 'uploaded',
          uploaded_bytes: size,
          duration_seconds: 40,
          attempts: 1,
          finished_at: at,
          error_message: null,
          remote_path: `/backups/${oa.name}/${filename}`,
        }],
      })
    }
    addOaLog(`oa_main-web-${selfStamp(at)}.tar.gz`, 900 * MB)
    addOaLog(`oa_main-db-${selfStamp(at)}.sql.gz`, 180 * MB)
  }

  db.logs.unshift(...added)
  // 与后端一致：备份记录按 backup_at 倒序（静态种子 + 预置日志统一排序）
  db.logs.sort((a, b) => new Date(b.backup_at) - new Date(a.backup_at) || b.id - a.id)

  /* ---------- 预置恢复命令：点亮「已恢复 / 执行中」徽标与仪表盘恢复统计 ---------- */
  const addRestoreCmd = (log, opts = {}) => {
    const upload = latestUpload(log)
    if (!upload) return null
    const filename = upload.remote_path.split('/').pop()
    const cmd = {
      id: opts.id,
      server_id: log.server_id,
      type: 'restore',
      status: opts.status || 'success',
      output: null,
      error: null,
      completed_at: null,
      dispatched_at: null,
      created_at: new Date(NOW - 30 * 60000).toISOString(),
      params: {
        backup_log_id: log.id,
        upload_id: upload.id,
        remote_path: upload.remote_path,
        local_dir: opts.local_dir,
        filename,
        ...(opts.advanced ? { restore_mode: 'advanced', website_id: opts.website_id } : {}),
      },
    }
    if (cmd.status === 'success') {
      cmd.dispatched_at = new Date(NOW - 29 * 60000).toISOString()
      cmd.completed_at = new Date(NOW - 28 * 60000).toISOString()
      cmd.output = /\.sql\.gz$/i.test(filename)
        ? `高级恢复完成：数据库已重建导入（预备备 ${opts.local_dir}.bak-20261021.sql）`
        : `高级恢复完成：网站目录已覆盖（预备备 ${opts.local_dir}.bak-20261021.tar.gz）`
    }
    db.restoreCommands.push(cmd)
    return cmd
  }

  if (sessions.b) {
    // 今天 06:00 场次：web + db 两条 success 命令 → 套件徽标「已恢复」
    addRestoreCmd(sessions.b.web, { id: 1, local_dir: shop.web_root, advanced: true, website_id: shop.id })
    addRestoreCmd(sessions.b.db, { id: 2, local_dir: '/tmp/bc-restore-3-1729000000', advanced: true, website_id: shop.id })
  }
  if (sessions.e) {
    // 昨天 28h 场次的 web 文件：pending → dispatched(6s) → success(18s)，演示刷新后进度恢复
    const cmd = addRestoreCmd(sessions.e.web, { id: 3, status: 'pending', local_dir: shop.web_root, advanced: true, website_id: shop.id })
    if (cmd) scheduleRestore(cmd)
  }
})()

/** Agent 升级：下发后模拟「待执行 → 执行中 → 成功」的状态推进 */
const scheduleAgentUpgrade = (serverId, cmdId) => {
  const patch = (fn) => {
    const s = findById(db.servers, serverId)
    if (s && s.pending_agent_command && s.pending_agent_command.id === cmdId) fn(s)
  }
  setTimeout(() => patch((s) => { s.pending_agent_command.status = 'dispatched' }), 6000)
  setTimeout(() => patch((s) => {
    s.agent_version = s.pending_agent_command.to_version
    s.pending_agent_command.status = 'success'
  }), 14000)
}

/**
 * 恢复统计 + 最近恢复记录：由 db.restoreCommands 派生，
 * 字段结构与后端 DashboardController 的 stats.restore / recent_restores 一致。
 */
function buildRestoreStats() {
  const cmds = db.restoreCommands
  const success = cmds.filter((c) => c.status === 'success').length
  const failed = cmds.filter((c) => c.status === 'failed').length
  const total_size = cmds.reduce((s, c) => {
    const log = findById(db.logs, c.params.backup_log_id)
    return s + (log ? log.size : 0)
  }, 0)
  return {
    total: cmds.length,
    success,
    failed,
    success_rate: cmds.length > 0 ? Math.round(success / cmds.length * 1000) / 10 : null,
    total_size,
  }
}

function buildRecentRestores() {
  return db.restoreCommands
    .slice()
    .sort((a, b) => b.id - a.id)
    .slice(0, 10)
    .map((c) => {
      const log = findById(db.logs, c.params.backup_log_id)
      const website = log ? findById(db.websites, log.website_id) : null
      return {
        id: c.id,
        filename: c.params.filename,
        size: log ? log.size : null,
        status: c.status,
        duration_seconds: c.dispatched_at && c.completed_at
          ? Math.max(1, Math.round((new Date(c.completed_at) - new Date(c.dispatched_at)) / 1000))
          : null,
        created_at: c.created_at,
        completed_at: c.completed_at,
        website: website ? { id: website.id, name: website.name, domain: website.domain } : null,
      }
    })
}

function buildDashboard(range) {
  const key = RANGE_CFG[range] ? range : 'week'
  const buckets = buildBuckets(key)
  return {
    range: key,
    range_label: RANGE_CFG[key].label,
    stats: {
      server_count: db.servers.length,
      server_online: db.servers.filter((s) => s.status === 'online').length,
      website_count: db.websites.length,
      website_enabled: db.websites.filter((w) => w.status === 'enabled').length,
      ...RANGE_STATS[key],
      cumulative_size: 612 * GB,
      cumulative_count: 15832,
      restore: buildRestoreStats(),
    },
    count_trend: buckets.map((b) => ({ label: b.label, value: b.count })),
    size_trend: buckets.map((b) => ({ label: b.label, value: b.size })),
    recent_logs: db.logs.slice(0, 10),
    recent_restores: buildRecentRestores(),
    // 与后端一致：任何外发 shape 里的 encryption.password 都必须是掩码，永不明文
    storages: db.storages.map(safeStorage),
  }
}

/* ============================================================
   VPS 备份文件浏览（只读）· 演示树
   路径口径与 Agent uploader 对齐：<base_path>/<sanitize(站名)>/<YYYYMMDD-HHMMSS>/
   sanitize 只替换 / \ : * ? " < > |（中文原样保留），空串回落 default
   ============================================================ */

// Go sanitizePathSegment 的演示复刻（中文保留）
const sanitizeSegment = (name) => {
  const s = String(name || '').trim().replace(/[/\\:*?"<>|]/g, '_')
  return s || 'default'
}

// 演示指令登记：前端轮询 GET /agent-commands/{id} 消费。
// 2.5 秒后自动翻 success（轮询 4s 一轮，正好赶上；瞬间完成会显得 Agent 没在干活）
const registerDemoCommand = (cmdId, output) => {
  db.commands[cmdId] = { id: cmdId, status: 'dispatched', output: null, error: null }
  setTimeout(() => {
    const c = db.commands[cmdId]
    if (c) { c.status = 'success'; c.output = output }
  }, 2500)
}

// 演示节点：文件带 size；目录带 children（递归统计后填 recursiveSize/recursiveCount）
const demoFile = (name, size, mtime) => ({ name, isDir: false, size, mtime, children: null })
const demoDir = (name, mtime, children) => ({ name, isDir: true, size: null, mtime, children: children || [] })

// 自底向上求和：每个目录节点得到 recursiveSize / recursiveCount（只数文件，与 rclone size 口径一致）
const calcRecursive = (nodes) => {
  let size = 0
  let count = 0
  for (const n of nodes) {
    if (n.isDir) {
      const sub = calcRecursive(n.children)
      n.recursiveSize = sub.size
      n.recursiveCount = sub.count
      size += sub.size
      count += sub.count
    } else {
      n.recursiveSize = n.size
      n.recursiveCount = 1
      size += n.size
      count += 1
    }
  }
  return { size, count }
}

// 路径 → 演示节点索引（含递归统计）
const indexTree = (nodes, prefix, index) => {
  for (const n of nodes) {
    const p = prefix ? `${prefix}/${n.name}` : n.name
    index[p] = n
    if (n.isDir) indexTree(n.children, p, index)
  }
  return index
}

// 父目录（根目录返回 null）
const browseParent = (p) => {
  const s = String(p || '').replace(/\/+$/, '')
  const i = s.lastIndexOf('/')
  return i <= 0 ? null : s.slice(0, i)
}

// storage 5（VPS 增量存储）主演示树：rsync 硬链快照布局，站名 = sanitize 后的网站名
const DEMO_TREE_S5 = [
  demoDir('官网主站', hours(2), [
    demoDir('20261018-020000', hours(2), [
      demoFile('index.php', 28.4 * 1024, hours(2)),
      demoDir('wp-content', hours(26), [
        demoDir('themes', hours(26), [
          demoDir('twentytwentyfour', hours(50), [
            demoFile('style.css', 92.6 * 1024, hours(50)),
            demoFile('functions.php', 41.8 * 1024, hours(50)),
          ]),
          demoFile('flavor.zip', 8.4 * MB, hours(74)),
        ]),
        demoDir('plugins', hours(26), [
          demoDir('akismet', hours(26), [demoFile('akismet.php', 208.3 * 1024, hours(26))]),
        ]),
        demoFile('index.php', 128, hours(26)),
      ]),
      demoFile('db.sql.gz', 45.2 * MB, hours(2)),
    ]),
    // 硬链增量：前一日快照只含变化部分，体积明显更小
    demoDir('20261017-020000', hours(26), [
      demoFile('index.php', 4.2 * 1024, hours(26)),
      demoFile('db.sql.gz', 2.1 * MB, hours(26)),
    ]),
  ]),
  demoDir('营销落地页', hours(28), [
    demoDir('20261016-030000', hours(28), [
      demoFile('index.html', 18.6 * 1024, hours(28)),
      demoDir('assets', hours(28), [
        demoFile('app.css', 34.2 * 1024, hours(28)),
        demoFile('app.js', 126.7 * 1024, hours(28)),
      ]),
    ]),
  ]),
]

// 其余存储的 2 层通用小树：<base>/<站点>/<tar.gz|sql.gz>（文件名呼应备份记录演示数据）
const buildGenericTree = () => [
  demoDir(sanitizeSegment('电商平台'), hours(15), [
    demoFile('shop_20261013_040000.tar.gz', 5.1 * GB, hours(15)),
    demoFile('shop_mysql_20261013_040001.sql.gz', 620 * MB, hours(15)),
  ]),
  demoDir(sanitizeSegment('客户门户'), hours(9), [
    demoFile('portal_20261013_030002.tar.gz', 1.8 * GB, hours(9)),
    demoFile('portal_mysql_20261013_030003.sql.gz', 240 * MB, hours(9)),
  ]),
  demoFile('bc-system-20261013-030000.tar.gz', 18.6 * MB, hours(13)),
]

// POST /api/storages/preview-files 演示处理器（新建态表单浏览：无 storageId，按提交快照列目录）
function previewStorageFiles(body) {
  const base = String(body?.base_path || '').replace(/\/+$/, '')
  let p = String(body?.path || '')
  if (!p) p = base

  const tree = buildGenericTree()
  const rootStat = calcRecursive(tree)
  const index = indexTree(tree, base, {})
  // 补上根目录节点自身（base_path 这一层，含整树递归统计）
  index[base] = { isDir: true, children: tree, recursiveSize: rootStat.size, recursiveCount: rootStat.count }
  const node = index[p]
  if (!node || !node.isDir) {
    return { ok: false, message: `目录不存在或不可浏览：${p || '/'}` }
  }

  const entries = node.children
    .slice()
    .sort((a, b) => (b.isDir ? 1 : 0) - (a.isDir ? 1 : 0))
    .map((n) => ({
      name: n.name,
      path: `${p}/${n.name}`,
      is_dir: n.isDir,
      size: n.isDir ? null : n.size,
      modified: n.mtime,
    }))

  return {
    ok: true,
    storage: { id: null, name: '预览目录', type: body?.type || 'sftp', encrypted: false, base_path: base },
    path: p,
    parent: browseParent(p),
    entries,
    truncated: false,
    dir_count: entries.filter((e) => e.is_dir).length,
    file_count: entries.filter((e) => !e.is_dir).length,
    recursive_size: node.recursiveSize ?? null,
    recursive_count: node.recursiveCount ?? null,
  }
}

// GET /api/storages/{id}/files 演示处理器（契约见 .workbuddy/vps-browse-spec.md §1.5）
function browseStorageFiles(storageId, path, websiteId) {
  const storage = findById(db.storages, storageId)
  if (!storage) return { ok: false, message: '存储不存在' }

  // agent_local 加密：中心不持密钥，明确拒绝（与后端 StorageFileController 同文案）
  const enc = storage.config?.encryption
  if (enc?.enabled && enc.key_mode === 'agent_local') {
    return { ok: false, message: '该存储启用了「Agent 本地密钥」加密，中心无法解密文件名，暂不支持浏览' }
  }

  const base = String(storage.base_path || '').replace(/\/+$/, '')

  // 初始路径：website_id 命中该存储的增量目标时直达站点快照根，否则回落 base_path
  let p = String(path || '')
  if (!p && websiteId) {
    const w = findById(db.websites, websiteId)
    if (w && Number(w.incremental_storage_id) === Number(storage.id)) {
      p = base ? `${base}/${sanitizeSegment(w.name)}` : sanitizeSegment(w.name)
    }
  }
  if (!p) p = base

  // 选演示树：storage 5 走硬链快照主演示，其余走通用小树；两棵树的节点名均为裸名，索引时统一拼 base 前缀
  const tree = storage.id === 5 ? DEMO_TREE_S5 : buildGenericTree()
  const rootStat = calcRecursive(tree)
  const index = indexTree(tree, base, {})
  // 补上根目录节点自身（base_path 这一层，含整树递归统计）
  index[base] = { isDir: true, children: tree, recursiveSize: rootStat.size, recursiveCount: rootStat.count }
  const node = index[p]
  if (!node || !node.isDir) {
    return { ok: false, message: `目录不存在或不可浏览：${p || '/'}` }
  }

  // 目录优先（稳定排序保持演示顺序：新快照在前）；条目按服务端口径渲染
  const entries = node.children
    .slice()
    .sort((a, b) => (b.isDir ? 1 : 0) - (a.isDir ? 1 : 0))
    .map((n) => ({
      name: n.name,
      path: `${p}/${n.name}`,
      is_dir: n.isDir,
      size: n.isDir ? null : n.size,
      modified: n.mtime,
    }))

  return {
    ok: true,
    storage: {
      id: storage.id,
      name: storage.name,
      type: storage.type,
      // center 密钥模式才算前端可见的「已解密浏览」；agent_local 已在上面拒绝
      encrypted: !!(enc?.enabled && enc.key_mode === 'center'),
      base_path: storage.base_path,
    },
    path: p,
    parent: browseParent(p),
    entries,
    truncated: false,
    dir_count: entries.filter((e) => e.is_dir).length,
    file_count: entries.filter((e) => !e.is_dir).length,
    recursive_size: node.recursiveSize ?? null,
    recursive_count: node.recursiveCount ?? null,
  }
}

/* ============================================================
   路由表：[method, 路径正则, handler(match, params, body)]
   返回体 shape 逐个对齐 src/api/*.js 的解包方式（r.data / r.data.data）
   ============================================================ */
const routes = [
  /* ---------- 认证 ---------- */
  ['post', /^\/auth\/login$/, () => ({ token: 'demo-token-' + randToken(12), user: db.user })],
  ['get', /^\/auth\/me$/, () => db.user],
  ['post', /^\/auth\/logout$/, () => ({ message: '已退出登录' })],
  ['get', /^\/auth\/bootstrap-status$/, () => ({ need_bootstrap: false, system_name: 'backup center' })],
  ['post', /^\/auth\/bootstrap$/, () => ({ token: 'demo-token-' + randToken(12), user: db.user })],
  ['put', /^\/auth\/profile$/, (m, p, body) => {
    Object.assign(db.user, { username: body.username ?? db.user.username, name: body.name ?? db.user.name })
    return { message: '资料已更新', user: db.user }
  }],
  ['put', /^\/auth\/password$/, () => ({ message: '密码已更新，其他会话已注销' })],
  ['get', /^\/auth\/login-logs$/, () => ({ data: db.loginLogs })],

  /* ---------- 仪表盘 ---------- */
  ['get', /^\/dashboard$/, (m, p) => buildDashboard(p.range)],

  /* ---------- 服务器 ---------- */
  ['get', /^\/agent-latest-version$/, () => ({ latest_version: db.latestAgentVersion, version: db.latestAgentVersion })],
  // 系统版本（对齐后端 VersionController：真实部署取构建时写入的 backend/VERSION，
  // 演示模式无该文件，直接返回当前发版号；不覆盖的话兜底返回 {data:[]}，设置页/页脚显示「—」）
  ['get', /^\/version$/, () => ({ version: 'v1.2.25', commit_short: '3d742ab', channel: 'demo' })],
  ['get', /^\/servers$/, (m, p) => paginate(db.servers.filter((s) => (
    (!p.status || s.status === p.status)
    && (!p.connect_type || s.connect_type === p.connect_type)
    && matchKeyword(p.keyword, s.name, s.ip, s.remark)
  )), p)],
  ['get', /^\/servers\/(\d+)$/, (m) => ({ data: findById(db.servers, m[1]) || {} })],
  ['post', /^\/servers$/, (m, p, body) => {
    const s = {
      id: nextId('server'),
      name: body.name || '未命名服务器',
      remark: body.remark || '',
      connect_type: body.connect_type || 'agent',
      ip: body.ip || '',
      os_type: body.os_type || '',
      status: 'pending',
      cpu_usage: 0, mem_usage: 0, disk_usage: 0,
      agent_version: null,
      agent_token: body.connect_type === 'ssh' ? null : randToken(),
      hostname: null, kernel: null, arch: null,
      ssh_port: body.ssh_port || 22,
      ssh_user: body.ssh_user || '',
      has_ssh_key: !!body.ssh_private_key,
      backup_path: body.backup_path || '',
      pending_agent_command: null,
      last_seen_at: null,
      disks: [],
    }
    db.servers.push(s)
    return { data: s }
  }],
  ['put', /^\/servers\/(\d+)$/, (m, p, body) => {
    const s = findById(db.servers, m[1])
    if (!s) return { data: {} }
    const { ssh_private_key, ...rest } = body || {}
    Object.assign(s, rest)
    if (ssh_private_key) s.has_ssh_key = true
    return { data: s }
  }],
  ['delete', /^\/servers\/(\d+)$/, (m) => { removeById(db.servers, m[1]); return { message: '已删除' } }],
  ['post', /^\/servers\/(\d+)\/regenerate-token$/, (m) => {
    const s = findById(db.servers, m[1])
    const token = randToken()
    if (s) s.agent_token = token
    return { message: '已重生 Token', agent_token: token }
  }],
  ['post', /^\/servers\/(\d+)\/agent-update$/, (m) => {
    const s = findById(db.servers, m[1])
    if (!s) return { message: '服务器不存在' }
    const cmdId = nextId('cmd')
    s.pending_agent_command = {
      id: cmdId,
      status: 'pending',
      from_version: s.agent_version || '0.0.0',
      to_version: db.latestAgentVersion,
    }
    scheduleAgentUpgrade(s.id, cmdId)
    return { message: `已下发升级指令，${s.name} 将在 60 秒内完成升级` }
  }],
  ['post', /^\/servers\/bulk-agent-update$/, (m, p, body) => {
    const ids = body.server_ids || []
    ids.forEach((id) => {
      const s = findById(db.servers, id)
      if (!s) return
      const cmdId = nextId('cmd')
      s.pending_agent_command = {
        id: cmdId,
        status: 'pending',
        from_version: s.agent_version || '0.0.0',
        to_version: db.latestAgentVersion,
      }
      scheduleAgentUpgrade(s.id, cmdId)
    })
    return { message: `已向 ${ids.length} 台服务器下发升级指令` }
  }],

  /* ---------- 网站 ---------- */
  ['get', /^\/websites$/, (m, p) => paginate(db.websites.filter((w) => (
    (!p.status || w.status === p.status)
    && (!p.server_id || String(w.server_id) === String(p.server_id))
    && matchKeyword(p.keyword, w.name, w.domain, w.remark, (w.backup_paths || []).map((b) => b.path).join(' '))
  )).map((w) => w.backup_source === 'self'
    ? { ...w, recent_self_backups: buildRecentSelfBackups(w) }
    : w), p)],
  ['get', /^\/websites\/(\d+)$/, (m) => ({ data: findById(db.websites, m[1]) || {} })],
  ['post', /^\/websites$/, (m, p, body) => {
    const w = {
      id: nextId('website'),
      name: body.name || '未命名网站',
      domain: body.domain || '',
      server_id: body.server_id || null,
      server: serverRef(body.server_id),
      backup_paths: body.backup_paths || [],
      recent_backups: [],
      schedule: body.schedule || null,
      sync_mode: body.sync_mode || 'auto',
      retention_days: body.retention_days || 30,
      status: body.status || 'enabled',
      remark: body.remark || '',
      storage_ids: body.storage_ids || [],
      backup_source: body.backup_source || 'external',
      backup_mode: body.backup_mode || 'full',
      incremental_storage_id: body.incremental_storage_id || null,
      restore_mode: body.restore_mode || 'lite',
      bind_token: randToken(),
      web_root: null,
      root_bound_at: null,
      db_type: body.db_type || null, db_host: body.db_host || null, db_port: body.db_port || null, db_name: body.db_name || null, db_user: body.db_user || null, db_password_set: !!body.db_password,
    }
    db.websites.push(w)
    return { data: w }
  }],
  ['put', /^\/websites\/(\d+)$/, (m, p, body) => {
    const w = findById(db.websites, m[1])
    if (!w) return { data: {} }
    Object.assign(w, body || {})
    if (body && 'server_id' in body) w.server = serverRef(body.server_id)
    return { data: w }
  }],
  ['delete', /^\/websites\/(\d+)$/, (m) => { removeById(db.websites, m[1]); return { message: '已删除' } }],
  ['post', /^\/websites\/(\d+)\/sync-now$/, () => ({ message: '已下发同步指令，最长 60 秒内 Agent 会执行' })],
  // 指令状态查询（绑定/自主备份/增量备份下发后前端轮询执行结果）
  ['get', /^\/agent-commands\/(\d+)$/, (m) => ({
    command: db.commands[m[1]] || { id: Number(m[1]), status: 'success', output: '指令已完成（演示环境）' },
  })],
  ['post', /^\/websites\/(\d+)\/bind-root$/, (m, p, body) => {
    const w = findById(db.websites, m[1])
    if (!w) return { __error: { status: 404, message: '网站不存在' } }
    if (w.server?.connect_type !== 'agent') {
      return { __error: { status: 422, message: '该网站所属服务器不是 Agent 模式，无法绑定' } }
    }
    let root = (body.direct_path || '').trim()
    if (!root) {
      const base = ((body.search_bases || [])[0] || '/www/wwwroot').trim().replace(/\/$/, '')
      root = `${base}/${w.domain || w.name}`
    }
    w.web_root = root
    w.root_bound_at = new Date().toISOString()
    const cmdId = nextId('cmd')
    registerDemoCommand(cmdId, '已绑定网站根目录（演示环境）')
    return { message: '已下发绑定指令，最长 60 秒内 Agent 会查找密钥文件', command_id: cmdId }
  }],
  ['post', /^\/websites\/(\d+)\/self-backup$/, (m) => {
    const w = findById(db.websites, m[1])
    if (!w) return { __error: { status: 404, message: '网站不存在' } }
    // 与后端 selfBackupNow 同序校验：Agent 模式 + self 来源
    if (w.server?.connect_type !== 'agent') {
      return { __error: { status: 422, message: '该网站所属服务器不是 Agent 模式，无法自主备份' } }
    }
    if (w.backup_source !== 'self') {
      return { __error: { status: 422, message: '该网站未启用自主压缩备份（backup_source=self）' } }
    }
    const safe = String(w.name || 'site').replace(/[^A-Za-z0-9._-]/g, '_')
    const ts = tsStamp()
    const at = new Date().toISOString()
    const cmdId = nextId('cmd')
    registerDemoCommand(cmdId, '自主备份完成：已打包网站目录与数据库并上传（演示环境）')

    const addSelfLog = (filename, fullPath, size) => {
      const id = nextId('log')
      db.logs.unshift({
        ...mkLog(id, w.name, w.server?.name, size, 'success', 0, 30),
        id,
        website: { name: w.name, domain: w.domain },
        server: w.server,
        website_id: w.id,
        server_id: w.server_id,
        filename,
        full_path: fullPath,
        size,
        status: 'success',
        // backup_at/synced_at 取点击时刻：mkLog 的 minutesAgo=0 只能取到模块加载时间
        backup_at: at,
        synced_at: at,
        created_at: at,
        upload_status: 'uploaded',
        uploads: [{
          id: id * 10 + 1,
          storage_name: db.storages[0]?.name || 'Hetzner 主存储',
          storage_type: 'sftp',
          status: 'uploaded',
          uploaded_bytes: size,
          duration_seconds: 40,
          attempts: 1,
          finished_at: at,
          error_message: null,
          remote_path: `/backups/${w.name}/${filename}`,
        }],
      })
    }
    addSelfLog(`${safe}-web-${ts}.tar.gz`, `${w.web_root || '/www'}/backup/${safe}-web-${ts}.tar.gz`, 1.5 * GB)
    addSelfLog(`${safe}-db-${ts}.sql.gz`, `${w.web_root || '/www'}/backup/${safe}-db-${ts}.sql.gz`, 320 * MB)

    return { message: '已下发自主备份指令，最长 60 秒后 Agent 会执行（打包网站目录 + 导出数据库并上传）', command_id: cmdId }
  }],
  // v1.2.17 增量备份：与后端 incrementalBackupNow 同序校验（Agent 模式 + self 来源 + incremental 模式 + 目标存储）
  ['post', /^\/websites\/(\d+)\/incremental-backup$/, (m) => {
    const w = findById(db.websites, m[1])
    if (!w) return { __error: { status: 404, message: '网站不存在' } }
    if (w.server?.connect_type !== 'agent') {
      return { __error: { status: 422, message: '该网站所属服务器不是 Agent 模式，无法增量备份' } }
    }
    if (w.backup_source !== 'self') {
      return { __error: { status: 422, message: '该网站未启用自主压缩备份（backup_source=self）' } }
    }
    if ((w.backup_mode || 'full') !== 'incremental') {
      return { __error: { status: 422, message: '该网站未启用增量备份（backup_mode=incremental）' } }
    }
    if (!w.incremental_storage_id) {
      return { __error: { status: 422, message: '未配置增量备份目标存储，请在编辑网站时选择' } }
    }
    const incStorage = findById(db.storages, w.incremental_storage_id)
    const safe = String(w.name || 'site').replace(/[^A-Za-z0-9._-]/g, '_')
    const ts = tsStamp()
    const at = new Date().toISOString()
    const cmdId = nextId('cmd')
    registerDemoCommand(cmdId, '增量备份完成：rsync 快照已同步到目标存储（演示环境）')
    // 远端快照目录：<base_path>/<safe>/<stamp>（full_path 不带 bc_ 前缀，remote_path 带 bc_<sid>: 前缀）
    const remoteDir = `${incStorage?.base_path || '/backup'}/${safe}/${ts}`
    const remotePrefix = `bc_${w.incremental_storage_id}:${remoteDir}`

    const addIncLog = (filename, fullPath, size, remotePath) => {
      const id = nextId('log')
      db.logs.unshift({
        ...mkLog(id, w.name, w.server?.name, size, 'success', 0, 30),
        id,
        // 增量两条日志同 batch_code（incremental_backup 指令 ID），恢复页按编码精确分组
        batch_code: cmdId,
        website: { name: w.name, domain: w.domain },
        server: w.server,
        website_id: w.id,
        server_id: w.server_id,
        filename,
        full_path: fullPath,
        size,
        status: 'success',
        backup_at: at,
        synced_at: at,
        created_at: at,
        upload_status: 'uploaded',
        uploads: [{
          id: id * 10 + 1,
          storage_name: incStorage?.name || 'VPS 增量存储',
          storage_type: 'sftp',
          status: 'uploaded',
          uploaded_bytes: size,
          duration_seconds: 40,
          attempts: 1,
          finished_at: at,
          error_message: null,
          remote_path: remotePath,
        }],
      })
    }
    // 快照日志（目录）+ 随快照存档的数据库全量导出
    addIncLog(`${safe}-snap-${ts}`, remoteDir, 1.6 * GB, remotePrefix)
    addIncLog(`${safe}-db-${ts}.sql.gz`, `${w.web_root || '/www'}/backup/${safe}-db-${ts}.sql.gz`, 320 * MB, `${remotePrefix}/${safe}-db-${ts}.sql.gz`)

    return { message: '已下发增量备份指令，最长 60 秒后 Agent 会执行（rsync 快照 + 数据库导出并上传）', command_id: cmdId }
  }],
  ['post', /^\/system\/backup$/, () => {
    const filename = `bc-system-${new Date(NOW).toISOString().slice(0, 10)}.tar.gz`
    const targets = db.storages.filter((s) => s.is_mandatory && s.status === 'enabled')
    return {
      ok: true,
      filename,
      size: 18.6 * MB,
      uploaded: targets.map((s) => ({ storage_name: s.name, remote_path: `${s.base_path}/system/${filename}` })),
      failed: [],
    }
  }],

  /* ---------- 存储 ---------- */
  ['get', /^\/storages$/, (m, p) => paginate(db.storages.filter((s) => (
    (!p.type || s.type === p.type)
    && (!p.status || s.status === p.status)
    && matchKeyword(p.keyword, s.name, s.remark)
  )).map(safeStorage), p)],
  ['get', /^\/storages\/(\d+)$/, (m) => ({ data: safeStorage(findById(db.storages, m[1])) || {} })],
  ['post', /^\/storages$/, (m, p, body) => {
    const s = {
      id: nextId('storage'),
      name: body.name || '未命名存储',
      type: body.type || 'sftp',
      status: body.status || 'enabled',
      is_mandatory: !!body.is_mandatory,
      quota_bytes: Number(body.quota_bytes) || 0,
      used_bytes: 0,
      websites_count: 0,
      upload_count: 0,
      base_path: body.base_path || '/',
      last_test_status: null,
      last_tested_at: null,
      access_url: body.access_url || '',
      remark: body.remark || '',
      config: { ...(body.config || {}), encryption: resolveEncryption(body.config?.encryption) },
    }
    db.storages.push(s)
    return { data: safeStorage(s) }
  }],
  ['put', /^\/storages\/(\d+)$/, (m, p, body) => {
    const s = findById(db.storages, m[1])
    if (!s) return { data: {} }
    const next = { ...(body || {}) }
    if (body?.config) {
      // 对齐后端 normalizeConfig：请求没带 encryption 但库里有时以旧配置为准（避免 partial update 悄悄关掉加密）；
      // 带了则以请求为准，password 留空/占位符 = 不修改
      const incoming = body.config.encryption ?? s.config?.encryption
      next.config = { ...body.config, encryption: resolveEncryption(incoming, s.config?.encryption) }
    }
    Object.assign(s, next)
    return { data: safeStorage(s) }
  }],
  ['delete', /^\/storages\/(\d+)$/, (m) => { removeById(db.storages, m[1]); return { message: '已删除' } }],
  ['post', /^\/storages\/(\d+)\/test$/, (m) => {
    const s = findById(db.storages, m[1])
    if (!s) return { ok: false, message: '存储不存在' }
    s.last_test_status = 'ok'
    s.last_tested_at = new Date().toISOString()
    return { ok: true, message: '连接成功（演示环境不会发起真实连接）' }
  }],
  ['get', /^\/storages\/(\d+)\/files$/, (m, p) => browseStorageFiles(Number(m[1]), p?.path || '', p?.website_id ? Number(p.website_id) : null)],
  ['post', /^\/storages\/preview-files$/, (m, p, body) => previewStorageFiles(body)],

  /* ---------- 备份记录 ---------- */
  ['get', /^\/backup-logs$/, (m, p) => paginate(db.logs.filter((l) => (
    (!p.status || l.status === p.status)
    && (!p.website_id || String(l.website_id) === String(p.website_id))
    && (!p.server_id || String(l.server_id) === String(p.server_id))
    && matchKeyword(p.keyword, l.filename, l.full_path)
  )), p)],
  ['get', /^\/backup-logs\/(\d+)$/, (m) => ({ data: findById(db.logs, m[1]) || {} })],
  ['delete', /^\/backup-logs\/(\d+)$/, (m) => { removeById(db.logs, m[1]); return { message: '已删除' } }],
  ['post', /^\/backup-logs\/(\d+)\/restore$/, (m, p, body) => {
    const log = findById(db.logs, m[1])
    if (!log) return { message: '备份记录不存在' }
    // 记录该 upload 已被"恢复"，供 restore-status 模拟已恢复态
    if (body && body.upload_id != null) {
      if (!db.restored) db.restored = {}
      db.restored[body.upload_id] = true
    }
    return {
      message: '演示环境：已模拟下发恢复指令（实际环境会由 Agent 从云端拉取归档回服务器本地目录，不自动解包 / 不自动导入数据库）',
      command_id: 900000 + Number(m[1]),
      local_dir: log.full_path ? log.full_path.replace(/\/[^/]+$/, '') : '/var/backups',
      filename: log.filename,
    }
  }],
  ['get', /^\/backup-logs\/(\d+)\/restore-status$/, (m) => {
    const log = findById(db.logs, m[1])
    const commands = {}
    if (log && db.restored) {
      (log.uploads || []).forEach((u) => {
        if (db.restored[u.id]) {
          commands[u.id] = {
            status: 'success',
            output: `已恢复到本地: ${log.full_path || '/var/backups'}`,
            error: null,
            command_id: 900000 + Number(m[1]),
            completed_at: new Date().toISOString(),
          }
        }
      })
    }
    return { commands }
  }],

  /* ---------- 数据恢复（P2：按网站+日期分组的整套恢复） ---------- */
  ['get', /^\/restore\/websites$/, () => ({
    data: [...db.websites]
      .sort((a, b) => a.name.localeCompare(b.name, 'zh'))
      .slice(0, 200)
      .map((w) => ({
        id: w.id,
        name: w.name,
        domain: w.domain,
        server_name: w.server?.name || findById(db.servers, w.server_id)?.name || null,
        restore_mode: w.restore_mode || 'lite',
        web_root: w.web_root || null,
        last_backup_at: w.recent_backups?.[0]?.log?.backup_at || null,
        last_backup_status: w.recent_backups?.[0]?.log?.status || null,
      })),
  })],
  ['get', /^\/restore\/backups$/, (m, p) => {
    const website = findById(db.websites, Number(p.website_id))
    if (!website) return { data: [], active_commands: [] }
    const days = RANGE_DAYS[p.range] || 30
    const since = Date.now() - days * 86400000

    // 1) 收集文件（与后端同口径：范围内全部日志，含上传失败的——restorable=false）
    const files = []
    db.logs
      .filter((l) => l.website_id === website.id && new Date(l.backup_at).getTime() >= since)
      .sort((a, b) => new Date(b.backup_at) - new Date(a.backup_at) || b.id - a.id)
      .forEach((l) => {
        const latest = latestUpload(l)
        files.push({
          backup_log_id: l.id,
          label: fileLabel(l, website),
          type: fileType(l.filename),
          filename: l.filename,
          full_path: l.full_path,
          size: l.size,
          backup_at: l.backup_at,
          batch_code: String(l.batch_code ?? ''),
          restorable: !!latest,
          latest_upload: latest ? {
            id: latest.id,
            storage_name: latest.storage_name,
            storage_type: latest.storage_type,
            remote_path: latest.remote_path,
            uploaded_at: latest.finished_at || null,
          } : null,
        })
      })

    // 2) 套件合并：batch_code 精确成组 → 类型配对 → 300s 窗口兜底（buildSuites 与后端同策略）
    const suites = buildSuites(files)

    // 3) 每个 backup_log 取最新一条 restore 命令（含终态，用于「已恢复」徽标）
    const latestByLog = {}
    db.restoreCommands
      .slice()
      .sort((a, b) => b.id - a.id)
      .forEach((c) => {
        const logId = String(c.params.backup_log_id)
        if (!latestByLog[logId]) latestByLog[logId] = c
      })

    // 4) 日期分组：按文件级 backup_at 归属；suite 跨天时在多个日期组呈现，统计与状态按天重算
    const dayGroups = {}
    for (const suite of suites) {
      const byDate = {}
      for (const f of suite.files) {
        const d = localDate(f.backup_at)
        if (!byDate[d]) byDate[d] = []
        byDate[d].push(f)
      }
      for (const date of Object.keys(byDate)) {
        const dayFiles = byDate[date] // 组内已按 backup_at 倒序
        const states = []
        for (const f of dayFiles) {
          const cmd = latestByLog[String(f.backup_log_id)]
          if (cmd) states.push(cmd.status)
        }
        const times = [...new Set(dayFiles.map((f) => f.backup_at).filter(Boolean))]
        if (!dayGroups[date]) dayGroups[date] = []
        dayGroups[date].push({
          suite_id: suite.suite_id,
          backup_at: dayFiles[0].backup_at,
          // match：当天文件 backup_at 是否完全一致（self 精确合并 / external 窗口合并）
          match: times.length <= 1 ? 'exact' : 'window',
          total_count: dayFiles.length,
          restorable_count: dayFiles.filter((f) => f.restorable).length,
          total_size: dayFiles.reduce((sum, f) => sum + (f.size || 0), 0),
          restore_state: aggregateRestoreState(states),
          files: dayFiles,
        })
      }
    }

    // 日期倒序 + 组内套件倒序（新→旧）
    const out = Object.keys(dayGroups).sort().reverse().map((date) => ({
      date,
      suites: dayGroups[date].sort((a, b) => new Date(b.backup_at) - new Date(a.backup_at)),
    }))

    // 5) 进行中的恢复命令（pending/dispatched）：刷新后恢复进度展示与重复下发防护
    const logIds = files.map((f) => f.backup_log_id)
    const active = []
    for (const logId of Object.keys(latestByLog)) {
      const cmd = latestByLog[logId]
      if (!logIds.includes(Number(logId))) continue
      if (cmd.status !== 'pending' && cmd.status !== 'dispatched') continue
      active.push({
        backup_log_id: Number(logId),
        command_id: cmd.id,
        status: cmd.status,
        filename: (cmd.params.remote_path || '').split('/').pop(),
        local_dir: cmd.params.local_dir || '',
      })
    }

    return { data: out, active_commands: active }
  }],
  ['post', /^\/restore$/, (m, p, body) => {
    const results = {}
    const skipped = []
    ;[].concat(body?.backup_log_ids || []).map(Number).forEach((id) => {
      const log = findById(db.logs, id)
      if (!log) { skipped.push({ backup_log_id: id, reason: '备份记录不存在' }); return }
      const upload = latestUpload(log)
      if (!upload) { skipped.push({ backup_log_id: log.id, reason: '没有成功上传的云端副本，无法恢复' }); return }
      const server = findById(db.servers, log.server_id)
      if (!server) { skipped.push({ backup_log_id: log.id, reason: '找不到所属服务器，无法下发指令' }); return }
      const website = findById(db.websites, log.website_id)
      const isAdvanced = website?.restore_mode === 'advanced'
      // advanced 恢复目标为线上 web_root（Agent 先下载到中转目录再覆盖），lite 保持原备份目录
      const localDir = isAdvanced
        ? (website?.web_root || '/var/www/html')
        : (log.full_path ? log.full_path.replace(/\/[^/]+$/, '') : '')
      if (!isAdvanced && (!localDir || localDir === '.')) { skipped.push({ backup_log_id: log.id, reason: '备份记录缺少本地路径，无法定位恢复目录' }); return }
      // 去重：同一 upload 已有进行中的恢复命令则跳过该文件（不做服务器级 409）
      const conflict = db.restoreCommands.some((c) =>
        c.params.upload_id === upload.id && (c.params.restore_mode || 'lite') === (isAdvanced ? 'advanced' : 'lite') &&
        (c.status === 'pending' || c.status === 'dispatched'))
      if (conflict) { skipped.push({ backup_log_id: log.id, reason: '该文件已有恢复任务在进行中' }); return }

      const filename = upload.remote_path.split('/').pop()
      const cmd = {
        id: nextId('cmd'),
        server_id: server.id,
        type: 'restore',
        status: 'pending',
        output: null,
        error: null,
        completed_at: null,
        dispatched_at: null,
        created_at: new Date().toISOString(),
        params: {
          backup_log_id: log.id,
          upload_id: upload.id,
          remote_path: upload.remote_path,
          local_dir: localDir,
          filename,
          ...(isAdvanced ? { restore_mode: 'advanced', website_id: website.id } : {}),
        },
      }
      db.restoreCommands.push(cmd)
      scheduleRestore(cmd)
      results[String(log.id)] = {
        command_id: cmd.id,
        status: cmd.status,
        filename,
        local_dir: localDir,
      }
    })

    if (!Object.keys(results).length) return { message: '没有可下发的恢复任务', skipped }
    return {
      message: `已下发 ${Object.keys(results).length} 个恢复指令，Agent 将在下次心跳（≤60s）起陆续执行`,
      data: results,
      skipped,
    }
  }],
  ['get', /^\/restore\/status$/, (m, p) => {
    const ids = [].concat(p.backup_log_ids || []).map(Number).filter(Boolean)
    const out = {}
    db.restoreCommands
      .slice()
      .sort((a, b) => b.id - a.id)
      .forEach((c) => {
        const logId = String(c.params.backup_log_id)
        if (!ids.includes(Number(logId)) || out[logId]) return
        out[logId] = {
          status: c.status,
          output: c.output,
          error: c.error,
          command_id: c.id,
          upload_id: c.params.upload_id,
          completed_at: c.completed_at,
        }
      })
    return { data: out }
  }],

  /* ---------- 资源池 ---------- */
  ['get', /^\/resource-pools$/, () => ({ data: db.pools })],
  ['post', /^\/resource-pools$/, (m, p, body) => {
    const pool = {
      id: nextId('pool'),
      name: body.name || '未命名资源池',
      provider: body.provider || 's3',
      endpoint: body.endpoint || '',
      region: body.region || '',
      bucket: body.bucket || '',
      public_domain: body.public_domain || '',
      public_url_prefix: body.public_domain
        ? String(body.public_domain).replace(/\/$/, '')
        : `https://${body.bucket || 'bucket'}.${body.endpoint || 'example.com'}`,
      is_agent_mirror: !!body.is_agent_mirror,
      status: body.status || 'enabled',
      has_credentials: !!(body.access_key_id && body.secret_access_key),
      last_test_status: null,
      remark: body.remark || '',
    }
    if (pool.is_agent_mirror) db.pools.forEach((x) => { x.is_agent_mirror = false })
    db.pools.push(pool)
    db.files[pool.id] = []
    return { data: pool }
  }],
  ['put', /^\/resource-pools\/(\d+)$/, (m, p, body) => {
    const pool = findById(db.pools, m[1])
    if (!pool) return { data: {} }
    Object.assign(pool, body || {})
    if (body && (body.access_key_id || body.secret_access_key)) pool.has_credentials = true
    if (pool.is_agent_mirror) db.pools.forEach((x) => { if (x.id !== pool.id) x.is_agent_mirror = false })
    return { data: pool }
  }],
  ['delete', /^\/resource-pools\/(\d+)$/, (m) => { removeById(db.pools, m[1]); return { message: '已删除' } }],
  ['post', /^\/resource-pools\/(\d+)\/test$/, (m) => {
    const pool = findById(db.pools, m[1])
    if (!pool) return { ok: false, message: '资源池不存在' }
    pool.last_test_status = 'ok'
    return { ok: true, message: '连接成功（演示环境不会发起真实连接）' }
  }],
  ['get', /^\/resource-pools\/(\d+)\/files$/, (m) => ({ ok: true, files: db.files[m[1]] || [] })],
  ['post', /^\/resource-pools\/(\d+)\/files$/, (m, p, body) => {
    const poolId = m[1]
    const file = body instanceof FormData ? body.get('file') : null
    const customName = body instanceof FormData ? body.get('name') : null
    const name = customName || file?.name || `upload-${Date.now()}`
    const entry = {
      name,
      path: `uploads/${name}`,
      size: file?.size || Math.round(1 * MB + Math.random() * 20 * MB),
      modified: new Date().toISOString(),
      remark: '',
      public_url: `${findById(db.pools, poolId)?.public_url_prefix || 'https://demo.example.com'}/uploads/${name}`,
    }
    if (!db.files[poolId]) db.files[poolId] = []
    db.files[poolId].unshift(entry)
    return { ok: true, file: entry }
  }],
  ['delete', /^\/resource-pools\/(\d+)\/files$/, (m, p, body) => {
    const list = db.files[m[1]]
    if (list) {
      const i = list.findIndex((f) => f.path === body?.path)
      if (i >= 0) list.splice(i, 1)
    }
    return { ok: true, message: '已删除' }
  }],
  ['post', /^\/resource-pools\/(\d+)\/files\/remark$/, (m, p, body) => {
    const f = (db.files[m[1]] || []).find((x) => x.path === body?.path)
    const remark = body?.remark || null
    if (f) f.remark = remark
    return { ok: true, remark }
  }],

  /* ---------- 设置 ---------- */
  ['get', /^\/notification-settings$/, () => ({ data: db.notification })],
  ['put', /^\/notification-settings$/, (m, p, body) => {
    const hasKey = !!body.serverchan_sendkey && !String(body.serverchan_sendkey).includes('••••')
    Object.assign(db.notification, {
      notify_backup_success: !!body.notify_backup_success,
      notify_backup_failed: !!body.notify_backup_failed,
      notify_agent_offline: !!body.notify_agent_offline,
      updated_at: new Date().toISOString(),
    })
    if (body.serverchan_sendkey === '' || body.serverchan_sendkey === null) {
      db.notification.has_sendkey = false
      db.notification.sendkey_hint = null
    } else if (hasKey) {
      const raw = String(body.serverchan_sendkey)
      db.notification.has_sendkey = true
      db.notification.sendkey_hint = raw.slice(0, 3) + '••••••••••••' + raw.slice(-4)
    }
    return { data: db.notification }
  }],
  ['post', /^\/notification-settings\/test$/, () => ({
    ok: true,
    message: '测试通知已发出（演示环境不会真正推送微信）',
    raw: JSON.stringify({ code: 0, message: 'success', data: { pushid: 'demo' } }, null, 2),
  })],
  ['get', /^\/system-settings$/, () => ({ data: db.settings })],
  ['put', /^\/system-settings$/, (m, p, body) => {
    const { footer_config, ...rest } = body || {}
    Object.assign(db.settings, rest)
    if (footer_config) db.settings.footer_config = { ...db.settings.footer_config, ...footer_config }
    return { data: db.settings }
  }],
  ['get', /^\/system\/footer-stats$/, () => ({
    ok: true,
    stats: {
      servers: db.servers.length,
      online_servers: db.servers.filter((s) => s.status === 'online').length,
      websites: db.websites.length,
      backup_logs: 15832,
      total_size: 612 * GB,
    },
  })],
]

/* ============================================================
   axios adapter
   ============================================================ */
const normalizeUrl = (url = '') => {
  let u = String(url).split('?')[0]
  if (u.startsWith('/api/')) u = u.slice(4)
  else if (u === '/api') u = ''
  if (!u.startsWith('/')) u = '/' + u
  return u.length > 1 ? u.replace(/\/$/, '') : u
}

const parseBody = (data) => {
  if (data === undefined || data === null || data === '') return {}
  if (typeof data !== 'string') return data // FormData 等原样透传
  try { return JSON.parse(data) } catch (_) { return {} }
}

const respond = (data, config) => ({
  data,
  status: 200,
  statusText: 'OK',
  headers: {},
  config,
  request: {},
})

const delay = (ms) => new Promise((resolve) => setTimeout(resolve, ms))

export async function mockAdapter(config) {
  const method = (config.method || 'get').toLowerCase()
  const url = normalizeUrl(config.url)
  const params = config.params || {}
  const body = parseBody(config.data)

  // 让 loading 态看起来真实
  await delay(120 + Math.random() * 140)

  for (const [m, pattern, handler] of routes) {
    if (m !== method) continue
    const match = url.match(pattern)
    if (!match) continue
    const result = handler(match, params, body)
    // 处理器可用 { __error: { status, message } } 模拟后端错误响应（走与线上一致的拦截器逻辑）
    if (result && typeof result === 'object' && result.__error) {
      const err = new Error(result.__error.message || '请求失败')
      err.response = {
        data: { message: result.__error.message },
        status: result.__error.status || 422,
        statusText: '',
        headers: {},
        config,
      }
      err.config = config
      throw err
    }
    return respond(result, config)
  }

  console.warn('[demo] 未覆盖的接口', method, url)
  return respond({ data: [] }, config)
}
