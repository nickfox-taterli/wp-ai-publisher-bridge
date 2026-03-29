"""拟人化后处理 - 标点英文化,错别字注入,标点随机化"""

import re
import random


# 中文标点 -> 英文标点
_FULL_WIDTH_MAP = {
    "\u2018": "'",   # '
    "\u2019": "'",   # '
    "\u201c": '"',   # "
    "\u201d": '"',   # "
    "\uff0c": ", ",  # ,
    "\u3002": ". ",  # .
    "\uff01": "! ",  # !
    "\uff1f": "? ",  # ?
    "\uff1a": ": ",  # :
    "\uff1b": "; ",  # ;
    "\uff08": " (",  # (
    "\uff09": ") ",  # )
    "\u3010": " [",  # [
    "\u3011": "] ",  # ]
    "\u300a": " <",  # <
    "\u300b": "> ",  # >
    "\u2026": "...",  # ...
    "\u2014": "--",   # --
}

# 保护代码块
_CODE_BLOCK_RE = re.compile(
    r"(\[sourcecode[^\]]*\].*?\[/sourcecode\]|<pre[^>]*>.*?</pre>)",
    re.DOTALL,
)

_HTML_TAG_RE = re.compile(r"<[^>]+>")


def convert_to_half_width_punctuation(html: str) -> str:
    parts = _CODE_BLOCK_RE.split(html)

    result = []
    for i, part in enumerate(parts):
        if _CODE_BLOCK_RE.match(part):
            result.append(part)
        else:
            result.append(_replace_in_text_nodes(part))

    return "".join(result)


def _replace_in_text_nodes(html: str) -> str:
    segments = _HTML_TAG_RE.split(html)

    for i, seg in enumerate(segments):
        if _HTML_TAG_RE.match(html[sum(len(s) for s in segments[:i]):
                                   sum(len(s) for s in segments[:i + 1])]):
            continue
        for cn, en in _FULL_WIDTH_MAP.items():
            seg = seg.replace(cn, en)
        segments[i] = seg

    # 重新拼接(标签和文本交替)
    output = []
    pos = 0
    tag_iter = list(_HTML_TAG_RE.finditer(html))
    seg_idx = 0

    for m in tag_iter:
        text_before = html[pos:m.start()]
        for cn, en in _FULL_WIDTH_MAP.items():
            text_before = text_before.replace(cn, en)
        output.append(text_before)
        output.append(m.group())
        pos = m.end()

    remaining = html[pos:]
    for cn, en in _FULL_WIDTH_MAP.items():
        remaining = remaining.replace(cn, en)
    output.append(remaining)

    return "".join(output)


def randomize_punctuation(html: str, rate: float = 0.05) -> str:
    """只影响 <p> 标签内文本,不影响代码块和标题"""
    parts = _CODE_BLOCK_RE.split(html)
    result = []

    for part in parts:
        if _CODE_BLOCK_RE.match(part):
            result.append(part)
            continue

        part = re.sub(
            r"(<p>)(.*?)(</p>)",
            lambda m: m.group(1) + _randomize_p_in_para(m.group(2), rate) + m.group(3),
            part,
            flags=re.DOTALL,
        )
        result.append(part)

    return "".join(result)


def _randomize_p_in_para(text: str, rate: float) -> str:
    # 偶尔把句末 ". " 替换成 "..."
    def _maybe_ellipsis(m):
        if random.random() < rate:
            return "..."
        return m.group(0)

    text = re.sub(r"\.\s", _maybe_ellipsis, text)

    # 偶尔句末加 ~ (口语化)
    if random.random() < rate * 0.5:
        ends = list(re.finditer(r"([.!?])\s", text))
        if ends:
            target = random.choice(ends)
            pos = target.end() - 1
            if random.random() < rate:
                text = text[:pos] + "~" + text[pos + 1:]

    # 偶尔重复标点(!! ???)
    def _maybe_double(m):
        if random.random() < rate * 0.3:
            return m.group(1) * 2
        return m.group(0)

    text = re.sub(r"([!?])\s", _maybe_double, text)

    return text


# 同音字/形近字替换词库
_TYPO_MAP = {
    "的": ["地", "得"],
    "在": ["再"],
    "做": ["作"],
    "已": ["己"],
    "到": ["道"],
    "里": ["理"],
    "和": ["何"],
    "个": ["各"],
    "人": ["入"],
    "来": ["莱"],
    "为": ["围"],
    "用": ["中"],
    "是": ["事"],
    "有": ["又"],
    "会": ["汇"],
    "能": ["态"],
    "出": ["初"],
    "等": ["邓"],
    "着": ["这"],
}

_TYPO_CHARS_RE = re.compile(
    "[" + "".join(re.escape(c) for c in _TYPO_MAP.keys()) + "]"
)

_CN_CHAR_RE = re.compile(r"[\u4e00-\u9fff]")


def inject_typos(html: str, density: float = 0.0008) -> str:
    """density 是每千字替换几个字(默认 0.8/千字)"""
    parts = _CODE_BLOCK_RE.split(html)
    result = []

    for part in parts:
        if _CODE_BLOCK_RE.match(part):
            result.append(part)
            continue
        result.append(_inject_typos_in_text(part, density))

    return "".join(result)


def _inject_typos_in_text(html: str, density: float) -> str:
    text_only = _HTML_TAG_RE.sub("", html)
    cn_count = len(_CN_CHAR_RE.findall(text_only))
    if cn_count == 0:
        return html

    num_typos = max(0, int(cn_count * density + random.random() * 0.5))
    if num_typos == 0:
        return html

    # 收集非标签区域的可替换位置
    positions = []
    in_tag = False
    for i, ch in enumerate(html):
        if ch == "<":
            in_tag = True
            continue
        if ch == ">":
            in_tag = False
            continue
        if in_tag:
            continue
        if ch in _TYPO_MAP:
            positions.append((i, ch))

    if not positions:
        return html

    num_typos = min(num_typos, len(positions))
    targets = random.sample(positions, num_typos)

    # 替换字长度都是 1,不会偏移
    html_chars = list(html)
    for idx, orig_char in targets:
        replacements = _TYPO_MAP[orig_char]
        new_char = random.choice(replacements)
        html_chars[idx] = new_char

    return "".join(html_chars)
