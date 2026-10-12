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
 * 每种类型的字段定义
 * 字段类型：text | password | number | select
 */
export const typeFields = {
  sftp: [
    { key: 'host',        label: '主机', type: 'text',     required: true, placeholder: 'u12345.your-storagebox.de' },
    { key: 'port',        label: '端口', type: 'number',   required: false, default: 22, placeholder: '22 (HostBrr: 23)' },
    { key: 'user',        label: '用户名', type: 'text',   required: true, placeholder: 'u12345' },
    { key: 'password',    label: '密码', type: 'password', required: false, placeholder: '密码或 SSH 私钥二选一' },
    { key: 'private_key', label: 'SSH 私钥', type: 'textarea', required: false, placeholder: '-----BEGIN OPENSSH PRIVATE KEY-----\n...\n-----END OPENSSH PRIVATE KEY-----' },
  ],
  ftp: [
    { key: 'host',                 label: '主机',   type: 'text',     required: true, placeholder: 'ftpX.hostypanel.com 或 box.example.com' },
    { key: 'port',                 label: '端口',   type: 'number',   required: false, default: 21, placeholder: '21 (FTPS 通常 21 或 990)' },
    { key: 'user',                 label: '用户名', type: 'text',     required: true },
    { key: 'password',             label: '密码',   type: 'password', required: true },
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
    { key: 'no_check_certificate', label: '跳过 TLS 证书校验', type: 'select', required: false, default: false,
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
