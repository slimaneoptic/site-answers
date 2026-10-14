"""Multisite checks against a local network started with:
  BLUEPRINT=tests/blueprint-multisite.json tests/serve.sh PORT 8.3
(sites: / and /shop/ with a Returns page; the plugin is mounted but not active).

Network activation sets every site up (options, daily rebuild) on its next request, a site created later too, and a
site without an index builds it at the first question even when WP-Cron does not run. Network deactivation clears
the scheduled jobs of every site. With a network-wide key in wp-config.php, a site administrator cannot change the
AI base URL, model or limits (a network administrator can).

Run: python -I tests/e2e_multisite.py http://127.0.0.1:9405
"""
from __future__ import annotations

import json
import sys
import time
from pathlib import Path

import requests

BASE = sys.argv[1].rstrip("/")
OUT = Path(__file__).resolve().parent / "out"
results: list[dict] = []
s = requests.Session()
s.get(BASE + "/", timeout=120)  # Playground auto-login cookie


def check(name: str, ok: bool, detail="") -> bool:
    results.append({"name": name, "ok": bool(ok), "detail": str(detail)[:400]})
    print(("PASS " if ok else "FAIL ") + name + ("" if ok else f"  -- {str(detail)[:300]}"))
    return bool(ok)


def rest(site: str, route: str, data=None, headers=None):
    url = f"{BASE}{site}?rest_route=/{route.lstrip('/')}"
    r = s.post(url, json=data, headers=headers or {}, timeout=120) if data is not None else s.get(url, headers=headers or {}, timeout=120)
    try:
        return r.json()
    except ValueError:
        return {"_status": r.status_code, "_text": r.text[:300]}


def daily(crons: list) -> bool:
    return any(c.startswith("site_answers_daily_rebuild") for c in crons)


st = rest("/", "sa-test/v1/state")
check("network starts with the plugin inactive", st.get("active") is False, st)
net = rest("/", "sa-test/v1/network", {"do": "activate"})
check("Network Activate: plugin active for the network", net["network_active"] is True, net)

main = rest("/", "sa-test/v1/state")
shop = rest("/shop/", "sa-test/v1/state")
check("main site set up on its next request: daily rebuild scheduled, key option autoload off",
      main.get("active") and daily(main["cron"]) and any(r["option_name"] == "site_answers_api_key" and r["autoload"] in ("off", "no")
                                                        for r in main["autoload"]), main.get("cron"))
check("/shop/ set up on its next request: daily rebuild scheduled", shop.get("active") and daily(shop["cron"]) and shop["blog"] == 2, shop.get("cron"))
check("/shop/ has no index yet (built at the next cron run or first question)", shop["status"]["posts"] == 0, shop["status"])

rest("/shop/", "sa-test/v1/settings", {"ip_per_hour": 1000})
d = rest("/shop/", "site-answers/v1/ask", {"question": "How many days do I have to return an item?"})
shop = rest("/shop/", "sa-test/v1/state")
check("/shop/: first question builds the missing index without WP-Cron and finds the Returns page",
      d.get("mode") == "extractive" and any(x["url"].endswith("/returns/") for x in d.get("sources", [])) and shop["status"]["posts"] >= 1,
      (d.get("mode"), [x.get("url") for x in d.get("sources", [])], shop["status"]))
check("/shop/: daily rebuild moved to tomorrow after that build (no second rebuild right away)",
      any(c.startswith("site_answers_daily_rebuild@") and int(c.split("@")[1]) > time.time() + 20 * 3600 for c in shop["cron"]),
      shop["cron"])

net = rest("/", "sa-test/v1/network", {"do": "site", "slug": "newsite"})
new = rest("/newsite/", "sa-test/v1/state")
check("site created after the network activation: set up on its first request", new.get("active") and daily(new["cron"])
      and new["blog"] == 3, (net.get("result"), new.get("cron")))

# A network-wide key in wp-config.php: only network administrators decide where it goes and how much it spends.
key = {"X-SA-Test-Key": "network-key-not-real-55"}
attack = {"base_url": "https://attacker.example/v1", "model": "vendor/most-expensive-model", "ip_per_hour": 1000,
          "daily_cap": 100000, "enabled": 1}
saved = rest("/shop/", "sa-test/v1/state")["settings"]
r = rest("/shop/", "sa-test/v1/sanitize", {"as": "site", "input": attack}, headers=key)
out = r.get("out", {})
check("site administrator (not network admin) + network key: base URL, model and limits keep their saved values",
      r.get("site_admin") and not r.get("network_admin")
      and all(out.get(k) == saved[k] for k in ("base_url", "model", "ip_per_hour", "daily_cap")), (saved, r))
check("the network key goes to the default service, whatever was saved", r.get("base_url") == "https://openrouter.ai/api/v1", r.get("base_url"))
r = rest("/shop/", "sa-test/v1/sanitize", {"as": "network", "input": attack}, headers=key)
check("network administrator can change them", r.get("network_admin") and r["out"]["model"] == "vendor/most-expensive-model"
      and r["out"]["daily_cap"] == 100000, r)
r = rest("/shop/", "sa-test/v1/sanitize", {"as": "site", "input": attack})
check("without a network key, a site administrator sets their own base URL and model (their own key)",
      r["out"]["base_url"] == "https://attacker.example/v1" and r["out"]["model"] == "vendor/most-expensive-model", r["out"])

edit = rest("/shop/", "sa-test/v1/edit", {"slug": "returns", "append": "Exchanges are free within 30 days."})
check("/shop/: an edit schedules the queue event", bool(edit.get("next")), edit)
net = rest("/", "sa-test/v1/network", {"do": "deactivate"})
left = {path: [c for c in crons if c.startswith("site_answers")] for path, crons in net["crons"].items()}
check("Network Deactivate: no scheduled job left on any site (3 sites)", len(left) == 3 and not any(left.values()), left)

OUT.mkdir(exist_ok=True)
(OUT / f"e2e-multisite-{BASE.rsplit(':', 1)[-1]}.json").write_text(json.dumps(results, indent=1))
failed = [r for r in results if not r["ok"]]
print(f"\n{len(results) - len(failed)}/{len(results)} passed (multisite)")
sys.exit(1 if failed else 0)
