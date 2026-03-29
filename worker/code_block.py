"""代码块格式化 - 用 SyntaxHighlighter Evolved 的 [sourcecode] 短代码

语言标签参考: as3, bash, c, cpp, csharp, css, go, html, java, javascript,
json->plain, matlab, php, python, ruby, sql, swift, xml, yaml 等.
"""

import html as html_lib


# AI 输出语言名 -> SyntaxHighlighter 标签
LANG_MAP = {
    "c": "c",
    "cpp": "cpp",
    "c++": "cpp",
    "arduino": "arduino",
    "ino": "arduino",
    "python": "python",
    "py": "python",
    "javascript": "javascript",
    "js": "javascript",
    "typescript": "javascript",
    "ts": "javascript",
    "java": "java",
    "go": "go",
    "golang": "go",
    "rust": "plain",
    "ruby": "ruby",
    "rb": "ruby",
    "php": "php",
    "bash": "bash",
    "shell": "bash",
    "sh": "bash",
    "sql": "sql",
    "html": "html",
    "xml": "xml",
    "css": "css",
    "yaml": "yaml",
    "yml": "yaml",
    "json": "plain",
    "diff": "diff",
    "patch": "diff",
    "makefile": "plain",
    "dockerfile": "plain",
    "plain": "plain",
    "text": "plain",
}


def format_code_block(code: str, language: str = "c") -> str:
    lang = LANG_MAP.get(language.lower().strip(), "plain")
    raw_code = code.rstrip("\n")
    # 必须转义,否则 wp_kses_post() 会把 <xxx.h> 当 HTML 标签吃掉
    raw_code = html_lib.escape(raw_code, quote=False)

    return f'[sourcecode language="{lang}"]{raw_code}[/sourcecode]'
