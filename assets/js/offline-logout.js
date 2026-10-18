/**
 * Clears the offline device-copy of unit/agent meta on any portal logout POST.
 * Does not delete drafts or queued submissions (those stay for sync after re-auth).
 */
(function () {
    'use strict';

    var userId = parseInt(document.body.getAttribute('data-user-id') || '', 10);
    var store = window.EmOfflineStore;
    if (!userId || userId < 1 || !store || typeof store.deleteMeta !== 'function') {
        return;
    }

    var signingOut = false;
    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (signingOut || !form || (form.method || 'get').toLowerCase() !== 'post') {
            return;
        }
        var action = '';
        try {
            action = new URL(form.action, window.location.href).pathname;
        } catch (e) {
            return;
        }
        if (!/\/portal\/logout(\.php)?$/.test(action)) {
            return;
        }
        event.preventDefault();
        signingOut = true;
        var finished = false;
        var finish = function () {
            if (finished) {
                return;
            }
            finished = true;
            form.submit();
        };
        Promise.all([
            store.deleteMeta('units:' + userId),
            store.deleteMeta('agent')
        ]).then(finish, finish);
        window.setTimeout(finish, 800);
    }, true);
})();
