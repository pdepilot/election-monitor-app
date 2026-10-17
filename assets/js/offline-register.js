/*
 * Registers the result-entry service worker (scope results/entry) on polling-agent pages and
 * reports truthfully whether this device can reopen result entry without a connection.
 */
(function () {
    'use strict';

    var root = document.getElementById('em-sync');
    var store = window.EmOfflineStore;
    if (!root || !store) {
        return;
    }
    var out = root.querySelector('[data-em-offline-ready]');
    var swUrl = root.getAttribute('data-sw-url');
    var scope = root.getAttribute('data-sw-scope');
    var shellUrl = root.getAttribute('data-shell-url');
    var userId = parseInt(root.getAttribute('data-user-id'), 10);
    if (!swUrl || !scope) {
        return;
    }

    function say(kind, text) {
        root.setAttribute('data-offline-ready', kind);
        if (out) {
            out.textContent = text;
        }
    }

    if (!('serviceWorker' in navigator) || !window.caches || !window.isSecureContext) {
        say('unsupported', 'Reopening result entry without a connection is not available in this browser (it needs a secure connection and service-worker support). A page that is already open still saves results on this device.');
        return;
    }

    function activated(registration) {
        if (registration.active) {
            return Promise.resolve(registration);
        }
        var worker = registration.installing || registration.waiting;
        if (!worker) {
            return Promise.reject(new Error('no service worker is installing'));
        }
        return new Promise(function (resolve, reject) {
            worker.addEventListener('statechange', function () {
                if (worker.state === 'activated') {
                    resolve(registration);
                } else if (worker.state === 'redundant') {
                    reject(new Error('the offline files could not all be stored'));
                }
            });
        });
    }

    var ready = false;
    function evaluate() {
        if (!ready) {
            return Promise.resolve();
        }
        return Promise.all([caches.match(shellUrl), store.getMeta('agent'), store.getMeta('units:' + userId)]).then(function (found) {
            var shell = found[0];
            var agent = found[1];
            var copy = found[2];
            if (!shell) {
                say('preparing', 'Offline reopening is not ready yet: the offline page is not stored on this device. Stay online and reload this page.');
            } else if (!agent || agent.user_id !== userId || !copy || !copy.units) {
                say('preparing', 'Offline reopening is not ready yet: your unit details have not been saved on this device. Stay online until the status check completes.');
            } else {
                say('ready', 'Offline reopening is ready: result entry for your ' + copy.units.length + ' polling unit(s) can be reopened on this device without a connection. Unit details are a device copy from ' + new Date(copy.saved_ms).toLocaleString() + ' and are re-checked by the server when results are sent.');
            }
        }).catch(function (error) {
            say('failed', 'Offline reopening could not be checked: ' + (error && error.message ? error.message : 'storage error'));
        });
    }

    say('preparing', 'Preparing this device to reopen result entry without a connection…');
    navigator.serviceWorker.register(swUrl, { scope: scope }).then(activated).then(function () {
        ready = true;
        return evaluate();
    }).catch(function (error) {
        say('failed', 'Offline reopening could not be prepared: ' + (error && error.message ? error.message : 'unknown error') + '. Results still save on this device while this page is open.');
    });

    store.onChange(function (detail) {
        if (detail && detail.type === 'unit_copy') {
            evaluate();
        }
    });
}());
