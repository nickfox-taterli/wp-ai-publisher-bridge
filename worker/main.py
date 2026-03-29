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
    t = re.sub(r'[::,,.??!!--\-·「」[]()()\[\]""'']', ' ', t)
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
    }


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
        if suggested_cat_name:
            for c in categories:
                cn = c["name"].strip()
                sc = suggested_cat_name.strip()
                if cn == sc or cn in sc or sc in cn:
                    cat_id = c["id"]
                    break
        if not cat_id:
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


def process_one_job(job: dict, categories: list[dict], cfg: dict):
    job_id = job["id"]
    topic = job["topic"]
    keywords = job.get("keywords", "")
    site_profile = job.get("site_profile", "")

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
        result = complete_job(job_id, title, html_content, excerpt, post_slug=slug,
                              category_id=final_cat_id or None, post_date=post_date)
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

    published_count = 0
    attempts = 0
    retry_multiplier = cfg.get("retry_multiplier", 30) or 30
    max_attempts = count * retry_multiplier

    while published_count < count and attempts < max_attempts:
        attempts += 1
        print(f"\n--- 第 {attempts} 次尝试 (已发布 {published_count}/{count}) ---")

        topic_data = None
        try:
            topic_data = ai_generate_topic(
                categories=categories,
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
            result = complete_job(job_id, title, html_content, excerpt, post_slug=slug,
                                  category_id=cat_id, post_date=post_date)
            status = result.get("data", {}).get("status", "unknown")
            wp_id = result.get("data", {}).get("wp_post_id")
            print(f"  发布成功! 状态: {status}, WP ID: {wp_id}")
            existing_titles.append(title)
            published_count += 1
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
