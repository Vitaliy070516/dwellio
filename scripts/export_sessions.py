#!/usr/bin/env python3
"""Експорт локальних сесій Claude Code (.jsonl) у читабельні Markdown-файли.

Використання:
    python3 scripts/export_sessions.py [--src DIR] [--out DIR] [--max-result N]

За замовчуванням бере сесії проєкту з ~/.claude/projects/<слаг-поточної-папки>/
і пише у ./exported_sessions/. Сабагенти (<session>/subagents/*.jsonl) експортуються
окремими файлами. Службові записи (api-request, snapshots, hooks тощо) пропускаються.
"""

from __future__ import annotations

import argparse
import json
from pathlib import Path
from typing import Any


def project_slug(path: Path) -> str:
    return str(path.resolve()).replace("/", "-").replace(".", "-")


def fence(text: str, lang: str = "") -> str:
    """Огорожа, що не ламається, якщо всередині вже є ```."""
    ticks = "```"
    while ticks in text:
        ticks += "`"
    return f"{ticks}{lang}\n{text}\n{ticks}"


def clip(text: str, limit: int) -> str:
    if limit <= 0 or len(text) <= limit:
        return text
    return text[:limit] + f"\n… [обрізано: ще {len(text) - limit} символів]"


def result_text(content: Any) -> str:
    if isinstance(content, str):
        return content
    parts: list[str] = []
    for block in content or []:
        if block.get("type") == "text":
            parts.append(block.get("text", ""))
        else:
            parts.append(f"[{block.get('type')}]")
    return "\n".join(parts)


def render_tool_use(block: dict[str, Any]) -> str:
    name = block.get("name", "?")
    inp = block.get("input", {}) or {}
    if name == "Bash" and "command" in inp:
        desc = inp.get("description")
        head = f"**🔧 Bash**" + (f" — {desc}" if desc else "")
        return f"{head}\n\n{fence(inp['command'], 'bash')}"
    return f"**🔧 {name}**\n\n{fence(json.dumps(inp, ensure_ascii=False, indent=2), 'json')}"


def convert(src: Path, max_result: int) -> tuple[str, str]:
    title = src.stem
    sections: list[str] = []
    results: dict[str, str] = {}
    entries: list[dict[str, Any]] = []

    for line in src.read_text(encoding="utf-8").splitlines():
        if not line.strip():
            continue
        try:
            d = json.loads(line)
        except json.JSONDecodeError:
            continue
        if d.get("type") == "custom-title" and d.get("customTitle"):
            title = d["customTitle"]
        if d.get("type") in ("user", "assistant"):
            entries.append(d)
            if d["type"] == "user" and isinstance(d["message"].get("content"), list):
                for b in d["message"]["content"]:
                    if b.get("type") == "tool_result":
                        results[b.get("tool_use_id", "")] = result_text(b.get("content"))

    first_ts = entries[0].get("timestamp", "") if entries else ""
    last_ts = entries[-1].get("timestamp", "") if entries else ""

    for d in entries:
        ts = d.get("timestamp", "")
        content = d["message"].get("content")
        if d["type"] == "user":
            if isinstance(content, str):
                sections.append(f"## 👤 Користувач  \n<sub>{ts}</sub>\n\n{content.strip()}")
                continue
            parts: list[str] = []
            for b in content or []:
                if b.get("type") == "text":
                    parts.append(b.get("text", "").strip())
                elif b.get("type") == "image":
                    parts.append("_[зображення]_")
            if parts:
                sections.append(f"## 👤 Користувач  \n<sub>{ts}</sub>\n\n" + "\n\n".join(parts))
            continue

        # assistant
        parts = []
        for b in content or []:
            t = b.get("type")
            if t == "thinking":
                text = (b.get("thinking") or "").strip()
                if text:
                    parts.append(f"<details><summary>💭 Роздуми</summary>\n\n{text}\n\n</details>")
                else:
                    parts.append("_💭 [роздуми: у .jsonl збережено лише підпис, текст не записується]_")
            elif t == "text":
                if b.get("text", "").strip():
                    parts.append(b["text"].strip())
            elif t == "tool_use":
                call = render_tool_use(b)
                res = results.get(b.get("id", ""))
                if res is not None:
                    call += (
                        "\n\n<details><summary>Результат</summary>\n\n"
                        + fence(clip(res, max_result))
                        + "\n\n</details>"
                    )
                parts.append(call)
        if parts:
            sections.append(f"## 🤖 Claude  \n<sub>{ts}</sub>\n\n" + "\n\n".join(parts))

    header = f"# {title}\n\n- Файл: `{src.name}`\n- Період: {first_ts} → {last_ts}\n- Повідомлень: {len(sections)}\n"
    return title, header + "\n---\n\n" + "\n\n---\n\n".join(sections) + "\n"


def main() -> None:
    ap = argparse.ArgumentParser()
    ap.add_argument("--src", type=Path, default=Path.home() / ".claude/projects" / project_slug(Path.cwd()))
    ap.add_argument("--out", type=Path, default=Path("exported_sessions"))
    ap.add_argument("--max-result", type=int, default=4000, help="макс. символів результату інструмента (0 = без обрізання)")
    ap.add_argument("--clean", action="store_true", help="перед експортом видалити старі .md у папці виводу")
    args = ap.parse_args()

    files = sorted(args.src.glob("*.jsonl")) + sorted(args.src.glob("*/subagents/*.jsonl"))
    if not files:
        raise SystemExit(f"Немає .jsonl у {args.src}")
    args.out.mkdir(parents=True, exist_ok=True)
    if args.clean:
        # Лише *.md в самій папці виводу; інші файли й підпапки не чіпаємо.
        removed = 0
        for old in args.out.glob("*.md"):
            old.unlink()
            removed += 1
        print(f"--clean: видалено {removed} старих .md")

    for f in files:
        title, md = convert(f, args.max_result)
        first = next((json.loads(l) for l in f.read_text(encoding="utf-8").splitlines() if '"timestamp"' in l), {})
        date = (first.get("timestamp") or "")[:10] or "undated"
        safe = "".join(c if c.isalnum() or c in " -_" else "" for c in title).strip().replace(" ", "-")[:60] or "session"
        prefix = "subagent-" if f.parent.name == "subagents" else ""
        out = args.out / f"{date}_{prefix}{safe}_{f.stem[:8]}.md"
        out.write_text(md, encoding="utf-8")
        print(f"{f.name} → {out} ({out.stat().st_size // 1024} KB)")


if __name__ == "__main__":
    main()
