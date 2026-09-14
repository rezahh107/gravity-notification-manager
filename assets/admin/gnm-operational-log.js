(() => {
	'use strict';

	const status = document.getElementById('gnm-copy-status');
	const fallbackCopy = (text) => {
		const area = document.createElement('textarea');
		area.value = text;
		area.setAttribute('readonly', 'readonly');
		area.style.position = 'fixed';
		area.style.opacity = '0';
		document.body.appendChild(area);
		area.select();
		const copied = document.execCommand('copy');
		area.remove();
		return copied;
	};

	document.addEventListener('click', async (event) => {
		const button = event.target.closest('.gnm-copy-debug');
		if (!button) {
			return;
		}
		const report = button.dataset.report || '';
		if (!report) {
			return;
		}
		let copied = false;
		try {
			if (navigator.clipboard && navigator.clipboard.writeText) {
				await navigator.clipboard.writeText(report);
				copied = true;
			} else {
				copied = fallbackCopy(report);
			}
		} catch (error) {
			copied = fallbackCopy(report);
		}
		if (copied && status) {
			status.textContent = button.dataset.copied || '';
		}
	});
})();
