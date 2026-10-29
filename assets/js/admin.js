/**
 * 后台「互访对账」相关的交互。
 *
 * 干四件事：
 *   ① 对账表里的「删除订阅」→ 确认 → 调 FreshRSS 取消 → 那一行淡出
 *   ② 评论列表「未订阅」旁边的「添加订阅」→ 探测 feed → 调 FreshRSS 订阅 → 标记变绿
 *   ③ 「他站上最近更新」里没数据的行 → 自动分批向 FreshRSS 现查最新文章时间
 *   ④ 二道复核：FreshRSS 说他没更新（或查不到）的站 → 自己访问他的 feed 和站点，
 *      两个都打不开才算死站（FreshRSS 的 GReader API 不吐抓取错误状态，探不到只是线索）
 *
 * 不引任何依赖，用原生 fetch。按钮都用事件委托，因为评论列表是整页渲染、
 * 对账表还会被筛选重绘，直接绑在元素上会丢。
 */
(function () {
	'use strict';

	var cfg = window.WBF_ADMIN || {};
	if (!cfg.ajax || !cfg.nonce) {
		return;
	}

	/*
	 * 进行中的请求登记簿 + 失败计数。
	 *
	 * 为什么要这两个东西：探查/复核的每一批在服务端要跑一两分钟，用户不会干等着
	 * —— 他会去点筛选、会关标签页重开。旧页面的请求必须跟着导航一起停掉，不然
	 * 新旧页面各跑各的，服务端就堆起一摞两分钟的长任务，谁都挤不动。
	 * 失败计数是另一个教训：批请求被拦（WAF/超时）时如果立刻原样重试，几十毫秒
	 * 一轮无限空转，浏览器直接弹「页面无响应」。失败必须退避、必须有上限。
	 */
	var inflight = [];
	var probeTries = 0;
	var verifyTries = 0;

	window.addEventListener('pagehide', function () {
		inflight.forEach(function (c) {
			try { c.abort(); } catch (e) {}
		});
		inflight = [];
	});

	function trackedFetch(opts) {
		var ctl = ('AbortController' in window) ? new AbortController() : null;
		if (ctl) {
			inflight.push(ctl);
			opts.signal = ctl.signal;
		}
		return fetch(opts.url, opts).finally(function () {
			if (ctl) {
				var i = inflight.indexOf(ctl);
				if (i > -1) inflight.splice(i, 1);
			}
		});
	}

	/**
	 * 右下角浮一条提示，3 秒后自己消失。
	 */
	function toast(msg, ok) {
		var el = document.createElement('div');
		el.textContent = msg;
		el.style.cssText = [
			'position:fixed', 'right:24px', 'bottom:24px', 'z-index:100000',
			'max-width:420px', 'padding:10px 14px', 'border-radius:5px',
			'font-size:13px', 'line-height:1.6', 'box-shadow:0 6px 20px rgba(0,0,0,.18)',
			ok ? 'background:#edfaef;border:1px solid #b8e0c0;color:#1e7b34;'
			   : 'background:#fcf0f1;border:1px solid #f0c9cb;color:#8a2424;',
		].join(';');
		document.body.appendChild(el);
		setTimeout(function () {
			el.style.transition = 'opacity .3s';
			el.style.opacity = '0';
			setTimeout(function () { el.remove(); }, 320);
		}, 3200);
	}

	function post(data) {
		return postAs(data, 'wbf_sub_edit');
	}

	function postAs(data, action) {
		var fd = new FormData();
		fd.append('action', action);
		fd.append('nonce', cfg.nonce);
		Object.keys(data).forEach(function (k) {
			fd.append(k, data[k]);
		});
		return fetch(cfg.ajax, {
			method: 'POST',
			credentials: 'same-origin',
			body: fd,
		}).then(function (r) {
			return r.json();
		});
	}

	function fail(res) {
		var msg = (res && res.data && res.data.message) ? res.data.message : '操作失败，稍后再试。';
		toast(msg, false);
	}

	// ① 删除订阅
	document.addEventListener('click', function (e) {
		var btn = e.target.closest ? e.target.closest('.wbf-unsub-btn') : null;
		if (!btn) {
			return;
		}
		e.preventDefault();

		var host = btn.getAttribute('data-host') || '';
		var title = btn.getAttribute('data-title') || host;

		if (!window.confirm('确定取消订阅「' + title + '」？\n\n这会直接改 FreshRSS 里的订阅，不是只从这张表里划掉。取消后还能再订回来。')) {
			return;
		}

		btn.disabled = true;
		btn.textContent = '处理中…';

		post({ do: 'unsubscribe', host: host, title: title }).then(function (res) {
			if (res && res.success) {
				var tr = btn.closest('tr');
				if (tr) {
					tr.style.transition = 'opacity .3s';
					tr.style.opacity = '0';
					setTimeout(function () { tr.remove(); }, 320);
				}
				toast(res.data.message, true);
			} else {
				btn.disabled = false;
				btn.textContent = '删除订阅';
				fail(res);
			}
		}).catch(function () {
			btn.disabled = false;
			btn.textContent = '删除订阅';
			toast('网络错误，没发出去。', false);
		});
	});

	// ② 添加订阅
	document.addEventListener('click', function (e) {
		var btn = e.target.closest ? e.target.closest('.wbf-sub-add') : null;
		if (!btn) {
			return;
		}
		e.preventDefault();

		var site = btn.getAttribute('data-site') || '';
		var title = btn.getAttribute('data-title') || '';
		if (!site) {
			return;
		}

		var old = btn.textContent;
		btn.disabled = true;
		btn.textContent = '找 feed…';

		post({ do: 'subscribe', site: site, title: title }).then(function (res) {
			if (res && res.success) {
				btn.textContent = '已订阅';
				btn.style.color = '#1e7b34';

				var mark = btn.parentNode.querySelector('.wbf-unsub');
				if (mark) {
					mark.textContent = '已订阅';
					mark.style.background = '#edfaef';
					mark.style.color = '#1e7b34';
					mark.style.borderColor = '#b8e0c0';
				}
				toast(res.data.message, true);
			} else {
				btn.disabled = false;
				btn.textContent = old;
				fail(res);
			}
		}).catch(function () {
			btn.disabled = false;
			btn.textContent = old;
			toast('网络错误，没发出去。', false);
		});
	});

	// ③ 「他站上最近更新」：同步数据只覆盖近一个月，窗口内没动静的行显示「查询中」，
	//    页面打开后自动分批去 FreshRSS 现查（每站只要最新一篇，查完服务端缓存 24 小时）。
	function daysText(d) {
		if (d < 1) return '今天';
		if (d < 30) return d + ' 天';
		if (d < 365) return Math.floor(d / 30) + ' 个月';
		var y = Math.floor(d / 365);
		var m = Math.floor((d % 365) / 30);
		return m > 0 ? y + ' 年 ' + m + ' 个月' : y + ' 年';
	}

	function renderCell(cell, item, now) {
		var ts = item && item.ts ? parseInt(item.ts, 10) : 0;
		if (ts > 0) {
			var days = Math.max(0, Math.floor((now - ts) / 86400));
			var mark = '';
			var verifyMark = '';
			if (days > 365) {
				mark = ' · <b style="color:#d63638;">一年以上没更新</b>';
				verifyMark = '<br><span class="wbf-verify-pending" style="font-size:12px;color:#8c8f94;">待复核</span>';
				cell.setAttribute('data-need-verify', '1');
			} else if (days > 180) {
				mark = ' · <b style="color:#996800;">半年以上没更新</b>';
				verifyMark = '<br><span class="wbf-verify-pending" style="font-size:12px;color:#8c8f94;">待复核</span>';
				cell.setAttribute('data-need-verify', '1');
			}
			var dt = new Date(ts * 1000);
			var pad = function (n) { return (n < 10 ? '0' : '') + n; };
			var local = dt.getFullYear() + '-' + pad(dt.getMonth() + 1) + '-' + pad(dt.getDate());
			cell.innerHTML = local + '<br><span style="font-size:12px;color:#8c8f94;">' + daysText(days) + '前' + mark + '</span>' + verifyMark;
		} else if (item && item.miss) {
			cell.innerHTML = '<span style="color:#8c8f94;" title="FreshRSS 里没有这个站的订阅，可能已被取消">不在订阅里</span>';
		} else {
			// FreshRSS 拿不到他的更新 —— 只是线索，标上待复核，由二道复核下结论。
			cell.innerHTML = '<span style="color:#8c8f94;" title="FreshRSS 里也拿不到他的更新，可能 feed 坏了或订阅已失效">查不到更新</span>'
				+ '<br><span class="wbf-verify-pending" style="font-size:12px;color:#8c8f94;">待复核</span>';
			cell.setAttribute('data-need-verify', '1');
		}
	}

	function probeStale(done) {
		var cells = document.querySelectorAll('.wbf-lp[data-host]');
		if (!cells.length) {
			if (done) done();
			return;
		}

		var pending = {};
		cells.forEach(function (cell) {
			if (cell.getAttribute('data-done')) return;
			if (!cell.querySelector('.wbf-lp-pending')) return;
			var h = cell.getAttribute('data-host');
			if (h && !pending[h]) pending[h] = [];
			if (h) pending[h].push(cell);
		});
		var hosts = Object.keys(pending);
		if (!hosts.length) {
			// 都探完了（或没有要探的），接着跑二道复核。
			if (done) done();
			return;
		}

		// 一批 12 个：服务端每个站要发一次 FreshRSS 请求，批太大容易撞 PHP 超时。
		var batch = hosts.splice(0, 12);

		var fd = new FormData();
		fd.append('action', 'wbf_probe_stale');
		fd.append('nonce', cfg.nonce);
		batch.forEach(function (h) { fd.append('hosts[]', h); });

		trackedFetch({
			url: cfg.ajax,
			method: 'POST',
			credentials: 'same-origin',
			body: fd,
		}).then(function (r) { return r.json(); }).then(function (res) {
			if (res && res.success && res.data && res.data.items) {
				probeTries = 0;
				var now = parseInt(res.data.now, 10) || Math.floor(Date.now() / 1000);
				Object.keys(res.data.items).forEach(function (h) {
					(pending[h] || []).forEach(function (cell) {
						cell.setAttribute('data-done', '1');
						renderCell(cell, res.data.items[h], now);
					});
				});
				// 服务端说忙（别的请求正在跑同类任务）：这批先按查过算，别再发。
				if (res.data.busy) {
					batch.forEach(function (h) {
						(pending[h] || []).forEach(function (cell) {
							cell.setAttribute('data-done', '1');
							cell.querySelector('.wbf-lp-pending') && (cell.innerHTML = '<span style="color:#8c8f94;">稍后再查</span>');
						});
					});
					if (done) done();
					return;
				}
			} else {
				// 失败退避：立刻原样重试会几十毫秒一轮空转，浏览器弹「页面无响应」的教训。
				probeTries++;
				if (probeTries > 2) {
					batch.forEach(function (h) {
						(pending[h] || []).forEach(function (cell) {
							cell.setAttribute('data-done', '1');
							cell.querySelector('.wbf-lp-pending') && (cell.innerHTML = '<span style="color:#8c8f94;">查询失败</span>');
						});
					});
					if (done) done();
					return;
				}
				setTimeout(function () { probeStale(done); }, 5000);
				return;
			}
			// 失败或部分失败都不弹窗打扰：格子停在「查询中」，24 小时内下次打开会带上缓存。
			probeStale(done);
		}).catch(function () {
			probeTries++;
			if (probeTries > 2) {
				if (done) done();
				return;
			}
			setTimeout(function () { probeStale(done); }, 5000);
		});
	}

	// 表格是页面加载完才由 WP 渲染的，这里 DOM ready 后直接跑一轮。
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', function () {
			setTimeout(function () { probeStale(verifyDue); }, 400);
		});
	} else {
		setTimeout(function () { probeStale(verifyDue); }, 400);
	}

	// ④ 二道复核：FreshRSS 的结论只是线索，自己访问过才算数。
	//    找出所有标了 data-need-verify 且没核过的行，一批 8 个（每站最多两次请求，
	//    10 秒超时，服务端放宽了执行时限），地址从行属性里带回来。
	function renderVerify(cell, v) {
		cell.setAttribute('data-verify-done', '1');
		var state = v && v.state ? v.state : 'err';
		var note = v && v.note ? String(v.note) : '';
		var date = v && v.checked ? (function () {
			var d = new Date(parseInt(v.checked, 10) * 1000);
			var pad = function (n) { return (n < 10 ? '0' : '') + n; };
			return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
		})() : '';

		if (state === 'dead') {
			cell.innerHTML = '<b style="color:#d63638;">站点无法访问</b>'
				+ '<br><span style="font-size:12px;color:#8c8f94;" title="' + note.replace(/"/g, '&quot;') + '">'
				+ (date ? date + '复核' : '已复核') + (note ? ' · ' + note.slice(0, 30) : '') + '</span>';
		} else if (state === 'ok-site') {
			cell.innerHTML = '<b style="color:#996800;">feed 读不到 · 站点可访问</b>'
				+ '<br><span style="font-size:12px;color:#8c8f94;" title="' + note.replace(/"/g, '&quot;') + '">'
				+ (date ? date + '复核' : '已复核') + '</span>';
		} else if (state === 'ok-feed' && v.ts > 0) {
			// 复核到 feed 还活着且有新文章 —— 按 FreshRSS 之前的结论显示就错了，重渲染。
			cell.removeAttribute('data-need-verify');
			renderCell(cell, { ts: v.ts }, Math.floor(Date.now() / 1000));
		} else {
			// ok-feed 但抠不到时间：feed 通着，保守按「查不到更新」显示，不再标待复核。
			cell.innerHTML = '<span style="color:#8c8f94;">feed 通 · 没抠到更新时间</span>';
		}
	}

	function verifyDue() {
		var cells = document.querySelectorAll('.wbf-lp[data-host][data-need-verify]');
		if (!cells.length) return;

		var picked = [];
		cells.forEach(function (cell) {
			if (cell.getAttribute('data-verify-done')) return;
			picked.push(cell);
		});
		if (!picked.length) return;

		var batch = picked.splice(0, 6);
		var fd = new FormData();
		fd.append('action', 'wbf_verify_sites');
		fd.append('nonce', cfg.nonce);
		batch.forEach(function (cell) {
			fd.append('hosts[]', cell.getAttribute('data-host') || '');
			fd.append('feeds[]', cell.getAttribute('data-feed') || '');
			fd.append('sites[]', cell.getAttribute('data-site') || '');
		});

		trackedFetch({
			url: cfg.ajax,
			method: 'POST',
			credentials: 'same-origin',
			body: fd,
		}).then(function (r) { return r.json(); }).then(function (res) {
			if (res && res.success && res.data && res.data.items) {
				verifyTries = 0;
				batch.forEach(function (cell) {
					var h = cell.getAttribute('data-host');
					if (res.data.items[h]) {
						renderVerify(cell, res.data.items[h]);
					} else {
						/*
						 * 这批没轮到它。绝不能把标记撤了重发 —— 那样同一批会无限循环，
						 * 请求打不完，页面就废了。标成「稍后复核」，下次打开页面再说。
						 */
						cell.setAttribute('data-verify-done', '1');
						cell.innerHTML = cell.innerHTML + '<br><span style="font-size:12px;color:#8c8f94;">稍后复核</span>';
					}
				});
			} else {
				// 失败也一律向前走：标记保留，下次打开页面会重新复核。绝不原地重试。
				verifyTries++;
				batch.forEach(function (cell) { cell.setAttribute('data-verify-done', '1'); });
				if (verifyTries <= 2) {
					setTimeout(verifyDue, 5000);
					return;
				}
			}
			verifyDue();
		}).catch(function () {
			verifyTries++;
			batch.forEach(function (cell) { cell.setAttribute('data-verify-done', '1'); });
			if (verifyTries <= 2) {
				setTimeout(verifyDue, 5000);
				return;
			}
			verifyDue();
		});
	}
})();
