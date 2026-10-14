"""The hosted engine (POST /api/answer) with a fake model, for the browser tests: no AI key, no cost, same answer
every run.

The engine code runs unchanged (validation, caps, citation mapping, "not found" handling, translate mode, CORS and
origin rules). Only its OpenRouter URL points to a fake OpenAI-compatible route in this same process, which answers
from the passages it gets: the line that shares most words with the question (plus the next line when the best one
is a heading), cited as [n], with a French or Arabic opening for French or Arabic questions.

Run with the engine's virtualenv:
  ENGINE_DIR=/path/to/site-chatbot /path/to/site-chatbot/.venv/bin/python tests/fake_engine.py PORT [--refuse]
  --refuse: origin rules that refuse every local page, like the hosted engine on a site that is not the demo.
"""
from __future__ import annotations

import json
import math
import os
import re
import sys

PORT = int(sys.argv[1])
REFUSE = "--refuse" in sys.argv
sys.path.insert(0, os.environ["ENGINE_DIR"])
os.environ["OPENROUTER_API_KEY"] = "fake-local-key"  # not a real key: every model call stays in this process
for cap in ("WP_IP_CAP", "WP_SESSION_CAP"):  # the test runs ask many questions from one address
    os.environ.setdefault(cap, "100000")
os.environ.setdefault("WP_DAILY_USD", "1000")
os.environ.setdefault("STARTER_ANSWERS", os.path.join(os.environ["ENGINE_DIR"], "no-starter-answers.json"))
if REFUSE:
    os.environ["CORS_ORIGINS"] = "https://refused.invalid"
    os.environ["CORS_ORIGIN_REGEX"] = r"^https://refused\.invalid$"
    os.environ["WP_DEMO_ORIGINS"] = "https://refused.invalid"

import uvicorn  # noqa: E402
from fastapi import Request  # noqa: E402
from fastapi.responses import JSONResponse, StreamingResponse  # noqa: E402

import llm  # noqa: E402

# Before api.main is imported: its module-level app checks the key at once, and must not reach OpenRouter.
llm.URL = f"http://127.0.0.1:{PORT}/__fake/v1/chat/completions"
llm.KEY_URL = f"http://127.0.0.1:{PORT}/__fake/v1/key"
from api import main  # noqa: E402

app = main.create_app(check_key=False)
llm.mark_key("ok")

STOP = set("""a an the and or of to in on at by for with from is are was be do does did i my me we our you your it its this
that what which who how when where why can could would should will there have has much many any some about long""".split())
# Known test questions in other languages -> English words to match the passages with (the real model reads them).
WORDS = {"livraison": "shipping canada", "إرجاع": "return days", "لإرجاع": "return days", "معرض": "showroom online",
         "شحن": "shipping canada", "الشحن": "shipping canada", "ضمان": "warranty furniture", "fonte": "cast iron dishwasher"}
OPENING = {"ar": "حسب صفحات الموقع: ", "fr": "D'après le site, la réponse est : "}


def words(text: str) -> set[str]:
    out = set()
    for w in re.findall(r"[a-z0-9$.]+", text.lower()):
        w = w.strip(".")
        if w and w not in STOP:
            out.add(w[:-1] if len(w) > 3 and w.endswith("s") else w)
    return out


def lang(question: str) -> str:
    if re.search(r"[؀-ۿ]", question):
        return "ar"
    return "fr" if re.search(r"\b(combien|est-ce|quel|quelle|livraison|vous|le|la|les|au)\b", question.lower()) else "en"


def answer(messages: list[dict]) -> str:
    system, user = messages[0]["content"], messages[-1]["content"]
    if system.startswith("Translate"):
        q = user.strip()
        return next((v for k, v in WORDS.items() if k in q), q)
    m = re.search(r"Pages from the site:\n\n(.*)\n\nQuestion: (.*)$", user, re.S)
    if not m:
        return "I couldn't find that on this site."
    block, question = m.group(1), m.group(2).strip()
    want = words(question) | words(" ".join(v for k, v in WORDS.items() if k in question))
    lines = []  # (passage number, title words, line, next line)
    for part in re.split(r"\n\n(?=\[\d+\] )", block):
        head = re.match(r"\[(\d+)\] ([^\n]*) \(", part)
        if not head:
            continue
        body = [x.strip() for x in part[part.index("\n") + 1:].split("\n") if x.strip() and not x.startswith("…")]
        for i, line in enumerate(body):
            lines.append((int(head.group(1)), words(head.group(2)), line, body[i + 1] if i + 1 < len(body) else ""))
    df: dict[str, int] = {}
    for _, _, line, _ in lines:
        for w in words(line):
            df[w] = df.get(w, 0) + 1
    idf = {w: math.log(1 + len(lines) / d) for w, d in df.items()}
    best = (0.0, "", 0)
    for n, title, line, after in lines:
        hit = want & words(line)
        score = sum(idf.get(w, 0) for w in hit) + sum(idf.get(w, 1) for w in want & title) if hit else 0.0
        if score > best[0]:
            best = (score, line if len(line) >= 40 or not after else line + " " + after, n)
    if not best[0]:
        return "I couldn't find that on this site."
    return OPENING.get(lang(question), "") + f"{best[1]} [{best[2]}]"


@app.post("/__fake/v1/chat/completions")
async def fake_completions(request: Request):
    body = await request.json()
    text = answer(body.get("messages") or [])
    usage = {"prompt_tokens": 100, "completion_tokens": max(1, len(text) // 4), "cost": 0.0}
    if not body.get("stream"):
        return JSONResponse({"choices": [{"message": {"role": "assistant", "content": text}}], "usage": usage})

    def sse():
        for i in range(0, len(text), 12):
            yield "data: " + json.dumps({"choices": [{"delta": {"content": text[i:i + 12]}}]}) + "\n\n"
        yield "data: " + json.dumps({"choices": [], "usage": usage}) + "\n\n"
        yield "data: [DONE]\n\n"

    return StreamingResponse(sse(), media_type="text/event-stream")


if __name__ == "__main__":
    uvicorn.run(app, host="127.0.0.1", port=PORT, log_level="warning")
