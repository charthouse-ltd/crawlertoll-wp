/**
 * CrawlerToll admin scripts — curl tester, collapsible sections, bot filter.
 */
(function () {
	'use strict';

	// ── Collapsible sections ──────────────────────────────────
	document.querySelectorAll('.ct-collapse-toggle').forEach(function (btn) {
		btn.addEventListener('click', function () {
			var content = this.nextElementSibling;
			var isOpen = content.style.maxHeight !== '0px' && content.style.maxHeight !== '';
			if (isOpen) {
				content.style.maxHeight = '0px';
				this.classList.remove('open');
			} else {
				content.style.maxHeight = content.scrollHeight + 'px';
				this.classList.add('open');
			}
		});
		// Open initially if not collapsed
		var content = btn.nextElementSibling;
		if (content && content.style.maxHeight !== '0px') {
			content.style.maxHeight = content.scrollHeight + 'px';
			btn.classList.add('open');
		}
	});

	// ── Bot catalogue filter ──────────────────────────────────
	var botFilter = document.getElementById('ct-bot-filter');
	if (botFilter) {
		botFilter.addEventListener('input', function () {
			var query = this.value.toLowerCase();
			document.querySelectorAll('.ct-bot-chip').forEach(function (chip) {
				var name = (chip.querySelector('.ct-bot-name') || {}).textContent || '';
				var op   = (chip.querySelector('.ct-bot-op') || {}).textContent || '';
				chip.style.display = (name.toLowerCase().indexOf(query) !== -1 || op.toLowerCase().indexOf(query) !== -1) ? '' : 'none';
			});
		});
	}

	// ── Curl tester ────────────────────────────────────────────
	var curlBtn = document.getElementById('ct-curl-test-btn');
	var curlOutput = document.getElementById('ct-curl-output');
	if (curlBtn && curlOutput) {
		curlBtn.addEventListener('click', function () {
			var ua   = document.getElementById('ct-curl-ua').value.trim();
			var path = document.getElementById('ct-curl-path').value.trim() || '/';
			var site = document.getElementById('ct-curl-site').value.trim();

			if (!ua) {
				curlOutput.innerHTML = '<span style="color:#f87171">Error: select a User-Agent.</span>';
				return;
			}

			curlOutput.innerHTML = '<span style="color:#818cf8">Simulating…</span>';

			// Build the simulated curl command and expected output from the policy
			var policyText = document.getElementById('crawlertoll-policy').value;
			var isBot = false;
			var botName = '';
			var botOperator = '';
			var isDisallowed = false;
			var hasCompensation = false;

			// Simple client-side simulation from the known catalogue
			var catalogue = JSON.parse(document.getElementById('ct-bot-data').textContent);
			for (var i = 0; i < catalogue.length; i++) {
				if (ua.toLowerCase().indexOf(catalogue[i].ua_match) !== -1) {
					isBot = true;
					botName = catalogue[i].name;
					botOperator = catalogue[i].operator;
					break;
				}
			}

			if (!isBot) {
				curlOutput.innerHTML =
					'<span style="color:#94a3b8">$ curl -sI -H \'user-agent: ' + escHtml(ua) + '\' ' + escHtml(site + path) + '</span>\n\n' +
					'<span class="ct-http-code">HTTP/2 200</span>\n' +
					'<span class="ct-header-name">x-crawlertoll-action:</span> allow\n' +
					'<span class="ct-header-name">x-crawlertoll-bot-name:</span> (none — not a known AI crawler)\n\n' +
					'<span style="color:#94a3b8">→ Request passes through normally. Browsers and non-AI crawlers are not affected.</span>';
				return;
			}

			// Check if the path is in an Allow/Disallow rule
			var lines = policyText.split('\n');
			var currentUAs = [];
			var currentDisallow = [];
			var currentAllow = [];
			var currentCompensation = null;
			var matchedGroup = null;

			for (var j = 0; j < lines.length; j++) {
				var line = lines[j].replace(/#.*/, '').trim();
				if (!line) continue;
				var colon = line.indexOf(':');
				if (colon === -1) continue;
				var key = line.substring(0, colon).trim().toLowerCase();
				var val = line.substring(colon + 1).trim();

				if (key === 'user-agent') {
					if (currentUAs.length > 0 && (currentDisallow.length > 0 || currentAllow.length > 0)) {
						// Check if this group matches
						for (var k = 0; k < currentUAs.length; k++) {
							if (currentUAs[k] === '*' || ua.toLowerCase().indexOf(currentUAs[k]) !== -1) {
								matchedGroup = { disallow: currentDisallow.slice(), allow: currentAllow.slice(), compensation: currentCompensation };
								break;
							}
						}
					}
					currentUAs = [val.toLowerCase()];
					currentDisallow = [];
					currentAllow = [];
					currentCompensation = null;
				} else if (key === 'disallow' && val) {
					currentDisallow.push(val);
				} else if (key === 'allow' && val) {
					currentAllow.push(val);
				} else if (key === 'compensation') {
					currentCompensation = val;
				}
			}
			// Last group
			if (currentUAs.length > 0) {
				for (var k2 = 0; k2 < currentUAs.length; k2++) {
					if (currentUAs[k2] === '*' || ua.toLowerCase().indexOf(currentUAs[k2]) !== -1) {
						matchedGroup = { disallow: currentDisallow.slice(), allow: currentAllow.slice(), compensation: currentCompensation };
						break;
					}
				}
			}

			var pathAllowed = true;
			var matchedRule = '';
			if (matchedGroup) {
				var bestAllow = null, bestDisallow = null;
				for (var a = 0; a < matchedGroup.allow.length; a++) {
					if (path.indexOf(matchedGroup.allow[a]) === 0 && (bestAllow === null || matchedGroup.allow[a].length > bestAllow.length)) {
						bestAllow = matchedGroup.allow[a];
					}
				}
				for (var d = 0; d < matchedGroup.disallow.length; d++) {
					if (path.indexOf(matchedGroup.disallow[d]) === 0 && (bestDisallow === null || matchedGroup.disallow[d].length > bestDisallow.length)) {
						bestDisallow = matchedGroup.disallow[d];
					}
				}
				if (bestAllow && (!bestDisallow || bestAllow.length >= bestDisallow.length)) {
					pathAllowed = true;
					matchedRule = 'Allow: ' + bestAllow;
				} else if (bestDisallow) {
					pathAllowed = false;
					matchedRule = 'Disallow: ' + bestDisallow;
				}
				if (matchedGroup.compensation) hasCompensation = true;
			}

			var priceEl = document.getElementById('crawlertoll-price-micros');
			var price = priceEl ? priceEl.value : '5000';
			var currEl = document.getElementById('crawlertoll-currency');
			var currency = currEl ? currEl.value : 'USD';

			var output = '<span style="color:#94a3b8">$ curl -sI -H \'user-agent: ' + escHtml(ua) + '\' ' + escHtml(site + path) + '</span>\n\n';

			if (pathAllowed) {
				output += '<span class="ct-http-code">HTTP/2 200</span>\n';
				output += '<span class="ct-header-name">x-crawlertoll-action:</span> allow\n';
				output += '<span class="ct-header-name">x-crawlertoll-operator:</span> ' + escHtml(botOperator) + '\n';
				output += '<span class="ct-header-name">x-crawlertoll-bot-name:</span> ' + escHtml(botName) + '\n';
				if (matchedRule) {
					output += '\n<span style="color:#94a3b8">→ Matched rule: ' + escHtml(matchedRule) + ' — path is allowed.</span>';
				} else {
					output += '\n<span style="color:#94a3b8">→ No matching Disallow rule for this path. Request passes through.</span>';
				}
			} else if (hasCompensation) {
				output += '<span class="ct-http-code">HTTP/2 402</span> Payment Required\n';
				output += '<span class="ct-header-name">crawler-price:</span> ' + escHtml(price) + ' micros ' + escHtml(currency) + '\n';
				var railEl = document.getElementById('crawlertoll-rail');
				output += '<span class="ct-header-name">crawler-price-rail:</span> ' + escHtml(railEl ? railEl.value : 'x402') + '\n';
				output += '<span class="ct-header-name">retry-after:</span> 60\n';
				output += '<span class="ct-header-name">link:</span> &lt;.../.well-known/context-license.json&gt;; rel="describedby"\n';
				output += '<span class="ct-header-name">x-crawlertoll-action:</span> 402\n';
				output += '<span class="ct-header-name">x-crawlertoll-operator:</span> ' + escHtml(botOperator) + '\n';
				output += '<span class="ct-header-name">x-crawlertoll-bot-name:</span> ' + escHtml(botName) + '\n';
				output += '\n<span style="color:#fbbf24">→ Payment required. Crawler receives structured 402 offer with price + rail.</span>';
			} else {
				output += '<span class="ct-http-code block">HTTP/2 403</span> Forbidden\n';
				output += '<span class="ct-header-name">x-crawlertoll-action:</span> block\n';
				output += '<span class="ct-header-name">x-crawlertoll-operator:</span> ' + escHtml(botOperator) + '\n';
				output += '<span class="ct-header-name">x-crawlertoll-bot-name:</span> ' + escHtml(botName) + '\n';
				output += '\n<span style="color:#f87171">→ Blocked. Path disallowed with no compensation directive.</span>';
			}

			curlOutput.innerHTML = output;
		});

		// Run on load
		curlBtn.click();
	}

	function escHtml(s) {
		return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
	}
})();
