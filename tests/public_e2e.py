"""Public flow, to run AFTER publishing: playground.wordpress.net loads the published blueprint and zip, and the widget
answers through the deployed engine (POST /api/answer, WP_DEMO_ORIGINS set).

Checks: blueprint and zip reachable with CORS, engine origin rules (Playground allowed on /api/answer only, preflight
included, another site refused), time until the widget is ready, 4 English questions with known answers, 1 French,
1 Arabic, 1 off-topic, source links, 0 console errors from the demo site.

Run: python -I tests/public_e2e.py [--engine https://site-chatbot-omega.vercel.app] [--page https://slimanechanchoul.com/wp-chatbot]
"""
from __future__ import annotations

import argparse
import json
import os
import time
from pathlib import Path

import requests
from playwright.sync_api import sync_playwright

ap = argparse.ArgumentParser()
ap.add_argument("--engine", default="https://site-chatbot-omega.vercel.app")
ap.add_argument("--page", default="https://slimanechanchoul.com/wp-chatbot")
args = ap.parse_args()
PLAYGROUND = "https://playground.wordpress.net"
DEMO_URL = f"{PLAYGROUND}/?blueprint-url={args.page}/blueprint.json"
CHROME = os.path.expanduser("~/.cache/ms-playwright/chromium_headless_shell-1243/chrome-headless-shell-linux64/chrome-headless-shell")
OUT = Path(__file__).resolve().parent / "out"
results: list[dict] = []

CASES = [
    ("How much does standard shipping cost?", "/shipping/", ["6.95"]),
    ("How many days do I have to return an item?", "/returns-and-refunds/", ["30"]),
    ("How should I clean a wool rug?", "/care-guide/", ["beater", "suction", "blot", "vacuum"]),
    ("What are your customer service hours?", "/contact/", ["9 am", "9am", "9:00"]),
    ("Combien coûte la livraison au Canada ?", "/shipping/", ["19"]),
    ("كم يوماً لدي لإرجاع منتج؟", "/returns-and-refunds/", ["30", "٣٠"]),
]


def check(name, ok, detail=""):
    results.append({"name": name, "ok": bool(ok), "detail": str(detail)[:500]})
    print(("PASS " if ok else "FAIL ") + name + ("" if ok else f"  -- {str(detail)[:400]}"))
    return ok


# ---------------------------------------------------------------- files and engine rules
for name in ("blueprint.json", "site-answers.zip", ""):
    r = requests.get(f"{args.page}/{name}", timeout=30)
    check(f"{args.page}/{name} -> 200", r.status_code == 200, r.status_code)
    if name:
        check(f"  {name} has Access-Control-Allow-Origin: *", r.headers.get("access-control-allow-origin") == "*", r.headers.get("access-control-allow-origin"))
try:
    bp = requests.get(f"{args.page}/blueprint.json", timeout=30).json()
    check("blueprint installs the published zip", bp["steps"][0]["pluginData"]["url"] == f"{args.page}/site-answers.zip", bp["steps"][0])
except ValueError as e:
    check("blueprint is valid JSON", False, e)

pre = {"Origin": PLAYGROUND, "Access-Control-Request-Method": "POST", "Access-Control-Request-Headers": "content-type"}
r = requests.options(f"{args.engine}/api/answer", headers=pre, timeout=30)
check("engine: CORS preflight for /api/answer allows Playground", r.status_code == 200 and r.headers.get("access-control-allow-origin") == PLAYGROUND,
      (r.status_code, dict(r.headers)))
probe = {"question": "Is shipping free?", "passages": [{"n": 1, "url": "https://shop.example/shipping/", "title": "Shipping",
                                                        "text": "Shipping is free on orders over $75."}]}
r = requests.post(f"{args.engine}/api/chat", data=json.dumps({"kb_id": "x", "messages": []}), headers={"Origin": PLAYGROUND}, timeout=30)
check("engine: other POST routes still refuse Playground", r.status_code == 403, r.status_code)
r = requests.post(f"{args.engine}/api/answer", data=json.dumps(probe), headers={"Origin": "https://example.com", "Content-Type": "text/plain"}, timeout=30)
check("engine: /api/answer refuses another site", r.status_code == 403, r.status_code)

# ---------------------------------------------------------------- the demo in the browser
errors: list[str] = []
timing = {}
with sync_playwright() as pw:
    browser = pw.chromium.launch(executable_path=CHROME)
    ctx = browser.new_context(viewport={"width": 1280, "height": 900})
    page = ctx.new_page()
    page.on("console", lambda m: errors.append(f"{m.text}") if m.type == "error" and "/scope:" in (m.location or {}).get("url", "") else None)
    t0 = time.monotonic()
    page.goto(DEMO_URL, wait_until="domcontentloaded", timeout=120_000)

    def site():
        for f in page.frames:
            try:
                if f.locator(".sa-launch").count():
                    return f
            except Exception:  # noqa: BLE001
                pass
        return None

    frame = None
    while frame is None and time.monotonic() - t0 < 240:
        time.sleep(0.5)
        frame = site()
    timing["seconds_to_widget"] = round(time.monotonic() - t0, 1)
    if check(f"Playground shows the store with the chat button ({timing['seconds_to_widget']} s)", frame is not None):
        for q, path, want in CASES + [("Who won the 2022 World Cup?", "/contact/", ["couldn't find", "could not find"])]:
            frame.goto(frame.url)  # fresh conversation
            frame.locator(".sa-launch").wait_for(timeout=60_000)
            frame.locator(".sa-launch").click()
            t = time.monotonic()
            frame.locator(".sa-float .sa-input").fill(q)
            frame.locator(".sa-float .sa-input").press("Enter")
            frame.wait_for_function("!document.querySelector('.sa-float .sa-wait') && document.querySelectorAll('.sa-float .sa-bot').length >= 2",
                                    timeout=90_000)
            a = frame.evaluate("""(() => { const b = [...document.querySelectorAll('.sa-float .sa-bot')].pop();
                return { text: b.innerText, hrefs: [...b.querySelectorAll('.sa-src a, .sa-contact a')].map(x => x.getAttribute('href')) }; })()""")
            ok = any(w in a["text"] for w in want) and any(h and h.rstrip("/").endswith(path.rstrip("/")) for h in a["hrefs"]) \
                and "only works in the online demo" not in a["text"]
            check(f"{q} ({round(time.monotonic() - t, 1)} s)", ok, a)
        page.screenshot(path=str(OUT / "public-playground.png"))
    check("0 console errors from the demo site", not errors, errors[:5])
    browser.close()

OUT.mkdir(exist_ok=True)
(OUT / "public-e2e.json").write_text(json.dumps({"timing": timing, "results": results}, indent=1, ensure_ascii=False))
failed = [r for r in results if not r["ok"]]
print(f"\n{len(results) - len(failed)}/{len(results)} passed; widget ready after {timing.get('seconds_to_widget')} s")
raise SystemExit(1 if failed else 0)
