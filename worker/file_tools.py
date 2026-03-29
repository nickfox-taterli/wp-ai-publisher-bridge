"""本地文件读取工具集"""

from pathlib import Path


class LocalFileTools:

    @staticmethod
    def read_file(path: str, max_lines: int = 200, encoding: str = "utf-8", errors: str = "replace") -> dict:
        p = Path(path)
        if not p.exists():
            return {"error": f"文件不存在: {path}", "content": ""}
        if not p.is_file():
            return {"error": f"不是文件: {path}", "content": ""}
        try:
            raw = p.read_text(encoding=encoding, errors=errors)
            lines = raw.splitlines()
            total = len(lines)
            shown = lines[:max_lines]
            return {
                "path": str(p),
                "total_lines": total,
                "shown_lines": len(shown),
                "truncated": total > max_lines,
                "content": "\n".join(shown),
            }
        except Exception as e:
            return {"error": str(e), "content": ""}

    @staticmethod
    def list_dir(path: str, suffix: str = "") -> list[str]:
        p = Path(path)
        if not p.exists() or not p.is_dir():
            return []
        results = []
        for item in sorted(p.iterdir()):
            if suffix and item.is_file() and item.suffix != suffix:
                continue
            results.append(str(item) + ("/" if item.is_dir() else ""))
        return results

    @staticmethod
    def find_files(root: str, pattern: str = "*.c", max_depth: int = 3, max_results: int = 100) -> list[str]:
        p = Path(root)
        if not p.exists():
            return []
        results = []
        for item in p.rglob(pattern):
            if len(item.relative_to(p).parts) <= max_depth:
                results.append(str(item))
            if len(results) >= max_results:
                break
        return sorted(results)

    @staticmethod
    def get_file_info(path: str) -> dict:
        p = Path(path)
        if not p.exists():
            return {"error": f"文件不存在: {path}"}
        stat = p.stat()
        return {
            "path": str(p),
            "name": p.name,
            "suffix": p.suffix,
            "size": stat.st_size,
            "size_kb": f"{stat.st_size / 1024:.1f}KB" if stat.st_size < 1024 * 1024 else f"{stat.st_size / 1024 / 1024:.1f}MB",
            "is_file": p.is_file(),
            "is_dir": p.is_dir(),
        }
