/*
 * Device storage for offline result submission (IndexedDB "em-offline").
 *
 * Holds only result figures and their sync bookkeeping. Never store passwords, cookies,
 * CSRF tokens, or any other secret here. A save is reported only after the IndexedDB
 * transaction commits; nothing in this file claims that the server received anything.
 */
(function () {
    'use strict';

    var DB_NAME = 'em-offline';
    var DB_VERSION = 1;
    var SCHEMA_VERSION = 1;
    var ACTIVE_STATES = ['pending', 'sending', 'received'];

    var dbPromise = null;

    function StoreError(kind, message, cause) {
        this.name = 'StoreError';
        this.kind = kind;
        this.message = message;
        this.cause = cause || null;
    }
    StoreError.prototype = Object.create(Error.prototype);

    function describe(error) {
        if (error instanceof StoreError) {
            return error;
        }
        var name = error && error.name ? error.name : '';
        if (name === 'QuotaExceededError') {
            return new StoreError('quota', 'This device is out of storage space for offline results. Free some space, or submit while online.', error);
        }
        if (name === 'InvalidStateError' || name === 'SecurityError' || name === 'NotAllowedError') {
            return new StoreError('unavailable', 'This browser is not allowing offline storage here (private browsing or a blocked setting).', error);
        }
        if (name === 'VersionError') {
            return new StoreError('unavailable', 'Offline storage was created by a newer version of this app. Reload the page while online.', error);
        }
        return new StoreError('unknown', 'The device could not save the result (' + (name || 'storage error') + ').', error);
    }

    function open() {
        if (dbPromise) {
            return dbPromise;
        }
        dbPromise = new Promise(function (resolve, reject) {
            var request;
            try {
                if (!window.indexedDB) {
                    throw new StoreError('unavailable', 'This browser does not support offline storage.');
                }
                request = window.indexedDB.open(DB_NAME, DB_VERSION);
            } catch (error) {
                reject(describe(error));
                return;
            }
            request.onupgradeneeded = function () {
                var db = request.result;
                if (!db.objectStoreNames.contains('submissions')) {
                    var submissions = db.createObjectStore('submissions', { keyPath: 'submission_id' });
                    submissions.createIndex('by_user', 'user_id');
                    submissions.createIndex('by_user_unit', ['user_id', 'polling_unit_id']);
                }
                if (!db.objectStoreNames.contains('drafts')) {
                    db.createObjectStore('drafts', { keyPath: ['user_id', 'polling_unit_id'] });
                }
                if (!db.objectStoreNames.contains('meta')) {
                    db.createObjectStore('meta', { keyPath: 'key' });
                }
            };
            request.onblocked = function () {
                reject(new StoreError('blocked', 'Offline storage is busy in another tab that is out of date. Close other tabs of this portal and reload.'));
            };
            request.onerror = function () {
                reject(describe(request.error));
            };
            request.onsuccess = function () {
                var db = request.result;
                db.onversionchange = function () {
                    db.close();
                    dbPromise = null;
                };
                db.onclose = function () {
                    dbPromise = null;
                };
                resolve(db);
            };
        });
        dbPromise.catch(function () {
            dbPromise = null;
        });
        return dbPromise;
    }

    /*
     * Runs work(stores, tx) inside one transaction and resolves with its value only once the
     * transaction has committed. An abort (including quota exhaustion) rejects.
     */
    function run(storeNames, mode, work, durability) {
        return open().then(function (db) {
            return new Promise(function (resolve, reject) {
                var tx;
                try {
                    tx = durability
                        ? db.transaction(storeNames, mode, { durability: durability })
                        : db.transaction(storeNames, mode);
                } catch (error) {
                    reject(describe(error));
                    return;
                }
                var stores = {};
                storeNames.forEach(function (name) {
                    stores[name] = tx.objectStore(name);
                });
                var outcome;
                var refusal = null;
                tx.oncomplete = function () {
                    if (refusal) {
                        reject(refusal);
                    } else {
                        resolve(outcome);
                    }
                };
                tx.onabort = function () {
                    reject(refusal || describe(tx.error));
                };
                try {
                    work(stores, tx, function (value) {
                        outcome = value;
                    }, function (error) {
                        refusal = error;
                        try {
                            tx.abort();
                        } catch (ignored) {
                            // The transaction already finished; oncomplete reports the refusal.
                        }
                    });
                } catch (error) {
                    refusal = describe(error);
                    try {
                        tx.abort();
                    } catch (ignored) {
                        // Nothing left to abort.
                    }
                }
            });
        });
    }

    /* A throw inside a request callback would abort with a generic AbortError; keep the real cause. */
    function guarded(refuse, handler) {
        return function () {
            try {
                handler.apply(this, arguments);
            } catch (error) {
                refuse(describe(error));
            }
        };
    }

    function newSubmissionId() {
        var cryptoApi = window.crypto;
        if (cryptoApi && typeof cryptoApi.randomUUID === 'function') {
            return cryptoApi.randomUUID();
        }
        if (!cryptoApi || typeof cryptoApi.getRandomValues !== 'function') {
            throw new StoreError('unavailable', 'This browser cannot create a secure submission ID.');
        }
        var bytes = cryptoApi.getRandomValues(new Uint8Array(16));
        bytes[6] = (bytes[6] & 0x0f) | 0x40;
        bytes[8] = (bytes[8] & 0x3f) | 0x80;
        var hex = Array.prototype.map.call(bytes, function (b) {
            return (b < 16 ? '0' : '') + b.toString(16);
        }).join('');
        return hex.slice(0, 8) + '-' + hex.slice(8, 12) + '-' + hex.slice(12, 16) + '-' + hex.slice(16, 20) + '-' + hex.slice(20);
    }

    /*
     * Adds a formal submission and removes the unit's device draft in the same transaction.
     * Refuses (kind "duplicate") when this user already has an active submission for the
     * unit; IndexedDB serialises read-write transactions, so two tabs cannot both pass.
     * A submission that needs attention is marked superseded by the new one.
     */
    function queueSubmission(input) {
        var now = Date.now();
        var record = {
            submission_id: newSubmissionId(),
            schema_version: SCHEMA_VERSION,
            user_id: input.user_id,
            polling_unit_id: input.polling_unit_id,
            unit_code: input.unit_code,
            config_fingerprint: input.config_fingerprint,
            figures: input.figures,
            votes: input.votes,
            client_created_at: new Date(now).toISOString(),
            created_ms: now,
            updated_ms: now,
            state: 'pending',
            attempts: 0,
            next_attempt_ms: 0,
            last_error: null,
            server: null,
            supersedes: null
        };
        return run(['submissions', 'drafts'], 'readwrite', function (stores, tx, done, refuse) {
            var range = IDBKeyRange.only([record.user_id, record.polling_unit_id]);
            var cursorRequest = stores.submissions.index('by_user_unit').openCursor(range);
            var superseded = [];
            cursorRequest.onsuccess = guarded(refuse, function () {
                var cursor = cursorRequest.result;
                if (cursor) {
                    var existing = cursor.value;
                    if (ACTIVE_STATES.indexOf(existing.state) !== -1) {
                        refuse(new StoreError('duplicate', 'A result for this polling unit is already saved on this device.', existing));
                        return;
                    }
                    if (existing.state === 'needs_attention') {
                        superseded.push(existing);
                    }
                    cursor.continue();
                    return;
                }
                superseded.forEach(function (old) {
                    old.state = 'superseded';
                    old.updated_ms = now;
                    old.superseded_by = record.submission_id;
                    stores.submissions.put(old);
                });
                if (superseded.length) {
                    record.supersedes = superseded[superseded.length - 1].submission_id;
                }
                stores.submissions.add(record);
                stores.drafts.delete([record.user_id, record.polling_unit_id]);
                done(record);
            });
        }, 'strict');
    }

    function listSubmissions(userId, unitId) {
        return run(['submissions'], 'readonly', function (stores, tx, done) {
            var request = unitId
                ? stores.submissions.index('by_user_unit').getAll(IDBKeyRange.only([userId, unitId]))
                : stores.submissions.index('by_user').getAll(IDBKeyRange.only(userId));
            request.onsuccess = function () {
                done(request.result.sort(function (a, b) {
                    return b.created_ms - a.created_ms;
                }));
            };
        });
    }

    /* Read-modify-write of one submission; mutate(record) returns false to leave it unchanged. */
    function updateSubmission(submissionId, mutate) {
        return run(['submissions'], 'readwrite', function (stores, tx, done, refuse) {
            var request = stores.submissions.get(submissionId);
            request.onsuccess = guarded(refuse, function () {
                var record = request.result;
                if (!record || mutate(record) === false) {
                    done(record || null);
                    return;
                }
                record.updated_ms = Date.now();
                stores.submissions.put(record);
                done(record);
            });
        }, 'strict');
    }

    /* Applies mutate(record) to every submission of one user in a single transaction. */
    function updateAll(userId, mutate) {
        return run(['submissions'], 'readwrite', function (stores, tx, done, refuse) {
            var changed = [];
            var request = stores.submissions.index('by_user').openCursor(IDBKeyRange.only(userId));
            request.onsuccess = guarded(refuse, function () {
                var cursor = request.result;
                if (!cursor) {
                    done(changed);
                    return;
                }
                var record = cursor.value;
                if (mutate(record) !== false) {
                    record.updated_ms = Date.now();
                    cursor.update(record);
                    changed.push(record);
                }
                cursor.continue();
            });
        }, 'strict');
    }

    /*
     * Called only by the tab holding the sync lock: any item still marked "sending" was left
     * by a closed tab or an interrupted request, so it goes back to pending with the same ID.
     */
    function resetAbandoned(userId) {
        return updateAll(userId, function (record) {
            if (record.state !== 'sending') {
                return false;
            }
            record.state = 'pending';
            record.sending_owner = null;
            record.next_attempt_ms = 0;
        });
    }

    /* "Sync now": pending items become due immediately (their submission IDs never change). */
    function clearBackoff(userId) {
        return updateAll(userId, function (record) {
            if (record.state !== 'pending' || !record.next_attempt_ms) {
                return false;
            }
            record.next_attempt_ms = 0;
        });
    }

    /* The agent asks to send a submission that needs attention again, unchanged. */
    function retrySubmission(submissionId) {
        return updateSubmission(submissionId, function (record) {
            if (record.state !== 'needs_attention') {
                return false;
            }
            record.state = 'pending';
            record.attempts = 0;
            record.next_attempt_ms = 0;
        });
    }

    /* Cross-tab lease used only where the Web Locks API is unavailable. */
    function acquireLease(name, owner, ttlMs) {
        return run(['meta'], 'readwrite', function (stores, tx, done, refuse) {
            var request = stores.meta.get('lease:' + name);
            request.onsuccess = guarded(refuse, function () {
                var current = request.result ? request.result.value : null;
                var now = Date.now();
                if (current && current.owner !== owner && current.expires_ms > now) {
                    done(false);
                    return;
                }
                stores.meta.put({ key: 'lease:' + name, value: { owner: owner, expires_ms: now + ttlMs } });
                done(true);
            });
        });
    }

    function releaseLease(name, owner) {
        return run(['meta'], 'readwrite', function (stores, tx, done, refuse) {
            var request = stores.meta.get('lease:' + name);
            request.onsuccess = guarded(refuse, function () {
                if (request.result && request.result.value.owner === owner) {
                    stores.meta.delete('lease:' + name);
                }
                done(true);
            });
        });
    }

    function getDraft(userId, unitId) {
        return run(['drafts'], 'readonly', function (stores, tx, done) {
            var request = stores.drafts.get([userId, unitId]);
            request.onsuccess = function () {
                done(request.result || null);
            };
        });
    }

    function saveDraft(draft, durable) {
        var record = {
            user_id: draft.user_id,
            polling_unit_id: draft.polling_unit_id,
            fields: draft.fields,
            votes: draft.votes,
            saved_ms: Date.now()
        };
        return run(['drafts'], 'readwrite', function (stores, tx, done) {
            stores.drafts.put(record);
            done(record);
        }, durable ? 'strict' : 'relaxed');
    }

    function deleteDraft(userId, unitId) {
        return run(['drafts'], 'readwrite', function (stores, tx, done) {
            stores.drafts.delete([userId, unitId]);
            done(true);
        });
    }

    function getMeta(key) {
        return run(['meta'], 'readonly', function (stores, tx, done) {
            var request = stores.meta.get(key);
            request.onsuccess = function () {
                done(request.result ? request.result.value : null);
            };
        });
    }

    function setMeta(key, value) {
        return run(['meta'], 'readwrite', function (stores, tx, done) {
            stores.meta.put({ key: key, value: value });
            done(value);
        });
    }

    function deleteMeta(key) {
        return run(['meta'], 'readwrite', function (stores, tx, done) {
            stores.meta.delete(key);
            done(true);
        });
    }

    var listeners = [];
    var channel = typeof window.BroadcastChannel === 'function' ? new window.BroadcastChannel('em-offline') : null;
    function emit(detail, fromOtherTab) {
        listeners.slice().forEach(function (listener) {
            try {
                listener(detail, !!fromOtherTab);
            } catch (error) {
                window.setTimeout(function () {
                    throw error;
                });
            }
        });
    }
    if (channel) {
        channel.onmessage = function (event) {
            emit(event.data || {}, true);
        };
    }
    /* Tells this tab and every other open tab that stored submissions changed. */
    function notify(detail) {
        emit(detail || {}, false);
        if (channel) {
            try {
                channel.postMessage(detail || {});
            } catch (ignored) {
                // Other tabs re-read storage when they next become visible.
            }
        }
    }
    function onChange(listener) {
        listeners.push(listener);
    }

    var persistAsked = false;
    function requestPersistence() {
        if (persistAsked || !navigator.storage || typeof navigator.storage.persist !== 'function') {
            return Promise.resolve(null);
        }
        persistAsked = true;
        return navigator.storage.persist().catch(function () {
            return null;
        });
    }

    window.EmOfflineStore = {
        SCHEMA_VERSION: SCHEMA_VERSION,
        ACTIVE_STATES: ACTIVE_STATES,
        StoreError: StoreError,
        open: open,
        newSubmissionId: newSubmissionId,
        queueSubmission: queueSubmission,
        listSubmissions: listSubmissions,
        updateSubmission: updateSubmission,
        updateAll: updateAll,
        resetAbandoned: resetAbandoned,
        clearBackoff: clearBackoff,
        retrySubmission: retrySubmission,
        acquireLease: acquireLease,
        releaseLease: releaseLease,
        getDraft: getDraft,
        saveDraft: saveDraft,
        deleteDraft: deleteDraft,
        getMeta: getMeta,
        setMeta: setMeta,
        deleteMeta: deleteMeta,
        notify: notify,
        onChange: onChange,
        requestPersistence: requestPersistence
    };
}());
