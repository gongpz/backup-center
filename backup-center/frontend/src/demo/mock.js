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

const db = {
  seq: { server: 7, website: 9, storage: 5, log: 15833, pool: 2, cmd: 100 },
  latestAgentVersion: '0.14.0',

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
    { id: 1, name: '官网主站', domain: 'www.example.com', server_id: 1, server: { name: 'hk-prod-01', connect_type: 'agent' }, backup_paths: [{ label: '站点根目录', path: '/var/www/html', pattern: '' }, { label: 'Nginx 配置', path: '/etc/nginx', pattern: '' }], recent_backups: [{ log: mkLog(15832, '官网主站', 'hk-prod-01', 2.4 * GB, 'success', 4) }], schedule: '0 2 * * *', sync_mode: 'auto', retention_days: 30, status: 'enabled', remark: '对外主站（自主压缩演示）', storage_ids: [1, 2], backup_source: 'self', restore_mode: 'lite', bind_token: randToken(), web_root: '/var/www/html', root_bound_at: hours(72), db_type: 'mysql', db_host: '127.0.0.1', db_port: 3306, db_name: 'main_site', db_user: 'root', db_password_set: true },
    { id: 2, name: '客户门户', domain: 'portal.example.com', server_id: 1, server: { name: 'hk-prod-01', connect_type: 'agent' }, backup_paths: [{ label: '备份目录1', path: '/srv/portal', pattern: '' }], recent_backups: [{ log: mkLog(15831, '客户门户', 'hk-prod-01', 1.8 * GB, 'success', 9) }], schedule: '0 3 * * *', sync_mode: 'auto', retention_days: 60, status: 'enabled', remark: '', storage_ids: [1], backup_source: 'external', restore_mode: 'lite', bind_token: randToken(), web_root: '/srv/portal', root_bound_at: hours(96), db_type: null, db_host: null, db_port: null, db_name: null, db_user: null, db_password_set: false },
    { id: 3, name: '电商平台', domain: 'shop.example.com', server_id: 2, server: { name: 'sg-db-02', connect_type: 'agent' }, backup_paths: [{ label: '商品数据', path: '/data/shop', pattern: '' }, { label: 'MySQL 转储', path: '/data/mysql', pattern: '*.sql.gz' }], recent_backups: [{ log: mkLog(15830, '电商平台', 'sg-db-02', 5.1 * GB, 'failed', 15) }], schedule: '0 */6 * * *', sync_mode: 'scheduled', retention_days: 90, status: 'enabled', remark: '核心业务', storage_ids: [1, 2, 3], backup_source: 'external', restore_mode: 'advanced', bind_token: randToken(), web_root: '/var/www/shop', root_bound_at: hours(48), db_type: 'mysql', db_host: '127.0.0.1', db_port: 3306, db_name: 'shop_main', db_user: 'root', db_password_set: true },
    { id: 4, name: '数据中台', domain: 'data.example.internal', server_id: 2, server: { name: 'sg-db-02', connect_type: 'agent' }, backup_paths: [{ label: '数仓', path: '/data/warehouse', pattern: '' }], recent_backups: [{ log: mkLog(15829, '数据中台', 'sg-db-02', 3.6 * GB, 'success', 22) }], schedule: '30 1 * * 1', sync_mode: 'auto', retention_days: 180, status: 'enabled', remark: '', storage_ids: [2], backup_source: 'external', restore_mode: 'lite', bind_token: randToken(), web_root: '/data/warehouse', root_bound_at: hours(120), db_type: null, db_host: null, db_port: null, db_name: null, db_user: null, db_password_set: false },
    { id: 5, name: '营销落地页', domain: 'lp.example.com', server_id: 3, server: { name: 'jp-edge-03', connect_type: 'ssh' }, backup_paths: [{ label: '页面静态资源', path: '/usr/share/nginx/lp', pattern: '' }], recent_backups: [{ log: mkLog(15828, '营销落地页', 'jp-edge-03', 420 * MB, 'running', 26) }], schedule: '0 4 * * *', sync_mode: 'auto', retention_days: 14, status: 'enabled', remark: '', storage_ids: [2], backup_source: 'external', restore_mode: 'lite', bind_token: randToken(), web_root: null, root_bound_at: null, db_type: null, db_host: null, db_port: null, db_name: null, db_user: null, db_password_set: false },
    { id: 6, name: '内部 OA', domain: 'oa.example.internal', server_id: 1, server: { name: 'hk-prod-01', connect_type: 'agent' }, backup_paths: [{ label: 'OA 程序目录', path: '/opt/oa', pattern: '' }], recent_backups: [{ log: mkLog(15827, '内部 OA', 'hk-prod-01', 900 * MB, 'success', 38) }], schedule: '0 5 * * *', sync_mode: 'auto', retention_days: 30, status: 'enabled', remark: '', storage_ids: [1], backup_source: 'external', restore_mode: 'lite', bind_token: randToken(), web_root: null, root_bound_at: null, db_type: null, db_host: null, db_port: null, db_name: null, db_user: null, db_password_set: false },
    { id: 7, name: 'API 网关', domain: 'api.example.com', server_id: 3, server: { name: 'jp-edge-03', connect_type: 'ssh' }, backup_paths: [{ label: 'Kong 配置', path: '/etc/kong', pattern: '' }], recent_backups: [{ log: mkLog(15826, 'API 网关', 'jp-edge-03', 260 * MB, 'success', 52) }], schedule: '0 6 * * *', sync_mode: 'auto', retention_days: 30, status: 'enabled', remark: '', storage_ids: [2], backup_source: 'external', restore_mode: 'lite', bind_token: randToken(), web_root: null, root_bound_at: null, db_type: null, db_host: null, db_port: null, db_name: null, db_user: null, db_password_set: false },
    { id: 8, name: '旧版官网', domain: 'old.example.com', server_id: 4, server: { name: 'us-backup-04', connect_type: 'agent' }, backup_paths: [{ label: '旧站目录', path: '/var/www/old', pattern: '' }], recent_backups: [{ log: mkLog(15825, '旧版官网', 'us-backup-04', 1.2 * GB, 'success', 320) }], schedule: '0 2 * * 0', sync_mode: 'auto', retention_days: 7, status: 'disabled', remark: '已下线，保留归档', storage_ids: [1], backup_source: 'external', restore_mode: 'lite', bind_token: randToken(), web_root: null, root_bound_at: null, db_type: null, db_host: null, db_port: null, db_name: null, db_user: null, db_password_set: false },
  ],

  storages: [
    { id: 1, name: 'Hetzner 主存储', type: 'sftp', status: 'enabled', is_mandatory: true, quota_bytes: 500 * GB, used_bytes: 318 * GB, websites_count: 16, upload_count: 9820, base_path: '/backups/primary', last_test_status: 'ok', last_tested_at: mins(35), access_url: 'https://u12345.your-storagebox.de', remark: '欧洲主力存储盒', config: { host: 'u12345.your-storagebox.de', port: 23, user: 'u12345', password: '' } },
    { id: 2, name: 'Backblaze B2', type: 's3', status: 'enabled', is_mandatory: false, quota_bytes: 1000 * GB, used_bytes: 286 * GB, websites_count: 18, upload_count: 7412, base_path: 'bc-backups', last_test_status: 'ok', last_tested_at: mins(120), access_url: '', remark: '异地对象存储', config: { endpoint: 'https://s3.us-west-000.backblazeb2.com', region: 'us-west-000', bucket: 'bc-backups', access_key_id: '000b2c3d4e5f6000', secret_access_key: '' } },
    { id: 3, name: 'Nextcloud 异地', type: 'webdav', status: 'enabled', is_mandatory: false, quota_bytes: 300 * GB, used_bytes: 264 * GB, websites_count: 9, upload_count: 3180, base_path: '/remote.php/dav', last_test_status: 'failed', last_tested_at: mins(280), access_url: 'https://cloud.example.com', remark: '最近连接异常', config: { url: 'https://cloud.example.com/remote.php/dav', vendor: 'nextcloud', user: 'backup', password: '' } },
    { id: 4, name: '本地 NAS', type: 'ftp', status: 'disabled', is_mandatory: false, quota_bytes: 0, used_bytes: 96 * GB, websites_count: 0, upload_count: 640, base_path: '/volume1/backup', last_test_status: 'ok', last_tested_at: mins(1440), access_url: '', remark: '机房内网', config: { host: '10.0.0.9', port: 21, user: 'backup', password: '' } },
  ],

  logs: [],
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
    const addSelfLog = (filename, size) => {
      const id = nextId('log')
      const at = hours(2)
      db.logs.unshift({
        ...mkLog(id, w.name, w.server?.name, size, 'success', 120, 60),
        id,
        website: { name: w.name, domain: w.domain },
        server: w.server,
        website_id: w.id,
        server_id: w.server_id,
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
    addSelfLog('main_site-web-20261014-020001.tar.gz', 2.2 * GB)
    addSelfLog('main_site-db-20261014-030000.sql.gz', 360 * MB)
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

const fileType = (filename) => (/\.sql(\.|$)/i.test(filename || '') ? 'database' : 'website')

const fileLabel = (log, website) => {
  const type = fileType(log.filename)
  const fallback = type === 'database' ? '数据库' : '网站目录'
  const fullPath = log.full_path || ''
  for (const bp of website.backup_paths || []) {
    if (bp.path && fullPath.startsWith(bp.path)) return bp.label || fallback
  }
  return fallback
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
  const db = pick(/^.*-db-.*\.sql\.gz$/)
  if (db) out.push({ label: '数据库', path: '（数据库）', log: shape(db) })
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
  const finish = () => {
    if (cmd.status !== 'pending' && cmd.status !== 'dispatched') return
    cmd.status = 'success'
    cmd.completed_at = new Date().toISOString()
    cmd.output = advanced
      ? (isDb
        ? `高级恢复完成：数据库已重建导入（预备份 ${cmd.params.local_dir}.bak-*.sql）`
        : `高级恢复完成：网站目录已覆盖（预备份 ${cmd.params.local_dir}.bak-*.tar.gz）`)
      : `已恢复 ${cmd.params.filename} → ${cmd.params.local_dir}`
  }
  setTimeout(() => { if (cmd.status === 'pending') cmd.status = 'dispatched' }, 6000)
  if (!advanced) {
    setTimeout(finish, 14000)
    return
  }
  // advanced：模拟 Agent 的多步执行（预备份 → 解压/导库），进度经 output 逐步透出
  const steps = isDb
    ? ['[预备份] 数据库 → ' + cmd.params.local_dir + '.bak-*.sql', '[导入] 解压数据库归档…', '[导入] 重建并导入数据库…']
    : ['[预备份] 网站目录 → ' + cmd.params.local_dir + '.bak-*.tar.gz', '[解压] 正在覆盖网站根目录…']
  steps.forEach((s, i) => setTimeout(() => { if (cmd.status !== 'success') cmd.output = s }, 8000 + i * 5000))
  setTimeout(finish, 8000 + steps.length * 5000)
}

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
    },
    count_trend: buckets.map((b) => ({ label: b.label, value: b.count })),
    size_trend: buckets.map((b) => ({ label: b.label, value: b.size })),
    recent_logs: db.logs.slice(0, 10),
    storages: db.storages,
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
      restore_mode: body.restore_mode || 'lite',
      bind_token: randToken(),
      web_root: null,
      root_bound_at: null,
      db_type: null, db_host: null, db_port: null, db_name: null, db_user: null, db_password_set: false,
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
    return { message: '已下发绑定指令，最长 60 秒内 Agent 会查找密钥文件', command_id: nextId('cmd') }
  }],
  ['post', /^\/websites\/(\d+)\/self-backup$/, (m) => {
    const w = findById(db.websites, m[1])
    if (!w) return { __error: { status: 404, message: '网站不存在' } }
    if (w.backup_source !== 'self') {
      return { __error: { status: 422, message: '该网站不是「自主压缩」来源，无法立即自主备份' } }
    }
    const safe = String(w.name || 'site').replace(/[^A-Za-z0-9._-]/g, '_')
    const now = new Date()
    const p2 = (n) => String(n).padStart(2, '0')
    const ts = `${now.getFullYear()}${p2(now.getMonth() + 1)}${p2(now.getDate())}-${p2(now.getHours())}${p2(now.getMinutes())}${p2(now.getSeconds())}`
    const at = new Date().toISOString()
    const cmdId = nextId('cmd')

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

    return { message: '已下发自主备份指令，最长 60 秒内 Agent 会执行', command_id: cmdId }
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
  )), p)],
  ['get', /^\/storages\/(\d+)$/, (m) => ({ data: findById(db.storages, m[1]) || {} })],
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
      config: body.config || {},
    }
    db.storages.push(s)
    return { data: s }
  }],
  ['put', /^\/storages\/(\d+)$/, (m, p, body) => {
    const s = findById(db.storages, m[1])
    if (!s) return { data: {} }
    Object.assign(s, body || {})
    return { data: s }
  }],
  ['delete', /^\/storages\/(\d+)$/, (m) => { removeById(db.storages, m[1]); return { message: '已删除' } }],
  ['post', /^\/storages\/(\d+)\/test$/, (m) => {
    const s = findById(db.storages, m[1])
    if (!s) return { ok: false, message: '存储不存在' }
    s.last_test_status = 'ok'
    s.last_tested_at = new Date().toISOString()
    return { ok: true, message: '连接成功（演示环境不会发起真实连接）' }
  }],

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
    if (!website) return { data: [] }
    const days = RANGE_DAYS[p.range] || 30
    const since = Date.now() - days * 86400000

    const groups = {}
    db.logs
      .filter((l) => l.website_id === website.id && new Date(l.backup_at).getTime() >= since)
      .sort((a, b) => new Date(b.backup_at) - new Date(a.backup_at) || b.id - a.id)
      .forEach((l) => {
        const date = localDate(l.backup_at)
        const latest = latestUpload(l)
        if (!groups[date]) groups[date] = []
        groups[date].push({
          backup_log_id: l.id,
          label: fileLabel(l, website),
          type: fileType(l.filename),
          filename: l.filename,
          full_path: l.full_path,
          size: l.size,
          backup_at: l.backup_at,
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

    return {
      data: Object.keys(groups).sort().reverse().map((date) => ({
        date,
        total_count: groups[date].length,
        restorable_count: groups[date].filter((f) => f.restorable).length,
        files: groups[date],
      })),
    }
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
