"""API 用量追踪 - 基于字符数估算 Token 消耗"""

import time

# 全局用量累积器
_records: list[dict] = []

# Token 估算比例
# 中文: ~1.5 token/字符  英文: ~0.25 token/字符
_ZH_RATIO = 1.5
_EN_RATIO = 0.25


def estimate_tokens(text: str) -> int:
    """基于字符组成估算 token 数量"""
    if not text:
        return 0
    zh = sum(1 for c in text if "\u4e00" <= c <= "\u9fff")
    other = len(text) - zh
    return int(zh * _ZH_RATIO + other * _EN_RATIO)


def track(call_type: str, model: str, prompt_text: str, response_text: str,
          latency_ms: int):
    """记录一次 API 调用的估算用量"""
    prompt_tokens = estimate_tokens(prompt_text)
    completion_tokens = estimate_tokens(response_text)
    total_tokens = prompt_tokens + completion_tokens
    tps = round(completion_tokens / (latency_ms / 1000), 2) if latency_ms > 0 else 0.0

    _records.append({
        "call_type": call_type,
        "model": model,
        "prompt_tokens": prompt_tokens,
        "completion_tokens": completion_tokens,
        "total_tokens": total_tokens,
        "latency_ms": latency_ms,
        "tps": tps,
    })


def collect() -> list[dict]:
    """返回所有累积的用量记录并清空"""
    out = _records.copy()
    _records.clear()
    return out


def peek() -> list[dict]:
    """查看当前累积记录但不清空"""
    return _records.copy()


def reset():
    """清空累积记录"""
    _records.clear()


class timed_call:
    """上下文管理器: 测量 API 调用耗时

    用法::

        with timed_call() as t:
            resp = ai_client.chat.completions.create(...)
        latency_ms = t.ms
    """

    def __init__(self):
        self.ms = 0

    def __enter__(self):
        self._start = time.monotonic()
        return self

    def __exit__(self, *_):
        self.ms = int((time.monotonic() - self._start) * 1000)
