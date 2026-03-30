"""自主模式: 自动选题,生成文章,发布到 WordPress"""

import argparse
import html as html_lib
import re
import sys
import random
import textwrap
from datetime import datetime, timedelta
from pathlib import Path

import requests

from apb_client import (
    claim_job,
    complete_job,
    create_job,
    fail_job,
    fetch_categories,
    fetch_category_distribution,
    fetch_completed_jobs,
    fetch_config,
    fetch_pending_jobs,
    fetch_published_jobs,
)
from ai_writer import (
    classify_category,
    generate_article,
    generate_kernel_article,
    ai_generate_topic,
    generate_english_slug,
    detect_quality_defects,
    _FALLBACK_SYSTEM_PROMPT,
)
from kernel import (
    TEST_KERNEL_TOPICS,
    TEST_ARDUINO_TOPICS,
    GENERAL_TECH_TOPICS,
)
from topic_profiles import (
    detect_topic_profile,
    load_profiles,
)
import config as _config
from usage_tracker import collect as _collect_usage, reset as _reset_usage


def _generate_for_topic(
    topic: str,
    keywords: str,
    site_profile: str,
    category_name: str,
    site_tone: str,
    category_prompt: str | None = None,
    category_system_prompt: str | None = None,
    profiles: list | None = None,
    global_system_prompt: str = "",
    min_words: int = 1500,
    code_lines_per_segment: int = 40,
    code_max_segments: int = 3,
    prompt_template: str | None = None,
    kernel_prompt_template: str | None = None,
    max_tokens_article: int = 8192,
    max_total_segments: int = 5,
    *,
    persona_enabled: bool = True,
    half_width_punctuation: bool = False,
    typo_injection: bool = False,
    typo_density: float = 0.0008,
) -> tuple[str, str, str]:
    """根据主题检测选择生成策略.

    提示词优先级:
    1. category_system_prompt (分类完整系统提示词)
    2. global_system_prompt + category_prompt
    3. profile.system_prompt (关键词匹配的 profile)
    4. 极简兜底
    """
    profile = detect_topic_profile(topic, keywords, category_name, profiles=profiles)

    if category_system_prompt:
        system_prompt = category_system_prompt
    elif category_prompt:
        system_prompt = (global_system_prompt or _FALLBACK_SYSTEM_PROMPT) + "\n" + category_prompt
    elif profile.system_prompt:
        system_prompt = profile.system_prompt
        if profile.prompt_append:
            system_prompt += "\n" + profile.prompt_append
    else:
        system_prompt = global_system_prompt or _FALLBACK_SYSTEM_PROMPT

    humanizer_kwargs = dict(
        persona_enabled=persona_enabled,
        half_width_punctuation=half_width_punctuation,
        typo_injection=typo_injection,
        typo_density=typo_density,
    )

    if profile.generator == "kernel":
        print(f"  {profile.name} 主题 -> 源码分析模式 (source_path={profile.source_path})")
        return generate_kernel_article(
            topic, keywords, site_profile, site_tone,
            source_path=profile.source_path,
            system_prompt=system_prompt,
            source_map=profile.source_map,
            code_lines_per_segment=code_lines_per_segment,
            code_max_segments=code_max_segments,
            prompt_template=kernel_prompt_template,
            max_tokens=max_tokens_article,
            max_total_segments=max_total_segments,
            **humanizer_kwargs,
        )
    else:
        label = f"{profile.name} 主题" if profile.name != "default" else "通用模式"
        print(f"  {label} -> 使用{'专用' if profile.system_prompt else '默认'}提示词")
        return generate_article(
            topic, keywords, site_profile, category_name, site_tone,
            system_prompt=system_prompt,
            min_words=min_words,
            prompt_template=prompt_template,
            max_tokens=max_tokens_article,
            **humanizer_kwargs,
        )


def _extract_title_keywords(title: str) -> set[str]:
    t = re.sub(r'[的了吗呢了吧和与从到在是有]', ' ', title)
    t = re.sub(r'[::,,.??!!\-\-·「」\[\]\(\)（）\u201c\u201d\u2018\u2019]', ' ', t)
    return {m.group().lower() for m in re.finditer(r'[\u4e00-\u9fff]{2,}|[a-zA-Z0-9_]+', t)}


def title_similarity(t1: str, t2: str) -> float:
    t1, t2 = t1.lower().strip(), t2.lower().strip()
    if t1 == t2:
        return 1.0
    if t1 in t2 or t2 in t1:
        return 0.85
    kw1 = _extract_title_keywords(t1)
    kw2 = _extract_title_keywords(t2)
    if not kw1 or not kw2:
        return 0.0
    return len(kw1 & kw2) / len(kw1 | kw2)


def is_duplicate_title(title: str, existing: list[str], threshold: float = 0.55) -> bool:
    for t in existing:
        if title_similarity(title, t) >= threshold:
            return True
    return False


def fetch_all_existing_titles() -> list[str]:
    titles = []
    for fetch_fn in (fetch_published_jobs, fetch_completed_jobs):
        try:
            for job in fetch_fn(limit=100):
                t = job.get("generated_title")
                if t:
                    titles.append(t)
        except Exception:
            pass
    return titles


def _read_cfg_params(cfg: dict) -> dict:
    return {
        "max_quality_retries": cfg.get("max_quality_retries", 2),
        "max_slug_retries": cfg.get("max_slug_retries", 10),
        "title_threshold": cfg.get("title_similarity_threshold", 0.55),
        "min_words": cfg.get("min_article_words", 1500),
        "max_words": cfg.get("max_article_words", 2500),
        "code_lines": cfg.get("code_lines_per_segment", 40),
        "code_segs": cfg.get("code_max_segments", 3),
        "max_tokens_article": cfg.get("max_tokens_article", 8192),
        "max_tokens_topic": cfg.get("max_tokens_topic", 1024),
        "max_total_segments": cfg.get("kernel_max_total_segments", 5),
        "prompt_template": cfg.get("article_prompt_template") or None,
        "kernel_prompt_template": cfg.get("kernel_prompt_template") or None,
        "slug_prompt": cfg.get("slug_system_prompt") or None,
        "topic_system_prompt": cfg.get("topic_system_prompt") or None,
        "post_date_randomize": cfg.get("post_date_randomize", False),
        "post_date_max_offset_days": cfg.get("post_date_max_offset_days", 90),
        "persona_injection": cfg.get("persona_injection", True),
        "half_width_punctuation": cfg.get("half_width_punctuation", False),
        "typo_injection": cfg.get("typo_injection", False),
        "typo_density": cfg.get("typo_density", 0.8),
        "category_balance_threshold": cfg.get("category_balance_threshold", 0.6),

        "image_gen_enabled": cfg.get("image_gen_enabled", False),
        "minimax_api_key": cfg.get("minimax_api_key", ""),
        "image_gen_model": cfg.get("image_gen_model", "image-01"),
        "image_gen_max_per_article": cfg.get("image_gen_max_per_article", 3),
        "image_gen_prompt_template": cfg.get("image_gen_prompt_template", ""),
        "image_gen_aspect_ratios": cfg.get("image_gen_aspect_ratios", "16:9,4:3,1:1"),
    }


def _generate_and_insert_images(
    title: str,
    html_content: str,
    params: dict,
) -> str:
    """根据配置生成配图并插入到文章 HTML 中.

    图片插入策略:
    - 第一张图: 在第一个 <h2> 标签之后(作为封面图)
    - 后续图: 在后续 <h2> 标签之间均匀分布
    """
    if not params.get("image_gen_enabled"):
        return html_content
    if not params.get("minimax_api_key"):
        return html_content

    try:
        from image_gen import generate_and_upload_images
        images = generate_and_upload_images(title, html_content, params)
    except Exception as e:
        print(f"  图片生成失败(不影响文章): {e}")
        return html_content

    if not images:
        return html_content

    print(f"  成功生成 {len(images)} 张配图,正在插入...")

    cover_img = images[0]
    inline_imgs = images[1:] if len(images) > 1 else []

    # 构建图片 HTML
    def _img_tag(img: dict) -> str:
        att_id = img.get("attachment_id", "")
        cls = "wp-block-image size-large"
        wp_cls = f'wp-image-{att_id}' if att_id else ""
        class_attr = f' class="{wp_cls}"' if wp_cls else ""
        return (
            f'<figure class="{cls}">'
            f'<img src="{img["url"]}" alt="{img["alt_text"]}"{class_attr}/>'
            f'</figure>'
        )

    # 插入封面图: 在第一个 <h2> 之后
    if cover_img:
        tag = _img_tag(cover_img)
        h2_match = re.search(r"(<h2[^>]*>.*?</h2>)", html_content, re.DOTALL)
        if h2_match:
            pos = h2_match.end()
            html_content = html_content[:pos] + "\n" + tag + "\n" + html_content[pos:]
        else:
            html_content = tag + "\n" + html_content

    # 插入内联图片: 在后续 <h2> 标签之后均匀分布
    if inline_imgs:
        h2_positions = [m.end() for m in re.finditer(r"<h2[^>]*>.*?</h2>", html_content, re.DOTALL)]
        if len(h2_positions) > 1:
            # 从第二个 h2 开始,每隔一个插一张
            step = max(1, (len(h2_positions) - 1) // len(inline_imgs))
            inserted = 0
            for i, img in enumerate(inline_imgs):
                target_idx = 1 + i * step
                if target_idx < len(h2_positions):
                    tag = _img_tag(img)
                    # 需要重新计算位置(因为前面插入改变了偏移)
                    # 简化方案:从后往前插
                    pass

            # 从后往前插入避免偏移问题
            insertions = []
            for i, img in enumerate(inline_imgs):
                target_idx = 1 + i * step
                if target_idx < len(h2_positions):
                    insertions.append((h2_positions[target_idx], _img_tag(img)))

            for pos, tag in reversed(insertions):
                html_content = html_content[:pos] + "\n" + tag + "\n" + html_content[pos:]

    return html_content


def _random_past_date(max_offset_days: int = 90) -> str:
    """生成随机过去时间(MySQL DATETIME),集中在 8-23 点,近期概率更高"""
    from datetime import datetime, timedelta

    now = datetime.now()

    # 指数分布让近期概率更高
    days_back = min(
        int(random.expovariate(2.0 / max_offset_days) + 0.5),
        max_offset_days,
    )
    hours_back = random.randint(0, 23)
    minutes_back = random.randint(0, 59)

    past = now - timedelta(days=days_back, hours=hours_back, minutes=minutes_back)

    if past.hour < 8:
        past = past.replace(hour=random.randint(8, 11), minute=random.randint(0, 59))
    elif past.hour >= 23:
        past = past.replace(hour=random.randint(20, 22), minute=random.randint(0, 59))

    past = past.replace(second=random.randint(0, 59))

    if past > now:
        past = now - timedelta(minutes=random.randint(5, 60))

    return past.strftime("%Y-%m-%d %H:%M:%S")


def _resolve_category(
    topic: str,
    keywords: str,
    categories: list[dict],
    job_cat_id: int = 0,
    suggested_cat_name: str = "",
) -> tuple[int | None, str, str | None, str | None]:
    """统一分类解析 -> (cat_id, category_name, category_prompt, category_system_prompt)"""
    category_name = "默认分类"
    category_prompt = None
    category_system_prompt = None
    cat_id = None

    if job_cat_id:
        cat_id = job_cat_id
        cat = next(
            (c for c in categories if c["id"] == cat_id), None
        )
        if cat:
            category_name = cat["name"]
            category_prompt = cat.get("prompt_append") or None
            category_system_prompt = cat.get("system_prompt") or None
    elif categories:
        # Step 1: 尝试将 AI 建议的分类名匹配到分类 ID
        suggested_cat_id = None
        suggested_cat = None
        if suggested_cat_name:
            for c in categories:
                cn = c["name"].strip()
                sc = suggested_cat_name.strip()
                if cn == sc or cn in sc or sc in cn:
                    suggested_cat_id = c["id"]
                    suggested_cat = c
                    break

        if suggested_cat_name and suggested_cat_id:
            # Step 2: 快速检查主题文本是否包含建议分类的关键词
            # 如果主题本身提到了建议分类的核心概念,直接信任 AI 建议
            topic_text = f"{topic} {keywords}".lower()
            suggestion_is_relevant = False

            # 2a: 从分类名中提取核心词 (去掉 "开发"/"技术" 等通用后缀)
            cat_core = suggested_cat_name.strip()
            for suffix in ("开发", "技术", "教程", "实践", "研究", "入门", "进阶"):
                if cat_core.endswith(suffix):
                    cat_core = cat_core[: -len(suffix)]
            cat_core = cat_core.strip().lower()
            if cat_core and cat_core in topic_text:
                suggestion_is_relevant = True

            # 2b: 检查分类配置的 topic_keywords 是否命中
            if not suggestion_is_relevant and suggested_cat:
                kw_text = (suggested_cat.get("topic_keywords") or "").strip()
                if kw_text:
                    cat_kws = [k.strip().lower() for k in kw_text.split(",") if k.strip()]
                    if any(kw in topic_text for kw in cat_kws):
                        suggestion_is_relevant = True

            if suggestion_is_relevant:
                # 主题与建议分类相关,直接信任
                cat_id = suggested_cat_id
            else:
                # 主题与建议分类无明显关联,用 AI 交叉验证
                verified_id = classify_category(topic, keywords, categories)
                if verified_id:
                    if verified_id != suggested_cat_id:
                        verified_name = next(
                            (c["name"] for c in categories if c["id"] == verified_id), "?"
                        )
                        print(
                            f"  ⚠ 选题建议分类 '{suggested_cat_name}' 与实际内容不符,"
                            f"AI 验证修正为 '{verified_name}'"
                        )
                    cat_id = verified_id
                else:
                    cat_id = suggested_cat_id
        else:
            # 无建议或建议名无法匹配,直接分类
            cat_id = classify_category(topic, keywords, categories)

        if cat_id:
            cat = next((c for c in categories if c["id"] == cat_id), None)
            category_name = cat["name"] if cat else "默认分类"
            category_prompt = cat.get("prompt_append") or None if cat else None
            category_system_prompt = cat.get("system_prompt") or None if cat else None
            print(f"  AI 选择分类: {category_name} (ID: {cat_id})")

    if category_system_prompt:
        print(f"  使用分类完整系统提示词 ({len(category_system_prompt)} 字符)")
    elif category_prompt:
        print(f"  使用分类追加提示词 ({len(category_prompt)} 字符)")

    return cat_id, category_name, category_prompt, category_system_prompt


def _filter_balanced_categories(
    categories: list[dict],
    cat_stats: dict[int, int],
    threshold: float = 0.6,
) -> list[dict]:
    """过滤掉占比过高的分类,只返回欠represented的分类供 AI 选题.

    这样 AI 生成的话题自然匹配最终分类,避免「事后换分类导致内容不匹配」的问题.
    如果所有分类都超阈值或过滤后无可用分类,返回原始列表.

    Args:
        categories: 所有可用分类.
        cat_stats: {category_id: count} 已发布文章的分类统计.
        threshold: 单分类占比上限(默认 0.6).

    Returns:
        过滤后的分类列表.
    """
    if not cat_stats or len(categories) < 2:
        return categories
    if threshold <= 0 or threshold >= 1:
        return categories

    total = sum(cat_stats.values())
    if total == 0:
        return categories

    allowed = [c for c in categories if cat_stats.get(c["id"], 0) / total < threshold]

    if not allowed:
        # 所有分类都超阈值,选占比最低的几个
        sorted_cats = sorted(categories, key=lambda c: cat_stats.get(c["id"], 0))
        allowed = sorted_cats[:max(1, len(sorted_cats) // 2)]

    if len(allowed) < len(categories):
        excluded = [c["name"] for c in categories if c not in allowed]
        print(f"  ⚖ 分类均衡: 暂时排除过度集中的分类 {excluded},"
              f"要求 AI 从 {[c['name'] for c in allowed]} 中选题")

    return allowed


def process_one_job(job: dict, categories: list[dict], cfg: dict):
    job_id = job["id"]
    topic = job["topic"]
    keywords = job.get("keywords", "")
    site_profile = job.get("site_profile", "")

    _reset_usage()
    params = _read_cfg_params(cfg)

    print(f"\n{'=' * 60}")
    print(f"处理任务 #{job_id}: {topic}")
    print(f"{'=' * 60}")

    try:
        claimed = claim_job(job_id)
        print(f"  已抢占任务 #{job_id}")
    except requests.HTTPError as e:
        print(f"  抢占任务 #{job_id} 失败: {e.response.status_code}")
        return

    final_cat_id = int(job.get("category_id") or 0)
    cat_id, category_name, category_prompt, category_system_prompt = _resolve_category(
        topic, keywords, categories,
        job_cat_id=final_cat_id,
    )
    if cat_id:
        final_cat_id = cat_id

    # 生成文章(含质量重试)
    title = ""
    html_content = ""
    excerpt = ""
    for quality_attempt in range(1, params["max_quality_retries"] + 1):
        try:
            title, html_content, excerpt = _generate_for_topic(
                topic, keywords, site_profile, category_name,
                cfg.get("site_tone", ""), category_prompt, category_system_prompt,
                global_system_prompt=cfg.get("default_system_prompt", ""),
                min_words=params["min_words"],
                code_lines_per_segment=params["code_lines"],
                code_max_segments=params["code_segs"],
                prompt_template=params["prompt_template"],
                kernel_prompt_template=params["kernel_prompt_template"],
                max_tokens_article=params["max_tokens_article"],
                max_total_segments=params["max_total_segments"],
                persona_enabled=params["persona_injection"],
                half_width_punctuation=params["half_width_punctuation"],
                typo_injection=params["typo_injection"],
                typo_density=params["typo_density"],
            )
            print(f"  文章生成完成: {title}")
            print(f"  摘要: {excerpt[:80]}...")
            print(f"  内容长度: {len(html_content)} 字符")
        except Exception as e:
            print(f"  文章生成失败: {e}")
            try:
                fail_job(job_id, f"内容生成失败: {e}")
            except Exception:
                pass
            return

        quality_defects = detect_quality_defects(html_content)
        if not quality_defects:
            break
        print(f"  ⚠ 质量检查发现问题 (尝试 {quality_attempt}/{params['max_quality_retries']}):")
        for defect in quality_defects:
            print(f"    - {defect}")
        if quality_attempt < params["max_quality_retries"]:
            print(f"  重新生成文章...")

    # 图像生成(在质量检查之后)
    html_content = _generate_and_insert_images(title, html_content, params)

    slug = ""
    for slug_attempt in range(1, params["max_slug_retries"] + 1):
        slug = generate_english_slug(title, topic, slug_prompt=params["slug_prompt"])
        if slug:
            print(f"  Slug: {slug}")
            break
        print(f"  ⚠ Slug 生成失败或推理泄漏 (尝试 {slug_attempt}/{params['max_slug_retries']})")
    if not slug:
        print(f"  ⚠ Slug 连续 {params['max_slug_retries']} 次质量不达标,终止任务")
        try:
            fail_job(job_id, "Slug 质量不达标,无法生成有效的英文 slug")
        except Exception:
            pass
        return

    post_date = None
    if params["post_date_randomize"]:
        post_date = _random_past_date(params["post_date_max_offset_days"])
        print(f"  随机发布时间: {post_date}")

    try:
        usage = _collect_usage()
        if usage:
            total_tokens = sum(u["total_tokens"] for u in usage)
            print(f"  用量统计: {len(usage)} 次 API 调用, ~{total_tokens} tokens")
        result = complete_job(job_id, title, html_content, excerpt, post_slug=slug,
                              category_id=final_cat_id or None, post_date=post_date,
                              usage=usage or None)
        status = result.get("data", {}).get("status", "unknown")
        wp_id = result.get("data", {}).get("wp_post_id")
        print(f"  任务完成! 状态: {status}, WordPress 文章 ID: {wp_id}")
    except requests.HTTPError as e:
        print(f"  提交失败: {e.response.status_code}")
        try:
            fail_job(job_id, f"提交失败: {e.response.text}")
        except Exception:
            pass


def autonomous_run(count: int = 1):
    print("=" * 60)
    print("APB Worker - 自主模式")
    print(f"API: {_config.APB_BASE}")
    print(f"计划发布: {count} 篇")
    print("=" * 60)

    try:
        cfg = fetch_config()
        _config.init_ai_client(cfg)
        print(f"AI: {_config.AI_MODEL}  |  状态={cfg.get('default_post_status')}, 语气={cfg.get('site_tone') or '默认'}")
    except Exception as e:
        print(f"获取配置失败: {e}")
        cfg = {}
    if _config.ai_client is None:
        print("AI 客户端未初始化,终止运行. 请检查 /config 接口返回的 AI 配置.")
        return

    params = _read_cfg_params(cfg)

    try:
        categories = fetch_categories()
        print(f"分类: {[c['name'] for c in categories]}")
    except Exception as e:
        print(f"获取分类失败: {e}")
        categories = []

    profiles = load_profiles(categories)
    kernel_profiles = [p for p in profiles if p.source_path]
    if kernel_profiles:
        for kp in kernel_profiles:
            exists = Path(kp.source_path).exists()
            print(f"  源码路径 [{kp.name}]: {kp.source_path} ({'存在' if exists else '不存在!'})")
    else:
        print("  源码路径: 无(所有分类使用通用生成)")

    existing_titles = fetch_all_existing_titles()
    print(f"已有文章标题: {len(existing_titles)} 条")

    # 分类均衡: 获取已发布文章的分类分布
    cat_stats = {}
    try:
        cat_stats = fetch_category_distribution()
        if cat_stats:
            total_articles = sum(cat_stats.values())
            print(f"分类分布(共 {total_articles} 篇):")
            for c in categories:
                cnt = cat_stats.get(c["id"], 0)
                ratio = cnt / total_articles * 100 if total_articles else 0
                print(f"  {c['name']}: {cnt} 篇 ({ratio:.0f}%)")
    except Exception as e:
        print(f"  获取分类分布失败(不影响运行): {e}")

    published_count = 0
    attempts = 0
    retry_multiplier = cfg.get("retry_multiplier", 30) or 30
    max_attempts = count * retry_multiplier

    while published_count < count and attempts < max_attempts:
        attempts += 1
        print(f"\n--- 第 {attempts} 次尝试 (已发布 {published_count}/{count}) ---")

        _reset_usage()
        topic_data = None
        try:
            # 分类均衡: 在选题前过滤掉过度集中的分类
            allowed_categories = _filter_balanced_categories(
                categories, cat_stats,
                threshold=params["category_balance_threshold"],
            )
            topic_data = ai_generate_topic(
                categories=allowed_categories,
                existing_titles=existing_titles,
                site_tone=cfg.get("site_tone", ""),
                topic_system_prompt=params["topic_system_prompt"],
                max_tokens=params["max_tokens_topic"],
            )
        except Exception as e:
            print(f"  AI 选题异常: {e}")

        if not topic_data:
            print("  AI 选题失败,重试...")
            continue

        topic = topic_data["topic"]
        keywords = topic_data.get("keywords", "")

        try:
            result = create_job(topic, keywords)
            job_id = result.get("data", {}).get("id")
            if not job_id:
                print(f"  创建任务失败: 无返回 ID")
                continue
            print(f"  任务已创建: #{job_id}")
        except Exception as e:
            print(f"  创建任务失败: {e}")
            continue

        try:
            claim_job(job_id)
            print(f"  已抢占任务 #{job_id}")
        except requests.HTTPError as e:
            print(f"  抢占失败: {e.response.status_code}")
            continue

        suggested_cat_name = topic_data.get("category", "")
        cat_id, category_name, category_prompt, category_system_prompt = _resolve_category(
            topic, keywords, categories,
            suggested_cat_name=suggested_cat_name,
        )

        title = ""
        html_content = ""
        for quality_attempt in range(1, params["max_quality_retries"] + 1):
            try:
                title, html_content, excerpt = _generate_for_topic(
                    topic, keywords, "", category_name,
                    cfg.get("site_tone", ""), category_prompt, category_system_prompt,
                    profiles=profiles,
                    global_system_prompt=cfg.get("default_system_prompt", ""),
                    min_words=params["min_words"],
                    code_lines_per_segment=params["code_lines"],
                    code_max_segments=params["code_segs"],
                    prompt_template=params["prompt_template"],
                    kernel_prompt_template=params["kernel_prompt_template"],
                    max_tokens_article=params["max_tokens_article"],
                    max_total_segments=params["max_total_segments"],
                    persona_enabled=params["persona_injection"],
                    half_width_punctuation=params["half_width_punctuation"],
                    typo_injection=params["typo_injection"],
                    typo_density=params["typo_density"],
                )
                print(f"  生成完成: {title}")
                print(f"  摘要: {excerpt[:80]}...")
                print(f"  内容: {len(html_content)} 字符")
            except Exception as e:
                print(f"  生成失败: {e}")
                try:
                    fail_job(job_id, f"内容生成失败: {e}")
                except Exception:
                    pass
                break

            quality_defects = detect_quality_defects(html_content)
            if not quality_defects:
                break
            print(f"  ⚠ 质量检查发现问题 (尝试 {quality_attempt}/{params['max_quality_retries']}):")
            for defect in quality_defects:
                print(f"    - {defect}")
            if quality_attempt < params["max_quality_retries"]:
                print(f"  重新生成文章...")
        else:
            print(f"  质量检查连续 {params['max_quality_retries']} 次未通过,放弃该任务")
            try:
                fail_job(job_id, f"内容质量检查未通过: {'; '.join(quality_defects)}")
            except Exception:
                pass
            continue

        if not title:
            continue

        # 图像生成(在质量检查之后,标题重复检查之前)
        html_content = _generate_and_insert_images(title, html_content, params)

        if is_duplicate_title(title, existing_titles, threshold=params["title_threshold"]):
            print(f"  标题重复! '{title}' 与已有文章相似度过高")
            try:
                fail_job(job_id, f"标题重复,与已有文章相似: {title}")
            except Exception:
                pass
            continue

        slug = ""
        for slug_attempt in range(1, params["max_slug_retries"] + 1):
            slug = generate_english_slug(title, topic, slug_prompt=params["slug_prompt"])
            if slug:
                print(f"  Slug: {slug}")
                break
            print(f"  ⚠ Slug 生成失败或推理泄漏 (尝试 {slug_attempt}/{params['max_slug_retries']})")
        if not slug:
            print(f"  ⚠ Slug 连续 {params['max_slug_retries']} 次质量不达标,终止任务")
            try:
                fail_job(job_id, "Slug 质量不达标,无法生成有效的英文 slug")
            except Exception:
                pass
            continue

        post_date = None
        if params["post_date_randomize"]:
            post_date = _random_past_date(params["post_date_max_offset_days"])
            print(f"  随机发布时间: {post_date}")

        try:
            usage = _collect_usage()
            if usage:
                total_tokens = sum(u["total_tokens"] for u in usage)
                print(f"  用量统计: {len(usage)} 次 API 调用, ~{total_tokens} tokens")
            result = complete_job(job_id, title, html_content, excerpt, post_slug=slug,
                                  category_id=cat_id, post_date=post_date,
                                  usage=usage or None)
            status = result.get("data", {}).get("status", "unknown")
            wp_id = result.get("data", {}).get("wp_post_id")
            print(f"  发布成功! 状态: {status}, WP ID: {wp_id}")
            existing_titles.append(title)
            published_count += 1
            # 更新分类分布统计,下次循环使用
            if cat_id:
                cat_stats[cat_id] = cat_stats.get(cat_id, 0) + 1
        except requests.HTTPError as e:
            print(f"  提交失败: {e.response.status_code}")
            try:
                fail_job(job_id, f"提交失败: {e.response.text}")
            except Exception:
                pass

    print(f"\n{'=' * 60}")
    if published_count >= count:
        print(f"完成! 共发布 {published_count} 篇文章 (尝试 {attempts} 次)")
    else:
        print(f"结束: 发布 {published_count}/{count} 篇 (达到最大尝试次数 {max_attempts})")


def pull_and_process():
    print("APB Worker - 拉取模式")
    print(f"API: {_config.APB_BASE}")

    try:
        cfg = fetch_config()
        _config.init_ai_client(cfg)
        print(f"AI: {_config.AI_MODEL}")
    except Exception as e:
        print(f"获取配置失败: {e}")
        cfg = {}
    if _config.ai_client is None:
        print("AI 客户端未初始化,终止运行. 请检查 /config 接口返回的 AI 配置.")
        return

    try:
        categories = fetch_categories()
        print(f"可用分类: {[c['name'] for c in categories]}")
    except Exception as e:
        print(f"获取分类失败: {e}")
        categories = []

    try:
        jobs = fetch_pending_jobs()
    except Exception as e:
        print(f"获取任务列表失败: {e}")
        sys.exit(1)

    if not jobs:
        print("当前没有待处理的任务.")
        return

    print(f"找到 {len(jobs)} 个待处理任务")
    for job in jobs:
        process_one_job(job, categories, cfg)

    print("\n所有任务处理完毕")


def test_run(n: int):
    output_dir = Path(__file__).parent / "test_output"
    output_dir.mkdir(exist_ok=True)

    all_topics = (
        [("kernel", t) for t in TEST_KERNEL_TOPICS]
        + [("arduino", t) for t in TEST_ARDUINO_TOPICS]
        + [("general", t) for t in GENERAL_TECH_TOPICS]
    )
    random.shuffle(all_topics)
    all_topics = all_topics[:n]

    print(f"测试模式:生成 {n} 篇文章")
    print(f"输出目录: {output_dir}")

    for i, (pool_name, (topic, keywords)) in enumerate(all_topics, 1):
        print(f"\n{'=' * 60}")
        print(f"测试 [{i}/{n}] ({pool_name}): {topic}")
        print(f"{'=' * 60}")

        try:
            title, html_content, excerpt = _generate_for_topic(
                topic, keywords, "", pool_name, "",
            )

            print(f"  生成完成: {title}")
            print(f"  摘要: {excerpt[:80]}...")
            print(f"  内容: {len(html_content)} 字符")

            safe_name = re.sub(r"[^\w\u4e00-\u9fff]", "_", title)[:50]
            out_file = output_dir / f"{i:02d}_{safe_name}.html"
            out_file.write_text(
                f"<!DOCTYPE html>\n<html lang='zh-CN'>\n<head>"
                f"<meta charset='utf-8'>\n"
                f"<title>{html_lib.escape(title)}</title>\n"
                f"<style>body{{font-family:sans-serif;max-width:800px;margin:2em auto;padding:0 1em}}"
                f"pre{{background:#f5f5f5;padding:1em;overflow-x:auto;border-radius:4px}}"
                f"code{{font-family:monospace}}</style>\n"
                f"</head>\n<body>\n"
                f"<h1>{html_lib.escape(title)}</h1>\n"
                f"<p><em>{html_lib.escape(excerpt)}</em></p>\n"
                f"<hr>\n{html_content}\n</body>\n</html>",
                encoding="utf-8",
            )
            print(f"  保存到: {out_file.name}")
        except Exception as e:
            print(f"  生成失败: {e}")
            import traceback
            traceback.print_exc()

    print(f"\n{'=' * 60}")
    print(f"测试完成! 共 {n} 篇, 输出: {output_dir}")


def main():
    parser = argparse.ArgumentParser(
        description="APB Worker - 自动选题,生成博客文章,发布到 WordPress",
        formatter_class=argparse.RawDescriptionHelpFormatter,
        epilog=textwrap.dedent("""\
        示例:
          python main.py              # 自主模式:AI 选题 + 发布 1 篇
          python main.py -n 3         # 自主模式:连续发布 3 篇
          python main.py --pull       # 拉取模式:处理已有待处理任务
          python main.py --test 3     # 测试模式:本地生成 3 篇,不提交
        """),
    )
    parser.add_argument(
        "-n", "--count", type=int, default=1,
        help="自主模式发布的文章数 (默认: 1)",
    )
    parser.add_argument(
        "--pull", action="store_true",
        help="拉取模式:获取并处理已有的待处理任务",
    )
    parser.add_argument(
        "--test", type=int, metavar="N",
        help="测试模式:本地生成 N 篇文章(不提交到 WordPress)",
    )
    args = parser.parse_args()

    if args.test:
        test_run(args.test)
    elif args.pull:
        pull_and_process()
    else:
        autonomous_run(args.count)


if __name__ == "__main__":
    main()
