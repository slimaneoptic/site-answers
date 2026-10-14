"""WooCommerce checks (local test site from tests/blueprint-woo.json): products are indexed with price, sale price,
SKU, stock status, categories, attributes and size; a hidden product is not; price and stock changes are re-indexed.

Run: python -I tests/e2e_woo.py http://127.0.0.1:9402
"""
from __future__ import annotations

import json
import sys
from pathlib import Path

import requests

BASE = sys.argv[1].rstrip("/")
s = requests.Session()
s.get(BASE + "/", timeout=120)
results = []


def check(name, ok, detail=""):
    results.append({"name": name, "ok": bool(ok), "detail": str(detail)[:400]})
    print(("PASS " if ok else "FAIL ") + name + ("" if ok else f"  -- {str(detail)[:300]}"))


def rest(path, data=None):
    url = f"{BASE}/wp-json/{path}"
    return (s.post(url, json=data, timeout=90) if data is not None else s.get(url, timeout=90)).json()


def passages(q):
    d = rest("site-answers/v1/search", {"question": q})
    return d.get("passages", [])


rest("sa-test/v1/settings", {"demo_mode": 1, "ip_per_hour": 1000, "daily_cap": 1000})
rest("sa-test/v1/run-queue", {})
st = rest("sa-test/v1/state")["status"]
check("5 products indexed, the hidden one skipped", st["types"].get("product") == 5, st)

cases = [
    ("How much is the wool rug?", "Hand-loomed wool rug", ["$159.00 (on sale, regular price $189.00)"]),
    ("What is the SKU of the cast iron skillet?", "Cast iron skillet 26 cm", ["SKU: AH-CI-26"]),
    ("Is the cast iron skillet in stock?", "Cast iron skillet 26 cm", ["Availability: Out of stock"]),
    ("What sizes does the linen duvet cover come in and what does it cost?", "Washed linen duvet cover", ["Size: Queen, King", "$149.00 to $169.00"]),
    ("Which colours does the stoneware dinner plate come in?", "Stoneware dinner plate", ["Colour: Sand, Charcoal", "Categories: Kitchen and dining"]),
    ("How big and heavy is the wool rug?", "Hand-loomed wool rug", ["Dimensions: 305 × 244 × 1 cm", "Weight: 9 kg"]),
    ("Can I order the brass table lamp now?", "Brass table lamp", ["Availability: Available on backorder"]),
]
for q, title, want in cases:
    ps = passages(q)
    hit = next((p for p in ps if p["title"] == title), None)
    check(f"{q} -> {title}", hit is not None and all(w in hit["text"] for w in want), [(p["title"], p["text"][:200]) for p in ps])
check("hidden product never returned", not any("zanzibarite" in p["text"] or "Staff sample" in p["title"] for p in passages("zanzibarite staff sample")))

r = rest("sa-test/v1/woo-update", {"sku": "AH-LMP-BR", "price": "139"})
check("price change queues the product", r["id"] in (r["queued"] or []), r)
r = rest("sa-test/v1/woo-update", {"sku": "AH-CI-26", "stock": "instock"})
check("stock change queues the product", r["id"] in (r["queued"] or []), r)
rest("sa-test/v1/run-queue", {})
lamp = next((p for p in passages("How much is the brass table lamp?") if p["title"] == "Brass table lamp"), {})
check("new price answered after the update", "Price: $139.00" in lamp.get("text", ""), lamp)
pan = next((p for p in passages("Is the cast iron skillet in stock?") if p["title"].startswith("Cast iron")), {})
check("new stock status answered after the update", "Availability: In stock" in pan.get("text", ""), pan)
log = rest("sa-test/v1/debug-log")["log"]
mine = [l for l in log.splitlines() if "site-answers" in l or "Site_Answers" in l]
check("no PHP notice from the plugin with WooCommerce active", not mine, mine[:3])
Path(__file__).resolve().parent.joinpath("out", "e2e-woo.json").write_text(json.dumps(results, indent=1))
failed = [x for x in results if not x["ok"]]
print(f"\n{len(results) - len(failed)}/{len(results)} passed")
sys.exit(1 if failed else 0)
