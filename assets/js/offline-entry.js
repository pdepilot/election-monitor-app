/*
 * Offline-first result entry for polling agents (results/entry).
 *
 * "Submit result" saves the figures on this device first and confirms only after the
 * IndexedDB transaction commits. Server receipt is shown only from a server response
 * (offline-sync.js). Drafts are kept on the device and are never sent as submissions.
 * Without storage, or without JavaScript, the classic form post is used as before.
 */
(function () {
    'use strict';

    var root = document.getElementById('em-offline');
    var form = document.querySelector('form[data-em-offline-form]');
    var store = window.EmOfflineStore;
    if (!root || !form || !store) {
        return;
    }

    var FIELDS = ['accredited_voters', 'rejected_ballots', 'declared_valid_votes', 'declared_total_votes'];
    var ctx = {
        userId: parseInt(root.getAttribute('data-user-id'), 10),
        unitId: parseInt(root.getAttribute('data-unit-id'), 10),
        unitCode: root.getAttribute('data-unit-code') || '',
        fingerprint: root.getAttribute('data-config-fingerprint') || '',
        candidates: JSON.parse(root.getAttribute('data-candidates') || '[]'),
        serverStatus: root.getAttribute('data-server-status') || 'none',
        canSubmit: root.getAttribute('data-can-submit') === '1'
    };
    var statusBox = root.querySelector('[data-em-status]');
    var recordsBox = root.querySelector('[data-em-records]');
    var draftNote = root.querySelector('[data-em-draft-note]');

    // The offline page shell has no CSRF token, so it never falls back to a classic form post.
    var classicAvailable = !form.hasAttribute('data-em-shell');
    var storeError = null;
    var busy = false;
    var locked = false;
    var bypass = false;
    var leaving = false;
    var autosaveTimer = null;
    var metrics = [];

    function input(name) {
        return form.elements.namedItem(name);
    }

    function readValues() {
        var values = { fields: {}, votes: {} };
        FIELDS.forEach(function (field) {
            var el = input(field);
            values.fields[field] = el ? el.value.trim() : '';
        });
        ctx.candidates.forEach(function (id) {
            var el = input('votes[' + id + ']');
            values.votes[id] = el ? el.value.trim() : '';
        });
        return values;
    }

    function writeValues(values) {
        FIELDS.forEach(function (field) {
            var el = input(field);
            if (el && values.fields && typeof values.fields[field] === 'string') {
                el.value = values.fields[field];
            }
        });
        ctx.candidates.forEach(function (id) {
            var el = input('votes[' + id + ']');
            if (el && values.votes && typeof values.votes[id] === 'string') {
                el.value = values.votes[id];
            }
        });
    }

    function key(values) {
        return JSON.stringify([FIELDS.map(function (f) {
            return values.fields[f] || '';
        }), ctx.candidates.map(function (id) {
            return values.votes[id] || '';
        })]);
    }

    var baseline = readValues();
    var persistedKey = key(baseline);

    function isDirty() {
        return ctx.canSubmit && !locked && key(readValues()) !== persistedKey;
    }

    function clock(ms) {
        return new Date(ms).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' });
    }

    function stamp(ms) {
        return new Date(ms).toLocaleString([], { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
    }

    function showMessage(kind, text, actions) {
        statusBox.textContent = '';
        if (!text) {
            return;
        }
        var p = document.createElement('p');
        p.className = 'pu-alert pu-alert--' + kind;
        p.setAttribute('role', kind === 'error' ? 'alert' : 'status');
        p.textContent = text;
        (actions || []).forEach(function (action) {
            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'cms-btn cms-btn--ghost cms-btn--sm em-offline__action';
            button.textContent = action.label;
            button.addEventListener('click', action.run);
            p.appendChild(document.createTextNode(' '));
            p.appendChild(button);
        });
        statusBox.appendChild(p);
    }

    function clearFieldErrors() {
        Array.prototype.forEach.call(form.querySelectorAll('[data-em-error]'), function (el) {
            el.remove();
        });
        Array.prototype.forEach.call(form.querySelectorAll('[aria-invalid="true"]'), function (el) {
            el.removeAttribute('aria-invalid');
        });
    }

    function fieldError(el, message) {
        if (!el) {
            return;
        }
        el.setAttribute('aria-invalid', 'true');
        var span = document.createElement('span');
        span.className = 'pu-error';
        span.setAttribute('data-em-error', '');
        span.textContent = message;
        el.insertAdjacentElement('afterend', span);
    }

    function count(raw) {
        if (!/^\d{1,6}$/.test(raw)) {
            return null;
        }
        var n = parseInt(raw, 10);
        return n <= 100000 ? n : null;
    }

    /* Mirrors result_validate() for a formal submission; the server re-checks everything. */
    function validate(values) {
        var errors = {};
        var figures = {};
        var votes = {};
        var sum = 0;
        FIELDS.forEach(function (field) {
            var n = count(values.fields[field]);
            if (n === null) {
                errors[field] = 'Enter a whole number from 0 to 100000.';
            }
            figures[field] = n;
        });
        ctx.candidates.forEach(function (id) {
            var n = count(values.votes[id]);
            if (n === null) {
                errors['votes[' + id + ']'] = 'Enter a whole-number vote count.';
            } else {
                votes[id] = n;
                sum += n;
            }
        });
        var problems = [];
        if (Object.keys(errors).length === 0) {
            var valid = figures.declared_valid_votes;
            var total = figures.declared_total_votes;
            var rejected = figures.rejected_ballots;
            var accredited = figures.accredited_voters;
            if (sum !== valid) {
                problems.push('Candidate votes add up to ' + sum + ', but the sheet total of valid votes is ' + valid + '.');
            }
            if (valid + rejected !== total) {
                problems.push('Valid votes (' + valid + ') plus rejected ballots (' + rejected + ') do not equal total votes cast (' + total + ').');
            }
            if (total > accredited) {
                problems.push('Total votes cast (' + total + ') cannot exceed accredited voters (' + accredited + ').');
            }
            if (sum + rejected > accredited) {
                problems.push('Candidate votes plus rejected ballots cannot exceed accredited voters.');
            }
        }
        return { errors: errors, problems: problems, figures: figures, votes: votes };
    }

    function actionButtons() {
        return Array.prototype.slice.call(form.querySelectorAll('button[name="intent"]'));
    }

    function setBusy(state) {
        busy = state;
        actionButtons().forEach(function (button) {
            button.disabled = state;
        });
        form.setAttribute('aria-busy', state ? 'true' : 'false');
    }

    function lockForm() {
        locked = true;
        Array.prototype.forEach.call(form.querySelectorAll('input:not([type="hidden"]), textarea'), function (el) {
            el.readOnly = true;
        });
        actionButtons().forEach(function (button) {
            button.hidden = true;
        });
        if (draftNote) {
            draftNote.textContent = '';
        }
    }

    var STATE_LABELS = {
        pending: ['Saved on this device · Pending sync', 'cms-badge--warn'],
        sending: ['Sending to server…', 'cms-badge--warn'],
        needs_attention: ['Needs attention', 'cms-badge--off'],
        superseded: ['Replaced by a newer submission', 'cms-badge--muted']
    };

    function stateLabel(record) {
        if (record.state === 'received') {
            if (record.server && record.server.state === 'accepted') {
                return ['Accepted after review', 'cms-badge--ok'];
            }
            return ['Received by server · Under review', 'cms-badge--ok'];
        }
        return STATE_LABELS[record.state] || [record.state, 'cms-badge--muted'];
    }

    function describeFigures(record) {
        var f = record.figures || {};
        return 'Accredited ' + f.accredited_voters + ' · Rejected ' + f.rejected_ballots
            + ' · Valid ' + f.declared_valid_votes + ' · Total cast ' + f.declared_total_votes;
    }

    function renderRecords(records) {
        recordsBox.textContent = '';
        records.filter(function (record) {
            return record.state !== 'superseded';
        }).slice(0, 3).forEach(function (record) {
            var label = stateLabel(record);
            var card = document.createElement('article');
            card.className = 'pu-record em-offline__record';
            card.setAttribute('data-submission-id', record.submission_id);
            card.setAttribute('data-state', record.state);
            var head = document.createElement('div');
            head.className = 'pu-record__id';
            var badge = document.createElement('span');
            badge.className = 'cms-badge ' + label[1];
            badge.textContent = label[0];
            var title = document.createElement('strong');
            title.textContent = 'Submission saved ' + stamp(record.created_ms);
            var figures = document.createElement('p');
            figures.textContent = describeFigures(record);
            head.appendChild(title);
            head.appendChild(figures);
            head.appendChild(badge);
            card.appendChild(head);
            var detail = document.createElement('p');
            detail.className = 'pu-note em-offline__detail';
            if (record.state === 'received' && record.server && record.server.received_at) {
                detail.textContent = 'Server receipt ' + stamp(Date.parse(record.server.received_at)) + '. Reference ' + record.submission_id.slice(0, 8) + '.';
            } else if (record.state === 'needs_attention' && record.last_error) {
                detail.textContent = record.last_error.message || 'The server did not accept this submission.';
            } else if (record.state === 'sending') {
                detail.textContent = 'Sending now. Not yet confirmed by the server. Reference ' + record.submission_id.slice(0, 8) + '.';
            } else if (record.state === 'pending' && record.last_error) {
                detail.textContent = 'Not yet received by the server. ' + record.last_error.message + ' Reference ' + record.submission_id.slice(0, 8) + '.';
            } else {
                detail.textContent = 'Not yet received by the server. Keep this device; it will be sent when a connection is available. Reference ' + record.submission_id.slice(0, 8) + '.';
            }
            card.appendChild(detail);
            recordsBox.appendChild(card);
        });
    }

    function refresh() {
        return store.listSubmissions(ctx.userId, ctx.unitId).then(function (records) {
            renderRecords(records);
            var active = records.some(function (record) {
                return store.ACTIVE_STATES.indexOf(record.state) !== -1;
            });
            if (active) {
                lockForm();
            }
            return records;
        });
    }

    function scheduleAutosave() {
        if (!ctx.canSubmit || locked || storeError) {
            return;
        }
        window.clearTimeout(autosaveTimer);
        autosaveTimer = window.setTimeout(function () {
            var values = readValues();
            var snapshot = key(values);
            if (snapshot === persistedKey) {
                return;
            }
            store.saveDraft({ user_id: ctx.userId, polling_unit_id: ctx.unitId, fields: values.fields, votes: values.votes }, false)
                .then(function (draft) {
                    persistedKey = snapshot;
                    if (draftNote) {
                        draftNote.textContent = 'Draft saved on this device at ' + clock(draft.saved_ms) + '. Drafts are not sent to the server.';
                    }
                })
                .catch(function (error) {
                    showMessage('error', 'Your draft could not be saved on this device: ' + error.message + ' Keep this page open.');
                });
        }, 400);
    }

    function submitClassic(submitter) {
        bypass = true;
        leaving = true;
        form.requestSubmit(submitter);
    }

    /* Without device storage, an online form post goes through the classic path untouched. */
    function passThrough(event) {
        if (storeError && navigator.onLine && !busy && classicAvailable) {
            leaving = true;
            return true;
        }
        event.preventDefault();
        event.stopImmediatePropagation();
        return false;
    }

    function handleDraft(event, submitter) {
        if (passThrough(event) || busy) {
            return;
        }
        var online = navigator.onLine && classicAvailable;
        if (storeError) {
            showMessage('error', 'You are offline and this browser cannot store a draft (' + storeError.message + ') Keep this page open until you are back online.');
            return;
        }
        var values = readValues();
        setBusy(true);
        store.saveDraft({ user_id: ctx.userId, polling_unit_id: ctx.unitId, fields: values.fields, votes: values.votes }, true)
            .then(function (draft) {
                persistedKey = key(values);
                if (online) {
                    showMessage('success', 'Draft saved on this device. Also saving the draft to the server…');
                    setBusy(false);
                    submitClassic(submitter);
                    return;
                }
                setBusy(false);
                showMessage('success', 'Draft saved on this device only, at ' + clock(draft.saved_ms) + '. A draft is not a submission and is not sent automatically.');
            })
            .catch(function (error) {
                setBusy(false);
                if (online) {
                    showMessage('warn', 'The draft could not be saved on this device (' + error.message + ') Saving it to the server instead…');
                    submitClassic(submitter);
                    return;
                }
                showMessage('error', 'Draft not saved: ' + error.message + ' Your figures are still on screen; keep this page open.');
            });
    }

    function handleSubmit(event, submitter) {
        var started = performance.now();
        if (passThrough(event) || busy || locked) {
            return;
        }
        if (storeError) {
            showMessage('error', 'Not saved: this browser cannot store results offline (' + storeError.message + ') Your figures are still on screen. Keep this page open and submit when you are back online.');
            return;
        }
        clearFieldErrors();
        var values = readValues();
        var checked = validate(values);
        var fieldNames = Object.keys(checked.errors);
        if (fieldNames.length || checked.problems.length) {
            fieldNames.forEach(function (name) {
                fieldError(input(name), checked.errors[name]);
            });
            showMessage('error', fieldNames.length
                ? 'Not saved. Every figure must be a whole number before the result can be submitted.'
                : 'Not saved. The figures do not add up. ' + checked.problems.join(' '));
            var first = fieldNames.length ? input(fieldNames[0]) : null;
            if (first) {
                first.focus();
            }
            return;
        }
        setBusy(true);
        store.queueSubmission({
            user_id: ctx.userId,
            polling_unit_id: ctx.unitId,
            unit_code: ctx.unitCode,
            config_fingerprint: ctx.fingerprint,
            figures: checked.figures,
            votes: checked.votes
        }).then(function (record) {
            var saved = performance.now();
            persistedKey = key(values);
            lockForm();
            renderRecords([record]);
            showMessage('success', 'Saved on this device. It has not reached the server yet; it will be sent automatically when a connection is available.');
            setBusy(false);
            window.requestAnimationFrame(function () {
                var painted = performance.now();
                var sample = { submission_id: record.submission_id, local_save_ms: +(saved - started).toFixed(1), ui_ms: +(painted - started).toFixed(1) };
                metrics.push(sample);
                root.setAttribute('data-local-save-ms', String(sample.local_save_ms));
                root.setAttribute('data-ui-ms', String(sample.ui_ms));
            });
            store.requestPersistence();
            store.notify({ type: 'queued', user_id: ctx.userId, polling_unit_id: ctx.unitId, submission_id: record.submission_id });
            refresh().catch(function () {
                // The saved record is already shown; a failed re-read changes nothing.
            });
        }).catch(function (error) {
            setBusy(false);
            if (error.kind === 'duplicate') {
                refresh();
                showMessage('warn', 'A result for this polling unit is already saved on this device, so it was not saved twice.');
                return;
            }
            showMessage('error', 'Not saved: ' + error.message + ' Your figures are still on screen.');
        });
    }

    document.addEventListener('submit', function (event) {
        if (event.target !== form) {
            return;
        }
        if (bypass) {
            bypass = false;
            return;
        }
        var submitter = event.submitter;
        var intent = submitter ? submitter.value : '';
        if (intent === 'save') {
            handleDraft(event, submitter);
        } else if (intent === 'submit') {
            handleSubmit(event, submitter);
        }
    }, true);

    form.addEventListener('input', function () {
        if (draftNote && isDirty()) {
            draftNote.textContent = 'Unsaved changes…';
        }
        scheduleAutosave();
    });

    window.addEventListener('beforeunload', function (event) {
        if (leaving || !(busy || isDirty())) {
            return;
        }
        event.preventDefault();
        event.returnValue = '';
    });

    store.onChange(function (detail) {
        if (!detail || detail.polling_unit_id === undefined || detail.polling_unit_id === ctx.unitId) {
            refresh().catch(function () {
                // Storage errors are reported by the action that caused them.
            });
        }
    });

    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'visible' && !storeError) {
            refresh().catch(function () {
                // Shown on the next action.
            });
        }
    });

    /* Server outcomes for this unit, reported by offline-sync.js. */
    store.onChange(function (detail) {
        if (!detail || detail.polling_unit_id !== ctx.unitId || (detail.type !== 'received' && detail.type !== 'attention')) {
            return;
        }
        store.listSubmissions(ctx.userId, ctx.unitId).then(function (records) {
            var record = records.filter(function (r) {
                return r.submission_id === detail.submission_id;
            })[0];
            if (!record) {
                return;
            }
            if (record.state === 'received' && record.server) {
                showMessage('success', 'Received by the server at ' + stamp(Date.parse(record.server.received_at)) + '. It now awaits review. ' + (root.querySelector('.em-offline__notice') || {}).textContent);
            } else if (record.state === 'needs_attention' && record.last_error) {
                showMessage('error', 'Not accepted by the server: ' + record.last_error.message + ' The result is still saved on this device.');
            }
        }).catch(function () {
            // The sync panel shows the same outcome.
        });
    });

    function unlockForm() {
        locked = false;
        Array.prototype.forEach.call(form.querySelectorAll('input:not([type="hidden"]), textarea'), function (el) {
            el.readOnly = false;
        });
        actionButtons().forEach(function (button) {
            button.hidden = false;
            button.disabled = false;
        });
    }

    /* Prefills the form from a submission that needs attention; the new one supersedes it on save. */
    function startResubmit(submissionId) {
        return store.listSubmissions(ctx.userId, ctx.unitId).then(function (records) {
            var record = records.filter(function (r) {
                return r.submission_id === submissionId;
            })[0];
            var active = records.some(function (r) {
                return store.ACTIVE_STATES.indexOf(r.state) !== -1;
            });
            if (!record || record.state !== 'needs_attention' || active || !ctx.canSubmit) {
                showMessage('warn', 'That submission cannot be edited here. Only a submission that needs attention, for an editable result, can be resubmitted.');
                return;
            }
            var values = { fields: {}, votes: {} };
            FIELDS.forEach(function (field) {
                values.fields[field] = record.figures && record.figures[field] != null ? String(record.figures[field]) : '';
            });
            ctx.candidates.forEach(function (id) {
                values.votes[id] = record.votes && record.votes[id] != null ? String(record.votes[id]) : '';
            });
            unlockForm();
            writeValues(values);
            persistedKey = key(readValues());
            showMessage('warn', 'Correct the figures and submit again. The earlier submission stays on this device and is marked as replaced once the new one is saved.');
            var first = input(FIELDS[0]);
            if (first) {
                first.focus();
            }
        });
    }

    document.addEventListener('click', function (event) {
        var link = event.target && event.target.closest ? event.target.closest('[data-em-edit]') : null;
        if (!link || !/[?&]unit=(\d+)/.test(link.getAttribute('href') || '') || parseInt(RegExp.$1, 10) !== ctx.unitId) {
            return;
        }
        event.preventDefault();
        startResubmit(link.getAttribute('data-em-edit'));
    });

    function restoreDraft(draft) {
        writeValues(draft);
        persistedKey = key(readValues());
        if (draftNote) {
            draftNote.textContent = 'Restored the draft saved on this device at ' + stamp(draft.saved_ms) + '.';
        }
    }

    root.hidden = false;
    store.open()
        .then(function () {
            return Promise.all([refresh(), store.getDraft(ctx.userId, ctx.unitId)]);
        })
        .then(function (loaded) {
            var draft = loaded[1];
            var resubmit = /^#resubmit-([0-9a-f-]{36})$/.exec(window.location.hash);
            if (resubmit) {
                return startResubmit(resubmit[1]);
            }
            if (!draft || locked) {
                return;
            }
            if (!ctx.canSubmit || ctx.serverStatus === 'submitted') {
                return store.deleteDraft(ctx.userId, ctx.unitId);
            }
            if (key(draft) === key(baseline)) {
                return;
            }
            if (ctx.serverStatus === 'none') {
                restoreDraft(draft);
                return;
            }
            showMessage('warn', 'A draft saved on this device at ' + stamp(draft.saved_ms) + ' differs from the draft on the server (shown below).', [
                { label: 'Use device draft', run: function () { restoreDraft(draft); showMessage('', ''); } },
                { label: 'Discard device draft', run: function () {
                    store.deleteDraft(ctx.userId, ctx.unitId).then(function () {
                        showMessage('success', 'Device draft discarded. The server draft is shown.');
                    }).catch(function (error) {
                        showMessage('error', 'The device draft could not be discarded: ' + error.message);
                    });
                } }
            ]);
        })
        .catch(function (error) {
            storeError = error;
            root.setAttribute('data-store-error', error.kind || 'unknown');
            showMessage('warn', 'Offline saving is unavailable in this browser: ' + error.message
                + (navigator.onLine ? ' Results are sent directly while you are online.' : ' Keep this page open and submit once you are back online.'));
        });

    window.EmOfflineEntry = {
        metrics: metrics,
        refresh: refresh,
        startResubmit: startResubmit,
        validate: function () {
            return validate(readValues());
        }
    };
}());
