/* Migration Safety Net admin: drive capture/compare AJAX batches with a progress bar. */
(function () {
	'use strict';

	function runBatches(action, params, progressEl, btn, onDone) {
		var bar = progressEl.querySelector('.tumils-msn-progress-bar');
		var txt = progressEl.querySelector('.tumils-msn-progress-text');
		progressEl.hidden = false;

		function step(extra) {
			var data = new FormData();
			data.append('action', action);
			data.append('_ajax_nonce', tumilsMsnAdmin.nonce);
			Object.keys(params).forEach(function (k) { data.append(k, params[k]); });
			Object.keys(extra).forEach(function (k) { data.append(k, extra[k]); });

			fetch(tumilsMsnAdmin.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: data })
				.then(function (r) { return r.json(); })
				.then(function (json) {
					if (!json.success) { throw new Error(tumilsMsnAdmin.serverError); }
					var d = json.data;
					bar.style.width = Math.round((d.done / d.total) * 100) + '%';
					txt.textContent = d.done + ' / ' + d.total;
					if (d.done < d.total) {
						step({ offset: d.done, run_id: d.run_id || params.run_id || '' });
					} else {
						onDone(d);
					}
				})
				.catch(function (err) {
					// Both halves of this sentence come from wp_localize_script: the progress
					// text is user-visible, so it is translated like every other string.
					txt.textContent = tumilsMsnAdmin.batchFailed.replace('%s', err.message);
					if (btn) { btn.disabled = false; }
				});
		}
		step({ offset: 0 });
	}

	var captureBtn = document.getElementById('tumils-msn-capture');
	if (captureBtn) {
		captureBtn.addEventListener('click', function () {
			captureBtn.disabled = true;
			runBatches(
				'tumils_msn_capture_batch',
				{ label: document.getElementById('tumils-msn-label').value },
				document.getElementById('tumils-msn-capture-progress'),
				captureBtn,
				function () { window.location.reload(); }
			);
		});
	}

	var compareBtn = document.getElementById('tumils-msn-compare');
	if (compareBtn) {
		compareBtn.addEventListener('click', function () {
			compareBtn.disabled = true;
			runBatches(
				'tumils_msn_compare_batch',
				{
					run_a: document.getElementById('tumils-msn-run-a').value,
					run_b: document.getElementById('tumils-msn-run-b').value
				},
				document.getElementById('tumils-msn-compare-progress'),
				compareBtn,
				function (d) { window.location = d.report_url; }
			);
		});
	}
	// Delete is destructive, so it keeps a confirm — but bound here rather than in
	// an onsubmit="" attribute, so the markup carries no inline JavaScript. If this
	// script fails to load the delete still goes through the server-side nonce and
	// capability check; only the confirmation is lost.
	document.addEventListener('submit', function (e) {
		if (e.target.classList.contains('tumils-msn-delete-form') && !window.confirm(tumilsMsnAdmin.confirmDelete)) {
			e.preventDefault();
		}
	});
}());
