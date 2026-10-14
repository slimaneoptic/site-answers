=== Site Answers ===
Contributors: slimaneoptic
Tags: chatbot, ai, search, woocommerce, faq
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A chat assistant that answers visitors from your own pages, posts and products, with numbered sources. Uses your own AI key.

== Description ==

Site Answers adds a chat button to your site. A visitor asks a question; the plugin finds the most relevant passages in your published content and asks an AI model to answer from those passages only, with numbered links to the pages it used. When the answer is not on your site, it says so and links your contact page.

* **Answers from your content only**: pages, posts and WooCommerce products (price, SKU, stock status, categories, attributes, size and descriptions).
* **Numbered sources** under every answer, linked to the pages.
* **Says when it does not know**: off-topic questions get "I couldn't find that on this site" and your contact page, without an AI call.
* **English, French and Arabic**: a question in another language than the site is translated for the search, and the answer comes back in the visitor's language. Arabic reads right to left.
* **Runs inside WordPress**: the search index lives in your database and the AI call goes from your server to the AI service you choose (any OpenAI-compatible API; OpenRouter by default). No extra hosting.
* **Your key stays on the server**: it is never shown again and never sent to the browser.
* **Limits that protect your AI bill**: questions per visitor per hour and AI answers per day for the whole site.
* **Works without a key too**: visitors then get the most relevant passages with links.
* **Light front end**: plain JavaScript and CSS (about 14 KB together), no libraries, no cookies; works with the keyboard and has labels for screen readers.
* **Shortcode**: `[site_answers]` shows the same chat inside any page.

= How it works =

1. Published content is split into passages of about 900 characters, each carrying its page title. The index is stored in the options table (autoload off), so it works on MySQL and SQLite alike.
2. A question is matched against the passages with BM25+ ranking. French accents are folded and Arabic letter forms normalised. Passages that do not cover enough of the question are dropped, so an off-topic question finds nothing.
3. The best passages (up to five) go to the AI, with instructions to answer only from them, cite them like [1], treat them as data and reply in the visitor's language.
4. The index stays current: a saved, unpublished, trashed or deleted post is re-indexed by one WP-Cron event shortly after, and a full rebuild runs once a day. Settings > Site Answers has a Rebuild button and a box to test questions.

= Privacy =

Site Answers stores no visitor data by default: no question log, no chat history on the server, no cookies and nothing in the browser's storage. The conversation lives only in the open page.

To enforce the per-visitor limit, the plugin keeps a salted hash of the visitor's IP address with a counter for at most one hour. The daily limit stores one number per day.

When a visitor asks a question, your server sends the question, the last few turns of the conversation and the matching passages of your content to the AI service you configured, under that service's terms. Mention it in your privacy policy.

Demo mode (for the public online demo only) is different: the visitor's browser sends the question and the passages to the hosted demo AI. Leave it off on your own site.

= Limits =

* The index reads the post content, the excerpt and WooCommerce product data. Text that only exists in custom fields or is rendered by dynamic blocks is not indexed; developers can add it with the `site_answers_document_text` filter.
* The whole index is read for each question. It is meant for sites with up to a few thousand pages or products.
* Edits are picked up by WP-Cron, so on a site with no visits and no server cron they wait for the next visit.

== Installation ==

1. Upload the zip in Plugins > Add New > Upload Plugin (or copy the `site-answers` folder to `/wp-content/plugins/`) and activate it. The first index is built on activation.
2. Go to Settings > Site Answers.
3. Paste your API key (for example from OpenRouter), choose your contact page and save.
4. Ask a few questions in the "Test a question" box, then open your site: the chat button sits in the bottom corner.

To keep the key out of the database, add this line to wp-config.php instead: `define( 'SITE_ANSWERS_API_KEY', 'your-key' );`

== Frequently Asked Questions ==

= Which AI services work? =

Any service with an OpenAI-compatible `/chat/completions` endpoint over HTTPS. The default base URL is `https://openrouter.ai/api/v1` with the model `google/gemini-3.8-flash`; you can change both.

= What does it cost? =

The plugin is free. Each AI answer is one request to your AI service with the question and up to five passages, billed by that service. Off-topic questions make no AI call. The daily limit caps the total.

= What happens when a question is not covered by my pages? =

The chat says it could not find that on the site and links the contact page you chose.

= Can I keep some pages out? =

Password-protected, private and draft content is never indexed, nor are pages set to noindex in Yoast SEO or Rank Math, or WooCommerce products hidden from the catalog. Choose the content types in the settings; developers can use the `site_answers_index_post` filter.

= My site is behind a proxy or CDN. =

The per-visitor limit uses the connection's address (REMOTE_ADDR). If a trusted proxy puts the visitor's address in a header, return it with the `site_answers_client_ip` filter. Never trust a header that visitors can set themselves.

== Developers ==

Filters: `site_answers_post_types`, `site_answers_index_post`, `site_answers_document_text`, `site_answers_system_prompt`, `site_answers_ai_request_body`, `site_answers_client_ip`, `site_answers_queue_delay`, `site_answers_demo_endpoint`.

REST: `POST /wp-json/site-answers/v1/ask` with `{ "question": "...", "history": [ { "role": "user", "content": "..." } ] }` returns `{ "answer", "sources": [ { "n", "title", "url", "passage" } ], "mode", "contact" }`. `mode` is `ai`, `extractive` (passages only) or `not_found`.

== Screenshots ==

1. The chat answering from the store's pages, with numbered sources.
2. An Arabic question on an English site: the answer comes back in Arabic, right to left.
3. Settings > Site Answers: index status, Rebuild button and the test box with the passages used.

== Changelog ==

= 1.0.0 =
* First release.
