/* Site Answers settings page: colour picker and the "Test a question" box. */
(function ($) {
	'use strict';
	var S = window.siteAnswersAdmin || {};

	$(function () {
		$('.site-answers-color').wpColorPicker();
	});

	function el(tag, cls, text) {
		var e = document.createElement(tag);
		if (cls) { e.className = cls; }
		if (text != null) { e.textContent = text; }
		return e;
	}

	function show(out, res) {
		out.textContent = '';
		var mode = el('p', 'sa-mode');
		mode.appendChild(el('strong', '', S.mode + ': '));
		mode.appendChild(document.createTextNode((S.modes && S.modes[res.mode]) || res.mode));
		out.appendChild(mode);
		out.appendChild(el('h3', '', S.answer));
		var answer = el('div', 'sa-answer', res.answer);
		answer.dir = 'auto';
		out.appendChild(answer);
		if (res.contact && res.contact.url) {
			var c = el('p'), a = el('a', '', res.contact.label + ' (' + res.contact.title + ')');
			a.href = res.contact.url;
			c.appendChild(a);
			out.appendChild(c);
		}
		var d = res.debug || {};
		if (d.error) { out.appendChild(el('p', 'sa-error', S.aiError + ': ' + d.error)); }
		if (d.translated) { out.appendChild(el('p', '', S.query + ': ' + d.translated)); }
		out.appendChild(el('h3', '', S.passages));
		if (!d.passages || !d.passages.length) {
			out.appendChild(el('p', 'description', S.none));
			return;
		}
		var ol = el('ol', 'sa-passages');
		d.passages.forEach(function (p) {
			var li = el('li'), a = el('a', '', p.title);
			a.href = p.url;
			li.appendChild(a);
			li.appendChild(el('span', 'sa-score', ' ' + S.score + ' ' + p.score + ', ' + S.coverage + ' ' + Math.round(p.coverage * 100) + '%'));
			var t = el('p', 'sa-passage', p.text);
			t.dir = 'auto';
			li.appendChild(t);
			ol.appendChild(li);
		});
		out.appendChild(ol);
	}

	$(function () {
		var form = document.getElementById('site-answers-test');
		var out = document.getElementById('site-answers-test-result');
		if (!form || !out || !window.wp || !wp.apiFetch) { return; }
		form.addEventListener('submit', function (e) {
			e.preventDefault();
			var q = document.getElementById('sa-test-q').value.trim();
			var btn = document.getElementById('sa-test-submit');
			if (!q) { return; }
			btn.disabled = true;
			out.textContent = S.asking;
			wp.apiFetch({ path: '/site-answers/v1/test', method: 'POST', data: { question: q } }).then(function (res) {
				show(out, res);
			}, function (err) {
				out.textContent = (err && err.message) || S.failed;
			}).then(function () {
				btn.disabled = false;
			});
		});
	});
}(jQuery));
