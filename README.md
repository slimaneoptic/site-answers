# Site Answers

A WordPress plugin that adds a chat to your site. It answers visitors from your own pages, posts and WooCommerce products, with numbered sources, and says so when the answer is not on your site. It replies in the visitor's language (tested with English, French and Arabic questions) and runs on your own AI key.

**Live demo:** [slimanechanchoul.com/wp-chatbot](https://slimanechanchoul.com/wp-chatbot/) opens a real WordPress site in your browser, with a fictional store.

## Install

1. Download `site-answers.zip` from the demo page (or zip the `site-answers` folder of this repository).
2. In WordPress: Plugins > Add New > Upload Plugin, then activate. The first index is built on activation.
3. Go to Settings > Site Answers, paste your AI key (OpenRouter by default), choose your contact page and save.
4. Try a few questions in the "Test a question" box, then open your site: the chat button sits in the bottom corner. The `[site_answers]` shortcode shows the chat inside a page.

To keep the key out of the database, put it in `wp-config.php`: `define( 'SITE_ANSWERS_API_KEY', 'your-key' );`. That key is only sent to OpenRouter, or to `SITE_ANSWERS_BASE_URL` if you define it.

Requires WordPress 6.4+ and PHP 7.4+ (tested on WordPress 7.1 with PHP 7.4 and 8.3, and on WordPress 6.4 with PHP 7.4).

## Settings

- Chat button on every page, brand colour, greeting, three starter questions, contact page.
- AI base URL, API key and model (any service with an OpenAI-compatible `/chat/completions` endpoint should work; OpenRouter is the one tested).
- Limits: questions per visitor per hour, AI answers per day for the whole site.
- Content types to index, a Rebuild button and an index status table.

## How it works

1. The index lives in your WordPress database (options, autoload off). Content is read as a logged-out visitor sees it, split into passages, and kept current by hooks, a short queue and a daily rebuild.
2. Retrieval runs in PHP: BM25+ ranking with a coverage cutoff, light stemming, French accent folding and Arabic normalisation. When nothing on the site matches, the chat says so, usually without calling the AI.
3. Only the best passages go to your AI service, which must answer from them and cite them. Your key stays on your server.

## Privacy

The plugin keeps no questions or chat history, sets no cookies and writes nothing to the browser's storage. For the per-visitor limit it keeps a salted hash of the IP address for one hour. Questions and matching passages go to the AI service you configure; see "External services" in `site-answers/readme.txt`.

## Limits

- You need an AI key; each answer is a paid request to your AI service. Without a key, visitors get the matching passages.
- Made for small sites: the whole index is read for each question (tested with up to 17 items and about 330 passages).
- Text kept only in custom fields is not indexed (the `site_answers_document_text` filter can add it).
- Members-only content is handled for Paid Memberships Pro, MemberPress, Restrict Content (Pro), WooCommerce Memberships and Members; for other gated content use the `site_answers_index_post` filter.

## Development

`tests/` holds the local test suites. They run against WordPress Playground CLI (`tests/serve.sh PORT PHP`, Node 22, `npm install` in `tools/`): REST and settings (`e2e_rest.py`), retrieval (`e2e_retrieval.py`), browser (`e2e_ui.py`, Playwright), WooCommerce (`e2e_woo.py`) and multisite (`e2e_multisite.py`). `python3 demo/build.py` builds the demo blueprints and the plugin zip.

## Licence

GPL-2.0-or-later. Built by [S'limane Chanchoul](https://slimanechanchoul.com).
