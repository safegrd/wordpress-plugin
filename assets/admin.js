/* SafeGrd Backup: the Tools page. No dependencies. */
(function () {
	"use strict";

	var cfg = window.SafeGrdAdmin || {};

	function post(action, data) {
		var body = new URLSearchParams();
		body.set("action", action);
		body.set("nonce", cfg.nonce);
		Object.keys(data || {}).forEach(function (k) { body.set(k, data[k]); });
		return fetch(cfg.ajax, { method: "POST", credentials: "same-origin", body: body })
			.then(function (r) { return r.json().catch(function () { return { success: false, data: { message: "The site answered HTTP " + r.status + "." } }; }); })
			.then(function (j) {
				if (!j || !j.success) {
					throw new Error((j && j.data && j.data.message) || "The request failed.");
				}
				return j.data;
			});
	}

	function notice(kind, text) {
		var n = document.getElementById("safegrd-notice");
		if (!n) return;
		n.className = "notice inline notice-" + kind;
		n.querySelector("p").textContent = text;
		n.hidden = false;
	}

	// Connecting: a device sign-in in a new tab, polled until approved, or a
	// personal access token pasted here. Either can stop to ask which
	// organization the site belongs to.
	var connectBtn = document.getElementById("safegrd-connect-btn");
	var tokenBtn = document.getElementById("safegrd-token-btn");
	var orgBtn = document.getElementById("safegrd-org-btn");
	var viaToken = false;

	function custody() {
		var picked = document.querySelector("input[name=safegrd_custody]:checked");
		return picked ? picked.value : "safegrd";
	}

	function busy(on) {
		[connectBtn, tokenBtn, orgBtn].forEach(function (b) { if (b) b.disabled = on; });
	}

	function failed(e) {
		busy(false);
		document.getElementById("safegrd-waiting").hidden = true;
		notice("error", e.message);
	}

	if (connectBtn) {
		connectBtn.addEventListener("click", function () {
			// Opened now, inside the click, so a popup blocker lets it through.
			var tab = window.open("about:blank", "_blank");
			viaToken = false;
			busy(true);
			post("safegrd_connect_start", { custody: custody() }).then(function (s) {
				document.getElementById("safegrd-code").textContent = s.user_code;
				var link = document.getElementById("safegrd-approve-link");
				link.href = s.approve_url;
				document.getElementById("safegrd-waiting").hidden = false;
				if (tab) {
					tab.location = s.approve_url;
				}
				poll();
			}).catch(function (e) {
				if (tab) tab.close();
				failed(e);
			});
		});
	}

	if (tokenBtn) {
		tokenBtn.addEventListener("click", function () {
			viaToken = true;
			busy(true);
			post("safegrd_connect_token", { token: document.getElementById("safegrd-token-text").value, custody: custody() })
				.then(finish).catch(failed);
		});
	}

	if (orgBtn) {
		orgBtn.addEventListener("click", function () {
			var org = document.getElementById("safegrd-org").value;
			busy(true);
			var sent = viaToken
				? post("safegrd_connect_token", { token: document.getElementById("safegrd-token-text").value, custody: custody(), org: org })
				: post("safegrd_connect_org", { org: org });
			sent.then(finish).catch(failed);
		});
	}

	function poll() {
		setTimeout(function () {
			post("safegrd_connect_poll", {}).then(function (p) {
				if (p.status === "pending") {
					poll();
					return;
				}
				finish(p);
			}).catch(failed);
		}, 2000);
	}

	function finish(p) {
		document.getElementById("safegrd-waiting").hidden = true;
		if (p.status === "pick_org") {
			var sel = document.getElementById("safegrd-org");
			sel.textContent = "";
			p.orgs.forEach(function (o) {
				var opt = document.createElement("option");
				opt.value = o.id;
				opt.textContent = o.name + " (" + o.id + ")";
				sel.appendChild(opt);
			});
			document.getElementById("safegrd-orgs").hidden = false;
			busy(false);
			connectBtn.disabled = true;
			tokenBtn.disabled = true;
			return;
		}
		document.getElementById("safegrd-token-text").value = "";
		document.getElementById("safegrd-orgs").hidden = true;
		var who = p.user_email ? " as " + p.user_email : "";
		if (p.identity) {
			document.getElementById("safegrd-identity-text").value = p.identity;
			document.getElementById("safegrd-identity").hidden = false;
			connectBtn.hidden = true;
			document.getElementById("safegrd-token").hidden = true;
			if (p.escrow_failed) {
				notice("warning", "Connected, but SafeGrd did not store the key. The key below is the only copy: save it now.");
			} else {
				notice("success", "Connected" + who + ".");
			}
			return;
		}
		notice("success", "Connected" + who + ". The first backup is starting.");
		setTimeout(function () { window.location.reload(); }, 1500);
	}

	// Connected: what SafeGrd recorded, and Back up now.
	var status = document.getElementById("safegrd-status");
	if (!status) return;

	var PARTS = [
		["database", "Database"],
		["plugins", "Plugins"],
		["themes", "Themes"],
		["uploads", "Uploads"],
		["others", "Others"]
	];

	function text(el, value) {
		var node = document.createElement("span");
		node.textContent = value;
		el.appendChild(node);
	}

	function el(tag, cls, content) {
		var e = document.createElement(tag);
		if (cls) e.className = cls;
		if (content) e.textContent = content;
		return e;
	}

	function copyText(value, btn) {
		var done = function () { btn.textContent = "Copied"; };
		if (navigator.clipboard) {
			navigator.clipboard.writeText(value).then(done, function () {});
		}
	}

	// Tabs: the hash names the open one, so a reload keeps it and Back
	// goes to the last. #restore/<snapshot> opens that backup.
	function openTab(name) {
		name = String(name || "backups").split("/")[0];
		var found = false;
		Array.prototype.forEach.call(document.querySelectorAll(".safegrd-panel"), function (p) {
			var on = p.getAttribute("data-panel") === name;
			p.hidden = !on;
			found = found || on;
		});
		if (!found) return openTab("backups");
		Array.prototype.forEach.call(document.querySelectorAll("#safegrd-tabs .nav-tab"), function (t) {
			t.classList.toggle("nav-tab-active", t.getAttribute("data-tab") === name);
		});
		if (name === "logs") loadLogs();
	}
	Array.prototype.forEach.call(document.querySelectorAll("[data-tab], [data-tab-link]"), function (a) {
		a.addEventListener("click", function (ev) {
			ev.preventDefault();
			var name = a.getAttribute("data-tab") || a.getAttribute("data-tab-link");
			if (location.hash !== "#" + name) history.pushState(null, "", "#" + name);
			openTab(name);
		});
	});
	function route() {
		var h = (location.hash || "#backups").slice(1);
		openTab(h);
		var id = h.indexOf("restore/") === 0 ? h.slice(8) : "";
		if (id && backups && (!opened || opened.id !== id)) {
			var s = backups.filter(function (b) { return b.id === id; })[0];
			if (s) openBackup(s);
		}
	}
	window.addEventListener("popstate", route);
	openTab((location.hash || "#backups").slice(1));

	var logKeys = {};

	function renderSnapshots(list, error) {
		var box = document.getElementById("safegrd-snapshots");
		box.textContent = "";
		if (error) {
			box.appendChild(el("p", "description", "SafeGrd did not answer: " + error));
			return;
		}
		if (!list || !list.length) {
			box.appendChild(el("p", "description", "None recorded yet."));
			return;
		}
		var table = el("table", "widefat striped");
		var head = table.createTHead().insertRow();
		["Taken", "Kind", "Site size", "Uploaded", "Status", "Locked until", ""].forEach(function (h) {
			head.appendChild(el("th", "", h));
		});
		var body = table.createTBody();
		list.forEach(function (s) {
			var row = body.insertRow();
			var t = row.insertCell();
			t.appendChild(el("strong", "", s.created || ""));
			t.appendChild(el("div", "safegrd-id", s.id));
			[s.kind, s.site, s.size, s.status, s.locked].forEach(function (v) {
				text(row.insertCell(), v || "");
			});
			var cell = row.insertCell();
			cell.className = "safegrd-actions";
			if (s.status !== "Failed") {
				var open = el("a", "", "Open");
				open.href = "#restore/" + s.id;
				open.addEventListener("click", function (ev) {
					ev.preventDefault();
					history.pushState(null, "", "#restore/" + s.id);
					route();
				});
				cell.appendChild(open);
				cell.appendChild(document.createTextNode(" "));
			}
			if (s.log) {
				var a = el("a", "", "Log");
				a.href = "#logs";
				a.addEventListener("click", function (ev) {
					ev.preventDefault();
					history.replaceState(null, "", "#logs");
					openTab("logs");
					showLog(s.id);
				});
				cell.appendChild(a);
			}
		});
		box.appendChild(table);
	}

	// Hosted storage held against the plan, and the server's warning in
	// its own words: refused, billed or in grace, from 80% of the plan.
	function showStorage(line, warning, used, quota) {
		var node = document.getElementById("safegrd-storage");
		if (!node || !line) return;
		var meter = document.getElementById("safegrd-meter");
		if (meter && quota > 0) {
			var pct = Math.min(100, used * 100 / quota);
			meter.firstElementChild.style.width = Math.max(pct, 0.5) + "%";
			meter.className = "safegrd-meter" + (pct >= 100 ? " is-full" : pct >= 80 ? " is-high" : "");
			meter.hidden = false;
		}
		node.textContent = line + ".";
		if (warning) {
			var w = el("strong", "safegrd-warn", " " + warning);
			node.appendChild(w);
		}
	}

	// Where the site cannot reach itself, no loopback or WP-Cron runs the
	// next slice, so this page runs it while it is open. The server's lock
	// keeps it to one slice at a time.
	function drive() {
		if (cfg.loopback) return Promise.resolve();
		return post("safegrd_tick", {}).catch(function () {});
	}

	var backupBtn = document.getElementById("safegrd-backup-btn");
	var watching = false;

	function refresh(local) {
		return post("safegrd_status", local ? { local: "1" } : {}).then(function (s) {
			document.getElementById("safegrd-last").innerHTML = s.last;
			backupBtn.disabled = !!s.running;
			showDownloads(s.downloads);
			if (!local) {
				if (s.drill) {
					var drill = document.getElementById("safegrd-drill");
					drill.textContent = s.drill;
					if (s.drill_next) {
						drill.appendChild(el("span", "description", " " + s.drill_next + " "));
						var plans = el("a", "", "What each plan tests");
						plans.href = cfg.server + "/pricing";
						plans.target = "_blank";
						plans.rel = "noopener";
						drill.appendChild(plans);
					}
				}
				showStorage(s.storage, s.storage_warning, s.storage_used, s.storage_quota);
				renderSnapshots(s.snapshots, s.snapshots_error);
			}
			return s;
		});
	}

	function watch() {
		if (watching) return;
		watching = true;
		var tick = function () {
			drive().then(function () { return refresh(true); }).then(function (s) {
				if (s.running) {
					setTimeout(tick, 4000);
					return;
				}
				watching = false;
				refresh(false);
			}).catch(function () { watching = false; });
		};
		setTimeout(tick, 3000);
	}

	backupBtn.addEventListener("click", function () {
		backupBtn.disabled = true;
		post("safegrd_backup_now", {}).then(function (r) {
			notice("info", r.message);
			watch();
		}).catch(function (e) {
			backupBtn.disabled = false;
			notice("error", e.message);
		});
	});

	// Settings: how often, from what hour, and what to leave out.
	var save = document.getElementById("safegrd-save-settings");
	if (save) {
		save.addEventListener("click", function () {
			save.disabled = true;
			post("safegrd_save_settings", {
				frequency: document.getElementById("safegrd-frequency").value,
				hour: document.getElementById("safegrd-hour").value,
				leave_out: document.getElementById("safegrd-exclude").value
			}).then(function (r) {
				document.getElementById("safegrd-next").textContent = r.next;
				document.getElementById("safegrd-cadence").textContent = r.cadence;
				document.getElementById("safegrd-exclude").value = r.leave_out;
				notice("success", r.message);
			}).catch(function (e) { notice("error", e.message); }).then(function () { save.disabled = false; });
		});
	}

	var copy = document.getElementById("safegrd-copy-diagnostics");
	if (copy) {
		copy.addEventListener("click", function () {
			copyText(document.getElementById("safegrd-diagnostics").value, copy);
		});
	}

	// Logs: the runs kept, and one run's lines.
	function loadLogs() {
		var box = document.getElementById("safegrd-logs");
		post("safegrd_logs", {}).then(function (r) {
			box.textContent = "";
			logKeys = {};
			if (!r.runs.length) {
				box.appendChild(el("p", "description", "None yet. A backup, restore or download writes one."));
				return;
			}
			var table = el("table", "widefat striped");
			var head = table.createTHead().insertRow();
			["Started", "What", "Of", "Outcome", ""].forEach(function (h) { head.appendChild(el("th", "", h)); });
			var body = table.createTBody();
			r.runs.forEach(function (run) {
				logKeys[run.key] = true;
				var row = body.insertRow();
				[run.started, run.kind, run.label, run.status].forEach(function (v) { text(row.insertCell(), v); });
				var a = el("a", "", "View");
				a.href = "#logs";
				a.addEventListener("click", function (ev) { ev.preventDefault(); showLog(run.key); });
				row.insertCell().appendChild(a);
			});
			box.appendChild(table);
		}).catch(function (e) { box.textContent = e.message; });
	}

	function showLog(key) {
		var pre = document.getElementById("safegrd-log-text");
		post("safegrd_logs", { key: key }).then(function (r) {
			pre.textContent = r.text;
			pre.hidden = false;
			document.getElementById("safegrd-log-actions").hidden = false;
			pre.scrollIntoView({ block: "nearest" });
		}).catch(function (e) { notice("error", e.message); });
	}

	var copyLog = document.getElementById("safegrd-copy-log");
	if (copyLog) {
		copyLog.addEventListener("click", function () {
			copyText(document.getElementById("safegrd-log-text").textContent, copyLog);
		});
	}

	var disconnect = document.getElementById("safegrd-disconnect");
	if (disconnect) {
		disconnect.addEventListener("click", function (ev) {
			ev.preventDefault();
			if (!window.confirm("Disconnect this site? It stops backing up, and the backups already taken stay in SafeGrd.")) return;
			post("safegrd_disconnect", {}).then(function () { window.location.reload(); })
				.catch(function (e) { notice("error", e.message); });
		});
	}

	// Restore and download. The list holds every WordPress backup in the
	// account, this site's shown first. Opening one shows its panel: what it
	// holds, what to take from it, and a review before anything changes.
	var backups = null;
	var LIST_PAGE = 20;
	var listShown = LIST_PAGE;
	var filter = document.getElementById("safegrd-site-filter");
	var panel = document.getElementById("safegrd-backup");
	var opened = null;
	var pick = null;
	var PART_INFO = {
		database: "Every table, with posts, pages, settings and users.",
		plugins: "Every plugin's files. Their settings are in the database.",
		themes: "Every theme's files.",
		uploads: "Media and anything else under uploads.",
		others: "The rest of wp-content, such as mu-plugins and languages."
	};
	var SOME = { plugins: "Some plugins only", themes: "Some themes only", database: "Some tables only" };

	function host(u) {
		try { return new URL(u).host; } catch (e) { return u || ""; }
	}

	function plural(n, what) {
		return Number(n).toLocaleString() + " " + what + (Number(n) === 1 ? "" : "s");
	}

	function sizeOf(bytes) {
		var u = ["B", "KB", "MB", "GB", "TB"];
		var i = 0;
		bytes = Number(bytes) || 0;
		while (bytes >= 1024 && i < u.length - 1) { bytes /= 1024; i++; }
		return (i ? bytes.toFixed(1) : bytes) + " " + u[i];
	}

	function fillFilter() {
		var others = {};
		var mine = backups.some(function (s) { return s.node_id === cfg.node; });
		backups.forEach(function (s) { if (s.node_id !== cfg.node && !others[s.node_id]) others[s.node_id] = s.site; });
		filter.textContent = "";
		var add = function (v, label) {
			var o = el("option", "", label);
			o.value = v;
			filter.appendChild(o);
		};
		if (mine) add("this", "This site");
		Object.keys(others).forEach(function (n) { add(n, host(others[n]) + " (" + n + ")"); });
		add("all", "All sites");
		filter.value = mine ? "this" : "all";
	}

	if (filter) {
		filter.addEventListener("change", function () {
			listShown = LIST_PAGE;
			renderRestoreList();
		});
	}

	function renderRestoreList() {
		var box = document.getElementById("safegrd-restore-list");
		box.textContent = "";
		var f = filter.value;
		var list = backups.filter(function (s) {
			return f === "all" || (f === "this" ? s.node_id === cfg.node : s.node_id === f);
		});
		if (!list.length) {
			box.appendChild(el("p", "description", "No WordPress backups in this account yet."));
			return;
		}
		var table = el("table", "widefat striped safegrd-restore-table");
		var head = table.createTHead().insertRow();
		var cols = ["Taken", "Site", "Holds", "Test restore", ""];
		cols.forEach(function (h) { head.appendChild(el("th", "", h)); });
		var body = table.createTBody();
		list.slice(0, listShown).forEach(function (s) {
			var row = body.insertRow();
			if (opened && opened.id === s.id) row.className = "is-open";
			var t = row.insertCell();
			t.appendChild(el("strong", "", s.taken));
			t.appendChild(el("div", "safegrd-id", s.id));
			text(row.insertCell(), s.node_id === cfg.node ? "This site" : host(s.site));
			text(row.insertCell(), plural(s.tables, "table") + ", " + plural(s.files, "file") + ", " + s.size);
			text(row.insertCell(), s.verified ? "Passed" : "");
			var cell = row.insertCell();
			cell.className = "safegrd-actions";
			var btn = el("button", "button safegrd-restore-btn", "Open");
			btn.type = "button";
			btn.disabled = restoring;
			btn.addEventListener("click", function () { openBackup(s); });
			cell.appendChild(btn);
		});
		box.appendChild(table);
		if (list.length > listShown) {
			var more = el("button", "button-link safegrd-more-btn", "Show " + Math.min(LIST_PAGE, list.length - listShown) + " older of " + (list.length - listShown));
			more.type = "button";
			more.addEventListener("click", function () {
				listShown += LIST_PAGE;
				renderRestoreList();
			});
			box.appendChild(more);
		}
	}

	// Opens a backup in the panel. Its manifest, with each part's size and
	// the plugins, themes and tables, is read from storage once and cached
	// by the site, so it shows after a few seconds the first time.
	function openBackup(s) {
		// The list below shows the backup's site, so its row is in view.
		var f = filter.value;
		if (f !== "all" && (f === "this" ? s.node_id !== cfg.node : s.node_id !== f)) {
			filter.value = s.node_id === cfg.node ? "this" : s.node_id;
			listShown = LIST_PAGE;
		}
		opened = s;
		pick = { parts: {}, some: {}, items: { plugins: {}, themes: {}, database: {} }, contents: null, error: "", review: false };
		PARTS.forEach(function (p) { pick.parts[p[0]] = true; });
		history.replaceState(null, "", "#restore/" + s.id);
		openTab("restore");
		renderPanel();
		renderRestoreList();
		panel.hidden = false;
		panel.scrollIntoView({ block: "start", behavior: "smooth" });
		post("safegrd_restore_items", { snapshot: s.id }).then(function (c) {
			if (opened !== s) return;
			pick.contents = c;
			renderPanel();
		}).catch(function (e) {
			if (opened !== s) return;
			pick.error = e.message;
			renderPanel();
		});
	}

	function closeBackup() {
		opened = null;
		pick = null;
		panel.hidden = true;
		panel.textContent = "";
		history.replaceState(null, "", "#restore");
		renderRestoreList();
	}

	// The part's size, from the manifest once read, else from the list.
	function partFacts(part) {
		var c = pick.contents;
		var p = c && c.parts ? c.parts[part] : null;
		if (part === "database") {
			if (p) return plural(p.tables, "table") + ", " + plural(p.rows, "row") + ", " + sizeOf(p.bytes);
			return plural(opened.tables, "table");
		}
		if (p) return plural(p.files, "file") + ", " + sizeOf(p.bytes);
		return c ? "" : "...";
	}

	function itemsOf(part) {
		var c = pick.contents;
		if (!c) return [];
		return part === "database" ? c.tables : c[part];
	}

	function itemKey(part, it) {
		return part === "database" ? it.name : it.slug;
	}

	function describeItem(part, it) {
		if (part === "database") {
			return it.name + ", " + plural(it.rows, "row") + ", " + sizeOf(it.bytes);
		}
		var here = it.installed === null || it.installed === undefined ? "not installed here"
			: it.installed === it.version ? "same as installed" : it.installed + " installed";
		return it.name + " " + it.version + " (" + here + ")";
	}

	// What the restore will do, as the review lists it: one line per part.
	function chosen() {
		var parts = [];
		var items = {};
		var lines = [];
		var problem = "";
		PARTS.forEach(function (p) {
			var part = p[0];
			if (!pick.parts[part]) return;
			parts.push(part);
			if (pick.some[part]) {
				var on = Object.keys(pick.items[part]).filter(function (k) { return pick.items[part][k]; });
				if (!on.length) {
					problem = "Tick at least one of the " + (part === "database" ? "tables" : part) + ", or choose the whole part.";
					return;
				}
				items[part] = on;
				var names = itemsOf(part).filter(function (it) { return pick.items[part][itemKey(part, it)]; }).map(function (it) {
					return part === "database" ? it.name : it.name + " " + it.version;
				});
				lines.push((part === "database" ? "Tables" : p[1]) + ": " + names.join(", ") + " (" + on.length + " of " + itemsOf(part).length + ")");
			} else {
				lines.push(p[1] + ": all of it, " + partFacts(part));
			}
		});
		if (!parts.length) problem = "Tick at least one part.";
		return { parts: parts, items: items, lines: lines, problem: problem };
	}

	// What the person should know before restoring this choice.
	function notes(ch) {
		var out = [];
		var db = ch.parts.indexOf("database") >= 0;
		var prefix = pick.contents ? pick.contents.prefix : "";
		var users = db && (!ch.items.database || ch.items.database.indexOf(prefix + "users") >= 0);
		if (users) out.push("This site's users become the backup's. Afterwards, sign in with an administrator account of the backup.");
		if (db && opened.node_id !== cfg.node) out.push("This backup is of " + host(opened.site) + ". Its address is replaced with " + host(cfg.site) + " in the database, serialized data included.");
		if (ch.parts.indexOf("plugins") >= 0 && !ch.items.plugins) out.push("Plugins added since the backup are put aside with the rest. SafeGrd Backup stays the version running.");
		if ((ch.items.plugins || ch.items.themes) && !db) out.push("Settings are in the database, so restored plugins and themes keep this site's settings and whether each is active.");
		if (ch.parts.indexOf("others") >= 0) out.push("wp-config.php and .htaccess stay this site's own.");
		return out;
	}

	function renderPanel() {
		if (!opened) return;
		var s = opened;
		var c = pick.contents;
		panel.textContent = "";
		var head = el("div", "safegrd-card-head");
		head.appendChild(el("h2", "", "Backup of " + (s.node_id === cfg.node ? "this site" : host(s.site)) + ", taken " + s.taken));
		var close = el("button", "button-link", "Close");
		close.type = "button";
		close.addEventListener("click", closeBackup);
		head.appendChild(close);
		panel.appendChild(head);

		var facts = el("p", "safegrd-facts");
		[s.id, s.verified ? "Test restore passed" : "Not test-restored", c && c.wordpress_version ? "WordPress " + c.wordpress_version : "", c && c.php_version ? "PHP " + c.php_version : "", s.site].forEach(function (f) {
			if (f) facts.appendChild(el("span", "", f));
		});
		panel.appendChild(facts);
		if (!c && !pick.error) {
			panel.appendChild(el("p", "description safegrd-loading", "Reading what this backup holds from storage. The first time takes a few seconds."));
		}
		if (pick.error) {
			panel.appendChild(el("p", "safegrd-warn", "SafeGrd could not read this backup's contents: " + pick.error + " The whole parts can still be restored."));
		}
		if (c && !Object.keys(c.parts || {}).length) {
			panel.appendChild(el("p", "description", "This backup was taken by an older version of the plugin, which did not list each part's size or its plugins and themes. Every part can still be restored whole, and its tables one by one."));
		}

		var table = el("table", "widefat safegrd-parts-table");
		var th = table.createTHead().insertRow();
		["", "Part", "In this backup", "Take"].forEach(function (h) { th.appendChild(el("th", "", h)); });
		var body = table.createTBody();
		PARTS.forEach(function (p) {
			var part = p[0];
			var row = body.insertRow();
			var box = document.createElement("input");
			box.type = "checkbox";
			box.checked = !!pick.parts[part];
			box.id = "safegrd-part-" + part;
			box.addEventListener("change", function () {
				pick.parts[part] = box.checked;
				pick.review = false;
				renderPanel();
			});
			row.insertCell().appendChild(box);
			var name = row.insertCell();
			var label = el("label", "", p[1]);
			label.htmlFor = box.id;
			name.appendChild(label);
			name.appendChild(el("div", "description", PART_INFO[part]));
			text(row.insertCell(), partFacts(part));
			var how = row.insertCell();
			if (SOME[part]) {
				var sel = el("select");
				[["", "The whole part"], ["some", SOME[part]]].forEach(function (o) {
					var opt = el("option", "", o[1]);
					opt.value = o[0];
					sel.appendChild(opt);
				});
				sel.value = pick.some[part] ? "some" : "";
				sel.disabled = !pick.parts[part] || (!c && !pick.error) || !itemsOf(part).length;
				sel.setAttribute("aria-label", p[1] + ": whole or some");
				sel.addEventListener("change", function () {
					pick.some[part] = sel.value === "some";
					pick.review = false;
					renderPanel();
				});
				how.appendChild(sel);
			}
			if (SOME[part] && pick.parts[part] && pick.some[part]) {
				var irow = body.insertRow();
				irow.className = "safegrd-items-row";
				irow.insertCell();
				var ic = irow.insertCell();
				ic.colSpan = 3;
				var bar = el("p", "safegrd-items-bar");
				[["All", true], ["None", false]].forEach(function (a) {
					var link = el("button", "button-link", a[0]);
					link.type = "button";
					link.addEventListener("click", function () {
						itemsOf(part).forEach(function (it) { pick.items[part][itemKey(part, it)] = a[1]; });
						renderPanel();
					});
					bar.appendChild(link);
				});
				ic.appendChild(bar);
				var list = el("div", "safegrd-items-list");
				itemsOf(part).forEach(function (it) {
					var key = itemKey(part, it);
					var l = el("label");
					var b = document.createElement("input");
					b.type = "checkbox";
					b.value = key;
					b.checked = !!pick.items[part][key];
					b.addEventListener("change", function () {
						pick.items[part][key] = b.checked;
						pick.review = false;
						renderActions();
					});
					l.appendChild(b);
					l.appendChild(document.createTextNode(" " + describeItem(part, it)));
					list.appendChild(l);
				});
				ic.appendChild(list);
			}
		});
		panel.appendChild(table);
		var actions = el("div", "safegrd-panel-actions");
		actions.id = "safegrd-panel-actions";
		panel.appendChild(actions);
		renderActions();
	}

	// The notes, the buttons, and the review once asked for.
	function renderActions() {
		var box = document.getElementById("safegrd-panel-actions");
		if (!box) return;
		box.textContent = "";
		var ch = chosen();
		var n = notes(ch);
		if (n.length && !ch.problem) {
			var ul = el("ul", "safegrd-notes");
			n.forEach(function (t) { ul.appendChild(el("li", "", t)); });
			box.appendChild(ul);
		}
		if (ch.problem) box.appendChild(el("p", "description", ch.problem));
		if (pick.review && !ch.problem) {
			var r = el("div", "safegrd-review");
			r.appendChild(el("h3", "", "Restore onto " + host(cfg.site) + ":"));
			var ul2 = el("ul");
			ch.lines.forEach(function (t) { ul2.appendChild(el("li", "", t)); });
			r.appendChild(ul2);
			r.appendChild(el("p", "", "This site runs as it is until these are loaded and checked against the backup. What they replace is kept aside, and Put the copy back on this page restores it."));
			var go = el("button", "button button-primary safegrd-restore-btn", "Restore now");
			go.type = "button";
			go.disabled = restoring;
			go.addEventListener("click", function () { startRestore(opened, ch, n); });
			var back = el("button", "button", "Back");
			back.type = "button";
			back.addEventListener("click", function () {
				pick.review = false;
				renderActions();
			});
			r.appendChild(go);
			r.appendChild(document.createTextNode(" "));
			r.appendChild(back);
			box.appendChild(r);
			return;
		}
		var p = el("p");
		var review = el("button", "button button-primary safegrd-restore-btn", "Review restore");
		review.type = "button";
		review.disabled = restoring || !!ch.problem;
		review.addEventListener("click", function () {
			pick.review = true;
			renderActions();
		});
		p.appendChild(review);
		p.appendChild(document.createTextNode(" "));
		var dl = el("button", "button safegrd-restore-btn", "Download as a file");
		dl.type = "button";
		dl.disabled = restoring || !!ch.problem || ch.parts.length !== 1;
		dl.addEventListener("click", function () { startDownload(opened, ch); });
		p.appendChild(dl);
		if (ch.parts.length > 1) p.appendChild(el("span", "description", " A download takes one part: tick only that one."));
		box.appendChild(p);
	}

	function startRestore(s, ch, n) {
		setRestoring(true);
		var prefix = pick.contents ? pick.contents.prefix : "";
		restoresDatabase = ch.parts.indexOf("database") >= 0 && (!ch.items.database || ch.items.database.indexOf(prefix + "users") >= 0);
		var body = { snapshot: s.id, parts: ch.parts.join(",") };
		if (Object.keys(ch.items).length) body.items = JSON.stringify(ch.items);
		post("safegrd_restore_start", body).then(function (r) {
			notice("info", r.message);
			closeBackup();
			window.scrollTo(0, 0);
			watchRestore(500);
		}).catch(function (e) {
			setRestoring(false);
			notice("error", e.message);
		});
	}

	function startDownload(s, ch) {
		setRestoring(true);
		restoresDatabase = false;
		var body = { snapshot: s.id, part: ch.parts[0] };
		if (ch.items[ch.parts[0]]) body.items = JSON.stringify(ch.items);
		post("safegrd_download_start", body).then(function (r) {
			notice("info", r.message);
			watchRestore(500);
		}).catch(function (e) {
			setRestoring(false);
			notice("error", e.message);
		});
	}

	function showDownloads(html) {
		var box = document.getElementById("safegrd-downloads");
		if (!box || html === undefined) return;
		box.innerHTML = html;
		Array.prototype.forEach.call(box.querySelectorAll(".safegrd-delete-download"), function (b) {
			b.addEventListener("click", function () {
				post("safegrd_download_delete", { id: b.getAttribute("data-id") }).then(function (r) { showDownloads(r.downloads); })
					.catch(function (e) { notice("error", e.message); });
			});
		});
	}
	showDownloads(document.getElementById("safegrd-downloads") ? document.getElementById("safegrd-downloads").innerHTML : undefined);

	// While a restore or a download runs, its progress is the notice at the
	// top of the page, and no other can start.
	var restoring = !document.getElementById("safegrd-restore-progress").hidden;

	function setRestoring(on) {
		restoring = on;
		Array.prototype.forEach.call(document.querySelectorAll(".safegrd-restore-btn"), function (b) { b.disabled = on; });
		if (pick) renderActions();
	}

	function showProgress(html) {
		var box = document.getElementById("safegrd-restore-progress");
		box.innerHTML = html || "";
		box.hidden = !html;
	}

	// Whether the restore under way takes the database, and with it this
	// site's users: unknown after a reload, so assumed.
	var restoresDatabase = true;

	function watchRestore(delay) {
		var failures = 0;
		var tick = function () {
			drive().then(function () { return post("safegrd_status", { local: "1" }); }).then(function (s) {
				failures = 0;
				showProgress(s.progress);
				setRestoring(!!s.restoring);
				document.getElementById("safegrd-restore-state").innerHTML = s.restore;
				showDownloads(s.downloads);
				if (s.restoring) {
					setTimeout(tick, 4000);
				}
			}).catch(function () {
				// While the plugins are swapped in, a request can find this
				// plugin's directory moved for a moment: ask again.
				failures++;
				if (failures < 5) {
					setTimeout(tick, 3000);
					return;
				}
				showProgress("");
				if (restoresDatabase) {
					// The session ends when the restored users replace this site's.
					notice("success", "The restore has finished or your session ended with it. Sign in with an administrator account of the restored site.");
				} else {
					notice("error", "This page lost contact with the site. Reload it to see how the restore ended.");
				}
			});
		};
		setTimeout(tick, delay || 3000);
	}

	// The last restore's copy: put back, or deleted. Each asks twice, on the
	// button itself, rather than in a dialog.
	function armed(btn, ask) {
		if (btn.dataset.armed) return true;
		btn.dataset.armed = "1";
		btn.dataset.label = btn.textContent;
		btn.textContent = ask;
		setTimeout(function () {
			if (!btn.isConnected) return;
			delete btn.dataset.armed;
			btn.textContent = btn.dataset.label;
		}, 6000);
		return false;
	}
	var restoreState = document.getElementById("safegrd-restore-state");
	if (restoreState) {
		restoreState.addEventListener("click", function (ev) {
			var btn = ev.target.closest("button");
			if (!btn) return;
			if (btn.id === "safegrd-delete-copy") {
				if (!armed(btn, "Delete for good? Click again")) return;
				btn.disabled = true;
				post("safegrd_restore_delete_copy", {}).then(function (r) {
					notice("success", r.message);
					restoreState.innerHTML = r.restore;
				}).catch(function (e) { notice("error", e.message); btn.disabled = false; });
			}
			if (btn.id === "safegrd-delete-older") {
				if (!armed(btn, "Delete for good? Click again")) return;
				btn.disabled = true;
				post("safegrd_restore_delete_older", {}).then(function (r) {
					notice("success", r.message);
					restoreState.innerHTML = r.restore;
				}).catch(function (e) { notice("error", e.message); btn.disabled = false; });
			}
			if (btn.id === "safegrd-undo-restore") {
				if (!armed(btn, btn.dataset.users ? "This changes who can sign in. Click again" : "Click again to confirm")) return;
				btn.disabled = true;
				post("safegrd_restore_undo", {}).then(function (r) {
					notice("success", r.message);
					restoreState.innerHTML = r.restore;
					if (btn.dataset.users) setTimeout(function () { window.location.reload(); }, 2500);
				}).catch(function (e) { notice("error", e.message); btn.disabled = false; });
			}
		});
	}

	post("safegrd_restore_list", {}).then(function (r) {
		backups = r.snapshots;
		fillFilter();
		renderRestoreList();
		route();
	}).catch(function (e) {
		document.getElementById("safegrd-restore-list").textContent = "SafeGrd did not answer: " + e.message;
	});

	refresh(false).then(function (s) { if (s.running) watch(); if (s.restoring) watchRestore(); }).catch(function (e) {
		document.getElementById("safegrd-drill").textContent = "";
		notice("error", e.message);
	});
})();
