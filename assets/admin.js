(function () {
	var button = document.getElementById('sb-webp-upload-optimizer-bulk');
	var status = document.getElementById('sb-webp-upload-optimizer-status');

	if (!button || !status || typeof sb_webp_upload_optimizer === 'undefined') {
		return;
	}

	button.addEventListener('click', function () {
		button.disabled = true;
		status.textContent = sb_webp_upload_optimizer.working;
		runStep(0);
	});

	function runStep(guard) {
		if (guard > 5000) {
			button.disabled = false;
			status.textContent = sb_webp_upload_optimizer.errorMessage;
			return;
		}

		var body = new FormData();
		body.append('action', 'sb_webp_upload_optimizer_bulk_step');
		body.append('nonce', sb_webp_upload_optimizer.nonce);

		window.fetch(sb_webp_upload_optimizer.ajaxUrl, {
			method: 'POST',
			body: body,
			credentials: 'same-origin'
		}).then(function (response) {
			return response.json();
		}).then(function (payload) {
			if (!payload || !payload.success || !payload.data) {
				button.disabled = false;
				status.textContent = payload && payload.data && payload.data.message
					? payload.data.message
					: sb_webp_upload_optimizer.errorMessage;
				return;
			}

			status.textContent = payload.data.message || '';

			if (payload.data.done) {
				button.disabled = false;
				return;
			}

			runStep(guard + 1);
		}).catch(function () {
			button.disabled = false;
			status.textContent = sb_webp_upload_optimizer.errorMessage;
		});
	}
}());
