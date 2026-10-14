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
* **Says when it does not know**: off-topic questions get "I couldn't find that on this site" and your contact page, usually without an AI call.
* **English, French and Arabic**: the answer comes back in the visitor's language (tested on an English site with French and Arabic questions). A question in another language than the site is matched through common shop words, or translated once for the search. Arabic reads right to left.
* **Runs inside WordPress**: the search index lives in your database and the AI call goes from your server to your AI service (OpenRouter by default). No extra hosting.
* **Your key stays on the server**: it is never shown again and never sent to visitors' browsers.
* **Limits that protect your AI bill**: questions per visitor per hour and AI answers per day for the whole site. Other websites' pages cannot use your chat.
* **Works without a key too**: visitors then get the most relevant passages with links.
* **Light front end**: plain JavaScript and CSS, no libraries, no cookies; works with the keyboard and has labels for screen readers.
* **Shortcode**: `[site_answers]` shows the same chat inside any page.

= How it works =

1. Published content is read as a logged-out visitor sees it (blocks and shortcodes rendered for a visitor who is not logged in), split into passages of about 900 characters, each carrying its page title. The index is stored in the options table (autoload off), so it needs no extra tables (tested on SQLite).
2. A question is matched against the passages with BM25+ ranking. French accents are folded and Arabic letter forms normalised. Passages that do not cover enough of the question are dropped, so most off-topic questions find nothing.
3. The best passages (up to five) go to the AI, with instructions to answer only from them, cite them like [1], treat them as data and reply in the visitor's language.
4. The index stays current: a saved post is re-indexed by one WP-Cron event about 15 seconds later (or at the next question, where WP-Cron does not run); an unpublished, private, trashed or deleted post leaves the answers at once; a full rebuild runs once a day. Settings > Site Answers has a Rebuild button and a box to test questions.

= Privacy =

Site Answers keeps no questions or chat history, sets no cookies and writes nothing to the browser's storage. The conversation lives only in the open page.

To enforce the per-visitor limit, the plugin keeps a salted hash of the visitor's IP address (an IPv6 address counts as its /64 network) with a counter for one hour; WordPress deletes expired entries in its daily cleanup. The daily limit stores one number per day.

When a visitor asks a question, your server sends it to the AI service you configured; see External services. Mention it in your privacy policy.

Demo mode (for the public online demo only) is different: the visitor's browser sends the question and the passages to the hosted demo AI. Leave it off on your own site.

= Limits =

* The index reads the post content (blocks and shortcodes rendered), the excerpt and WooCommerce product data. Text that only exists in custom fields is not indexed; developers can add it with the `site_answers_document_text` filter.
* Members-only content: pages locked by Paid Memberships Pro, MemberPress, Restrict Content (Pro), WooCommerce Memberships or Members are not indexed, and members-only blocks and shortcodes are read as a logged-out visitor sees them. Other gated content (courses, customer-only pages of other plugins) can be kept out with the `site_answers_index_post` filter.
* The whole index is read for each question, so it suits small sites (tested with up to 17 items and about 330 passages; larger sites are not load-tested yet).
* Edits are indexed by WP-Cron; where WP-Cron does not run, at the next question or when an administrator opens Settings > Site Answers.

== External services ==

Site Answers uses an AI service to write answers from your own content.

= The AI service you configure (default: OpenRouter) =

Used only after you save an API key or define SITE_ANSWERS_API_KEY. When a visitor's question matches your content, your server sends the question, up to three earlier chat turns, up to five passages of your published content with their titles and addresses, and your site's name and address to {base URL}/chat/completions (default https://openrouter.ai/api/v1). A question in another language than the site may first be sent alone, with a list of words your site uses, to be translated for the search. The test box in Settings > Site Answers sends the same. Nothing is sent without a key.
OpenRouter terms: https://openrouter.ai/terms - privacy policy: https://openrouter.ai/privacy

= Site Answers demo AI (demo mode only) =

Used only when Demo mode is ticked (off by default; meant for the online demo on playground.wordpress.net). The visitor's browser sends the question, up to three earlier turns, the matching passages and the site's name and language to https://site-chatbot-omega.vercel.app/api/answer, which asks an AI model through OpenRouter. Privacy policy: https://slimanechanchoul.com/privacy/

== Installation ==

1. Upload the zip in Plugins > Add New > Upload Plugin (or copy the `site-answers` folder to `/wp-content/plugins/`) and activate it. The first index is built on activation (on a network, each site builds its own at its first question or cron run).
2. Go to Settings > Site Answers.
3. Paste your API key (for example from OpenRouter), choose your contact page and save.
4. Ask a few questions in the "Test a question" box, then open your site: the chat button sits in the bottom corner.

To keep the key out of the database, add this line to wp-config.php instead: `define( 'SITE_ANSWERS_API_KEY', 'your-key' );`. A key set there is only sent to OpenRouter, or to the address you set next to it: `define( 'SITE_ANSWERS_BASE_URL', 'https://your-service.example/v1' );`. On a network, every site shares that key, and only network administrators can change the model and the limits.

== Frequently Asked Questions ==

= Which AI services work? =

Tested with OpenRouter: the default base URL is `https://openrouter.ai/api/v1` with the model `google/gemini-3.8-flash`, and you can change both. Other services with an OpenAI-compatible `/chat/completions` endpoint should work with a model that accepts `max_tokens` and `temperature`; developers can change the request with the `site_answers_ai_request_body` filter. The address must be public HTTPS on port 443, 80 or 8080; a model on your own network can be allowed with the `http_request_host_is_external` filter (and `http_allowed_safe_ports` for another port).

= What does it cost? =

The plugin is free. Each AI answer is one request to your AI service with the question and up to five passages, billed by that service (two requests when a question in another language than the site must be translated for the search first). Most off-topic questions make no AI call. The daily limit caps the total.

= What happens when a question is not covered by my pages? =

The chat says it could not find that on the site and links the contact page you chose.

= Can I keep some pages out? =

Password-protected, private and draft content is never indexed, nor are pages set to noindex in Yoast SEO or Rank Math, WooCommerce products hidden from the catalog, the WooCommerce cart, checkout and account pages, and pages locked by the membership plugins listed under Limits. Choose the content types in the settings; developers can use the `site_answers_index_post` filter.

= My site is behind a proxy or CDN. =

The per-visitor limit uses the connection's address (REMOTE_ADDR). The best fix is your web server's real-IP module (nginx real_ip, Apache mod_remoteip) set to your proxy's address ranges. Otherwise return the visitor's address with the `site_answers_client_ip` filter, only from a header your proxy sets and only when REMOTE_ADDR is that proxy: visitors can send any header themselves.

== Developers ==

Filters: `site_answers_post_types`, `site_answers_index_post`, `site_answers_document_text`, `site_answers_shortcodes_keep_text`, `site_answers_system_prompt`, `site_answers_ai_request_body`, `site_answers_client_ip`, `site_answers_allowed_origins`, `site_answers_queue_delay`, `site_answers_demo_endpoint`, `site_answers_demo_origins`.

REST: `POST /wp-json/site-answers/v1/ask` with `{ "question": "...", "history": [ { "role": "user", "content": "..." } ] }` returns `{ "answer", "sources": [ { "n", "title", "url", "passage" } ], "mode", "contact" }`. `mode` is `ai`, `extractive` (passages only) or `not_found`. A browser request sent from another website's page gets 403; add your other domains with `site_answers_allowed_origins`.

== Screenshots ==

1. The chat answering from the store's pages, with numbered sources.
2. An Arabic question on an English site: the answer comes back in Arabic, right to left.
3. Settings > Site Answers: index status, Rebuild button and the test box with the passages used.

== Changelog ==

= 1.0.0 =
* First release.
