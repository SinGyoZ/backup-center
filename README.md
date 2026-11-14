# Backup Center（目录备份版）

自托管的多服务器**目录备份**编排系统。一台 Center 统一管理多台机器上的目录备份：归档收集、目录打包、增量快照，自动上传、集中查看、一键恢复。

- **Center**：Laravel 12 后端 + Vue 3 前端，Docker Compose 一键起
- **Agent**：Go 写的轻量代理，装在每台被备份的机器上，负责扫描归档、打包目录、快照与上传
- **存储**：S3 兼容 / SFTP / FTP-FTPS / WebDAV，可同时挂多个
- 如需要购买备份空间，推荐hostbrr的储存盒子 500GB每年才7欧元（StorageBox 500GB/€7.00/yr）
- 购买地址：https://my.hostbrr.com/order/forms/a/MjA3MjM=

> 本仓库是基于 gongpz/backup-center 的「目录备份改造版」（fork，原仓库已转移至本账号 SinGyoZ 名下）：备份对象从「网站」泛化为「服务器任意目录」。
> 部署请使用本仓库（SinGyoZ/backup-center）的安装地址，或你自己的仓库与发布源（见下方一键部署说明）。

---

## 特性

- **三种备份方式** —— 归档收集（扫描目录里的现成归档文件）/ 目录打包（Agent 逐目录 tar.gz 打包）/ 目录快照（rsync 硬链增量快照，仅 SFTP 存储）
- **多协议存储** —— S3 兼容（COS、OSS、R2、MinIO）、SFTP、FTP/FTPS、WebDAV
- **强制备份** —— 某个存储标记为「强制」后，所有任务自动向它推送一份
- **时间戳归组** —— 备份按 `<任务名>/YYYYMMDD/` 分目录存放，干净可追溯
- **两种恢复模式** —— 留档恢复（下载到本机留档）/ 自动恢复（解压覆盖到备份时记录的来源目录，恢复前自动预备份原目录）
- **定时与自动** —— 归档收集支持自动检测（每 2 分钟）或定时（每天指定时刻）；打包与快照支持每天定时或手动立即执行
- **Agent 远程升级** —— 网页点一下，Agent 从镜像源拉新二进制自更新
- **资源池** —— 集中管理多家对象存储凭证，可选作 Agent 下载镜像
- **系统自备份** —— Backup Center 自己也能定时打包上传到远端存储
- **页脚可配置** —— 版权、ICP 备案、统计、联系方式、自定义 HTML

---
## 快速开始

### 方式一 · 一键部署（推荐）

> **fork 部署提醒**：本改造版需要从**你自己的仓库**拉取部署包。先把本仓库推送到你的 GitHub，再打一个 tag（如 `v1.1.0`）触发 GitHub Actions 构建发布，然后在一台干净的 VPS 上（KVM / XEN 虚拟化，root 权限）执行：

```bash
curl -fsSL https://raw.githubusercontent.com/SinGyoZ/backup-center/main/install-scripts/bc-install.sh \
  | BC_REPO=SinGyoZ/backup-center sudo -E bash
```

脚本会交互式询问访问地址、证书方式、端口，然后自动完成：虚拟化兼容检查 → 装 Docker → 拉部署包 → 签证书 → 起容器 → 配置续期钩子 → 冒烟测试。

想跳过交互（自动化场景）：

```bash
curl -fsSL .../bc-install.sh | BC_ACCESS=bak.example.com BC_EMAIL=you@example.com sudo -E bash
```

常用环境变量：

| 变量 | 说明 |
|---|---|
| `BC_REPO` | 部署包源仓库（fork 部署时必须指定为你的仓库，如 `me/my-backup-center`） |
| `BC_ACCESS` | 访问地址（域名或 IP） |
| `BC_EMAIL` | Let's Encrypt 通知邮箱 |
| `BC_HTTP_ONLY=1` | 强制 HTTP-only |
| `BC_HTTP_PORT` / `BC_HTTPS_PORT` | 自定义端口（LXC 等绑不了 80/443 时用） |
| `BC_DEPLOY_URL` | 覆盖部署包下载源 |
| `BC_FORCE=1` | 覆盖已有安装 |

> ⚠️ **VPS 兼容性**：OpenVZ 6/7 不支持 Docker，脚本会拒绝安装。LXC 若无法绑特权端口，脚本自动切到 8080/8443。

### 方式二 · 手动部署

```bash
git clone https://github.com/SinGyoZ/backup-center.git
cd backup-center/backup-center

# 后端
cd backend && composer install
cp .env.example .env && php artisan key:generate
php artisan migrate
cd ..

# 前端
cd frontend && npm install && npm run build
cd ..
```

然后用 `deploy/docker-compose.yml` 起服务。

### 首次登录

部署完访问你的地址。系统检测到还没有任何用户时，会引导你**创建第一个管理员账号**（密码至少 8 位）。创建后该入口自动关闭。

---

## 部署 Agent

在 Center 的「服务器」页面添加一台服务器，复制生成的安装命令，到目标机器上执行：

```bash
curl -fsSL https://<your-center>/agent/install.sh | BC_URL="https://<your-center>" BC_TOKEN="<token>" sh
```

Agent 会以 systemd 服务运行，定期向 Center 心跳上报，并接收扫描 / 打包 / 快照 / 恢复 / 清理 / 升级指令。

> Agent 与 Center 版本必须配套（当前 Agent 为 v0.18.0，目录备份版协议）。升级 Center 后请同步重装或远程升级 Agent。

---

## 项目结构

```
backup-center/
├── backend/     Laravel 12 后端（PHP 8.2+）
├── frontend/    Vue 3 + Element Plus 前端
├── agent/       Go 备份代理（Go 1.23+）
└── deploy/      docker-compose + nginx 模板
install-scripts/ 一键部署 / 卸载脚本
docs/            产品与部署文档
```

---

## 开发

环境要求：Node.js ≥ 20、PHP ≥ 8.2 + Composer 2、Go ≥ 1.22、Docker + Docker Compose。

```bash
cd backup-center/frontend && npm run dev     # 前端开发模式
cd backup-center/backend && php artisan serve # 后端
cd backup-center/agent && GOOS=linux GOARCH=amd64 go build -o backup-agent ./
```

---

## 构建部署包 / 发布

一键部署脚本从 GitHub Release 拉取 `bc-deploy.tar.gz`，包内结构如下（解压到 `/opt/bc`）：

```
backend/                    Laravel 后端源码
deploy/                     docker-compose + nginx 模板
deploy/frontend-dist/       前端构建产物
deploy/agent-dist/          Agent 二进制（linux amd64 / arm64）
```

**推荐走 GitHub Actions** —— 打 tag 即自动构建并挂到 Release：

```bash
git tag v1.0.0 && git push origin v1.0.0
```

也可以本地构建（需 Node + Go）：

```bash
bash scripts/build-release.sh          # 产出 ./bc-deploy.tar.gz
SKIP_AGENT=1 bash scripts/build-release.sh   # 不重编 Agent，复用已有 deploy/agent-dist
```

然后手动上传到 Release 页面即可。

---

## 卸载

```bash
curl -fsSL https://raw.githubusercontent.com/SinGyoZ/backup-center/main/install-scripts/bc-uninstall.sh | sudo bash
```

交互式逐项确认，默认保留数据库、SSL 证书等资产，不会动 Docker 本身。

---

## 许可

本项目采用 **GNU Affero General Public License v3.0 (AGPL-3.0)** —— 详见 [LICENSE](./LICENSE)。

简而言之：你可以自由使用、修改和分发本项目，但**如果你将修改后的版本作为网络服务对外提供，必须同样公开你的完整源代码**。
