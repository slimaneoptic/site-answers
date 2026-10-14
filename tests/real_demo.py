"""Demo path with the real model, a few questions at a time, and the screenshots of the landing page.

The widget gets passages from the local WordPress (/search) and answers from a local copy of the hosted engine
(branch wp-demo) that has the AI key. Start that engine with low caps (for example WP_IP_CAP=4), so this script can
never make more calls than planned: 1 English starter question (hero screenshot) and 3 Arabic questions on the
English store (one screenshot). Screenshots are taken at 2x: shots/*.png and the page images at 1280 px, plus the
chat panel alone for phones (site-page/img/*-panel.webp, 760 x 1200).

Run: python -I tests/real_demo.py http://127.0.0.1:9400 http://127.0.0.1:8790
"""
from __future__ import annotations

import io
import json
import os
import re
import sys
import time
from pathlib import Path

import requests
from PIL import Image
from playwright.sync_api import sync_playwright

BASE = sys.argv[1].rstrip("/")
ENGINE = sys.argv[2].rstrip("/")
ROOT = Path(__file__).resolve().parents[1]
CHROME = os.path.expanduser("~/.cache/ms-playwright/chromium_headless_shell-1243/chrome-headless-shell-linux64/chrome-headless-shell")
ARABIC = [  # question, page it may cite, any of these in the answer, screenshot name
    ("هل لديكم معرض يمكنني زيارته؟", ["/faq/", "/contact/"], ["لا", "الإنترنت", "عبر"], None),
    ("كم تكلفة الشحن إلى كندا؟", ["/shipping/"], ["19", "١٩"], "widget-arabic"),
    ("ما مدة ضمان الأثاث؟", ["/warranty/"], ["2", "٢", "سنتان", "سنتين", "عامين", "عامان"], None),
]
results, transcript = [], []


def check(name, ok, detail=""):
    results.append({"name": name, "ok": bool(ok), "detail": str(detail)[:500]})
    print(("PASS " if ok else "FAIL ") + name + ("" if ok else f"  -- {str(detail)[:400]}"))


http = requests.Session()
http.get(BASE + "/", timeout=120)
for name, value in (("sa_test_mock", None), ("site_answers_api_key", ""), ("sa_test_demo_endpoint", ENGINE + "/api/answer")):
    http.post(f"{BASE}/wp-json/sa-test/v1/set", json={"name": name, "value": value}, timeout=60)
http.post(f"{BASE}/wp-json/sa-test/v1/settings", json={"demo_mode": 1, "ip_per_hour": 1000, "daily_cap": 300, "enabled": 1}, timeout=60)
http.post(f"{BASE}/wp-json/sa-test/v1/reset", json={}, timeout=60)
before = requests.get(ENGINE + "/healthz", timeout=30).json()["wp_budget"]["questions"]


def wait_answer(page):
    page.wait_for_function("""() => { const r = document.querySelector('.sa-float'); return r && !r.querySelector('.sa-wait')
        && r.querySelectorAll('.sa-bot').length >= 2 && r.querySelector('.sa-user'); }""", timeout=90_000)
    page.wait_for_timeout(400)
    return page.evaluate("""() => { const b = [...document.querySelectorAll('.sa-float .sa-bot')].pop(), p = b.querySelector('p');
        return { text: [...b.querySelectorAll(':scope > p:not(.sa-contact)')].map(x => x.innerText).join('\\n'),
                 sources: [...b.querySelectorAll('.sa-src a')].map(a => a.getAttribute('href')),
                 note: b.classList.contains('sa-note'), dir: getComputedStyle(p || b).direction }; }""")


def save(png: bytes, name: str) -> None:
    """2x screenshot -> shots/NAME.png and img/NAME.webp at 1280 x 960, img/NAME-panel.webp: the chat panel alone."""
    img = Image.open(io.BytesIO(png)).convert("RGB")
    small = img.resize((1280, 960), Image.LANCZOS)
    small.save(ROOT / "shots" / f"{name}.png", optimize=True)
    small.save(ROOT / "site-page" / "img" / f"{name}.webp", quality=82, method=6)
    panel = img.crop((1760, 552, 2520, 1752))  # the floating panel at 2x (380 x 600 CSS px)
    panel.save(ROOT / "site-page" / "img" / f"{name}-panel.webp", quality=82, method=6)


with sync_playwright() as pw:
    browser = pw.chromium.launch(executable_path=CHROME)
    ctx = browser.new_context(viewport={"width": 1280, "height": 960}, device_scale_factor=2)
    ctx.add_cookies([{"name": "playground_auto_login_already_happened", "value": "1", "url": BASE}])  # visitor view
    page = ctx.new_page()

    page.goto(BASE + "/")
    page.locator(".sa-launch").click()
    page.locator(".sa-float .sa-chip").nth(1).click()  # "What is your return policy?"
    t = time.monotonic()
    a = wait_answer(page)
    a["seconds"] = round(time.monotonic() - t, 1)
    transcript.append({"q": "What is your return policy? (starter)", **a})
    check("starter 'What is your return policy?': real answer citing Returns and refunds", not a["note"] and "30" in a["text"]
          and any(h.endswith("/returns-and-refunds/") for h in a["sources"]), a)
    save(page.screenshot(), "widget-answer")

    for q, paths, want, shot in ARABIC:
        page.goto(BASE + "/")
        page.locator(".sa-launch").click()
        page.locator(".sa-float .sa-input").fill(q)
        page.locator(".sa-float .sa-input").press("Enter")
        t = time.monotonic()
        a = wait_answer(page)
        a["seconds"] = round(time.monotonic() - t, 1)
        transcript.append({"q": q, **a})
        check(f"demo path, Arabic on the English store: {q}", not a["note"] and a["dir"] == "rtl" and re.search(r"[؀-ۿ]", a["text"])
              and any(w in a["text"] for w in want) and any(h.endswith(p) for h in a["sources"] for p in paths), a)
        if shot:
            save(page.screenshot(), shot)
    browser.close()

used = requests.get(ENGINE + "/healthz", timeout=30).json()["wp_budget"]["questions"] - before
print(f"real AI calls through the engine: {used}")
(ROOT / "tests" / "out" / "real-demo.json").write_text(json.dumps({"real_calls": used, "results": results, "transcript": transcript},
                                                                     indent=1, ensure_ascii=False))
print(f"{sum(r['ok'] for r in results)}/{len(results)} passed")
sys.exit(0 if all(r["ok"] for r in results) else 1)
