/* SafeGrd Backup: Back up now on the Updates, Plugins and Themes screens. No dependencies. */
(function () {
	"use strict";

	var cfg = window.SafeGrdAdmin || {};
	var btn = document.getElementById("safegrd-update-backup");
	var last = document.getElementById("safegrd-update-last");
	if (!btn || !last) return;

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

	// Where the site cannot reach itself, this page runs each slice.
	function drive() {
		if (cfg.loopback) return Promise.resolve();
		return post("safegrd_tick", {}).catch(function () {});
	}

	function follow() {
		drive().then(function () { return post("safegrd_status", { local: "1" }); }).then(function (s) {
			last.textContent = s.last_short;
			if (s.running) {
				setTimeout(follow, 4000);
				return;
			}
			btn.disabled = false;
			var box = document.getElementById("safegrd-update-notice");
			if (box) box.className = "notice " + (/failed/.test(s.last_short) ? "notice-error" : "notice-success");
		}).catch(function (e) {
			last.textContent = e.message;
			btn.disabled = false;
		});
	}

	btn.addEventListener("click", function () {
		btn.disabled = true;
		post("safegrd_backup_now", {}).then(function () {
			last.textContent = "Backup started.";
			setTimeout(follow, 2000);
		}).catch(function (e) {
			last.textContent = e.message;
			btn.disabled = false;
		});
	});

	if (btn.disabled) follow();
})();
