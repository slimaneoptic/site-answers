/*! Site Answers chat widget. No libraries, no cookies, no storage: the conversation lives only in this page. */
(function () {
	'use strict';
	var C = window.siteAnswers;
	if (!C || !window.fetch || !document.querySelector) { return; }
	var T = C.i18n || {}, uid = 0;
	var ICON_CHAT = '<svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" focusable="false"><path fill="currentColor" d="M4 3h16a2 2 0 0 1 2 2v11a2 2 0 0 1-2 2H9.5L4 22v-4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z"/></svg>';
	var ICON_SEND = '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false"><path fill="currentColor" d="M3 20.5 21 12 3 3.5V10l12 2-12 2z"/></svg>';

	function el(tag, cls, text) {
		var e = document.createElement(tag);
		if (cls) { e.className = cls; }
		if (text != null) { e.textContent = text; }
		return e;
	}

	// JSON POST. Cross-origin calls (demo AI) use text/plain, so the browser sends no preflight and no cookies.
	function send(url, body, cross) {
		return fetch(url, {
			method: 'POST',
			headers: { 'Content-Type': cross ? 'text/plain;charset=UTF-8' : 'application/json' },
			body: JSON.stringify(body),
			credentials: cross ? 'omit' : 'same-origin'
		}).then(function (r) {
			return r.json().catch(function () { return {}; }).then(function (j) {
				if (!r.ok) {
					var e = new Error((j && (j.message || (j.error && j.error.message))) || T.error);
					e.status = r.status;
					throw e;
				}
				return j;
			});
		});
	}

	// Demo mode: WordPress finds the passages, the hosted demo AI writes the answer. No key in the browser.
	function askDemo(q, hist) {
		return send(C.rest + 'search', { question: q, history: hist }).then(function (r) {
			if (r.found || !r.foreign) { return r; }
			// Nothing found and the question is in another language: one try with a translated query.
			return send(C.demoEndpoint, { question: q, site_lang: r.site_lang, translate_query: true }, true).then(function (t) {
				return t && t.query ? send(C.rest + 'search', { question: q, history: hist, query: t.query }) : r;
			}, function () { return r; });
		}).then(function (r) {
			if (!r.found) { return r; }
			var sources = r.passages.map(function (p) { return { n: p.n, title: p.title, url: p.url, passage: p.text }; });
			return send(C.demoEndpoint, { question: q, passages: r.passages, history: hist, site_label: r.site_label, site_lang: r.site_lang }, true)
				.then(function (a) {
					if (a.not_found && C.contact) { a.contact = { title: C.contact.title, url: C.contact.url, label: T.contact }; }
					return a;
				}, function (e) {
					// No readable answer: the demo AI refused this site (it only serves the online demo) or is unreachable.
					return { answer: e && e.status && e.status !== 403 ? e.message : T.demoOnly, sources: sources, mode: 'extractive' };
				});
		});
	}

	function chat(host, inline) {
		var id = 'sa' + (++uid), hist = [], busy = false, chips = null, launch = null;
		var box = el('div', 'sa-box'), head = el('div', 'sa-head'), title = el('p', 'sa-title', C.title || '');
		var log = el('div', 'sa-log'), form = el('form', 'sa-form'), label = el('label', 'sa-sr', T.label);
		var input = el('input', 'sa-input'), btn = el('button', 'sa-send');
		box.id = id;
		title.id = id + 't';
		log.setAttribute('role', 'log');
		log.setAttribute('aria-live', 'polite');
		log.setAttribute('aria-labelledby', id + 't');
		input.id = label.htmlFor = id + 'q';
		input.type = 'text';
		input.maxLength = 500;
		input.dir = 'auto';
		input.autocomplete = 'off';
		input.placeholder = T.placeholder || '';
		btn.type = 'submit';
		btn.setAttribute('aria-label', T.send);
		btn.innerHTML = ICON_SEND;
		head.appendChild(title);
		form.appendChild(label);
		form.appendChild(input);
		form.appendChild(btn);
		box.appendChild(head);
		box.appendChild(log);
		box.appendChild(form);
		box.appendChild(el('p', 'sa-foot', T.footer));

		function bubble(cls, text) {
			var b = el('div', 'sa-msg ' + cls);
			b.dir = 'auto';
			if (text) { b.appendChild(el('p', '', text)); }
			log.appendChild(b);
			log.scrollTop = log.scrollHeight;
			return b;
		}

		// Answer text with [n] marks turned into links to the numbered sources; **bold** kept. Text only, never HTML.
		function render(b, res) {
			var byN = {};
			(res.sources || []).forEach(function (s) { byN[s.n] = s; });
			String(res.answer || '').split(/\n+/).forEach(function (line) {
				if (!line.trim()) { return; }
				var p = el('p');
				line.split(/(\[\d+(?:\s*,\s*\d+)*\]|\*\*[^*\n]+\*\*)/).forEach(function (part) {
					var m = /^\[([\d,\s]+)\]$/.exec(part);
					if (m) {
						m[1].split(',').forEach(function (n) {
							var s = byN[+n], a;
							if (!s) { return; }
							a = el('a', 'sa-cite', String(+n));
							a.href = s.url;
							a.title = s.title;
							p.appendChild(a);
						});
					} else if (/^\*\*[^*]+\*\*$/.test(part)) {
						p.appendChild(el('strong', '', part.slice(2, -2)));
					} else if (part) {
						p.appendChild(document.createTextNode(part));
					}
				});
				b.appendChild(p);
			});
			if (res.sources && res.sources.length) {
				var src = el('div', 'sa-src'), ul = el('ul');
				src.appendChild(el('p', 'sa-src-h', T.sources));
				res.sources.forEach(function (s) {
					var li = el('li'), a = el('a');
					li.dir = 'auto'; // An English page title stays left to right inside an Arabic answer.
					a.href = s.url;
					a.appendChild(el('span', 'sa-n', String(s.n)));
					a.appendChild(document.createTextNode(s.title));
					li.appendChild(a);
					if (s.passage) { li.appendChild(el('span', 'sa-pass', s.passage)); }
					ul.appendChild(li);
				});
				src.appendChild(ul);
				b.appendChild(src);
			}
			if (res.contact && res.contact.url) {
				var c = el('p', 'sa-contact'), ca = el('a', '', (res.contact.label || T.contact) + ' →');
				ca.href = res.contact.url;
				c.appendChild(ca);
				b.appendChild(c);
			}
		}

		function ask(q) {
			q = String(q || '').trim();
			if (!q || busy) { return; }
			busy = true;
			if (chips) { chips.parentNode.removeChild(chips); chips = null; }
			var asked = bubble('sa-user', q);
			input.value = '';
			var wait = bubble('sa-bot sa-wait');
			wait.innerHTML = '<span class="sa-dots" aria-hidden="true"><i></i><i></i><i></i></span>';
			wait.appendChild(el('span', 'sa-sr', T.thinking));
			box.setAttribute('aria-busy', 'true');
			var h = hist.slice(-6);
			(C.demo ? askDemo(q, h) : send(C.rest + 'ask', { question: q, history: h })).then(function (res) {
				log.removeChild(wait);
				render(bubble('sa-bot' + (res.mode === 'extractive' ? ' sa-note' : '')), res);
				log.scrollTop = asked.offsetTop - 8; // A long answer is read from its start, under the question.
				hist.push({ role: 'user', content: q }, { role: 'assistant', content: String(res.answer || '').slice(0, 1000) });
			}, function (e) {
				log.removeChild(wait);
				bubble('sa-bot sa-err', e && e.status ? e.message : T.error);
			}).then(function () {
				busy = false;
				box.removeAttribute('aria-busy');
			});
		}

		form.addEventListener('submit', function (e) { e.preventDefault(); ask(input.value); });
		bubble('sa-bot', C.greeting);
		if (C.starters && C.starters.length) {
			chips = el('div', 'sa-chips');
			C.starters.forEach(function (s) {
				var b = el('button', 'sa-chip', s);
				b.type = 'button';
				b.dir = 'auto';
				b.addEventListener('click', function () { ask(s); });
				chips.appendChild(b);
			});
			log.appendChild(chips);
		}

		if (inline) {
			box.className += ' sa-inline';
			host.appendChild(box);
			return;
		}
		var x = el('button', 'sa-x', '×');
		x.type = 'button';
		x.setAttribute('aria-label', T.close);
		head.appendChild(x);
		box.setAttribute('role', 'dialog');
		box.setAttribute('aria-labelledby', id + 't');
		box.hidden = true;
		launch = el('button', 'sa-launch');
		launch.type = 'button';
		launch.setAttribute('aria-label', T.open);
		launch.setAttribute('aria-expanded', 'false');
		launch.setAttribute('aria-controls', id);
		launch.innerHTML = ICON_CHAT;
		launch.appendChild(el('span', '', T.ask));
		function toggle(open) {
			box.hidden = !open;
			launch.setAttribute('aria-expanded', open ? 'true' : 'false');
			if (open) { input.focus(); } else { launch.focus(); }
		}
		launch.addEventListener('click', function () { toggle(box.hidden); });
		x.addEventListener('click', function () { toggle(false); });
		box.addEventListener('keydown', function (e) { if (e.key === 'Escape') { toggle(false); } });
		host.appendChild(box);
		host.appendChild(launch);
	}

	function mount() {
		var hosts = document.querySelectorAll('[data-site-answers-inline]'), i, root;
		for (i = 0; i < hosts.length; i++) { chat(hosts[i], true); }
		if (C.floating) {
			root = el('div', 'sa-root sa-float');
			document.body.appendChild(root);
			chat(root, false);
		}
	}
	if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', mount); } else { mount(); }
}());
