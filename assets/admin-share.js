/* Event Toolkit for Custom Fields: share helpers on the settings page. */
(function () {
	'use strict';

	var canShareFiles = false;
	try {
		canShareFiles = !!(navigator.canShare && navigator.canShare({ files: [new File([''], 'x.jpg', { type: 'image/jpeg' })] }));
	} catch (e) {
		canShareFiles = false;
	}
	if (canShareFiles) {
		document.querySelectorAll('[data-imm-share], [data-imm-share-hint]').forEach(function (el) {
			el.hidden = false;
		});
	}

	function flash(button, card) {
		var label = button.textContent;
		button.textContent = card.getAttribute('data-copied') || '✓';
		setTimeout(function () {
			button.textContent = label;
		}, 1500);
	}

	function copy(text) {
		if (navigator.clipboard && window.isSecureContext) {
			return navigator.clipboard.writeText(text);
		}
		var area = document.createElement('textarea');
		area.value = text;
		area.style.position = 'fixed';
		area.style.opacity = '0';
		document.body.appendChild(area);
		area.select();
		document.execCommand('copy');
		document.body.removeChild(area);
		return Promise.resolve();
	}

	// Read-only address fields: select everything on focus, ready to copy.
	document.addEventListener('focusin', function (event) {
		if (event.target.matches && event.target.matches('[data-imm-select]')) {
			event.target.select();
		}
	});

	document.addEventListener('click', function (event) {
		var button = event.target.closest('[data-imm-copy], [data-imm-share]');
		if (!button) {
			return;
		}
		var card = button.closest('.imm-tk-share');
		var text = card.querySelector('textarea').value;

		if (button.hasAttribute('data-imm-copy')) {
			if (button.getAttribute('data-imm-copy') === 'plain') {
				text = card.getAttribute('data-plain');
			}
			copy(text).then(function () {
				flash(button, card);
			});
			return;
		}

		// Share: hand the images (and the text) to the share sheet of the phone.
		var images = Array.prototype.slice.call(card.querySelectorAll('img[data-full]'));
		button.disabled = true;
		Promise.all(images.map(function (img, i) {
			return fetch(img.getAttribute('data-full'), { credentials: 'same-origin' })
				.then(function (r) { return r.blob(); })
				.then(function (blob) { return new File([blob], 'story-' + (i + 1) + '.jpg', { type: 'image/jpeg' }); });
		})).then(function (files) {
			var data = { files: files, text: text };
			if (!navigator.canShare(data)) {
				data = { files: files };
			}
			return navigator.share(data);
		}).catch(function () {
			/* Cancelled or not supported. */
		}).then(function () {
			button.disabled = false;
		});
	});
})();
