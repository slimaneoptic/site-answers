"""Browser end-to-end (Playwright + headless Chrome) against a local WordPress from tests/serve.sh.

Demo path: the widget gets passages from WordPress (/search) and answers from a local copy of the hosted engine
(branch wp-demo, POST /api/answer). 6 English questions with known answers, 1 French, 1 Arabic, 2 off-topic,
source links, keyboard and focus, phone width, console errors, the inline shortcode, the "demo AI only works in
the online demo" message on a site the engine refuses, and the screenshots.

Run: python -I tests/e2e_ui.py http://127.0.0.1:9400 http://127.0.0.1:8790/api/answer [http://127.0.0.1:8791/api/answer]
     (the third URL: an engine that refuses this site's origin)
"""
from __future__ import annotations

import json
import os
import re
import sys
from pathlib import Path

import requests
from playwright.sync_api import sync_playwright

BASE = sys.argv[1].rstrip("/")
ENGINE = sys.argv[2]
REFUSING = sys.argv[3] if len(sys.argv) > 3 else ""
ROOT = Path(__file__).resolve().parents[1]
SHOTS = ROOT / "shots"
OUT = ROOT / "tests" / "out"
CHROME = os.path.expanduser("~/.cache/ms-playwright/chromium_headless_shell-1243/chrome-headless-shell-linux64/chrome-headless-shell")
results: list[dict] = []
transcript: list[dict] = []

CASES = [  # (question, page the answer must cite, any of these must appear in the answer)
    ("How much does standard shipping cost?", "/shipping/", ["6.95"]),
    ("How many days do I have to return an item?", "/returns-and-refunds/", ["30"]),
    ("How long is the warranty on furniture?", "/warranty/", ["2 years", "two years", "2-year", "two-year"]),
    ("How should I clean a wool rug?", "/care-guide/", ["beater", "suction", "blot", "vacuum"]),
    ("What size rug goes under a queen bed?", "/how-to-choose-the-right-rug-size/", ["8 x 10", "8x10", "8 by 10", "8 × 10"]),
    ("What are your customer service hours?", "/contact/", ["9 am", "9am", "9:00"]),
    ("Combien coûte la livraison au Canada ?", "/shipping/", ["19"]),
    ("كم يوماً لدي لإرجاع منتج؟", "/returns-and-refunds/", ["30", "٣٠"]),
]
OFF_TOPIC = ["Who won the 2022 World Cup?", "Can you recommend a good pizza recipe?"]


def check(name: str, ok: bool, detail="") -> bool:
    results.append({"name": name, "ok": bool(ok), "detail": str(detail)[:500]})
    print(("PASS " if ok else "FAIL ") + name + ("" if ok else f"  -- {str(detail)[:400]}"))
    return bool(ok)


http = requests.Session()
http.get(BASE + "/", timeout=120)


def rest(path, data=None):
    url = f"{BASE}/wp-json/{path}"
    return (http.post(url, json=data, timeout=90) if data is not None else http.get(url, timeout=90)).json()


def demo_endpoint(url: str):
    rest("sa-test/v1/set", {"name": "sa_test_demo_endpoint", "value": url})


rest("sa-test/v1/set", {"name": "sa_test_mock", "value": None})
rest("sa-test/v1/set", {"name": "site_answers_api_key", "value": ""})
rest("sa-test/v1/settings", {"demo_mode": 1, "ip_per_hour": 1000, "daily_cap": 300, "enabled": 1})
rest("sa-test/v1/reset", {})
demo_endpoint(ENGINE)

errors: list[str] = []


def watch(page):
    page.on("console", lambda m: errors.append(f"{page.url}: {m.text}") if m.type == "error" else None)
    page.on("pageerror", lambda e: errors.append(f"{page.url}: {e}"))


def ask(page, q: str, scope=".sa-float") -> dict:
    page.locator(f"{scope} .sa-input").fill(q)
    page.locator(f"{scope} .sa-input").press("Enter")
    page.wait_for_function(
        """(scope) => { const r = document.querySelector(scope); return r && !r.querySelector('.sa-wait')
              && r.querySelectorAll('.sa-bot').length >= 2 && r.querySelector('.sa-user'); }""", arg=scope, timeout=90_000)
    return page.evaluate(
        """(scope) => { const b = [...document.querySelectorAll(scope + ' .sa-bot')].pop();
              const p = b.querySelector('p');
              return { text: [...b.querySelectorAll(':scope > p:not(.sa-contact)')].map(x => x.innerText).join('\\n'),
                       sources: [...b.querySelectorAll('.sa-src a')].map(a => ({ href: a.getAttribute('href'), title: a.innerText })),
                       cites: [...b.querySelectorAll('.sa-cite')].map(a => a.getAttribute('href')),
                       contact: (b.querySelector('.sa-contact a') || {}).href || null,
                       note: b.classList.contains('sa-note'), err: b.classList.contains('sa-err'),
                       dir: getComputedStyle(p || b).direction }; }""", scope)


def open_widget(page):
    page.locator(".sa-launch").click()
    page.wait_for_selector(".sa-float .sa-box:not([hidden])")


REAL_KEY = os.environ.get("SA_REAL_KEY", "")  # optional: real AI in the admin test box, sent per request only
VISITOR = [{"name": "playground_auto_login_already_happened", "value": "1", "url": BASE}]  # no Playground auto-login


def admin_shot(browser):
    """Settings > Site Answers with the index status and a test question, as the site owner sees it."""
    ctx = browser.new_context(viewport={"width": 1280, "height": 960})
    if REAL_KEY:  # the key travels in a header to this WordPress only (tests/mu): never stored, never in the page
        ctx.route(re.compile("^" + re.escape(BASE) + "/.*"),
                  lambda route: route.continue_(headers={**route.request.headers, "x-sa-test-key": REAL_KEY}))
    page = ctx.new_page()
    watch(page)
    page.goto(BASE + "/wp-admin/options-general.php?page=site-answers")
    page.fill("#sa-test-q", "Can I return a lamp I don't like?")
    page.click("#sa-test-submit")
    page.wait_for_selector("#site-answers-test-result h3", timeout=90_000)
    page.wait_for_timeout(300)
    html_text = page.content()
    check("admin page: index status and test box shown, key never in the page", "Index" in html_text and "Passages used" in html_text
          and (not REAL_KEY or REAL_KEY not in html_text))
    page.screenshot(path=str(SHOTS / "admin-settings.png"))
    result = page.locator("#site-answers-test-result").inner_text()
    transcript.append({"q": "admin test box: Can I return a lamp I don't like?", "text": result[:600]})
    ctx.close()


with sync_playwright() as pw:
    browser = pw.chromium.launch(executable_path=CHROME)
    ctx = browser.new_context(viewport={"width": 1280, "height": 960})
    ctx.add_cookies(VISITOR)
    page = ctx.new_page()
    watch(page)
    page.goto(BASE + "/")

    # ---------------------------------------------------------------- keyboard, focus, labels
    launch = page.locator(".sa-launch")
    check("launcher has an accessible name and aria-expanded=false", launch.get_attribute("aria-label") == "Ask a question about this site"
          and launch.get_attribute("aria-expanded") == "false")
    open_widget(page)
    check("opening moves focus into the panel (question field)", page.evaluate("document.activeElement.classList.contains('sa-input')"))
    check("panel is a labelled dialog, answers in an aria-live log", page.evaluate(
        "(() => { const b = document.querySelector('.sa-float .sa-box'), l = b.querySelector('.sa-log');"
        " return b.getAttribute('role') === 'dialog' && !!document.getElementById(b.getAttribute('aria-labelledby'))"
        " && l.getAttribute('aria-live') === 'polite' && !!document.querySelector('label[for=\"' + b.querySelector('.sa-input').id + '\"]'); })()"))
    check("starter questions shown as buttons", page.locator(".sa-float .sa-chip").count() == 3)
    page.keyboard.press("Escape")
    check("Esc closes and returns focus to the launcher", page.locator(".sa-float .sa-box").is_hidden()
          and page.evaluate("document.activeElement.classList.contains('sa-launch')") and launch.get_attribute("aria-expanded") == "false")
    store_before = page.evaluate("[document.cookie, Object.keys(localStorage).sort().join(), Object.keys(sessionStorage).sort().join()]")

    # ---------------------------------------------------------------- known answers (demo path, real AI through the local engine)
    for q, path, want in CASES:
        page.goto(BASE + "/")
        open_widget(page)
        a = ask(page, q)
        transcript.append({"q": q, **a})
        cited_ok = any(s["href"].endswith(path) for s in a["sources"])
        text_ok = any(w in a["text"] for w in want)
        check(f"answer: {q}", text_ok and cited_ok and not a["note"] and not a["err"], a)
        if a["cites"]:
            check(f"  inline [n] links point to the sources: {q}", all(c in [s["href"] for s in a["sources"]] for c in a["cites"]), a["cites"])
        if q.startswith("Combien"):
            check("  French question answered in French", re.search(r"\b(livraison|coûte|jours|est|sont|pour)\b", a["text"]) is not None, a["text"])
        if q.startswith("كم"):
            check("  Arabic answer in Arabic, right to left", re.search(r"[؀-ۿ]", a["text"]) is not None and a["dir"] == "rtl", (a["dir"], a["text"]))
            page.screenshot(path=str(SHOTS / "widget-arabic.png"))

    page.goto(BASE + "/")
    open_widget(page)
    ask(page, "Do you sell gift cards?")
    store_after = page.evaluate("[document.cookie, Object.keys(localStorage).sort().join(), Object.keys(sessionStorage).sort().join()]")
    check("the widget sets no cookie and stores nothing in the browser", store_before == store_after, (store_before, store_after))

    for q in OFF_TOPIC:
        page.goto(BASE + "/")
        open_widget(page)
        a = ask(page, q)
        transcript.append({"q": q, **a})
        check(f"off-topic says not found and links the contact page: {q}", ("couldn't find" in a["text"].lower() or "could not find" in a["text"].lower())
              and a["contact"] and a["contact"].endswith("/contact/") and not a["sources"], a)

    # ---------------------------------------------------------------- a source link opens the right page
    page.goto(BASE + "/")
    open_widget(page)
    page.locator(".sa-float .sa-chip").nth(1).click()  # "What is your return policy?"
    page.wait_for_function("!document.querySelector('.sa-float .sa-wait') && document.querySelectorAll('.sa-float .sa-bot').length >= 2", timeout=90_000)
    page.wait_for_timeout(300)
    page.screenshot(path=str(SHOTS / "widget-answer.png"))
    first = page.locator(".sa-float .sa-src a").first
    href = first.get_attribute("href")
    first.click()
    page.wait_for_load_state("load")
    check("starter chip answered; clicking a source opens that page", page.url.rstrip("/").endswith(href.rstrip("/").split("/")[-1])
          and "Returns" in page.title(), (href, page.url, page.title()))
    check("visitor view: no admin bar in the screenshots", page.locator("#wpadminbar").count() == 0)

    # ---------------------------------------------------------------- inline shortcode on the Contact page
    page.goto(BASE + "/contact/")
    check("shortcode chat is on the Contact page", page.locator(".sa-inline-host .sa-box").count() == 1)
    a = ask(page, "Do you have a showroom?", scope=".sa-inline-host")
    check("inline chat answers too", "online" in a["text"].lower() and any(s["href"].endswith("/faq/") for s in a["sources"]), a)

    # ---------------------------------------------------------------- phone width
    phone = browser.new_context(viewport={"width": 390, "height": 844}, is_mobile=True, has_touch=True, device_scale_factor=2)
    phone.add_cookies(VISITOR)
    mp = phone.new_page()
    watch(mp)
    mp.goto(BASE + "/")
    open_widget(mp)
    a = ask(mp, "How much does standard shipping cost?")
    box = mp.locator(".sa-float .sa-box").bounding_box()
    sideways = mp.evaluate("document.documentElement.scrollWidth - window.innerWidth")
    check("phone 390x844: answer shown, panel inside the screen, no sideways scroll", "6.95" in a["text"] and box["x"] >= 0
          and box["x"] + box["width"] <= 390 and box["y"] >= 0 and sideways <= 0, (box, sideways))
    mp.screenshot(path=str(OUT / "phone-390.png"))
    phone.close()

    # ---------------------------------------------------------------- a site the demo AI refuses
    if REFUSING:
        demo_endpoint(REFUSING)
        page.goto(BASE + "/")
        open_widget(page)
        n_err = len(errors)
        a = ask(page, "How much does standard shipping cost?")
        check("refused by the demo AI: clear message + the passages", "only works in the online demo" in a["text"]
              and "Settings > Site Answers" in a["text"] and any(s["href"].endswith("/shipping/") for s in a["sources"]), a)
        del errors[n_err:]  # the browser logs the refused cross-origin call itself; expected here only
        demo_endpoint(ENGINE)

    admin_shot(browser)
    check("0 console errors", not errors, errors)
    browser.close()

OUT.mkdir(exist_ok=True)
(OUT / "e2e-ui.json").write_text(json.dumps({"results": results, "transcript": transcript}, indent=1, ensure_ascii=False))
failed = [r for r in results if not r["ok"]]
print(f"\n{len(results) - len(failed)}/{len(results)} passed")
sys.exit(1 if failed else 0)
