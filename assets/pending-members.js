(function () {
	'use strict';

	const config = window.macMembers || {};
	const rootSelector = '[data-mac-members-pending-table]';
	const actionSelector = '[data-mac-members-action][data-mac-members-user-id]';

	const getMessage = (response, fallback) => {
		if (response && response.data && typeof response.data.message === 'string') {
			return response.data.message;
		}

		return fallback;
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

		const empty = root.querySelector('.mac-members-empty');

		if (empty) {
			empty.hidden = false;
			empty.textContent = config.emptyStateText || 'There are no pending members.';
		}
	};

	const parseJsonResponse = async (response) => {
		try {
			return await response.json();
		} catch (error) {
			console.warn('MAC Members: unexpected AJAX response', error);

			return null;
		}
	};

	const sendAction = async (ajaxAction, userId) => {
		const body = new URLSearchParams();
		body.set('action', ajaxAction);
		body.set('nonce', config.nonce || '');
		body.set('user_id', userId);

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
		const row = button.closest('[data-mac-members-user-id]');

		if (!root || !row || !config.ajaxUrl) {
			return;
		}

		const actionType = button.dataset.macMembersAction;
		const userId = button.dataset.macMembersUserId;
		const isApprove = actionType === 'approve';
		const ajaxAction = isApprove ? config.approveAction : config.denyAction;
		const confirmMessage = isApprove ? config.confirmApprove : config.confirmDeny;

		if (!ajaxAction || (confirmMessage && !window.confirm(confirmMessage))) {
			return;
		}

		setRowProcessing(row, true);

		try {
			const result = await sendAction(ajaxAction, userId);

			if (!result.ok) {
				showNotice(root, 'error', getMessage(result.data, config.genericError || 'Something went wrong. Please try again.'));

				if (result.status === 409 || (result.data && result.data.data && result.data.data.code === 'not_pending')) {
					row.classList.add('is-success');
					window.setTimeout(() => {
						row.remove();
						showEmptyStateIfNeeded(root);
					}, 900);
					return;
				}

				setRowProcessing(row, false);
				return;
			}

			showNotice(
				root,
				result.data && result.data.data && result.data.data.warning ? 'warning' : 'success',
				getMessage(result.data, config.genericSuccess || 'Member updated.')
			);
			row.classList.add('is-success');

			window.setTimeout(() => {
				row.remove();
				showEmptyStateIfNeeded(root);
			}, 900);
		} catch (error) {
			console.warn('MAC Members: AJAX action failed', error);
			showNotice(root, 'error', config.genericError || 'Something went wrong. Please try again.');
			setRowProcessing(row, false);
		}
	});
})();
