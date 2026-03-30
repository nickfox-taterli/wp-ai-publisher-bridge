"""MiniMaxi 图像生成 - Prompt 生成, API 调用, 上传"""

import base64
import random
import re
import textwrap
import time

import requests

import config as _config
from ai_writer import extract_json_object
from apb_client import upload_image

_MINIMAX_API_URL = "https://api.minimaxi.com/v1/image_generation"

_FALLBACK_IMAGE_PROMPT_SYSTEM = textwrap.dedent("""\
You generate image prompts for a technical blog's AI image generation.
Given an article title and HTML content, create image prompts that produce
professional, visually striking images related to the topic.

Rules:
- Prompts must be in English, detailed and specific (50-200 words)
- Images should be technical illustrations, abstract visualizations, or thematic representations
- NO text, NO words, NO letters in the generated images
- Professional style suitable for a tech blog
- Each image should be visually distinct from the others

Output format: JSON array of objects, nothing else.
[{"prompt": "detailed English description...", "aspect_ratio": "16:9", "alt_text": "short Chinese description for alt tag"}]

Available aspect ratios: {aspect_ratios}
- Use wide ratios (16:9, 21:9) for the first/cover image
- Use other ratios for inline images to add variety
""")


def generate_image_prompts(
    title: str,
    html_content: str,
    count: int,
    aspect_ratios: str = "16:9,4:3,1:1",
    prompt_template: str = "",
) -> list[dict]:
    """根据文章内容生成 N 个图片 prompt.

    Returns:
        [{"prompt": "...", "aspect_ratio": "16:9", "alt_text": "..."}, ...]
    """
    sys_prompt = prompt_template or _FALLBACK_IMAGE_PROMPT_SYSTEM
    sys_prompt = sys_prompt.replace("{aspect_ratios}", aspect_ratios)

    # 提取纯文本摘要(去掉 HTML 标签),避免 token 浪费
    plain_text = re.sub(r"<[^>]+>", " ", html_content)
    plain_text = re.sub(r"\s+", " ", plain_text).strip()[:2000]

    user_prompt = (
        f"Article title: {title}\n\n"
        f"Article content excerpt:\n{plain_text}\n\n"
        f"Generate {count} image prompt(s) for this article."
    )

    resp = _config.ai_client.chat.completions.create(
        model=_config.AI_MODEL,
        messages=[
            {"role": "system", "content": sys_prompt},
            {"role": "user", "content": user_prompt},
        ],
        max_tokens=2048,
        extra_body={"reasoning_split": True},
    )

    raw = (resp.choices[0].message.content or "").strip()
    # 去掉 <think\> 块
    raw = re.sub(r"<think\s*>.*?</think\s*>", "", raw, flags=re.DOTALL).strip()

    data = extract_json_object(raw)
    if not data:
        # 尝试从原始文本中找 JSON 数组
        arr_match = re.search(r"\[.*\]", raw, re.DOTALL)
        if arr_match:
            import json
            try:
                data = json.loads(arr_match.group())
            except Exception:
                pass

    if isinstance(data, list) and data:
        prompts = []
        for item in data[:count]:
            if isinstance(item, dict) and item.get("prompt"):
                prompts.append({
                    "prompt": str(item["prompt"])[:1500],
                    "aspect_ratio": str(item.get("aspect_ratio", "16:9")),
                    "alt_text": str(item.get("alt_text", title))[:200],
                })
        return prompts

    return []


def call_minimax_api(
    prompt: str,
    model: str = "image-01",
    aspect_ratio: str = "16:9",
    api_key: str = "",
) -> bytes:
    """调用 MiniMaxi 图像生成 API,返回图片二进制数据.

    Uses response_format=url, then downloads the image.
    """
    headers = {
        "Authorization": f"Bearer {api_key}",
        "Content-Type": "application/json",
    }
    payload = {
        "model": model,
        "prompt": prompt,
        "aspect_ratio": aspect_ratio,
        "response_format": "url",
        "n": 1,
        "prompt_optimizer": True,
    }

    resp = requests.post(_MINIMAX_API_URL, headers=headers, json=payload, timeout=120)
    resp.raise_for_status()

    result = resp.json()
    base_resp = result.get("base_resp", {})
    status_code = base_resp.get("status_code", -1)

    if status_code != 0:
        raise RuntimeError(
            f"MiniMaxi API error: code={status_code}, msg={base_resp.get('status_msg', 'unknown')}"
        )

    image_urls = result.get("data", {}).get("image_urls", [])
    if not image_urls:
        raise RuntimeError("MiniMaxi API returned no image URLs")

    # 下载图片
    img_resp = requests.get(image_urls[0], timeout=60)
    img_resp.raise_for_status()
    return img_resp.content


def _call_minimax_with_retry(
    prompt: str,
    model: str,
    aspect_ratio: str,
    api_key: str,
    max_retries: int = 2,
) -> bytes | None:
    """带重试的 MiniMaxi API 调用."""
    for attempt in range(1, max_retries + 1):
        try:
            return call_minimax_api(prompt, model, aspect_ratio, api_key)
        except Exception as e:
            if attempt < max_retries:
                wait = 2 ** attempt
                print(f"    MiniMaxi API 失败 (尝试 {attempt}/{max_retries}): {e}, {wait}s 后重试")
                time.sleep(wait)
            else:
                print(f"    MiniMaxi API 连续 {max_retries} 次失败: {e}")
    return None


def generate_and_upload_images(
    title: str,
    html_content: str,
    cfg: dict,
) -> list[dict]:
    """完整的图像生成流水线: 生成 prompt -> 调用 MiniMaxi -> 上传到 WP.

    Args:
        title: 文章标题.
        html_content: 文章 HTML 内容.
        cfg: 配置字典,需包含 image_gen_* 字段.

    Returns:
        [{"url": "wp-media-url", "alt_text": "...", "attachment_id": int}, ...]
    """
    api_key = cfg.get("minimax_api_key", "")
    if not api_key:
        return []

    model = cfg.get("image_gen_model", "image-01")
    max_images = cfg.get("image_gen_max_per_article", 3)
    aspect_ratios = cfg.get("image_gen_aspect_ratios", "16:9,4:3,1:1")
    prompt_template = cfg.get("image_gen_prompt_template", "")

    # 随机决定插入几张图
    count = random.randint(1, max_images)
    print(f"  计划生成 {count} 张配图 (模型: {model})")

    # Step 1: 生成图片 prompt
    try:
        prompts = generate_image_prompts(
            title, html_content, count,
            aspect_ratios=aspect_ratios,
            prompt_template=prompt_template,
        )
    except Exception as e:
        print(f"  图片 Prompt 生成失败: {e}")
        return []

    if not prompts:
        print("  AI 未返回有效的图片 Prompt")
        return []

    print(f"  生成了 {len(prompts)} 个图片 Prompt")

    # Step 2: 逐个调用 MiniMaxi 并上传
    images = []
    for i, p in enumerate(prompts):
        prompt_text = p["prompt"]
        ratio = p.get("aspect_ratio", "16:9")
        alt = p.get("alt_text", title)

        print(f"  生成图片 {i + 1}/{len(prompts)} (比例: {ratio})...")

        # 调用 MiniMaxi
        image_bytes = _call_minimax_with_retry(prompt_text, model, ratio, api_key)
        if not image_bytes:
            continue

        # 上传到 WordPress
        try:
            b64_data = base64.b64encode(image_bytes).decode("ascii")
            filename = f"apb-{int(time.time())}-{i + 1}.png"
            result = upload_image(b64_data, filename=filename, alt_text=alt)
            att_id = result.get("data", {}).get("attachment_id", 0)
            url = result.get("data", {}).get("url", "")
            if url:
                images.append({
                    "url": url,
                    "alt_text": alt,
                    "attachment_id": att_id,
                })
                print(f"    上传成功: attachment_id={att_id}")
            else:
                print(f"    上传返回无效: {result}")
        except Exception as e:
            print(f"    图片上传失败: {e}")

    return images
