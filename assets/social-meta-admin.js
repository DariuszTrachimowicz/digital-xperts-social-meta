(() => {
	const admin = document.querySelector('.dxsm-admin');
	if (!admin) {
		return;
	}

	const fragment = window.location.hash;
	if (fragment === '#dxsm-admin-preview' || fragment === '#dxsm-admin-results') {
		admin.querySelector(`${fragment}[tabindex="-1"]`)?.focus({ preventScroll: true });
	}

	const picker = admin.querySelector('.dxsm-admin-search');
	const filterKeys = ['dxsm_schema_search', 'dxsm_schema_type', 'dxsm_sort', 'dxsm_seo_filter', 'dxsm_year', 'dxsm_month', 'dxsm_results_page'];
	const filterParams = new URLSearchParams(window.location.search);
	const resumeKey = picker?.dataset.resumeKey;
	const rememberWorkspace = () => {
		if (!resumeKey) return;
		const state = new URLSearchParams(new FormData(picker));
		state.delete('page');
		if (Number(picker.dataset.resultsPage) > 1) state.set('dxsm_results_page', picker.dataset.resultsPage);
		for (const [key, value] of [...state]) {
			if (!value || (key === 'dxsm_schema_type' && value === 'all') || (key === 'dxsm_sort' && value === 'recent')) {
				state.delete(key);
			}
		}
		try {
			window.localStorage.setItem(resumeKey, state.toString());
		} catch {
			// The panel also works when browser storage is blocked.
		}
	};

	if (resumeKey) {
		const url = new URL(window.location.href);
		const resumed = url.searchParams.get('dxsm_resume') === '1';
		const stateKeys = [...filterKeys, 'dxsm_schema_post', 'dxsm_view'];
		if (!url.hash && [...url.searchParams.keys()].every((key) => key === 'page')) {
			let stored;
			try {
				stored = new URLSearchParams(window.localStorage.getItem(resumeKey) || '');
			} catch {
				stored = new URLSearchParams();
			}
			if ([...stored.keys()].some((key) => stateKeys.includes(key))) {
				stateKeys.forEach((key) => {
					if (stored.has(key)) url.searchParams.set(key, stored.get(key));
				});
				url.searchParams.set('dxsm_resume', '1');
				window.location.replace(url.href);
				return;
			}
		}
		if (resumed) {
			url.searchParams.delete('dxsm_resume');
			if (picker.dataset.resultsPage === '1') {
				url.searchParams.delete('dxsm_results_page');
				filterParams.delete('dxsm_results_page');
			}
			const selected = picker.elements.namedItem('dxsm_schema_post');
			if (selected) {
				url.searchParams.set('dxsm_schema_post', selected.value);
			} else {
				url.searchParams.delete('dxsm_schema_post');
			}
			window.history.replaceState(window.history.state, '', url.href);
		}
		rememberWorkspace();
	}

	const getView = () => picker.elements.namedItem('dxsm_view').value;
	const withFilters = (url) => {
		filterKeys.forEach((key) => {
			if (filterParams.has(key)) {
				url.searchParams.set(key, filterParams.get(key));
			} else {
				url.searchParams.delete(key);
			}
		});
		return url;
	};
	const syncPickerView = (view) => {
		picker.elements.namedItem('dxsm_view').value = view;
		admin.querySelectorAll('.dxsm-admin-results a, .dxsm-admin-pagination a, [data-dxsm-clear]').forEach((link) => {
			const url = new URL(link.href);
			url.searchParams.set('dxsm_view', view);
			link.href = url.href;
		});
	};

	if (picker && picker.dataset.ajaxUrl) {
		const results = admin.querySelector('[data-dxsm-results]');
		const status = admin.querySelector('[data-dxsm-search-status]');
		const search = picker.elements.namedItem('dxsm_schema_search');
		let request;
		let timer;
		let failed = false;

		const loadResults = (page = 1, focusResults = false) => {
			window.clearTimeout(timer);
			request?.abort();
			const current = new AbortController();
			request = current;
			failed = false;
			status.textContent = 'Szukam…';
			results.setAttribute('aria-busy', 'true');
			timer = window.setTimeout(async () => {
				const params = new URLSearchParams(new FormData(picker));
				params.set('action', 'dxsm_filter_content');
				params.set('nonce', picker.dataset.nonce);
				params.set('dxsm_results_page', String(page));
				try {
					const response = await fetch(`${picker.dataset.ajaxUrl}?${params}`, { signal: current.signal, cache: 'no-store' });
					const result = await response.json();
					if (!response.ok || !result.success) {
						throw new Error('Search failed');
					}
					if (request !== current) {
						return;
					}
					results.innerHTML = result.data.html;
					const total = result.data.total;
					status.textContent = total ? `Liczba wyników: ${total}.` : 'Brak wyników.';
					filterKeys.forEach((key) => {
						filterParams.delete(key);
						if (Object.hasOwn(result.data.filters, key)) filterParams.set(key, result.data.filters[key]);
					});
					syncPickerView(getView());

					const location = withFilters(new URL(window.location.href));
					location.searchParams.set('dxsm_view', getView());
					const selected = params.get('dxsm_schema_post');
					if (selected) location.searchParams.set('dxsm_schema_post', selected);
					window.history.replaceState(window.history.state, '', location);
					admin.querySelectorAll('.dxsm-admin-views a').forEach((link) => { link.href = withFilters(new URL(link.href)).href; });
					const edit = admin.querySelector('.dxsm-admin-edit');
					if (edit) edit.setAttribute('action', withFilters(new URL(edit.getAttribute('action'), document.baseURI)).href);
					picker.dataset.resultsPage = result.data.page;
					rememberWorkspace();
					if (focusResults) admin.querySelector('#dxsm-admin-results').focus();
				} catch (error) {
					if (request === current && error.name !== 'AbortError') {
						failed = true;
						status.textContent = 'Nie udało się odświeżyć wyników. Kliknij Szukaj, aby spróbować ponownie.';
					}
				} finally {
					if (request === current) results.removeAttribute('aria-busy');
				}
			}, focusResults ? 0 : 250);
		};

		search.addEventListener('input', (event) => {
			if (!event.isComposing) loadResults();
		});
		search.addEventListener('compositionend', () => loadResults());
		picker.addEventListener('change', (event) => {
			if (event.target.matches('input[type="radio"], select')) loadResults();
		});
		picker.addEventListener('submit', (event) => {
			if (failed) return;
			event.preventDefault();
			loadResults(1, true);
		});
		picker.querySelector('[data-dxsm-clear]').addEventListener('click', (event) => {
			if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
			event.preventDefault();
			search.value = '';
			picker.elements.namedItem('dxsm_schema_type').value = 'all';
			picker.elements.namedItem('dxsm_sort').value = 'recent';
			['dxsm_seo_filter', 'dxsm_year', 'dxsm_month'].forEach((key) => {
				const field = picker.elements.namedItem(key);
				if (field) field.value = '';
			});
			search.focus();
			loadResults();
		});
		results.addEventListener('click', (event) => {
			const link = event.target.closest('.dxsm-admin-pagination a');
			if (!link || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
			event.preventDefault();
			loadResults(Number(new URL(link.href).searchParams.get('dxsm_results_page')) || 1, true);
		});
	}

	const previews = admin.querySelector('[data-dxsm-live-previews]');
	if (previews) {
		const panes = previews.querySelectorAll('[data-dxsm-preview-pane]');
		const tabs = admin.querySelectorAll('[data-dxsm-preview-tab]');
		const returnView = previews.querySelector('[name="dxsm_return_view"]');

		tabs.forEach((tab) => tab.addEventListener('click', (event) => {
			if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
				return;
			}

			window.history.replaceState(window.history.state, '', tab.href);
			event.preventDefault();
			const view = tab.dataset.dxsmPreviewTab;
			panes.forEach((pane) => { pane.hidden = pane.dataset.dxsmPreviewPane !== view; });
			tabs.forEach((item) => item.removeAttribute('aria-current'));
			tab.setAttribute('aria-current', 'page');
			if (returnView) {
				returnView.value = view;
			}
			if (picker) syncPickerView(view);
			rememberWorkspace();
		}));

		const form = previews.querySelector('.dxsm-admin-edit');
		if (form) {
			const fields = [...form.querySelectorAll('[data-seo-input]')];
			const warn = (event) => {
				event.preventDefault();
				event.returnValue = true;
			};
			form.addEventListener('input', () => {
				if (fields.some((field) => field.value !== field.defaultValue)) {
					window.addEventListener('beforeunload', warn);
				} else {
					window.removeEventListener('beforeunload', warn);
				}
			});
			form.addEventListener('submit', () => window.removeEventListener('beforeunload', warn));
		}
	}

	const button = admin.querySelector('[data-dxsm-copy-json]');
	const panel = button?.closest('.dxsm-admin-panel');
	const code = panel?.querySelector('.dxsm-admin-code pre');
	const status = panel?.querySelector('[data-dxsm-copy-status]');
	if (!code || !status || typeof navigator.clipboard?.writeText !== 'function') {
		return;
	}

	button.addEventListener('click', async () => {
		status.textContent = '';
		try {
			await navigator.clipboard.writeText(code.textContent);
			status.textContent = 'Skopiowano JSON-LD.';
		} catch {
			status.textContent = 'Kopiowanie nie powiodło się. Rozwiń kod i skopiuj go ręcznie.';
		}
	});
	button.hidden = false;
})();
