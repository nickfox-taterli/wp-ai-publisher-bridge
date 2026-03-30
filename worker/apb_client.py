"""WordPress REST API 客户端"""

import json

from config import APB_BASE, APB_SESSION


def _json_with_bom_fallback(response):
    """优先常规 JSON 解析,失败时兼容 UTF-8 BOM."""
    try:
        return response.json()
    except ValueError:
        raw = response.content.decode("utf-8", errors="replace")
        raw = raw.lstrip("\ufeff\r\n\t ")
        return json.loads(raw)


def apb_get(path: str, params: dict | None = None):
    r = APB_SESSION.get(f"{APB_BASE}{path}", params=params, timeout=30)
    r.raise_for_status()
    return _json_with_bom_fallback(r)


def apb_post(path: str, body: dict | None = None):
    r = APB_SESSION.post(
        f"{APB_BASE}{path}",
        json=body,
        timeout=30,
    )
    r.raise_for_status()
    return _json_with_bom_fallback(r)


def fetch_categories() -> list[dict]:
    resp = apb_get("/categories")
    return resp.get("data", [])


def fetch_config() -> dict:
    resp = apb_get("/config")
    return resp.get("data", {})


def fetch_pending_jobs(limit: int = 5) -> list[dict]:
    resp = apb_get("/jobs", {"status": "pending", "limit": limit})
    return resp.get("data", [])


def claim_job(job_id: str) -> dict:
    return apb_post(f"/jobs/{job_id}/claim")


def complete_job(job_id: str, title: str, html_content: str, excerpt: str = "",
                 post_slug: str = "", category_id: int | None = None,
                 post_date: str | None = None, usage: list[dict] | None = None):
    body = {
        "generated_title": title,
        "generated_html": html_content,
        "generated_excerpt": excerpt,
        "generated_json": "",
    }
    if post_slug:
        body["post_slug"] = post_slug
    if category_id:
        body["category_id"] = category_id
    if post_date:
        body["post_date"] = post_date
    if usage:
        body["usage"] = usage
    return apb_post(f"/jobs/{job_id}/complete", body)


def fail_job(job_id: str, error: str):
    return apb_post(f"/jobs/{job_id}/fail", {"error_message": error})


def create_job(topic: str, keywords: str = "", site_profile: str = "",
               category_id: int | None = None) -> dict:
    body = {"topic": topic, "keywords": keywords, "site_profile": site_profile}
    if category_id:
        body["category_id"] = category_id
    return apb_post("/jobs", body)


def fetch_published_jobs(limit: int = 100) -> list[dict]:
    resp = apb_get("/jobs", {"status": "published", "limit": limit})
    return resp.get("data", [])


def fetch_completed_jobs(limit: int = 100) -> list[dict]:
    resp = apb_get("/jobs", {"status": "completed", "limit": limit})
    return resp.get("data", [])


def fetch_category_distribution() -> dict[int, int]:
    """从已发布 + 已完成的任务中统计各分类的文章数量.

    Returns:
        {category_id: count} 字典.
    """
    stats: dict[int, int] = {}
    for fetch_fn in (fetch_published_jobs, fetch_completed_jobs):
        try:
            for job in fetch_fn(limit=100):
                cat_id = int(job.get("category_id") or 0)
                if cat_id:
                    stats[cat_id] = stats.get(cat_id, 0) + 1
        except Exception:
            pass
    return stats


def upload_image(image_data: str, filename: str = "apb-image.png",
                 alt_text: str = "", post_id: int = 0) -> dict:
    """上传 base64 编码的图片到 WordPress 媒体库.

    Args:
        image_data: base64 编码的图片数据.
        filename: 保存到媒体库的文件名.
        alt_text: 图片 alt 文本.
        post_id: 关联的文章 ID (0 表示不关联).

    Returns:
        {"attachment_id": int, "url": str}
    """
    body: dict = {
        "image_data": image_data,
        "filename": filename,
    }
    if alt_text:
        body["alt_text"] = alt_text
    if post_id:
        body["post_id"] = post_id
    return apb_post("/upload-image", body)
