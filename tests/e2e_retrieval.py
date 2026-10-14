"""Retrieval only (no AI): natural customer questions, short follow-ups and off-topic questions on the Atlas Home demo.

Each question must bring the page that answers it among the passages sent to the AI; off-topic questions should find
nothing. Thresholds: 31 of 33 natural questions, 6 of 7 follow-ups, and at least 11 of 15 off-topic questions with
nothing found. Four off-topic questions ("price of Bitcoin", "president of the United States", "time in Tokyo",
"sell cars") share one word with a page and reach the AI, which then says it could not find it (one AI call spent);
that was already so before the synonym groups. The groups come from common shop wording; check them on a second
site before relying on them.

Run: python -I tests/e2e_retrieval.py http://127.0.0.1:9400
"""
from __future__ import annotations

import json
import sys
from pathlib import Path

import requests

BASE = sys.argv[1].rstrip("/")
OUT = Path(__file__).resolve().parent / "out"
TITLE = {"shipping": "Shipping", "home": "Everyday home goods, made to last", "returns": "Returns and refunds",
         "warranty": "Warranty", "care": "Care guide", "faq": "FAQ", "contact": "Contact", "about": "About us",
         "rug": "How to choose the right rug size", "test": "How we test every product before it goes on sale"}
NATURAL = [  # question, pages that answer it
    ("How long will my order take to arrive in New York?", ["shipping"]),
    ("Is shipping free?", ["shipping", "home"]),
    ("I ordered an 8x10 rug, how is it delivered?", ["shipping"]),
    ("Do you deliver to PO boxes?", ["shipping"]),
    ("My parcel arrived damaged, what should I do?", ["shipping"]),
    ("How much is express shipping?", ["shipping"]),
    ("Can I send something back if I don't like it?", ["returns"]),
    ("How long does a refund take?", ["returns"]),
    ("Who pays for return shipping?", ["returns"]),
    ("Can I return a monogrammed pillowcase?", ["returns"]),
    ("Can I exchange a duvet cover for a bigger size?", ["returns"]),
    ("My lamp stopped working after 18 months, what can I do?", ["warranty"]),
    ("Does the warranty cover scratches on my oak table?", ["warranty"]),
    ("Is the warranty valid if my dog chewed the table?", ["warranty"]),
    ("How do I make a warranty claim?", ["warranty"]),
    ("Can I put the skillet in the dishwasher?", ["care"]),
    ("How hot can the stoneware go in the oven?", ["care"]),
    ("How do I wash linen sheets?", ["care"]),
    ("Why is my new rug shedding?", ["care"]),
    ("How do I keep brass shiny?", ["care"]),
    ("Can I pay with PayPal?", ["faq"]),
    ("Do I need to create an account?", ["faq"]),
    ("Can I cancel my order?", ["faq"]),
    ("Do you offer discounts?", ["faq"]),
    ("Can I get free fabric samples?", ["faq"]),
    ("Can I add a gift message?", ["faq"]),
    ("Can I visit your store?", ["faq", "contact"]),
    ("Is there a phone number?", ["contact"]),
    ("Are you open on Sunday?", ["contact"]),
    ("What rug size for a dining table for six?", ["rug"]),
    ("How do you test your chairs?", ["test"]),
    ("Where are your products made?", ["about"]),
    ("How many people work at Atlas Home?", ["about"]),
]


def turn(q, a):
    return [{"role": "user", "content": q}, {"role": "assistant", "content": a}]


FOLLOW = [
    (turn("How much is shipping to Canada?", "$19 per order."), "And to the UK?", ["shipping"]),
    (turn("How much is shipping to Canada?", "$19 per order."), "What about Europe?", ["shipping"]),
    (turn("How much is shipping to Canada?", "$19 per order."), "How long does it take?", ["shipping"]),
    (turn("How long is the warranty on furniture?", "2 years."), "And for rugs?", ["warranty"]),
    (turn("How long is the warranty on furniture?", "2 years."), "Does it cover scratches?", ["warranty"]),
    (turn("Can I return an item?", "Yes, within 30 days."), "Even if I used it?", ["returns"]),
    (turn("Can I return an item?", "Yes, within 30 days."), "How do I start?", ["returns"]),
]
OFF = ["Who won the 2022 World Cup?", "Can you recommend a good pizza recipe?", "What is the capital of France?",
       "How do I fix my car engine?", "Write me a poem about the sea", "What's the weather tomorrow?",
       "Can you help me with my maths homework?", "How much is a Tesla?", "What is the price of Bitcoin?",
       "Who is the president of the United States?", "Tell me a joke", "How do I learn Python?",
       "What time is it in Tokyo?", "Do you sell cars?", "Is it going to rain in London?"]

s = requests.Session()
s.get(BASE + "/", timeout=120)  # Playground auto-login cookie first, so POSTs are not redirected


def rest(path, data):
    return s.post(f"{BASE}/wp-json/{path}", json=data, timeout=120).json()


rest("sa-test/v1/set", {"name": "sa_test_mock", "value": None})
rest("sa-test/v1/set", {"name": "site_answers_api_key", "value": ""})
rest("sa-test/v1/settings", {"demo_mode": 0, "enabled": 1, "ip_per_hour": 1000, "daily_cap": 300})
rest("sa-test/v1/reset", {})


def found(q, hist=None):
    return [x["title"] for x in rest("site-answers/v1/ask", {"question": q, "history": hist or []}).get("sources", [])]


out = {"natural": [], "follow": [], "off": []}
for q, want in NATURAL:
    t, w = found(q), [TITLE[x] for x in want]
    out["natural"].append({"q": q, "titles": t, "ok": any(x in w for x in t)})
for hist, q, want in FOLLOW:
    t, w = found(q, hist), [TITLE[x] for x in want]
    out["follow"].append({"q": q, "prev": hist[0]["content"], "titles": t, "ok": any(x in w for x in t)})
for q in OFF:
    t = found(q)
    out["off"].append({"q": q, "titles": t, "ok": not t})
summary = {k: f"{sum(r['ok'] for r in v)}/{len(v)}" for k, v in out.items()}
out["summary"] = summary
OUT.mkdir(exist_ok=True)
(OUT / f"e2e-retrieval-{BASE.rsplit(':', 1)[-1]}.json").write_text(json.dumps(out, indent=1, ensure_ascii=False))
for kind in ("natural", "follow", "off"):
    for r in out[kind]:
        if not r["ok"]:
            print(f"MISS {kind}: {r['q']} -> {r['titles']}")
nat, fol, off = (sum(r["ok"] for r in out[k]) for k in ("natural", "follow", "off"))
ok = nat >= 31 and fol >= 6 and off >= 11
print(("PASS" if ok else "FAIL") + f" retrieval: natural {summary['natural']}, follow-ups {summary['follow']}, off-topic not found {summary['off']}")
sys.exit(0 if ok else 1)
