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

	// Tabs: the hash names the open one, so a reload keeps it.
	function openTab(name) {
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
			history.replaceState(null, "", "#" + name);
			openTab(name);
		});
	});
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
			[s.created, s.kind, s.site, s.size, s.status, s.locked].forEach(function (v) {
				text(row.insertCell(), v || "");
			});
			var cell = row.insertCell();
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
			row.title = s.id;
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

	// Restore and download: the account's WordPress backups. Restore takes
	// all of a backup or the parts ticked; a download is one part.
	function renderRestoreList(list) {
		var box = document.getElementById("safegrd-restore-list");
		box.textContent = "";
		if (!list.length) {
			box.appendChild(el("p", "description", "No WordPress backups in this account yet."));
			return;
		}
		var table = el("table", "widefat striped safegrd-restore-table");
		var head = table.createTHead().insertRow();
		["Site", "Taken", "Contents", ""].forEach(function (h) { head.appendChild(el("th", "", h)); });
		var body = table.createTBody();
		list.forEach(function (s) {
			var row = body.insertRow();
			text(row.insertCell(), s.site);
			text(row.insertCell(), s.taken);
			text(row.insertCell(), s.tables + " tables, " + s.files + " files, " + s.size + (s.verified ? ", test-restored" : ""));
			var cell = row.insertCell();
			cell.className = "safegrd-actions";
			var btn = el("button", "button safegrd-restore-btn", "Restore...");
			btn.type = "button";
			btn.disabled = restoring;
			cell.appendChild(btn);
			var dl = el("select", "safegrd-restore-btn");
			dl.disabled = restoring;
			dl.setAttribute("aria-label", "Download a part of this backup");
			var first = el("option", "", "Download...");
			first.value = "";
			dl.appendChild(first);
			PARTS.forEach(function (p) {
				var o = el("option", "", p[1]);
				o.value = p[0];
				dl.appendChild(o);
			});
			dl.addEventListener("change", function () {
				if (!dl.value) return;
				var part = dl.value;
				dl.value = "";
				startDownload(s, part);
			});
			cell.appendChild(dl);

			// The parts picker, under the row while it is open.
			var pick = body.insertRow();
			pick.hidden = true;
			var pc = pick.insertCell();
			pc.colSpan = 4;
			var fs = el("fieldset", "safegrd-parts");
			fs.appendChild(el("legend", "", "Restore these parts of the backup onto this site"));
			PARTS.forEach(function (p) {
				var label = el("label");
				var box = document.createElement("input");
				box.type = "checkbox";
				box.value = p[0];
				box.checked = true;
				label.appendChild(box);
				label.appendChild(document.createTextNode(" " + p[1]));
				fs.appendChild(label);
			});
			pc.appendChild(fs);
			pc.appendChild(el("p", "description", "Others is the rest of wp-content: mu-plugins, languages and what plugins keep there. Without the database, the site keeps its own content, settings and sign-in."));
			var go = el("button", "button button-primary safegrd-restore-btn", "Restore");
			go.type = "button";
			go.addEventListener("click", function () {
				var parts = Array.prototype.filter.call(fs.querySelectorAll("input"), function (b) { return b.checked; }).map(function (b) { return b.value; });
				if (!parts.length) {
					notice("error", "Choose at least one part to restore.");
					return;
				}
				startRestore(s, parts);
			});
			pc.appendChild(go);
			btn.addEventListener("click", function () { pick.hidden = !pick.hidden; });
		});
		box.appendChild(table);
	}

	function startRestore(s, parts) {
		var all = parts.length === PARTS.length;
		var db = parts.indexOf("database") >= 0;
		var msg = "Restore " + (all ? "the backup" : parts.join(", ") + " from the backup") + " of " + s.site + " taken " + s.taken + " onto this site?\n\n" +
			(all ? "This site's database and content directory are replaced." : "This site's " + parts.join(", ") + " are replaced.") +
			" What they replace is kept aside until you delete it." +
			(db ? "\n\nAfterwards this site's users are the backup's: sign in with an administrator account of the restored site." : "");
		if (!window.confirm(msg)) return;
		setRestoring(true);
		restoresDatabase = db;
		post("safegrd_restore_start", { snapshot: s.id, parts: parts.join(",") }).then(function () {
			watchRestore(500);
		}).catch(function (e) {
			setRestoring(false);
			notice("error", e.message);
		});
	}

	function startDownload(s, part) {
		setRestoring(true);
		restoresDatabase = false;
		post("safegrd_download_start", { snapshot: s.id, part: part }).then(function (r) {
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
				bindDeleteCopy();
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

	function bindDeleteCopy() {
		var del = document.getElementById("safegrd-delete-copy");
		if (!del || del.dataset.bound) return;
		del.dataset.bound = "1";
		del.addEventListener("click", function () {
			if (!window.confirm("Delete the tables and files kept from before the restore? They cannot be brought back.")) return;
			post("safegrd_restore_delete_copy", {}).then(function (r) {
				notice("success", r.message);
				document.getElementById("safegrd-restore-state").textContent = "";
			}).catch(function (e) { notice("error", e.message); });
		});
	}
	bindDeleteCopy();

	post("safegrd_restore_list", {}).then(function (r) { renderRestoreList(r.snapshots); }).catch(function (e) {
		document.getElementById("safegrd-restore-list").textContent = "SafeGrd did not answer: " + e.message;
	});

	refresh(false).then(function (s) { if (s.running) watch(); if (s.restoring) watchRestore(); }).catch(function (e) {
		document.getElementById("safegrd-drill").textContent = "";
		notice("error", e.message);
	});
})();
