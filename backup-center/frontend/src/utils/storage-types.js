/**
 * Rclone 后端类型定义 - 每种类型的字段、示例、标签
 * 用于前端 Storage 表单动态渲染
 */

export const storageTypes = [
  { value: 'sftp',   label: 'SFTP / SSH', icon: 'Link',        color: '#0f6e8c' },
  { value: 'ftp',    label: 'FTP / FTPS', icon: 'Upload',      color: '#b08a3e' },
  { value: 'webdav', label: 'WebDAV',      icon: 'Cloudy',      color: '#2e9b76' },
  { value: 's3',     label: 'S3 兼容',      icon: 'MostlyCloudy', color: '#6e7bc8' },
]

export const typeMeta = Object.fromEntries(storageTypes.map((t) => [t.value, t]))

/**
 * 备份加密字段定义（跨类型通用，与 rclone crypt 包裹层对应）
 * 存放在 storages.config.encryption，字段名与后端/Agent 契约一致，不得更改
 * 字段类型：switch | select | password
 */
export const encryptionFields = [
  { key: 'enabled', label: '备份加密', type: 'switch', default: false },
  { key: 'key_mode', label: '密钥模式', type: 'select', default: 'center',
    options: [
      { value: 'center',      label: '中心托管',     desc: '密钥由中心托管并随配置下发至 Agent（与存储密码同信任级别）' },
      { value: 'agent_local', label: 'Agent 本地密钥', desc: '密钥由你在 Agent 主机 /etc/backup-agent/backup.key 自行管理，中心不保存、不知晓' },
    ]},
  { key: 'password', label: '加密密码', type: 'password', required: false, placeholder: '留空表示不修改' },
  { key: 'filename_encryption', label: '文件名加密', type: 'select', default: 'standard',
    options: [
      { value: 'standard', label: '标准加密' },
      { value: 'off',      label: '不加密文件名' },
    ]},
  { key: 'directory_name_encryption', label: '目录名加密', type: 'switch', default: true },
]

/** encryption 块默认值（新增存储 / 重置表单时使用） */
export const defaultEncryption = {
  enabled: false,
  key_mode: 'center',
  password: '',
  filename_encryption: 'standard',
  directory_name_encryption: true,
}

/**
 * 归一化服务端返回的 encryption 块：缺省字段补默认值；
 * password 直接透传服务端返回的明文密码（后端 safeConfig 已改为返回明文而非 ••••••••），
 * 编辑时回填展示；提交时留空即表示「不修改」（后端契约）
 */
export const normalizeEncryption = (enc) => ({
  enabled: !!enc?.enabled,
  key_mode: enc?.key_mode === 'agent_local' ? 'agent_local' : 'center',
  password: enc?.password || '',
  filename_encryption: enc?.filename_encryption === 'off' ? 'off' : 'standard',
  directory_name_encryption: enc?.directory_name_encryption !== false,
})

/**
 * 每种类型的字段定义
 * 字段类型：text | password | number | select
 */
export const typeFields = {
  sftp: [
    { key: 'host',        label: '主机', type: 'text',     required: true, placeholder: 'u12345.your-storagebox.de', row: 'host-port' },
    // row 相同的连续字段在表单里合并为一行：主机+端口（连接）、用户名+密码（凭据）
    { key: 'port',        label: '端口', type: 'number',   required: false, default: 22, placeholder: '22 (HostBrr: 23)', row: 'host-port' },
    { key: 'user',        label: '用户名', type: 'text',   required: true, placeholder: 'u12345', row: 'user-password' },
    { key: 'password',    label: '密码', type: 'password', required: false, placeholder: '密码或 SSH 私钥二选一', row: 'user-password' },
    { key: 'private_key', label: 'SSH 私钥', type: 'textarea', required: false, placeholder: '-----BEGIN OPENSSH PRIVATE KEY-----\n...\n-----END OPENSSH PRIVATE KEY-----' },
  ],
  ftp: [
    // row 相同的连续字段在表单里合并为一行：主机+端口（连接）、用户名+密码（凭据）
    { key: 'host',                 label: '主机',   type: 'text',     required: true, placeholder: 'ftpX.hostypanel.com 或 box.example.com', row: 'host-port' },
    { key: 'port',                 label: '端口',   type: 'number',   required: false, default: 21, placeholder: '21 (FTPS 通常 21 或 990)', row: 'host-port' },
    { key: 'user',                 label: '用户名', type: 'text',     required: true, row: 'user-password' },
    { key: 'password',             label: '密码',   type: 'password', required: true, row: 'user-password' },
    { key: 'tls',                  label: 'FTPS（Implicit TLS）', type: 'select', required: false, default: false,
      options: [
        { value: false, label: '关闭（标准 FTP，21 端口）' },
        { value: true,  label: '开启（Implicit FTPS，通常 990 端口）' },
      ]},
    { key: 'explicit_tls',         label: 'FTPES（Explicit TLS）', type: 'select', required: false, default: false,
      options: [
        { value: false, label: '关闭' },
        { value: true,  label: '开启（FTPES / AUTH TLS，21 端口起协商）' },
      ]},
    { key: 'no_check_certificate', label: '跳过证书校验', type: 'select', required: false, default: false,
      options: [
        { value: false, label: '否（默认，安全）' },
        { value: true,  label: '是（自签名证书环境用）' },
      ]},
  ],
  webdav: [
    { key: 'url',      label: '服务地址', type: 'text', required: true, placeholder: 'https://webdav.example.com/dav' },
    { key: 'vendor',   label: '服务提供方', type: 'select', required: false, default: 'other',
      options: [
        { value: 'other',     label: '通用 WebDAV' },
        { value: 'nextcloud', label: 'Nextcloud' },
        { value: 'owncloud',  label: 'ownCloud' },
        { value: 'sharepoint',label: 'SharePoint' },
      ]},
    { key: 'user',     label: '用户名', type: 'text', required: true },
    { key: 'password', label: '密码', type: 'password', required: false },
  ],
  s3: [
    { key: 'endpoint',          label: 'Endpoint', type: 'text', required: true, placeholder: 'https://s3.us-west-000.backblazeb2.com' },
    { key: 'region',            label: 'Region', type: 'text', required: false, placeholder: 'us-west-000' },
    { key: 'bucket',            label: 'Bucket', type: 'text', required: true, placeholder: 'my-backup' },
    { key: 'access_key_id',     label: 'Access Key ID', type: 'text', required: true },
    { key: 'secret_access_key', label: 'Secret Access Key', type: 'password', required: true },
    { key: 'path_style',        label: 'Path-style URL', type: 'select', required: false, default: false,
      options: [
        { value: false, label: '虚拟主机风格（默认）' },
        { value: true,  label: 'Path-style（MinIO 等自建常用）' },
      ]},
  ],
}
