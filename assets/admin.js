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

	// Connecting: start a device sign-in, open the approval page, and poll
	// until it is approved and the site is registered.
	var connectBtn = document.getElementById("safegrd-connect-btn");
	if (connectBtn) {
		connectBtn.addEventListener("click", function () {
			var picked = document.querySelector("input[name=safegrd_custody]:checked");
			var custody = picked ? picked.value : "safegrd";
			// Opened now, inside the click, so a popup blocker lets it through.
			var tab = window.open("about:blank", "_blank");
			connectBtn.disabled = true;
			post("safegrd_connect_start", { custody: custody }).then(function (s) {
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
				connectBtn.disabled = false;
				notice("error", e.message);
			});
		});
	}

	function poll() {
		setTimeout(function () {
			post("safegrd_connect_poll", {}).then(function (p) {
				if (p.status !== "connected") {
					poll();
					return;
				}
				document.getElementById("safegrd-waiting").hidden = true;
				if (p.identity) {
					document.getElementById("safegrd-identity-text").value = p.identity;
					document.getElementById("safegrd-identity").hidden = false;
					connectBtn.hidden = true;
					if (p.escrow_failed) {
						notice("warning", "Connected, but SafeGrd did not store the key. The key below is the only copy: save it now.");
					} else {
						notice("success", "Connected" + (p.user_email ? " as " + p.user_email : "") + ".");
					}
					return;
				}
				notice("success", "Connected" + (p.user_email ? " as " + p.user_email : "") + ". The first backup starts in about a minute.");
				setTimeout(function () { window.location.reload(); }, 1500);
			}).catch(function (e) {
				connectBtn.disabled = false;
				document.getElementById("safegrd-waiting").hidden = true;
				notice("error", e.message);
			});
		}, 2000);
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
		["Snapshot", "Taken", "Status", "Size", "Locked until"].forEach(function (h) {
			var th = document.createElement("th");
			th.textContent = h;
			head.appendChild(th);
		});
		var body = table.createTBody();
		list.forEach(function (s) {
			var row = body.insertRow();
			[s.id, s.created, s.status, s.size, s.locked].forEach(function (v) {
				text(row.insertCell(), v || "");
			});
		});
		box.appendChild(table);
	}

	var backupBtn = document.getElementById("safegrd-backup-btn");
	var watching = false;

	function refresh(local) {
		return post("safegrd_status", local ? { local: "1" } : {}).then(function (s) {
			document.getElementById("safegrd-last").innerHTML = s.last;
			backupBtn.disabled = !!s.running;
			if (!local) {
				if (s.drill) document.getElementById("safegrd-drill").textContent = s.drill;
				renderSnapshots(s.snapshots, s.snapshots_error);
			}
			return s;
		});
	}

	function watch() {
		if (watching) return;
		watching = true;
		var tick = function () {
			refresh(true).then(function (s) {
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

	var disconnect = document.getElementById("safegrd-disconnect");
	if (disconnect) {
		disconnect.addEventListener("click", function (ev) {
			ev.preventDefault();
			if (!window.confirm("Disconnect this site? Backups stop. The backups already taken stay in SafeGrd.")) return;
			post("safegrd_disconnect", {}).then(function () { window.location.reload(); })
				.catch(function (e) { notice("error", e.message); });
		});
	}

	refresh(false).then(function (s) { if (s.running) watch(); }).catch(function (e) {
		document.getElementById("safegrd-drill").textContent = "";
		notice("error", e.message);
	});
})();
