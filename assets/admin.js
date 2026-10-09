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

	function text(el, value) {
		var node = document.createElement("span");
		node.textContent = value;
		el.appendChild(node);
	}

	function renderSnapshots(list, error) {
		var box = document.getElementById("safegrd-snapshots");
		box.textContent = "";
		if (error) {
			var p = document.createElement("p");
			p.className = "description";
			p.textContent = "SafeGrd did not answer: " + error;
			box.appendChild(p);
			return;
		}
		if (!list || !list.length) {
			var none = document.createElement("p");
			none.className = "description";
			none.textContent = "None recorded yet.";
			box.appendChild(none);
			return;
		}
		var table = document.createElement("table");
		table.className = "widefat striped";
		var head = table.createTHead().insertRow();
		["Taken", "Kind", "Site size", "Uploaded", "Status", "Locked until"].forEach(function (h) {
			var th = document.createElement("th");
			th.textContent = h;
			head.appendChild(th);
		});
		var body = table.createTBody();
		list.forEach(function (s) {
			var row = body.insertRow();
			[s.created, s.kind, s.site, s.size, s.status, s.locked].forEach(function (v) {
				text(row.insertCell(), v || "");
			});
			row.title = s.id;
		});
		box.appendChild(table);
	}

	// Hosted storage held against the plan, and the server's warning in
	// its own words: refused, billed or in grace, from 80% of the plan.
	function showStorage(line, warning, used, quota) {
		var el = document.getElementById("safegrd-storage");
		if (!el || !line) return;
		var meter = document.getElementById("safegrd-meter");
		if (meter && quota > 0) {
			var pct = Math.min(100, used * 100 / quota);
			meter.firstElementChild.style.width = Math.max(pct, 0.5) + "%";
			meter.className = "safegrd-meter" + (pct >= 100 ? " is-full" : pct >= 80 ? " is-high" : "");
			meter.hidden = false;
		}
		el.textContent = line + ".";
		if (warning) {
			var w = document.createElement("strong");
			w.className = "safegrd-warn";
			w.textContent = " " + warning;
			el.appendChild(w);
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
			if (!local) {
				if (s.drill) {
					var drill = document.getElementById("safegrd-drill");
					drill.textContent = s.drill;
					if (s.drill_next) {
						var next = document.createElement("span");
						next.className = "description";
						next.textContent = " " + s.drill_next + " ";
						drill.appendChild(next);
						var plans = document.createElement("a");
						plans.href = cfg.server + "/pricing";
						plans.target = "_blank";
						plans.rel = "noopener";
						plans.textContent = "What each plan tests";
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

	var frequency = document.getElementById("safegrd-frequency");
	if (frequency) {
		frequency.addEventListener("change", function () {
			frequency.disabled = true;
			post("safegrd_set_frequency", { frequency: frequency.value }).then(function (r) {
				document.getElementById("safegrd-next").textContent = r.next;
				notice("success", r.message);
			}).catch(function (e) { notice("error", e.message); }).then(function () { frequency.disabled = false; });
		});
	}

	var copy = document.getElementById("safegrd-copy-diagnostics");
	if (copy) {
		copy.addEventListener("click", function () {
			var area = document.getElementById("safegrd-diagnostics");
			area.select();
			var done = function () { copy.textContent = "Copied"; };
			if (navigator.clipboard) {
				navigator.clipboard.writeText(area.value).then(done, function () { document.execCommand("copy"); done(); });
			} else {
				document.execCommand("copy");
				done();
			}
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

	// Restore: the account's WordPress backups, each restorable onto this site.
	function renderRestoreList(list) {
		var box = document.getElementById("safegrd-restore-list");
		box.textContent = "";
		if (!list.length) {
			var none = document.createElement("p");
			none.className = "description";
			none.textContent = "No WordPress backups in this account yet.";
			box.appendChild(none);
			return;
		}
		var table = document.createElement("table");
		table.className = "widefat striped";
		var head = table.createTHead().insertRow();
		["Site", "Taken", "Contents", ""].forEach(function (h) {
			var th = document.createElement("th");
			th.textContent = h;
			head.appendChild(th);
		});
		var body = table.createTBody();
		list.forEach(function (s) {
			var row = body.insertRow();
			text(row.insertCell(), s.site);
			text(row.insertCell(), s.taken);
			text(row.insertCell(), s.tables + " tables, " + s.files + " files, " + s.size + (s.verified ? ", test-restored" : ""));
			var btn = document.createElement("button");
			btn.type = "button";
			btn.textContent = "Restore";
			btn.className = "button safegrd-restore-btn";
			btn.disabled = restoring;
			btn.addEventListener("click", function () { startRestore(s); });
			row.insertCell().appendChild(btn);
		});
		box.appendChild(table);
	}

	function startRestore(s) {
		var msg = "Restore the backup of " + s.site + " taken " + s.taken + " onto this site?\n\n" +
			"This site's database and content directory are replaced. The current ones are kept aside until you delete them.\n\n" +
			"Afterwards this site's users are the backup's: sign in with an administrator account of the restored site.";
		if (!window.confirm(msg)) return;
		setRestoring(true);
		post("safegrd_restore_start", { snapshot: s.id }).then(function () {
			watchRestore(500);
		}).catch(function (e) {
			setRestoring(false);
			notice("error", e.message);
		});
	}

	// While a restore runs, its progress is the notice at the top of the
	// page, and no other restore can start.
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

	function watchRestore(delay) {
		var tick = function () {
			drive().then(function () { return post("safegrd_status", { local: "1" }); }).then(function (s) {
				showProgress(s.progress);
				setRestoring(!!s.restoring);
				document.getElementById("safegrd-restore-state").innerHTML = s.restore;
				bindDeleteCopy();
				if (s.restoring) {
					setTimeout(tick, 4000);
				}
			}).catch(function () {
				// The session ends when the restored users replace this site's.
				showProgress("");
				notice("success", "The restore has finished or your session ended with it. Sign in with an administrator account of the restored site.");
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
