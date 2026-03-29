"""Linux 内核源码分析工具"""

import random
import re
from pathlib import Path

import config as _config


KERNEL_SUBSYSTEM_MAP = {
    "进程": ["kernel/"],
    "调度": ["kernel/sched/"],
    "内存": ["mm/"],
    "文件系统": ["fs/"],
    "网络": ["net/"],
    "块设备": ["block/"],
    "驱动": ["drivers/"],
    "安全": ["security/"],
    "加密": ["crypto/"],
    "io_uring": ["io_uring/"],
    "系统调用": ["kernel/"],
    "中断": ["kernel/irq/"],
    "ipc": ["ipc/"],
    "初始化": ["init/"],
    "虚拟化": ["virt/", "drivers/virtio/"],
    "时间": ["kernel/time/"],
    "信号": ["kernel/signal.c"],
    "锁": ["kernel/locking/"],
    "追踪": ["kernel/trace/"],
    "电源": ["kernel/power/"],
    "printk": ["kernel/printk/"],
}

TEST_KERNEL_TOPICS = [
    ("Linux 内核进程调度器 CFS 源码走读", "调度器,CFS,进程管理,sched"),
    ("深入理解 Linux 内核内存管理", "slab,伙伴系统,页面置换,mm"),
    ("Linux 内核 io_uring 高性能异步 IO 源码解析", "io_uring,异步IO,高性能"),
    ("Linux 内核网络协议栈初探", "socket,TCP,netfilter,sk_buff"),
    ("Linux 内核中断处理机制详解", "中断,IRQ,软中断,tasklet,softirq"),
    ("Linux 内核文件系统 VFS 层源码走读", "VFS,superblock,inode,dentry"),
    ("Linux 内核系统调用实现原理", "syscall,系统调用表,VDSO,syscalls"),
    ("Linux 内核进程间通信 IPC 源码分析", "信号量,共享内存,管道,消息队列"),
    ("Linux 内核 RCU 机制深度解析", "RCU,读写锁,无锁编程,rcu"),
    ("Linux 内核启动流程 start_kernel 源码跟踪", "start_kernel,init,引导,boot"),
    ("Linux 内核定时器与时间管理子系统", "hrtimer,tick,jiffies,timer"),
    ("Linux 内核块设备 IO 调度器源码解读", "blk-mq,deadline,io调度,block"),
]

TEST_ARDUINO_TOPICS = [
    ("Arduino 入门:从点亮一盏 LED 开始", "Arduino,LED,入门,digitalWrite,blink"),
    ("Arduino DHT11 温湿度传感器实战教程", "DHT11,温湿度,传感器,Arduino"),
    ("Arduino 超声波测距 HC-SR04 使用教程", "HC-SR04,超声波,测距,Arduino,pulseIn"),
    ("Arduino 舵机控制:从原理到实战", "舵机,servo,PWM,Arduino"),
    ("Arduino I2C OLED 显示屏实战", "OLED,I2C,SSD1306,Adafruit,显示屏"),
    ("Arduino ESP8266 WiFi 物联网入门", "ESP8266,WiFi,物联网,Arduino"),
    ("Arduino 红外遥控器解码与发送", "红外遥控,IRremote,NEC协议,Arduino"),
    ("Arduino 步进电机 28BYJ-48 精准控制", "步进电机,28BYJ-48,ULN2003,Arduino"),
    ("Arduino RFID RC522 读卡实战", "RFID,RC522,SPI,Arduino,读卡"),
    ("Arduino 智能避障小车项目实战", "智能小车,L298N,避障,超声波,Arduino"),
    ("Arduino 自动浇水系统:土壤湿度检测", "土壤湿度,继电器,水泵,自动浇水,Arduino"),
    ("Arduino 蓝牙模块 HC-05 手机遥控", "HC-05,蓝牙,Arduino,手机遥控"),
]

GENERAL_TECH_TOPICS = [
    ("Git 高级技巧:rebase,cherry-pick 与冲突解决", "Git,rebase,cherry-pick,版本控制"),
    ("Docker 容器化部署入门到实战", "Docker,容器化,Dockerfile,docker-compose"),
    ("Linux 命令行效率提升:Shell 实用技巧", "Linux,Shell,bash,命令行,效率"),
    ("Python 异步编程 asyncio 入门与实践", "Python,asyncio,异步编程,协程"),
    ("MySQL 索引优化实战指南", "MySQL,索引,查询优化,EXPLAIN"),
    ("RESTful API 设计最佳实践", "RESTful,API设计,HTTP,接口规范"),
    ("Python 装饰器深入理解与实战应用", "Python,装饰器,闭包,functools"),
    ("Nginx 反向代理与负载均衡配置", "Nginx,反向代理,负载均衡,upstream"),
    ("Redis 缓存策略与常见问题解决", "Redis,缓存,击穿,雪崩,穿透"),
    ("SSH 安全配置与密钥管理实践", "SSH,密钥,安全,配置,免密登录"),
]


def discover_kernel_sources(
    topic: str,
    keywords: str,
    kernel_root: str,
    max_files: int = 50,
    subsystem_map: dict | None = None,
) -> list[dict]:
    smap = subsystem_map if subsystem_map is not None else KERNEL_SUBSYSTEM_MAP
    text = f"{topic} {keywords}".lower()
    relevant_dirs = set()

    for kw, dirs in smap.items():
        if kw.lower() in text:
            relevant_dirs.update(dirs)

    # 没匹配到就随机挑一个子系统
    if not relevant_dirs:
        all_dirs = list(smap.values())
        random.shuffle(all_dirs)
        relevant_dirs = set(all_dirs[0])

    candidates = []
    kernel_root_path = Path(kernel_root)
    if not kernel_root_path.exists():
        print(f"  ⚠ 源码目录不存在: {kernel_root}")
        return []

    for d in relevant_dirs:
        full_dir = kernel_root_path / d
        if not full_dir.exists():
            continue
        for f in full_dir.rglob("*.c"):
            try:
                size = f.stat().st_size
            except OSError:
                continue
            if size < 100000:  # < 100KB
                rel = f.relative_to(kernel_root_path)
                candidates.append({
                    "path": str(f),
                    "rel_path": str(rel),
                    "size": size,
                    "size_kb": f"{size / 1024:.1f}KB",
                })

    # 太小的文件没意义,至少 500 字节
    candidates = [c for c in candidates if c["size"] > 500]
    random.shuffle(candidates)
    candidates.sort(key=lambda x: x["size"], reverse=True)
    return candidates[:max_files]


def read_source_segments(file_path: str, kernel_root: str, lines_per_segment: int = 40, max_segments: int = 3) -> list[dict]:
    p = Path(file_path)
    if not p.exists():
        return []

    try:
        content = p.read_text(encoding="utf-8", errors="replace")
    except Exception:
        return []

    lines = content.splitlines()
    segments = []
    current = []
    seg_start = 1

    for i, line in enumerate(lines, 1):
        current.append(line)

        if len(current) >= lines_per_segment:
            # 在空行或 } 处断开,尽量不切断函数
            if not line.strip() or line.strip() == "}":
                rel = str(p.relative_to(kernel_root)) if str(p).startswith(kernel_root) else p.name
                segments.append({
                    "file": str(p),
                    "rel_path": rel,
                    "start": seg_start,
                    "end": i,
                    "code": "\n".join(current),
                    "lines": len(current),
                })
                current = []
                seg_start = i + 1

                if len(segments) >= max_segments:
                    break

    if current and len(segments) < max_segments:
        rel = str(p.relative_to(kernel_root)) if str(p).startswith(kernel_root) else p.name
        segments.append({
            "file": str(p),
            "rel_path": rel,
            "start": seg_start,
            "end": len(lines),
            "code": "\n".join(current),
            "lines": len(current),
        })

    return segments


def ai_select_source_files(topic: str, candidates: list[dict], count: int = 3, max_retries: int = 2) -> list[str]:
    if not candidates:
        return []

    for attempt in range(1, max_retries + 1):
        # 每次重新采样,让不同尝试看到不同候选
        sample_size = min(30, len(candidates))
        sampled = random.sample(candidates, sample_size) if len(candidates) > sample_size else candidates

        file_list = "\n".join(
            f"  {i + 1}. {c['rel_path']} ({c['size_kb']})"
            for i, c in enumerate(sampled)
        )

        prompt = (
            f"我正在写一篇关于「{topic}」的博客,需要分析 Linux 内核源码.\n"
            f"以下是可以分析的源文件:\n{file_list}\n\n"
            f"请选择 {count} 个最有趣,最有分析价值的文件(按兴趣排序).\n"
            f"只回复文件序号,用逗号分隔,例如:2,5,8\n"
            f"不要输出任何其他内容."
        )

        try:
            resp = _config.ai_client.chat.completions.create(
                model=_config.AI_MODEL,
                messages=[{"role": "user", "content": prompt}],
                max_tokens=32,
            )
            text = resp.choices[0].message.content.strip()
            print(f"    AI 回复: {text[:60]}")

            nums = re.findall(r"\d+", text)
            selected = []
            for n in nums:
                idx = int(n) - 1
                if 0 <= idx < len(sampled) and sampled[idx]["path"] not in selected:
                    selected.append(sampled[idx]["path"])
                if len(selected) >= count:
                    break

            # 数字解析失败时试试从文本里匹配文件名
            if not selected:
                for c in sampled:
                    if c["rel_path"].split("/")[-1].replace(".c", "") in text:
                        selected.append(c["path"])
                    if len(selected) >= count:
                        break

            if selected:
                return selected

            print(f"  ⚠ AI 选文件解析失败 (尝试 {attempt}/{max_retries}),重新采样重试...")

        except Exception as e:
            print(f"  ⚠ AI 选文件失败 (尝试 {attempt}/{max_retries}): {e}")

    # 全部失败就抛异常,让调用方重来整个任务
    raise RuntimeError(f"AI 选文件连续 {max_retries} 次失败,无法继续生成")
