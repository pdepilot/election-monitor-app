/*
 * Offline page shell for result entry (results/offline-shell, served by the service worker only
 * when results/entry cannot reach the server). Builds the entry form from the device copy of
 * the agent's units saved by offline-sync.js, then loads the existing offline scripts.
 * The device copy is labelled with its age and never presented as current server data.
 */
(function () {
    'use strict';

    var app = document.getElementById('em-shell-app');
    var boot = document.getElementById('em-shell-boot');
    var store = window.EmOfflineStore;
    if (!app || !boot || !store) {
        return;
    }
    boot.className = 'pu-alert pu-alert--warn';
    boot.textContent = 'Opening offline result entry…';

    var FIELDS = [
        ['accredited_voters', 'Accredited voters'],
        ['rejected_ballots', 'Rejected ballots'],
        ['declared_valid_votes', 'Total valid votes (as written on the sheet)'],
        ['declared_total_votes', 'Total votes cast (as written on the sheet)']
    ];
    var entryUrl = app.getAttribute('data-entry-url');
    var unitId = parseInt(new URLSearchParams(window.location.search).get('unit') || '', 10);

    function fail(text) {
        app.hidden = true;
        boot.hidden = false;
        boot.className = 'pu-alert pu-alert--error';
        boot.setAttribute('role', 'alert');
        boot.textContent = text;
        document.body.setAttribute('data-shell-state', 'failed');
    }

    function node(tag, attrs, text) {
        var el = document.createElement(tag);
        Object.keys(attrs || {}).forEach(function (name) {
            el.setAttribute(name, attrs[name]);
        });
        if (text !== undefined) {
            el.textContent = text;
        }
        return el;
    }

    function stamp(ms) {
        return new Date(ms).toLocaleString([], { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
    }

    function syncPanel(userId) {
        var section = node('section', {
            id: 'em-sync',
            'class': 'em-sync cms-card',
            hidden: '',
            'aria-label': 'Results saved on this device',
            'data-user-id': String(userId),
            'data-submit-url': app.getAttribute('data-submit-url'),
            'data-status-url': app.getAttribute('data-status-url'),
            'data-entry-url': entryUrl
        });
        var body = node('div', { 'class': 'cms-card__body' });
        var bar = node('div', { 'class': 'em-sync__bar' });
        bar.appendChild(node('span', { 'class': 'em-sync__conn', 'data-em-conn': '', 'data-conn': 'offline', role: 'status' }, 'Offline'));
        var pending = node('span', { 'class': 'em-sync__stat' });
        pending.appendChild(node('strong', { 'data-em-pending': '' }, '0'));
        pending.appendChild(document.createTextNode(' pending'));
        var attention = node('span', { 'class': 'em-sync__stat' });
        attention.appendChild(node('strong', { 'data-em-attention': '' }, '0'));
        attention.appendChild(document.createTextNode(' need attention'));
        bar.appendChild(pending);
        bar.appendChild(attention);
        bar.appendChild(node('span', { 'class': 'em-sync__stat', 'data-em-last': '' }, 'No server sync yet on this device'));
        bar.appendChild(node('button', { type: 'button', 'class': 'cms-btn cms-btn--ghost cms-btn--sm em-sync__now', 'data-em-sync-now': '' }, 'Sync now'));
        body.appendChild(bar);
        body.appendChild(node('p', { 'class': 'em-sync__msg', 'data-em-sync-msg': '', role: 'status', 'aria-live': 'polite' }));
        var details = node('details', { 'class': 'em-sync__details' });
        var summary = node('summary', {}, 'Results saved on this device (');
        summary.appendChild(node('span', { 'data-em-total': '' }, '0'));
        summary.appendChild(document.createTextNode(')'));
        details.appendChild(summary);
        details.appendChild(node('ul', { 'class': 'em-sync__items', 'data-em-list': '' }));
        body.appendChild(details);
        section.appendChild(body);
        return section;
    }

    function preparedUnits(units) {
        var wrap = node('div', { 'class': 'em-shell__units' });
        if (!units.length) {
            return wrap;
        }
        wrap.appendChild(node('p', {}, 'Polling units prepared for offline entry on this device:'));
        var list = node('ul', {});
        units.forEach(function (unit) {
            var item = node('li', {});
            item.appendChild(node('a', { href: entryUrl + '?unit=' + encodeURIComponent(unit.id) }, unit.code + ' · ' + unit.name));
            list.appendChild(item);
        });
        wrap.appendChild(list);
        return wrap;
    }

    function entryForm(agent, snapshot, unit) {
        var submitted = unit.result_status === 'submitted';
        var card = node('article', { 'class': 'cms-card' });
        var body = node('div', { 'class': 'cms-card__body' });
        body.appendChild(node('h2', { 'class': 'em-shell__unit' }, unit.code));
        body.appendChild(node('p', { 'class': 'pu-note' }, unit.name + ' · ' + unit.ward));

        var section = node('section', {
            id: 'em-offline',
            'class': 'em-offline',
            hidden: '',
            'data-user-id': String(agent.user_id),
            'data-unit-id': String(unit.id),
            'data-unit-code': unit.code,
            'data-config-fingerprint': unit.config_fingerprint,
            'data-candidates': JSON.stringify(unit.candidates.map(function (c) {
                return c.id;
            })),
            'data-server-status': submitted ? 'submitted' : 'none',
            'data-can-submit': submitted ? '0' : '1'
        });
        section.appendChild(node('p', { 'class': 'em-offline__notice' }, app.getAttribute('data-notice')));
        section.appendChild(node('div', { 'data-em-status': '' }));
        section.appendChild(node('div', { 'class': 'pu-list', 'data-em-records': '', 'aria-live': 'polite' }));
        if (!submitted) {
            section.appendChild(node('p', { 'class': 'pu-note em-offline__draft', 'data-em-draft-note': '', 'aria-live': 'polite' }));
        }
        body.appendChild(section);

        if (submitted) {
            body.appendChild(node('p', { 'class': 'pu-note' }, 'When this device last checked (' + stamp(snapshot.saved_ms) + '), a result for this unit was already submitted and locked. It cannot be entered again here.'));
        } else if (unit.result_status === 'draft') {
            body.appendChild(node('p', { 'class': 'pu-note' }, 'A draft saved on the server is not available offline. Only drafts saved on this device are shown.'));
        }

        var form = node('form', { method: 'post', action: entryUrl + '?unit=' + unit.id, 'data-em-offline-form': '', 'data-em-shell': '', novalidate: '' });
        var grid = node('div', { 'class': 'pu-form-grid' });
        function field(id, name, label) {
            var wrap = node('div', { 'class': 'pu-field' });
            wrap.appendChild(node('label', { 'for': id }, label));
            var input = node('input', { id: id, name: name, inputmode: 'numeric', pattern: '[0-9]*', maxlength: '6', autocomplete: 'off' });
            if (submitted) {
                input.readOnly = true;
            }
            wrap.appendChild(input);
            grid.appendChild(wrap);
        }
        FIELDS.forEach(function (f) {
            field(f[0], f[0], f[1]);
        });
        unit.candidates.forEach(function (c) {
            field('votes-' + c.id, 'votes[' + c.id + ']', c.party + ' · ' + c.name);
        });
        form.appendChild(grid);
        var actions = node('div', { 'class': 'pu-form-actions' });
        if (!submitted) {
            actions.appendChild(node('button', { 'class': 'cms-btn cms-btn--ghost', type: 'submit', name: 'intent', value: 'save', formnovalidate: '' }, 'Save draft'));
            actions.appendChild(node('button', { 'class': 'cms-btn cms-btn--primary', type: 'submit', name: 'intent', value: 'submit' }, 'Submit result'));
        }
        form.appendChild(actions);
        body.appendChild(form);
        card.appendChild(body);
        return card;
    }

    function loadScripts(urls) {
        return urls.reduce(function (chain, src) {
            return chain.then(function () {
                return new Promise(function (resolve, reject) {
                    var script = document.createElement('script');
                    script.src = src;
                    script.onload = resolve;
                    script.onerror = function () {
                        reject(new Error('missing ' + src));
                    };
                    document.body.appendChild(script);
                });
            });
        }, Promise.resolve());
    }

    function onlineBanner() {
        var banner = node('p', { 'class': 'pu-alert pu-alert--success', role: 'status', hidden: '', 'data-em-back-online': '' });
        banner.appendChild(document.createTextNode('The connection is back. Results on this device are being sent. '));
        var reload = node('button', { type: 'button', 'class': 'cms-btn cms-btn--ghost cms-btn--sm' }, 'Open the live page');
        reload.addEventListener('click', function () {
            window.location.reload();
        });
        banner.appendChild(reload);
        window.addEventListener('online', function () {
            banner.hidden = false;
        });
        window.addEventListener('offline', function () {
            banner.hidden = true;
        });
        return banner;
    }

    store.open().then(function () {
        return store.getMeta('agent');
    }).then(function (agent) {
        if (!agent || !agent.user_id) {
            fail('No polling-agent account has been prepared for offline entry on this device. Reconnect, sign in, and open your result page once while online.');
            return null;
        }
        return store.getMeta('units:' + agent.user_id).then(function (snapshot) {
            var units = snapshot && Array.isArray(snapshot.units) ? snapshot.units : [];
            var unit = units.filter(function (u) {
                return u.id === unitId;
            })[0];
            if (!unit) {
                fail('This polling unit has not been prepared for offline entry on this device. Reconnect and open it once while online.');
                boot.parentNode.appendChild(preparedUnits(units));
                return null;
            }
            app.textContent = '';
            app.appendChild(node('p', { 'class': 'pu-alert pu-alert--warn', role: 'status', 'data-em-shell-banner': '' },
                'Opened without a server connection, from this device\'s copy of your unit details saved ' + stamp(snapshot.saved_ms)
                + '. They may be out of date. Your assignment, the candidate list and the figures are checked again by the server when the result is sent.'));
            app.appendChild(onlineBanner());
            app.appendChild(syncPanel(agent.user_id));
            app.appendChild(entryForm(agent, snapshot, unit));
            var back = node('p', {});
            back.appendChild(node('a', { href: app.getAttribute('data-dashboard-url') }, 'Back to my polling units (needs a connection)'));
            app.appendChild(back);
            return loadScripts(JSON.parse(app.getAttribute('data-scripts') || '[]')).then(function () {
                boot.hidden = true;
                app.hidden = false;
                document.body.setAttribute('data-shell-state', 'ready');
            });
        });
    }).catch(function (error) {
        if (error && /^missing /.test(error.message || '')) {
            fail('Offline entry could not start: a required file is not stored on this device. Reconnect and open your result page while online. Results already saved on this device are kept.');
            return;
        }
        fail('Offline entry could not start: ' + (error && error.message ? error.message : 'device storage is unavailable') + ' Results already saved on this device are kept.');
    });
}());
