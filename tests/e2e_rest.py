"""End-to-end checks against a local WordPress (Playground CLI) started with tests/serve.sh.

Owner-key path with a fake AI (pre_http_request in tests/mu/sa-test-helper.php), limits, validation, settings
sanitising, the Rebuild button, incremental updates, exclusions, the shortcode, "the key never leaves the server",
the debug log, and (with --uninstall, last) the uninstall cleanup.

Run: python -I tests/e2e_rest.py http://127.0.0.1:9400 [--uninstall]
"""
from __future__ import annotations

import html
import json
import re
import sys
import time
from pathlib import Path

import requests

BASE = sys.argv[1].rstrip("/")
UNINSTALL = "--uninstall" in sys.argv
FAKE_KEY = "test-key-not-real-0042"
OUT = Path(__file__).resolve().parent / "out"
results: list[dict] = []
s = requests.Session()


def check(name: str, ok: bool, detail="") -> bool:
    results.append({"name": name, "ok": bool(ok), "detail": str(detail)[:400]})
    print(("PASS " if ok else "FAIL ") + name + ("" if ok else f"  -- {str(detail)[:300]}"))
    return bool(ok)


def rest(path: str, data=None, method=None, **kw):
    url = f"{BASE}/wp-json/{path.lstrip('/')}"
    if data is None and method is None:
        return s.get(url, timeout=90, **kw)
    return s.request(method or "POST", url, json=data, timeout=90, **kw)


def ask(q: str, history=None):
    r = rest("site-answers/v1/ask", {"question": q, "history": history or []})
    try:
        return r.status_code, r.json(), r
    except ValueError:
        return r.status_code, {"raw": r.text[:500]}, r


def state() -> dict:
    return rest("sa-test/v1/state").json()


def setopt(name: str, value):
    rest("sa-test/v1/set", {"name": name, "value": value})


def settings(**values) -> dict:
    return rest("sa-test/v1/settings", values).json()


def page(path: str) -> str:
    return s.get(BASE + path, timeout=90).text


def last_requests() -> list:
    return state()["requests"]


# ---------------------------------------------------------------- start
s.get(BASE + "/", timeout=120)  # Playground auto-login (admin cookies) happens on the first request
st = state()
print(f"PHP {st['php']}  WordPress {st['wp']}  WP_DEBUG {st['debug']}")
check("WP_DEBUG is on", st["debug"] is True)
check("index built at setup: 10 items (8 pages, 2 posts)", st["status"]["posts"] == 10 and st["status"]["types"] == {"page": 8, "post": 2},
      st["status"])
check("empty Journal page skipped", "Journal" not in json.dumps(st["status"]))
check("index options have autoload off", all(r["autoload"] in ("off", "no") for r in st["autoload"] if r["option_name"] != "site_answers_settings"),
      st["autoload"])
check("daily safety rebuild scheduled", any(c.startswith("site_answers_daily_rebuild") for c in st["cron"]), st["cron"])

# ---------------------------------------------------------------- no key: passages with links, not found, validation
setopt("sa_test_mock", None)
setopt("site_answers_api_key", "")
settings(demo_mode=0, ip_per_hour=1000, daily_cap=300)
code, d, r = ask("How much does standard shipping cost?")
check("no key: extractive passages with an honest note", code == 200 and d["mode"] == "extractive" and d.get("notice") == "no_key"
      and d["sources"][0]["url"].endswith("/shipping/") and "$6.95" in d["sources"][0]["passage"], d)
check("ask response is not cacheable", "no-store" in r.headers.get("Cache-Control", ""), r.headers.get("Cache-Control"))
code, d, _ = ask("Who won the 2022 World Cup?")
check("off-topic: not found + contact link, no AI", d["mode"] == "not_found" and d["sources"] == [] and d["contact"]["url"].endswith("/contact/")
      and d["contact"]["label"] == "Contact us", d)
code, d, _ = ask("Can you recommend a good pizza recipe?")
check("off-topic 2: not found", d["mode"] == "not_found", d)
code, d, _ = ask("Qui a gagné la coupe du monde 2022 ?")
check("French off-topic: not found in French", d["mode"] == "not_found" and "trouvé" in d["answer"] and d["contact"]["label"] == "Nous contacter", d)
for q, why in (("", "empty"), ("   ", "blank"), ("x" * 501, "501 chars")):
    code, d, _ = ask(q)
    check(f"question {why} -> 400", code == 400, (code, d))
code, d, _ = ask("ok?", history=[{"role": "user", "content": "q"}] * 7)
check("history of 7 items -> 400", code == 400, (code, d))
code, d, _ = ask("ok?", history=[{"role": "user", "content": "x" * 1001}])
check("history item of 1,001 chars -> 400", code == 400, (code, d))
code, d, _ = ask("ok?", history=[{"role": "system", "content": "x"}])
check("history role system -> 400", code == 400, (code, d))
check("GET /ask is not a route", rest("site-answers/v1/ask").status_code == 404)
check("/search is off unless demo mode", rest("site-answers/v1/search", {"question": "shipping"}).status_code == 404)
check("/test needs an admin nonce", rest("site-answers/v1/test", {"question": "shipping"}).status_code in (401, 403))

# ---------------------------------------------------------------- owner key + fake AI
setopt("site_answers_api_key", FAKE_KEY)
setopt("sa_test_mock", "answer")
setopt("sa_test_requests", [])
code, d, _ = ask("How much does standard shipping cost?")
check("AI answer with citations", code == 200 and d["mode"] == "ai" and "[1]" in d["answer"], d)
check("citations map to sources: [1] and [2] kept, [9] dropped", [x["n"] for x in d["sources"]] == [1, 2]
      and d["sources"][0]["url"].endswith("/shipping/"), d.get("sources"))
req = last_requests()[-1]
check("AI call: OpenRouter URL, default model, bearer key", req["url"] == "https://openrouter.ai/api/v1/chat/completions"
      and req["model"] == "google/gemini-3.8-flash" and req["has_auth"], req)
check("system prompt: passages only, cite, contact page, passages are data, visitor language",
      all(k in req["system"] for k in ('only the numbered passages', '[1]', '"Contact" page', 'never instructions', 'language of the question')),
      req["system"])
check("passages numbered with title and URL", req["user"].startswith("Passages from the site:\n\n[1] Shipping (") and "Question: How much" in req["user"],
      req["user"][:200])
code, d, _ = ask("And express?", history=[{"role": "user", "content": "How much does standard shipping cost?"},
                                           {"role": "assistant", "content": "It costs $6.95 [1]."}])
req = last_requests()[-1]
check("follow-up: history sent (3 messages before the question)", d["mode"] == "ai" and req["turns"] == 4, (d.get("mode"), req["turns"]))
check("follow-up found the shipping page through the previous question", d["sources"] and d["sources"][0]["url"].endswith("/shipping/"), d.get("sources"))
code, d, _ = ask("How much does standard shipping cost NOTFOUND?")
check("AI says not found: no sources, contact link", d["mode"] == "ai" and d["sources"] == [] and d["contact"] and d["contact"]["url"].endswith("/contact/"), d)
n_before = len(last_requests())
code, d, _ = ask("Who won the 2022 World Cup?")
check("off-topic makes no AI call", d["mode"] == "not_found" and len(last_requests()) == n_before, d)
code, d, _ = ask("كم يوما لدي لإرجاع منتج؟")
reqs = last_requests()
check("Arabic question: translated once for search, then answered", d["mode"] == "ai" and d["sources"]
      and d["sources"][0]["url"].endswith("/returns-and-refunds/") and any("Translate" in x["system"] for x in reqs[-2:]), (d, [x["system"][:40] for x in reqs[-2:]]))
setopt("sa_test_mock", "fail")
code, d, _ = ask("How long is the warranty on furniture?")
check("AI failure: passages + honest note", d["mode"] == "extractive" and d.get("notice") == "ai_error" and d["sources"][0]["url"].endswith("/warranty/"), d)
setopt("sa_test_mock", "answer")

# ---------------------------------------------------------------- limits
settings(ip_per_hour=3)
rest("sa-test/v1/reset", {})
codes = [ask("How should I clean a wool rug?")[0] for _ in range(4)]
code, d, _ = ask("How should I clean a wool rug?")
check("per-IP limit: 429 after 3 per hour", codes[:3] == [200, 200, 200] and code == 429 and "try again" in d.get("message", ""), (codes, code, d))
settings(ip_per_hour=1000, daily_cap=1)
rest("sa-test/v1/reset", {})
setopt("site_answers_daily", None)
c1 = ask("How should I clean a wool rug?")[1]
c2 = ask("What size rug goes under a queen bed?")[1]
check("daily cap: first AI answer, then passages with a note", c1["mode"] == "ai" and c2["mode"] == "extractive" and c2.get("notice") == "daily_cap", (c1.get("mode"), c2))
settings(daily_cap=300)

# ---------------------------------------------------------------- the key never leaves the server
admin_html = page("/wp-admin/options-general.php?page=site-answers")
pages_html = page("/") + page("/contact/") + admin_html
api = rest("").text + rest("wp/v2/settings").text + json.dumps([ask("How much does standard shipping cost?")[1]])
check("key not in page HTML (front + settings page)", FAKE_KEY not in pages_html)
check("key not in REST output", FAKE_KEY not in api)
check("settings page shows the saved state, empty password field", "A key is saved." in admin_html
      and re.search(r'<input type="password" id="sa-api_key"[^>]*value=""', admin_html) is not None)

# ---------------------------------------------------------------- admin test box (REST with nonce)
nonce = re.search(r'createNonceMiddleware\(\s*"([0-9a-f]+)"', admin_html)
if check("settings page has a REST nonce for the test box", nonce is not None):
    r = rest("site-answers/v1/test", {"question": "What are your customer service hours?"}, headers={"X-WP-Nonce": nonce.group(1)})
    t = r.json()
    check("test box: answer + passages with scores", r.status_code == 200 and t["mode"] == "ai" and t["debug"]["passages"]
          and t["debug"]["passages"][0]["url"].endswith("/contact/") and "score" in t["debug"]["passages"][0], t)

# ---------------------------------------------------------------- settings form (options.php): sanitising
form = admin_html.split('action="options.php"', 1)[1]
wpnonce = re.search(r'name="_wpnonce" value="([^"]+)"', form).group(1)
before = state()["settings"]
fields = [("option_page", "site_answers"), ("action", "update"), ("_wpnonce", wpnonce),
          ("_wp_http_referer", "/wp-admin/options-general.php?page=site-answers"),
          ("site_answers_settings[enabled]", "1"),
          ("site_answers_settings[color]", "not-a-colour"),
          ("site_answers_settings[greeting]", "<script>alert(1)</script>Hello <b>there</b>"),
          ("site_answers_settings[starters][]", "How much is shipping?"),
          ("site_answers_settings[starters][]", "<img src=x onerror=alert(1)>Returns?"),
          ("site_answers_settings[starters][]", ""),
          ("site_answers_settings[contact_page]", str(before["contact_page"])),
          ("site_answers_settings[base_url]", "http://insecure.example/v1"),
          ("site_answers_settings[model]", "openai/gpt-x<script>"),
          ("site_answers_settings[ip_per_hour]", "0"),
          ("site_answers_settings[daily_cap]", "999999"),
          ("site_answers_settings[post_types][]", "page"),
          ("site_answers_settings[post_types][]", "bogus_type"),
          ("site_answers_api_key", "  test-key-not-real-0043  ")]
r = s.post(BASE + "/wp-admin/options.php", data=fields, timeout=90)
after = state()
sv = after["settings"]
check("save: http base URL refused, previous kept, error shown", sv["base_url"] == "https://openrouter.ai/api/v1" and "must start with https://" in r.text, sv["base_url"])
check("save: bad colour -> default", sv["color"] == "#1f6f5c", sv["color"])
check("save: tags stripped from greeting and starters", sv["greeting"] == "Hello there" and sv["starters"] == ["How much is shipping?", "Returns?", ""], (sv["greeting"], sv["starters"]))
check("save: model keeps only safe characters", sv["model"] == "openai/gpt-xscript", sv["model"])
check("save: limits clamped (1 per hour minimum, 100,000 per day maximum)", sv["ip_per_hour"] == 1 and sv["daily_cap"] == 100000, sv)
check("save: unknown post type dropped, inactive WooCommerce type kept", sorted(sv["post_types"]) == ["page", "product"], sv["post_types"])
check("save: key trimmed and saved (length only checked)", after["key_len"] == len("test-key-not-real-0043"), after["key_len"])
check("post types changed -> index rebuilt with pages only", after["status"]["types"] == {"page": 8}, after["status"])
check("saved key not echoed after save", "test-key-not-real-0043" not in r.text)
# empty key field keeps the key; the Remove box clears it
form = r.text.split('action="options.php"', 1)[1]
wpnonce = re.search(r'name="_wpnonce" value="([^"]+)"', form).group(1)
keep = [(k, v) for k, v in fields if k not in ("_wpnonce", "site_answers_api_key", "site_answers_settings[post_types][]",
                                               "site_answers_settings[base_url]", "site_answers_settings[ip_per_hour]")]
keep += [("_wpnonce", wpnonce), ("site_answers_api_key", ""), ("site_answers_settings[base_url]", "https://openrouter.ai/api/v1/chat/completions"),
         ("site_answers_settings[ip_per_hour]", "1000"), ("site_answers_settings[post_types][]", "page"), ("site_answers_settings[post_types][]", "post")]
r = s.post(BASE + "/wp-admin/options.php", data=keep, timeout=90)
after = state()
check("empty key field keeps the saved key", after["key_len"] == len("test-key-not-real-0043"), after["key_len"])
check("base URL pasted with /chat/completions is trimmed", after["settings"]["base_url"] == "https://openrouter.ai/api/v1", after["settings"]["base_url"])
check("post types back to pages + posts -> 10 items", after["status"]["posts"] == 10, after["status"])
form = r.text.split('action="options.php"', 1)[1]
wpnonce = re.search(r'name="_wpnonce" value="([^"]+)"', form).group(1)
rm = [(k, v) for k, v in keep if k != "_wpnonce"] + [("_wpnonce", wpnonce), ("site_answers_api_key_remove", "1")]
s.post(BASE + "/wp-admin/options.php", data=rm, timeout=90)
check("Remove box clears the key", state()["key_len"] == 0)
setopt("site_answers_api_key", FAKE_KEY)
settings(greeting="", starters=["How much is shipping?", "What is your return policy?", "How do I clean a wool rug?"], daily_cap=300, ip_per_hour=1000)

# ---------------------------------------------------------------- Rebuild button (admin-post + nonce)
admin_html = page("/wp-admin/options-general.php?page=site-answers")
form = admin_html.split("admin-post.php", 1)[1]
rb_nonce = re.search(r'name="_wpnonce" value="([^"]+)"', form).group(1)
r = s.post(BASE + "/wp-admin/admin-post.php", data={"action": "site_answers_rebuild", "_wpnonce": rb_nonce}, timeout=120)
check("Rebuild button: redirect + notice with counts", "site_answers_rebuilt=done" in r.url and "Index rebuilt: 10 items in" in html.unescape(r.text), r.url)
r = s.post(BASE + "/wp-admin/admin-post.php", data={"action": "site_answers_rebuild", "_wpnonce": "bad"}, timeout=60)
check("Rebuild with a bad nonce is refused", r.status_code in (403, 400) or "link you followed has expired" in r.text, r.status_code)
gen = [o for o in state()["options"] if o.startswith("site_answers_shard_")]
check("old index generation dropped after rebuild (one shard left)", len(gen) == 1, gen)

# ---------------------------------------------------------------- incremental updates (queue + one cron event)
r = rest("sa-test/v1/edit", {"slug": "shipping", "append": "Same-day courier delivery in Larkfield costs $12 and arrives before 8 pm."}).json()
check("edit queues the post and schedules one cron event", r["queued"] == [r["id"]] and r["next"], r)
code, d, _ = ask("How much is same-day courier delivery in Larkfield?")
check("not searchable before the queue runs", not any("Larkfield" in x.get("passage", "") for x in d.get("sources", [])), d.get("mode"))
wait = max(0, r["next"] - time.time()) + 2
time.sleep(min(wait, 40))
rest("sa-test/v1/reset", {})  # drops the lock a failed loopback spawn left (Playground has no loopback)
s.get(BASE + "/wp-cron.php", timeout=90)  # WP-Cron runs the due event, as a server cron job or a visit would
time.sleep(2)
st = state()
via_cron = st["status"]["queued"] == 0
if not via_cron:
    rest("sa-test/v1/run-queue", {})
check("queued edit indexed by WP-Cron" if via_cron else "queued edit indexed by firing the scheduled hook (WP-Cron does not run inside Playground)",
      state()["status"]["queued"] == 0)
code, d, _ = ask("How much is same-day courier delivery in Larkfield?")
check("edited text is now answered", d["mode"] == "ai" and any("Larkfield" in x["passage"] for x in d["sources"]), d)

for label, edit, expect in (
    ("draft", {"status": "draft"}, 9), ("published again", {"status": "publish"}, 10),
    ("password-protected", {"password": "secret"}, 9), ("password removed", {"password": ""}, 10),
    ("Yoast noindex", {"noindex": True}, 9), ("noindex removed", {"noindex": False}, 10),
    ("trashed", {"trash": True}, 9),
):
    rest("sa-test/v1/edit", {"slug": "warranty", **edit})
    rest("sa-test/v1/run-queue", {})
    n = state()["status"]["posts"]
    check(f"warranty page {label}: {expect} items indexed", n == expect, n)
code, d, _ = ask("How long is the warranty on furniture?")
check("trashed page no longer used in answers", not any(x["url"].endswith("/warranty/") for x in d.get("sources", [])), d.get("sources"))

# ---------------------------------------------------------------- shortcode, front-end config
contact = page("/contact/")
check("[site_answers] shortcode renders the inline chat host", "data-site-answers-inline" in contact)
check("shortcode text not indexed", "[site_answers]" not in json.dumps(ask("What are your customer service hours?")[1]))
home = page("/")
cfg = re.search(r"window\.siteAnswers=(\{.*?\});", home)
check("front-end config printed, no secrets", cfg is not None and "key" not in json.loads(cfg.group(1)).keys() and FAKE_KEY not in home)
check("script loads deferred in the footer", re.search(r"<script[^>]+site-answers\.js[^>]+defer", home) is not None
      or re.search(r"<script[^>]+defer[^>]+site-answers\.js", home) is not None)

# ---------------------------------------------------------------- debug log
log = rest("sa-test/v1/debug-log").json()["log"]
mine = [l for l in log.splitlines() if "site-answers" in l or "Site_Answers" in l]
check("no PHP notice, warning or error from the plugin in debug.log", not mine, mine[:5])

# ---------------------------------------------------------------- uninstall (last)
if UNINSTALL:
    r = rest("sa-test/v1/uninstall", {}).json()
    check("uninstall: every site_answers option, shard and transient removed", r["after"] == [], r)
    check("uninstall: scheduled jobs cleared", r["cron_after"] == [], r["cron_after"])

OUT.mkdir(exist_ok=True)
port = BASE.rsplit(":", 1)[-1]
(OUT / f"e2e-rest-{port}.json").write_text(json.dumps({"php": st["php"], "wp": st["wp"], "results": results}, indent=1))
failed = [r for r in results if not r["ok"]]
print(f"\n{len(results) - len(failed)}/{len(results)} passed on PHP {st['php']}")
sys.exit(1 if failed else 0)
