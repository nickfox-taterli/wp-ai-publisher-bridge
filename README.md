# AI Publisher Bridge (APB)

真·无干预全自动 WordPress 建站插件.

丢一台服务器,配好 AI 模型的 key,然后就不用管了--它会自己找话题,自己写文章,自己发到 WordPress 上.没人盯也没事,睡醒一看站就更新了.

## 这玩意儿是啥

两部分组成:

- **plugin/** - WordPress 插件,负责后台管理界面,REST API,文章发布
- **worker/** - Python 后端,负责调 AI 写文章,跟 WordPress 对接

Worker 跑起来之后,它会:
1. 自己想写啥(用 AI 选话题)
2. 自己写(调大模型生成内容)
3. 自己发(通过 REST API 推到 WordPress)
4. 循环往复,永不停歇

未来也支持pull模式,就是你在WordPress后台手动建任务,worker去拉下来执行.

## 环境要求

- WordPress 6.0+,PHP 8.1+
- Python 3.10+
- 一个兼容 OpenAI API 的模型(作者用 MiniMax 测试的,其他模型没怎么测)
- Linux 服务器(内核文章功能需要准备内核源码,可选)

## 配置步骤

### 1. 装 WordPress 插件

把 `plugin/` 目录整个丢到 WordPress 的 `wp-content/plugins/ai-publisher-bridge/` 下,然后去后台激活.

激活后会自动建表,后台左侧菜单会出现 "APB 管理".

### 2. 后台配置

进 WordPress 后台 → APB 管理 → 设置,填:

- AI 模型的 API Key
- API Base URL(支持 OpenAI 兼容接口)
- 模型名称
- 通讯 Token(插件和 worker 之间的认证令牌,自己定一个就行)

API 文档在后台 "APB 管理 → API 文档" 里也能看到.

### 3. 装 Worker

```bash
cd worker/
python -m venv venv
source venv/bin/activate
pip install -r requirements.txt
```

### 4. 跑起来

```bash
source venv/bin/activate

# 自主模式:AI 自己选话题写文章发出去
python main.py

# 连续发 3 篇
python main.py -n 3

# 拉取模式:处理后台手动建的任务
python main.py --pull

# 测试模式:本地生成看看效果,不提交
python main.py --test 1
```

### 5. 内核文章(可选)

如果你想让 worker 写 Linux 内核相关的深度文章,需要准备一份内核源码:

```bash
# 随便放哪都行,比如:
mkdir -p /opt/linux-sources
cd /opt/linux-sources
# 解压一份内核源码tarball到这,保持原始目录结构
```

源码目录结构大概是 `源码根目录/subsystem/xxx.c` 这种,worker 会随机挑文件来分析然后写文章.不需要整个内核都放,放几个感兴趣的子系统就行.

## 注意事项 & 风险

- **这是全自动的**,没人看着就会一直发,注意别把站发满了
- **AI 生成的内容质量看模型**,MiniMax 表现还行,其他模型不确定
- **Token 会花钱**,全自动意味着持续消耗 API token,注意账单
- **默认 token 是硬编码的**,`config.py` 里的 `APB_TOKEN` 是默认值,正式用的话建议改掉或者通过环境变量 `APB_TOKEN` 传入
- **删插件会删表**,uninstall 会把 `apb_jobs` 表和配置项都删了,任务数据就没了
- WordPress 端和 Worker 之间的通讯没有 HTTPS(默认 localhost),如果要远程部署记得自己加一层

## 环境变量

| 变量 | 说明 | 默认值 |
|------|------|--------|
| `APB_BASE` | WordPress REST API 地址 | `http://127.0.0.1:10087/wp-json/apb/v1` |
| `APB_TOKEN` | 通讯认证 token | 硬编码在 config.py 里 |
| `HTTPS_PROXY` / `HTTP_PROXY` | 代理地址,worker 调 AI API 时走这个 | 无 |
| `NO_PROXY` | 不走代理的地址 | `localhost,127.0.0.1` |

## 关于代码

这个项目部分代码由多个不同 AI 模型生成,主要集中在前端部分(因为作者前端比较拉胯).后端基本是手撸的.

项目解耦不太好,一开始没打算做成分享插件,就是自己用着顺手的东西,后来才想着整理出来.凑合用吧.
