# 极验云网盘 · 部署文档

- 由 TG 困困开源 [@sad1881](https://t.me/sad1881) 困困承接各种业务 欢迎咨询

---

## 环境要求

| 组件 | 要求 |
| ---- | ---- |
| 操作系统 | Linux（推荐 Ubuntu / CentOS） |
| Web 服务器 | Nginx（**必需**，依赖 X-Accel-Redirect） |
| PHP | 8.x（8.0+，推荐 8.1+），需 `pdo_mysql` 扩展 |
| 数据库 | MySQL 5.7+ / MariaDB 10.3+ |
| 面板 | 宝塔面板（可选，本文按宝塔举例） |

> 纯 Nginx + PHP-FPM 环境同样可部署，无需任何 PHP 框架或 Composer 依赖。

---

## 一、上传源码

把本仓库所有文件上传到站点根目录，例如：

```
/www/wwwroot/your-pan/
```

确保目录结构如下（`storage/` 不存在则程序会自动创建）：

```
├── index.php  lib.php  dl.php  cleanup.php  config.php
├── assets/  lib/  data/
└── storage/   ← 运行后自动创建
```

---

## 二、创建数据库并导入表结构

在宝塔「数据库」里新建一个库（如 `pan`），然后导入：

```bash
mysql -u你的用户 -p 你的库名 < database.sql
```

> `database.sql` 只含建表结构，无任何数据，可放心导入。

---

## 三、修改 config.php

编辑 `config.php`，填你自己的数据库信息：

```php
'db' => [
    'host'    => 'localhost',
    'port'    => 3306,
    'name'    => '你的库名',
    'user'    => '你的数据库用户',
    'pass'    => '你的数据库密码',
    'charset' => 'utf8mb4',
],
```

其余规则（签到积分、存储天数、容量上限等）按需调整，均有注释说明。

---

## 四、配置 Nginx

参照 `nginx.conf.example`，把以下关键配置放入站点的 server 块（宝塔：网站 → 设置 → 配置文件）：

```nginx
client_max_body_size 2048m;
fastcgi_send_timeout 3600s;
fastcgi_read_timeout 3600s;

location ^~ /storage { deny all; }
location ^~ /data    { deny all; }
location ^~ /lib     { deny all; }

location /__f/ {
    internal;
    alias /www/wwwroot/your-pan/storage/;   # 改成你的实际路径
}

rewrite "^/d/([a-f0-9]{32})$" /dl.php?t=$1 last;
fastcgi_intercept_errors off;
```

> 关键点：
> - `storage / data / lib` 必须 `deny all`，防止文件与程序库被直接下载；
> - `/__f/` 必须 `internal`，配合 `dl.php` 的 `X-Accel-Redirect` 高效分发文件；
> - `fastcgi_intercept_errors off` 保证 PHP 返回的 JSON 错误不被 nginx 替换成 HTML。

---

## 五、配置 PHP（.user.ini）

把 `.user.ini.example` 重命名为 `.user.ini` 放到站点根目录，
修改 `open_basedir` 为你的实际路径：

```ini
open_basedir=/www/wwwroot/your-pan/:/tmp/
upload_max_filesize=2048M
post_max_size=2048M
max_execution_time=0
max_input_time=-1
```

配置后重启 PHP-FPM 生效。

---

## 六、下载 ip2region 数据文件

上传 / 下载 IP 到市依赖 ip2region 数据，执行：

```bash
cd data
wget https://github.com/lionsoul2014/ip2region/raw/master/data/ip2region.xdb -O ip2region_v4.xdb
```

详见 [data/README.md](./data/README.md)。

---

## 七、创建管理员账号

1. 打开你的站点，注册一个普通账号（邮箱任意，无需验证）；
2. 在数据库里把它提升为管理员：

```sql
UPDATE users SET role='admin' WHERE email='你注册的邮箱';
```

3. 重新登录后，顶部会出现「管理」入口（或访问 `?admin=1`）。

> 管理员拥有：账号管理（封禁 / 解封 / 重置密码 / 调积分 / 删除）、
> 文件管理（删除 / 公开隐藏）、系统设置（改签到积分、存储天数、手动清理）。

---

## 八、设置定时清理

把过期文件清理脚本加入 cron（每 5 分钟一次）：

```bash
crontab -e
# 添加一行（路径改成你的实际路径）：
*/5 * * * * php /www/wwwroot/your-pan/cleanup.php >> /dev/null 2>&1
```

> 普通用户文件默认 30 天过期自动清理；管理员文件永久保留。

---

## 常见问题

**Q：上传大文件失败 / 413？**
A：nginx `client_max_body_size`、`.user.ini` 的 `upload_max_filesize` 与 `post_max_size` 三者都要调大并保持一致。

**Q：下载走的是 PHP 还是 Nginx？**
A：`dl.php` 校验后通过 `X-Accel-Redirect` 交给 Nginx 直接分发，不占用 PHP 内存，支持大文件与断点续传。

**Q：首页显示的是官网而不是网盘？**
A：未登录访问根路径默认显示官网首页；点「登录 / 进入网盘」进 `?login`；登录后根路径自动进入网盘。

**Q：想关闭注册？**
A：本程序注册入口在 `?login` 的「注册」Tab，如需关闭可自行在 `index.php` 的 `render_auth_page()` 里隐藏注册 Tab 与 `register` 接口，或仅保留管理员账号使用。

**Q：ip2region 查不到 IP 归属地？**
A：确认 `data/ip2region_v4.xdb` 已下载且文件名正确；内网 / 保留 IP 本身无归属地，会返回「未知」。

---

## 联系与业务

- TG：[@sad1881](https://t.me/sad1881)
- 困困承接各种业务，欢迎咨询

---

## 关于 AIGC

> 项目包含 AIGC 内容，AI 减少了开发时间；我们也认真审核了每一条代码。
