(function () {
	'use strict';

	const config = window.macMembers || {};
	const transitions = config.transitions || {};
	const statuses = config.statuses || {};
	const genericError = config.genericError || 'Something went wrong. Please try again.';
	const genericSuccess = config.genericSuccess || 'Member updated.';
	const rangeText = config.rangeText || '%1$s-%2$s of %3$s';
	const columnsCookie = config.columnsCookie || 'mac_members_hidden_columns';
	const rootSelector = '[data-mac-members-table]';
	const rowSelector = 'tr[data-mac-members-user-id]';
	const actionSelector = '[data-mac-members-action][data-mac-members-user-id]';

	const getMessage = (response, fallback) => {
		if (response && response.data && typeof response.data.message === 'string') {
			return response.data.message;
		}

		return fallback;
	};

	const getErrorCode = (response) => {
		if (response && response.data && typeof response.data.code === 'string') {
			return response.data.code;
		}

		return '';
	};

	// Shows a message in a notices area: the table's, or the member details dialog's.
	const showNotice = (notices, type, message) => {
		if (!notices) {
			return;
		}

		notices.innerHTML = '';

		const notice = document.createElement('div');
		notice.className = `mac-members-notice mac-members-notice--${type}`;
		notice.textContent = message;
		notices.appendChild(notice);
	};

	const setRowProcessing = (row, isProcessing) => {
		row.classList.toggle('is-processing', isProcessing);
		row.querySelectorAll('button').forEach((button) => {
			button.disabled = isProcessing;
		});
	};

	const findRow = (root, userId) => [...root.querySelectorAll(rowSelector)].find((row) => row.dataset.macMembersUserId === userId) || null;

	// The member details dialog, while it still shows this member.
	const dialogFor = (dialog, userId) => (dialog && dialog.open && dialog.dataset.macMembersDetailsUserId === userId ? dialog : null);

	const setDialogProcessing = (dialog, isProcessing) => {
		if (!dialog) {
			return;
		}

		dialog.querySelectorAll('[data-mac-members-details-actions] button').forEach((button) => {
			button.disabled = isProcessing;
		});
	};

	// Fills the member details dialog from a row: the details from its template, the footer's buttons from its
	// Actions cell.
	const fillDetails = (dialog, row) => {
		const template = row.querySelector('template[data-mac-members-details]');
		const actions = row.querySelector('.mac-members-actions');

		dialog.dataset.macMembersDetailsUserId = row.dataset.macMembersUserId || '';
		dialog.querySelector('.mac-members-details__title').textContent = template.dataset.macMembersDetailsTitle || '';
		dialog.querySelector('[data-mac-members-details-body]').replaceChildren(template.content.cloneNode(true));
		dialog.querySelector('[data-mac-members-details-notices]').replaceChildren();
		dialog.querySelector('[data-mac-members-details-actions]').replaceChildren(...(actions ? [...actions.children].map((button) => button.cloneNode(true)) : []));
	};

	const showEmptyStateIfNeeded = (root) => {
		const tbody = root.querySelector('tbody');

		if (tbody && tbody.querySelector('tr')) {
			return;
		}

		const tableWrap = root.querySelector('.mac-members-table-wrap');

		if (tableWrap) {
			tableWrap.remove();
		}

		const footer = root.querySelector('.mac-members-footer');

		if (footer) {
			footer.hidden = true;
		}

		const empty = root.querySelector('.mac-members-empty');

		if (empty) {
			empty.hidden = false;
		}
	};

	// Keeps the numbers in the status filters in step with the rows that change status.
	const changeCount = (root, view, delta) => {
		if (!view) {
			return;
		}

		const count = root.querySelector(`[data-mac-members-count-for="${view}"]`);

		if (!count) {
			return;
		}

		const value = parseInt(count.textContent, 10);

		if (!Number.isNaN(value)) {
			count.textContent = String(Math.max(0, value + delta));
		}
	};

	const createButton = (transitionKey, userId) => {
		const button = document.createElement('button');
		const classes = (transitions[transitionKey] && transitions[transitionKey].classes) || '';
		button.type = 'button';
		button.className = `mac-members-button mac-members-button--${transitionKey} ${classes}`.trim();
		button.dataset.macMembersAction = transitionKey;
		button.dataset.macMembersUserId = userId;
		button.textContent = (transitions[transitionKey] && transitions[transitionKey].label) || transitionKey;

		return button;
	};

	// In the "All" view the row stays: show its new status and the changes that status allows.
	const updateRowStatus = (row, status, statusLabel) => {
		row.dataset.macMembersStatus = status;

		const template = row.querySelector('template[data-mac-members-details]');
		const labels = [
			row.querySelector('td .mac-members-status__label'),
			template ? template.content.querySelector('.mac-members-status__label') : null,
		];

		labels.forEach((label) => {
			if (label) {
				label.className = `mac-members-status__label mac-members-status__label--${status}`;
				label.textContent = statusLabel;
			}
		});

		const actions = row.querySelector('.mac-members-actions');

		if (!actions) {
			return;
		}

		actions.textContent = '';

		((statuses[status] && statuses[status].transitions) || []).forEach((transitionKey) => {
			if (transitions[transitionKey]) {
				actions.appendChild(createButton(transitionKey, row.dataset.macMembersUserId));
			}
		});
	};

	const formatNumber = (value) => value.toLocaleString(document.documentElement.lang || undefined);

	// Keeps the range under the table, such as "1-24 of 2,353", in step when a row leaves the view.
	const shrinkRange = (root) => {
		const range = root.querySelector('[data-mac-members-range]');

		if (!range) {
			return null;
		}

		const first = parseInt(range.dataset.macMembersRangeFirst, 10);
		const last = Math.max(0, parseInt(range.dataset.macMembersRangeLast, 10) - 1);
		const total = Math.max(0, parseInt(range.dataset.macMembersRangeTotal, 10) - 1);

		if ([first, last, total].some((value) => Number.isNaN(value))) {
			return null;
		}

		range.dataset.macMembersRangeLast = String(last);
		range.dataset.macMembersRangeTotal = String(total);
		range.textContent = rangeText
			.replace('%1$s', formatNumber(first))
			.replace('%2$s', formatNumber(last))
			.replace('%3$s', formatNumber(total));

		return { total };
	};

	const removeRow = (root, row) => {
		row.classList.add('is-success');

		window.setTimeout(() => {
			row.remove();

			const range = shrinkRange(root);

			// The page is empty but members are left on other pages: load the page again to show them.
			if (range && range.total > 0 && !root.querySelector('tbody tr')) {
				window.location.reload();
				return;
			}

			showEmptyStateIfNeeded(root);
		}, 900);
	};

	const parseJsonResponse = async (response) => {
		try {
			return await response.json();
		} catch (error) {
			console.warn('MAC Members: unexpected AJAX response', error);

			return null;
		}
	};

	const sendAction = async (ajaxAction, userId, renderToken) => {
		const body = new URLSearchParams();
		body.set('action', ajaxAction);
		body.set('nonce', config.nonce || '');
		body.set('user_id', userId);
		body.set('render_token', renderToken);

		const response = await window.fetch(config.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
			},
			body,
		});

		const data = await parseJsonResponse(response);

		if (!response.ok || !data || data.success !== true) {
			console.warn('MAC Members: AJAX action failed', data);
		}

		return {
			ok: response.ok && data && data.success === true,
			data,
			status: response.status,
		};
	};

	// The status buttons of a row, and their copies in the member details dialog's footer. A change made from the
	// dialog closes it, and the table shows the result; an error shows in the dialog.
	document.addEventListener('click', async (event) => {
		const button = event.target.closest(actionSelector);

		if (!button) {
			return;
		}

		const root = button.closest(rootSelector);
		const userId = button.dataset.macMembersUserId;
		const dialog = button.closest('[data-mac-members-details-dialog]');
		const row = button.closest(rowSelector) || (root ? findRow(root, userId) : null);

		if (!root || !row || !config.ajaxUrl) {
			return;
		}

		const transition = transitions[button.dataset.macMembersAction];

		if (!transition || !transition.action || (transition.confirm && !window.confirm(transition.confirm))) {
			return;
		}

		const renderToken = root.dataset.macMembersRenderToken || '';
		const previousStatus = row.dataset.macMembersStatus || '';
		const isAllView = root.dataset.macMembersView === 'all';
		const tableNotices = root.querySelector('.mac-members-notices');
		// Errors show in the dialog while it still shows this member, or else above the table.
		const errorNotices = () => {
			const open = dialogFor(dialog, userId);

			return open ? open.querySelector('[data-mac-members-details-notices]') : tableNotices;
		};
		const closeDialog = () => {
			const open = dialogFor(dialog, userId);

			if (open) {
				open.close();
			}
		};

		setRowProcessing(row, true);
		setDialogProcessing(dialog, true);

		try {
			const result = await sendAction(transition.action, userId, renderToken);

			if (!result.ok) {
				showNotice(errorNotices(), 'error', getMessage(result.data, genericError));

				// The member's status changed elsewhere, so this row no longer belongs in a filtered view, and the
				// dialog's buttons no longer apply.
				if (getErrorCode(result.data) === 'status_changed' && !isAllView) {
					const open = dialogFor(dialog, userId);

					if (open) {
						open.querySelector('[data-mac-members-details-actions]').replaceChildren();
					}

					removeRow(root, row);
					return;
				}

				setRowProcessing(row, false);
				setDialogProcessing(dialogFor(dialog, userId), false);
				return;
			}

			const data = (result.data && result.data.data) || {};

			showNotice(tableNotices, data.warning ? 'warning' : 'success', getMessage(result.data, genericSuccess));
			changeCount(root, previousStatus, -1);
			changeCount(root, data.status, 1);

			if (isAllView && data.status) {
				updateRowStatus(row, data.status, data.status_label || data.status);
				setRowProcessing(row, false);
				// After the row's buttons are enabled again, so the dialog can give the focus back to "View details".
				closeDialog();
				row.classList.add('is-success');
				window.setTimeout(() => row.classList.remove('is-success'), 900);
				return;
			}

			closeDialog();
			removeRow(root, row);
		} catch (error) {
			console.warn('MAC Members: AJAX action failed', error);
			showNotice(errorNotices(), 'error', genericError);
			setRowProcessing(row, false);
			setDialogProcessing(dialogFor(dialog, userId), false);
		}
	});

	// Member details: "View details" fills the table's dialog from the row and opens it. Close, Escape and a click
	// outside the dialog close it.
	document.addEventListener('click', (event) => {
		const opener = event.target.closest('[data-mac-members-details-open]');

		if (opener) {
			const root = opener.closest(rootSelector);
			const row = opener.closest(rowSelector);
			const dialog = root ? root.querySelector('[data-mac-members-details-dialog]') : null;

			if (!row || !row.querySelector('template[data-mac-members-details]') || !dialog || typeof dialog.showModal !== 'function') {
				return;
			}

			fillDetails(dialog, row);
			dialog.showModal();
			return;
		}

		const closer = event.target.closest('[data-mac-members-details-close]');

		if (closer) {
			closer.closest('dialog').close();
			return;
		}

		const dialog = event.target;

		if (!(dialog instanceof HTMLDialogElement) || !dialog.matches('[data-mac-members-details-dialog]')) {
			return;
		}

		const box = dialog.getBoundingClientRect();
		const inside = event.clientX >= box.left && event.clientX <= box.right && event.clientY >= box.top && event.clientY <= box.bottom;

		if (!inside) {
			dialog.close();
		}
	});

	// The column checkboxes show and hide columns through the stylesheet. The cookie keeps the changes to the
	// defaults for a year, for the next page, which the server renders from it: the columns the viewer hid, and
	// with a + the columns that start hidden, like Last Login, that the viewer showed.
	document.addEventListener('change', (event) => {
		const toggle = event.target;

		if (!(toggle instanceof HTMLInputElement) || !toggle.matches('[data-mac-members-column-toggle]')) {
			return;
		}

		const root = toggle.closest(rootSelector);

		if (!root) {
			return;
		}

		const changes = [...root.querySelectorAll('[data-mac-members-column-toggle]')]
			.map((box) => {
				const startsHidden = box.dataset.macMembersColumnDefault === 'hidden';

				if (startsHidden) {
					return box.checked ? `+${box.value}` : '';
				}

				return box.checked ? '' : box.value;
			})
			.filter((change) => change !== '');
		const secure = window.location.protocol === 'https:' ? '; Secure' : '';

		document.cookie = `${columnsCookie}=${encodeURIComponent(changes.join(','))}; path=/; max-age=31536000; SameSite=Lax${secure}`;
	});

	// When the table fits its frame, the frame stops scrolling and the header sticks to the page instead. The check
	// runs with the frame marked, so the frame's own scrollbar does not change the answer. It runs again when the
	// frame or the table changes size, for example when the window narrows or a column is hidden.
	const fitFrame = (wrap) => {
		const table = wrap.querySelector('table');

		if (!table) {
			return;
		}

		wrap.classList.add('is-page-sticky');
		wrap.classList.toggle('is-page-sticky', table.offsetWidth <= wrap.clientWidth);
	};

	document.querySelectorAll('.mac-members-table-wrap').forEach((wrap) => {
		fitFrame(wrap);

		if (typeof window.ResizeObserver !== 'function') {
			return;
		}

		const observer = new window.ResizeObserver(() => window.requestAnimationFrame(() => fitFrame(wrap)));
		const table = wrap.querySelector('table');

		observer.observe(wrap);

		if (table) {
			observer.observe(table);
		}
	});

	// The role and search form leaves out empty fields, so the page address only has the choices that are set.
	document.querySelectorAll('form.mac-members-search').forEach((form) => {
		form.addEventListener('formdata', (event) => {
			[...event.formData.entries()].forEach(([name, value]) => {
				if (value === '') {
					event.formData.delete(name);
				}
			});
		});
	});

	// The role filter and the page size have no button: choosing an option sends their form, like Enter in
	// the search. The page size select belongs to that form through its form attribute.
	document.addEventListener('change', (event) => {
		const select = event.target;

		if (!(select instanceof HTMLSelectElement) || !select.matches('[data-mac-members-autosubmit]') || !select.form) {
			return;
		}

		if (typeof select.form.requestSubmit === 'function') {
			select.form.requestSubmit();
			return;
		}

		select.form.submit();
	});

	// The search sends its form by itself 400 ms after the last change, once it has at least 3 characters, or
	// when it's emptied while a search is set; Enter sends it at any length. The page that loads next puts the
	// cursor back at the end of the search, with anything typed while it loaded, and searches again for that.
	const searchDelay = 400;
	const searchMinLength = 3;
	const searchStorageKey = 'mac_members_search';
	const searchStorageAge = 15000;

	const rememberSearch = (form, value) => {
		try {
			window.sessionStorage.setItem(searchStorageKey, JSON.stringify({ action: form.action, value, time: Date.now() }));
		} catch (error) {
			// Without session storage the next page only leaves the cursor out of the search.
		}
	};

	const takeRememberedSearch = (form) => {
		try {
			const stored = JSON.parse(window.sessionStorage.getItem(searchStorageKey) || 'null');

			window.sessionStorage.removeItem(searchStorageKey);

			if (!stored || stored.action !== form.action || typeof stored.value !== 'string' || Date.now() - stored.time > searchStorageAge) {
				return null;
			}

			return stored.value;
		} catch (error) {
			return null;
		}
	};

	document.querySelectorAll('form.mac-members-search').forEach((form) => {
		const input = form.querySelector('.mac-members-search__input');

		if (!input) {
			return;
		}

		const applied = input.value.trim();
		let timer = 0;
		let sending = false;

		const send = () => {
			sending = true;
			rememberSearch(form, input.value);

			if (typeof form.requestSubmit === 'function') {
				form.requestSubmit();
				return;
			}

			form.submit();
		};

		const schedule = () => {
			const value = input.value.trim();

			window.clearTimeout(timer);

			if (value === applied || (value !== '' && value.length < searchMinLength)) {
				return;
			}

			timer = window.setTimeout(send, searchDelay);
		};

		input.addEventListener('input', (event) => {
			if (!event.isComposing) {
				schedule();
			}
		});

		input.addEventListener('compositionend', schedule);

		input.addEventListener('keydown', (event) => {
			if (event.key === 'Enter' && !event.isComposing) {
				window.clearTimeout(timer);
				sending = true;
				rememberSearch(form, input.value);
			}
		});

		window.addEventListener('pagehide', () => {
			if (sending) {
				rememberSearch(form, input.value);
			}
		});

		const remembered = takeRememberedSearch(form);

		if (remembered === null) {
			return;
		}

		input.value = remembered;
		input.focus();
		input.setSelectionRange(remembered.length, remembered.length);
		schedule();
	});
})();
