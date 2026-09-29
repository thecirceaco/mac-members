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

	const showNotice = (root, type, message) => {
		const notices = root.querySelector('.mac-members-notices');

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

	document.addEventListener('click', async (event) => {
		const button = event.target.closest(actionSelector);

		if (!button) {
			return;
		}

		const root = button.closest(rootSelector);
		const row = button.closest(rowSelector);

		if (!root || !row || !config.ajaxUrl) {
			return;
		}

		const transition = transitions[button.dataset.macMembersAction];

		if (!transition || !transition.action || (transition.confirm && !window.confirm(transition.confirm))) {
			return;
		}

		const userId = button.dataset.macMembersUserId;
		const renderToken = root.dataset.macMembersRenderToken || '';
		const previousStatus = row.dataset.macMembersStatus || '';
		const isAllView = root.dataset.macMembersView === 'all';

		setRowProcessing(row, true);

		try {
			const result = await sendAction(transition.action, userId, renderToken);

			if (!result.ok) {
				showNotice(root, 'error', getMessage(result.data, genericError));

				// The member's status changed elsewhere, so this row no longer belongs in a filtered view.
				if (getErrorCode(result.data) === 'status_changed' && !isAllView) {
					removeRow(root, row);
					return;
				}

				setRowProcessing(row, false);
				return;
			}

			const data = (result.data && result.data.data) || {};

			showNotice(root, data.warning ? 'warning' : 'success', getMessage(result.data, genericSuccess));
			changeCount(root, previousStatus, -1);
			changeCount(root, data.status, 1);

			if (isAllView && data.status) {
				updateRowStatus(row, data.status, data.status_label || data.status);
				setRowProcessing(row, false);
				row.classList.add('is-success');
				window.setTimeout(() => row.classList.remove('is-success'), 900);
				return;
			}

			removeRow(root, row);
		} catch (error) {
			console.warn('MAC Members: AJAX action failed', error);
			showNotice(root, 'error', genericError);
			setRowProcessing(row, false);
		}
	});

	// Member details: "View details" copies the row's template into the table's dialog and opens it. Close, Escape
	// and a click outside the dialog close it.
	document.addEventListener('click', (event) => {
		const opener = event.target.closest('[data-mac-members-details-open]');

		if (opener) {
			const root = opener.closest(rootSelector);
			const template = opener.parentElement ? opener.parentElement.querySelector('template[data-mac-members-details]') : null;
			const dialog = root ? root.querySelector('[data-mac-members-details-dialog]') : null;

			if (!template || !dialog || typeof dialog.showModal !== 'function') {
				return;
			}

			dialog.querySelector('[data-mac-members-details-body]').replaceChildren(template.content.cloneNode(true));
			dialog.querySelector('.mac-members-details__title').textContent = template.dataset.macMembersDetailsTitle || '';
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
})();
