/* Event Toolkit for Custom Fields: share helpers on the admin pages. */
(function () {
	'use strict';

	// "Share images and text" only where it works: touch devices whose browser can hand
	// image files to other apps (Web Share API Level 2). On computers it stays hidden.
	var touch = window.matchMedia && window.matchMedia('(pointer: coarse)').matches;
	var canShareFiles = false;
	try {
		canShareFiles = !!(navigator.share && navigator.canShare && navigator.canShare({ files: [new File(['x'], 'x.jpg', { type: 'image/jpeg' })] }));
	} catch (e) {
		canShareFiles = false;
	}
	var sharing = touch && canShareFiles;

	// Browsers open the share sheet only right after a tap. So the images are loaded
	// in advance, and the tap calls navigator.share() without waiting for the network.
	var files = new WeakMap();

	function loadFiles(card) {
		if (!files.has(card)) {
			var images = Array.prototype.slice.call(card.querySelectorAll('img[data-full]'));
			files.set(card, Promise.all(images.map(function (img, i) {
				return fetch(img.getAttribute('data-full'), { credentials: 'same-origin' })
					.then(function (r) {
						if (!r.ok) {
							throw new Error('HTTP ' + r.status);
						}
						return r.blob();
					})
					.then(function (blob) {
						return new File([blob], 'story-' + (i + 1) + '.jpg', { type: 'image/jpeg' });
					});
			})).then(function (list) {
				card.immFiles = list;
				return list;
			}));
		}
		return files.get(card);
	}

	if (sharing) {
		document.querySelectorAll('[data-imm-share-hint]').forEach(function (el) {
			el.hidden = false;
		});
		document.querySelectorAll('[data-imm-share]').forEach(function (button) {
			button.hidden = false;
			var card = button.closest('.imm-tk-share');
			button.disabled = true;
			loadFiles(card).then(function () {
				button.disabled = false;
			}).catch(function (error) {
				showError(card, error);
			});
		});
	}

	function showError(card, error) {
		var box = card.querySelector('.imm-tk-share-error');
		if (!box) {
			return;
		}
		box.textContent = (card.getAttribute('data-error') || 'Sharing did not work:') + ' ' + (error && error.message ? error.message : String(error));
		box.hidden = false;
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
		var box  = card.querySelector('.imm-tk-share-error');
		if (box) {
			box.hidden = true;
		}

		if (button.hasAttribute('data-imm-copy')) {
			copy(text).then(function () {
				flash(button, card);
			}).catch(function (error) {
				showError(card, error);
			});
			return;
		}

		// Share: called directly in the tap, with the images loaded beforehand.
		var list = card.immFiles;
		if (!list || !list.length) {
			return;
		}
		var data = { files: list, text: text };
		if (!navigator.canShare(data)) {
			data = { files: list };
		}
		navigator.share(data).catch(function (error) {
			if (error && error.name === 'AbortError') {
				return; // Closed the share sheet.
			}
			showError(card, error);
		});
	});
})();
