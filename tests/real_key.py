"""A few real questions on the owner-key path (the plugin calls OpenRouter itself, from PHP-WASM).

The key comes from the SA_REAL_KEY environment variable and travels only in a request header to the local test
WordPress (tests/mu/sa-test-helper.php defines SITE_ANSWERS_API_KEY for that request): it is never stored in the
database, a file or the output. The helper counts the real calls and refuses any call past the budget given here.

Run: SA_REAL_KEY=... python -I tests/real_key.py http://127.0.0.1:9400 [arabic|first]
"""
from __future__ import annotations

import json
import os
import sys
import time
from pathlib import Path

import requests

BASE = sys.argv[1].rstrip("/")
SET = sys.argv[2] if len(sys.argv) > 2 else "arabic"
KEY = os.environ["SA_REAL_KEY"]
QUESTIONS = {  # (question, pages it may cite, any of these in the answer)
    "first": [
        ("Can I pay in instalments?", ["/faq/"], ["4", "four", "instal"]),
        ("Est-ce que la poêle en fonte va au lave-vaisselle ?", ["/care-guide/"], ["lave-vaisselle", "jamais", "non", "Non"]),
        ("هل لديكم معرض يمكنني زيارته؟", ["/faq/", "/contact/"], ["لا", "الإنترنت", "عبر"]),
    ],
    "arabic": [  # an English site, Arabic questions: one the site answers with "no", two with numbers
        ("هل لديكم معرض يمكنني زيارته؟", ["/faq/", "/contact/"], ["لا", "الإنترنت", "عبر"]),
        ("كم تكلفة الشحن إلى كندا؟", ["/shipping/"], ["19", "١٩"]),
        ("ما مدة ضمان الأثاث؟", ["/warranty/"], ["2", "٢", "سنتان", "سنتين", "عامين", "عامان"]),
    ],
}[SET]
s = requests.Session()
s.get(BASE + "/", timeout=60)


def setopt(name, value):
    s.post(f"{BASE}/wp-json/sa-test/v1/set", json={"name": name, "value": value}, timeout=60)


setopt("sa_test_mock", None)
setopt("site_answers_api_key", "")
setopt("sa_test_real_calls", 0)
setopt("sa_test_call_budget", len(QUESTIONS))  # one answer each; a translation call would show as a failed answer
s.post(f"{BASE}/wp-json/sa-test/v1/reset", json={}, timeout=60)
out, ok_all = [], True
for q, paths, want in QUESTIONS:
    t = time.monotonic()
    r = s.post(f"{BASE}/wp-json/site-answers/v1/ask", json={"question": q}, headers={"X-SA-Test-Key": KEY}, timeout=90)
    d = r.json()
    ms = int((time.monotonic() - t) * 1000)
    cited = [x["url"] for x in d.get("sources", [])]
    ok = d.get("mode") == "ai" and any(u.endswith(p) for u in cited for p in paths) and any(w in d.get("answer", "") for w in want)
    ok_all &= ok
    print(("PASS " if ok else "FAIL ") + f"{q} ({ms} ms) -> {d.get('answer', '')[:200]!r} sources={[u.rsplit('/', 2)[-2] for u in cited]}")
    out.append({"q": q, "ms": ms, "ok": ok, **{k: d.get(k) for k in ("answer", "mode", "sources", "contact")}})
calls = s.get(f"{BASE}/wp-json/sa-test/v1/state", timeout=60).json().get("real_calls")
setopt("sa_test_call_budget", None)
print(f"real AI calls made: {calls}")
blob = json.dumps({"real_calls": calls, "answers": out}, ensure_ascii=False)
assert KEY not in blob
Path(__file__).resolve().parent.joinpath("out", f"real-key-{SET}.json").write_text(blob)
print(f"{sum(o['ok'] for o in out)}/{len(out)} passed")
sys.exit(0 if ok_all else 1)
