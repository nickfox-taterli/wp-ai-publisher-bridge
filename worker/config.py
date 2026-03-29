"""全局配置和客户端初始化"""

import os

import httpx
import requests
from openai import OpenAI

APB_BASE = os.environ.get("APB_BASE", "http://127.0.0.1/wp-json/apb/v1")
APB_TOKEN = os.environ.get("APB_TOKEN", "xXD95mbcE66viakl5lVv73ejcM3IQ1hd")

HEADERS = {"X-APB-Token": APB_TOKEN, "Content-Type": "application/json"}

# localhost 请求不走代理
os.environ.setdefault("NO_PROXY", "localhost,127.0.0.1")

APB_SESSION = requests.Session()
APB_SESSION.trust_env = False
APB_SESSION.headers.update(HEADERS)

# 延迟初始化,main.py 启动时调 init_ai_client
ai_client: OpenAI | None = None
AI_MODEL: str = ""


def init_ai_client(config: dict):
    global ai_client, AI_MODEL
    AI_MODEL = config.get("ai_model", "")
    explicit_proxy = os.environ.get("HTTPS_PROXY") or os.environ.get("HTTP_PROXY")
    http_client_kwargs = {"trust_env": False}
    if explicit_proxy:
        http_client_kwargs["proxy"] = explicit_proxy

    ai_client = OpenAI(
        api_key=config.get("ai_api_key", ""),
        base_url=config.get("ai_base_url", ""),
        http_client=httpx.Client(**http_client_kwargs),
    )
