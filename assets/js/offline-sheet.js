/*
 * Offline-capable "Submit result sheet only" for polling agents.
 * Queues the selected file in IndexedDB without creating a numerical result submission.
 * Server receipt is shown only after offline-sync.js receives a matching acknowledgement.
 */
(function () {
    'use strict';

    var root = document.getElementById('em-sheet-only');
    var form = document.querySelector('form[data-em-sheet-form]');
    var store = window.EmOfflineStore;
    if (!root || !form || !store) {
        return;
    }

    var ctx = {
        userId: parseInt(root.getAttribute('data-user-id'), 10),
        unitId: parseInt(root.getAttribute('data-unit-id'), 10),
        unitCode: root.getAttribute('data-unit-code') || '',
        hasVersions: root.getAttribute('data-has-versions') === '1',
        maxBytes: parseInt(root.getAttribute('data-max-bytes'), 10) || (8 * 1024 * 1024)
    };
    var statusBox = root.querySelector('[data-em-sheet-status]');
    var recordsBox = root.querySelector('[data-em-sheet-records]');
    var fileInput = form.querySelector('input[name="result_sheet"]');
    var busy = false;
    var classicAvailable = true;

    var ALLOWED = {
        'image/jpeg': true,
        'image/png': true,
        'image/webp': true,
        'application/pdf': true
    };

    function setStatus(kind, text) {
        if (!statusBox) {
            return;
        }
        statusBox.textContent = text || '';
        statusBox.className = 'pu-note' + (kind === 'error' ? ' pu-alert pu-alert--error' : (kind === 'ok' ? ' pu-alert pu-alert--success' : ''));
        if (kind === 'error') {
            statusBox.setAttribute('role', 'alert');
        } else {
            statusBox.setAttribute('role', 'status');
        }
    }

    function validateFile(file) {
        if (!file) {
            return 'Choose the result sheet photo or PDF.';
        }
        if (file.size < 1) {
            return 'That file is empty.';
        }
        if (file.size > ctx.maxBytes) {
            return 'That file is larger than the upload limit (' + Math.floor(ctx.maxBytes / 1048576) + ' MB).';
        }
        if (file.type && !ALLOWED[file.type]) {
            return 'Upload a JPEG, PNG, WebP, or PDF result sheet.';
        }
        if (ctx.hasVersions) {
            var kind = form.elements.namedItem('upload_kind');
            var reason = form.elements.namedItem('upload_reason');
            if (!kind || !kind.value) {
                return 'Choose whether this upload replaces or supplements the earlier version.';
            }
            var text = reason && reason.value ? reason.value.trim() : '';
            if (text.length < 5 || text.length > 255) {
                return 'Explain in 5 to 255 characters why this version is being added.';
            }
        }
        return '';
    }

    function renderRecords() {
        if (!recordsBox) {
            return Promise.resolve();
        }
        return store.listSheetUploads(ctx.userId, ctx.unitId).then(function (rows) {
            recordsBox.textContent = '';
            rows.slice(0, 8).forEach(function (row) {
                var article = document.createElement('article');
                article.className = 'pu-record';
                var id = document.createElement('div');
                id.className = 'pu-record__id';
                var strong = document.createElement('strong');
                strong.textContent = row.original_name || 'Result sheet';
                var p = document.createElement('p');
                var stateLabel = row.state === 'received'
                    ? 'Received by server · pending review'
                    : (row.state === 'needs_attention' ? 'Needs attention' : 'Saved on this device — pending upload');
                p.textContent = stateLabel + (row.byte_size ? ' · ' + Math.round(row.byte_size / 1024) + ' KB' : '');
                id.appendChild(strong);
                id.appendChild(p);
                article.appendChild(id);
                recordsBox.appendChild(article);
            });
        }).catch(function () {
            // Status area reports storage problems on save.
        });
    }

    function queueSelected(event) {
        if (busy) {
            event.preventDefault();
            return;
        }
        var file = fileInput && fileInput.files && fileInput.files[0] ? fileInput.files[0] : null;
        var error = validateFile(file);
        if (error) {
            event.preventDefault();
            setStatus('error', error);
            return;
        }
        // Prefer device queue whenever offline storage works (online or offline).
        event.preventDefault();
        busy = true;
        setStatus('', 'Saving the result sheet on this device…');
        var kindEl = form.elements.namedItem('upload_kind');
        var reasonEl = form.elements.namedItem('upload_reason');
        store.queueSheetUpload({
            user_id: ctx.userId,
            polling_unit_id: ctx.unitId,
            unit_code: ctx.unitCode,
            upload_kind: kindEl ? kindEl.value : '',
            upload_reason: reasonEl ? reasonEl.value.trim() : '',
            original_name: file.name || 'result-sheet',
            mime: file.type || '',
            blob: file
        }).then(function (record) {
            store.notify({ type: 'sheet_queued', user_id: ctx.userId, polling_unit_id: ctx.unitId, client_upload_id: record.client_upload_id });
            setStatus('ok', 'Result sheet saved on this device. It is pending upload — not yet received by the server, and not reviewed.');
            if (fileInput) {
                fileInput.value = '';
            }
            return renderRecords();
        }).catch(function (err) {
            var message = err && err.message ? err.message : 'The sheet could not be saved on this device.';
            if (classicAvailable && navigator.onLine !== false && err && err.kind === 'unavailable') {
                setStatus('error', message + ' Try again while online, or use the classic upload.');
                // Fall back to classic post only when storage is unavailable and the browser is online.
                busy = false;
                form.removeAttribute('data-em-sheet-form');
                form.submit();
                return;
            }
            setStatus('error', message + ' The figures and file selection stay on screen.');
        }).then(function () {
            busy = false;
        });
    }

    form.addEventListener('submit', queueSelected);
    root.hidden = false;
    store.open().then(function () {
        return store.requestPersistence();
    }).then(function () {
        return renderRecords();
    }).catch(function (err) {
        setStatus('error', err && err.message ? err.message : 'Offline sheet storage is unavailable in this browser.');
    });
    store.onChange(function (detail) {
        if (!detail || detail.polling_unit_id === ctx.unitId || detail.type === 'sheet_received' || detail.type === 'sheet_attention' || detail.type === 'sheet_queued') {
            renderRecords();
        }
    });
}());
