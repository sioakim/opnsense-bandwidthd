/*
 * bandwidthd.js — interactive dashboard for the OPNsense BandwidthD plugin.
 * Vanilla JS + Chart.js 4. Data comes from /api/bandwidthd/data/<action>.
 */
(function () {
	'use strict';

	// OPNsense MVC API. Each dashboard action is its own endpoint under
	// /api/bandwidthd/data/<action>; authentication and ACL are handled by the
	// framework (session cookie here, HTTP Basic API key for outside callers).
	var API = '/api/bandwidthd/data/';
	// POST action -> endpoint. OPNsense API method names are camelCase.
	var POST_EP = { set_override: 'setOverride', probe: 'probe',
		rename_tag: 'renameTag', delete_tag: 'deleteTag' };
	var state = {
		// Unified time Window: a preset (rangeSecs + the CDF resolution tier `period`
		// it implies) OR an explicit custom [from,to]. The window picks BOTH the
		// filter and the bandwidthd CDF tier to read, so resolution is automatic.
		period: 1,         // CDF tier (1=daily/fine … 4=yearly), derived from the window
		windowLabel: 'last 24 hours',
		view: 'ip',        // 'ip' | 'name'
		sort: 'total',     // 'total' | 'in' | 'out' | 'label'
		search: '',
		tags: {},          // active usage-tag filters (set); empty = all (multi-select)
		from: 0,           // unix seconds, 0 = unbounded (explicit custom range)
		to: 0,
		rangeSecs: 86400,  // active window duration (default 24h) — re-derived per fetch so
		                   // the window slides with the clock instead of freezing at click time
		seriesSeq: 0,      // monotonic token; only the latest series fetch may render (stale guard)
		// Same guard for the other loaders. Without it a slow response for an old
		// window (1y hits the DB and a large CDF scan) can land after a newer one
		// and repaint year data under a toolbar that says 24h, until the next tick.
		hostsSeq: 0,
		overviewSeq: 0,
		// Separate tokens per loader: percentile and daily are fired back-to-back for
		// the same host, so a shared token would have the second bump invalidate the
		// first response and the percentile strip would never render.
		pctileSeq: 0,
		dailySeq: 0,
		hosts: [],
		totalHost: null,   // the 0.0.0.0 interface-total aggregate
		ifaceIn: 0,        // interface-wide window totals (the toolbar pills'
		ifaceOut: 0,       // fallback when no tag filter is active)
		selected: null,
		dailyExpanded: false,  // per-date table show-all toggle
		tagEditorOpen: false,  // custom-tag editor panel visibility
		chart: null,
		ovChart: null
	};
	// Muted, earthy categorical palette for multi-talker charts; index 0 is the
	// brand accent (teal). Mid-tone so they read on both paper and ink.
	// Tableau Classic10 — the scheme OPNsense's own Traffic and Firewall widgets
	// use (Chart.colorschemes.tableau.Classic10), so multi-series charts here read
	// as part of the same interface rather than a separate app.
	var SERIES_COLORS = ['#1f77b4', '#ff7f0e', '#2ca02c', '#d62728', '#9467bd',
		'#8c564b', '#e377c2', '#7f7f7f', '#bcbd22', '#17becf'];
	var TOTAL_IP = '0.0.0.0';
	// Auto-classified type tags (have dedicated colors); anything else is a custom tag.
	var TYPE_TAGS = ['pc', 'phone', 'tablet', 'tv', 'iot', 'camera', 'printer',
		'network', 'voip', 'gaming', 'nas', 'appliance'];
	var DAILY_COLLAPSE = 14;   // daily-table rows shown before the "show all" toggle
	// In/Out fallbacks: the first two Classic10 entries, matching core's charts.
	// The live values come from the CSS tokens (--bwd-in / --bwd-out).
	var SERIES_IN = '#1f77b4', SERIES_OUT = '#ff7f0e';
	// Window presets → human label for the hero. Keyed by duration (seconds).
	var WINDOW_WORD = {
		3600: 'last hour', 21600: 'last 6 hours', 86400: 'last 24 hours',
		604800: 'last 7 days', 2592000: 'last 30 days', 31536000: 'last year'
	};
	// A custom span → the finest CDF tier whose resolution/retention suits it
	// (1=daily fine … 4=yearly coarse). Keeps the live CDF tail at a sane resolution.
	function spanToPeriod(span) {
		if (span <= 86400)   { return 1; }
		if (span <= 604800)  { return 2; }
		if (span <= 2592000) { return 3; }
		return 4;
	}
	var prefersReducedMotion = !!(window.matchMedia &&
		window.matchMedia('(prefers-reduced-motion: reduce)').matches);

	/* ---------- helpers ---------- */
	// Eased count-up for the hero number: animates from the element's previous
	// value (stored on the node) to `to`, formatting each frame. Snaps instantly
	// under prefers-reduced-motion. rAF passes the frame timestamp, so no clock read.
	function countUp(node, to, fmt) {
		if (!node) { return; }
		var from = node._bwdVal || 0;
		node._bwdVal = to;
		if (prefersReducedMotion || from === to) { node.textContent = fmt(to); return; }
		var start = null, dur = 900;
		function step(ts) {
			if (start === null) { start = ts; }
			var p = Math.min(1, (ts - start) / dur);
			var e = 1 - Math.pow(1 - p, 3);             // cubic ease-out
			node.textContent = fmt(from + (to - from) * e);
			if (p < 1) { requestAnimationFrame(step); }
		}
		requestAnimationFrame(step);
	}
	function fmtBytes(n) {
		n = Number(n) || 0;
		var u = ['B', 'KB', 'MB', 'GB', 'TB'], i = 0;
		while (n >= 1024 && i < u.length - 1) { n /= 1024; i++; }
		return (n >= 100 || i === 0 ? Math.round(n) : n.toFixed(1)) + ' ' + u[i];
	}
	function fmtBps(n) {
		n = Number(n) || 0;
		var u = ['bps', 'Kbps', 'Mbps', 'Gbps', 'Tbps'], i = 0;
		while (n >= 1000 && i < u.length - 1) { n /= 1000; i++; }
		return (n >= 100 || i === 0 ? Math.round(n) : n.toFixed(1)) + ' ' + u[i];
	}
	function el(sel) { return document.querySelector(sel); }
	// The effective [from,to] for a request: a relative preset slides with the
	// clock (re-derived now), an explicit custom range is used verbatim.
	function effRange() {
		if (state.rangeSecs) { return { from: Math.floor(Date.now() / 1000) - state.rangeSecs, to: 0 }; }
		return { from: state.from, to: state.to };
	}
	// Toggle a "connection lost" indicator on the updated-stamp, keeping stale data
	// visible rather than blanking the UI on a transient fetch failure.
	function connError() { var u = el('#bwd-updated'); if (u) { u.textContent = '⚠ connection lost — retrying'; u.classList.add('bwd-stale'); } }
	function connOk() { var u = el('#bwd-updated'); if (u) { u.classList.remove('bwd-stale'); } }
	function api(params) {
		var rng = effRange();
		if (rng.from) { params.from = rng.from; }
		if (rng.to)   { params.to = rng.to; }
		var action = params.action;
		var q = Object.keys(params).filter(function (k) { return k !== 'action'; }).map(function (k) {
			return encodeURIComponent(k) + '=' + encodeURIComponent(params[k]);
		}).join('&');
		return fetch(API + action + (q ? '?' + q : ''), { credentials: 'same-origin' }).then(function (r) {
			if (!r.ok) { throw new Error('HTTP ' + r.status); }
			return r.json();
		}).then(function (j) { connOk(); return j; }, function (e) { connError(); throw e; });
	}
	function label(h) {
		if (h && h.is_total) { return h.name || 'Interface Total'; }
		return (state.view === 'name' && h.name) ? h.name : h.ip;
	}
	function toEpoch(v) { if (!v) { return 0; } var t = Date.parse(v); return isNaN(t) ? 0 : Math.floor(t / 1000); }
	function toLocalInput(epoch) {
		var d = new Date(epoch * 1000), p = function (n) { return (n < 10 ? '0' : '') + n; };
		return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate()) + 'T' + p(d.getHours()) + ':' + p(d.getMinutes());
	}
	// A device's full tag set: its auto type tag plus its custom tags (deduped).
	function hostTags(h) {
		var out = [];
		if (h && h.tag) { out.push(h.tag); }
		(h && h.tags || []).forEach(function (t) { if (out.indexOf(t) < 0) { out.push(t); } });
		return out;
	}
	function isCustomTag(t) { return TYPE_TAGS.indexOf(t) < 0; }
	// The active tag selection as a sorted comma list ('' = no filter) — sent to
	// the API so aggregate views (overview, the 0.0.0.0 series/percentile/daily)
	// switch from interface totals to the tag selection's totals.
	function tagParam() {
		var t = Object.keys(state.tags).sort();
		return t.length ? t.join(',') : '';
	}
	function hostMatchesFilter(h) {
		return hostTags(h).some(function (t) { return state.tags[t]; });
	}
	// HTML for a device's custom-tag chips (neutral, distinct from the type badge).
	function customTagChips(h) {
		return (h && h.tags || []).map(function (t) {
			return '<span class="bwd-ctag">' + escapeHtml(t) + '</span>';
		}).join('');
	}
	// POST a write action. OPNsense checks an X-CSRFToken header on every API
	// write; the layout only patches jQuery's ajax, so plain fetch must send it
	// itself (the token is emitted into the page by dashboard.volt).
	function apiPost(data) {
		var ep = POST_EP[data.act];
		if (!ep) { return Promise.reject(new Error('unknown action ' + data.act)); }
		var parts = Object.keys(data).filter(function (k) { return k !== 'act'; })
			.map(function (k) { return encodeURIComponent(k) + '=' + encodeURIComponent(data[k]); });
		return fetch(API + ep, {
			method: 'POST', credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/x-www-form-urlencoded',
				'X-Requested-With': 'XMLHttpRequest',
				'X-CSRFToken': window.bwdCsrfToken || ''
			},
			body: parts.join('&')
		}).then(function (r) {
			// An expired session answers with the login page, not JSON — r.json()
			// would reject with a parse error that says nothing useful.
			if (!r.ok) { throw new Error('HTTP ' + r.status); }
			return r.json();
		});
	}

	/* ---------- host list ---------- */
	function renderTagBar() {
		var bar = el('#bwd-tagbar');
		if (!bar) { return; }
		// Prototype-free maps: a tag is any [a-z0-9_-] slug the operator types, and
		// one literally named 'constructor' would otherwise read as an inherited
		// truthy value — matching every device under any selection, rendering a
		// garbage count, and never being deselectable.
		var counts = Object.create(null), sums = Object.create(null);
		// Count over each device's full tag set (auto type tag + custom tags).
		state.hosts.forEach(function (h) {
			hostTags(h).forEach(function (t) {
				counts[t] = (counts[t] || 0) + 1;
				if (!sums[t]) { sums[t] = { in: 0, out: 0, total: 0 }; }
				sums[t].in += h.in || 0; sums[t].out += h.out || 0; sums[t].total += h.total || 0;
			});
		});
		var tags = Object.keys(counts).sort();
		if (!tags.length) { bar.hidden = true; bar.innerHTML = ''; hideTagEditor(); return; }
		bar.hidden = false;
		var sel = Object.keys(state.tags);                 // selected tag set
		var html = '<button class="bwd-tag-chip' + (sel.length === 0 ? ' is-active' : '') +
			'" data-tag="">All</button>';
		tags.forEach(function (t) {
			var tip = t + ': ' + fmtBytes(sums[t].total) + ' total (▼ ' + fmtBytes(sums[t].in) + ' / ▲ ' + fmtBytes(sums[t].out) + ')';
			// No type tint here: a chip is a filter control, styled like the other
			// controls. The colour tint belongs to the per-device badge, and emitting
			// the class only to have the chip rule cancel it was dead markup.
			var cust = isCustomTag(t) ? ' bwd-tag-chip--custom' : '';
			html += '<button class="bwd-tag-chip' + cust +
				(state.tags[t] ? ' is-active' : '') + '" data-tag="' + escapeHtml(t) + '" title="' + escapeHtml(tip) + '">' +
				escapeHtml(t) + ' <span class="bwd-tag-n">' + counts[t] + '</span></button>';
		});
		// Offer the cleanup editor once any custom tags exist.
		if (tags.some(isCustomTag)) {
			html += '<button class="bwd-tag-edit-btn" data-tagedit="1" title="Rename or delete custom tags">✎ Edit tags</button>';
		}
		// With tag(s) filtered, show the combined total traffic of the selection
		// (each matching device counted once, even if it carries several tags).
		if (sel.length) {
			var agg = { in: 0, out: 0, total: 0, n: 0 };
			state.hosts.forEach(function (h) {
				if (hostMatchesFilter(h)) {
					agg.in += h.in || 0; agg.out += h.out || 0; agg.total += h.total || 0; agg.n++;
				}
			});
			html += '<div class="bwd-tag-summary"><b>' + escapeHtml(sel.slice().sort().join(', ')) + '</b> — ' +
				agg.n + ' device' + (agg.n === 1 ? '' : 's') +
				' · <span class="bwd-in">▼ ' + fmtBytes(agg.in) + '</span> · <span class="bwd-out">▲ ' + fmtBytes(agg.out) +
				'</span> · total <b>' + fmtBytes(agg.total) + '</b></div>';
		}
		bar.innerHTML = html;
		bar.querySelectorAll('.bwd-tag-chip').forEach(function (b) {
			b.addEventListener('click', function () {
				var t = b.dataset.tag;
				if (t === '') { state.tags = {}; }            // "All" clears the selection
				else if (state.tags[t]) { delete state.tags[t]; }  // toggle off
				else { state.tags[t] = true; }                // toggle on (multi-select)
				applyTagFilter();
			});
		});
		var eb = bar.querySelector('.bwd-tag-edit-btn');
		if (eb) { eb.addEventListener('click', toggleTagEditor); }
	}

	// Tag filter changed: beyond the host list, re-scope every aggregate — the
	// toolbar totals, the overview cards/chart and, when the ★ total row is
	// selected, its stats/chart/percentile/daily (now the tag selection's total).
	function applyTagFilter() {
		renderTagBar();
		renderList();
		renderTotals();
		loadOverview();
		if (state.selected === TOTAL_IP) { selectHost(TOTAL_IP); }
	}

	/* ---------- tag editor (rename / delete custom tags) ---------- */
	function hideTagEditor() {
		var box = el('#bwd-tageditor');
		if (box) { box.hidden = true; box.innerHTML = ''; }
		state.tagEditorOpen = false;
	}
	function toggleTagEditor() {
		if (state.tagEditorOpen) { hideTagEditor(); return; }
		state.tagEditorOpen = true;
		renderTagEditor();
	}
	// After a rename/delete: refresh the host list, the selected device's detail,
	// and the editor panel so every view reflects the change.
	function afterTagEdit() {
		return loadHosts().then(function () {
			if (state.selected) { selectHost(state.selected); }
			renderTagEditor();
		});
	}
	// Report a tag-editor outcome in place. Without this a rejected rename (an
	// emptied field sanitises to nothing, so the server answers {error:...}) or a
	// dropped session left the panel looking like the edit had worked.
	function tagEditMsg(text, isError) {
		var box = el('#bwd-tageditor');
		if (!box) { return; }
		var head = box.querySelector('.bwd-te-head');
		if (!head) { return; }
		var m = head.querySelector('.bwd-te-msg');
		if (!m) {
			m = document.createElement('span');
			m.className = 'bwd-te-msg';
			head.insertBefore(m, head.querySelector('.bwd-te-close'));
		}
		m.textContent = text || '';
		m.classList.toggle('is-error', !!isError);
	}
	function afterTagEditResult(res) {
		if (res && res.error) { tagEditMsg(res.error, true); return; }
		tagEditMsg('');
		afterTagEdit();
	}
	function tagEditFailed(e) { tagEditMsg('Failed: ' + (e && e.message ? e.message : e), true); }

	function renderTagEditor() {
		var box = el('#bwd-tageditor');
		if (!box) { return; }
		box.hidden = false;
		box.innerHTML = '<div class="bwd-te-head">Custom tags<button class="bwd-te-close" title="Close">×</button></div>' +
			'<div class="bwd-te-body">Loading…</div>';
		box.querySelector('.bwd-te-close').addEventListener('click', hideTagEditor);
		api({ action: 'tags' }).then(function (d) {
			if (!state.tagEditorOpen) { return; }
			var tags = (d && d.tags) || {};
			var names = Object.keys(tags).sort();
			var body = box.querySelector('.bwd-te-body');
			if (!names.length) { body.innerHTML = '<div class="bwd-te-empty">No custom tags yet. Add tags to a device in its editor below.</div>'; return; }
			body.innerHTML = names.map(function (t) {
				return '<div class="bwd-te-row" data-tag="' + escapeHtml(t) + '">' +
					'<span class="bwd-ctag">' + escapeHtml(t) + '</span>' +
					'<span class="bwd-te-n">' + tags[t] + ' device' + (tags[t] === 1 ? '' : 's') + '</span>' +
					'<input type="text" class="bwd-te-rename" value="' + escapeHtml(t) + '" aria-label="Rename ' + escapeHtml(t) + '">' +
					'<button class="bwd-te-btn bwd-te-save">Rename</button>' +
					'<button class="bwd-te-btn bwd-te-del" title="Delete from all devices">Delete</button>' +
					'</div>';
			}).join('');
			body.querySelectorAll('.bwd-te-row').forEach(function (row) {
				var tag = row.dataset.tag;
				row.querySelector('.bwd-te-save').addEventListener('click', function () {
					var to = row.querySelector('.bwd-te-rename').value;
					apiPost({ act: 'rename_tag', from: tag, to: to })
						.then(afterTagEditResult, tagEditFailed);
				});
				row.querySelector('.bwd-te-del').addEventListener('click', function () {
					if (!window.confirm('Delete custom tag "' + tag + '" from all devices?')) { return; }
					apiPost({ act: 'delete_tag', tag: tag })
						.then(afterTagEditResult, tagEditFailed);
				});
			});
		}, function (e) {
			var body = box.querySelector('.bwd-te-body');
			if (body) { body.innerHTML = '<div class="bwd-te-empty">Could not load tags.</div>'; }
			tagEditFailed(e);
		});
	}

	function renderList() {
		var ul = el('#bwd-hostlist');
		var q = state.search.toLowerCase();
		var qhex = q.replace(/[^0-9a-f]/g, '');   // MAC match ignores separators
		var selTags = Object.keys(state.tags);
		var rows = state.hosts.filter(function (h) {
			// match when any of the device's tags (type + custom) is selected
			if (selTags.length && !hostMatchesFilter(h)) { return false; }
			if (!q) { return true; }
			return h.ip.toLowerCase().indexOf(q) >= 0 ||
				(h.name && h.name.toLowerCase().indexOf(q) >= 0) ||
				(h.vendor && h.vendor.toLowerCase().indexOf(q) >= 0) ||
				hostTags(h).some(function (t) { return t.indexOf(q) >= 0; }) ||
				(h.mac && (h.mac.toLowerCase().indexOf(q) >= 0 ||
					(qhex.length >= 2 && h.mac.replace(/:/g, '').indexOf(qhex) >= 0)));
		});
		rows.sort(function (a, b) {
			if (state.sort === 'label') {
				return label(a).localeCompare(label(b), undefined, { numeric: true });
			}
			return (b[state.sort] || 0) - (a[state.sort] || 0);
		});
		var max = rows.length ? Math.max.apply(null, rows.map(function (h) { return h.total; })) : 1;
		var scrollTop = ul.scrollTop;   // preserve scroll across the rebuild (the 60s auto-refresh
		ul.innerHTML = '';              // would otherwise yank a scrolled-down list back to the top)
		// Pinned total row at the top (ignores search/sort): the interface total,
		// or — with the tag filter active — the tag selection's total.
		var t = scopedTotalHost();
		if (t) {
			var tli = document.createElement('li');
			tli.className = 'bwd-host bwd-host-total' + (state.selected === TOTAL_IP ? ' is-selected' : '');
			tli.dataset.ip = TOTAL_IP;
			tli.innerHTML =
				'<div class="bwd-host-main">' +
					'<span class="bwd-host-label">★ ' + escapeHtml(t.name || 'Interface Total') + '</span>' +
					'<span class="bwd-host-sub">' + (selTags.length ? 'tag selection' : 'all hosts') + '</span>' +
				'</div>' +
				'<div class="bwd-host-nums">' +
					'<span class="bwd-num bwd-in">▼ ' + fmtBytes(t.in) + '</span>' +
					'<span class="bwd-num bwd-out">▲ ' + fmtBytes(t.out) + '</span>' +
				'</div>';
			tli.addEventListener('click', function () { selectHost(TOTAL_IP); });
			ul.appendChild(tli);
		}
		rows.forEach(function (h) {
			var li = document.createElement('li');
			li.className = 'bwd-host' + (h.ip === state.selected ? ' is-selected' : '');
			li.dataset.ip = h.ip;
			var pct = max ? (h.total / max * 100) : 0;
			var subParts = [];
			var primarySub = (state.view === 'name' && h.name) ? h.ip : (h.name || '');
			if (primarySub) { subParts.push(escapeHtml(primarySub)); }
			// prefer the specific model (e.g. "Shelly Dimmer 2") over the bare vendor
			if (h.model) { subParts.push(escapeHtml(h.model)); }
			else if (h.vendor) { subParts.push(escapeHtml(h.vendor)); }
			else if (h.randomized) { subParts.push('<em>randomized MAC</em>'); }
			if (h.ips && h.ips.length > 1) { subParts.push(h.ips.length + ' IPs'); }
			var sub = subParts.join(' · ');
			var badge = (h.tag ? '<span class="bwd-tag bwd-tag-' + escapeHtml(h.tag) + '">' + escapeHtml(h.tag) + '</span>' : '') +
				customTagChips(h);
			li.innerHTML =
				'<div class="bwd-host-bar" style="width:' + pct.toFixed(1) + '%"></div>' +
				'<div class="bwd-host-main">' +
					'<span class="bwd-host-label">' + escapeHtml(label(h)) + badge + '</span>' +
					(sub ? '<span class="bwd-host-sub">' + sub + '</span>' : '') +
				'</div>' +
				'<div class="bwd-host-nums">' +
					'<span class="bwd-num bwd-in">▼ ' + fmtBytes(h.in) + '</span>' +
					'<span class="bwd-num bwd-out">▲ ' + fmtBytes(h.out) + '</span>' +
				'</div>';
			li.addEventListener('click', function () { selectHost(h.ip); });
			ul.appendChild(li);
		});
		if (!rows.length) {
			// Append, don't replace: the interface-total row is pinned above and is
			// documented to ignore search and sort, so wiping innerHTML would take it
			// (and the current selection highlight) with it.
			ul.insertAdjacentHTML('beforeend', '<li class="bwd-empty-row">No hosts match.</li>');
		}
		ul.scrollTop = scrollTop;   // restore the pre-rebuild scroll position
	}
	function escapeHtml(s) {
		return String(s).replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	}

	/* ---------- detail / chart ---------- */
	// The ★ row's data: the interface total, or — with the tag filter active —
	// the aggregate of the matching devices (totals + proto mix summed client-
	// side), so the pinned row and its detail stats are tag-scoped too.
	function scopedTotalHost() {
		if (!state.totalHost) { return null; }
		var sel = Object.keys(state.tags).sort();
		if (!sel.length) { return state.totalHost; }
		var agg = { ip: TOTAL_IP, is_total: true, in: 0, out: 0,
			tcp: 0, udp: 0, http: 0, p2p: 0, icmp: 0, ftp: 0, n: 0 };
		state.hosts.forEach(function (h) {
			if (!hostMatchesFilter(h)) { return; }
			['in', 'out', 'tcp', 'udp', 'http', 'p2p', 'icmp', 'ftp'].forEach(function (k) { agg[k] += h[k] || 0; });
			agg.n++;
		});
		agg.total = agg.in + agg.out;
		agg.name = sel.join(', ') + ' — ' + agg.n + ' device' + (agg.n === 1 ? '' : 's');
		return agg;
	}
	function hostByIp(ip) {
		if (ip === TOTAL_IP) { return scopedTotalHost(); }
		for (var i = 0; i < state.hosts.length; i++) { if (state.hosts[i].ip === ip) { return state.hosts[i]; } }
		return null;
	}
	function selectHost(ip) {
		state.selected = ip;
		renderList();
		var h = hostByIp(ip);
		el('#bwd-detail-title').textContent = h ? label(h) : ip;
		if (h && h.is_total) {
			var selT = Object.keys(state.tags).sort();
			el('#bwd-detail-sub').textContent = selT.length ?
				'sum of devices tagged ' + selT.join(', ') :
				'all hosts on the monitored subnet(s)';
		} else if (h) {
			var parts = [];
			var alt = (h.name && state.view !== 'name') ? h.name : (state.view === 'name' ? h.ip : '');
			if (alt) { parts.push(escapeHtml(alt)); }
			if (h.mac) { parts.push(escapeHtml(h.mac)); }
			if (h.vendor) { parts.push(escapeHtml(h.vendor)); }
			else if (h.randomized) { parts.push('<em>randomized MAC</em>'); }
			if (h.model) {
				var mvia = (h.probe && h.probe.via) ? h.probe.via : 'fingerprint';
				parts.push('<span class="bwd-model" title="model — identified via ' + escapeHtml(mvia) + '">' + escapeHtml(h.model) + '</span>');
			}
			if (h.ips && h.ips.length > 1) {
				var others = h.ips.filter(function (x) { return x !== h.ip; });
				parts.push('also seen as ' + escapeHtml(others.join(', ')));
			}
			var badge = '';
			if (h.tag) {
				var conf = (typeof h.tag_confidence === 'number') ? h.tag_confidence : 1;
				var srcs = (h.tag_signals || []).map(function (s) { return s.source; }).join(', ');
				var ttl = conf >= 1 ? 'manual override' : ('auto-classified from ' + (srcs || 'vendor') + ' · ' + Math.round(conf * 100) + '% confidence');
				badge = ' <span class="bwd-tag bwd-tag-' + escapeHtml(h.tag) + '" title="' + escapeHtml(ttl) + '">' +
					escapeHtml(h.tag) + (conf < 1 ? ' ' + Math.round(conf * 100) + '%' : '') + '</span>';
			}
			var sub = el('#bwd-detail-sub');
			sub.innerHTML = parts.join(' · ') + badge + customTagChips(h);
			if (state.probeEnabled) {
				var pb = document.createElement('button');
				pb.className = 'bwd-export-btn bwd-probe-btn';
				pb.textContent = h.probe ? 'Re-probe' : 'Probe device';
				pb.addEventListener('click', function () { doProbe(h.ip, pb); });
				sub.appendChild(document.createTextNode(' '));
				sub.appendChild(pb);
			}
		} else {
			el('#bwd-detail-sub').textContent = '';
		}
		if (h) {
			el('#bwd-detail-stats').innerHTML =
				'<span class="bwd-stat bwd-in"><i></i><span class="bwd-stat-k">In</span><b>' + fmtBytes(h.in) + '</b></span>' +
				'<span class="bwd-stat bwd-out"><i></i><span class="bwd-stat-k">Out</span><b>' + fmtBytes(h.out) + '</b></span>' +
				'<span class="bwd-stat"><i></i><span class="bwd-stat-k">Total</span><b>' + fmtBytes(h.total) + '</b></span>';
			renderProto(h);
		}
		state.dailyExpanded = false;   // collapse the per-date table on each new selection
		// Fetch by MAC when known so a device's lease-renewal IPs are unioned.
		var seriesId = (h && h.mac) ? h.mac : ip;
		loadSeries(seriesId);
		loadPercentile(ip, seriesId);
		loadDaily(ip, seriesId);
		renderAlertCfg(h);
	}

	/* ---------- per-device alert/tag editor ---------- */
	function mkField(label, ctrl) {
		return '<label class="bwd-acf-field"><span>' + escapeHtml(label) + '</span>' + ctrl + '</label>';
	}
	function mkSelect(name, val, opts) {
		return '<select data-k="' + name + '">' + opts.map(function (o) {
			return '<option value="' + escapeHtml(o[0]) + '"' + (o[0] === val ? ' selected' : '') + '>' +
				escapeHtml(o[1]) + '</option>';
		}).join('') + '</select>';
	}
	function renderAlertCfg(h) {
		var box = el('#bwd-alertcfg');
		if (!box) { return; }
		if (!h || h.is_total) { box.hidden = true; box.innerHTML = ''; return; }
		var match = h.mac || h.ip;
		api({ action: 'override', mac: h.mac || '', ip: h.ip || '' }).then(function (d) {
			if (state.selected !== h.ip || !box) { return; }
			// Save under the EXISTING row's key (d.match) so a device that gained a MAC
			// after an IP-keyed override was created updates that row instead of
			// orphaning it as a duplicate. Falls back to the MAC-preferred default.
			match = d.match || match;
			var r = d.row || {}, g = d.globals || {};
			var tri = function (gv) { return [['inherit', 'Inherit (' + (gv || 'off') + ')'], ['on', 'On'], ['off', 'Off']]; };
			var tagOpts = [['auto', 'Auto-detect']].concat(
				['pc', 'phone', 'tablet', 'tv', 'iot', 'camera', 'printer', 'network', 'voip', 'gaming', 'nas', 'appliance']
					.map(function (t) { return [t, t]; }));
			// datalist of custom tags already in use, for autocomplete
			var known = {};
			state.hosts.forEach(function (hh) { (hh.tags || []).forEach(function (t) { known[t] = true; }); });
			var dlist = '<datalist id="bwd-ctags-list">' + Object.keys(known).sort().map(function (t) {
				return '<option value="' + escapeHtml(t) + '">';
			}).join('') + '</datalist>';
			var customField = '<label class="bwd-acf-field bwd-acf-field--wide"><span>Custom tags</span>' +
				'<input type="text" data-k="tags" value="' + escapeHtml(d.tags || '') +
				'" list="bwd-ctags-list" placeholder="comma-separated, e.g. work, kids"></label>';
			box.hidden = false;
			box.innerHTML =
				'<div class="bwd-acf-head">Identity, alerts &amp; tag for this device' +
					'<span class="bwd-acf-key">' + escapeHtml(d.matched_by || 'ip') + ': ' + escapeHtml(match) + '</span></div>' +
				'<div class="bwd-acf-grid">' +
					mkField('Name', '<input type="text" data-k="name" value="' +
						escapeHtml(r.name || r.label || '') + '" placeholder="' + escapeHtml(h.name || 'auto') + '">') +
					mkField('Vendor', '<input type="text" data-k="vendor" value="' +
						escapeHtml(r.vendor || '') + '" placeholder="' + escapeHtml(h.vendor || 'auto') + '">') +
					mkField('Alerts', mkSelect('alerts_enable', r.alerts_enable || 'inherit', tri(g.alerts_enable))) +
					mkField('Quota GB', '<input type="number" min="0" step="0.1" data-k="quota_host_gb" value="' +
						escapeHtml(r.quota_host_gb || '') + '" placeholder="inherit (' + escapeHtml(g.quota_host_gb || '0') + ')">') +
					mkField('Anomaly', mkSelect('anomaly_enable', r.anomaly_enable || 'inherit', tri(g.anomaly_enable))) +
					mkField('Exfiltration', mkSelect('exfil_enable', r.exfil_enable || 'inherit', tri(g.exfil_enable))) +
					mkField('New device', mkSelect('newdevice_enable', r.newdevice_enable || 'inherit', tri(g.newdevice_enable))) +
					mkField('Tag', mkSelect('tag', r.tag || 'auto', tagOpts)) +
					customField +
				'</div>' + dlist +
				'<div class="bwd-acf-actions">' +
					'<button type="button" id="bwd-acf-save" class="bwd-export-btn">Save</button>' +
					'<span class="bwd-acf-msg" id="bwd-acf-msg"></span>' +
					'<span class="bwd-acf-hint">overrides the global defaults for this device only</span>' +
				'</div>';
			el('#bwd-acf-save').addEventListener('click', function () { saveAlertCfg(match); });
		});
	}
	function saveAlertCfg(match) {
		var box = el('#bwd-alertcfg'), msg = el('#bwd-acf-msg');
		var data = { act: 'set_override', match: match };
		box.querySelectorAll('[data-k]').forEach(function (c) { data[c.dataset.k] = c.value; });
		if (msg) { msg.textContent = 'Saving…'; }
		apiPost(data)
			.then(function (res) {
				if (msg) { msg.textContent = res.ok ? (res.removed ? 'Saved — reverted to inherit.' : 'Saved.') : ('Error: ' + (res.error || 'failed')); }
				if (res.ok) { loadHosts(); }   // refresh tags/labels
			})
			.catch(function (e) { if (msg) { msg.textContent = 'Error: ' + e; } });
	}
	function doProbe(ip, btn) {
		if (btn) { btn.disabled = true; btn.textContent = 'Probing…'; }
		apiPost({ act: 'probe', ip: ip })
			.then(function (res) {
				if (res && res.error) { if (btn) { btn.disabled = false; btn.textContent = 'Probe (' + res.error + ')'; } return; }
				loadHosts().then(function () { if (state.selected === ip) { selectHost(ip); } });   // refresh tag + fingerprint
			})
			.catch(function () { if (btn) { btn.disabled = false; btn.textContent = 'Probe device'; } });
	}
	function loadPercentile(ip, seriesId) {
		var _seq = ++state.pctileSeq;
		var box = el('#bwd-pctile');
		if (box) { box.hidden = true; }
		var q = { action: 'percentile', ip: seriesId || ip, period: state.period };
		if (q.ip === TOTAL_IP && tagParam()) { q.tags = tagParam(); }   // tag-scoped total
		api(q).then(function (p) {
			if (_seq !== state.pctileSeq) { return; }   // superseded by a newer window/selection
			if (state.selected !== ip || !box) { return; }
			if (p && p.samples >= 2 && p.total_bps > 0) {
				box.innerHTML = '<span class="bwd-pctile-k">95th percentile</span>' +
					'<span class="bwd-pctile-v">' + fmtBps(p.total_bps) + '</span>' +
					'<span class="bwd-pctile-sub">▼ ' + fmtBps(p.in_bps) + ' · ▲ ' + fmtBps(p.out_bps) +
					' · ' + p.samples + ' samples</span>';
				box.hidden = false;
			} else {
				box.hidden = true; box.innerHTML = '';
			}
		}).catch(function () {});
	}
	var MON = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
	function pad2(n) { return (n < 10 ? '0' : '') + n; }
	// Full local date+time for chart tooltips (so a point is never ambiguous).
	function fmtFull(t) {
		var d = new Date(t * 1000);
		return MON[d.getMonth()] + ' ' + d.getDate() + ', ' + d.getFullYear() + ' ' + pad2(d.getHours()) + ':' + pad2(d.getMinutes());
	}
	// Span-aware x-axis tick: time only within a day, date+time within ~2 weeks,
	// date (with year past a year) for wider windows — so multi-day charts show dates.
	function fmtAxis(t, span) {
		var d = new Date(t * 1000);
		if (span <= 86400) { return pad2(d.getHours()) + ':' + pad2(d.getMinutes()); }
		if (span <= 1209600) { return (d.getMonth() + 1) + '/' + d.getDate() + ' ' + pad2(d.getHours()) + ':' + pad2(d.getMinutes()); }
		if (span <= 31536000) { return (d.getMonth() + 1) + '/' + d.getDate(); }
		return (d.getMonth() + 1) + '/' + d.getDate() + '/' + String(d.getFullYear()).slice(2);
	}
	function loadSeries(ip) {
		if (!ip) { return; }
		var p = { action: 'series', ip: ip, period: state.period };
		if (ip === TOTAL_IP && tagParam()) { p.tags = tagParam(); }   // tag-scoped total
		var seq = ++state.seriesSeq;   // supersede any in-flight series fetch
		api(p).then(function (d) {
			if (seq !== state.seriesSeq) { return; }   // a newer selection/range won the race
			renderChart(d.points || []);
		}).catch(function () {});
	}
	// Re-fetch the selected device's chart, percentile and daily table together,
	// resolving its MAC so lease-renewal IPs are unioned (used on period/range reloads).
	function refreshSelected() {
		if (!state.selected) { return; }
		var h = hostByIp(state.selected);
		var seriesId = (h && h.mac) ? h.mac : state.selected;
		loadSeries(seriesId);
		loadPercentile(state.selected, seriesId);
		loadDaily(state.selected, seriesId);
	}
	function loadDaily(ip, seriesId) {
		var _seq = ++state.dailySeq;
		var box = el('#bwd-daily');
		if (box) { box.hidden = true; }
		var q = { action: 'daily', ip: seriesId || ip, period: state.period };
		if (q.ip === TOTAL_IP && tagParam()) { q.tags = tagParam(); }   // tag-scoped total
		api(q).then(function (d) {
			if (_seq !== state.dailySeq) { return; }   // superseded by a newer window/selection
			if (state.selected !== ip || !box) { return; }
			renderDaily(d);
		}).catch(function () {});
	}
	function dailyDateLabel(day) {
		var p = String(day).split('-');                  // 'YYYY-MM-DD' (local day)
		var d = new Date(+p[0], (+p[1] || 1) - 1, +p[2] || 1);
		var w = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'][d.getDay()];
		var s = w + ', ' + MON[d.getMonth()] + ' ' + d.getDate();
		if (d.getFullYear() !== new Date().getFullYear()) { s += ', ' + d.getFullYear(); }
		return s;
	}
	function renderDaily(d) {
		var box = el('#bwd-daily');
		if (!box) { return; }
		var days = (d && d.days) || [];
		if (!days.length) { box.hidden = true; box.innerHTML = ''; return; }
		var maxTotal = days.reduce(function (m, r) { return Math.max(m, r.total); }, 0) || 1;
		var shown = state.dailyExpanded ? days : days.slice(0, DAILY_COLLAPSE);
		var rowsHtml = shown.map(function (r) {
			var w = (r.total / maxTotal * 100);
			var inW = r.total ? (r.in / r.total * 100) : 0;
			return '<tr>' +
				'<td class="bwd-daily-date">' + escapeHtml(dailyDateLabel(r.day)) + '</td>' +
				'<td class="bwd-daily-num bwd-in">' + fmtBytes(r.in) + '</td>' +
				'<td class="bwd-daily-num bwd-out">' + fmtBytes(r.out) + '</td>' +
				'<td class="bwd-daily-num bwd-daily-tot">' + fmtBytes(r.total) + '</td>' +
				'<td class="bwd-daily-barcell"><span class="bwd-daily-bar" style="width:' + w.toFixed(1) + '%">' +
					'<i class="bwd-in" style="width:' + inW.toFixed(1) + '%"></i>' +
					'<i class="bwd-out" style="width:' + (100 - inW).toFixed(1) + '%"></i>' +
				'</span></td></tr>';
		}).join('');
		var more = '';
		if (days.length > DAILY_COLLAPSE) {
			more = '<button class="bwd-daily-more" type="button">' +
				(state.dailyExpanded ? 'Show less' : 'Show all ' + days.length + ' days') + '</button>';
		}
		box.hidden = false;
		box.innerHTML =
			'<div class="bwd-daily-head"><span class="bwd-daily-title">Daily totals</span>' +
				'<span class="bwd-daily-sub">' + days.length + ' day' + (days.length === 1 ? '' : 's') +
				' · <span class="bwd-in">▼ ' + fmtBytes(d.total_in) + '</span> · <span class="bwd-out">▲ ' + fmtBytes(d.total_out) +
				'</span> · total <b>' + fmtBytes(d.total) + '</b></span></div>' +
			'<table class="bwd-daily-tbl"><thead><tr>' +
				'<th>Date</th><th class="bwd-daily-num">In</th><th class="bwd-daily-num">Out</th>' +
				'<th class="bwd-daily-num">Total</th><th class="bwd-daily-barhead"></th></tr></thead><tbody>' +
				rowsHtml + '</tbody></table>' + more;
		var mb = box.querySelector('.bwd-daily-more');
		if (mb) { mb.addEventListener('click', function () { state.dailyExpanded = !state.dailyExpanded; renderDaily(d); }); }
	}
	function gradient(ctx, area, hex) {
		var g = ctx.createLinearGradient(0, area.top, 0, area.bottom);
		g.addColorStop(0, hex + '66');
		g.addColorStop(1, hex + '05');
		return g;
	}
	function renderChart(points) {
		var canvas = el('#bwd-chart');
		var empty = el('#bwd-chart-empty');
		if (!points.length) {
			empty.style.display = 'block';
			canvas.style.display = 'none';
			if (state.chart) { state.chart.destroy(); state.chart = null; }
			return;
		}
		empty.style.display = 'none';
		canvas.style.display = 'block';
		var span = points.length > 1 ? (points[points.length - 1].t - points[0].t) : 0;
		var labels = points.map(function (p) { return fmtAxis(p.t, span); });
		var inData = points.map(function (p) { return p.in; });
		var outData = points.map(function (p) { return p.out; });
		// Read In/Out from the CSS tokens so the chart re-themes in dark mode.
		var IN = cssVar('--bwd-in', SERIES_IN), OUT = cssVar('--bwd-out', SERIES_OUT);
		if (state.chart) { state.chart.destroy(); }
		state.chart = new Chart(canvas.getContext('2d'), {
			type: 'line',
			data: {
				labels: labels,
				datasets: [
					{ label: 'In (download)', data: inData, borderColor: IN, tension: 0.35,
					  borderWidth: 2, pointRadius: 0, pointHoverRadius: 4, fill: true,
					  backgroundColor: function (c) { var a = c.chart.chartArea; return a ? gradient(c.chart.ctx, a, IN) : IN + '22'; } },
					{ label: 'Out (upload)', data: outData, borderColor: OUT, tension: 0.35,
					  borderWidth: 2, pointRadius: 0, pointHoverRadius: 4, fill: true,
					  backgroundColor: function (c) { var a = c.chart.chartArea; return a ? gradient(c.chart.ctx, a, OUT) : OUT + '22'; } }
				]
			},
			options: {
				responsive: true, maintainAspectRatio: false,
				interaction: { mode: 'index', intersect: false },
				plugins: {
					legend: { labels: { usePointStyle: true, boxWidth: 8, color: cssVar('--bwd-fg', '#373736') } },
					tooltip: {
						callbacks: {
							title: function (items) { return items.length ? fmtFull(points[items[0].dataIndex].t) : ''; },
							label: function (c) { return c.dataset.label + ': ' + fmtBytes(c.parsed.y); }
						}
					}
				},
				scales: {
					x: { grid: { display: false }, ticks: { maxTicksLimit: 8, color: cssVar('--bwd-muted', '#7f7f7f') } },
					y: { beginAtZero: true, grid: { color: cssVar('--bwd-grid', 'rgba(128,128,128,.18)') },
						 ticks: { color: cssVar('--bwd-muted', '#7f7f7f'), callback: function (v) { return fmtBytes(v); } } }
				}
			}
		});
	}
	// Resolve a design token. OPNsense themes are whole-stylesheet swaps with no
	// custom properties and no marker on <body>, so the chart's text colour cannot
	// be hardcoded or read from a theme variable — it is taken from what the active
	// theme actually computed for the page. Everything else is a theme-neutral
	// rgba() overlay and resolves straight from the token.
	function cssVar(name, fallback) {
		if (name === '--bwd-fg') { return themeFg(); }
		var v = getComputedStyle(document.documentElement).getPropertyValue(name);
		v = v ? v.trim() : '';
		return (v && v !== 'inherit') ? v : fallback;
	}
	function themeFg() {
		// Read it per call rather than caching: it costs one getComputedStyle per
		// chart build, and a cached value would go stale if the active theme ever
		// changes under the page.
		var host = el('#bwd-app') || document.body;
		return getComputedStyle(host).color || '#373736';
	}

	/* ---------- overview: summary cards + stacked-area chart ---------- */
	function loadOverview() {
		var _seq = ++state.overviewSeq;
		var q = { action: 'overview', period: state.period, topn: 8 };
		if (tagParam()) { q.tags = tagParam(); }   // scope cards + chart to the tag selection
		api(q).then(function (o) {
			if (_seq !== state.overviewSeq) { return; }   // superseded by a newer window/selection
			var tt = el('.bwd-overview-title');
			if (tt) { tt.textContent = 'Traffic over time — top talkers' + (tagParam() ? ' · ' + Object.keys(state.tags).sort().join(', ') : ''); }
			renderCards(o.summary || {});
			renderOverviewChart(o);
		}).catch(function () {});
	}
	function renderCards(s) {
		var box = el('#bwd-cards');
		if (!box) { return; }
		var scoped = Object.keys(state.tags).length > 0;
		var top = s.top ? ((s.top.name || s.top.ip) + ' · ' + fmtBytes(s.top.total)) : '–';
		// Total / Download / Upload lead in the hero; these are the supporting stats.
		var cards = [
			['Active hosts', (s.hosts != null ? s.hosts : '–'), 'with traffic'],
			['Top talker', top, 'by total'],
			['95th %ile', s.pct95_total_bps ? fmtBps(s.pct95_total_bps) : '–',
				scoped ? 'tag selection throughput' : 'interface throughput']
		];
		box.innerHTML = cards.map(function (c) {
			return '<div class="bwd-card"><span class="bwd-card-k">' + escapeHtml(c[0]) + '</span>' +
				'<span class="bwd-card-v">' + escapeHtml(String(c[1])) + '</span>' +
				'<span class="bwd-card-sub">' + escapeHtml(c[2]) + '</span></div>';
		}).join('');
	}
	function renderOverviewChart(o) {
		var canvas = el('#bwd-overview-chart');
		var empty = el('#bwd-overview-empty');
		if (!canvas) { return; }
		var bin = o.bin || 1;
		var hasData = o.series && o.series.some(function (s) {
			return s.data.some(function (v) { return v > 0; });
		});
		if (!o.labels || !o.labels.length || !hasData) {
			// toggle via inline style: the author rule `.bwd-empty{display:none}` beats
			// the [hidden] attribute, so `hidden=false` alone would never show it.
			if (empty) { empty.style.display = ''; }
			canvas.style.display = 'none';
			if (state.ovChart) { state.ovChart.destroy(); state.ovChart = null; }
			return;
		}
		if (empty) { empty.style.display = 'none'; }
		canvas.style.display = 'block';
		var ospan = o.labels.length > 1 ? (o.labels[o.labels.length - 1] - o.labels[0]) : 0;
		var labels = o.labels.map(function (t) { return fmtAxis(t, ospan); });
		// bytes-per-bin -> Mbps (stacked sum = interface throughput)
		var datasets = o.series.map(function (s, i) {
			var color = SERIES_COLORS[i % SERIES_COLORS.length];
			if (s.key === 'other') { color = cssVar('--bwd-muted', '#7f7f7f'); }
			return {
				label: s.name + (s.tag ? ' (' + s.tag + ')' : ''),
				data: s.data.map(function (b) { return b * 8 / bin / 1e6; }),
				borderColor: color, backgroundColor: color + 'cc',
				borderWidth: 1, pointRadius: 0, pointHoverRadius: 3, fill: true, tension: 0.25
			};
		});
		if (state.ovChart) { state.ovChart.destroy(); }
		state.ovChart = new Chart(canvas.getContext('2d'), {
			type: 'line',
			data: { labels: labels, datasets: datasets },
			options: {
				responsive: true, maintainAspectRatio: false,
				interaction: { mode: 'index', intersect: false },
				plugins: {
					legend: { labels: { usePointStyle: true, boxWidth: 8, color: cssVar('--bwd-fg', '#373736') } },
					tooltip: { callbacks: {
						title: function (items) { return items.length ? fmtFull(o.labels[items[0].dataIndex]) : ''; },
						label: function (c) { return c.dataset.label + ': ' + c.parsed.y.toFixed(2) + ' Mbps'; } } }
				},
				scales: {
					x: { stacked: true, grid: { display: false }, ticks: { maxTicksLimit: 10, color: cssVar('--bwd-muted', '#7f7f7f') } },
					y: { stacked: true, beginAtZero: true, grid: { color: cssVar('--bwd-grid', 'rgba(128,128,128,.18)') },
						 ticks: { color: cssVar('--bwd-muted', '#7f7f7f'), callback: function (v) { return v + ' Mbps'; } } }
				}
			}
		});
	}
	function renderProto(h) {
		// bandwidthd counts http/ftp/p2p as port-based SUB-classes of tcp (each TCP
		// packet bumps tcp AND, if it matches, http/ftp/p2p), while tcp/udp/icmp are
		// mutually-exclusive transport classes summing to ~total. So split tcp into
		// its named slices + "other TCP" and add "other IP", giving disjoint parts
		// that sum to the device total — summing tcp+http+… would double-count.
		var http = h.http || 0, ftp = h.ftp || 0, p2p = h.p2p || 0;
		var otherTcp = Math.max(0, (h.tcp || 0) - http - ftp - p2p);
		var otherIp = Math.max(0, (h.total || 0) - (h.tcp || 0) - (h.udp || 0) - (h.icmp || 0));
		// Slices drawn from the same Classic10 scheme as the charts, so the mix bar
		// and the stacked chart read as one palette.
		var protos = [
			['HTTP/HTTPS', http, SERIES_COLORS[0]], ['Other TCP', otherTcp, SERIES_COLORS[4]],
			['UDP', h.udp, SERIES_COLORS[2]], ['P2P', p2p, SERIES_COLORS[1]],
			['FTP', ftp, SERIES_COLORS[9]], ['ICMP', h.icmp, SERIES_COLORS[6]],
			['Other IP', otherIp, SERIES_COLORS[7]]
		].filter(function (p) { return p[1] > 0; });
		var tot = protos.reduce(function (s, p) { return s + p[1]; }, 0) || 1;
		var html = '<div class="bwd-proto-title">Protocol mix</div><div class="bwd-proto-bar">';
		protos.forEach(function (p) {
			html += '<span style="width:' + (p[1] / tot * 100).toFixed(2) + '%;background:' + p[2] + '" title="' + p[0] + ' ' + fmtBytes(p[1]) + '"></span>';
		});
		html += '</div><div class="bwd-proto-legend">';
		protos.forEach(function (p) {
			html += '<span><i style="background:' + p[2] + '"></i>' + p[0] + ' ' + fmtBytes(p[1]) + '</span>';
		});
		html += '</div>';
		el('#bwd-proto').innerHTML = protos.length ? html : '';
	}

	/* ---------- loading ---------- */
	// Toolbar In/Out pills: interface-wide normally; with the tag filter active,
	// the selected tag(s)' devices summed instead (each device counted once).
	function renderTotals() {
		var sel = Object.keys(state.tags).sort();
		var tin = state.ifaceIn || 0, tout = state.ifaceOut || 0;
		if (sel.length) {
			tin = 0; tout = 0;
			state.hosts.forEach(function (h) {
				if (hostMatchesFilter(h)) { tin += h.in || 0; tout += h.out || 0; }
			});
		}
		el('#bwd-tot-in').textContent = fmtBytes(tin);
		el('#bwd-tot-out').textContent = fmtBytes(tout);
		var scope = sel.length ? 'devices tagged ' + sel.join(', ') : 'interface total';
		el('#bwd-tot-in').title = scope;
		el('#bwd-tot-out').title = scope;

		// Hero: lead with the one number (total traffic for the window), kept in
		// sync with the toolbar pills so the range/tag filter is reflected.
		countUp(el('#bwd-hero-total'), tin + tout, fmtBytes);
		var hi = el('#bwd-hero-in'), ho = el('#bwd-hero-out'), hw = el('#bwd-hero-window');
		if (hi) { hi.textContent = fmtBytes(tin); }
		if (ho) { ho.textContent = fmtBytes(tout); }
		if (hw) { hw.textContent = sel.length ? sel.join(', ') : (state.windowLabel || 'this window'); }
	}
	function loadHosts() {
		var _seq = ++state.hostsSeq;
		return api({ action: 'hosts', period: state.period }).then(function (d) {
			if (_seq !== state.hostsSeq) { return; }   // superseded by a newer window/selection
			state.hosts = d.hosts || [];
			state.totalHost = d.total_host || null;
			state.ifaceIn = d.total_in;
			state.ifaceOut = d.total_out;
			el('#bwd-updated').textContent = 'updated ' + new Date().toLocaleTimeString();
			renderTagBar();
			renderList();
			renderTotals();
			if (state.selected && hostByIp(state.selected)) {
				var h = hostByIp(state.selected);
				el('#bwd-detail-stats').innerHTML =
					'<span class="bwd-stat bwd-in"><i></i><span class="bwd-stat-k">In</span><b>' + fmtBytes(h.in) + '</b></span>' +
					'<span class="bwd-stat bwd-out"><i></i><span class="bwd-stat-k">Out</span><b>' + fmtBytes(h.out) + '</b></span>' +
					'<span class="bwd-stat"><i></i><span class="bwd-stat-k">Total</span><b>' + fmtBytes(h.total) + '</b></span>';
				renderProto(h);
			} else if (!state.selected) {
				selectHost(state.totalHost ? TOTAL_IP : (state.hosts[0] ? state.hosts[0].ip : null));
			}
		}).catch(function () {});   // keep stale data + the refresh timer alive on a failed fetch
	}
	// Re-render the open selection once status lands: init() fires checkStatus and
	// loadHosts concurrently, and selectHost reads state.probeEnabled, which is
	// undefined until status resolves. If hosts won the race, a probe-enabled box
	// showed no Probe button until the user clicked another device.
	function checkStatus() {
		return api({ action: 'status' }).then(function (s) {
			var hadProbe = state.probeEnabled;
			state.probeEnabled = !!s.probe;
			var b = el('#bwd-banner');
			if (!s.enabled) {
				b.hidden = false;
				b.innerHTML = 'BandwidthD is <b>disabled</b>. Enable it under <a href="/ui/bandwidthd/general">Settings</a>.';
			} else if (!s.have_data) {
				b.hidden = false;
				b.innerHTML = 'Data logging is on — collecting traffic now. Charts populate after the first interval (~3 minutes).';
			} else {
				b.hidden = true;
			}
			// see the note above checkStatus(). Only when the answer changed: on the
			// 60 s tick a blind re-select rebuilt the override editor (discarding
			// whatever was being typed) and re-collapsed the daily table every minute.
			if (state.selected && hadProbe !== state.probeEnabled) { selectHost(state.selected); }
		}).catch(function () {});
	}

	/* ---------- wire up ---------- */
	function exportUrl(fmt) {
		var p = { format: fmt, period: state.period };
		var rng = effRange();
		if (rng.from) { p.from = rng.from; }
		if (rng.to)   { p.to = rng.to; }
		if (tagParam()) { p.tags = tagParam(); }   // honor the active tag filter ("current view")
		return API + 'export?' + Object.keys(p).map(function (k) {
			return encodeURIComponent(k) + '=' + encodeURIComponent(p[k]);
		}).join('&');
	}
	function init() {
		var csvBtn = el('#bwd-export-csv'), jsonBtn = el('#bwd-export-json');
		if (csvBtn)  { csvBtn.addEventListener('click', function () { window.location = exportUrl('csv'); }); }
		if (jsonBtn) { jsonBtn.addEventListener('click', function () { window.location = exportUrl('json'); }); }
		document.querySelectorAll('.bwd-viewtoggle button').forEach(function (btn) {
			btn.addEventListener('click', function () {
				document.querySelectorAll('.bwd-viewtoggle button').forEach(function (b) { b.classList.remove('is-active'); });
				btn.classList.add('is-active');
				state.view = btn.dataset.view;
				renderList();
				if (state.selected) { var h = hostByIp(state.selected); if (h) { el('#bwd-detail-title').textContent = label(h); } }
			});
		});
		document.querySelectorAll('.bwd-sort').forEach(function (btn) {
			btn.addEventListener('click', function () {
				document.querySelectorAll('.bwd-sort').forEach(function (b) { b.classList.remove('is-active'); });
				btn.classList.add('is-active');
				state.sort = btn.dataset.sort;
				renderList();
			});
		});
		el('#bwd-search').addEventListener('input', function (e) {
			state.search = e.target.value;
			renderList();
		});
		// ---- unified Window selector (presets + Custom) ----
		function reloadRange() {
			loadOverview();
			loadHosts().then(function () { if (state.selected) { refreshSelected(); } });
		}
		function clearWindows() {
			document.querySelectorAll('.bwd-window').forEach(function (b) {
				b.classList.remove('is-active'); b.setAttribute('aria-pressed', 'false');
			});
		}
		function showCustom(open) {
			var box = el('#bwd-customrange'), btn = el('.bwd-window-custom');
			if (box) { box.hidden = !open; }
			if (btn) { btn.setAttribute('aria-expanded', open ? 'true' : 'false'); }
		}
		// Pick a preset window: sets BOTH the duration and the CDF tier it implies.
		document.querySelectorAll('.bwd-window:not(.bwd-window-custom)').forEach(function (btn) {
			btn.addEventListener('click', function () {
				clearWindows();
				btn.classList.add('is-active'); btn.setAttribute('aria-pressed', 'true');
				showCustom(false);
				var secs = parseInt(btn.dataset.secs, 10);
				// store the duration, not a frozen 'from' — effRange() re-derives it
				// per fetch so the window keeps sliding under the 60s auto-refresh.
				state.rangeSecs = secs;
				state.period = parseInt(btn.dataset.period, 10);
				state.windowLabel = WINDOW_WORD[secs] || 'this window';
				state.from = 0; state.to = 0;
				el('#bwd-from').value = ''; el('#bwd-to').value = '';
				reloadRange();
			});
		});
		// Custom: reveal the From/To inputs (does not fetch until a date is picked).
		el('.bwd-window-custom').addEventListener('click', function () {
			var btn = this, open = btn.getAttribute('aria-expanded') !== 'true';
			showCustom(open);
			if (open) {
				clearWindows();
				btn.classList.add('is-active'); btn.setAttribute('aria-pressed', 'true');
			}
		});
		// A custom From/To overrides the preset: rangeSecs cleared, the CDF tier is
		// auto-derived from the chosen span so the right-resolution data is read.
		function applyCustom() {
			state.rangeSecs = 0;
			state.from = toEpoch(el('#bwd-from').value);
			state.to   = toEpoch(el('#bwd-to').value);
			var span = (state.to || Math.floor(Date.now() / 1000)) - (state.from || 0);
			state.period = spanToPeriod(span > 0 ? span : 31536000);
			state.windowLabel = 'custom range';
			reloadRange();
		}
		el('#bwd-from').addEventListener('change', applyCustom);
		el('#bwd-to').addEventListener('change', applyCustom);
		// Reset → back to the default 24h window.
		el('#bwd-range-reset').addEventListener('click', function () {
			var def = document.querySelector('.bwd-window[data-secs="86400"]');
			if (def) { def.click(); }
		});
		checkStatus();
		loadOverview();
		loadHosts();
		function tick() {
			checkStatus();
			loadOverview();
			loadHosts().then(function () { if (state.selected) { refreshSelected(); } });
		}
		// A background tab must not cost the firewall a full CDF scan per endpoint
		// every minute; catch up once when it becomes visible again.
		setInterval(function () { if (!document.hidden) { tick(); } }, 60000);
		document.addEventListener('visibilitychange', function () { if (!document.hidden) { tick(); } });
	}

	if (document.readyState !== 'loading') { init(); }
	else { document.addEventListener('DOMContentLoaded', init); }
})();
