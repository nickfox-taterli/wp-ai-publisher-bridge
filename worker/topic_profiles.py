"""主题检测 - 从 WP 分类动态构建 profile"""

import json
import textwrap
from dataclasses import dataclass, field

from apb_client import fetch_categories


_FALLBACK_SYSTEM_PROMPT = textwrap.dedent("""\
你是一位资深的技术博客作者,擅长把复杂的技术概念用通俗易懂的方式讲解出来.

输出格式:
你的回复必须只包含一个 JSON 对象,不要输出思考过程或任何额外文本.
JSON 格式:{"title": "文章标题", "excerpt": "100字以内摘要", "content": "<h2>子标题</h2><p>段落内容</p>..."}
""")


@dataclass
class TopicProfile:
    name: str
    keywords: list[str] = field(default_factory=list)
    priority: int = 999
    system_prompt: str | None = None
    prompt_append: str | None = None
    generator: str = "generic"        # "generic" | "kernel"
    source_path: str | None = None
    source_map: dict | None = None

    @property
    def effective_system_prompt(self) -> str | None:
        return self.system_prompt


DEFAULT_PROFILE = TopicProfile(
    name="default",
    keywords=[],
    priority=999,
    system_prompt=_FALLBACK_SYSTEM_PROMPT,
    generator="generic",
)


def _build_profiles_from_categories(categories: list[dict]) -> list[TopicProfile]:
    profiles = []
    for cat in categories:
        kw_text = cat.get("topic_keywords", "").strip()
        if not kw_text:
            continue
        priority = int(cat.get("topic_priority") or 0)
        if priority <= 0:
            continue
        keywords = [k.strip().lower() for k in kw_text.split(",") if k.strip()]
        if not keywords:
            continue

        generator_raw = (cat.get("generator") or "generic").strip().lower()
        generator = generator_raw if generator_raw in ("generic", "kernel") else "generic"

        system_prompt = cat.get("system_prompt", "").strip() or None
        prompt_append = cat.get("prompt_append", "").strip() or None
        source_path = cat.get("source_path", "").strip() or None

        source_map = None
        sm_raw = cat.get("source_map", "").strip()
        if sm_raw:
            try:
                decoded = json.loads(sm_raw)
                if isinstance(decoded, dict):
                    source_map = decoded
            except json.JSONDecodeError:
                print(f"  ⚠ 分类 '{cat.get('name')}' 的 source_map JSON 解析失败,已忽略")

        profiles.append(TopicProfile(
            name=cat.get("name", ""),
            keywords=keywords,
            priority=priority,
            system_prompt=system_prompt,
            prompt_append=prompt_append,
            generator=generator,
            source_path=source_path,
            source_map=source_map,
        ))
    return profiles


def load_profiles(categories: list[dict] | None = None) -> list[TopicProfile]:
    if categories is None:
        try:
            categories = fetch_categories()
        except Exception:
            categories = []

    profiles = _build_profiles_from_categories(categories)
    profiles.sort(key=lambda p: p.priority)

    # kernel 没有 source_path 就没意义,降级成 generic
    for p in profiles:
        if p.generator == "kernel" and not p.source_path:
            print(f"  ⚠ 分类 '{p.name}' generator=kernel 但无 source_path,降级为 generic")
            p.generator = "generic"

    return profiles


def detect_topic_profile(
    topic: str,
    keywords: str = "",
    category_name: str = "",
    profiles: list[TopicProfile] | None = None,
) -> TopicProfile:
    if profiles is None:
        profiles = load_profiles()

    text = f"{topic} {keywords} {category_name}".lower()

    for profile in profiles:
        if not profile.keywords:
            continue
        if any(kw in text for kw in profile.keywords):
            return profile

    return DEFAULT_PROFILE
