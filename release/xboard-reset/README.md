# Xboard 流量重置：构建产物与部署文件核对

本目录固定经过适配验证的 Xboard 基镜像，封装原生事务补丁与 FakaBridge 1.8.0。默认不开启 v2 查询、免费或付费执行，不读业务环境文件，不含密钥。构建、文件核对成功不等于真实 QQ 或生产上线验收通过。

2026-10-26 第十三批已按用户选择增加现用SQLite支持：生产同一文件型main/WAL、synchronous至少NORMAL、无attached和关键TEMP遮蔽；MySQL仍要求InnoDB。核心补丁更新为SQLite先取得写锁再读快照的版本，摘要以base-lock.json为准。第十一批旧镜像/源包仅作历史快照，不能与新锁定文件/清单混用。数据库能力、共享持久文件与真实进程仍须部署后核验，文件探针不检查这些条件。

## 第一步：先识别现用部署（由服务器操作者执行）

在 Xboard、AstrBot/NapCat 所在服务器分别执行；同一服务器只执行一次：

```bash
docker ps --format 'table {{.Names}}\t{{.Image}}\t{{.Status}}'
```

从输出找到 Xboard web 和 horizon/queue 容器，用实际名称替换下例。只看容器使用的镜像 ID，不要输出完整 inspect、Config.Env、.env 或机器人登录凭据。

```bash
docker inspect --format '{{.Image}}' Xboard的web容器名
docker inspect --format '{{.Image}}' Xboard的队列容器名
# 用上一步 sha256:... 的镜像 ID 代替最后一项；两者不同则分别查。
docker image inspect --format '{{json .RepoDigests}}' sha256:上一条得到的镜像ID
```

将容器名称/镜像名/镜像 ID/RepoDigests 交给维护者核对。私有构建的 RepoDigests 可能为空，只能说明本地镜像身份，不能猜测上游版本。没有 Docker 时先说明安装方式与版本，不照搬后续命令。不要因为看到 :new/latest 就覆盖现用核心。

## 源码安装包（本地生成，不上传或部署）

```bash
python3 scripts/xboard-reset-release.py bundle --output /tmp/monexus-xboard-reset-source.zip
```

仅打包此工具、固定锁定文件、补丁生成器和明确的插件源码清单；拒绝符号链接/未知插件文件、非空默认 bridge 密钥及默认开启的 v2 开关。ZIP 中有 BUNDLE-SHA256.json，不含环境、数据库、现有生产脚本或 AstrBot 登录状态。SHA256 用来与发布者提供的值比对，不是数字签名。

服务器构建时可在新的工作目录解压后进入 monexus-xboard-reset，运行同样的构建命令。安装包只提供源文件，不自动安装插件、不操作容器或发消息。

## 固定输入的本地镜像构建

需要 Python 3.10+、Docker 及 base-lock.json 指定的基镜像。源始终来自固定 digest，不从浮动 tag 推断；基镜像缺失时 Docker 可能从 registry 拉取该固定 digest。只支持锁定核心摘要，未知版本拒绝构建，不在运行容器里打临时补丁。

```bash
python3 scripts/xboard-reset-release.py build \
  --tag monexus/xboard-reset:reviewed-local \
  --output-dir /tmp/monexus-reset-reviewed-release
```

输出目录必须尚不存在，避免覆盖旧发布证据。工具绕过正常容器 entrypoint 提取原始核心，核验两个服务的摘要，生成固定补丁，在最小隔离构建上下文内复制插件；Docker build 的 RUN 禁止网络。构建内 PHP 验证实际落盘文件，不启动 Xboard/数据库，不执行迁移。

- manifest.json：固定基镜像、revision、核心摘要、插件全部文件摘要、PHP/JSON 运行文件摘要及与 capabilities.build_id 一致的构建 ID。输入相同则清单字节相同。插件版本号只作元数据一致检查，不能替代 buildId/文件摘要；本次插件源码快照以实际生成清单为准，未由 base-lock.json 硬编码源码 hash。
- release.json：清单 SHA256 和本机最终 imageId。registryDigest=null 表示没有发布到 registry；本机 image ID 不能冒充 registry manifest digest。
- 镜像 /www/monexus-reset-release.json 保存同份清单。保留基镜像原 entrypoint/CMD；本工具不决定你的生产启动参数。

不承诺跨 Docker 版本/构建时间的整个镜像二进制完全一致。后续发布 registry、取得最终不可变 digest、备份与部署需另按实际环境安排，本工具没有 push/up/restart/install 功能。保留旧镜像和所有操作/消费/tombstone 记录；回退不得删除数据库表或改变稳定实例/client。

## 已明确选择的两个运行容器核对

在管理该 Docker daemon 的主机执行，需要与构建时相同的工具和受信发布清单。使用实际 web 和一个 worker 的容器名或 ID；如还有 scheduler/consumer 等常驻进程，应逐一替换 --worker 重复核对。不同参数指向同一容器也会失败。

```bash
python3 scripts/xboard-reset-release.py check \
  --release-dir /path/to/monexus-reset-reviewed-release \
  --web xboard-web-1 \
  --worker xboard-horizon-1
```

仅选择 inspect 的容器 ID、镜像 ID和运行状态；用本地受信 PHP 探针读取实际容器文件，不读取 Config.Env、不启动框架、不查数据库、不外呼、不重启服务。会检测旧镜像、缺失容器、核心漂移、插件文件增删改、宿主机旧插件挂载及发布清单被修改。除新增 PHP CLI 进程外不修改应用数据。

exit 0 / ok=true 仅表示所选两个运行容器的镜像及文件匹配，coverage=running_container_artifact_files_only，productionReady 始终 false。核对时应避免并发更新；工具按不可变容器 ID 执行探针并复查容器名称绑定，发现替换会失败。它不能证明常驻内存/OPcache 已重载、插件已安装并迁移、InnoDB/配置正确、签名能力可用或真实 QQ 正常；部署时仍需按实际环境重载并取得运行证据。

签名 capabilities 属于业务只读请求，但会写防重放 nonce 表，不能把它叫作数据库零写入检查。本工具不发该请求，也不调用 order-paid、reset、revoke 或取消操作。

## 本地定向验证

```bash
python3 -m unittest discover -s tests/release -p 'test_*.py' -v
python3 tests/release/smoke_xboard_reset_release.py \
  --release-dir /tmp/monexus-reset-reviewed-release
```

第二条只在源码仓库提供；不随生产源文件安装包分发。临时容器 network=none、read-only、无宿主端口，只运行 PHP 等待进程以测试文件核对，自动清理。它们并非真正的 web/horizon 服务；通过不能替代完整 Kernel、插件迁移、数据库、MoNexus或AstrBot/NapCat验收。
