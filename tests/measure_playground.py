"""Measure how long playground.wordpress.net takes to show the Atlas Home demo (headless Chrome, empty cache).

The published blueprint installs the plugin zip from slimanechanchoul.com, which does not exist before publishing.
This script runs the same steps with the plugin files written inline (same PHP work, minus a ~50 KB download),
so the time measured is close to what a first-time visitor waits. It also records what the widget does there:
before the engine with /api/answer is deployed, the hosted demo AI refuses, and the widget must say so.

Run: python -I tests/measure_playground.py [runs]
"""
from __future__ import annotations

import json
import os
import sys
import time
import urllib.parse
from pathlib import Path

from playwright.sync_api import sync_playwright

ROOT = Path(__file__).resolve().parents[1]
CHROME = os.path.expanduser("~/.cache/ms-playwright/chromium_headless_shell-1243/chrome-headless-shell-linux64/chrome-headless-shell")
RUNS = int(sys.argv[1]) if len(sys.argv) > 1 else 2


def inline_blueprint() -> dict:
    steps, dirs = [], set()
    for p in sorted((ROOT / "site-answers").rglob("*")):
        if p.is_file():
            rel = p.relative_to(ROOT / "site-answers").as_posix()
            d = "/wordpress/wp-content/plugins/site-answers/" + rel.rsplit("/", 1)[0] if "/" in rel else ""
            if d and d not in dirs:
                dirs.add(d)
                steps.append({"step": "mkdir", "path": d})
            steps.append({"step": "writeFile", "path": "/wordpress/wp-content/plugins/site-answers/" + rel,
                          "data": p.read_text(encoding="utf-8")})
    steps.insert(0, {"step": "mkdir", "path": "/wordpress/wp-content/plugins/site-answers"})
    steps += [{"step": "activatePlugin", "pluginPath": "site-answers/site-answers.php"},
              {"step": "runPHP", "code": (ROOT / "demo" / "setup.php").read_text(encoding="utf-8")}]
    return {"landingPage": "/", "preferredVersions": {"php": "8.3", "wp": "latest"}, "features": {"networking": True},
            "login": True, "steps": steps}


def site_frame(page):
    for f in page.frames:
        try:
            if f.locator(".sa-launch").count():
                return f
        except Exception:  # noqa: BLE001 - frames come and go while Playground boots
            pass
    return None


url = "https://playground.wordpress.net/#" + urllib.parse.quote(json.dumps(inline_blueprint()), safe="")
out = {"runs": [], "url_chars": len(url)}
with sync_playwright() as pw:
    browser = pw.chromium.launch(executable_path=CHROME)
    for i in range(RUNS):
        ctx = browser.new_context(viewport={"width": 1280, "height": 900})  # new context = empty cache
        page = ctx.new_page()
        t0 = time.monotonic()
        page.goto(url, wait_until="domcontentloaded", timeout=120_000)
        frame = None
        while time.monotonic() - t0 < 240 and frame is None:
            time.sleep(0.5)
            frame = site_frame(page)
        secs = round(time.monotonic() - t0, 1)
        run = {"seconds_to_widget": secs if frame else None}
        if frame is not None:
            frame.locator(".sa-launch").click()
            frame.locator(".sa-float .sa-input").fill("How much does standard shipping cost?")
            frame.locator(".sa-float .sa-input").press("Enter")
            try:
                frame.wait_for_function("!document.querySelector('.sa-float .sa-wait') && document.querySelectorAll('.sa-float .sa-bot').length >= 2",
                                        timeout=60_000)
                run["widget_reply"] = frame.evaluate("[...document.querySelectorAll('.sa-float .sa-bot')].pop().innerText")[:400]
            except Exception as e:  # noqa: BLE001
                run["widget_reply"] = f"no reply: {e}"[:200]
            page.screenshot(path=str(ROOT / "tests" / "out" / f"playground-run{i + 1}.png"))
        out["runs"].append(run)
        print(run)
        ctx.close()
    browser.close()
(ROOT / "tests" / "out" / "playground-timing.json").write_text(json.dumps(out, indent=1, ensure_ascii=False))
