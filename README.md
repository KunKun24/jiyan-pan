# 极验云网盘

**中文** · [English](README.en.md)

一个轻量、私有、可外链直链分享的 PHP 多用户网盘。

> **在线演示（静态）**：<https://kunkun24.github.io/jiyan-pan/> —— 只有界面，没有后端。

- 由 TG 困困开源 [@sad1881](https://t.me/sad1881) 困困承接各种业务 欢迎咨询

---

## 特性

- 📤 **任意格式上传**：拖拽即传，管理员单文件上限可配（默认 2GB）
- 🔗 **外链直链分享**：一键生成直链，可限制下载次数、有效期，随时撤销
- 👥 **多用户**：邮箱注册（无需验证），不开放注册可在部署时关闭入口
- 🗂️ **文件列表 / 我的文件**：公开文件全平台可见，私人文件仅自己可见
- ⏱️ **每日签到**：签到领积分，积分兑换单文件大小上限（默认 50MB → 最高 200MB）
- 📍 **下载 / 上传留痕**：记录每次下载、上传的 IP 到市（ip2region）
- 🗑️ **自动过期清理**：普通用户文件默认 30 天清理，管理员文件永久保留
- 🛡️ **管理员后台**：账号管理（封禁 / 解封 / 重置密码 / 调积分 / 删除）、文件管理、系统设置
- 🖥️ **iOS 风格官网首页**：纯净黑白灰配色，无渐变无紫色

## 功能预览

| 页面 | 说明 |
| ---- | ---- |
| 官网首页 | 未登录访问根路径，iOS 风格，毛玻璃导航 + Hero + 特性区 |
| 登录 / 注册 | `?login`，邮箱注册无需验证 |
| 我的文件 | 上传、重命名、公开 / 隐藏、生成外链、删除 |
| 文件列表 | 全平台公开文件，登录即可直接下载 |
| 上传文件 | 限额校验 + 公开开关 + 违法违规提示 |
| 管理员后台 | `?admin=1`，账号管理 / 文件管理 / 系统设置 |

## 目录结构

```
├── index.php             # 单入口：官网 / 登录 / 网盘 / 管理后台
├── lib.php               # 公共函数库（DB、鉴权、积分、清理等）
├── dl.php                # 外链直链下载入口  /d/<token>
├── cleanup.php           # 过期文件清理（cron）
├── config.php            # 配置（数据库、存储、积分规则等）
├── database.sql          # 数据库建表结构（无数据）
├── nginx.conf.example    # Nginx 配置示例
├── .user.ini.example     # PHP 站点配置示例
├── assets/               # logo 等静态资源
├── lib/                  # ip2region 查询类（第三方，Apache-2.0）
├── data/                 # ip2region 数据文件（需自行下载，见 data/README.md）
└── storage/              # 上传文件存储目录（运行后自动创建，nginx 禁止直访）
```

## 技术栈

- PHP 8.x（无需任何框架、无 Composer 依赖）
- MySQL / MariaDB（PDO）
- Nginx（依赖 `X-Accel-Redirect` 做高效文件分发）
- 原生 JavaScript（fetch / FormData / XHR 上传进度）

## 快速开始

完整部署步骤见 [INSTALL.md](./INSTALL.md)，大致流程：

1. 上传源码到站点目录，配置 Nginx（参考 `nginx.conf.example`）
2. 导入 `database.sql` 建表
3. 修改 `config.php` 填数据库信息
4. 配置 `.user.ini`（参考 `.user.ini.example`）与 Nginx 上传上限
5. 下载 ip2region 数据文件（见 `data/README.md`）
6. 注册一个账号，然后把它设为管理员（见 INSTALL.md）
7. 设置 `cleanup.php` 的 cron 定时清理

## 安全说明

- `storage/`、`data/`、`lib/` 目录在 Nginx 中 `deny all`，文件只能通过 `dl.php` 分发
- `config.php`、`lib.php` 直接访问会返回 404
- 数据库密码等信息仅存于 `config.php`，请勿将真实配置提交到公开仓库

## 免责声明

本程序仅供学习交流与合法的私有文件分享使用。使用者需对上传内容负责，
**严禁上传任何违法违规文件**；如用于非法用途，一切后果由使用者自行承担。

## License

[MIT](./LICENSE) © 2026 极验云网盘 · TG 困困 @sad1881

> 其中 `lib/Searcher.class.php` 与 `data/ip2region_v4.xdb` 来自
> [lionsoul2014/ip2region](https://github.com/lionsoul2014/ip2region)，遵循 Apache-2.0 协议。

---

## 关于 AIGC

> 项目包含 AIGC 内容，AI 减少了开发时间；我们也认真审核了每一条代码。
