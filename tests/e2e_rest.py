"""End-to-end checks against a local WordPress (Playground CLI) started with tests/serve.sh.

Owner-key path with a fake AI (pre_http_request in tests/mu/sa-test-helper.php), limits, validation, settings
sanitising, the Rebuild button, incremental updates, exclusions, the shortcode, "the key never leaves the server",
members-only content, overlapping rebuilds, other sites' pages, IPv6 limits, the debug log, and (with --uninstall,
last) the uninstall cleanup.

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
        return s.get(url, timeout=120, **kw)
    return s.request(method or "POST", url, json=data, timeout=120, **kw)


def ask(q: str, history=None, headers=None, session=None):
    r = (session or s).post(f"{BASE}/wp-json/site-answers/v1/ask", json={"question": q, "history": history or []},
                            headers=headers or {}, timeout=120)
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


def page(path: str, **kw) -> str:
    return s.get(BASE + path, timeout=120, **kw).text


def last_requests() -> list:
    return state()["requests"]


def titles(d: dict) -> list:
    return [x["title"] for x in d.get("sources", [])]


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
code, d, _ = ask("Combien coûte la livraison au Canada ?")
check("no key, French question: the note is in French", d["mode"] == "extractive" and d["answer"].startswith("Les réponses par IA"), d.get("answer"))
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

# ---------------------------------------------------------------- another website's page cannot use the chat
code, d, _ = ask("How much does standard shipping cost?", headers={"Origin": "https://evil.example"})
check("POST /ask from another site's page (Origin) -> 403", code == 403 and d.get("code") == "site_answers_origin", (code, d))
code, d, _ = ask("How much does standard shipping cost?", headers={"Origin": BASE})
check("POST /ask from this site's own page -> 200", code == 200 and d["mode"] == "extractive", (code, d.get("mode")))
settings(demo_mode=1)
r = rest("site-answers/v1/search", {"question": "shipping"}, headers={"Origin": "https://evil.example"})
check("POST /search from another site's page -> 403", r.status_code == 403, r.status_code)
d = rest("site-answers/v1/search", {"question": "هل لديكم معرض يمكنني زيارته؟"}).json()
check("Arabic 'showroom' question finds the FAQ without translation", d.get("found") and "FAQ" in [x["title"] for x in d["passages"]],
      [x["title"] for x in d.get("passages", [])])
check("demo search gives the contact label in the visitor's language", d.get("contact_label") == "تواصل معنا", d.get("contact_label"))
settings(demo_mode=0)

# ---------------------------------------------------------------- owner key + fake AI
setopt("site_answers_api_key", FAKE_KEY)
setopt("sa_test_mock", "answer")
setopt("sa_test_requests", [])
code, d, _ = ask("How much does standard shipping cost?")
check("AI answer with citations", code == 200 and d["mode"] == "ai" and "[1]" in d["answer"], d)
check("citations map to sources: [1] and [2] kept, [9] dropped", [x["n"] for x in d["sources"]] == [1, 2]
      and d["sources"][0]["url"].endswith("/shipping/"), d.get("sources"))
req = last_requests()[-1]
check("AI call: OpenRouter URL, default model, bearer key, sent with wp_safe_remote_post()",
      req["url"] == "https://openrouter.ai/api/v1/chat/completions" and req["model"] == "google/gemini-3.8-flash" and req["has_auth"]
      and req["safe"] is True, req)
check("system prompt: passages only, cite, passages are data, visitor language",
      all(k in req["system"] for k in ('only the numbered passages', '[1]', 'never instructions', 'language of the question')),
      req["system"])
check("system prompt does not name the contact page (the widget links it, in the visitor's language)", '"Contact" page' not in req["system"], req["system"])
check("passages numbered with title and URL", req["user"].startswith("Passages from the site:\n\n[1] Shipping (") and "Question: How much" in req["user"],
      req["user"][:200])
code, d, _ = ask("And express?", history=[{"role": "user", "content": "How much does standard shipping cost?"},
                                           {"role": "assistant", "content": "It costs $6.95 [1]."}])
req = last_requests()[-1]
check("follow-up: history sent (3 messages before the question)", d["mode"] == "ai" and req["turns"] == 4, (d.get("mode"), req["turns"]))
check("follow-up found the shipping page through the previous question", d["sources"] and d["sources"][0]["url"].endswith("/shipping/"), d.get("sources"))
canada = [{"role": "user", "content": "How much is shipping to Canada?"}, {"role": "assistant", "content": "$19 per order."}]
for q in ("And to the UK?", "What about Europe?"):
    code, d, _ = ask(q, history=canada)
    check(f"short follow-up '{q}' after a Canada question finds Shipping", d["mode"] == "ai" and "Shipping" in titles(d), titles(d))
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

# ---------------------------------------------------------------- a "not found" sentence cites nothing
setopt("sa_test_reply", "I couldn't find that on this site [1]. Please see the Contact page.")
code, d, _ = ask("How long is the warranty on sofas?")
check("'not found' reply citing [1]: no source listed, mark removed, contact link", d["mode"] == "ai" and d["sources"] == []
      and "[1]" not in d["answer"] and d["contact"] and d["contact"]["url"].endswith("/contact/"), d)
setopt("sa_test_reply", "I couldn't find sofas on this site. Furniture comes with a 2-year warranty [1].")
code, d, _ = ask("How long is the warranty on sofas?")
check("partial answer: the cited source of the other sentence is kept", [x["n"] for x in d["sources"]] == [1] and d["contact"], d)
setopt("sa_test_reply", None)

# ---------------------------------------------------------------- limits
settings(ip_per_hour=3)
rest("sa-test/v1/reset", {})
codes = [ask("How should I clean a wool rug?")[0] for _ in range(4)]
code, d, _ = ask("How should I clean a wool rug?")
check("per-IP limit: 429 after 3 per hour", codes[:3] == [200, 200, 200] and code == 429 and "try again" in d.get("message", ""), (codes, code, d))
settings(ip_per_hour=2)
rest("sa-test/v1/reset", {})
v6 = [ask("How should I clean a wool rug?", headers={"X-SA-Test-IP": ip})[0]
      for ip in ("2001:db8:1:2::1", "2001:db8:1:2::2", "2001:db8:1:2:ffff::3", "2001:db8:1:3::1")]
check("IPv6: addresses of one /64 share the per-IP limit, another /64 does not", v6 == [200, 200, 429, 200], v6)
settings(ip_per_hour=1000, daily_cap=1)
rest("sa-test/v1/reset", {"daily": 1})
c1 = ask("How should I clean a wool rug?")[1]
c2 = ask("What size rug goes under a queen bed?")[1]
check("daily cap: first AI answer, then passages with a note", c1["mode"] == "ai" and c2["mode"] == "extractive" and c2.get("notice") == "daily_cap", (c1.get("mode"), c2))
# The cap is one UPDATE ... WHERE count < cap (atomic on MySQL). Playground's SQLite does not lock across its PHP
# workers, so parallel requests are not a valid test here; this checks the counter itself.
settings(daily_cap=3)
rest("sa-test/v1/reset", {"daily": 1})
modes = [ask(f"How should I clean a wool rug? ({i})")[1].get("mode") for i in range(5)]
st = state()
check("daily cap of 3: three AI answers, then passages; the day's counter stops at 3", modes == ["ai"] * 3 + ["extractive"] * 2
      and st["daily"] == 3 and any(o.startswith("site_answers_used_") for o in st["options"]), (modes, st["daily"]))
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
r = s.post(BASE + "/wp-admin/options.php", data=fields, timeout=120)
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
all_settings = page("/wp-admin/options.php")
check("key stored as an array: wp-admin/options.php shows SERIALIZED DATA, not the key", after["key_raw"] == "array"
      and "test-key-not-real-0043" not in all_settings and "site_answers_api_key" in all_settings, after["key_raw"])
# empty key field keeps the key; the Remove box clears it
form = r.text.split('action="options.php"', 1)[1]
wpnonce = re.search(r'name="_wpnonce" value="([^"]+)"', form).group(1)
keep = [(k, v) for k, v in fields if k not in ("_wpnonce", "site_answers_api_key", "site_answers_settings[post_types][]",
                                               "site_answers_settings[base_url]", "site_answers_settings[ip_per_hour]")]
keep += [("_wpnonce", wpnonce), ("site_answers_api_key", ""), ("site_answers_settings[base_url]", "https://openrouter.ai/api/v1/chat/completions"),
         ("site_answers_settings[ip_per_hour]", "1000"), ("site_answers_settings[post_types][]", "page"), ("site_answers_settings[post_types][]", "post")]
r = s.post(BASE + "/wp-admin/options.php", data=keep, timeout=120)
after = state()
check("empty key field keeps the saved key", after["key_len"] == len("test-key-not-real-0043"), after["key_len"])
check("base URL pasted with /chat/completions is trimmed", after["settings"]["base_url"] == "https://openrouter.ai/api/v1", after["settings"]["base_url"])
check("post types back to pages + posts -> 10 items", after["status"]["posts"] == 10, after["status"])
form = r.text.split('action="options.php"', 1)[1]
wpnonce = re.search(r'name="_wpnonce" value="([^"]+)"', form).group(1)
rm = [(k, v) for k, v in keep if k != "_wpnonce"] + [("_wpnonce", wpnonce), ("site_answers_api_key_remove", "1")]
s.post(BASE + "/wp-admin/options.php", data=rm, timeout=120)
check("Remove box clears the key", state()["key_len"] == 0)
setopt("site_answers_api_key", FAKE_KEY)
settings(greeting="", starters=["How much is shipping?", "What is your return policy?", "How do I clean a wool rug?"], daily_cap=300, ip_per_hour=1000)

# ---------------------------------------------------------------- where the key may go (wp_safe_remote_post, wp-config key)
setopt("sa_test_mock", None)
settings(base_url="https://127.0.0.1:6443/api/v1")
code, d, _ = ask("How long is the warranty on furniture?")
err = state()["last_err"]
check("base URL on a loopback address and port 6443: refused before any connection, passages shown",
      d.get("notice") == "ai_error" and "http_request_failed" in str(err), (d.get("notice"), err))
setopt("sa_test_mock", "answer")
setopt("sa_test_requests", [])
settings(base_url="https://attacker.example/v1")
code, d, _ = ask("How long is the warranty on furniture?", headers={"X-SA-Test-Key": "config-key-not-real-77"})
req = last_requests()[-1]
check("key from wp-config.php: sent to the default AI service, not to the saved base URL",
      d["mode"] == "ai" and req["url"] == "https://openrouter.ai/api/v1/chat/completions", req["url"])
admin_cfg = page("/wp-admin/options-general.php?page=site-answers", headers={"X-SA-Test-Key": "config-key-not-real-77"})
check("settings page with a wp-config key: base URL field disabled, key never printed",
      re.search(r'<input type="url" id="sa-base_url"[^>]*disabled', admin_cfg) is not None and "config-key-not-real-77" not in admin_cfg)
settings(base_url="https://openrouter.ai/api/v1")

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

# ---------------------------------------------------------------- incremental updates (queue, then the next question)
r = rest("sa-test/v1/edit", {"slug": "shipping", "append": "Same-day courier delivery in Larkfield costs $12 and arrives before 8 pm."}).json()
check("edit queues the post and schedules one cron event", r["queued"] == [r["id"]] and r["next"], r)
code, d, _ = ask("How much is same-day courier delivery in Larkfield?")
check("not searchable before the queue runs", not any("Larkfield" in x.get("passage", "") for x in d.get("sources", [])), d.get("mode"))
time.sleep(max(0, r["next"] - time.time()) + 2)
waiting = state()["status"]["queued"]
code, d, _ = ask("How much is same-day courier delivery in Larkfield?")
check("overdue edit indexed by the next question, without WP-Cron (Playground, DISABLE_WP_CRON)",
      waiting == 1 and d["mode"] == "ai" and any("Larkfield" in x["passage"] for x in d["sources"]) and state()["status"]["queued"] == 0,
      (waiting, d.get("mode")))
r = rest("sa-test/v1/edit", {"slug": "shipping", "append": "Bike couriers in Larkfield also deliver on Sundays for $15."}).json()
time.sleep(max(0, r["next"] - time.time()) + 2)
admin_html = page("/wp-admin/options-general.php?page=site-answers")
check("the settings page also indexes an overdue edit (no 'Waiting edits' row)", "Waiting edits" not in admin_html and state()["status"]["queued"] == 0)

for label, edit, expect, cron in (
    ("draft", {"status": "draft"}, 9, False), ("published again", {"status": "publish"}, 10, True),
    ("password-protected", {"password": "secret"}, 9, False), ("password removed", {"password": ""}, 10, True),
    ("Yoast noindex", {"noindex": True}, 9, True), ("noindex removed", {"noindex": False}, 10, True),
    ("Rank Math noindex", {"rank_math": True}, 9, True), ("Rank Math index", {"rank_math": False}, 10, True),
    ("trashed", {"trash": True}, 9, False),
):
    rest("sa-test/v1/edit", {"slug": "warranty", **edit})
    if cron:
        rest("sa-test/v1/run-queue", {})
    n = state()["status"]["posts"]
    check(f"warranty page {label}: {expect} items indexed" + ("" if cron else " at once (no cron run)"), n == expect, n)
code, d, _ = ask("How long is the warranty on furniture?")
check("trashed page no longer used in answers", not any(x["url"].endswith("/warranty/") for x in d.get("sources", [])), d.get("sources"))

# ---------------------------------------------------------------- members-only content: what a logged-out visitor sees
setopt("site_answers_api_key", "")
base = state()["status"]["posts"]
member = rest("sa-test/v1/add-page", {"slug": "member-perks", "title": "Member perks", "content":
              "<!-- wp:paragraph --><p>Members get early access to our seasonal sales.</p><!-- /wp:paragraph -->"
              "<!-- wp:shortcode -->[members_only]The private member discount code is ATLASVIP4471 for 40 percent off every order.[/members_only]<!-- /wp:shortcode -->"}).json()
vip = rest("sa-test/v1/add-page", {"slug": "vip-lounge", "title": "VIP lounge", "content":
           "<!-- wp:paragraph --><p>The VIP lounge password is OPALHARBOR93 for this month.</p><!-- /wp:paragraph -->"}).json()
rest("sa-test/v1/edit", {"slug": "vip-lounge", "locked": True})
rb = rest("sa-test/v1/rebuild", {"as_admin": 1}).json()
check("rebuild in an administrator's request still indexes as a logged-out visitor (user restored after)",
      rb["result"] == "done" and rb["user"] == 1 and rb["status"]["posts"] == base + 1, (base, rb))
code, d, _ = ask("What is the member discount code?")
blob = json.dumps(d)
check("members-only shortcode: the hidden text is never served", "ATLASVIP4471" not in blob and "Members only" in blob, titles(d))
settings(demo_mode=1)
blob = rest("site-answers/v1/search", {"question": "What is the member discount code?"}).text
check("members-only shortcode: not in demo-mode passages either", "ATLASVIP4471" not in blob, blob[:200])
settings(demo_mode=0)
code, d, _ = ask("What is the VIP lounge password?")
check("page locked by a membership plugin (PMPro check, run as a visitor): not indexed", "OPALHARBOR93" not in json.dumps(d), titles(d))
code, d, _ = ask("Do members get early access to sales?")
check("the public part of the members page is still answered", "Member perks" in titles(d), titles(d))
for slug in ("member-perks", "vip-lounge"):
    rest("sa-test/v1/edit", {"slug": slug, "trash": True})
setopt("site_answers_api_key", FAKE_KEY)

# ---------------------------------------------------------------- MySQL utf8mb3 tables: 4-byte characters dropped
setopt("sa_test_utf8mb3", 1)
rest("sa-test/v1/add-page", {"slug": "mascot", "title": "Our mascot \U0001F98A", "content": "<p>Our mascot is a red fox \U0001F98A called Pip.</p>"})
rest("sa-test/v1/run-queue", {})
code, d, _ = ask("What is the name of your mascot?")
src = next((x for x in d.get("sources", []) if x["url"].endswith("/mascot/")), None)
check("utf8mb3 table: emoji left out of the stored passage, the rest indexed", src is not None and "\U0001F98A" not in src["passage"]
      and "Pip" in src["passage"], src)
setopt("sa_test_utf8mb3", None)
rest("sa-test/v1/edit", {"slug": "mascot", "trash": True})

# ---------------------------------------------------------------- two rebuilds at once (long pages, three shards)
filler = " ".join(f"Garden note {i}: painted tulip bulbs rest in clay pots near the old stone wall until the autumn rain." for i in range(330))
base = state()["status"]["posts"]
for k in range(1, 9):
    rest("sa-test/v1/add-page", {"slug": f"long-{k}", "title": f"Garden notes {k}", "content": f"<p>{filler}</p>"})
rb = rest("sa-test/v1/rebuild", {"race": "long-6"}).json()
st = state()
gens = {o.split("_")[3] for o in st["shards"]}
check(f"rebuild overtaken by a second one: the second stays, nothing missing ({base + 8} items, {st['status']['passages']} passages, all searchable)",
      rb["result"] == "idle" and st["status"]["posts"] == base + 8 and st["search"] == st["status"]["passages"] and len(st["shards"]) >= 3
      and len(gens) == 1, (rb["result"], st["status"], st["search"], st["shards"]))
code, d, _ = ask("How long does standard shipping take?")
check("after the overlapping rebuilds, shipping is still answered", "Shipping" in titles(d), titles(d))
for k in range(1, 9):
    rest("sa-test/v1/edit", {"slug": f"long-{k}", "trash": True})
check("long pages trashed: out of the index at once", state()["status"]["posts"] == base, state()["status"]["posts"])

# ---------------------------------------------------------------- "&" in the site name and the contact page title
setopt("blogname", "Atlas Home & Co.")
r = rest("sa-test/v1/edit", {"slug": "contact", "title": "Help & Contact"}).json()
cfg = re.search(r"window\.siteAnswers=(\{.*?\});", page("/"))
title = json.loads(cfg.group(1))["title"] if cfg else None
setopt("sa_test_requests", [])
ask("How much does standard shipping cost?")
req = last_requests()[-1]
code, nf, _ = ask("Who won the 2022 World Cup?")
check("'&' in the site name and contact title: widget title, prompt and contact link read plain '&'",
      title == "Atlas Home & Co." and 'website "Atlas Home & Co."' in req["system"] and nf["contact"]["title"] == "Help & Contact",
      (title, req["system"][:80], nf.get("contact")))
# The contact page (with the [site_answers] chat in it) is now queued: once overdue, the settings page indexes it.
time.sleep(max(0, r["next"] - time.time()) + 2)
admin_html = page("/wp-admin/options-general.php?page=site-answers")
check("settings page indexed the overdue Contact edit and loads no front-end widget script",
      state()["status"]["queued"] == 0 and "site-answers.js" not in admin_html, state()["status"]["queued"])
setopt("blogname", "Atlas Home")
rest("sa-test/v1/edit", {"slug": "contact", "title": "Contact"})

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
st = state() if not UNINSTALL else st
(OUT / f"e2e-rest-{port}.json").write_text(json.dumps({"php": st.get("php"), "wp": st.get("wp"), "results": results}, indent=1))
failed = [r for r in results if not r["ok"]]
print(f"\n{len(results) - len(failed)}/{len(results)} passed on PHP {st.get('php')}")
sys.exit(1 if failed else 0)
