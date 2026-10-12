# Backup Center · 视觉设计规范 v2「青瓷 Porcelain」

> 交付阶段：Phase 1（视觉方向）
> 适用范围：`backup-center/frontend` 全部 27 个 `.vue` + 全局样式
> 硬约束：**不改动任何现有功能**——不改组件结构、不改交互逻辑、不改路由、不改 API、不增删页面
> 交付形式：Design Token 表 + 可直接复制的 CSS + 组件视觉规范 + 落地路径 + 风险清单
> 落盘责任人：Phase 3 工程师（本文档作者未修改任何项目文件）

---

## 0. 现状体检（我实际读代码得到的结论，不是推测）

| 项目 | 现状 | 判断 |
|---|---|---|
| Token 体系 | `src/style.css` 261 行，24 个 `--bc-*` 变量，`:root` 内映射 `--el-*` | 体系是对的，但**只映射了浅色** |
| 深色主题 | `html.dark` 只覆盖了 `--bc-*`，**没有覆盖 `--el-*`** | ❌ 见风险 R1，这是最大的隐藏缺陷 |
| 主色 | `#2563eb`（Tailwind blue-600） | 通用 SaaS 蓝，无辨识度 |
| 层次 | 卡片 = 纯白 + 1px 实边框 + 极轻阴影；全站靠 1px 线分隔 | 「线框感」重，与「轻巧」相悖 |
| 硬编码色 | 约 150 处（Dashboard `tone-*` 17、Servers 21、StoragePool 17、ServerDetailDrawer 15…） | 全局 CSS 管不到，需逐个替换 |
| EP 组件用量 | `el-button` 140、`el-form-item` 136、`el-table-column` 116、`el-input` 54、`el-select` 34、`el-dialog` 10、`el-drawer` 2、`el-tabs` 11、`el-collapse` 10、`el-progress` 5、`el-pagination` 4 | `el-button`/`el-table`/`el-form` 是三大主战场 |
| 状态标签 | **没有用 `el-tag`**，全部是自定义 `.badge` / `.chip` span | 改 token 可直接生效，无需动结构 |
| 字体 | 无 `@font-face`、无外链；`JetBrains Mono` 被 10 处引用但**从未加载**（静默回退） | 可安全引入本地系统字体栈 |

**关键结论**：现有 24 个 `--bc-*` 变量名**全部被引用**（`--bc-text-secondary` 74 次、`--bc-text-primary` 61 次、`--bc-border-light` 41 次…）。
→ 因此本次设计**保留全部 24 个变量名，一个都不改名、不删除**，只改值 + 新增补充变量。
→ 结果：26 个页面/组件**零改动即可获得新视觉**，符合「不改动功能」硬约束。

---

## 1. 视觉方向

### 一句话定位

> **「青瓷 Porcelain」——以冷调瓷白为底、以半透明边界替代实线框、以一层极柔的浮起感替代描边，只留一抹深青作为唯一可点击信号。**

### 与旧版的三处根本性差异

| 维度 | v1（现状） | v2（青瓷） |
|---|---|---|
| 边界表达 | 1px 实色边框 `#e5e7eb` | **半透明边框** `rgba(16,24,40,.07)` + 极柔多层阴影 |
| 主色 | `#2563eb` 通用蓝 | `#0F6E8C` 深青瓷（h≈194，s≈81%，l≈30%） |
| 层次来源 | 加线 | **背景明度递进**（page #F4F6F8 → card #FFF → inset #F7F9FA） |

### 为什么是「深青」而不是蓝 / 橙 / 绿

1. **避开语义撞色**：运维后台里 成功=绿、警告=琥珀、失败=红 是刚需。主色必须留在 蓝/青/靛 区间，否则主按钮会和状态标签混淆。
2. **避开通用感**：`blue-600` 是后台模板的默认答案，一眼「模板感」。青瓷（h≈194）比蓝（h≈217）偏冷偏静，天然带「精密、可靠、工程感」，且同类产品里少见。
3. **克制**：饱和度控制在 81%，明度压到 30%，大面积使用时不刺眼；长时间盯表格 + 图表 + 日志，低彩度是硬需求。
4. **对比度达标**：`#0F6E8C` 在白底 **5.78:1**、在页面底 `#F4F6F8` **5.34:1**，均过 WCAG AA 正文标准。

### 与用户历史审美（浅橙色 / Didone 数字 / 纤细页脚）的兼容关系

| 历史偏好 | v2 的处理 | 兼容性说明 |
|---|---|---|
| **浅橙色主色** | 不复用为主色（与 warning 撞色、且全站橙会显「热力图」般躁）；改为 `--bc-brand-warm: #C98A3C` 作为**品牌温度色**，只用于 Login 品牌图标渐变、空状态、页脚图标 hover | 保留「温度」，不牺牲功能语义；若用户坚持，可一行把 `--bc-primary` 换成 `--bc-brand-warm`，**token 结构不变** |
| **Didone 高对比数字** | ✅ **已实现，且零网络依赖**：`--bc-font-num` 字体栈以 `Didot`（macOS 自带）→ `Bodoni MT`（Windows 自带）→ `Playfair Display` 排序，两大平台都能落到位真正的 Didone 衬线数字 | 仅作用于纯数字串（KPI 数值、峰值/平均/累计），不含中文，无缺字风险 |
| **页脚链接统一、纤细字重** | ✅ 已在 §6.10 固化：页脚内所有文字 **12px / 400 / 同一字体栈**，`copyright` 的 `font-weight: 500` 覆盖为 400 | 直接沿用并写进规范 |

---

## 2. 设计原则（5 条，可裁决后续每个组件）

### 原则一 · 层次靠「明度递进」，不靠加线
页面底 `#F4F6F8` → 卡片 `#FFFFFF` → 内嵌块 `#F7F9FA`。三级明度差仅 3~5%，足够建立秩序，又不制造线框网格感。
**判据**：新增一个分组时，先问「能不能只靠背景色差解决」，能就不加 `border-top`。

### 原则二 · 边界用半透明，不用实色
所有分隔线/描边使用 `rgba(16,24,40,.05~.16)`（深色为 `rgba(255,255,255,.05~.20)`）。半透明边框会随底色自适应，在白卡片、灰页面、彩色标签上都自然——这是「不像模板」的最低成本手段。

### 原则三 · 彩色面积 < 5%，只标记「可点击」与「当前状态」
主色只出现在：主按钮、链接、激活菜单项、focus ring、图表主序列。**禁止**用主色做装饰背景、大面积区块、图标底色。
KPI 卡的彩色图标底（`tone-*`）降饱和为 8% 浓度的浅底 + 中彩度图标色，避免 8 张卡片 8 种颜色的「糖果盘」效应。

### 原则四 · 轻 = 低透明度多层柔光，不是「没有阴影」
阴影一律 2 层：一层近距小模糊（贴地感）+ 一层远距负扩散（悬浮感）。最大透明度不超过 0.18。
**禁止** 单层 `0 2px 8px rgba(0,0,0,.2)` 这类重投影。

### 原则五 · 数字是可读性第一公民
所有数字/编号/时间/容量：`tabular-nums` 等宽数字（防跳动）+ Didone 衬线（KPI 大数）+ `white-space: nowrap`。
长时间读数据的界面，数字稳定比装饰重要。

---

## 3. 色彩系统

### 3.1 中性色阶（浅色）

| Token | 值 | 用途 |
|---|---|---|
| `--bc-bg-page` | `#F4F6F8` | 页面底（`el-main`、布局容器） |
| `--bc-bg-card` | `#FFFFFF` | 卡片 / 表格 / 面板 |
| `--bc-bg-sidebar` | `#FBFCFD` | 侧栏（比卡片略暗 1 级，让白卡片浮起来） |
| `--bc-bg-header` | `#FFFFFF` | 顶栏 |
| `--bc-bg-hover` | `#EEF1F4` | 行 / 菜单项 / 图标按钮 hover |
| `--bc-bg-active` | `#E6EAEE` | pressed / 选中态 |
| `--bc-bg-inset` | `#F7F9FA` | 内嵌块：表头、代码块、抽屉 info-card、进度条槽 |
| `--bc-bg-elevated` | `#FFFFFF` | 弹层：Dialog / Drawer / Dropdown / Popover |
| `--bc-bg-overlay` | `rgba(16,24,40,.32)` | 遮罩 |

> 中性色统一带 **h≈210、s≈6~13%** 的冷调偏移，与主色青瓷（h≈194）同向，整体不发灰黄。

### 3.2 边框（半透明，四档）

| Token | 浅色 | 深色 | 用途 |
|---|---|---|---|
| `--bc-border` | `rgba(16,24,40,.10)` | `rgba(255,255,255,.12)` | 默认描边：输入框、下拉、卡片描边 |
| `--bc-border-light` | `rgba(16,24,40,.07)` | `rgba(255,255,255,.08)` | 分隔线（**41 处引用，主力**） |
| `--bc-border-hair` | `rgba(16,24,40,.05)` | `rgba(255,255,255,.05)` | 表格行线等极细分隔 |
| `--bc-border-strong` | `rgba(16,24,40,.16)` | `rgba(255,255,255,.20)` | hover 强化、激活描边 |

### 3.3 主色（青瓷 Teal，h≈194）

| 级别 | 值 | 用途 |
|---|---|---|
| primary-50 | `#E9F5F9` | 淡底（侧栏激活底、Tag 底、chip 底） |
| primary-100 | `#D6EEF4` | hover 淡底 |
| primary-200 | `#AFDDE8` | 图表次级 |
| primary-400 | `#45A5BD` | 图表 / 装饰 |
| primary-500 | `#1B87A1` | 深色模式边框辅助 |
| **primary-600** | **`#0F6E8C`** | **主色（浅色）** |
| primary-700 | `#0C5870` | hover / `--el-color-primary-dark-2` |
| primary-800 | `#0A4A60` | active / pressed |
| primary-900 | `#083C4E` | 深色模式文字压暗 |

派生：
- `--bc-primary` = `#0F6E8C`
- `--bc-primary-hover` = `#0C5870`（变**深**不变浅，克制）
- `--bc-primary-active` = `#0A4A60`
- `--bc-primary-light` = `#E9F5F9`

### 3.4 语义色（浅色 / 深色）

| 语义 | 浅色（文字/图标） | 深色（文字/图标） | 浅色淡底 | 浅色描边 | 深色淡底 |
|---|---|---|---|---|---|
| success 成功 | `#0F7A52` | `#3FBE8B` | `#E7F5EE` | `#BEE2CE` | `rgba(63,190,139,.14)` |
| warning 警告 | `#96600E` | `#D99A3E` | `#FBF2E3` | `#EFDDBE` | `rgba(217,154,62,.14)` |
| danger 危险 | `#C0353B` | `#E76A6E` | `#FBEAEA` | `#F0CACA` | `rgba(231,106,110,.14)` |
| info 信息 | `#4C63D2` | `#8098F0` | `#EDEFFB` | `#D3D9F4` | `rgba(128,152,240,.14)` |
| brand-warm 品牌温度 | `#C98A3C` | `#D9A45E` | `#FBF3E8` | `#F0DCC0` | `rgba(217,164,94,.14)` |

对比度自检（全部过 WCAG AA 正文 4.5:1）：

| 组合 | 比值 |
|---|---|
| `#0F6E8C` on `#FFFFFF` | **5.78:1** ✅ |
| `#0F6E8C` on `#F4F6F8` | 5.34:1 ✅ |
| `#0F7A52` on `#F4F6F8` | 4.94:1 ✅ |
| `#96600E` on `#F4F6F8` | 4.87:1 ✅ |
| `#C0353B` on `#F4F6F8` | 5.08:1 ✅ |
| `#4C63D2` on `#F4F6F8` | 4.84:1 ✅ |
| `#131A23` on `#FFFFFF` | 17.2:1 ✅ |
| `#66717F` on `#F4F6F8` | 4.58:1 ✅ |
| `#8A94A0` on `#FFFFFF` | 3.08:1（placeholder，达标） |

### 3.5 文字四级

| Token | 浅色 | 深色 | 用途 |
|---|---|---|---|
| `--bc-text-primary` | `#131A23` | `#E9EEF4` | 标题、KPI 数值、表格主内容 |
| `--bc-text-regular` | `#3A4552` | `#C3CBD5` | 正文、表格单元格、表单值 |
| `--bc-text-secondary` | `#66717F` | `#8B97A5` | 表头、标签、副标题、单位 |
| `--bc-text-placeholder` | `#8A94A0` | `#6B7785` | placeholder、禁用、空状态 |

### 3.6 侧栏专用

| Token | 浅色 | 深色 |
|---|---|---|
| `--bc-sidebar-text` | `#3A4552` | `#C3CBD5` |
| `--bc-sidebar-text-hover` | `#131A23` | `#E9EEF4` |
| `--bc-sidebar-active-bg` | `#E9F5F9` | `rgba(51,180,208,.14)` |
| `--bc-sidebar-active-text` | `#0F6E8C` | `#33B4D0` |

### 3.7 图表色板

| Token | 值 | 说明 |
|---|---|---|
| `--bc-chart-1` | `#0F6E8C` | 主序列（面积图/柱状图/当前存储） |
| `--bc-chart-2` | `#4C86C6` | 钢蓝 |
| `--bc-chart-3` | `#6E7BC8` | 靛 |
| `--bc-chart-4` | `#B08A3E` | 琥珀 |
| `--bc-chart-5` | `#2E9B76` | 玉绿 |
| `--bc-chart-6` | `#A8566B` | 绛 |
| `--bc-chart-grid` | `rgba(16,24,40,.08)` / dark `rgba(255,255,255,.08)` | 网格线（虚线） |
| `--bc-chart-axis` | `#66717F` / dark `#8B97A5` | 轴标签 |

面积图渐变：`--bc-chart-1` 从 `opacity .28` → `opacity .02`（比现状 .35→.02 更透气）。

---

## 4. 字体系统

### 4.1 字体栈

```css
--bc-font-sans: 'Inter var', Inter, -apple-system, BlinkMacSystemFont, 'Segoe UI',
                Roboto, 'PingFang SC', 'Hiragino Sans GB', 'Microsoft YaHei',
                'Helvetica Neue', Arial, sans-serif;

/* 数字专用：Didone 高对比衬线。macOS→Didot，Windows→Bodoni MT，均为系统自带，零网络依赖 */
--bc-font-num:  'Didot', 'Bodoni MT', 'Bodoni 72', 'Playfair Display',
                'Bodoni Moda', 'Noto Serif Display', Georgia,
                'Times New Roman', var(--bc-font-sans);

/* 回退开关：若不想要衬线数字，一行改为 → --bc-font-num: var(--bc-font-sans); */

--bc-font-mono: 'JetBrains Mono', 'SFMono-Regular', 'SF Mono', Menlo, Consolas,
                'Liberation Mono', ui-monospace, monospace;
```

**`--bc-font-num` 使用边界（重要）**：
- ✅ 只允许用于**纯数字 / 数字+单位**节点：`.stat-value`、`.summary-val`、KPI 数值、容量数字。
- ❌ **禁止**用于含中文的元素（Didot/Bodoni 无中文字形，会触发系统回退到宋体，反而破坏一致性）。
- ❌ 禁止用于表格内小数字（11~13px 下 Didone 衬线笔画过细，反而难读）；表格数字用 `--bc-font-mono` + `tabular-nums`。

### 4.2 字号阶梯

| Token | 值 | 行高 | 字重 | 用途 |
|---|---|---|---|---|
| `--bc-fs-2xs` | 11px | 1.5 | 500 | 表头、chip、badge、单位 |
| `--bc-fs-xs` | 12px | 1.5 | 400/500 | 页脚、辅助说明、表格副行、菜单项 |
| `--bc-fs-sm` | 13px | 1.55 | 400 | 表格单元格、表单值、正文 |
| `--bc-fs-base` | 14px | 1.6 | 400/500 | 卡片标题、按钮、输入框 |
| `--bc-fs-md` | 15px | 1.5 | 600 | 侧栏品牌名、顶栏页标题 |
| `--bc-fs-lg` | 16px | 1.45 | 600 | 区块标题、Dialog 标题 |
| `--bc-fs-xl` | 18px | 1.4 | 600 | 页面级小标题 |
| `--bc-fs-2xl` | 20px | 1.35 | 600 | 页面主标题（Dashboard「仪表盘」） |
| `--bc-fs-display` | 28px | 1.15 | 600 | KPI 主数值（配 `--bc-font-num`） |
| `--bc-fs-hero` | 32px | 1.1 | 600 | Login 品牌标题 |

### 4.3 字重规则

| 层级 | 字重 | 禁止 |
|---|---|---|
| 正文 / 表格 / 表单值 | **400** | 不要用 500 铺满正文 |
| 表头 / 标签 / 按钮 / 强调 | **500** | — |
| 页面标题 / 卡片标题 / KPI 数值 | **600** | — |
| 页脚全部文字（含 copyright、链接） | **400** | 禁止 500/600（历史偏好） |
| 全局 | — | **禁用 700+**（中文黑体 700 会糊） |

### 4.4 字距（中文界面的关键细节）

```css
--bc-tracking-tight:  -0.01em;  /* 20px+ 大标题、KPI 数值 */
--bc-tracking-normal: 0;        /* 正文 */
--bc-tracking-wide:   0.02em;   /* 11~12px 小字、chip、badge（中文小字加 2% 字距明显更清晰） */
--bc-tracking-caps:   0.06em;   /* 英文大写表头 */
```

---

## 5. 圆角 / 阴影 / 间距 / 动效

### 5.1 圆角

| Token | 值 | 用途 |
|---|---|---|
| `--bc-radius-xs` | `4px` | chip/badge 内部、进度条 |
| `--bc-radius-sm` | `6px` | 小按钮、徽标、tooltip |
| `--bc-radius` | `10px` | **默认**：按钮、输入框、下拉、菜单项、Tag |
| `--bc-radius-lg` | `14px` | 卡片、面板 |
| `--bc-radius-xl` | `20px` | Dialog、Drawer、Login 卡片 |
| `--bc-radius-full` | `999px` | pill、头像、状态标签 |

> v1 是 8/12/16；v2 整体放大 2~4px + 层级差拉开，是「轻」的直接来源之一。

### 5.2 阴影（低透明度多层柔光）

```css
/* 浅色 */
--bc-shadow-xs:  0 1px 2px rgba(16,24,40,.04);
--bc-shadow-sm:  0 1px 2px rgba(16,24,40,.04), 0 1px 3px rgba(16,24,40,.03);
--bc-shadow:     0 2px 4px rgba(16,24,40,.04), 0 4px 10px -2px rgba(16,24,40,.05);
--bc-shadow-lg:  0 8px 20px -6px rgba(16,24,40,.10), 0 2px 6px -2px rgba(16,24,40,.04);
--bc-shadow-xl:  0 24px 48px -12px rgba(16,24,40,.18), 0 8px 16px -8px rgba(16,24,40,.08);
--bc-shadow-inset: inset 0 1px 0 rgba(255,255,255,.60);

/* 深色（加大不透明度、去掉内高光） */
--bc-shadow-xs:  0 1px 2px rgba(0,0,0,.30);
--bc-shadow-sm:  0 1px 2px rgba(0,0,0,.30), 0 1px 3px rgba(0,0,0,.20);
--bc-shadow:     0 2px 6px rgba(0,0,0,.35), 0 4px 12px -2px rgba(0,0,0,.30);
--bc-shadow-lg:  0 10px 24px -6px rgba(0,0,0,.50), 0 2px 8px -2px rgba(0,0,0,.35);
--bc-shadow-xl:  0 28px 56px -12px rgba(0,0,0,.65), 0 8px 16px -8px rgba(0,0,0,.45);
```

**focus ring**（新增，键盘可达性的同时是「高级感」细节）：
```css
--bc-ring:        0 0 0 3px rgba(15,110,140,.16);   /* 浅色 */
--bc-ring-danger: 0 0 0 3px rgba(192,53,59,.16);
/* 深色 */ --bc-ring: 0 0 0 3px rgba(51,180,208,.28);
```

### 5.3 间距节奏（4pt 基准）

```
--bc-space-1: 4px   --bc-space-2: 8px   --bc-space-3: 12px  --bc-space-4: 16px
--bc-space-5: 20px  --bc-space-6: 24px  --bc-space-8: 32px  --bc-space-10: 40px
--bc-space-12: 48px
```

固定节奏约定：

| 场景 | 值 |
|---|---|
| 主区内边距（`el-main`） | 24px |
| 卡片内边距 | 20px（KPI 卡）/ 16px（图表卡） |
| 卡片网格间距 | 16px |
| 区块之间（KPI 排 → 图表区 → 表格） | 20px |
| 表格单元格 | 上下 13px / 左右 16px |
| 表单项间距 | 18px |
| Dialog body | 24px |

### 5.4 动效

```css
--bc-dur-fast: 120ms;   /* 颜色、背景切换 */
--bc-dur:      180ms;   /* 默认：hover、border */
--bc-dur-slow: 260ms;   /* 阴影抬升、抽屉/对话框进出 */

--bc-ease:        cubic-bezier(.4, 0, .2, 1);
--bc-ease-out:    cubic-bezier(.16, 1, .3, 1);      /* 悬浮抬升（premium 感） */
--bc-ease-spring: cubic-bezier(.34, 1.56, .64, 1);  /* 极少用：图标按钮点击反馈 */
```

规则：**只过渡 `background-color / border-color / color / box-shadow / transform / opacity`**，禁止 `transition: all`（现状代码里有 `transition: all .15s`，需改）。

---

## 6. 组件视觉规范

### 6.1 侧边栏（`.aside` / `.side-menu`）

| 项 | 规格 |
|---|---|
| 宽度 | 展开 224px / 折叠 64px（保持） |
| 背景 | `--bc-bg-sidebar` `#FBFCFD`（浅）/ `#10151B`（深） |
| 右边界 | `1px solid var(--bc-border-light)` |
| 品牌区 | 高 60px，`padding: 0 18px`，`gap: 10px`，下边界 hairline |
| 品牌图标 | 32×32，`border-radius: var(--bc-radius)`（10px），背景 `linear-gradient(135deg, #2E93AE 0%, #0F6E8C 100%)`，图标色 `#fff` |
| 菜单容器内边距 | `12px 10px` |
| 菜单项 | 高 **40px**，行高 40px，`border-radius: var(--bc-radius)`（10px），`padding-left: 12px`，`margin-bottom: 4px`，`font-size: 14px / 500`，`transition: background var(--bc-dur) var(--bc-ease), color var(--bc-dur)` |
| 菜单项 hover | 背景 `--bc-bg-hover`，文字 `--bc-sidebar-text-hover` |
| 菜单项 **激活** | 背景 `--bc-sidebar-active-bg`，文字 `--bc-sidebar-active-text`，字重 500；**左侧 3px 指示条**：`box-shadow: inset 3px 0 0 var(--bc-primary)`（这是唯一新增的装饰，取代 v1 纯底色，辨识度显著提升且零成本） |
| 图标 | 18px，颜色继承文字色 |

```css
/* 激活指示条：利用 inset box-shadow，不占布局、不影响折叠态 */
.side-menu .el-menu-item.is-active {
  box-shadow: inset 3px 0 0 var(--bc-primary);
}
```

### 6.2 顶栏（`.header`）

| 项 | 规格 |
|---|---|
| 高度 | 60px（保持） |
| 背景 | `--bc-bg-header` `#FFFFFF`；**可选毛玻璃**：`rgba(255,255,255,.72)` + `backdrop-filter: blur(12px) saturate(1.4)`（见 §6.2.1） |
| 下边界 | `1px solid var(--bc-border-light)` |
| 左右内边距 | `24px` |
| 折叠/图标按钮 | 34×34，`border-radius: var(--bc-radius)`，透明底，图标 18px，色 `--bc-text-regular` |
| 图标按钮 hover | 背景 `--bc-bg-hover`，色 `--bc-text-primary`，`transform: translateY(-1px)`，`transition: 180ms ease-out` |
| 页面标题 h1 | 15px / 600 / `--bc-text-primary` / `letter-spacing: -.01em` |
| 页面副标题 p | 12px / 400 / `--bc-text-secondary` |
| 用户区 | `padding: 4px 10px 4px 4px`，`border-radius: var(--bc-radius)`，hover 背景 `--bc-bg-hover` |
| 头像 | 28×28 圆形，渐变 `linear-gradient(135deg, #45A5BD 0%, #0F6E8C 100%)`，13px/600/#fff |
| 通知红点 | 6×6，`--bc-danger`，`top: 8px; right: 8px`，加 `box-shadow: 0 0 0 2px var(--bc-bg-header)` 描边环（浅色下更精致） |

#### 6.2.1 可选：毛玻璃顶栏（新增变量）
```css
--bc-glass-bg:   rgba(255,255,255,.72);          /* dark: rgba(16,21,27,.72) */
--bc-glass-blur: blur(12px) saturate(1.6);
```
> 注意：当前 `.el-header` 与 `.el-main` 是**并列 flex 兄弟**，主区不会滚到顶栏下面，所以毛玻璃**视觉收益有限**。仅当 Phase 3 后续把顶栏改为 `position: sticky` 时才启用。默认不启用，避免无谓的 GPU 开销。

### 6.3 卡片（`.bc-card` / `.el-card`）

这是本次「轻巧」改造的核心。

| 项 | 规格 |
|---|---|
| 背景 | `--bc-bg-card` `#FFFFFF` |
| **边框** | **去掉实色边框** → `1px solid var(--bc-border-hair)` `rgba(16,24,40,.05)`（极淡，保留以兼容深色下无阴影的边界） |
| 圆角 | `--bc-radius-lg` = 14px |
| 阴影 | `--bc-shadow-sm` |
| 内边距 | 20px（默认） |
| **hover** | `box-shadow: var(--bc-shadow)` + `border-color: var(--bc-border-light)` + `transform: translateY(-1px)`，`transition: box-shadow 260ms ease-out, transform 260ms ease-out, border-color 180ms` |

> ⚠️ **只对「可交互/可点击」的卡片加 hover 抬升**；纯展示卡片（表格卡、图表卡）只加极淡 hover 阴影，**不要 translateY**，否则整页会「抖」。
> 实现方式：默认给 `.bc-card` 静态样式；hover 抬升只挂在带 `.is-interactive` 的卡片上（或直接用 `.stat-card:hover`）。

```css
.bc-card, .el-card {
  background: var(--bc-bg-card);
  border: 1px solid var(--bc-border-hair) !important;
  border-radius: var(--bc-radius-lg) !important;
  box-shadow: var(--bc-shadow-sm) !important;
  padding: 20px;
}
/* KPI 卡：可悬浮 */
.stat-card:hover {
  box-shadow: var(--bc-shadow);
  border-color: var(--bc-border-light);
  transform: translateY(-1px);
}
```

### 6.4 表格

本项目有 **两套表格**：原生 `<table>`（Dashboard 最近备份记录）和 Element Plus `<el-table>`（Servers/Websites/Storages/BackupLogs/ResourceLibrary）。需分别覆盖。

#### 6.4.1 原生 table（Dashboard）

| 项 | 规格 |
|---|---|
| 表头背景 | `--bc-bg-inset` `#F7F9FA` |
| 表头文字 | 11px / 600 / `--bc-text-secondary` / `letter-spacing: .04em` |
| 表头边框 | 仅 `border-bottom: 1px solid var(--bc-border-light)`（**去掉 v1 的整行底色块感，改更淡**） |
| 单元格 | 13px / 400 / `--bc-text-regular`，`padding: 13px 16px` |
| 行分隔 | `1px solid var(--bc-border-hair)`；最后一行 `border-bottom: none` |
| 行 hover | 背景 `--bc-bg-hover`，`transition: background-color 120ms` |
| 斑马纹 | **不用**（靠 hover 足够；运维数据行多，斑马纹会加重视觉噪音） |
| 数字列 | `--bc-font-mono`，`font-variant-numeric: tabular-nums`，右对齐最佳 |
| 空状态 | `padding: 56px 20px`，13px / 400 / `--bc-text-secondary`，居中；建议配一个 24px 的 `--bc-border-strong` 描边图标 |

#### 6.4.2 Element Plus `el-table`

必须通过变量覆盖（EP 的 `--el-table-*` 定义在 `.el-table` 上，同特异性下看顺序，`style.css` 在 EP 之后引入 ✅）：

```css
.el-table {
  --el-table-border-color:        var(--bc-border-hair);
  --el-table-text-color:          var(--bc-text-regular);
  --el-table-header-text-color:   var(--bc-text-secondary);
  --el-table-header-bg-color:     var(--bc-bg-inset);
  --el-table-row-hover-bg-color:  var(--bc-bg-hover);
  --el-table-current-row-bg-color: var(--bc-primary-50);
  --el-table-bg-color:            var(--bc-bg-card);
  --el-table-tr-bg-color:         var(--bc-bg-card);
  --el-table-expanded-cell-bg-color: var(--bc-bg-inset);
  font-size: 13px;
}
.el-table th.el-table__cell {
  font-size: 11px;
  font-weight: 600;
  letter-spacing: .04em;
  background: var(--bc-bg-inset);
  color: var(--bc-text-secondary);
  padding: 11px 0;
}
.el-table td.el-table__cell {
  padding: 13px 0;
  color: var(--bc-text-regular);
}
.el-table .el-table__cell { border-bottom-color: var(--bc-border-hair); }
/* 固定列阴影换成柔光 */
.el-table--enable-row-hover .el-table__body tr:hover > td.el-table__cell {
  background-color: var(--bc-bg-hover);
}
```

### 6.5 按钮（`el-button`，全站 140 处）

| 类型 | 背景 | 边框 | 文字 | hover | active | disabled |
|---|---|---|---|---|---|---|
| **主要 primary** | `#0F6E8C` | 同背景 | `#FFFFFF` 14px/500 | 背景 `#0C5870`（变深） | `#0A4A60` | bg `#C9D3DA` / text `#FFFFFF` / `cursor: not-allowed` |
| **次要 default** | `#FFFFFF` | `1px solid var(--bc-border)` | `#3A4552` | 边框 `--bc-border-strong` + 文字 `--bc-text-primary` + 底 `#F7F9FA` | 底 `--bc-bg-active` | bg `#F4F6F8` / text `#8A94A0` |
| **文字 text** | 透明 | 无 | `#0F6E8C` | 底 `--bc-primary-light` | 底 `--bc-primary-100` | text `#8A94A0` |
| **危险 danger** | `#C0353B` | 同背景 | `#FFFFFF` | `#A82B31` | `#8F242A` | bg `#F0CACA` / text `#FFFFFF` |
| **链接型 link**（表格操作列） | 透明 | 无 | `#0F6E8C` 13px/500 | 文字 `#0C5870` + `text-decoration: underline` | — | text `#8A94A0` |

通用：
- 高度：默认 32px（EP `default`）／`large` 40px（`size="large"` 用于登录按钮，保持 44px）
- 圆角：`--bc-radius` 10px
- 字重 500，`letter-spacing: .01em`
- **去掉 EP 默认的 hover 变浅**（EP 用 `--el-color-primary-light-3`），覆盖为变深：
```css
.el-button--primary:hover,
.el-button--primary:focus-visible {
  background-color: var(--bc-primary-hover);
  border-color: var(--bc-primary-hover);
}
.el-button--primary:active {
  background-color: var(--bc-primary-active);
  border-color: var(--bc-primary-active);
}
```
- focus 可见性：`:focus-visible { box-shadow: var(--bc-ring); }`，同时 `outline: none`（只去默认 outline，保留 ring，不破坏 a11y）
- 图标按钮（表格操作列 `link`）：图标 14px，`gap: 4px`

### 6.6 状态标签（Tag / `.badge` / `.chip`）

项目未用 `el-tag`，全部是自定义 span。规范统一为：

| 项 | 规格 |
|---|---|
| 尺寸 | `padding: 2px 10px`，`border-radius: var(--bc-radius-full)`（999px） |
| 字号 | 11px / **500** / `letter-spacing: .02em` / `line-height: 1.6` |
| 边框 | `1px solid`（用各语义的「浅色描边」列） |
| 默认无图标 | 保持纯文字，最小视觉负担 |

| 状态 | 浅色底 | 浅色字 | 浅色边 | 深色底 | 深色字 |
|---|---|---|---|---|---|
| 成功 `ok` | `#E7F5EE` | `#0F7A52` | `#BEE2CE` | `rgba(63,190,139,.14)` | `#3FBE8B` |
| 执行中 `run` | `#FBF2E3` | `#96600E` | `#EFDDBE` | `rgba(217,154,62,.14)` | `#D99A3E` |
| 失败 `fail` | `#FBEAEA` | `#C0353B` | `#F0CACA` | `rgba(231,106,110,.14)` | `#E76A6E` |
| 离线/中性 `gray` | `#F4F6F8` | `#66717F` | `rgba(16,24,40,.10)` | `rgba(255,255,255,.06)` | `#8B97A5` |
| 信息 `info` | `#EDEFFB` | `#4C63D2` | `#D3D9F4` | `rgba(128,152,240,.14)` | `#8098F0` |

**关键改动**：把硬编码的 `.badge.ok/.badge.fail/.badge.run` 颜色换成 `var(--bc-success-bg)` 等语义淡底变量，深色模式才能自动跟随（现状这些在深色下是亮底+暗字，属于 bug）。

**可选的「点状指示」增强**（推荐，运维扫读更快）：
```css
.badge::before {
  content: ''; width: 5px; height: 5px; border-radius: 50%;
  background: currentColor; margin-right: 6px; opacity: .85;
}
```

### 6.7 对话框（`el-dialog`）

现状已有一套「半透明标题条」改造，v2 在其基础上精修：

| 项 | 规格 |
|---|---|
| 圆角 | **`20px`**（`--bc-radius-xl`）——v1 是 10px，20px 是「高级」的关键 |
| 背景 | `--bc-bg-elevated` `#FFFFFF` |
| 阴影 | `--bc-shadow-xl` |
| 遮罩 | `--bc-bg-overlay` `rgba(16,24,40,.32)` + `backdrop-filter: blur(2px)`（浅浅一层，提升层次） |
| 标题条 | `margin: 20px 20px 0`，`padding: 14px 20px`，`border-radius: 12px`，背景 `rgba(15,110,140,.07)`（浅）/ `rgba(51,180,208,.12)`（深） |
| 标题文字 | 16px / 600 / `--bc-text-primary` |
| body | `padding: 20px 24px` |
| footer | `padding: 12px 24px 28px`，按钮间距 12px，右对齐 |
| 关闭按钮 | `top: 38px; right: 34px`；**hover 加 34×34 圆形 `--bc-bg-hover` 底**（现状没有，hover 反馈缺失） |
| 入场动画 | `--bc-dur-slow` `cubic-bezier(.16,1,.3,1)` |

```css
.el-dialog {
  --el-dialog-border-radius: var(--bc-radius-xl);
  box-shadow: var(--bc-shadow-xl);
  background: var(--bc-bg-elevated);
}
.el-dialog__header {
  margin: 20px 20px 0 !important;
  padding: 14px 20px !important;
  background: rgba(15, 110, 140, .07);
  border-radius: 12px;
}
html.dark .el-dialog__header { background: rgba(51, 180, 208, .12); }
.el-dialog__headerbtn {
  top: 38px !important; right: 30px !important;
  width: 34px; height: 34px; border-radius: var(--bc-radius);
  transition: background-color var(--bc-dur) var(--bc-ease);
}
.el-dialog__headerbtn:hover { background: var(--bc-bg-hover); }
```

### 6.8 表单控件（`el-input` / `el-select` / `el-switch` / `el-radio`）

| 控件 | 默认 | hover | focus | disabled | 错误 |
|---|---|---|---|---|---|
| `el-input` | 高 32px，圆角 10px，底 `#FFFFFF`，边框 `1px solid var(--bc-border)`，文字 13px `--bc-text-regular`，placeholder `#8A94A0` | 边框 `--bc-border-strong` | 边框 `--bc-primary` + `box-shadow: var(--bc-ring)` | 底 `--bc-bg-inset`，文字 `--bc-text-placeholder` | 边框 `--bc-danger` + ring danger |
| `el-select` | 同上；下拉面板圆角 10px、`--bc-shadow-lg`、内边距 4px、选项高 34px、选中项底 `--bc-primary-light` 文字 `--bc-primary` | — | — | — | — |
| `el-textarea` | 圆角 10px，最小 3 行 | — | 同 input | — | — |
| `el-switch` | 关闭底 `#C9D3DA`，开启底 `#0F6E8C`，滑块 `#FFFFFF` + `--bc-shadow-xs` | — | focus ring | 整体 `opacity: .5` | — |
| `el-radio` | 圆点边框 `--bc-border-strong`，选中 `--bc-primary` | — | ring | — | — |
| `el-form-item__label` | 13px / 500 / `--bc-text-regular`，`padding-right: 12px`，`margin-bottom: 8px` | — | — | — | — |
| 表单项错误提示 | 12px / 400 / `--bc-danger`，`margin-top: 4px` | | | | |

EP 变量映射：
```css
.el-input, .el-textarea {
  --el-input-bg-color: var(--bc-bg-card);
  --el-input-border-color: var(--bc-border);
  --el-input-hover-border-color: var(--bc-border-strong);
  --el-input-focus-border-color: var(--bc-primary);
  --el-input-text-color: var(--bc-text-regular);
  --el-input-placeholder-color: var(--bc-text-placeholder);
  --el-input-border-radius: var(--bc-radius);
}
.el-input.is-disabled { --el-input-bg-color: var(--bc-bg-inset); }
```

### 6.9 抽屉（`ServerDetailDrawer`）

| 项 | 规格 |
|---|---|
| 宽度 | 保持现状（不改结构） |
| 背景 | `--bc-bg-elevated` `#FFFFFF` |
| 圆角 | **左侧两角 `20px`**：`border-radius: 20px 0 0 20px`（若从右滑出）——v1 是直角，这是明显的高级感提升点 |
| 阴影 | `--bc-shadow-xl` |
| 遮罩 | `--bc-bg-overlay` + `backdrop-filter: blur(2px)` |
| 标题 | 16px / 600，下边界 hairline，`padding: 20px 24px` |
| body | `padding: 20px 24px` |
| `info-card`（顶部信息块） | 底 `--bc-bg-inset`，圆角 14px，`padding: 16px`，**无边框** |
| `kv-grid` 键值 | key 12px `--bc-text-secondary`；value 13px / 500 / `--bc-text-primary` |
| `section-title` | 13px / 600 / `--bc-text-primary`，`margin: 20px 0 10px` |
| `cmd-block` 命令块 | 底 `--bc-bg-inset`，圆角 10px，`padding: 12px 14px`，`--bc-font-mono` 12px，可复制（`user-select: text` 已在全局处理） |

```css
.el-drawer {
  --el-drawer-bg-color: var(--bc-bg-elevated);
  border-radius: var(--bc-radius-xl) 0 0 var(--bc-radius-xl);
  box-shadow: var(--bc-shadow-xl);
}
```

### 6.10 页脚（`Footer.vue`）

严格遵循历史偏好：**全部同一字体、同一字号、同一字重（400）**。

| 项 | 规格 |
|---|---|
| 背景 | `--bc-bg-card`（白）；上边界 `1px solid var(--bc-border-light)` |
| 内边距 | `12px 72px`（≤900px 时 `12px 24px`，保持） |
| **统一字体** | `font-family: var(--bc-font-sans); font-size: 12px; font-weight: 400;` |
| `copyright` | **字重强制 400**（覆盖现有 `font-weight: 500`），色 `--bc-text-regular` |
| `icp` | 12px / 400 / `--bc-text-placeholder` |
| `link`（免责声明等） | 12px / **400** / `--bc-primary`，无下划线；hover **加 1px 下划线**（不用加粗） |
| `chip-info` | `padding: 3px 10px`，`border-radius: 999px`，底 `rgba(15,110,140,.06)`，11px / 400 / `--bc-text-regular` |
| `contact-btn` | 28×28，`border-radius: 8px`，底 `--bc-bg-page`，边框 `1px solid var(--bc-border-light)`，图标色 `--bc-text-secondary`；hover 底 `rgba(15,110,140,.08)` + 图标 `--bc-primary` + 边框 `rgba(15,110,140,.35)` |
| 间隔 | 各组内 `gap: 12px`，组间 `gap: 24px` |

```css
.bc-footer {
  font-family: var(--bc-font-sans);
  font-size: 12px;
  font-weight: 400;
  color: var(--bc-text-secondary);
  letter-spacing: .01em;
}
.bc-footer .copyright { font-weight: 400; }        /* 覆盖原 500 */
.bc-footer .link { font-weight: 400; font-size: 12px; }
.bc-footer .link:hover { text-decoration: underline; text-underline-offset: 3px; }
```

### 6.11 图表

#### AreaChart（备份大小趋势）
| 项 | 规格 |
|---|---|
| 折线 | `stroke: var(--bc-chart-1)`，`stroke-width: 1.8`（`vector-effect: non-scaling-stroke` 保持），`linejoin/linecap: round` |
| 面积渐变 | `#0F6E8C` `opacity .28` → `opacity .02`（比 v1 的 .35 更透气） |
| 数据点 | `r: 1.4`，`fill: #FFFFFF`，`stroke: var(--bc-chart-1)` `stroke-width: 1.4`——**空心点**比实心点更高级，且不与折线糊在一起 |
| X 轴标签 | 10px / 400 / `--bc-chart-axis` |
| 网格 | v1 无网格；**建议不加**（面积图加网格会显乱） |

#### BarChart（备份数量趋势）
| 项 | 规格 |
|---|---|
| 柱体 | 宽 `max-width: 32px`，`border-radius: 6px 6px 0 0`，填充 `linear-gradient(180deg, #2E93AE 0%, #0F6E8C 100%)` |
| hover 柱 | `linear-gradient(180deg, #45A5BD 0%, #2E93AE 100%)` + `box-shadow: 0 4px 12px -4px rgba(15,110,140,.45)` |
| 网格线 | `rgba(16,24,40,.07)`，`1px dashed`（深色 `rgba(255,255,255,.07)`） |
| Y 轴标签 | 10px / 400 / `--bc-text-placeholder`，右对齐 |
| X 轴标签 | 10px / 400 / `--bc-chart-axis` |
| tooltip | 底 `--bc-text-primary`（浅色下近黑）/ 深色下 `--bc-bg-elevated` + `1px solid var(--bc-border)`；11px / 400 / 圆角 8px / `--bc-shadow-lg` |

#### StoragePool 堆叠条
| 项 | 规格 |
|---|---|
| 轨道 | 高 **8px**（v1 是 6px），圆角 999px，底 `--bc-bg-inset` |
| 分段 | 依次取 `--bc-chart-1..6`；段间分隔 `1px solid var(--bc-bg-card)`（浅色）/ `1px solid var(--bc-bg-elevated)`（深色）——**替换 v1 的 `rgba(255,255,255,.7)`，深色下才不会出白线** |
| 图例点 | 10×10，`border-radius: 3px` |
| hover 段 | `filter: saturate(1.15) brightness(1.04)`（v1 是 `brightness(1.05)`，效果太弱基本看不见） |

### 6.12 登录页（`Login.vue`）

| 项 | 规格 |
|---|---|
| 页面背景 | 两层极淡径向渐变 + 底色：`radial-gradient(1100px 520px at 18% 8%, #E9F5F9 0%, transparent 62%), radial-gradient(900px 460px at 88% 92%, #EDEFFB 0%, transparent 60%), #F4F6F8` |
| 品牌图标 | 52×52，`border-radius: 16px`，渐变 `linear-gradient(135deg, #45A5BD 0%, #0F6E8C 100%)`，阴影 `0 10px 24px -8px rgba(15,110,140,.45)` |
| 卡片 | 宽 400px，`padding: 40px 36px 28px`，圆角 **20px**，底 `#FFFFFF`，边框 `1px solid var(--bc-border-hair)`，阴影 `--bc-shadow-xl` |
| 标题 h1 | 24px / 600 / `--bc-text-primary` / `letter-spacing: -.01em` |
| 副标题 p | 13px / 400 / `--bc-text-secondary` |
| 输入框 | `size="large"` → 高 44px，圆角 10px |
| 提交按钮 | 高 44px，15px / 500，圆角 10px，全宽 |
| 底部提示 | 12px / 400 / `--bc-text-placeholder` |

> 🔴 **必须同时补深色版**（现状是 bug，见风险 R5）：
```css
html.dark .login-page {
  background:
    radial-gradient(1100px 520px at 18% 8%, rgba(51,180,208,.10) 0%, transparent 62%),
    radial-gradient(900px 460px at 88% 92%, rgba(128,152,240,.10) 0%, transparent 60%),
    var(--bc-bg-page);
}
html.dark .login-card {
  background: var(--bc-bg-elevated);
  border-color: var(--bc-border-light);
}
```

### 6.13 KPI 卡片（`stat-card`）

| 项 | 规格 |
|---|---|
| 布局 | `padding: 20px`，`border-radius: 14px` |
| 标签 | 12px / 500 / `--bc-text-secondary` / `letter-spacing: .02em` |
| 图标容器 | 32×32，圆角 10px |
| **数值** | **28px / 600 / `--bc-font-num` / `--bc-text-primary` / `line-height: 1.15` / `letter-spacing: -.02em`** |
| 变化量 | 12px / 500；涨 `--bc-success`，跌 `--bc-danger` |
| 变化量辅助文字 | 12px / **400** / `--bc-text-placeholder`，`margin-left: 4px` |
| 卡片间距 | 16px |

`tone-*` 图标配色（**降饱和**，避免糖果盘）：

| tone | 底 | 图标色 |
|---|---|---|
| blue | `#E9F5F9` | `#0F6E8C` |
| indigo | `#EDEFFB` | `#4C63D2` |
| emerald | `#E7F5EE` | `#0F7A52` |
| red | `#FBEAEA` | `#C0353B` |
| cyan | `#E4F4F3` | `#2E9B76` |
| purple | `#F0EDF9` | `#6E7BC8` |
| amber | `#FBF2E3` | `#96600E` |
| pink | `#F9EDF1` | `#A8566B` |

---

## 7. Element Plus 变量映射表

> 现有 `style.css` 只映射了 24 个 `--el-*`。**必须补齐下列全部条目**，否则 EP 组件会回落到自己的默认值（蓝色 `#409eff`、`#f5f7fa` 灰）。

### 7.1 必须在 `:root` 新增/修改

| EP 变量 | 映射到 | 现状 | 说明 |
|---|---|---|---|
| `--el-color-primary` | `var(--bc-primary)` | ✅已有 | |
| `--el-color-primary-light-3` | `#5799AF` | ⚠️需改值 | EP 用作 primary 按钮 hover 底，我们另行覆盖为变深 |
| `--el-color-primary-light-5` | `#7FB7C6` | ⚠️需改值 | focus outline / link hover |
| `--el-color-primary-light-7` | `#B7D4DD` | ⚠️需改值 | |
| `--el-color-primary-light-8` | `#CFE2E8` | ⚠️需改值 | |
| `--el-color-primary-light-9` | `#E7F1F4` | ⚠️需改值 | `--el-table-current-row-bg-color` 依赖它 |
| `--el-color-primary-dark-2` | `#0C5870` | ⚠️需改值 | active |
| `--el-color-success` | `var(--bc-success)` | ✅已有 | |
| `--el-color-warning` | `var(--bc-warning)` | ✅已有 | |
| `--el-color-danger` | `var(--bc-danger)` | ✅已有 | |
| `--el-color-error` | `var(--bc-danger)` | ✅已有 | |
| `--el-color-info` | `var(--bc-info)` | ✅已有 | |
| `--el-border-radius-base` | `var(--bc-radius)` = 10px | ✅已有 | 按钮/输入框/标签圆角 |
| `--el-border-radius-small` | `var(--bc-radius-sm)` = 6px | ✅已有 | |
| `--el-border-radius-round` | `999px` | ✅已有 | |
| **`--el-border-radius-big`** | `var(--bc-radius-lg)` = 14px | ❌**缺** | 影响大尺寸控件 |
| `--el-text-color-primary` | `var(--bc-text-primary)` | ✅已有 | |
| `--el-text-color-regular` | `var(--bc-text-regular)` | ✅已有 | |
| `--el-text-color-secondary` | `var(--bc-text-secondary)` | ✅已有 | |
| `--el-text-color-placeholder` | `var(--bc-text-placeholder)` | ✅已有 | |
| **`--el-text-color-disabled`** | `var(--bc-text-placeholder)` | ❌**缺** | 禁用文字没跟上 |
| `--el-border-color` | `var(--bc-border)` | ✅已有 | |
| `--el-border-color-light` | `var(--bc-border-light)` | ✅已有 | `.el-card` 边框依赖它 |
| `--el-border-color-lighter` | `var(--bc-border-hair)` | ⚠️改指向 | `.el-table` 行线依赖它，应更淡 |
| **`--el-border-color-extra-light`** | `var(--bc-border-hair)` | ❌**缺** | |
| **`--el-border-color-dark`** | `var(--bc-border-strong)` | ❌**缺** | |
| **`--el-fill-color`** | `#EEF1F4` | ❌**缺** | EP 默认 `#f0f2f5` |
| **`--el-fill-color-light`** | `#F4F6F8` | ❌**缺** | 🔴 **表格行 hover 用它**，现状漏配导致 hover 是 EP 默认灰 |
| **`--el-fill-color-lighter`** | `#F7F9FA` | ❌**缺** | |
| **`--el-fill-color-dark`** | `#E6EAEE` | ❌**缺** | |
| `--el-fill-color-blank` | `var(--bc-bg-card)` | ✅已有 | |
| **`--el-fill-color-darker`** | `#DDE2E7` | ❌**缺** | |
| `--el-bg-color` | `var(--bc-bg-card)` | ✅已有 | Dialog/Drawer 背景依赖它 |
| `--el-bg-color-page` | `var(--bc-bg-page)` | ✅已有 | |
| **`--el-bg-color-overlay`** | `var(--bc-bg-elevated)` | ❌**缺** | 下拉/气泡背景 |
| **`--el-box-shadow`** | `var(--bc-shadow)` | ❌**缺** | Dialog 阴影 |
| **`--el-box-shadow-light`** | `var(--bc-shadow-sm)` | ❌**缺** | 下拉/表格固定列阴影 |
| **`--el-box-shadow-lighter`** | `var(--bc-shadow-xs)` | ❌**缺** | |
| **`--el-box-shadow-dark`** | `var(--bc-shadow-lg)` | ❌**缺** | Drawer/Popover 阴影 |
| **`--el-transition-duration`** | `var(--bc-dur)` | ❌**缺** | 统一动效时长 |
| **`--el-transition-duration-fast`** | `var(--bc-dur-fast)` | ❌**缺** | |
| **`--el-font-size-base`** | `13px` | ❌**缺** | EP 默认 14px，与本项目 13px 表格不一致 |
| **`--el-font-size-small`** | `12px` | ❌**缺** | |
| **`--el-font-size-extra-small`** | `11px` | ❌**缺** | |

### 7.2 🔴 必须新增 `html.dark { --el-* }` 覆盖块

**这是本次最重要的修复**。原因见风险 R1。需要覆盖的清单与 `:root` 完全对称（把浅色值换成深色值），尤其：

```css
html.dark {
  --el-color-primary: #33B4D0;
  --el-color-primary-light-3: #57C6DC;
  --el-color-primary-light-5: #7FD4E5;
  --el-color-primary-light-7: #A8E2EE;
  --el-color-primary-light-8: #BCE9F2;
  --el-color-primary-light-9: #1E3440;   /* 深色下 light-9 用作"极淡底"，应为深色调 */
  --el-color-primary-dark-2: #2A9AB4;

  --el-bg-color: var(--bc-bg-card);        /* 覆盖 EP 的 #141414 */
  --el-bg-color-page: var(--bc-bg-page);   /* 覆盖 EP 的 #0a0a0a */
  --el-bg-color-overlay: var(--bc-bg-elevated);
  --el-fill-color-blank: var(--bc-bg-card);
  --el-fill-color: #1E252F;
  --el-fill-color-light: #1B212A;
  --el-fill-color-lighter: #191F27;
  --el-border-color: var(--bc-border);
  --el-border-color-light: var(--bc-border-light);
  --el-border-color-lighter: var(--bc-border-hair);
  --el-text-color-primary: var(--bc-text-primary);
  --el-text-color-regular: var(--bc-text-regular);
  --el-text-color-secondary: var(--bc-text-secondary);
  --el-text-color-placeholder: var(--bc-text-placeholder);
  --el-box-shadow: var(--bc-shadow);
  --el-box-shadow-light: var(--bc-shadow-sm);
  --el-box-shadow-dark: var(--bc-shadow-lg);
}
/* 深色下 primary 按钮：亮青底 + 墨色字（白字仅 2.45:1，不可用） */
html.dark .el-button--primary {
  --el-button-text-color: #08131A;
  color: #08131A;
}
html.dark .el-button--primary:hover,
html.dark .el-button--primary:focus-visible {
  --el-button-text-color: #08131A;
  background-color: #57C6DC;
  border-color: #57C6DC;
  color: #08131A;
}
```

> ⚠️ 深色下 `--el-color-primary-light-9` 的语义会反转：浅色下它是「极淡底」（接近白），深色下 EP 把它当「极淡底」用（接近黑）。**必须给深色值**，否则表格 `current-row` 会是亮青底 + 深色字，刺眼。

---

## 8. 完整 Token CSS（可直接复制）

> 以下内容整体替换 `src/style.css` 的第 1～111 行（Token 区），其余区块见 §9。

```css
/* ============================================================
   Backup Center · Design Tokens v2 「青瓷 Porcelain」
   说明：保留 v1 全部 24 个变量名，组件零改动即可生效
   ============================================================ */

:root {
  /* ---------- 背景：明度递进 ---------- */
  --bc-bg-page:      #f4f6f8;
  --bc-bg-card:      #ffffff;
  --bc-bg-sidebar:   #fbfcfd;
  --bc-bg-header:    #ffffff;
  --bc-bg-hover:     #eef1f4;
  --bc-bg-active:    #e6eaee;
  --bc-bg-inset:     #f7f9fa;
  --bc-bg-elevated:  #ffffff;
  --bc-bg-overlay:   rgba(16, 24, 40, .32);

  /* ---------- 边框：半透明四档 ---------- */
  --bc-border:        rgba(16, 24, 40, .10);
  --bc-border-light:  rgba(16, 24, 40, .07);
  --bc-border-hair:   rgba(16, 24, 40, .05);
  --bc-border-strong: rgba(16, 24, 40, .16);

  /* ---------- 文字 ---------- */
  --bc-text-primary:     #131a23;
  --bc-text-regular:     #3a4552;
  --bc-text-secondary:   #66717f;
  --bc-text-placeholder: #8a94a0;

  /* ---------- 侧栏 ---------- */
  --bc-sidebar-text:        #3a4552;
  --bc-sidebar-text-hover:  #131a23;
  --bc-sidebar-active-bg:   #e9f5f9;
  --bc-sidebar-active-text: #0f6e8c;

  /* ---------- 主色：青瓷 Teal ---------- */
  --bc-primary:        #0f6e8c;
  --bc-primary-hover:  #0c5870;
  --bc-primary-active: #0a4a60;
  --bc-primary-light:  #e9f5f9;
  --bc-primary-50:  #e9f5f9;
  --bc-primary-100: #d6eef4;
  --bc-primary-200: #afdde8;
  --bc-primary-400: #45a5bd;
  --bc-primary-500: #1b87a1;
  --bc-primary-700: #0c5870;
  --bc-primary-800: #0a4a60;

  /* ---------- 语义色 ---------- */
  --bc-success: #0f7a52;
  --bc-warning: #96600e;
  --bc-danger:  #c0353b;
  --bc-info:    #4c63d2;
  --bc-brand-warm: #c98a3c;

  --bc-success-bg: #e7f5ee;  --bc-success-border: #bee2ce;
  --bc-warning-bg: #fbf2e3;  --bc-warning-border: #efddbe;
  --bc-danger-bg:  #fbeaea;  --bc-danger-border:  #f0caca;
  --bc-info-bg:    #edeffb;  --bc-info-border:    #d3d9f4;

  /* ---------- 圆角 ---------- */
  --bc-radius-xs:   4px;
  --bc-radius-sm:   6px;
  --bc-radius:      10px;
  --bc-radius-lg:   14px;
  --bc-radius-xl:   20px;
  --bc-radius-full: 999px;

  /* ---------- 阴影：低透明度多层柔光 ---------- */
  --bc-shadow-xs: 0 1px 2px rgba(16, 24, 40, .04);
  --bc-shadow-sm: 0 1px 2px rgba(16, 24, 40, .04), 0 1px 3px rgba(16, 24, 40, .03);
  --bc-shadow:    0 2px 4px rgba(16, 24, 40, .04), 0 4px 10px -2px rgba(16, 24, 40, .05);
  --bc-shadow-lg: 0 8px 20px -6px rgba(16, 24, 40, .10), 0 2px 6px -2px rgba(16, 24, 40, .04);
  --bc-shadow-xl: 0 24px 48px -12px rgba(16, 24, 40, .18), 0 8px 16px -8px rgba(16, 24, 40, .08);
  --bc-shadow-inset: inset 0 1px 0 rgba(255, 255, 255, .60);

  /* ---------- Focus ring ---------- */
  --bc-ring:        0 0 0 3px rgba(15, 110, 140, .16);
  --bc-ring-danger: 0 0 0 3px rgba(192, 53, 59, .16);

  /* ---------- 毛玻璃（可选） ---------- */
  --bc-glass-bg:   rgba(255, 255, 255, .72);
  --bc-glass-blur: blur(12px) saturate(1.6);

  /* ---------- 动效 ---------- */
  --bc-dur-fast: 120ms;
  --bc-dur:      180ms;
  --bc-dur-slow: 260ms;
  --bc-ease:        cubic-bezier(.4, 0, .2, 1);
  --bc-ease-out:    cubic-bezier(.16, 1, .3, 1);
  --bc-ease-spring: cubic-bezier(.34, 1.56, .64, 1);

  /* ---------- 字体 ---------- */
  --bc-font-sans: 'Inter var', Inter, -apple-system, BlinkMacSystemFont, 'Segoe UI',
                  Roboto, 'PingFang SC', 'Hiragino Sans GB', 'Microsoft YaHei',
                  'Helvetica Neue', Arial, sans-serif;
  --bc-font-num:  'Didot', 'Bodoni MT', 'Bodoni 72', 'Playfair Display',
                  'Bodoni Moda', 'Noto Serif Display', Georgia,
                  'Times New Roman', var(--bc-font-sans);
  --bc-font-mono: 'JetBrains Mono', 'SFMono-Regular', 'SF Mono', Menlo, Consolas,
                  'Liberation Mono', ui-monospace, monospace;

  /* ---------- 字号 / 行高 / 字距 ---------- */
  --bc-fs-2xs: 11px;  --bc-fs-xs: 12px;    --bc-fs-sm: 13px;
  --bc-fs-base: 14px; --bc-fs-md: 15px;    --bc-fs-lg: 16px;
  --bc-fs-xl: 18px;   --bc-fs-2xl: 20px;   --bc-fs-display: 28px; --bc-fs-hero: 32px;
  --bc-lh-tight: 1.2; --bc-lh-snug: 1.4;   --bc-lh-normal: 1.55; --bc-lh-relaxed: 1.65;
  --bc-tracking-tight: -.01em; --bc-tracking-normal: 0;
  --bc-tracking-wide: .02em;   --bc-tracking-caps: .06em;

  /* ---------- 间距 ---------- */
  --bc-space-1: 4px;  --bc-space-2: 8px;   --bc-space-3: 12px;
  --bc-space-4: 16px; --bc-space-5: 20px;  --bc-space-6: 24px;
  --bc-space-8: 32px; --bc-space-10: 40px; --bc-space-12: 48px;

  /* ---------- 图表 ---------- */
  --bc-chart-1: #0f6e8c; --bc-chart-2: #4c86c6; --bc-chart-3: #6e7bc8;
  --bc-chart-4: #b08a3e; --bc-chart-5: #2e9b76; --bc-chart-6: #a8566b;
  --bc-chart-grid: rgba(16, 24, 40, .07);
  --bc-chart-axis: #66717f;

  /* ============================================================
     Element Plus 映射（浅色）
     ============================================================ */
  --el-color-primary: var(--bc-primary);
  --el-color-primary-light-3: #5799af;
  --el-color-primary-light-5: #7fb7c6;
  --el-color-primary-light-7: #b7d4dd;
  --el-color-primary-light-8: #cfe2e8;
  --el-color-primary-light-9: #e7f1f4;
  --el-color-primary-dark-2:  #0c5870;

  --el-color-success: var(--bc-success);
  --el-color-warning: var(--bc-warning);
  --el-color-danger:  var(--bc-danger);
  --el-color-error:   var(--bc-danger);
  --el-color-info:    var(--bc-info);

  --el-border-radius-base:  var(--bc-radius);
  --el-border-radius-small: var(--bc-radius-sm);
  --el-border-radius-big:   var(--bc-radius-lg);
  --el-border-radius-round: 999px;

  --el-text-color-primary:     var(--bc-text-primary);
  --el-text-color-regular:     var(--bc-text-regular);
  --el-text-color-secondary:   var(--bc-text-secondary);
  --el-text-color-placeholder: var(--bc-text-placeholder);
  --el-text-color-disabled:    var(--bc-text-placeholder);

  --el-border-color:           var(--bc-border);
  --el-border-color-light:     var(--bc-border-light);
  --el-border-color-lighter:   var(--bc-border-hair);
  --el-border-color-extra-light: var(--bc-border-hair);
  --el-border-color-dark:      var(--bc-border-strong);
  --el-border-color-darker:    var(--bc-border-strong);

  --el-fill-color:        #eef1f4;
  --el-fill-color-light:  #f4f6f8;
  --el-fill-color-lighter:#f7f9fa;
  --el-fill-color-blank:  var(--bc-bg-card);
  --el-fill-color-dark:   #e6eaee;
  --el-fill-color-darker: #dde2e7;

  --el-bg-color:          var(--bc-bg-card);
  --el-bg-color-page:     var(--bc-bg-page);
  --el-bg-color-overlay:  var(--bc-bg-elevated);

  --el-box-shadow:        var(--bc-shadow);
  --el-box-shadow-light:  var(--bc-shadow-sm);
  --el-box-shadow-lighter:var(--bc-shadow-xs);
  --el-box-shadow-dark:   var(--bc-shadow-lg);

  --el-transition-duration:      var(--bc-dur);
  --el-transition-duration-fast: var(--bc-dur-fast);
  --el-transition-function-ease-in-out-bezier: var(--bc-ease);

  --el-font-size-extra-small: var(--bc-fs-2xs);
  --el-font-size-small:       var(--bc-fs-xs);
  --el-font-size-base:        var(--bc-fs-sm);
  --el-font-size-medium:      var(--bc-fs-base);
  --el-font-size-large:       var(--bc-fs-lg);
}

/* ============================================================
   深色主题
   注意：必须同时覆盖 --el-*，否则被 element-plus/theme-chalk/dark/css-vars.css
   的 html.dark（特异性 0,1,1）压过 :root（0,1,0）
   ============================================================ */
html.dark {
  --bc-bg-page:      #0d1117;
  --bc-bg-card:      #151a21;
  --bc-bg-sidebar:   #10151b;
  --bc-bg-header:    #10151b;
  --bc-bg-hover:     #1e252f;
  --bc-bg-active:    #232b36;
  --bc-bg-inset:     #11161c;
  --bc-bg-elevated:  #1b212a;
  --bc-bg-overlay:   rgba(0, 0, 0, .55);

  --bc-border:        rgba(255, 255, 255, .12);
  --bc-border-light:  rgba(255, 255, 255, .08);
  --bc-border-hair:   rgba(255, 255, 255, .05);
  --bc-border-strong: rgba(255, 255, 255, .20);

  --bc-text-primary:     #e9eef4;
  --bc-text-regular:     #c3cbd5;
  --bc-text-secondary:   #8b97a5;
  --bc-text-placeholder: #6b7785;

  --bc-sidebar-text:        #c3cbd5;
  --bc-sidebar-text-hover:  #e9eef4;
  --bc-sidebar-active-bg:   rgba(51, 180, 208, .14);
  --bc-sidebar-active-text: #33b4d0;

  --bc-primary:        #33b4d0;
  --bc-primary-hover:  #57c6dc;
  --bc-primary-active: #2a9ab4;
  --bc-primary-light:  rgba(51, 180, 208, .14);
  --bc-primary-50:  rgba(51, 180, 208, .14);
  --bc-primary-100: rgba(51, 180, 208, .22);
  --bc-primary-200: #2a9ab4;
  --bc-primary-400: #57c6dc;
  --bc-primary-500: #2fa8c2;
  --bc-primary-700: #2a9ab4;
  --bc-primary-800: #1f7f96;

  --bc-success: #3fbe8b;
  --bc-warning: #d99a3e;
  --bc-danger:  #e76a6e;
  --bc-info:    #8098f0;
  --bc-brand-warm: #d9a45e;

  --bc-success-bg: rgba(63, 190, 139, .14);  --bc-success-border: rgba(63, 190, 139, .30);
  --bc-warning-bg: rgba(217, 154, 62, .14);  --bc-warning-border: rgba(217, 154, 62, .30);
  --bc-danger-bg:  rgba(231, 106, 110, .14); --bc-danger-border:  rgba(231, 106, 110, .30);
  --bc-info-bg:    rgba(128, 152, 240, .14); --bc-info-border:    rgba(128, 152, 240, .30);

  --bc-shadow-xs: 0 1px 2px rgba(0, 0, 0, .30);
  --bc-shadow-sm: 0 1px 2px rgba(0, 0, 0, .30), 0 1px 3px rgba(0, 0, 0, .20);
  --bc-shadow:    0 2px 6px rgba(0, 0, 0, .35), 0 4px 12px -2px rgba(0, 0, 0, .30);
  --bc-shadow-lg: 0 10px 24px -6px rgba(0, 0, 0, .50), 0 2px 8px -2px rgba(0, 0, 0, .35);
  --bc-shadow-xl: 0 28px 56px -12px rgba(0, 0, 0, .65), 0 8px 16px -8px rgba(0, 0, 0, .45);
  --bc-shadow-inset: inset 0 1px 0 rgba(255, 255, 255, .04);

  --bc-ring:        0 0 0 3px rgba(51, 180, 208, .28);
  --bc-ring-danger: 0 0 0 3px rgba(231, 106, 110, .28);

  --bc-glass-bg: rgba(16, 21, 27, .72);

  --bc-chart-1: #33b4d0; --bc-chart-2: #6ba3dc; --bc-chart-3: #8f9ada;
  --bc-chart-4: #d9a45e; --bc-chart-5: #4fc59a; --bc-chart-6: #c87a8d;
  --bc-chart-grid: rgba(255, 255, 255, .07);
  --bc-chart-axis: #8b97a5;

  /* ---- Element Plus 深色覆盖（关键） ---- */
  --el-color-primary: #33b4d0;
  --el-color-primary-light-3: #57c6dc;
  --el-color-primary-light-5: #7fd4e5;
  --el-color-primary-light-7: #a8e2ee;
  --el-color-primary-light-8: #bce9f2;
  --el-color-primary-light-9: #1e3440;
  --el-color-primary-dark-2:  #2a9ab4;

  --el-color-success: var(--bc-success);
  --el-color-warning: var(--bc-warning);
  --el-color-danger:  var(--bc-danger);
  --el-color-error:   var(--bc-danger);
  --el-color-info:    var(--bc-info);

  --el-text-color-primary:     var(--bc-text-primary);
  --el-text-color-regular:     var(--bc-text-regular);
  --el-text-color-secondary:   var(--bc-text-secondary);
  --el-text-color-placeholder: var(--bc-text-placeholder);
  --el-text-color-disabled:    var(--bc-text-placeholder);

  --el-border-color:           var(--bc-border);
  --el-border-color-light:     var(--bc-border-light);
  --el-border-color-lighter:   var(--bc-border-hair);
  --el-border-color-extra-light: var(--bc-border-hair);
  --el-border-color-dark:      var(--bc-border-strong);

  --el-fill-color:        #1e252f;
  --el-fill-color-light:  #1b212a;
  --el-fill-color-lighter:#191f27;
  --el-fill-color-blank:  var(--bc-bg-card);
  --el-fill-color-dark:   #253040;
  --el-fill-color-darker: #2d3949;

  --el-bg-color:          var(--bc-bg-card);
  --el-bg-color-page:     var(--bc-bg-page);
  --el-bg-color-overlay:  var(--bc-bg-elevated);

  --el-box-shadow:        var(--bc-shadow);
  --el-box-shadow-light:  var(--bc-shadow-sm);
  --el-box-shadow-lighter:var(--bc-shadow-xs);
  --el-box-shadow-dark:   var(--bc-shadow-lg);

  color-scheme: dark;
}
```

---

## 9. 全局样式补丁（替换 `style.css` 第 113～261 行的基础/EP 细调区）

```css
/* ---------- 基础重置 ---------- */
html, body, #app {
  height: 100%;
  margin: 0;
  padding: 0;
  font-family: var(--bc-font-sans);
  font-size: var(--bc-fs-sm);
  line-height: var(--bc-lh-normal);
  color: var(--bc-text-primary);
  background: var(--bc-bg-page);
  -webkit-font-smoothing: antialiased;
  -moz-osx-font-smoothing: grayscale;
  cursor: default;
  user-select: none;
  -webkit-user-select: none;
}

/* 输入框恢复文字光标 + 允许选中（保持原逻辑） */
input[type="text"], input[type="password"], input[type="email"], input[type="number"],
input[type="search"], input[type="tel"], input[type="url"], input:not([type]),
textarea, [contenteditable="true"] { cursor: text; user-select: text; -webkit-user-select: text; }

code, pre, .mono, .selectable, .cell-file, .token-box code, .cmd-box pre {
  cursor: text; user-select: text; -webkit-user-select: text;
}

*, *::before, *::after { box-sizing: border-box; }
a { color: inherit; text-decoration: none; }

/* 数字统一等宽（防跳动）—— 运维界面的硬需求 */
.mono, .stat-value, .summary-val, td.mono, .kv .v {
  font-variant-numeric: tabular-nums;
  font-feature-settings: 'tnum' 1;
}

/* ---------- 通用工具类 ---------- */
.bc-card {
  background: var(--bc-bg-card);
  border: 1px solid var(--bc-border-hair);
  border-radius: var(--bc-radius-lg);
  padding: 20px;
  box-shadow: var(--bc-shadow-sm);
  transition: box-shadow var(--bc-dur-slow) var(--bc-ease-out),
              border-color var(--bc-dur) var(--bc-ease);
}
.bc-page-title { font-size: var(--bc-fs-xl); font-weight: 600; color: var(--bc-text-primary); margin: 0; }
.bc-muted { color: var(--bc-text-secondary); font-size: var(--bc-fs-xs); }

/* ---------- Element Plus 全局细调 ---------- */
.el-card {
  border-radius: var(--bc-radius-lg) !important;
  border: 1px solid var(--bc-border-hair) !important;
  box-shadow: var(--bc-shadow-sm) !important;
  background: var(--bc-bg-card) !important;
}
.el-button {
  font-weight: 500;
  border-radius: var(--bc-radius);
  letter-spacing: var(--bc-tracking-wide);
  transition: background-color var(--bc-dur) var(--bc-ease),
              border-color var(--bc-dur) var(--bc-ease),
              color var(--bc-dur) var(--bc-ease),
              box-shadow var(--bc-dur) var(--bc-ease);
}
/* primary hover 变深（覆盖 EP 默认的变浅） */
.el-button--primary:hover,
.el-button--primary:focus-visible {
  background-color: var(--bc-primary-hover);
  border-color: var(--bc-primary-hover);
}
.el-button--primary:active {
  background-color: var(--bc-primary-active);
  border-color: var(--bc-primary-active);
}
.el-button:focus-visible { outline: none; box-shadow: var(--bc-ring); }
.el-button.is-disabled, .el-button.is-disabled:hover { cursor: not-allowed; }

/* 表格 */
.el-table {
  --el-table-border-color:         var(--bc-border-hair);
  --el-table-text-color:           var(--bc-text-regular);
  --el-table-header-text-color:    var(--bc-text-secondary);
  --el-table-header-bg-color:      var(--bc-bg-inset);
  --el-table-row-hover-bg-color:   var(--bc-bg-hover);
  --el-table-current-row-bg-color: var(--bc-primary-50);
  --el-table-bg-color:             var(--bc-bg-card);
  --el-table-tr-bg-color:          var(--bc-bg-card);
  --el-table-expanded-cell-bg-color: var(--bc-bg-inset);
  font-size: var(--bc-fs-sm);
}
.el-table th.el-table__cell {
  font-size: var(--bc-fs-2xs);
  font-weight: 600;
  letter-spacing: var(--bc-tracking-caps);
  color: var(--bc-text-secondary);
  background: var(--bc-bg-inset);
  padding: 11px 0;
}
.el-table td.el-table__cell { padding: 13px 0; color: var(--bc-text-regular); }
.el-table .el-table__cell { border-bottom-color: var(--bc-border-hair); }

/* 表单 */
.el-input, .el-textarea, .el-select {
  --el-input-bg-color: var(--bc-bg-card);
  --el-input-border-color: var(--bc-border);
  --el-input-hover-border-color: var(--bc-border-strong);
  --el-input-focus-border-color: var(--bc-primary);
  --el-input-text-color: var(--bc-text-regular);
  --el-input-placeholder-color: var(--bc-text-placeholder);
  --el-input-border-radius: var(--bc-radius);
}
.el-input.is-disabled { --el-input-bg-color: var(--bc-bg-inset); }
.el-form-item__label {
  font-size: var(--bc-fs-sm);
  font-weight: 500;
  color: var(--bc-text-regular);
  padding-right: 12px;
}
.el-form-item__error { font-size: var(--bc-fs-xs); color: var(--bc-danger); }

/* ---------- Dialog ---------- */
.el-dialog {
  --el-dialog-border-radius: var(--bc-radius-xl);
  background: var(--bc-bg-elevated);
  box-shadow: var(--bc-shadow-xl);
}
.el-dialog__header {
  margin: 20px 20px 0 !important;
  padding: 14px 20px !important;
  background: rgba(15, 110, 140, .07);
  border-radius: 12px;
}
html.dark .el-dialog__header { background: rgba(51, 180, 208, .12); }
.el-dialog__title { font-size: var(--bc-fs-lg); font-weight: 600; color: var(--bc-text-primary); }
.el-dialog__body { padding: 20px 24px !important; }
.el-dialog__footer { padding: 12px 24px 28px !important; }
.el-dialog__headerbtn {
  top: 38px !important; right: 30px !important;
  width: 34px; height: 34px; border-radius: var(--bc-radius);
  transition: background-color var(--bc-dur) var(--bc-ease);
}
.el-dialog__headerbtn:hover { background: var(--bc-bg-hover); }
.el-dialog .el-form { max-width: 520px; margin: 0 auto; }
.el-dialog .el-form-item__label { padding-right: 12px; }
.el-overlay { backdrop-filter: blur(2px); }

/* ---------- Drawer ---------- */
.el-drawer {
  --el-drawer-bg-color: var(--bc-bg-elevated);
  box-shadow: var(--bc-shadow-xl);
}

/* ---------- 深色主按钮：亮青底 + 墨字 ---------- */
html.dark .el-button--primary {
  --el-button-text-color: #08131a;
  color: #08131a;
}
html.dark .el-button--primary:hover,
html.dark .el-button--primary:focus-visible {
  --el-button-text-color: #08131a;
  background-color: var(--bc-primary-hover);
  border-color: var(--bc-primary-hover);
  color: #08131a;
}

/* ---------- 滚动条 ---------- */
::-webkit-scrollbar { width: 8px; height: 8px; }
::-webkit-scrollbar-track { background: transparent; }
::-webkit-scrollbar-thumb { background: #c9d1d9; border-radius: 999px; }
::-webkit-scrollbar-thumb:hover { background: #a8b2bc; }
/* 深色滚动条（v1 缺失，深色下是浅灰条，属 bug） */
html.dark ::-webkit-scrollbar-thumb { background: #333d49; }
html.dark ::-webkit-scrollbar-thumb:hover { background: #47535f; }

/* ---------- 登录页深色（v1 缺失，深色下白底亮字，属 bug） ---------- */
html.dark .login-page {
  background:
    radial-gradient(1100px 520px at 18% 8%, rgba(51, 180, 208, .10) 0%, transparent 62%),
    radial-gradient(900px 460px at 88% 92%, rgba(128, 152, 240, .10) 0%, transparent 60%),
    var(--bc-bg-page);
}
html.dark .login-card {
  background: var(--bc-bg-elevated);
  border-color: var(--bc-border-light);
}
```

---

## 10. 落地路径

### 10.1 文件清单

| # | 文件 | 动作 | 内容 | 风险 |
|---|---|---|---|---|
| 1 | `src/style.css` | **重写**（主战场，约 520 行） | §8 Token 区 + §9 全局补丁区 | 低 |

> **建议：不拆文件。** 理由：
> - `main.js` 的引入顺序是 `element-plus/index.css` → `element-plus/theme-chalk/dark/css-vars.css` → `style.css`，这个顺序是**正确且关键**的（保证我们的覆盖在后）。拆成 `@import` 会引入 `@import` 必须置顶、Vite CSS 打包顺序等不确定性。
> - 单文件 520 行用 6 个注释区块分段，可维护性足够。
> - **零改动 `main.js`**，符合「不改动功能」约束。
>
> 若团队坚持拆分，可行方案（需改 `style.css` 顶部，不改 `main.js`）：
> ```css
> @import './styles/tokens.css';    /* 必须第一行 */
> @import './styles/base.css';
> @import './styles/element.css';
> ```
> 注意：`@import` 之前的任何规则都会让整个 `@import` 失效，且 `@import` 引入的文件在 CSS 层面**仍按出现位置计算层叠顺序**，顺序可控。

### 10.2 组件内硬编码色替换清单（需 Phase 3 逐个改，均为纯视觉替换，不改结构）

按工作量排序：

| 文件 | 处数 | 要替换的内容 |
|---|---|---|
| `src/views/Servers.vue` | 21 | 状态点/进度条/标签硬编码色 → 语义 token |
| `src/views/Dashboard.vue` | 17 | `tone-blue/indigo/emerald/red/cyan/purple/amber/pink`（8 组底+字）、`chip-blue/chip-purple`、`badge.ok/fail/run`、`site-avatar` |
| `src/components/StoragePool.vue` | 17 | 段色 → `--bc-chart-1..6`；`.stack-seg + .stack-seg` 的 `rgba(255,255,255,.7)` → `var(--bc-bg-card)`；`.stack-bar` 高 6→8px |
| `src/components/ServerDetailDrawer.vue` | 15 | `badge.ok/gray/warn/fail` → 语义 token；`chip` 色 |
| `src/components/WebsiteFormDialog.vue` | 14 | 表单内状态色 |
| `src/views/Storages.vue` | 12 | 类型标签色 |
| `src/views/Websites.vue` | 10 | 状态点色 |
| `src/views/Help.vue` | 10 | 代码块、chip |
| `src/components/settings/SystemSection.vue` | 10 | 徽标色 |
| `src/views/BackupLogs.vue` | 9 | 状态标签 |
| `src/components/charts/AreaChart.vue` | 4 | 见 §6.11（**可用全局 CSS 覆盖，不必改文件**） |
| `src/components/charts/BarChart.vue` | 3 | `.bar` 渐变 + hover 渐变（**必须改文件或用 `!important`**，见 R6） |
| `src/layouts/DefaultLayout.vue` | 3 | `.avatar` 渐变、`brand-icon` 底色 |
| `src/views/Login.vue` | 6 | 页面渐变、卡片、`brand-icon` 渐变 |
| `src/components/settings/*` | ~8 | 分散徽标色 |

**替换范式**（只改颜色值，不动选择器、不动 DOM）：
```css
/* before */
.tone-blue { background: #eff6ff; color: #2563eb; }
/* after */
.tone-blue { background: #e9f5f9; color: var(--bc-primary); }

/* before */
.badge.ok { background: #ecfdf5; color: #059669; border: 1px solid #a7f3d0; }
/* after */
.badge.ok { background: var(--bc-success-bg); color: var(--bc-success); border: 1px solid var(--bc-success-border); }
```

### 10.3 保证 Element Plus 同步生效的三个要点

1. **顺序**：`style.css` 必须在 `element-plus/dist/index.css` 和 `theme-chalk/dark/css-vars.css` **之后**引入（`main.js` 现状 ✅ 已满足，勿改）。
2. **特异性**：`html.dark`（0,1,1）> `:root`（0,1,0）。所以深色必须写 `html.dark { --el-* }`，不能只写 `:root`。
3. **组件级变量**：EP 把很多变量定义在组件自身选择器上（如 `.el-table { --el-table-... }`、`.el-dialog { --el-dialog-... }`、`.el-input { --el-input-... }`）。这类变量**必须在同选择器上覆盖**，写 `:root` 无效。§9 已按此规则编写。

### 10.4 执行顺序建议

1. 备份 `src/style.css` → 整体替换（§8 + §9）→ 浅色/深色各跑一遍冒烟
2. 修 `Login.vue` 深色（R5，最严重的可见 bug）
3. 修滚动条深色（一行）
4. 替换 `Dashboard.vue` 的 `tone-*` / `badge` / `chip`（视觉收益最大）
5. 替换其余组件硬编码色
6. `AreaChart` / `BarChart` 配色
7. 逐页走查：Dashboard / Servers / Websites / BackupLogs / Storages / ResourceLibrary / Settings(6 section) / Help / Login

---

## 11. 风险提示

### 🔴 R1 · 深色模式下 Element Plus 完全不受 `--bc-*` 控制（最高优先级）

`main.js` 引入了 `element-plus/theme-chalk/dark/css-vars.css`，其中包含：
```css
html.dark { --el-color-primary:#409eff; --el-bg-color-page:#0a0a0a; --el-bg-color:#141414;
            --el-border-color:#4c4d4f; --el-text-color-primary:#E5EAF3; ... }
```
`html.dark`（特异性 0,1,1）**高于** `:root`（0,1,0）。而现有 `style.css` 只在 `:root` 里定义 `--el-*`。

**后果**：深色模式下，所有 `el-button` / `el-input` / `el-table` / `el-dialog` 都在用 EP 自带的 `#409eff` 蓝、`#0a0a0a` 黑、`#4c4d4f` 边框，而自定义组件在用 `--bc-*` 的 slate 深色。**两套主题并存，视觉撕裂**。

**解法**：新增 `html.dark { --el-* }` 完整覆盖块（§7.2 / §8 已给）。这是本次落地的第一优先级。

---

### 🔴 R2 · 深色模式下主按钮白字对比度不足

`#33B4D0` 对白字仅 **2.45:1**（远低于 4.5:1）。EP 硬编码 `--el-button-text-color: var(--el-color-white)`。

**解法**：必须显式覆盖（§7.2 已给）：
```css
html.dark .el-button--primary { --el-button-text-color: #08131a; color: #08131a; }
```
（`#08131A` on `#33B4D0` = **8.02:1** ✅）

---

### 🔴 R3 · `--el-fill-color-light` 未映射，表格 hover 用的是 EP 默认灰

EP 的 `--el-table-row-hover-bg-color: var(--el-fill-color-light)`。现有 `style.css` 只映射了 `--el-fill-color-blank`，所以表格行 hover 一直是 EP 默认 `#f5f7fa`，**没跟上 `--bc-bg-hover`**。
**解法**：补齐 `--el-fill-color` / `-light` / `-lighter` / `-dark` / `-darker` 五个（§7.1 已列）。

---

### 🟠 R4 · 侧栏菜单用了 `!important`，改值时别被挡住

`DefaultLayout.vue` 里 `:deep(.side-menu .el-menu-item) { padding-left: 12px !important; }` 等。
**注意**：这些 `!important` 锁定的是 `padding-left`，**不影响颜色**。改 `--bc-sidebar-*` token 即可生效，无需动组件。但如果后续想改 `padding`，必须同步改组件。

---

### 🟠 R5 · 登录页深色模式是「白底 + 亮字」的可见 bug

`Login.vue` 的 `.login-page` 背景硬编码 `#eff6ff / #e0f2fe / #f8fafc`，`.login-card` 硬编码 `#ffffff`。
但 `applyTheme()` 在 `main.js` 启动时执行，若 localStorage 为 dark，`<html>` 会带 `.dark` → EP 输入框变深色、文字 token 变亮色 → **深色输入框 + 浅色文字压在白卡片上**。

**解法**：§9 末尾的 `html.dark .login-page` / `html.dark .login-card` 覆盖（全局 CSS 即可，无需改 `Login.vue`——虽然 `.login-page` 是 scoped，但 `html.dark .login-page` 特异性 (0,2,1) 高于 scoped 编译后的 `.login-page[data-v-x]` (0,2,0)，**能覆盖**；若保险起见也可直接改 `Login.vue`）。

---

### 🟠 R6 · `BarChart.vue` 的柱体渐变：全局 CSS 覆盖会输给 scoped 样式

`.bar { background: linear-gradient(...) }` 编译后是 `.bar[data-v-xxx]`（特异性 0,2,0）。全局写 `.bar { ... }`（0,1,0）会输；写 `.bar-chart .bar`（0,2,0）**特异性相同，顺序不确定**（scoped 样式注入时机在 dev/prod 下不同）。

**推荐解法（二选一）**：
- **A（推荐）**：直接改 `BarChart.vue` 的 3 行颜色值为 `var(--bc-chart-1)` 等——纯视觉替换，符合「极少量样式微调」许可。
- **B**：全局加 `!important`：
  ```css
  .bar-chart .bar { background: linear-gradient(180deg, #2e93ae 0%, #0f6e8c 100%) !important; }
  .bar-chart .bar.is-hovered { background: linear-gradient(180deg, #45a5bd 0%, #2e93ae 100%) !important; }
  ```

---

### 🟡 R7 · `AreaChart` 的 SVG 颜色**可以**用全局 CSS 覆盖（好消息）

`stroke="#2563eb"` / `fill="#2563eb"` / `<stop stop-color="#3b82f6">` 都是 **SVG presentation attribute**，其优先级低于任何 CSS 规则。所以：
```css
.chart-svg polyline { stroke: var(--bc-chart-1); }
.chart-svg polygon  { fill: url(#area-grad); }
.chart-svg circle   { fill: var(--bc-bg-card); stroke: var(--bc-chart-1); stroke-width: 1.4; }
#area-grad stop:first-child { stop-color: var(--bc-chart-1); stop-opacity: .28; }
#area-grad stop:last-child  { stop-color: var(--bc-chart-1); stop-opacity: .02; }
```
⚠️ 但 `#area-grad` 是**全局 id**（非 scoped 哈希），若同页出现两个 AreaChart 会 id 冲突。当前 Dashboard 只有一个，**可安全使用**；若后续复用需改为动态 id。

---

### 🟡 R8 · `.el-dialog__headerbtn` 的 `top: 38px` 与 header 布局强耦合

若 Phase 3 调整 `.el-dialog__header` 的 `margin`/`padding`，必须同步改 `top`。建议把这两个值写在一起并加注释。

### 🟡 R9 · 全局 `user-select: none` 的副作用

现状 `html, body, #app { user-select: none }`。新增的 `tabular-nums` 不影响它，但**新增任何需要复制的文本节点**（如命令块）必须显式加 `.selectable` 或加入 `style.css` 的选择器白名单。

### 🟡 R10 · `transition: all` 需清理

`Footer.vue` 的 `.contact-btn { transition: all .15s }`、`Dashboard.vue` 的 `.range-btn { transition: all .15s }` 会导致 `transform`/`width` 等意外参与动画。建议改为显式属性列表（§5.4）。

### 🟡 R11 · `--bc-font-num`（Didone）的两个注意点

1. **中文字形缺失**：Didot / Bodoni MT 无中文。若某个元素同时含中文和数字（如「本周成功备份 12」），会回退到系统宋体，视觉断裂。→ **只用于纯数字节点**。
2. **Windows 上 `Bodoni MT` 的字重**：只有 Regular/Bold/Italic，没有 500/600。设 `font-weight: 600` 会触发**合成粗体**（faux bold），在小字号下会糊。→ 建议 KPI 数值用 `font-weight: 600` 但字号 ≥28px（合成粗体在大字号下反而增加对比度，符合 Didone 气质）；若不满意，改为 `font-weight: 400` + 字号 30px。

### 🟡 R12 · 半透明边框在 `backdrop-filter` 元素上的表现

`.el-card`、`--bc-border-hair` 均为半透明。若某处同时启用毛玻璃（`--bc-glass-bg`），边框会因背景透出而偏色。默认方案未启用毛玻璃，无此问题；若启用需局部改用实色边框。

### 🟢 R13 · `el-collapse` / `el-tabs` / `el-progress` / `el-pagination`

这四个组件的变量未在 §7 逐一列出，但它们都依赖 `--el-border-color-lighter`、`--el-text-color-primary`、`--el-fill-color-lighter`、`--el-color-primary`，补齐 §7.1 后会自动跟随。**走查时重点看 `Help.vue` 的 `el-collapse`（745 行，折叠面板最多）和 `Settings.vue` 的 `el-tabs`。**

---

## 12. 验收清单（Phase 3 自查用）

- [ ] 浅色下 `el-button--primary` 背景 = `#0F6E8C`，hover 变**深**为 `#0C5870`
- [ ] 深色下 `el-button--primary` 背景 = `#33B4D0`，文字为**墨色** `#08131A`（不是白字）
- [ ] 深色下 `el-dialog` 背景 = `#1B212A`（不是 EP 的 `#141414`）
- [ ] 深色下 `el-input` 背景 = `#151A21`（不是 EP 的 `#141414`）
- [ ] 深色下表格行 hover = `#1E252F`（不是 EP 默认）
- [ ] 深色下滚动条滑块是深灰（不是浅灰）
- [ ] 深色下登录页是深色背景 + 深色卡片 + 亮色文字
- [ ] 侧栏激活项左侧有 3px 青色指示条
- [ ] 卡片圆角 14px、Dialog 圆角 20px（原来 8px / 10px）
- [ ] KPI 数值是 Didone 衬线（Windows 上应为 Bodoni MT，macOS 上应为 Didot）
- [ ] 表格数字不跳动（`tabular-nums`）
- [ ] 页脚所有文字 12px / 400，链接 hover 加下划线而非加粗
- [ ] 全站找不到 `#2563eb` / `#409eff` / `#eff6ff`（应已被替换）
- [ ] Tab 键走查：所有可聚焦元素有可见 focus ring
- [ ] 浅色/深色各截 9 个页面，无「亮底暗字」或「暗底暗字」

---

## 附录 A · 一句话速查卡（给工程师贴墙用）

```
主色    #0F6E8C / hover #0C5870 / active #0A4A60 / 淡底 #E9F5F9
页面底  #F4F6F8    卡片 #FFFFFF    内嵌 #F7F9FA    Hover #EEF1F4
边框    rgba(16,24,40,.10 / .07 / .05)   ← 越细的事用越淡的
文字    #131A23 / #3A4552 / #66717F / #8A94A0
成功 #0F7A52  警告 #96600E  危险 #C0353B  信息 #4C63D2
圆角    4 / 6 / 10 / 14 / 20 / 999
阴影    永远 2 层，最大透明度 ≤ .18
过渡    180ms cubic-bezier(.4,0,.2,1)；悬浮抬升 260ms cubic-bezier(.16,1,.3,1)
深色    页面 #0D1117 / 卡片 #151A21 / 主色 #33B4D0（配墨色字）
```
