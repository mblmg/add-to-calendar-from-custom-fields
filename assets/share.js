/* Event Toolkit for Custom Fields: share buttons (share page in the admin, share page and share buttons on the website). */
(function () {
	'use strict';

	// Sharing only on touch devices, where it hands content to installed apps.
	// 'files': images and text (Chrome on Android, Safari on iOS);
	// 'text':  text only (e.g. Firefox on Android, which cannot share files);
	// '':      no share button (computers, browsers without Web Share).
	var touch = window.matchMedia && window.matchMedia('(pointer: coarse)').matches;
	var canShareFiles = false;
	try {
		canShareFiles = !!(navigator.share && navigator.canShare && navigator.canShare({ files: [new File(['x'], 'x.jpg', { type: 'image/jpeg' })] }));
	} catch (e) {
		canShareFiles = false;
	}
	var mode = !touch || !navigator.share ? '' : (canShareFiles ? 'files' : 'text');

	// Image addresses of a card: thumbnails with data-full, or data-files on the card itself.
	function imageUrls(card) {
		var urls = Array.prototype.map.call(card.querySelectorAll('img[data-full]'), function (img) {
			return img.getAttribute('data-full');
		});
		var own = card.getAttribute('data-files');
		return own ? urls.concat(own.split(' ').filter(Boolean)) : urls;
	}

	// Browsers open the share sheet only right after a tap. So the images are loaded
	// in advance, and the tap calls navigator.share() without waiting for the network.
	var files = new WeakMap();

	function loadFiles(card) {
		if (!files.has(card)) {
			files.set(card, Promise.all(imageUrls(card).map(function (url, i) {
				return fetch(url, { credentials: 'same-origin' })
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

	// Cards with optional images (share buttons on event pages) load them only when they come
	// into view, and share the text alone if the images are not there (yet).
	function whenVisible(el, callback) {
		if (!('IntersectionObserver' in window)) {
			callback();
			return;
		}
		var observer = new IntersectionObserver(function (entries) {
			if (entries.some(function (entry) { return entry.isIntersecting; })) {
				observer.disconnect();
				callback();
			}
		}, { rootMargin: '300px' });
		observer.observe(el);
	}

	if (mode !== '') {
		document.querySelectorAll('[data-imm-share-hint="' + mode + '"]').forEach(function (el) {
			el.hidden = false;
		});
		document.querySelectorAll('[data-imm-desktop]').forEach(function (el) {
			el.hidden = true;
		});
	}

	document.querySelectorAll('[data-imm-share]').forEach(function (button) {
		var card = button.closest('.imm-tk-share');
		var optional = card.hasAttribute('data-files-optional');
		if (mode === '') {
			return;
		}
		button.hidden = false;
		var textLabel = button.getAttribute('data-label-text');
		if (mode === 'text' || !imageUrls(card).length) {
			if (textLabel) {
				button.textContent = textLabel;
			}
			return;
		}
		if (optional) {
			whenVisible(card, function () {
				loadFiles(card).catch(function () {
					card.immFiles = [];
				});
			});
			return;
		}
		button.disabled = true;
		loadFiles(card).then(function () {
			button.disabled = false;
		}).catch(function (error) {
			showError(card, error);
		});
	});

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
		var list = mode === 'files' ? card.immFiles : null;
		var data = { text: text };
		if (list && list.length) {
			data = { files: list, text: text };
			if (!navigator.canShare(data)) {
				data = { files: list };
			}
		}
		navigator.share(data).catch(function (error) {
			if (error && error.name === 'AbortError') {
				return; // Closed the share sheet.
			}
			showError(card, error);
		});
	});
})();
