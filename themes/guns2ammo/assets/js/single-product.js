/**
 * Guns 2 Ammo — single-product behaviour.
 *
 * Four small, independent modules:
 *   1. Gallery      thumbnail rail selection + rail scrolling
 *   2. Lightbox     click-to-zoom overlay with keyboard navigation
 *   3. Quantity     − / + stepper around the WooCommerce qty input
 *   4. Wishlist     save-for-later backed by localStorage
 *
 * Everything degrades: with the script blocked the stage still shows the
 * featured image, each thumbnail is a plain link to its full-size file, the
 * qty input still types, and the wishlist button simply does not render its
 * saved state.
 *
 * No dependencies — this file must stay framework-free.
 */
(function () {
	'use strict';

	var WISHLIST_KEY = 'g2a_wishlist';

	/* ----------------------------------------------------------------
	 * 1. GALLERY
	 * ---------------------------------------------------------------- */

	function initGallery(gallery) {
		var stage = gallery.querySelector('.g2a-gallery__image');
		var thumbs = Array.prototype.slice.call(gallery.querySelectorAll('.g2a-gallery__thumb'));

		if (!stage || !thumbs.length) {
			return;
		}

		function select(index) {
			var thumb = thumbs[index];
			if (!thumb) {
				return;
			}

			stage.src = thumb.getAttribute('data-src') || thumb.getAttribute('data-full') || stage.src;
			stage.srcset = thumb.getAttribute('data-srcset') || '';
			stage.alt = thumb.getAttribute('data-alt') || stage.alt;
			stage.setAttribute('data-full', thumb.getAttribute('data-full') || '');
			stage.setAttribute('data-index', String(index));

			thumbs.forEach(function (item, itemIndex) {
				item.classList.toggle('is-active', itemIndex === index);
				item.setAttribute('aria-current', itemIndex === index ? 'true' : 'false');
			});
		}

		thumbs.forEach(function (thumb, index) {
			thumb.setAttribute('aria-current', index === 0 ? 'true' : 'false');

			thumb.addEventListener('click', function (event) {
				event.preventDefault();
				select(index);
			});

			// Hovering a thumbnail previews it — matches how the design reads,
			// and costs nothing because the images are already downloaded.
			thumb.addEventListener('mouseenter', function () {
				select(index);
			});
		});

		// Rail chevrons scroll the thumbnail strip (vertical on desktop,
		// horizontal once the layout stacks on narrow screens).
		var strip = gallery.querySelector('[data-gallery-thumbs]');
		Array.prototype.forEach.call(gallery.querySelectorAll('[data-gallery-scroll]'), function (button) {
			button.addEventListener('click', function () {
				if (!strip) {
					return;
				}
				var direction = parseInt(button.getAttribute('data-gallery-scroll'), 10) || 1;
				var horizontal = strip.scrollWidth > strip.clientWidth + 1;
				var step = (horizontal ? strip.clientWidth : strip.clientHeight) * 0.7;

				if (horizontal) {
					strip.scrollBy({ left: direction * step, behavior: 'smooth' });
				} else {
					strip.scrollBy({ top: direction * step, behavior: 'smooth' });
				}
			});
		});

		var zoom = gallery.querySelector('[data-gallery-zoom]');
		if (zoom) {
			zoom.addEventListener('click', function () {
				openLightbox(thumbs, parseInt(stage.getAttribute('data-index'), 10) || 0);
			});
		}

		stage.addEventListener('click', function () {
			openLightbox(thumbs, parseInt(stage.getAttribute('data-index'), 10) || 0);
		});
	}

	/* ----------------------------------------------------------------
	 * 2. LIGHTBOX
	 * ---------------------------------------------------------------- */

	function openLightbox(thumbs, startIndex) {
		var index = startIndex;
		var lastFocused = document.activeElement;

		var overlay = document.createElement('div');
		overlay.className = 'g2a-lightbox';
		overlay.setAttribute('role', 'dialog');
		overlay.setAttribute('aria-modal', 'true');
		overlay.setAttribute('aria-label', 'Product image viewer');

		var image = document.createElement('img');
		image.className = 'g2a-lightbox__image';

		var close = document.createElement('button');
		close.type = 'button';
		close.className = 'g2a-lightbox__close';
		close.setAttribute('aria-label', 'Close image viewer');
		close.innerHTML = '&times;';

		overlay.appendChild(image);
		overlay.appendChild(close);

		var prev = null;
		var next = null;
		if (thumbs.length > 1) {
			prev = document.createElement('button');
			prev.type = 'button';
			prev.className = 'g2a-lightbox__nav is-prev';
			prev.setAttribute('aria-label', 'Previous image');
			prev.innerHTML = '&#8249;';

			next = document.createElement('button');
			next.type = 'button';
			next.className = 'g2a-lightbox__nav is-next';
			next.setAttribute('aria-label', 'Next image');
			next.innerHTML = '&#8250;';

			overlay.appendChild(prev);
			overlay.appendChild(next);
		}

		function show(nextIndex) {
			index = (nextIndex + thumbs.length) % thumbs.length;
			var thumb = thumbs[index];
			image.src = thumb.getAttribute('data-full') || thumb.getAttribute('data-src') || '';
			image.alt = thumb.getAttribute('data-alt') || '';
		}

		function dismiss() {
			document.removeEventListener('keydown', onKey);
			document.documentElement.classList.remove('g2a-lightbox-open');
			overlay.remove();
			if (lastFocused && typeof lastFocused.focus === 'function') {
				lastFocused.focus();
			}
		}

		function onKey(event) {
			if (event.key === 'Escape') {
				dismiss();
			} else if (event.key === 'ArrowRight') {
				show(index + 1);
			} else if (event.key === 'ArrowLeft') {
				show(index - 1);
			}
		}

		close.addEventListener('click', dismiss);
		overlay.addEventListener('click', function (event) {
			if (event.target === overlay) {
				dismiss();
			}
		});
		if (prev) {
			prev.addEventListener('click', function () {
				show(index - 1);
			});
			next.addEventListener('click', function () {
				show(index + 1);
			});
		}
		document.addEventListener('keydown', onKey);

		show(index);
		document.documentElement.classList.add('g2a-lightbox-open');
		document.body.appendChild(overlay);
		close.focus();
	}

	/* ----------------------------------------------------------------
	 * 3. QUANTITY STEPPER
	 * ---------------------------------------------------------------- */

	function initQuantity(root) {
		root.addEventListener('click', function (event) {
			if (!(event.target instanceof Element)) {
				return;
			}
			var button = event.target.closest('[data-qty-step]');
			if (!button) {
				return;
			}

			var wrapper = button.closest('.quantity');
			var input = wrapper ? wrapper.querySelector('input.qty') : null;
			if (!input) {
				return;
			}

			var step = parseFloat(input.getAttribute('step')) || 1;
			var min = input.getAttribute('min') !== null ? parseFloat(input.getAttribute('min')) : 0;
			var max = input.getAttribute('max') !== null && input.getAttribute('max') !== '' ? parseFloat(input.getAttribute('max')) : Infinity;
			var current = parseFloat(input.value);

			if (isNaN(current)) {
				current = min || 0;
			}

			var value = current + step * (parseInt(button.getAttribute('data-qty-step'), 10) || 1);
			value = Math.min(max, Math.max(min, value));

			// Keep the fractional precision WooCommerce's own step implies
			// (weight-priced items can legitimately step by 0.5).
			input.value = String(parseFloat(value.toFixed(4)));
			input.dispatchEvent(new Event('change', { bubbles: true }));
		});
	}

	/* ----------------------------------------------------------------
	 * 4. WISHLIST (browser-local save for later)
	 * ---------------------------------------------------------------- */

	function readWishlist() {
		try {
			var stored = window.localStorage.getItem(WISHLIST_KEY);
			var parsed = stored ? JSON.parse(stored) : [];
			return Array.isArray(parsed) ? parsed : [];
		} catch (error) {
			return [];
		}
	}

	function writeWishlist(ids) {
		try {
			window.localStorage.setItem(WISHLIST_KEY, JSON.stringify(ids));
			return true;
		} catch (error) {
			return false;
		}
	}

	function initWishlist(button) {
		var id = button.getAttribute('data-wishlist');
		var label = button.querySelector('.g2a-wishlist__label');
		var addedText = button.getAttribute('data-added-label') || 'Saved to Wishlist';
		var idleText = label ? label.textContent : 'Add to Wishlist';

		function paint(saved) {
			button.classList.toggle('is-saved', saved);
			button.setAttribute('aria-pressed', saved ? 'true' : 'false');
			if (label) {
				label.textContent = saved ? addedText : idleText;
			}
		}

		paint(readWishlist().indexOf(id) !== -1);

		button.addEventListener('click', function () {
			var ids = readWishlist();
			var position = ids.indexOf(id);

			if (position === -1) {
				ids.push(id);
			} else {
				ids.splice(position, 1);
			}

			if (writeWishlist(ids)) {
				paint(position === -1);
			}
		});
	}

	/* ---------------------------------------------------------------- */

	function boot() {
		Array.prototype.forEach.call(document.querySelectorAll('[data-g2a-gallery]'), initGallery);
		Array.prototype.forEach.call(document.querySelectorAll('[data-wishlist]'), initWishlist);

		var product = document.querySelector('.g2a-single');
		if (product) {
			initQuantity(product);
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}
})();
