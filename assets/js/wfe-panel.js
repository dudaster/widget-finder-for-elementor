/**
 * Widget Finder for Elementor — Panel Integration
 *
 * Appends a "Widget Finder" results section to #elementor-panel-page-elements
 * (below Elementor's own #elementor-panel-categories, which Elementor re-renders
 * on every keystroke). By staying outside that container we survive re-renders.
 */
(function ($) {
	'use strict';

	const WFE = {
		DEBOUNCE_MS: 350,
		MIN_CHARS: 2,
		MAX_RESULTS: (wfeData.settings && wfeData.settings.widgetsPerPage) || 20,

		searchTimer: null,
		$section: null,
		$itemsGrid: null,
		searchActive: false,
		attached: false,
		pollInterval: null,
		currentQuery: '',
		currentOffset: 0,

		// ── Plugin Health Check ────────────────────────────────────────────

		maybeStartHealthCheck: function () {
			const raw = sessionStorage.getItem('wfe_last_installed');
			if (!raw) return;

			let info;
			try { info = JSON.parse(raw); } catch (e) {
				sessionStorage.removeItem('wfe_last_installed');
				return;
			}

			// Check: panel stuck in loading state after 15s.
			// PHP fatal errors are already caught by the REST probe in doActivate
			// before the page reload, so we only need to handle the rare case where
			// a plugin's Elementor hook fails silently and leaves the panel spinning.
			// 15s gives slow servers enough time to finish widget-config loading.
			setTimeout(() => {
				sessionStorage.removeItem('wfe_last_installed');

				// Panel loaded successfully — user is editing, no conflict possible.
				// (WFE.attached is set true when tryAttach() finds the search input.)
				if (WFE.attached) return;

				// Panel never loaded: confirm it is still stuck in the loading state.
				const loadingEl = document.getElementById('elementor-panel-state-loading');
				const panelStuck = loadingEl && getComputedStyle(loadingEl).display !== 'none';

				if (!panelStuck) return;

				$.ajax({
					url: wfeData.deactivateUrl,
					method: 'POST',
					headers: { 'X-WP-Nonce': wfeData.nonce, 'Content-Type': 'application/json' },
					data: JSON.stringify({ plugin_file: info.file }),
					complete: () => {
						sessionStorage.setItem('wfe_conflict_notice', info.name);
						location.reload();
					},
				});
			}, 15000);
		},

		showConflictNotice: function (pluginName) {
			this.openModal({
				icon: 'eicon-warning',
				title: wfeData.i18n.conflict_title,
				body: wfeData.i18n.conflict_body.replace('%s', '<strong>' + pluginName + '</strong>'),
				btnText: wfeData.i18n.conflict_search_another,
				btnClass: 'wfe-modal-btn--activate',
				onConfirm: ($modal) => {
					this.closeModal($modal);
					const si = document.getElementById('elementor-panel-elements-search-input');
					if (si) si.focus();
				},
			});
		},

		// ── Bootstrap ──────────────────────────────────────────────────────

		// ── Panel-ready Guardian ───────────────────────────────────────────
		// If Elementor's get_widgets_config AJAX fails (e.g. "Action not found"
		// in certain server contexts), panel/state-ready is never called and the
		// loading spinner stays forever. This guard calls it after 6s as a
		// fallback — but only when no plugin was just installed (the conflict
		// detector handles that case separately via maybeStartHealthCheck).

		maybePanelReadyGuardian: function () {
			if (sessionStorage.getItem('wfe_last_installed')) return;

			setTimeout(() => {
				if (!document.body.classList.contains('elementor-panel-loading')) return;
				try {
					const panel = typeof $e !== 'undefined' && $e.components.get('panel');
					if (panel && !panel.stateReadyOnce) {
						$e.internal('panel/state-ready');
					}
				} catch (e) { /* $e not ready yet — ignore */ }
			}, 6000);
		},

		init: function () {
			this.pollInterval = setInterval(this.tryAttach.bind(this), 300);
			this.maybeStartHealthCheck();
			this.maybePanelReadyGuardian();
		},

		tryAttach: function () {
			if (this.attached) return;

			const searchInput = document.getElementById('elementor-panel-elements-search-input');
			if (!searchInput) return;

			clearInterval(this.pollInterval);
			this.attached = true;

			// Event delegation on #elementor-panel (stable ancestor) so we survive
			// any Elementor re-render that replaces the search input element.
			const panel = document.getElementById('elementor-panel') || document;
			panel.addEventListener('input', (e) => {
				if (e.target.id === 'elementor-panel-elements-search-input') {
					this.onSearchInput(e);
				}
			});

			// Restore search query after a post-install reload.
			// Call our handler directly — never dispatch a DOM event, which would
			// also trigger Elementor's Backbone handler before its ui.input is bound,
			// causing "this.ui.input.val is not a function" errors that kill the panel.
			const q = sessionStorage.getItem('wfe_restore_query');
			if (q) {
				sessionStorage.removeItem('wfe_restore_query');
				searchInput.value = q;

				// Wait until #elementor-panel-page-elements is in the DOM, then
				// dispatch a real input event. This triggers both Elementor's own
				// search handler (so it filters its widget list, keeping it short)
				// AND our delegated listener — identical to the user typing.
				// Safe to dispatch at this point because Backbone's ui.input is
				// only bound after the panel view is fully rendered, which is
				// guaranteed by the existence of #elementor-panel-page-elements.
				let attempts = 0;
				const trySearch = () => {
					if (document.getElementById('elementor-panel-page-elements')) {
						searchInput.dispatchEvent(new Event('input', { bubbles: true }));
					} else if (++attempts < 25) {
						setTimeout(trySearch, 150);
					}
				};
				setTimeout(trySearch, 150);
			}

			// Show conflict notice if a plugin was deactivated because it blocked
			// the Elementor panel from loading (stuck in #elementor-panel-state-loading).
			const conflictName = sessionStorage.getItem('wfe_conflict_notice');
			if (conflictName) {
				sessionStorage.removeItem('wfe_conflict_notice');
				setTimeout(() => { WFE.showConflictNotice(conflictName); }, 1000);
			}
		},

		// ── Build & inject results section (once) ─────────────────────────

		ensureSection: function () {
			// Build section once (but don't inject yet — pageEl may not exist).
			if (!this.$section) {
				const $section = $('<div>', { id: 'wfe-results-section' }).hide();

				const $header = $('<div>', { class: 'wfe-section-header' }).append(
					$('<button>', {
						class: 'elementor-panel-heading elementor-panel-category-title',
						type: 'button',
					}).append(
						$('<span>', { class: 'elementor-panel-heading-toggle' }).append(
							$('<i>', { class: 'eicon-caret-right', 'aria-hidden': 'true' })
						),
						$('<span>', {
							class: 'elementor-panel-heading-title',
							text: wfeData.i18n.title,
						})
					).on('click', function () {
						$section.toggleClass('wfe-collapsed');
					})
				);

				this.$itemsGrid = $('<div>', {
					class: 'elementor-panel-category-items elementor-responsive-panel wfe-items-grid',
				});

				$section.append($header).append(this.$itemsGrid);
				this.$section = $section;
			}

			// Inject into #elementor-panel-page-elements whenever the container
			// becomes available. Retried on every call so post-install restores
			// succeed even if the container wasn't ready at the first attempt.
			if (!document.getElementById('wfe-results-section')) {
				const pageEl = document.getElementById('elementor-panel-page-elements');
				if (pageEl) {
					pageEl.appendChild(this.$section[0]);
					this._watchSection(pageEl);
				}
			}
		},

		// Re-inject our section if Elementor replaces the panel body container.
		_watchSection: function (pageEl) {
			if (this._sectionObserver) return; // already watching
			this._sectionObserver = new MutationObserver(() => {
				if (!document.getElementById('wfe-results-section') && this.searchActive) {
					const el = document.getElementById('elementor-panel-page-elements');
					if (el) {
						el.appendChild(this.$section[0]);
					}
				}
			});
			this._sectionObserver.observe(pageEl.parentNode || document.body, { childList: true, subtree: true });
		},

		// ── Search Input Handler ───────────────────────────────────────────

		onSearchInput: function (e) {
			const query = e.target.value.trim();
			clearTimeout(this.searchTimer);

			if (query.length < this.MIN_CHARS) {
				this.searchActive = false;
				this.hideSection();
				return;
			}

			this.searchActive = true;
			this.currentQuery = query;
			this.currentOffset = 0;
			this.ensureSection();
			this.showLoading();

			this.searchTimer = setTimeout(() => {
				this.search(query, 0);
			}, this.DEBOUNCE_MS);
		},

		// ── API ────────────────────────────────────────────────────────────

		search: function (query, offset) {
			$.ajax({
				url: wfeData.restUrl,
				method: 'GET',
				data: { q: query, limit: this.MAX_RESULTS, offset: offset },
				headers: { 'X-WP-Nonce': wfeData.nonce },
				success: (data) => this.renderResults(data, offset > 0),
				error: () => { if (offset === 0) this.hideSection(); },
			});
		},

		// ── Rendering ─────────────────────────────────────────────────────

		showLoading: function () {
			if (!this.$section) return;
			this.$itemsGrid.html(
				'<div class="wfe-state-msg">' + wfeData.i18n.searching + '</div>'
			);
			this.$section.show();
		},

		hideSection: function () {
			if (this.$section) this.$section.hide();
		},

		renderResults: function (data, append) {
			if (!this.$itemsGrid) return;

			const items    = data.items || [];
			const hasMore  = data.has_more || false;

			if (!append) {
				this.$itemsGrid.empty();
			} else {
				this.$itemsGrid.find('.wfe-show-more').remove();
			}

			if (!append && items.length === 0) {
				this.$itemsGrid.html(
					'<div class="wfe-state-msg">' + wfeData.i18n.no_results + '</div>'
				);
				this.$section.show();
				return;
			}

			items.forEach((widget) => {
				this.$itemsGrid.append(this.buildWidgetCard(widget));
			});

			if (hasMore) {
				this.currentOffset += this.MAX_RESULTS;
				const $more = $('<button>', {
					class: 'wfe-show-more',
					type: 'button',
					text: wfeData.i18n.show_more,
				}).on('click', () => {
					$more.text(wfeData.i18n.searching).prop('disabled', true);
					this.search(this.currentQuery, this.currentOffset);
				});
				this.$itemsGrid.append($more);
			}

			this.$section.show();
		},

		buildWidgetCard: function (widget) {
			const $wrap = $('<div>', {
				class: 'wfe-widget-wrap wfe-status-' + widget.status,
				title: this.buildTooltip(widget),
				'data-slug': widget.plugin_slug,
				'data-file': widget.plugin_file || '',
			});

			const $btn = $('<button>', {
				class: 'wfe-element',
				type: 'button',
			});

			$('<div>', { class: 'icon' }).append(
				$('<i>', { class: this.resolveIconClass(widget), 'aria-hidden': 'true' })
			).appendTo($btn);

			$('<div>', { class: 'title-wrapper' }).append(
				$('<div>', { class: 'title', text: widget.widget_title })
			).appendTo($btn);

			$('<span>', {
				class: 'wfe-status-dot',
				'aria-label': this.statusLabel(widget.status),
			}).appendTo($wrap);

			$btn.on('click', () => this.onWidgetClick(widget, $wrap));
			$wrap.append($btn);

			return $wrap;
		},

		// ── Widget Click ───────────────────────────────────────────────────

		onWidgetClick: function (widget, $wrap) {
			if (widget.status === 'active') {
				this.showNotice('"' + widget.widget_title + '" ' + wfeData.i18n.available_notice);
			} else if (widget.status === 'inactive') {
				this.showActivateModal(widget, $wrap);
			} else {
				this.showInstallModal(widget, $wrap);
			}
		},

		// ── Modals ─────────────────────────────────────────────────────────

		showActivateModal: function (widget, $wrap) {
			this.openModal({
				icon: 'eicon-play',
				title: wfeData.i18n.modal_activate_title,
				body: wfeData.i18n.modal_activate_body.replace('%s', '<strong>' + widget.plugin_name + '</strong>'),
				btnText: wfeData.i18n.btn_activate,
				btnClass: 'wfe-modal-btn--activate',
				onConfirm: ($modal) => this.doActivate(widget, $wrap, $modal),
			});
		},

		showInstallModal: function (widget, $wrap) {
			this.openModal({
				icon: 'eicon-download',
				title: wfeData.i18n.modal_install_title,
				body: wfeData.i18n.modal_install_body.replace('%s', '<strong>' + widget.plugin_name + '</strong>'),
				btnText: wfeData.i18n.btn_install,
				btnClass: 'wfe-modal-btn--install',
				onConfirm: ($modal) => this.doInstall(widget, $wrap, $modal),
			});
		},

		doActivate: function (widget, $wrap, $modal) {
			const $btn = $modal.find('.wfe-modal-confirm');
			$btn.text(wfeData.i18n.btn_activating).prop('disabled', true);

			$.ajax({
				url: wfeData.activateUrl,
				method: 'POST',
				headers: { 'X-WP-Nonce': wfeData.nonce, 'Content-Type': 'application/json' },
				data: JSON.stringify({ plugin_file: widget.plugin_file }),
				success: (res) => {
					if (res.success) {
						widget.status = 'active';
						$wrap.removeClass('wfe-status-inactive').addClass('wfe-status-active');
						this.closeModal($modal);
						this.showNotice(wfeData.i18n.activate_success.replace('%s', widget.plugin_name));

						// Probe the REST search endpoint to detect PHP fatal errors
						// (e.g. class redeclaration) introduced by the newly activated plugin.
						// All WP plugins are bootstrapped for REST requests, so a 500 here
						// means the plugin is incompatible — deactivate immediately, no reload.
						$.ajax({
							url: wfeData.restUrl + '?q=a&limit=1',
							method: 'GET',
							headers: { 'X-WP-Nonce': wfeData.nonce },
							statusCode: {
								500: () => {
									$.ajax({
										url: wfeData.deactivateUrl,
										method: 'POST',
										headers: { 'X-WP-Nonce': wfeData.nonce, 'Content-Type': 'application/json' },
										data: JSON.stringify({ plugin_file: widget.plugin_file }),
										complete: () => { WFE.showConflictNotice(widget.plugin_name); },
									});
								},
							},
							success: () => {
								const q = document.getElementById('elementor-panel-elements-search-input')?.value?.trim();
								if (q) sessionStorage.setItem('wfe_restore_query', q);
								sessionStorage.setItem('wfe_last_installed', JSON.stringify({
									slug: widget.plugin_slug,
									file: widget.plugin_file,
									name: widget.plugin_name,
								}));
								WFE.saveAndReload();
							},
						});
					} else {
						$btn.text(wfeData.i18n.err_activate).prop('disabled', false);
					}
				},
				error: () => { $btn.text(wfeData.i18n.err_activate).prop('disabled', false); },
			});
		},

		doInstall: function (widget, $wrap, $modal) {
			const $btn = $modal.find('.wfe-modal-confirm');
			$btn.text(wfeData.i18n.btn_installing).prop('disabled', true);
			$modal.find('.wfe-modal-body').html(
				'<div class="wfe-modal-progress"><i class="eicon eicon-loading eicon-animation-spin"></i> ' +
				wfeData.i18n.installing_progress.replace('%s', '<strong>' + widget.plugin_name + '</strong>') +
				'</div>'
			);

			$.ajax({
				url: wfeData.installUrl,
				method: 'POST',
				headers: { 'X-WP-Nonce': wfeData.nonce, 'Content-Type': 'application/json' },
				data: JSON.stringify({ slug: widget.plugin_slug, plugin_name: widget.plugin_name }),
				success: (res) => {
					if (res.success) {
						widget.plugin_file = res.plugin_file;
						widget.status = 'inactive';
						$wrap.removeClass('wfe-status-not_installed').addClass('wfe-status-inactive');
						$modal.find('.wfe-modal-body').html(
							'<div class="wfe-modal-progress"><i class="eicon eicon-loading eicon-animation-spin"></i> ' +
							wfeData.i18n.activating_progress + '</div>'
						);
						this.doActivate(widget, $wrap, $modal);
					} else {
						$modal.find('.wfe-modal-body').html('<p class="wfe-modal-error">' + wfeData.i18n.err_install + '</p>');
						$btn.text(wfeData.i18n.btn_install).prop('disabled', false);
					}
				},
				error: () => {
					$modal.find('.wfe-modal-body').html('<p class="wfe-modal-error">' + wfeData.i18n.err_install + '</p>');
					$btn.text(wfeData.i18n.btn_install).prop('disabled', false);
				},
			});
		},

		// ── Save & Reload ──────────────────────────────────────────────────

		saveAndReload: function () {
			// 1. Fire Elementor's real save (best-effort, don't await).
			//    Uses the current document status so a draft stays draft,
			//    a published page stays published.
			try {
				if (window.$e && window.elementor?.documents) {
					const status = elementor.documents.getCurrent()?.config?.status?.value || 'publish';
					$e.internal('document/save/save', { status });
				}
			} catch (_) {}

			// 2. After 2 s (enough time for the save AJAX to complete) clear
			//    Elementor's "changed" flag and reload. Clearing the flag is what
			//    prevents the "Leave page?" dialog — Elementor's beforeunload handler
			//    only shows it when isEditorChanged() returns true.
			setTimeout(() => {
				try {
					if (window.elementor?.saver) {
						elementor.saver.setFlagEditorChange(false);
						elementor.saver.isEditorChanged = () => false;
					}
				} catch (_) {}
				location.reload();
			}, 2000);
		},

		// ── Modal Shell ────────────────────────────────────────────────────

		openModal: function (opts) {
			$('#wfe-modal-overlay').remove();

			const $overlay = $('<div>', { id: 'wfe-modal-overlay', class: 'wfe-modal-overlay' });
			const $modal   = $('<div>', { class: 'wfe-modal' });

			$('<div>', { class: 'wfe-modal-header' }).append(
				$('<i>', { class: 'eicon ' + opts.icon }),
				$('<span>', { class: 'wfe-modal-title', text: opts.title }),
				$('<button>', { class: 'wfe-modal-close', type: 'button', html: '<i class="eicon eicon-close"></i>' })
					.on('click', () => this.closeModal($overlay))
			).appendTo($modal);

			$('<div>', { class: 'wfe-modal-body', html: '<p>' + opts.body + '</p>' }).appendTo($modal);

			$('<div>', { class: 'wfe-modal-footer' }).append(
				$('<button>', { class: 'wfe-modal-btn wfe-modal-cancel', type: 'button', text: wfeData.i18n.btn_cancel })
					.on('click', () => this.closeModal($overlay)),
				$('<button>', { class: 'wfe-modal-btn wfe-modal-confirm ' + opts.btnClass, type: 'button', text: opts.btnText })
					.on('click', () => opts.onConfirm($overlay))
			).appendTo($modal);

			$overlay.append($modal).on('click', (e) => {
				if (e.target === $overlay[0]) this.closeModal($overlay);
			});

			const panel = document.getElementById('elementor-panel');
			$(panel || document.body).append($overlay);
		},

		closeModal: function ($overlay) { $overlay.remove(); },

		// ── Toast Notice ───────────────────────────────────────────────────

		showNotice: function (message) {
			$('#wfe-notice').remove();
			const $n = $('<div>', { id: 'wfe-notice', class: 'wfe-notice', text: message });
			$(document.getElementById('elementor-panel') || document.body).append($n);
			setTimeout(() => $n.addClass('wfe-notice--visible'), 40);
			setTimeout(() => { $n.removeClass('wfe-notice--visible'); setTimeout(() => $n.remove(), 300); }, 3500);
		},

		// ── Helpers ────────────────────────────────────────────────────────

		resolveIconClass: function (widget) {
			if (widget.icon_type === 'elementor_icon_class' && widget.icon_value) {
				const parts  = widget.icon_value.split(/\s+/);
				const eicon  = parts.find(c => c.startsWith('eicon-'));
				return 'eicon ' + (eicon || parts[parts.length - 1]);
			}
			const fallbacks = {
				gallery: 'eicon-gallery-grid', carousel: 'eicon-slider-push',
				slider: 'eicon-slider-push', form: 'eicon-form-horizontal',
				video: 'eicon-youtube', accordion: 'eicon-accordion',
				tabs: 'eicon-tabs', button: 'eicon-button', heading: 'eicon-t-letter',
				image: 'eicon-image', testimonial: 'eicon-testimonial',
				pricing: 'eicon-price-table', countdown: 'eicon-countdown',
				table: 'eicon-table', social: 'eicon-social-icons',
				map: 'eicon-google-maps', search: 'eicon-search',
				menu: 'eicon-nav-menu', woocommerce: 'eicon-woo-cart',
			};
			const t = (widget.widget_type || '').toLowerCase();
			for (const [key, icon] of Object.entries(fallbacks)) {
				if (t.includes(key)) return 'eicon ' + icon;
			}
			return 'eicon eicon-plug';
		},

		statusLabel: function (s) {
			return { active: wfeData.i18n.status_active, inactive: wfeData.i18n.status_inactive, not_installed: wfeData.i18n.status_missing }[s] || s;
		},

		buildTooltip: function (w) {
			return w.widget_title + '\n' + w.plugin_name + '\n' + this.statusLabel(w.status);
		},
	};

	$(function () { WFE.init(); });

})(jQuery);
