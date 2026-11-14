# 更新记录（CHANGELOG）

本仓库遵循 [语义化版本](https://semver.org/)：`v主.次.修订`。Center 发版 tag 与部署包对应，Agent 二进制版本独立演进（Center 发版说明中注明配套 Agent 版本）。

---

## v1.1.3（2026-11-13）

**配套 Agent 版本：v0.18.0**（协议变更，升级 Center 后必须同步升级 Agent）

### 变更 · 目录打包产物改用备份时间命名、存储端去日期层

此前目录打包在存储端按 `<目标目录>/<任务名>/<按日文件夹>/` 落盘，压缩包名混入 `任务名-dir-标签-` 前缀，某备份的全部历史分散在一堆日期目录里。本次按用户需求调整为：

- **压缩包命名 = 任务名 + 备份时间**：`FC2-20260809-040000.tar.gz`。同一次打包勾选了多个目录/文件时自动追加 `-<标签>` 段（`FC2-20260809-040000-配置.tar.gz`），同批次仍重名时追加序号。
- **存储端去掉按日文件夹**：压缩包直接落 `<存储目标目录>/<任务名>/`（如 `/root/BC/FC2/`），一个目录一屏看完该任务的所有历史备份，按文件名时间序自然排序。
- **范围**：仅目录打包模式。归档收集（`<任务名>/<日期>/` 布局）与目录快照（`<任务名>/<标签>/<时间>/` 布局，增量语义依赖日期结构）保持不变。
- **既有数据**：存储端已有的日期目录不迁移不删除，可自行清理；备份记录中的旧归档仍可正常恢复。

### 配套调整（命名联动修复）

文件名不再携带 `-dir-`/`-file-` 标记后，原先依赖文件名标记的三处链路改为显式参数，新旧归档均可识别：

- **上报**：Agent 打包上报新增 `source_type`（dir/file）字段，Center 据此直写 `source_key`（`self:dir` / `self:file`）；external 扫描与老版 Agent 上报继续走文件名推导兜底。
- **自动恢复**：advanced 恢复指令新增 `path_type=self_archive`（Center 依 backup_log 的 source_key 下发），Agent 以此分流「预备份 + 覆盖解压」；老命名归档（含 `-dir-`/`-file-`）仍按文件名标记兜底识别，**恢复行为与旧版完全一致**。
- **任务列表「最近备份」**：`recentSelfBackups` 改按 `source_key` 匹配打包产物（此前按 `-dir-` 文件名匹配，会漏掉单文件勾选产物；本次一并修正），文件勾选产物展示「文件归档」标签、目录产物展示「目录归档」。

### 升级指南

1. 推送 main → CI 绿灯（phpunit 92 项含新增命名契约用例 + 前端构建）。
2. 打 tag `v1.1.3` 发版 → 已部署 Center 的机器重跑一键部署升级（`BC_FORCE=1`）。
3. **同步升级 Agent 到 v0.18.0**：网页「服务器」页远程升级。旧版 Agent（≤ v0.17）仍能正常打包与恢复，但其产物继续使用旧命名与日期层布局；新命名归档由旧版 Agent 恢复时会退回留档模式（文件名无标记），建议尽快同步升级。
4. 验证：任务立即打包 → 存储端 `<目标目录>/<任务名>/` 下出现 `任务名-<时间>.tar.gz`；备份记录与任务列表「最近备份」正常显示；对新归档执行自动恢复 → 精确落回原目录。

---

## v1.1.2（2026-11-13）

**配套 Agent 版本：v0.17.0**（协议变更，升级 Center 后必须同步升级 Agent）

### 新增 · 目录打包精细勾选

任务表单的备份目录不再只能手输——每行新增「浏览」按钮，从服务器根目录逐级浏览目录树，勾选要备份的**目录或文件**（多选）：

- **浏览弹窗**：懒加载逐级展开（点开一层列一层）、文件夹/文件带图标与大小/修改时间、面包屑回跳、手输路径直达；勾选跨层保持，底部标签可逐个移除；归档收集/目录快照模式下文件行禁选（这两种方式要求路径为目录）。
- **勾选整个目录**（如 `/mnt/BBC`）→ Agent 整目录 tar.gz 打包（原有行为不变）。
- **勾选单个文件**（如 `/mnt/BBC/1.txt`）→ 单文件 tar.gz 打包，**包内保留父目录层**（`BBC/1.txt`）；多选文件/目录混合时逐项独立打包，一次打包指令产出多个归档。
- **恢复同构**：文件归档（`-file-` 命名）与目录归档（`-dir-` 命名）走同一套「预备份 + 覆盖解压」——自动恢复精确落回原位，手动下载的包内也能看到原目录结构。
- 每个勾选项独立上报备份记录，`origin_path` 记录其父目录；来源分组新增 `self:file`（文件归档），恢复页显示「文件归档」标签。

### 新增 · Agent 指令快速拉取通道（v0.17.0）

- Agent 新增每 3 秒一次的轻量指令拉取循环（`GET /api/agent/commands/pending`），浏览、立即打包、恢复等交互指令**秒级下发执行**，不再等 60 秒心跳；心跳通道保留（系统指标上报 + mirror 同步），两通道消费同一队列、原子标记不重复派发。
- Center 新增对应轻量端点与 `browse_dir` 指令类型、`POST /api/servers/{id}/browse` 下发接口（复用服务器操作员权限）；浏览只返回目录项元数据（名称/类型/大小/时间），不读文件内容、不携带凭据。

### 修复 · 卸载脚本与部署链路（首次正式发版收录的累计修复）

- **bc-uninstall.sh 一交互就退出**：`_read_tty` 函数末尾的 `[ ] && [ ] && eval` 短路链在用户输入非空时让函数以非零返回，被 `set -e` 直接终止且无任何报错——改回完整 `if` + `return 0`。
- **Let's Encrypt 证书清理从不出现**：nginx 模板 `server_name` 恒为通配符 `_`，原"从 nginx.conf 提域名"逻辑必然失效——改为优先读 `deploy/.bc-domain` 标记（bc-install 现已写入），无标记时从续期钩子文件名反推，**已部署机器无需重装即可生效**。
- **Center 调度器可能静默死亡**：docker-entrypoint 的调度循环被 `set -e` 传染，`schedule:run` 任何一次非零退出都会让全部定时任务永久停摆——循环内加 `|| true`。
- **compose down 期间工作目录被删**、grep/systemctl 无容错、结尾「证书保留」提示逻辑颠倒等健壮性问题一并修复；清理原版演示组件（bc-demo-*/komari）残留的删除逻辑。

### 修复 · 仓库转移（gongpz → SinGyoZ）全量更新

- GitHub 仓库转移后 `raw.githubusercontent.com` 不重定向，README/帮助页/脚本注释里的 35 处 gongpz 链接全部更新为 SinGyoZ（否则帮助页展示的安装/卸载命令直接 404）。
- `bc-install.sh` 的 `BC_REPO` 默认值改为 `SinGyoZ/backup-center`（防旧用户名被回收后的供应链风险）；Go module path 全链路改名（`github.com/SinGyoZ/backup-center/agent`）。
- 保留项：Footer/Login 的「By：Mask」原作者署名（AGPL 合规）、README 出处说明、历史作业书。

### 修复 · 后端测试（CI 首跑暴露）

- `StorageEncryptionTest.php` 文件头 UTF-8 BOM 导致 PHP Fatal error（namespace 不再是首语句）——已去除并全仓库扫描确认仅此一处。
- **模型外键与迁移列名错位**（18 个测试错误的根因）：中间表 `backup_task_storages` 与 `backup_logs` 的外键列为 `task_id`，而 `belongsToMany`/`hasMany` 按约定推断 `backup_task_id`——原版模型名 Website 恰与 `website_id` 吻合，改名后错位。模型侧显式声明外键（不动迁移，已部署库零成本兼容）。
- 测试断言对齐：快照文件名用例更新为 `<task>-snap-<label>-<ts>` 实际格式（incremental.go 生成规则）；StorageBrowse 断言对齐 SFTP 绝对路径契约（空 base_path + task 匹配产出 `/<任务名>`，与 Go 端逐字一致）。

### 移除 · 演示站

- 删除 GitHub Pages 演示站工作流 `demo-pages.yml`、浏览器内 mock 数据层 `frontend/src/demo/`、`http.js` 的 `VITE_DEMO` 接线、登录页演示提示、零引用的脚手架残留 `HelloWorld.vue` 及相关过时注释。
- 若此前已通过 Pages 发布过演示站，需到仓库 Settings → Pages 手动关闭一次。

### 升级指南

1. 推送 main → CI 绿灯（phpunit 87 项 + 前端构建）。
2. 打 tag `v1.1.2` 触发发版（release.yml 自动构建部署包并挂 Release）。
3. 已部署 Center 的机器重跑一键部署升级：`curl -fsSL https://raw.githubusercontent.com/SinGyoZ/backup-center/main/install-scripts/bc-install.sh | BC_FORCE=1 sudo -E bash`。
4. **同步升级 Agent 到 v0.17.0**：网页「服务器」页远程升级，或目标机器重跑 Agent 安装命令。旧版 Agent（≤ v0.16.0）不识别 `browse_dir` 指令（会明确报「未支持的指令类型」），浏览功能不可用；既有备份任务不受影响。
5. 验证：新建/编辑任务 → 备份目录点「浏览」→ 1-2 秒出目录树 → 勾选文件与目录混合 → 立即打包 → 备份记录出现「目录归档/文件归档」 → 对文件归档执行自动恢复 → 落回原路径。
