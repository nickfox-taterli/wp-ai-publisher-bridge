"""AI 内容生成 - 文章,JSON 解析,分类选择"""

import html as html_lib
import json
import random
import re
import textwrap
from pathlib import Path

import config as _config
from code_block import format_code_block
from humanizer import (
    convert_to_half_width_punctuation,
    inject_typos,
    randomize_punctuation,
)


_FALLBACK_SYSTEM_PROMPT = textwrap.dedent("""\
你是一位资深的技术博客作者.

输出格式:
你的回复必须只包含一个 JSON 对象,不要输出思考过程或任何额外文本.
JSON 格式:{"title": "文章标题", "excerpt": "100字以内摘要", "content": "<h2>子标题</h2><p>段落内容</p>..."}
""")

_FALLBACK_SLUG_PROMPT = textwrap.dedent("""\
You generate URL-friendly English slug phrases for blog post titles.
Rules:
- Output 3-6 lowercase English words separated by hyphens
- The slug should summarize the article topic, not translate the title literally
- Use standard technical English
- Keep it short, readable, and SEO-friendly
- Output ONLY the slug, nothing else
""")

_FALLBACK_TOPIC_PROMPT = textwrap.dedent("""\
你是一个技术博客的选题编辑.根据站点的分类列表,生成与分类高度相关的技术博客选题.

输出格式:
你的回复必须只包含一个 JSON 对象,不要输出任何其他内容.
JSON 格式:{"topic": "具体的文章选题", "keywords": "关键词1,关键词2,关键词3", "category": "分类名(必须来自分类列表)"}
""")


_FALLBACK_ARTICLE_PROMPT = textwrap.dedent("""\
请根据以下信息撰写一篇高质量的中文博客文章:

主题:{topic}
关键词:{keywords}
分类:{category}
{site_tone_line}{site_profile_line}
要求:
1. 标题吸引人,包含核心关键词
2. 文章结构清晰,使用 h2/h3 子标题
3. 内容专业,有深度,字数最低 {min_words} 字
4. 自然融入关键词,利于 SEO
5. 包含总结段落

请严格按以下 JSON 格式输出(不要 markdown 代码块,不要输出任何其他内容):
{{"title": "文章标题", "excerpt": "100字以内摘要", "content": "<h2>子标题</h2><p>段落内容</p>..."}}
""")

_FALLBACK_KERNEL_PROMPT = textwrap.dedent("""\
请分析以下 Linux 内核源码,写一篇口语化的技术博客文章.

主题:{topic}
关键词:{keywords}
{site_tone_line}{site_profile_line}
以下是提取的 {segment_count} 段源码:
{code_sections}
写作要求:
1. 标题吸引人且口语化,比如「走读 Linux 内核 XXX:看看这段代码在干嘛」 (请继续发散)
2. 开头用口语化的引入,比如「最近在看 XXX 的源码,发现了一些有意思的东西...」(请继续发散)
3. 逐段分析代码,每段之间有自然的过渡(「接下来看看...」「等等,这里有个细节...」) (请继续发散)
4. 代码段用 <pre><code class='language-c'> 标签展示
5. 每个代码段前后必须有分析文字,不能只贴代码
6. 分析时像人一样思考:先看代码在做什么,然后理解为什么这样做,最后说说自己的感受
7. 结尾要有总结和个人感悟,像是在回顾这段阅读经历
8. 总字数 2000-4000 字(含代码)

请严格按以下 JSON 格式输出(不要 markdown 代码块,不要其他内容):
{{"title": "文章标题", "excerpt": "100字以内摘要", "content": "<h2>...</h2>..."}}
""")


_PERSONA_FRAGMENTS = [
    "今天刚喝完咖啡,精神不错,写东西比较兴奋.",
    "熬夜写的这篇,思路可能有点跳跃.",
    "这篇文章改了好几遍,但感觉还是没写透.",
    "随手记的,不太严谨,但希望能帮到有需要的人.",
    "心情不错,写东西也比较放松.",
    "最近工作比较忙,这篇写得比较匆忙.",
    "研究了好久才搞明白,写下来免得忘了.",
    "今天状态一般,写得可能有点干巴巴的.",
    "刚跑完步回来,脑子比较清醒,条理应该还算清楚.",
    "这篇是午休时间写的,可能会有点草率.",
    "昨晚失眠想到的东西,赶紧记下来.",
    "整理笔记的时候顺便写出来的,可能会比较碎.",
    "面试被问到这个知识点,回来赶紧补了一下.",
    "看到有人在论坛问这个问题,干脆写详细一点.",
    "这篇是我第三遍改了,前两版都不满意.",
]

_STRUCTURE_HINTS = [
    "部分章节可以不用子标题,直接写段落.",
    "允许某些段落只有一两句话,短段落也很好.",
    "开头不要太正式,可以先用一个场景或经历引入.",
    "结尾不一定要有总结,可以突然结束或者留个问题.",
    "可以在文中偶尔使用列表,但不要每个章节都用.",
    "有些地方可以连续几个短段落,像是在自言自语.",
    "至少有一段要比较长,写成一大段也不拆分.",
    "可以有一个章节只有代码没有文字解释,然后下一段再回过头来说.",
    "中间可以穿插一些个人的吐槽或感想,不用每个段落都很正经.",
    "不用严格按照 h2 分章节,可以有大段连续的文字.",
]


def _maybe_persona() -> str:
    if random.random() < 0.7:
        return "\n\n[写作状态] " + random.choice(_PERSONA_FRAGMENTS)
    return ""


def _random_structure_hint() -> str:
    return "\n\n[结构要求] " + random.choice(_STRUCTURE_HINTS)


class _SafeDict(dict):
    """缺失 key 返回空串,给 format_map 用"""
    def __missing__(self, key):
        return ""


def _render_prompt_template(template: str, **kwargs) -> str:
    return template.format_map(_SafeDict(**kwargs))


def _extract_response_text(resp) -> str:
    msg = resp.choices[0].message
    raw = msg.content or ""

    # MiniMax M2.7 with reasoning_split: content 为空时从 reasoning_details 取
    if not raw.strip():
        if hasattr(msg, "model_extra") and msg.model_extra:
            details = msg.model_extra.get("reasoning_details", [])
            for d in (details or []):
                if isinstance(d, dict) and d.get("text"):
                    raw = d["text"]
                    break

    return raw.strip()


def extract_json_object(text: str) -> dict | None:
    # 去掉 <think\>...</think\> 推理块(MiniMax M2.7 等模型会输出)
    text = re.sub(r"<think\s*>.*?</think\s*>", "", text, flags=re.DOTALL)
    # 去掉 markdown 代码块
    text = re.sub(r"^```\w*\n?", "", text)
    text = re.sub(r"\n?```$", "", text)

    text_stripped = text.strip()
    try:
        return json.loads(text_stripped)
    except json.JSONDecodeError:
        pass

    # 暴力括号匹配
    start = text.find("{")
    if start == -1:
        return None

    depth = 0
    in_string = False
    escape = False
    for i in range(start, len(text)):
        ch = text[i]
        if escape:
            escape = False
            continue
        if ch == "\\":
            escape = True
            continue
        if ch == '"' and not escape:
            in_string = not in_string
            continue
        if in_string:
            continue
        if ch == "{":
            depth += 1
        elif ch == "}":
            depth -= 1
            if depth == 0:
                candidate = text[start : i + 1]
                try:
                    return json.loads(candidate)
                except json.JSONDecodeError:
                    continue
    return None


def _is_sentence_like(slug: str) -> bool:
    """检测 slug 是否像自然语言句子 - 推理泄漏的标志"""
    STOP_WORDS = {
        "the", "a", "an", "is", "are", "was", "were", "be", "been",
        "to", "of", "in", "for", "on", "with", "at", "by", "from",
        "that", "this", "it", "me", "my", "we", "you", "he", "she",
        "and", "or", "but", "not", "so", "if", "as",
        "user", "wants", "create", "make", "about", "how", "what",
        "should", "would", "could", "can", "will", "do", "does",
    }
    words = slug.split("-")
    stop_count = sum(1 for w in words if w in STOP_WORDS)
    if stop_count > 2 or (len(words) > 3 and stop_count / len(words) > 0.3):
        return True
    return False


def generate_english_slug(
    title: str,
    topic: str,
    slug_prompt: str | None = None,
) -> str:
    sys_prompt = slug_prompt or _FALLBACK_SLUG_PROMPT
    try:
        resp = _config.ai_client.chat.completions.create(
            model=_config.AI_MODEL,
            messages=[
                {"role": "system", "content": sys_prompt},
                {"role": "user", "content": f"Title: {title}\nTopic: {topic}"},
            ],
            max_tokens=1024,
            extra_body={"reasoning_split": True},
        )
        msg = resp.choices[0].message
        raw = (msg.content or "").strip().lower()

        if not raw:
            return ""

        raw = re.sub(r"<think\s*>.*?</think\s*>", "", raw, flags=re.DOTALL).strip()
        slug = re.sub(r"[^a-z0-9\-]", "", raw.replace(" ", "-"))
        slug = re.sub(r"-{2,}", "-", slug).strip("-")
        if len(slug) < 5:
            return ""
        if _is_sentence_like(slug):
            print(f"  ⚠ Slug 疑似推理泄漏,已丢弃: {slug[:60]}...")
            return ""
        return slug[:80]
    except Exception as e:
        print(f"  ⚠ Slug 生成失败: {e}")
        return ""


def sanitize_garbled_chars(html: str) -> str:
    html = re.sub(r'[\x00-\x08\x0b\x0c\x0e-\x1f]', '', html)
    html = re.sub(r'[\u200b\u200c\u200d\u2060\ufeff]', '', html)
    html = html.replace('\ufffd', '')
    html = re.sub(r'[\ue000-\uf8ff]', '', html)
    html = re.sub(r'[\ud800-\udfff]', '', html)

    # 常见 mojibake 修复
    mojibake_fixes = [
        ('\u00e2\u20ac\u0153', '\u201c'),
        ('\u00e2\u20ac\u009d', '\u201d'),
        ('\u00e2\u20ac\u201c', '\u2014'),
        ('\u00e2\u20ac\u2019', '\u2013'),
        ('\u00c3\u00bc', '\u00fc'),
        ('\u00c3\u00b6', '\u00f6'),
        ('\u00c3\u00a4', '\u00e4'),
        ('\u00c3\u00a9', '\u00e9'),
        ('\u00c3\u00a8', '\u00e8'),
        ('\u00c3\u00a1', '\u00e1'),
        ('\u00c3\u00b1', '\u00f1'),
        ('\u00c3\u00a7', '\u00e7'),
    ]
    for garbled, correct in mojibake_fixes:
        html = html.replace(garbled, correct)

    return html


def sanitize_html_content(html: str) -> str:
    html = sanitize_garbled_chars(html)

    while "</pre></pre>" in html:
        html = html.replace("</pre></pre>", "</pre>")
    while "</code></code>" in html:
        html = html.replace("</code></code>", "</code>")

    # 清理空代码块
    html = re.sub(
        r"<pre><code[^>]*>\s*</code></pre>",
        "",
        html,
        flags=re.DOTALL,
    )

    # 长代码没有换行时尝试加换行
    def _fix_code_breaks(m):
        open_tag = m.group(1)
        code = m.group(2)
        close_tag = m.group(3)
        if len(code) > 100 and "\n" not in code:
            code = re.sub(r";(?=\s*\S)", ";\n", code)
            code = re.sub(r"\{", "{\n  ", code)
            code = re.sub(r"\}(?!\s*[;,\)])", "\n}\n", code)
        return f"{open_tag}{code}{close_tag}"

    html = re.sub(
        r"(<pre><code[^>]*>)(.*?)(</code></pre>)",
        _fix_code_breaks,
        html,
        flags=re.DOTALL,
    )

    html = re.sub(r"<p>\s*</p>", "", html)
    html = re.sub(r"\n{3,}", "\n\n", html)

    return html


def convert_code_blocks_to_cbp(html_content: str) -> str:
    def replace_block(match):
        lang = match.group(1) or "c"
        code = match.group(2)
        import html as _html
        code = _html.unescape(code)
        return format_code_block(code, lang)

    pattern = r'<pre><code\s+class=[\'"]language-(\w+)[\'"]>\s*(.*?)\s*</code></pre>'
    return re.sub(pattern, replace_block, html_content, flags=re.DOTALL)


_STRONG_CODE_LINE_RE = re.compile(
    r'^(?:'
    r'#\s*(?:include|define|ifdef|ifndef|endif|pragma|if|elif)\b'
    r'|(?:void|int|float|double|byte|char|long|unsigned|bool|auto|const|static|struct|class|enum)\s+\w+'
    r'|(?:return|break|continue)\b'
    r'|(?:if|else|for|while|do|switch|case)\s*[\({]'
    r'|(?:Wire|Serial|u8g2|SPI|EEPROM|WiFi|BLE|MPU|DHT|accelgyro|Wire)\b'
    r'|(?:delay|pinMode|digitalWrite|digitalRead|analogWrite|analogRead|millis|micros)\s*\('
    r'|(?:U8G2_|SSD1306|MPU6050|I2Cdev)\b'
    r'|(?:int16_t|uint8_t|uint16_t|uint32_t|size_t|boolean)\b'
    r'|\}'
    r')'
)

_WEAK_CODE_LINE_RE = re.compile(
    r'(?:'
    r';\s*$'
    r'|\{\s*$'
    r'|\}\s*$'
    r'|\w+\.\w+\('
    r'|//.*$'
    r'|/\*.*\*/'
    r'|&lt;\w+\.h&gt;'
    r')'
)


def _line_looks_like_code(line: str) -> bool:
    stripped = line.strip()
    if not stripped:
        return False
    if stripped.startswith('<'):
        return False
    if _STRONG_CODE_LINE_RE.match(stripped):
        return True
    if _WEAK_CODE_LINE_RE.search(stripped):
        return True
    return False


def fix_code_in_paragraphs(html_content: str, default_lang: str = "cpp") -> str:
    """检测 <p> 里误放的多行代码,转成 [sourcecode]"""
    def _convert_if_code(match):
        content = match.group(1)
        raw_lines = re.split(r'<br\s*/?>', content)
        lines = [html_lib.unescape(re.sub(r'</?[^>]+>', '', l)) for l in raw_lines]

        non_empty = [l for l in lines if l.strip()]
        if not non_empty:
            return match.group(0)

        code_count = sum(1 for l in non_empty if _line_looks_like_code(l))
        if len(non_empty) >= 2 and code_count / len(non_empty) >= 0.6:
            code_text = '\n'.join(lines)
            return '\n' + format_code_block(code_text.strip(), default_lang) + '\n'

        if len(non_empty) == 1:
            line = non_empty[0]
            if (_STRONG_CODE_LINE_RE.match(line) or _WEAK_CODE_LINE_RE.search(line)):
                is_code_line = (
                    re.search(r'[;{}]\s*$', line)
                    or (re.search(r';\s*//', line) and re.search(r'[;{}]', line))
                )
                cn_chars = len(re.findall(r'[\u4e00-\u9fff]', line))
                if is_code_line and cn_chars < len(line) * 0.4:
                    return '\n' + format_code_block(line.strip(), default_lang) + '\n'

        return match.group(0)

    return re.sub(r'<p>(.*?)</p>', _convert_if_code, html_content, flags=re.DOTALL)


def detect_code_in_paragraphs(html_content: str) -> list[str]:
    """质控:检测 <p> 里疑似未包裹的代码"""
    issues: list[str] = []
    has_shortcode = bool(re.search(r'\[sourcecode[^\]]*\]', html_content))

    paragraphs = re.findall(r'<p>(.*?)</p>', html_content, flags=re.DOTALL)
    code_para_count = 0

    for i, content in enumerate(paragraphs, 1):
        raw_lines = re.split(r'<br\s*/?>', content)
        lines = [html_lib.unescape(re.sub(r'</?[^>]+>', '', l)) for l in raw_lines]
        non_empty = [l for l in lines if l.strip()]
        if not non_empty:
            continue

        code_count = sum(1 for l in non_empty if _line_looks_like_code(l))
        is_code_para = False

        if len(non_empty) >= 2 and code_count / len(non_empty) >= 0.6:
            is_code_para = True
        elif len(non_empty) == 1:
            line = non_empty[0]
            if (_STRONG_CODE_LINE_RE.match(line) or _WEAK_CODE_LINE_RE.search(line)):
                if re.search(r'[;{}]\s*$', line) and not re.search(r'[\u4e00-\u9fff]{5,}', line):
                    is_code_para = True

        if is_code_para:
            code_para_count += 1
            preview = content[:60].replace('\n', ' ')
            if code_para_count <= 3:
                issues.append(
                    f"第 {i} 个 <p> 标签疑似未包裹的代码: {preview}..."
                )

    if code_para_count > 0:
        total_msg = f"共发现 {code_para_count} 个 <p> 标签疑似包含未包裹的代码块"
        if not has_shortcode:
            total_msg += "(文章没有任何 [sourcecode] 代码块,代码渲染将完全失败)"
        issues.insert(0, total_msg)

    return issues


def validate_code_quality(html_content: str) -> list[str]:
    issues: list[str] = []

    blocks = re.findall(
        r'\[sourcecode[^\]]*\](.*?)\[/sourcecode\]',
        html_content,
        flags=re.DOTALL,
    )

    for i, block in enumerate(blocks, 1):
        code = html_lib.unescape(block)
        lines = code.split('\n')

        for ln, line in enumerate(lines, 1):
            stripped = line.strip()
            if stripped.startswith('#include'):
                arg = stripped[len('#include'):].strip()
                if not arg or arg == '':
                    issues.append(f"代码块 {i} 第 {ln} 行: #include 缺少头文件名")
                elif arg.startswith('<') and not arg.endswith('>'):
                    issues.append(f"代码块 {i} 第 {ln} 行: #include 尖括号不匹配: {arg}")
                elif arg.startswith('"') and not arg.endswith('"'):
                    issues.append(f"代码块 {i} 第 {ln} 行: #include 引号不匹配: {arg}")

        for ln, line in enumerate(lines, 1):
            stripped = line.strip()
            if stripped == '#define' or stripped == '#define ':
                issues.append(f"代码块 {i} 第 {ln} 行: #define 缺少宏定义内容")

        if not code.strip():
            issues.append(f"代码块 {i}: 内容为空")

        non_empty_lines = [l for l in lines if l.strip()]
        if 0 < len(non_empty_lines) < 3:
            issues.append(f"代码块 {i}: 仅 {len(non_empty_lines)} 行,可能内容不完整")

    return issues


def detect_quality_defects(html_content: str) -> list[str]:
    issues: list[str] = []

    if re.search(r"<p>\s*nn\s*</p>", html_content):
        issues.append("内容包含 <p>nn</p>,疑似 AI 生成质量异常")

    code_para_issues = detect_code_in_paragraphs(html_content)
    issues.extend(code_para_issues)

    return issues


def classify_category(topic: str, keywords: str, categories: list[dict]) -> int | None:
    if not categories:
        return None

    valid_ids = {int(c["id"]) for c in categories}
    id_to_name = {int(c["id"]): str(c["name"]).strip() for c in categories}

    def _is_valid(cat_id: int) -> bool:
        return cat_id in valid_ids

    def _extract_category_id(text: str) -> int | None:
        txt = (text or "").strip()
        if not txt:
            return None

        # 1) 最可靠: 整段就是数字
        if re.fullmatch(r"\d+", txt):
            cat_id = int(txt)
            if _is_valid(cat_id):
                return cat_id

        # 2) 常见格式: "ID 12" / "分类ID: 12" / "选择 12"
        id_like = re.findall(r"(?:\bID\b|分类ID|类别ID|分类|类别)?\s*[:：]?\s*(\d{1,8})", txt, flags=re.IGNORECASE)
        for n in reversed(id_like):
            cat_id = int(n)
            if _is_valid(cat_id):
                return cat_id

        # 3) 若回复了分类名,直接映射回 ID
        txt_lower = txt.lower()
        for cid, name in id_to_name.items():
            name_lower = name.lower()
            if name_lower and (name_lower == txt_lower or name_lower in txt_lower):
                return cid

        # 4) 最后兜底: 任意数字里找一个有效 ID (从后往前更接近“最终答案”)
        all_nums = re.findall(r"\d+", txt)
        for n in reversed(all_nums):
            cat_id = int(n)
            if _is_valid(cat_id):
                return cat_id

        return None

    cat_list = "\n".join(f"  - ID {c['id']}: {c['name']}" for c in categories)
    prompt = (
        f"以下是文章的主题和关键词:\n"
        f"主题:{topic}\n"
        f"关键词:{keywords or '无'}\n\n"
        f"请从以下分类中选择最合适的一个(只回复分类ID数字):\n{cat_list}\n\n"
        f"重要规则:\n"
        f"1. 根据主题的核心技术领域来选择分类,不要因为关键词中包含某个分类名就自动选它.\n"
        f"2. 例如,一篇关于 Python 脚本操作的文章不应归入「Linux 内核开发」,即使关键词包含 Linux.\n"
        f"3. 如果主题讨论的是通用编程/软件开发,就不应归入硬件/嵌入式相关分类(如 Arduino).\n"
        f"4. 同理,与某个硬件平台无关的纯软件主题不应归入该硬件平台的分类.\n"
        f"只回复分类ID,不要任何其他内容."
    )

    resp = _config.ai_client.chat.completions.create(
        model=_config.AI_MODEL,
        messages=[{"role": "user", "content": prompt}],
        max_tokens=256,
        extra_body={"reasoning_split": True},
    )
    text = _extract_response_text(resp)
    cat_id = _extract_category_id(text)
    if cat_id is not None:
        return cat_id

    print(f"  ⚠ 分类识别失败,模型回复前120字: {(text or '')[:120]}...")
    return None


def generate_article(
    topic: str,
    keywords: str,
    site_profile: str,
    category_name: str,
    site_tone: str,
    system_prompt: str | None = None,
    min_words: int = 1500,
    prompt_template: str | None = None,
    max_tokens: int = 8192,
    *,
    persona_enabled: bool = True,
    half_width_punctuation: bool = False,
    typo_injection: bool = False,
    typo_density: float = 0.0008,
) -> tuple[str, str, str]:
    sys_prompt = system_prompt or _FALLBACK_SYSTEM_PROMPT

    if persona_enabled:
        sys_prompt += _maybe_persona()

    template_vars = {
        "topic": topic,
        "keywords": keywords or "无特定关键词",
        "category": category_name,
        "site_tone": site_tone,
        "site_profile": site_profile,
        "min_words": str(min_words),
        "site_tone_line": f"写作风格/语气:{site_tone}\n" if site_tone else "",
        "site_profile_line": f"额外要求:{site_profile}\n" if site_profile else "",
    }

    if prompt_template:
        prompt = _render_prompt_template(prompt_template, **template_vars)
    else:
        prompt = _render_prompt_template(_FALLBACK_ARTICLE_PROMPT, **template_vars)

    prompt += _random_structure_hint()

    resp = _config.ai_client.chat.completions.create(
        model=_config.AI_MODEL,
        messages=[
            {"role": "system", "content": sys_prompt},
            {"role": "user", "content": prompt},
        ],
        max_tokens=max_tokens,
        extra_body={"reasoning_split": True},
    )

    raw = _extract_response_text(resp)
    data = extract_json_object(raw)

    if data and isinstance(data, dict) and data.get("content"):
        title = data.get("title", topic)
        excerpt = data.get("excerpt", "")
        content = sanitize_html_content(data.get("content", ""))

        # 拟人化后处理要在代码块转换之前
        if half_width_punctuation:
            content = convert_to_half_width_punctuation(content)
        if typo_injection:
            content = inject_typos(content, density=typo_density)
        content = randomize_punctuation(content)

        content = convert_code_blocks_to_cbp(content)
        content = fix_code_in_paragraphs(content)

        quality_issues = validate_code_quality(content)
        if quality_issues:
            print(f"  ⚠ 代码质量校验发现 {len(quality_issues)} 个问题:")
            for issue in quality_issues:
                print(f"    - {issue}")

        return title, content, excerpt

    # JSON 解析失败,兜底:尝试从原始输出里提取 HTML
    print("  ⚠ JSON 解析失败,尝试兜底处理")
    title = topic
    excerpt = ""
    html_match = re.search(r"(<h[1-6]|<p>)", raw)
    if html_match:
        content = raw[html_match.start():]
    else:
        paragraphs = raw.split("\n\n")
        html_parts = []
        for p in paragraphs:
            p = p.strip()
            if not p:
                continue
            if p.startswith("# "):
                html_parts.append(f"<h2>{p[2:]}</h2>")
            elif p.startswith("## "):
                html_parts.append(f"<h2>{p[3:]}</h2>")
            elif p.startswith("### "):
                html_parts.append(f"<h3>{p[4:]}</h3>")
            else:
                html_parts.append(f"<p>{html_lib.escape(p)}</p>")
        content = "\n".join(html_parts)
    return title, content, excerpt


def generate_kernel_article(
    topic: str,
    keywords: str,
    site_profile: str,
    site_tone: str,
    source_path: str,
    system_prompt: str | None = None,
    source_map: dict | None = None,
    code_lines_per_segment: int = 40,
    code_max_segments: int = 3,
    prompt_template: str | None = None,
    max_tokens: int = 8192,
    max_total_segments: int = 5,
    *,
    persona_enabled: bool = True,
    half_width_punctuation: bool = False,
    typo_injection: bool = False,
    typo_density: float = 0.0008,
) -> tuple[str, str, str]:
    from kernel import discover_kernel_sources, ai_select_source_files, read_source_segments

    if not source_path:
        raise ValueError(f"内核文章生成必须提供 source_path. Topic: {topic}")
    if not Path(source_path).exists():
        raise FileNotFoundError(f"source_path 路径不存在: {source_path}")

    print("  🔍 发现内核源文件...")
    candidates = discover_kernel_sources(
        topic, keywords, source_path,
        subsystem_map=source_map,
    )
    if not candidates:
        raise RuntimeError(f"未找到与主题相关的源文件. source_path={source_path}, topic={topic}")

    print(f"  找到 {len(candidates)} 个候选文件")

    print("  🤖 AI 选择分析文件...")
    selected_files = ai_select_source_files(topic, candidates, count=3)
    print(f"  选中: {[Path(f).name for f in selected_files]}")

    print("  📖 读取源码段...")
    all_segments = []
    for f in selected_files:
        segs = read_source_segments(
            f, source_path,
            lines_per_segment=code_lines_per_segment,
            max_segments=code_max_segments,
        )
        all_segments.extend(segs)
        if segs:
            print(f"    {Path(f).name}: 提取 {len(segs)} 段 (行 {segs[0]['start']}-{segs[-1]['end']})")

    if not all_segments:
        raise RuntimeError(f"无法从源文件提取代码段. source_path={source_path}, topic={topic}")

    all_segments = all_segments[:max_total_segments]

    code_sections = ""
    for i, seg in enumerate(all_segments, 1):
        code_sections += (
            f'\n===== 代码段 {i}: {seg["rel_path"]} (第 {seg["start"]}-{seg["end"]} 行) =====\n'
            f'```c\n{seg["code"]}\n```\n'
        )

    template_vars = {
        "topic": topic,
        "keywords": keywords or "无",
        "site_tone": site_tone,
        "site_profile": site_profile,
        "site_tone_line": f"额外语气要求:{site_tone}\n" if site_tone else "",
        "site_profile_line": f"额外要求:{site_profile}\n" if site_profile else "",
        "segment_count": str(len(all_segments)),
        "code_sections": code_sections,
    }

    if prompt_template:
        prompt = _render_prompt_template(prompt_template, **template_vars)
    else:
        prompt = _render_prompt_template(_FALLBACK_KERNEL_PROMPT, **template_vars)

    prompt += _random_structure_hint()

    sys_prompt = system_prompt or _FALLBACK_SYSTEM_PROMPT
    if persona_enabled:
        sys_prompt += _maybe_persona()

    resp = _config.ai_client.chat.completions.create(
        model=_config.AI_MODEL,
        messages=[
            {"role": "system", "content": sys_prompt},
            {"role": "user", "content": prompt},
        ],
        max_tokens=max_tokens,
        extra_body={"reasoning_split": True},
    )

    raw = _extract_response_text(resp)
    data = extract_json_object(raw)

    if data and isinstance(data, dict) and data.get("content"):
        title = data.get("title", topic)
        excerpt = data.get("excerpt", "")
        content = sanitize_html_content(data.get("content", ""))

        if half_width_punctuation:
            content = convert_to_half_width_punctuation(content)
        if typo_injection:
            content = inject_typos(content, density=typo_density)
        content = randomize_punctuation(content)

        content = convert_code_blocks_to_cbp(content)
        content = fix_code_in_paragraphs(content)

        quality_issues = validate_code_quality(content)
        if quality_issues:
            print(f"  ⚠ 代码质量校验发现 {len(quality_issues)} 个问题:")
            for issue in quality_issues:
                print(f"    - {issue}")

        return title, content, excerpt

    print("  ⚠ JSON 解析失败,尝试兜底处理")
    html_match = re.search(r"(<h[1-6]|<p>)", raw)
    if html_match:
        content = raw[html_match.start():]
    else:
        content = f"<p>{html_lib.escape(raw)}</p>"
    return topic, content, ""


def _verify_topic_category(topic: str, category: str, categories: list[dict]) -> bool:
    cat_names = [c["name"] for c in categories]
    suggested = category.strip()
    if suggested in cat_names:
        return True
    for name in cat_names:
        if suggested in name or name in suggested:
            return True
    return False


def ai_generate_topic(
    categories: list[dict],
    existing_titles: list[str],
    site_tone: str = "",
    topic_prompt: str = "",
    topic_system_prompt: str | None = None,
    max_tokens: int = 1024,
) -> dict | None:
    sys_prompt = topic_system_prompt or _FALLBACK_TOPIC_PROMPT
    cat_list = "\n".join(f"  - {c['name']}" for c in categories)

    titles_preview = ""
    if existing_titles:
        recent = existing_titles[-20:]
        titles_preview = "已发布的文章标题(请勿重复或过于相似):\n"
        titles_preview += "\n".join(f"  - {t}" for t in recent)
        titles_preview += "\n\n"

    tone_line = f"站点风格:{site_tone}\n" if site_tone else ""
    custom_line = f"额外要求:{topic_prompt}\n" if topic_prompt else ""

    prompt = (
        f"请为技术博客生成一个选题.\n\n"
        f"可选分类:\n{cat_list}\n\n"
        f"{tone_line}{custom_line}{titles_preview}"
        f"请生成一个全新的,有深度的技术博客选题.\n"
        f"严格按 JSON 格式输出,不要输出其他内容."
    )

    try:
        resp = _config.ai_client.chat.completions.create(
            model=_config.AI_MODEL,
            messages=[
                {"role": "system", "content": sys_prompt},
                {"role": "user", "content": prompt},
            ],
            max_tokens=max_tokens,
            extra_body={"reasoning_split": True},
        )
        raw = _extract_response_text(resp)
        data = extract_json_object(raw)
        if not data:
            print(f"  ⚠ AI 选题 JSON 解析失败, 原始回复前120字: {raw[:120]}...")
        if data and data.get("topic"):
            print(f"  🤖 AI 选题: {data['topic']}")
            print(f"     关键词: {data.get('keywords', '')}")
            print(f"     建议分类: {data.get('category', '')}")

            suggested_cat = data.get("category", "")
            if suggested_cat and categories:
                if not _verify_topic_category(data["topic"], suggested_cat, categories):
                    print(f"  ⚠ 选题分类 '{suggested_cat}' 不在可用分类中,丢弃重新生成")
                    return None
                print(f"  ✓ 分类验证通过")

            return data
    except Exception as e:
        print(f"  ⚠ AI 选题失败: {e}")
    return None
