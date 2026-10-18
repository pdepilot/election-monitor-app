/*
 * Sends results queued on this device to results/api-submit and shows their status.
 *
 * - One tab sends at a time (Web Locks, or an IndexedDB lease where locks are unavailable).
 * - Each item is sent with its original submission ID; a failed or lost request is retried with
 *   the same ID, so the server replays its first answer instead of recording a duplicate.
 * - "Received by server" is recorded only from a valid server acknowledgement. Review states
 *   (under review / accepted) come only from the server.
 * - Temporary failures back off exponentially with jitter and honour Retry-After. Refusals that
 *   retrying cannot fix become "needs attention" and are kept; nothing is deleted.
 * - The CSRF token lives in page memory only and is refreshed from results/api-status.
 */
(function () {
    'use strict';

    var store = window.EmOfflineStore;
    var root = document.getElementById('em-sync');
    if (!store || !root) {
        return;
    }

    var userId = parseInt(root.getAttribute('data-user-id'), 10);
    var config = {
        submitUrl: root.getAttribute('data-submit-url'),
        statusUrl: root.getAttribute('data-status-url'),
        entryUrl: root.getAttribute('data-entry-url'),
        timeoutMs: 20000,
        baseDelayMs: 2000,
        maxDelayMs: 300000,
        statusMinGapMs: 10000
    };
    var tabId = store.newSubmissionId();
    var tokenInput = document.querySelector('input[name="csrf_token"]');
    var csrf = tokenInput ? tokenInput.value : '';

    var state = {
        running: null,
        rerun: false,
        rerunManual: false,
        authRequired: false,
        wrongUser: false,
        reachable: null,
        otherTab: false,
        lastSyncMs: null,
        lastStatusMs: 0,
        timer: null,
        message: null,
        records: []
    };
    var metrics = [];

    var NO_RETRY = ['submission_id_reused', 'submission_id_conflict', 'unsupported_schema', 'candidates_changed'];
    var ATTENTION_HINTS = {
        unit_not_assigned: 'Contact your coordinator. Once the assignment is restored, use Retry.',
        unit_inactive: 'Contact your coordinator. Once the polling unit is active again, use Retry.',
        already_submitted: 'The server already holds a submitted result for this unit and was not changed.',
        candidates_changed: 'Reload the page while online, then use "Edit & resubmit" to enter the result for the current candidates.',
        validation_failed: 'Use "Edit & resubmit" to correct the figures.',
        submission_id_reused: 'The server kept the original figures. Use "Edit & resubmit" to send a new submission.',
        submission_id_conflict: 'Use "Edit & resubmit" to send a new submission.',
        unsupported_schema: 'Reload the page while online to update the app.',
        role_forbidden: 'Sign in with your polling-agent account.'
    };

    var el = {
        conn: root.querySelector('[data-em-conn]'),
        pending: root.querySelector('[data-em-pending]'),
        attention: root.querySelector('[data-em-attention]'),
        last: root.querySelector('[data-em-last]'),
        button: root.querySelector('[data-em-sync-now]'),
        msg: root.querySelector('[data-em-sync-msg]'),
        list: root.querySelector('[data-em-list]'),
        total: root.querySelector('[data-em-total]')
    };

    function stamp(ms) {
        return new Date(ms).toLocaleString([], { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit', second: '2-digit' });
    }

    function backoff(attempts) {
        var base = Math.min(config.maxDelayMs, config.baseDelayMs * Math.pow(2, Math.max(0, attempts - 1)));
        return Math.round(base * (0.5 + Math.random() * 0.5));
    }

    function parseRetryAfter(value) {
        if (!value) {
            return null;
        }
        if (/^\d+$/.test(value.trim())) {
            return parseInt(value, 10) * 1000;
        }
        var at = Date.parse(value);
        return isNaN(at) ? null : Math.max(0, at - Date.now());
    }

    function setMessage(kind, text) {
        state.message = text ? { kind: kind, text: text } : null;
    }

    /* ---------- server calls ---------- */

    function request(url, options) {
        var controller = typeof AbortController === 'function' ? new AbortController() : null;
        var timer = controller ? window.setTimeout(function () {
            controller.abort();
        }, config.timeoutMs) : null;
        options.credentials = 'same-origin';
        options.cache = 'no-store';
        options.redirect = 'error';
        if (controller) {
            options.signal = controller.signal;
        }
        return fetch(url, options).then(function (response) {
            return response.text().then(function (text) {
                var json = null;
                try {
                    json = text ? JSON.parse(text) : null;
                } catch (ignored) {
                    json = null;
                }
                return { status: response.status, json: json, retryAfterMs: parseRetryAfter(response.headers.get('Retry-After')) };
            });
        }).then(function (result) {
            window.clearTimeout(timer);
            return result;
        }, function (error) {
            window.clearTimeout(timer);
            return { status: 0, json: null, error: error && error.name === 'AbortError' ? 'timeout' : 'network' };
        });
    }

    function payloadOf(record) {
        return JSON.stringify({
            schema_version: record.schema_version,
            submission_id: record.submission_id,
            polling_unit_id: record.polling_unit_id,
            client_created_at: record.client_created_at,
            config_fingerprint: record.config_fingerprint,
            figures: record.figures,
            votes: record.votes
        });
    }

    function classify(response, record) {
        var json = response.json;
        var code = json && typeof json.code === 'string' ? json.code : '';
        var serverMessage = json && typeof json.message === 'string' ? json.message : '';
        if ((response.status === 201 || response.status === 200) && json && json.ok === true && code === 'received'
            && json.submission_id === record.submission_id && typeof json.received_at === 'string') {
            return { kind: 'received', json: json };
        }
        if (response.status === 0) {
            return {
                kind: 'retry',
                code: response.error,
                network: true,
                message: response.error === 'timeout'
                    ? 'The server did not answer in time. The result may already have arrived; it will be checked again with the same submission ID.'
                    : 'Could not reach the server. The result stays on this device and will be retried automatically.'
            };
        }
        if (response.status === 401) {
            return { kind: 'auth', code: 'auth_required', message: serverMessage || 'Your session has ended. Sign in again to send queued results.' };
        }
        if (response.status === 403 && code === 'csrf_invalid') {
            return { kind: 'csrf', code: code, message: serverMessage };
        }
        if (response.status === 429) {
            return { kind: 'retry', code: 'rate_limited', retryAfterMs: response.retryAfterMs, message: 'The server asked this device to slow down. It will retry automatically.' };
        }
        if (response.status >= 500 || response.status === 408) {
            return { kind: 'retry', code: code || 'server_error', retryAfterMs: response.retryAfterMs, message: serverMessage || 'The server had a temporary problem. The result stays on this device and will be retried automatically.' };
        }
        if (response.status >= 400 && response.status < 500 && code) {
            return { kind: 'attention', code: code, message: serverMessage || 'The server did not accept this submission.', fields: json.fields || null };
        }
        return { kind: 'retry', code: 'unexpected_response', message: 'The server gave an unexpected answer (HTTP ' + response.status + '). It will be retried with the same submission ID.' };
    }

    function refreshStatus(force) {
        if (!force && Date.now() - state.lastStatusMs < config.statusMinGapMs && !state.authRequired) {
            return Promise.resolve('skipped');
        }
        state.lastStatusMs = Date.now();
        return store.listSubmissions(userId).then(function (records) {
            var ids = records.filter(function (r) {
                return r.state === 'received';
            }).slice(0, 50).map(function (r) {
                return r.submission_id;
            });
            return request(config.statusUrl + (ids.length ? '?ids=' + ids.join(',') : ''), { method: 'GET', headers: { Accept: 'application/json' } });
        }).then(function (response) {
            if (response.status === 401) {
                state.authRequired = true;
                state.reachable = true;
                return 'auth';
            }
            if (response.status !== 200 || !response.json || response.json.ok !== true) {
                if (response.status === 0) {
                    state.reachable = false;
                }
                return 'unavailable';
            }
            state.reachable = true;
            if (response.json.user_id !== userId) {
                state.wrongUser = true;
                state.authRequired = true;
                return 'auth';
            }
            state.wrongUser = false;
            state.authRequired = false;
            if (typeof response.json.csrf_token === 'string') {
                csrf = response.json.csrf_token;
            }
            saveUnitCopy(response.json.units);
            var server = response.json.submissions || {};
            return store.updateAll(userId, function (record) {
                var current = server[record.submission_id];
                if (record.state !== 'received' || !current || !record.server) {
                    return false;
                }
                if (record.server.state === current.state && record.server.result_status === current.result_status && !!record.server.figures_changed === !!current.figures_changed) {
                    return false;
                }
                record.server.state = current.state;
                record.server.result_status = current.result_status;
                record.server.figures_changed = !!current.figures_changed;
                record.server.checked_ms = Date.now();
            }).then(function (changed) {
                if (changed.length) {
                    store.notify({ type: 'status' });
                }
                return 'ok';
            });
        });
    }

    /*
     * Device copy of this agent's units and candidates for the offline page shell. Only what the
     * entry form needs; no token. Replaced on every successful status check, removed on sign-out.
     */
    function saveUnitCopy(units) {
        if (!Array.isArray(units)) {
            return;
        }
        var copy = units.map(function (unit) {
            return {
                id: unit.id,
                code: unit.code,
                name: unit.name,
                ward: unit.ward,
                result_status: unit.result_status,
                config_fingerprint: unit.config_fingerprint,
                candidates: (unit.candidates || []).map(function (c) {
                    return { id: c.id, party: c.party, name: c.name };
                })
            };
        });
        copyWrite = store.getMeta('agent').then(function (previous) {
            if (signingOut) {
                return false;
            }
            var clearOld = previous && previous.user_id !== userId ? store.deleteMeta('units:' + previous.user_id) : null;
            return Promise.resolve(clearOld).then(function () {
                return signingOut ? null : store.setMeta('units:' + userId, { saved_ms: Date.now(), units: copy });
            }).then(function () {
                return signingOut ? false : store.setMeta('agent', { user_id: userId, saved_ms: Date.now() });
            });
        }).then(function (written) {
            if (written !== false) {
                store.notify({ type: 'unit_copy' });
            }
        }).catch(function () {
            // Offline reopening reports itself as not ready; online entry is unaffected.
        });
    }

    // Logout meta cleanup lives in offline-logout.js (loaded on every agent shell page).
    var signingOut = false;
    var copyWrite = Promise.resolve();
    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!form || !/\/portal\/logout(\.php)?$/.test(new URL(form.action, window.location.href).pathname)) {
            return;
        }
        signingOut = true;
    }, true);

    /* ---------- one item ---------- */

    function apply(record, outcome, elapsedMs) {
        var now = Date.now();
        return store.updateSubmission(record.submission_id, function (r) {
            if (r.state !== 'sending' || r.sending_owner !== tabId) {
                return false;
            }
            r.sending_owner = null;
            if (outcome.kind === 'received') {
                var json = outcome.json;
                var current = json.current || {};
                r.state = 'received';
                r.last_error = null;
                r.next_attempt_ms = 0;
                r.acked_ms = now;
                r.server = {
                    received_at: json.received_at,
                    state: current.state || json.state,
                    result_status: current.result_status || json.result_status,
                    figures_changed: !!current.figures_changed,
                    replayed: !!json.replayed,
                    message: json.message
                };
                return;
            }
            r.last_error = { code: outcome.code, message: outcome.message, fields: outcome.fields || null, at_ms: now };
            if (outcome.kind === 'attention') {
                r.state = 'needs_attention';
                r.next_attempt_ms = 0;
                return;
            }
            r.state = 'pending';
            r.next_attempt_ms = outcome.kind === 'auth' ? 0 : now + (outcome.retryAfterMs != null ? outcome.retryAfterMs + Math.round(Math.random() * 500) : backoff(r.attempts));
        }).then(function (saved) {
            if (outcome.kind === 'received') {
                metrics.push({ submission_id: record.submission_id, ack_ms: +elapsedMs.toFixed(1), replayed: !!outcome.json.replayed, attempts: saved ? saved.attempts : null });
                state.lastSyncMs = now;
                state.reachable = true;
                store.setMeta('last_sync_ms:' + userId, now).catch(function () {
                    // The receipt itself is already stored on the record.
                });
            } else if (outcome.kind === 'auth') {
                state.authRequired = true;
                state.reachable = true;
            } else if (outcome.kind === 'attention') {
                state.reachable = true;
            } else {
                state.reachable = outcome.network ? false : true;
            }
            store.notify({ type: outcome.kind === 'received' ? 'received' : (outcome.kind === 'attention' ? 'attention' : 'retry'), polling_unit_id: record.polling_unit_id, submission_id: record.submission_id });
            return outcome.kind === 'received' || outcome.kind === 'attention' ? 'continue' : 'stop';
        });
    }

    function sendOne(record) {
        return store.updateSubmission(record.submission_id, function (r) {
            if (r.state !== 'pending') {
                return false;
            }
            r.state = 'sending';
            r.sending_owner = tabId;
            r.attempts = (r.attempts || 0) + 1;
            r.last_attempt_ms = Date.now();
        }).then(function (claimed) {
            if (!claimed || claimed.state !== 'sending' || claimed.sending_owner !== tabId) {
                return 'continue';
            }
            store.notify({ type: 'sending', polling_unit_id: claimed.polling_unit_id, submission_id: claimed.submission_id });
            var started = performance.now();
            var send = function () {
                return request(config.submitUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': csrf },
                    body: payloadOf(claimed)
                });
            };
            return send().then(function (response) {
                var outcome = classify(response, claimed);
                if (outcome.kind !== 'csrf') {
                    return outcome;
                }
                return refreshStatus(true).then(function (status) {
                    if (status === 'auth') {
                        return { kind: 'auth', code: 'auth_required', message: 'Your session has ended. Sign in again to send queued results.' };
                    }
                    return send().then(function (second) {
                        var retried = classify(second, claimed);
                        return retried.kind === 'csrf'
                            ? { kind: 'retry', code: 'csrf_invalid', message: 'The security check failed. The device will try again shortly.' }
                            : retried;
                    });
                });
            }).then(function (outcome) {
                return apply(claimed, outcome, performance.now() - started);
            });
        });
    }

    /* ---------- the worker ---------- */

    function dueItems(records) {
        var now = Date.now();
        return records.filter(function (r) {
            return r.state === 'pending' && (r.next_attempt_ms || 0) <= now;
        }).sort(function (a, b) {
            return a.created_ms - b.created_ms;
        });
    }

    function drain() {
        if (state.authRequired || navigator.onLine === false) {
            return Promise.resolve();
        }
        return store.listSubmissions(userId).then(function (records) {
            var due = dueItems(records);
            if (!due.length) {
                return null;
            }
            return sendOne(due[0]).then(function (next) {
                return next === 'stop' ? null : drain();
            });
        });
    }

    function runOnce(options) {
        return store.resetAbandoned(userId).then(function () {
            return options.manual ? store.clearBackoff(userId) : null;
        }).then(function () {
            if (navigator.onLine === false) {
                state.reachable = null;
                return null;
            }
            var needStatus = options.manual || options.status || state.authRequired || !csrf;
            return (needStatus ? refreshStatus(options.manual) : Promise.resolve('skipped')).then(function () {
                return drain();
            }).then(function () {
                return refreshStatus(false);
            });
        });
    }

    function withLock(work) {
        if (navigator.locks && typeof navigator.locks.request === 'function') {
            return navigator.locks.request('em-offline-sync-' + userId, { ifAvailable: true }, function (lock) {
                if (!lock) {
                    state.otherTab = true;
                    return null;
                }
                state.otherTab = false;
                return work();
            });
        }
        return store.acquireLease('sync-' + userId, tabId, 60000).then(function (acquired) {
            if (!acquired) {
                state.otherTab = true;
                return null;
            }
            state.otherTab = false;
            return work().then(function (value) {
                return store.releaseLease('sync-' + userId, tabId).then(function () {
                    return value;
                });
            }, function (error) {
                return store.releaseLease('sync-' + userId, tabId).then(function () {
                    throw error;
                });
            });
        });
    }

    function sync(reason, options) {
        options = options || {};
        if (state.running) {
            state.rerun = true;
            state.rerunManual = state.rerunManual || !!options.manual;
            return state.running;
        }
        window.clearTimeout(state.timer);
        if (options.manual) {
            setMessage('', '');
        }
        state.running = withLock(function () {
            return runOnce(options);
        }).catch(function (error) {
            setMessage('error', 'Synchronisation stopped: ' + (error && error.message ? error.message : 'unexpected error') + ' Results stay on this device.');
        }).then(function () {
            state.running = null;
            var again = state.rerun;
            var manual = state.rerunManual;
            state.rerun = false;
            state.rerunManual = false;
            return render().then(function () {
                if (again) {
                    return sync('rerun', { manual: manual });
                }
                schedule();
                return null;
            });
        });
        render();
        return state.running;
    }

    function schedule() {
        window.clearTimeout(state.timer);
        if (state.authRequired) {
            return;
        }
        var pending = state.records.filter(function (r) {
            return r.state === 'pending' || r.state === 'sending';
        });
        if (!pending.length) {
            return;
        }
        var next = Math.min.apply(null, pending.map(function (r) {
            return r.state === 'sending' ? Date.now() + 15000 : (r.next_attempt_ms || 0);
        }));
        var wait = Math.max(state.otherTab ? 5000 : 500, next - Date.now());
        state.timer = window.setTimeout(function () {
            sync('timer');
        }, Math.min(wait, config.maxDelayMs));
    }

    /* ---------- interface ---------- */

    var LABELS = {
        pending: ['Pending sync', 'cms-badge--warn'],
        sending: ['Sending…', 'cms-badge--warn'],
        needs_attention: ['Needs attention', 'cms-badge--off'],
        superseded: ['Replaced by a newer submission', 'cms-badge--muted']
    };

    function label(record) {
        if (record.state === 'received') {
            if (record.server && record.server.state === 'accepted') {
                return ['Accepted', 'cms-badge--ok'];
            }
            return ['Received by server · Under review', 'cms-badge--ok'];
        }
        if (record.state === 'pending' && !record.attempts) {
            return ['Saved on device · Pending sync', 'cms-badge--warn'];
        }
        return LABELS[record.state] || [record.state, 'cms-badge--muted'];
    }

    function button(text, onClick) {
        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'cms-btn cms-btn--ghost cms-btn--sm';
        b.textContent = text;
        b.addEventListener('click', onClick);
        return b;
    }

    function detailText(record) {
        if (record.state === 'received' && record.server) {
            var text = 'Server receipt ' + stamp(Date.parse(record.server.received_at)) + '.';
            if (record.server.figures_changed) {
                text += ' The figures were later corrected on the server.';
            }
            return text;
        }
        if (record.state === 'needs_attention' && record.last_error) {
            return record.last_error.message + (ATTENTION_HINTS[record.last_error.code] ? ' ' + ATTENTION_HINTS[record.last_error.code] : '');
        }
        if (record.state === 'pending' && record.last_error) {
            if (record.last_error.code === 'auth_required') {
                return 'Waiting for you to sign in again. Not yet received by the server.';
            }
            var next = record.next_attempt_ms && record.next_attempt_ms > Date.now() ? ' Next try about ' + stamp(record.next_attempt_ms) + '.' : '';
            return record.last_error.message + next + ' Not yet received by the server.';
        }
        if (record.state === 'superseded') {
            return 'Kept for your records; a newer submission replaced it.';
        }
        return 'Not yet received by the server.';
    }

    function renderList(records) {
        el.list.textContent = '';
        records.forEach(function (record) {
            var item = document.createElement('li');
            item.className = 'em-sync__item';
            item.setAttribute('data-submission-id', record.submission_id);
            item.setAttribute('data-state', record.state);
            var tag = label(record);
            var head = document.createElement('div');
            head.className = 'em-sync__item-head';
            var unit = document.createElement('strong');
            unit.textContent = record.unit_code || ('Unit ' + record.polling_unit_id);
            var when = document.createElement('span');
            when.className = 'em-sync__when';
            when.textContent = 'Saved ' + stamp(record.created_ms);
            var badge = document.createElement('span');
            badge.className = 'cms-badge ' + tag[1];
            badge.textContent = tag[0];
            head.appendChild(unit);
            head.appendChild(when);
            head.appendChild(badge);
            item.appendChild(head);
            var detail = document.createElement('p');
            detail.className = 'em-sync__detail';
            detail.textContent = detailText(record);
            item.appendChild(detail);
            var actions = document.createElement('div');
            actions.className = 'em-sync__actions';
            if (record.state === 'needs_attention') {
                var code = record.last_error ? record.last_error.code : '';
                if (NO_RETRY.indexOf(code) === -1) {
                    actions.appendChild(button('Retry', function () {
                        store.retrySubmission(record.submission_id).then(function () {
                            store.notify({ type: 'retry', polling_unit_id: record.polling_unit_id });
                            sync('retry', { manual: true });
                        });
                    }));
                }
                var edit = document.createElement('a');
                edit.className = 'cms-btn cms-btn--ghost cms-btn--sm';
                edit.href = config.entryUrl + '?unit=' + encodeURIComponent(record.polling_unit_id) + '#resubmit-' + record.submission_id;
                edit.textContent = 'Edit & resubmit';
                edit.setAttribute('data-em-edit', record.submission_id);
                actions.appendChild(edit);
            } else if (record.state === 'pending' && record.last_error && record.last_error.code !== 'auth_required') {
                actions.appendChild(button('Retry now', function () {
                    sync('manual', { manual: true });
                }));
            }
            if (actions.childNodes.length) {
                item.appendChild(actions);
            }
            el.list.appendChild(item);
        });
    }

    function connectionLabel() {
        if (navigator.onLine === false) {
            return ['Offline', 'offline'];
        }
        if (state.wrongUser) {
            return ['Signed in as a different account', 'attention'];
        }
        if (state.authRequired) {
            return ['Sign in again to send', 'attention'];
        }
        if (state.reachable === false) {
            return ['Online · server unreachable', 'offline'];
        }
        if (state.reachable === true) {
            return ['Online · server reachable', 'online'];
        }
        return ['Online', 'online'];
    }

    function render() {
        return store.listSubmissions(userId).then(function (records) {
            state.records = records;
            var visible = records.filter(function (r) {
                return r.user_id === userId;
            });
            var pending = visible.filter(function (r) {
                return r.state === 'pending' || r.state === 'sending';
            }).length;
            var attention = visible.filter(function (r) {
                return r.state === 'needs_attention';
            }).length;
            var conn = connectionLabel();
            el.conn.textContent = conn[0];
            el.conn.setAttribute('data-conn', conn[1]);
            el.pending.textContent = String(pending);
            el.attention.textContent = String(attention);
            el.total.textContent = String(visible.length);
            el.last.textContent = state.lastSyncMs ? 'Last server sync ' + stamp(state.lastSyncMs) : 'No server sync yet on this device';
            el.button.disabled = !!state.running;
            el.button.textContent = state.running ? 'Syncing…' : 'Sync now';
            root.setAttribute('aria-busy', state.running ? 'true' : 'false');
            var message = state.message;
            if (!message && state.authRequired) {
                message = { kind: 'warn', text: state.wrongUser
                    ? 'This device holds results for another account. Sign in as that agent to send them.'
                    : 'Your session has ended. Results stay on this device. Sign in again and they will be sent with their original submission IDs.' };
            } else if (!message && state.otherTab && pending) {
                message = { kind: 'note', text: 'Another open tab of this portal is sending results.' };
            }
            el.msg.textContent = message ? message.text : '';
            el.msg.className = 'em-sync__msg' + (message ? ' em-sync__msg--' + message.kind : '');
            renderList(visible);
            return records;
        }).catch(function (error) {
            el.msg.textContent = 'Results on this device could not be read: ' + (error && error.message ? error.message : 'storage error');
            el.msg.className = 'em-sync__msg em-sync__msg--error';
            return [];
        });
    }

    el.button.addEventListener('click', function () {
        sync('manual', { manual: true });
    });

    store.onChange(function (detail) {
        if (detail && detail.type === 'queued' && detail.user_id === userId) {
            sync('queued');
            return;
        }
        render();
    });
    window.addEventListener('online', function () {
        render();
        sync('online', { status: true });
    });
    window.addEventListener('offline', function () {
        state.reachable = null;
        render();
    });
    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'visible') {
            sync('visible', { status: true });
        }
    });
    window.addEventListener('pageshow', function (event) {
        if (event.persisted) {
            sync('pageshow', { status: true });
        }
    });
    window.addEventListener('focus', function () {
        sync('focus');
    });

    root.hidden = false;
    store.open().then(function () {
        return store.getMeta('last_sync_ms:' + userId);
    }).then(function (last) {
        state.lastSyncMs = last;
        return sync('load', { status: true });
    }).catch(function (error) {
        el.msg.textContent = 'Offline results cannot be read in this browser: ' + (error && error.message ? error.message : 'storage error');
        el.msg.className = 'em-sync__msg em-sync__msg--error';
    });

    window.EmOfflineSync = {
        sync: sync,
        metrics: metrics,
        config: config,
        state: state
    };
}());
