/**
 * WooCommerce Conflict Doctor — wizard state machine.
 * Vanilla JavaScript, no framework. Runs on the admin page at WooCommerce > Conflict Doctor.
 */
(function() {
	'use strict';

	const data = window.wcdData || {};
	const S = data.strings || {};
	const STORAGE_KEY = 'wcd_device_token_v1';

	const root = document.getElementById('wcd-wizard-app');
	if (!root) return;

	// --------------------------------------------------------------------
	// State
	// --------------------------------------------------------------------
	const state = {
		step: 'loading',
		symptom: '',
		mode: 'full',          // 'focused' | 'full'
		suspects: [],
		showAllPlugins: false,
		theme: '',
		cachePurge: false,
		session: null,
		culprit: null,
		error: null,
		lastAnswer: null,      // last round answer, to show not-sure disclosure
		allowlistKept: [],     // plugins kept active during test (for HE diagnostic)
	};

	// --------------------------------------------------------------------
	// AJAX
	// --------------------------------------------------------------------
	async function ajax(action, payload) {
		const body = new URLSearchParams();
		body.set('action', action);
		body.set('nonce', data.nonce);
		if (payload) {
			Object.keys(payload).forEach((k) => {
				const v = payload[k];
				if (Array.isArray(v)) {
					v.forEach((item) => body.append(k + '[]', item));
				} else if (v !== undefined && v !== null) {
					body.set(k, v);
				}
			});
		}

		const res = await fetch(data.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body,
		});

		let json;
		try {
			json = await res.json();
		} catch (e) {
			throw new Error(S.genericError);
		}

		if (!json || !json.success) {
			const msg = (json && json.data && json.data.message) || S.genericError;
			const err = new Error(msg);
			err.code = json && json.data && json.data.code;
			throw err;
		}
		return json.data;
	}

	// --------------------------------------------------------------------
	// Rendering
	// --------------------------------------------------------------------
	function setStep(step, patch) {
		Object.assign(state, patch || {});
		state.step = step;
		render();
	}

	function render() {
		root.removeAttribute('data-loading');
		const tpl = templates[state.step];
		if (!tpl) {
			root.innerHTML = '<p>Unknown state: ' + escapeHtml(state.step) + '</p>';
			return;
		}
		root.innerHTML = tpl();
		bindHandlers();
	}

	function escapeHtml(s) {
		if (s === null || s === undefined) return '';
		return String(s)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;')
			.replace(/'/g, '&#39;');
	}

	function card(body, footer) {
		return '<div class="wcd-card">' + body + (footer ? '<div class="wcd-actions">' + footer + '</div>' : '') + '</div>';
	}

	function button(label, attrs) {
		const a = attrs || {};
		const cls = a.className || 'button';
		const action = a.action ? ' data-action="' + escapeHtml(a.action) + '"' : '';
		const disabled = a.disabled ? ' disabled' : '';
		return '<button type="button" class="' + cls + '"' + action + disabled + '>' + escapeHtml(label) + '</button>';
	}

	function notice(text, kind) {
		return '<div class="wcd-notice wcd-notice-' + (kind || 'info') + '">' + escapeHtml(text) + '</div>';
	}

	function spinner(label) {
		return '<div class="wcd-spinner"><span class="spinner is-active"></span> ' + escapeHtml(label || S.loading) + '</div>';
	}

	// --------------------------------------------------------------------
	// Templates (one per step)
	// --------------------------------------------------------------------
	const templates = {
		loading() {
			return spinner();
		},

		symptom() {
			let options = '<option value="">' + escapeHtml(S.symptomHeader) + '</option>';
			Object.keys(data.symptoms || {}).forEach((key) => {
				const sel = state.symptom === key ? ' selected' : '';
				options += '<option value="' + escapeHtml(key) + '"' + sel + '>' +
					escapeHtml(data.symptoms[key]) + '</option>';
			});

			const body = '<h2>' + escapeHtml(S.symptomHeader) + '</h2>' +
				'<select class="wcd-select" data-field="symptom">' + options + '</select>';

			const footer = button(S.continue, { className: 'button button-primary', action: 'symptom:next', disabled: !state.symptom });
			return card(body, footer);
		},

		mode() {
			const plugins = data.plugins || [];
			const nonAllowlisted = plugins.filter((p) => p.active && !p.allowlisted);
			const topSuspects = nonAllowlisted.slice(0, 3);
			const visible = state.showAllPlugins ? nonAllowlisted : topSuspects;

			let list = '';
			visible.forEach((p) => {
				const checked = state.suspects.indexOf(p.file) !== -1 ? ' checked' : '';
				list += '<label class="wcd-plugin-row">' +
					'<input type="checkbox" data-suspect="' + escapeHtml(p.file) + '"' + checked + '>' +
					'<span class="wcd-plugin-name">' + escapeHtml(p.name) + '</span>' +
					'<span class="wcd-plugin-meta">v' + escapeHtml(p.version) + ' · ' + escapeHtml(p.installed_ago) + ' ago</span>' +
					'</label>';
			});

			const toggleLabel = state.showAllPlugins ? S.modeLikely : S.modeShowAll;
			const modeFullChecked = state.mode === 'full' ? ' checked' : '';
			const modeFocusChecked = state.mode === 'focused' ? ' checked' : '';

			const body = '<h2>' + escapeHtml(S.modeHeader) + '</h2>' +
				'<p class="wcd-help">' + escapeHtml(S.modeLikely) + '</p>' +
				'<div class="wcd-plugin-list">' + list + '</div>' +
				'<p><a href="#" data-action="mode:toggleAll">' + escapeHtml(toggleLabel) + '</a></p>' +
				'<div class="wcd-mode-radio">' +
				'<label><input type="radio" name="wcd-mode" value="full" data-field="mode"' + modeFullChecked + '> ' +
				escapeHtml(S.modeTestAll) + '</label>' +
				'<label><input type="radio" name="wcd-mode" value="focused" data-field="mode"' + modeFocusChecked + '> ' +
				escapeHtml(S.modeHeader) + '</label>' +
				'</div>';

			const disabled = state.mode === 'focused' && state.suspects.length === 0;
			const footer = button(S.back, { action: 'mode:back' }) +
				button(S.continue, { className: 'button button-primary', action: 'mode:next', disabled: disabled });
			return card(body, footer);
		},

		theme() {
			const themes = data.themes || [];
			let list = '';
			themes.forEach((t) => {
				const checked = state.theme === t.slug ? ' checked' : '';
				const recommended = t.preferred ? ' <em>(' + escapeHtml(S.themeRecommended) + ')</em>' : '';
				list += '<label class="wcd-theme-row">' +
					'<input type="radio" name="wcd-theme" value="' + escapeHtml(t.slug) + '" data-field="theme"' + checked + '>' +
					'<span>' + escapeHtml(t.name) + recommended + '</span>' +
					'</label>';
			});
			const keepChecked = state.theme === '__keep__' ? ' checked' : '';
			list += '<label class="wcd-theme-row">' +
				'<input type="radio" name="wcd-theme" value="__keep__" data-field="theme"' + keepChecked + '>' +
				'<span>' + escapeHtml(S.themeKeepCurrent) + '</span>' +
				'</label>';

			const body = '<h2>' + escapeHtml(S.themeHeader) + '</h2>' + list;
			const footer = button(S.back, { action: 'theme:back' }) +
				button(S.continue, { className: 'button button-primary', action: 'theme:next', disabled: !state.theme });
			return card(body, footer);
		},

		cacheWarn() {
			const name = (data.cachePlugin && data.cachePlugin.name) || '';
			const body = '<h2>' + escapeHtml(name) + '</h2>' +
				notice(S.cacheWarning, 'warning') +
				'<div class="wcd-cache-choice">' +
				'<label><input type="radio" name="wcd-cache" value="purge" data-field="cachePurge"' +
				(state.cachePurge === true ? ' checked' : '') + '> ' + escapeHtml(S.cachePurgeBefore) + '</label>' +
				'<label><input type="radio" name="wcd-cache" value="skip" data-field="cachePurge"' +
				(state.cachePurge === false ? ' checked' : '') + '> ' + escapeHtml(S.cacheSkip) + '</label>' +
				'</div>';
			const footer = button(S.back, { action: 'cache:back' }) +
				button(S.continue, { className: 'button button-primary', action: 'cache:next' });
			return card(body, footer);
		},

		confirm() {
			const plugins = data.plugins || [];
			const stayActive = plugins.filter((p) => p.active && p.allowlisted).map((p) => p.name);
			let disabledList;
			if (state.mode === 'focused') {
				disabledList = state.suspects.map((f) => {
					const p = plugins.find((x) => x.file === f);
					return p ? p.name : f;
				});
			} else {
				disabledList = plugins.filter((p) => p.active && !p.allowlisted).map((p) => p.name);
			}

			const themeName = state.theme === '__keep__'
				? data.currentTheme
				: (data.themes.find((t) => t.slug === state.theme) || {}).name || state.theme;

			let body = '<h2>' + escapeHtml(S.confirmHeader) + '</h2>' +
				notice(S.confirmReassure, 'info') +
				'<h4>' + escapeHtml(S.confirmStayActive) + '</h4>' +
				'<ul class="wcd-plugin-summary">' +
				stayActive.map((n) => '<li>' + escapeHtml(n) + '</li>').join('') +
				'</ul>' +
				'<h4>' + escapeHtml(S.confirmTempOff) + ' (' + disabledList.length + ')</h4>' +
				'<details class="wcd-collapsible"><summary>' + escapeHtml(S.showList) + '</summary>' +
				'<ul class="wcd-plugin-summary">' +
				disabledList.map((n) => '<li>' + escapeHtml(n) + '</li>').join('') +
				'</ul></details>';

			if (state.theme && state.theme !== '__keep__') {
				body += '<h4>' + escapeHtml(S.confirmTheme) + '</h4><p>' + escapeHtml(themeName) + '</p>';
			}

			if (data.debugMode) {
				body += notice(S.debugWarning, 'warning');
			}

			const footer = button(S.cancel, { action: 'confirm:cancel' }) +
				button(S.go, { className: 'button button-primary', action: 'confirm:go' });
			return card(body, footer);
		},

		starting() {
			return spinner(S.updating);
		},

		waiting() {
			const session = state.session || {};
			const symptom = session.symptom || state.symptom;
			const tryUrl = (data.symptomUrls || {})[symptom] || data.homeUrl;

			const expires = session.expires_at || 0;
			const remaining = Math.max(0, expires - Math.floor(Date.now() / 1000));
			const mins = Math.floor(remaining / 60);

			let body = '<div class="wcd-waiting">' +
				'<h2>' + escapeHtml(S.waitingHeader) + '</h2>' +
				'<p>' + escapeHtml(S.waitingBody) + '</p>' +
				'<p class="wcd-timer">Session expires in ' + mins + ' minutes.</p>' +
				'<p><a href="' + escapeHtml(tryUrl) + '" target="_blank" rel="noopener" class="button button-primary button-hero" data-action="waiting:tryit">' +
				escapeHtml(S.tryItNow) + '</a></p>' +
				'<p class="wcd-help">' + escapeHtml(S.tryItHint) + '</p>';

			if (state.lastAnswer === 'not_sure') {
				body += notice(S.notSureDisclosure, 'info');
			}

			body += '<div class="wcd-round-buttons">' +
				button(S.worksNow, { className: 'button button-primary', action: 'round:fixed' }) +
				button(S.stillBroken, { className: 'button', action: 'round:broken' }) +
				button(S.notSure, { className: 'button', action: 'round:not_sure' }) +
				'</div>';

			body += '<p class="wcd-abort-link"><a href="#" data-action="abort">' + escapeHtml(S.abort) + '</a></p>';
			body += '</div>';

			return body;
		},

		updating() {
			return spinner(S.updating);
		},

		culprit() {
			const c = state.culprit || {};
			const diag = 'Plugin: ' + (c.name || '') + ' (v' + (c.version || '') + ')\n' +
				'Symptom: ' + (state.symptom || '') + '\n' +
				'Tested: ' + new Date().toISOString().slice(0, 10);

			const body = '<div class="wcd-result wcd-result-culprit">' +
				'<h2>' + escapeHtml(S.culpritHeader) + '</h2>' +
				'<div class="wcd-culprit-card">' +
				'<div class="wcd-culprit-name">' + escapeHtml(c.name || '') + '</div>' +
				'<div class="wcd-culprit-version">v' + escapeHtml(c.version || '') + '</div>' +
				(c.author ? '<div class="wcd-culprit-author">by ' + escapeHtml(c.author) + '</div>' : '') +
				'</div>' +
				'<h4>What to do next:</h4>' +
				'<ol>' +
				'<li>Update ' + escapeHtml(c.name || '') + ' to the latest version.</li>' +
				'<li>Check their support site for known conflicts.</li>' +
				'<li>Contact their support team with this diagnosis.</li>' +
				'</ol>' +
				notice(S.restoredBody, 'info') +
				'<textarea class="wcd-diagnosis" readonly>' + escapeHtml(diag) + '</textarea>' +
				'</div>';

			const footer = button(S.copyDiagnosis, { action: 'culprit:copy' }) +
				button(S.done, { className: 'button button-primary', action: 'culprit:done' });
			return card(body, footer);
		},

		notAConflict() {
			const kept = state.allowlistKept || [];
			let boundary = '';
			if (kept.length) {
				const list = kept.map((p) => '<li>' + escapeHtml(p.name || p.file) + '</li>').join('');
				boundary = '<h4>' + escapeHtml(S.allowlistBoundaryHeader) + '</h4>' +
					'<p class="wcd-help">' + escapeHtml(S.allowlistBoundaryBody) + '</p>' +
					'<ul class="wcd-plugin-summary">' + list + '</ul>';
			}

			const body = '<div class="wcd-result wcd-result-not-conflict">' +
				'<h2>' + escapeHtml(S.notAConflictHeader) + '</h2>' +
				'<p>' + escapeHtml(S.notAConflictBody).replace(/\n/g, '<br>') + '</p>' +
				boundary +
				notice(S.restoredBody, 'info') +
				'</div>';
			const footer = button(S.done, { className: 'button button-primary', action: 'done' });
			return card(body, footer);
		},

		intermittent() {
			const body = '<div class="wcd-result">' +
				'<h2>' + escapeHtml(S.intermittentHeader) + '</h2>' +
				notice(S.intermittentBody, 'warning') +
				'</div>';
			const footer = button(S.abort, { action: 'abort' }) +
				button(S.continue, { className: 'button button-primary', action: 'intermittent:continue' });
			return card(body, footer);
		},

		restored() {
			const body = '<div class="wcd-result">' +
				'<h2>' + escapeHtml(S.restoredHeader) + '</h2>' +
				'<p>' + escapeHtml(S.restoredBody) + '</p>' +
				'</div>';
			const footer = button(S.done, { className: 'button button-primary', action: 'done' });
			return card(body, footer);
		},

		resumePrompt() {
			const body = '<h2>' + escapeHtml(S.resumeHeader) + '</h2>' +
				'<p>' + escapeHtml(S.resumePrompt) + '</p>';
			const footer = button(S.abort, { action: 'resume:abort' }) +
				button(S.resume, { className: 'button button-primary', action: 'resume:continue' });
			return card(body, footer);
		},

		managedHost() {
			const body = '<h2>' + escapeHtml(S.managedHostHeader) + '</h2>' +
				'<p>' + escapeHtml(S.managedHostBody).replace(/\n/g, '<br>') + '</p>';
			return card(body);
		},

		error() {
			const body = '<div class="wcd-result wcd-result-error">' +
				'<h2>Something went wrong</h2>' +
				'<p>' + escapeHtml(state.error || S.genericError) + '</p>' +
				'</div>';
			const footer = button(S.restart, { className: 'button button-primary', action: 'error:restart' });
			return card(body, footer);
		},
	};

	// --------------------------------------------------------------------
	// Handlers
	// --------------------------------------------------------------------
	function bindHandlers() {
		root.querySelectorAll('[data-action]').forEach((el) => {
			el.addEventListener('click', onAction);
		});
		root.querySelectorAll('[data-field]').forEach((el) => {
			el.addEventListener('change', onFieldChange);
		});
		root.querySelectorAll('[data-suspect]').forEach((el) => {
			el.addEventListener('change', onSuspectToggle);
		});
	}

	function onFieldChange(e) {
		const field = e.target.dataset.field;
		if (field === 'cachePurge') {
			state.cachePurge = e.target.value === 'purge';
		} else {
			state[field] = e.target.value;
		}
		render();
	}

	function onSuspectToggle(e) {
		const file = e.target.dataset.suspect;
		if (e.target.checked) {
			if (state.suspects.indexOf(file) === -1) state.suspects.push(file);
		} else {
			state.suspects = state.suspects.filter((f) => f !== file);
		}
		render();
	}

	async function onAction(e) {
		const action = e.currentTarget.dataset.action;
		// waiting:tryit handles its own preventDefault conditionally so the
		// anchor can still open target="_blank" when nothing needs to block.
		if (action !== 'waiting:tryit') {
			e.preventDefault();
		}
		try {
			await handle(action, e);
		} catch (err) {
			if (err.code === 'wcd_session_expired' || err.code === 'wcd_ttl_expired') {
				setStep('error', { error: S.expired });
			} else if (err.code === 'wcd_allowlist_deactivated') {
				setStep('error', { error: err.message });
			} else {
				setStep('error', { error: err.message || S.genericError });
			}
		}
	}

	async function handle(action, event) {
		switch (action) {
			case 'symptom:next':
				setStep('mode');
				break;

			case 'mode:back':
				setStep('symptom');
				break;

			case 'mode:toggleAll':
				state.showAllPlugins = !state.showAllPlugins;
				render();
				break;

			case 'mode:next':
				if (data.cachePlugin) {
					setStep('theme');
				} else {
					setStep('theme');
				}
				break;

			case 'theme:back':
				setStep('mode');
				break;

			case 'theme:next':
				if (data.cachePlugin) {
					setStep('cacheWarn');
				} else {
					setStep('confirm');
				}
				break;

			case 'cache:back':
				setStep('theme');
				break;

			case 'cache:next':
				setStep('confirm');
				break;

			case 'confirm:cancel':
				setStep('symptom');
				break;

			case 'confirm:go':
				setStep('starting');
				await startTest();
				break;

			case 'waiting:tryit': {
				// Block the native anchor navigation so we can run TTL + purge
				// checks first, then reopen the tab if checks pass.
				if (event) event.preventDefault();
				const tryUrl = event && event.currentTarget && event.currentTarget.href;

				// TTL pre-check: don't let the merchant navigate with a dead session.
				try {
					await ajax(data.actions.ttlCheck);
				} catch (err) {
					setStep('error', { error: S.expired });
					return;
				}

				// Cache purge: if the merchant asked for it, purge is blocking.
				if (data.cachePlugin && state.cachePurge) {
					try {
						await ajax(data.actions.purge);
					} catch (err) {
						alert(err.message || S.genericError);
						return;
					}
				}

				if (tryUrl) window.open(tryUrl, '_blank', 'noopener');
				break;
			}

			case 'round:fixed':
				await sendRound('fixed');
				break;

			case 'round:broken':
				await sendRound('broken');
				break;

			case 'round:not_sure':
				await sendRound('not_sure');
				break;

			case 'abort':
			case 'resume:abort': {
				if (!confirm('Abort the test and restore everything?')) break;
				await ajax(data.actions.abort);
				localStorage.removeItem(STORAGE_KEY);
				setStep('restored');
				break;
			}

			case 'resume:continue':
				setStep('waiting', { session: data.session });
				break;

			case 'intermittent:continue':
				// Re-send the last answer with force=1 so the backend bypasses
				// the contradiction detector and proceeds with narrowing.
				await sendRound(state.lastAnswer || 'broken', { force: 1 });
				break;

			case 'culprit:copy': {
				const ta = root.querySelector('.wcd-diagnosis');
				if (ta) {
					ta.select();
					try { document.execCommand('copy'); } catch (e) { /* ignore */ }
					const btn = root.querySelector('[data-action="culprit:copy"]');
					if (btn) {
						const orig = btn.textContent;
						btn.textContent = S.copied;
						setTimeout(() => { btn.textContent = orig; }, 1500);
					}
				}
				break;
			}

			case 'culprit:done':
			case 'done':
				localStorage.removeItem(STORAGE_KEY);
				setStep('restored');
				break;

			case 'error:restart':
				localStorage.removeItem(STORAGE_KEY);
				state.symptom = '';
				state.suspects = [];
				state.theme = '';
				state.session = null;
				state.culprit = null;
				state.error = null;
				setStep('symptom');
				break;
		}
	}

	// --------------------------------------------------------------------
	// AJAX flows
	// --------------------------------------------------------------------
	async function startTest() {
		const payload = {
			symptom: state.symptom,
			mode: state.mode,
			theme: state.theme === '__keep__' ? '' : state.theme,
		};
		if (state.mode === 'focused') {
			payload.suspects = state.suspects;
		}
		const result = await ajax(data.actions.start, payload);
		if (result.status === 'resume_prompt') {
			setStep('resumePrompt', { session: result.session });
			return;
		}
		localStorage.setItem(STORAGE_KEY, result.token);
		setStep('waiting', { session: result.session });
	}

	async function sendRound(answer, extra) {
		state.lastAnswer = answer;
		setStep('updating');
		const payload = Object.assign({ answer: answer }, extra || {});
		const result = await ajax(data.actions.round, payload);

		if (result.status === 'culprit_found') {
			setStep('culprit', { culprit: result.culprit });
			return;
		}
		if (result.status === 'not_a_conflict') {
			setStep('notAConflict', { allowlistKept: result.allowlist_kept || [] });
			return;
		}
		if (result.status === 'intermittent') {
			setStep('intermittent', { session: result.session });
			return;
		}
		setStep('waiting', { session: result.session });
	}

	// --------------------------------------------------------------------
	// Boot
	// --------------------------------------------------------------------
	function init() {
		if (!data.canInstallMu) {
			setStep('managedHost');
			return;
		}
		if (data.session) {
			setStep('resumePrompt');
			return;
		}
		setStep('symptom');
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
