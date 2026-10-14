"""A few real questions on the owner-key path (the plugin calls OpenRouter itself, from PHP-WASM).

The key comes from the SA_REAL_KEY environment variable and travels only in a request header to the local test
WordPress (tests/mu/sa-test-helper.php defines SITE_ANSWERS_API_KEY for that request): it is never stored in the
database, a file or the output.

Run: SA_REAL_KEY=... python -I tests/real_key.py http://127.0.0.1:9400
"""
from __future__ import annotations

import json
import os
import sys
import time
from pathlib import Path

import requests

BASE = sys.argv[1].rstrip("/")
KEY = os.environ["SA_REAL_KEY"]
QUESTIONS = [  # (question, page it must cite, any of these in the answer)
    ("Can I pay in instalments?", "/faq/", ["4", "four", "instal"]),
    ("Est-ce que la poêle en fonte va au lave-vaisselle ?", "/care-guide/", ["lave-vaisselle", "jamais", "non", "Non"]),
    ("هل لديكم معرض يمكنني زيارته؟", "/faq/", ["لا", "الإنترنت", "عبر"]),
]
s = requests.Session()
s.get(BASE + "/", timeout=60)
s.post(f"{BASE}/wp-json/sa-test/v1/set", json={"name": "sa_test_mock", "value": None}, timeout=60)
s.post(f"{BASE}/wp-json/sa-test/v1/set", json={"name": "site_answers_api_key", "value": ""}, timeout=60)
s.post(f"{BASE}/wp-json/sa-test/v1/reset", json={}, timeout=60)
out, ok_all = [], True
for q, path, want in QUESTIONS:
    t = time.monotonic()
    r = s.post(f"{BASE}/wp-json/site-answers/v1/ask", json={"question": q}, headers={"X-SA-Test-Key": KEY}, timeout=90)
    d = r.json()
    ms = int((time.monotonic() - t) * 1000)
    ok = d.get("mode") == "ai" and any(x["url"].endswith(path) for x in d.get("sources", [])) and any(w in d.get("answer", "") for w in want)
    ok_all &= ok
    print(("PASS " if ok else "FAIL ") + f"{q} ({ms} ms) -> {d.get('answer', '')[:160]!r} sources={[x['url'].rsplit('/', 2)[-2] for x in d.get('sources', [])]}")
    out.append({"q": q, "ms": ms, "ok": ok, **{k: d.get(k) for k in ("answer", "mode", "sources", "contact")}})
blob = json.dumps(out, ensure_ascii=False)
assert KEY not in blob
Path(__file__).resolve().parent.joinpath("out", "real-key.json").write_text(blob)
sys.exit(0 if ok_all else 1)
