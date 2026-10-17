// Offline result entry and synchronisation browser test (local development only; never part
// of a deployment). Drives headless Chrome over the DevTools protocol with Node's WebSocket.
//   node scripts/_check_offline_browser.mjs
// Uses temporary fixtures (scripts/_offline_fixture.php) and a throwaway Chrome profile.

import { spawn, execFileSync } from 'node:child_process';
import { mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const PHP = 'C:\\xampp\\php\\php.exe';
const CHROME = process.env.CHROME_PATH || 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const PORT = 9333;
const UUID_V4 = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/;

let failures = 0;
const timings = {};
function check(ok, label) {
    console.log((ok ? 'PASS ' : 'FAIL ') + label);
    if (!ok) failures++;
}
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

function php(...args) {
    return execFileSync(PHP, args, { cwd: ROOT, encoding: 'utf8' });
}
function sql(query) {
    return JSON.parse(php('-r', `require 'includes/result_submissions.php'; echo json_encode(db()->query(base64_decode('${Buffer.from(query).toString('base64')}'))->fetchAll(PDO::FETCH_ASSOC));`));
}
function sqlExec(statement) {
    php('-r', `require 'includes/result_submissions.php'; db()->exec(base64_decode('${Buffer.from(statement).toString('base64')}'));`);
}

class Cdp {
    static connect(url) {
        return new Promise((resolve, reject) => {
            const ws = new WebSocket(url);
            ws.onopen = () => resolve(new Cdp(ws));
            ws.onerror = () => reject(new Error('CDP connection failed'));
        });
    }
    constructor(ws) {
        this.ws = ws;
        this.id = 0;
        this.pending = new Map();
        this.listeners = [];
        ws.onmessage = (event) => {
            const msg = JSON.parse(event.data);
            if (msg.id && this.pending.has(msg.id)) {
                const { resolve, reject } = this.pending.get(msg.id);
                this.pending.delete(msg.id);
                msg.error ? reject(new Error(msg.error.message)) : resolve(msg.result);
                return;
            }
            this.listeners.slice().forEach((l) => l(msg));
        };
    }
    send(method, params = {}, sessionId) {
        const id = ++this.id;
        this.ws.send(JSON.stringify(sessionId ? { id, method, params, sessionId } : { id, method, params }));
        return new Promise((resolve, reject) => this.pending.set(id, { resolve, reject }));
    }
    waitFor(method, sessionId, timeout = 20000) {
        return new Promise((resolve, reject) => {
            const timer = setTimeout(() => {
                this.off(listener);
                reject(new Error('Timed out waiting for ' + method));
            }, timeout);
            const listener = (msg) => {
                if (msg.method === method && (!sessionId || msg.sessionId === sessionId)) {
                    clearTimeout(timer);
                    this.off(listener);
                    resolve(msg.params);
                }
            };
            this.listeners.push(listener);
        });
    }
    on(listener) {
        this.listeners.push(listener);
    }
    off(listener) {
        this.listeners = this.listeners.filter((l) => l !== listener);
    }
}

async function launch(profile) {
    const proc = spawn(CHROME, ['--headless=new', `--remote-debugging-port=${PORT}`, `--user-data-dir=${profile}`, '--no-first-run', '--no-default-browser-check', '--disable-extensions', 'about:blank'], { stdio: 'ignore' });
    for (let i = 0; i < 100; i++) {
        try {
            const res = await fetch(`http://127.0.0.1:${PORT}/json/version`);
            if (res.ok) {
                const info = await res.json();
                return { proc, cdp: await Cdp.connect(info.webSocketDebuggerUrl) };
            }
        } catch { /* not up yet */ }
        await sleep(150);
    }
    throw new Error('Chrome did not start');
}

async function close(browser) {
    try { await browser.cdp.send('Browser.close'); } catch { /* already closing */ }
    await new Promise((r) => { browser.proc.once('exit', r); setTimeout(r, 5000); });
}

const allPages = [];
async function newPage(cdp, { context, initScript } = {}) {
    const { targetId } = await cdp.send('Target.createTarget', context ? { url: 'about:blank', browserContextId: context } : { url: 'about:blank' });
    const { sessionId } = await cdp.send('Target.attachToTarget', { targetId, flatten: true });
    const page = { targetId, sessionId, requests: [], responses: [], cdp, send: (m, p) => cdp.send(m, p, sessionId) };
    cdp.on((msg) => {
        if (msg.sessionId === sessionId && msg.method === 'Network.requestWillBeSent') {
            page.requests.push({ url: msg.params.request.url, method: msg.params.request.method, postData: msg.params.request.postData || '' });
        }
        if (msg.sessionId === sessionId && msg.method === 'Network.responseReceived') {
            page.responses.push({ url: msg.params.response.url, status: msg.params.response.status, fromServiceWorker: !!msg.params.response.fromServiceWorker });
        }
    });
    await page.send('Page.enable');
    await page.send('Runtime.enable');
    await page.send('Network.enable');
    if (initScript) await page.send('Page.addScriptToEvaluateOnNewDocument', { source: initScript });
    allPages.push(page);
    return page;
}

async function evaluate(page, expression) {
    const r = await page.send('Runtime.evaluate', { expression, awaitPromise: true, returnByValue: true });
    if (r.exceptionDetails) throw new Error(r.exceptionDetails.exception?.description || r.exceptionDetails.text);
    return r.result.value;
}
async function navigating(page, action) {
    const loaded = page.cdp.waitFor('Page.loadEventFired', page.sessionId);
    await action();
    await loaded;
    await sleep(150);
}
const goto = (page, url) => navigating(page, () => page.send('Page.navigate', { url }));
const reload = (page) => navigating(page, () => page.send('Page.reload', {}));
async function waitUntil(page, expression, timeout = 8000) {
    const end = Date.now() + timeout;
    while (Date.now() < end) {
        if (await evaluate(page, expression)) return true;
        await sleep(60);
    }
    return false;
}
const setOffline = (page, offline) => page.send('Network.emulateNetworkConditions', { offline, latency: 0, downloadThroughput: -1, uploadThroughput: -1 });
const setSlow = (page) => page.send('Network.emulateNetworkConditions', { offline: false, latency: 400, downloadThroughput: 50 * 1024, uploadThroughput: 25 * 1024 });

/* Page-level emulation does not cover the service worker's own fetches, so set it there too. */
const swSessions = new Map();
async function swNetwork(cdp, offline) {
    const { targetInfos } = await cdp.send('Target.getTargets');
    const live = new Set();
    for (const target of targetInfos.filter((t) => t.type === 'service_worker' && t.url.includes('offline-sw'))) {
        live.add(target.targetId);
        let sessionId = swSessions.get(target.targetId);
        if (!sessionId) {
            ({ sessionId } = await cdp.send('Target.attachToTarget', { targetId: target.targetId, flatten: true }));
            await cdp.send('Network.enable', {}, sessionId);
            swSessions.set(target.targetId, sessionId);
        }
        await cdp.send('Network.emulateNetworkConditions', { offline, latency: 0, downloadThroughput: -1, uploadThroughput: -1 }, sessionId);
    }
    for (const [targetId, sessionId] of swSessions) {
        if (!live.has(targetId)) {
            swSessions.delete(targetId);
            await cdp.send('Target.detachFromTarget', { sessionId }).catch(() => {});
        }
    }
}
async function allOffline(page, offline) {
    await swNetwork(page.cdp, offline);
    await setOffline(page, offline);
}

async function login(page, base, user) {
    await goto(page, base + 'portal/login');
    await navigating(page, () => evaluate(page, `(() => { document.getElementById('login').value = ${JSON.stringify(user.login)}; document.getElementById('password').value = ${JSON.stringify(user.password)}; document.getElementById('login-form').requestSubmit(); return true; })()`));
    return evaluate(page, 'location.pathname');
}

const fill = (values) => `(() => { const f = document.querySelector('form[data-em-offline-form]'); const v = ${JSON.stringify(values)}; for (const [k, val] of Object.entries(v)) { const el = f.elements.namedItem(k); el.value = String(val); el.dispatchEvent(new Event('input', { bubbles: true })); } return true; })()`;
const click = (intent) => `(() => { document.querySelector('button[name="intent"][value="${intent}"]').click(); return true; })()`;
const statusText = `(document.querySelector('[data-em-status]') || {}).textContent || ''`;
const dumpIdb = `new Promise((res, rej) => { const r = indexedDB.open('em-offline', 1); r.onsuccess = () => { const db = r.result; const out = {}; const names = [...db.objectStoreNames]; const tx = db.transaction(names, 'readonly'); names.forEach((n) => { const q = tx.objectStore(n).getAll(); q.onsuccess = () => { out[n] = q.result; }; }); tx.oncomplete = () => { db.close(); res(out); }; }; r.onerror = () => rej(r.error); })`;
const records = async (page, unit) => (await evaluate(page, dumpIdb)).submissions.filter((s) => unit === undefined || s.polling_unit_id === unit).sort((a, b) => b.created_ms - a.created_ms);
const latest = async (page, unit) => (await records(page, unit)).find((r) => r.state !== 'superseded') || null;
const stateIs = (unit, state, extra = 'true') => `(async () => { const rows = await EmOfflineStore.listSubmissions(${agent.id}); return rows.some((r) => r.polling_unit_id === ${unit} && r.state === '${state}' && (${extra})); })()`;
const panel = (selector) => `(document.querySelector('#em-sync ${selector}') || {}).textContent || ''`;
const posts = (id) => allPages.flatMap((p) => p.requests).filter((r) => r.method === 'POST' && r.url.includes('api-submit') && r.postData.includes(id)).length;

function figures(cands, a = 40, b = 30, accredited = 120, rejected = 5) {
    return {
        accredited_voters: accredited,
        rejected_ballots: rejected,
        declared_valid_votes: a + b,
        declared_total_votes: a + b + rejected,
        [`votes[${cands[0]}]`]: a,
        [`votes[${cands[1]}]`]: b,
    };
}
function pct(list, p) {
    const sorted = [...list].sort((x, y) => x - y);
    return sorted[Math.min(sorted.length - 1, Math.floor(p * sorted.length))];
}

/* Intercepts api-submit requests: each handler is 'continue', { fail }, or { status, headers, body }. */
async function intercept(page, stage, handlers) {
    const times = [];
    let n = 0;
    const listener = async (msg) => {
        if (msg.sessionId !== page.sessionId || msg.method !== 'Fetch.requestPaused') return;
        const handler = handlers[n++] || 'continue';
        times.push(Date.now());
        const requestId = msg.params.requestId;
        try {
            if (handler === 'continue') {
                await page.send('Fetch.continueRequest', { requestId });
            } else if (handler.fail) {
                await page.send('Fetch.failRequest', { requestId, errorReason: handler.fail });
            } else {
                const headers = Object.entries({ 'Content-Type': 'application/json', ...(handler.headers || {}) }).map(([name, value]) => ({ name, value: String(value) }));
                await page.send('Fetch.fulfillRequest', { requestId, responseCode: handler.status, responseHeaders: headers, body: Buffer.from(JSON.stringify(handler.body || {})).toString('base64') });
            }
        } catch { /* request already gone */ }
    };
    page.cdp.on(listener);
    await page.send('Fetch.enable', { patterns: [{ urlPattern: '*results/api-submit*', requestStage: stage }] });
    return {
        times,
        count: () => n,
        stop: async () => {
            page.cdp.off(listener);
            await page.send('Fetch.disable');
        },
    };
}

const fixture = JSON.parse(php('scripts/_offline_fixture.php', 'setup'));
const profile = mkdtempSync(path.join(tmpdir(), 'em-offline-'));
const { base, users, units: u, candidates: cands } = fixture;
const agent = users['ob.agent'];
const entry = (unit) => `${base}results/entry?unit=${unit}`;
const resultRows = (unit) => sql(`SELECT id, status, accredited_voters FROM polling_unit_results WHERE polling_unit_id = ${Number(unit)}`);
const submissionRows = (id) => sql(`SELECT id, polling_unit_id, user_id FROM result_submissions WHERE submission_id = '${String(id).replace(/[^0-9a-f-]/g, '')}'`);
const userExpr = `(() => { window.EmOfflineSyncUser = () => ${agent.id}; return true; })()`;
let browser = null;

async function queueOffline(page, unit, values) {
    await goto(page, entry(unit));
    await evaluate(page, userExpr);
    await setOffline(page, true);
    await evaluate(page, fill(values));
    await evaluate(page, click('submit'));
    await waitUntil(page, stateIs(unit, 'pending'));
    return latest(page, unit);
}

try {
    browser = await launch(profile);

    console.log('-- scope');
    const { browserContextId: adminContext } = await browser.cdp.send('Target.createBrowserContext');
    const adminPage = await newPage(browser.cdp, { context: adminContext });
    await login(adminPage, base, users['ob.admin']);
    await goto(adminPage, entry(u[0]));
    check(await evaluate(adminPage, `document.getElementById('em-offline') === null && document.getElementById('em-sync') === null && ![...document.scripts].some((s) => /offline-/.test(s.src))`), 'administrators keep the classic form; the offline queue and sync load only for polling agents');
    await browser.cdp.send('Target.disposeBrowserContext', { browserContextId: adminContext });

    const page = await newPage(browser.cdp);
    check((await login(page, base, agent)).includes('agent'), 'agent signs in');
    check(await waitUntil(page, `!!document.getElementById('em-sync') && !document.getElementById('em-sync').hidden && /Sync now/.test(${panel('[data-em-sync-now]')})`), 'agent dashboard shows the sync panel');
    await goto(page, entry(u[0]));
    await evaluate(page, userExpr);
    check(await evaluate(page, `(() => { const r = document.getElementById('em-offline'); return !!r && !r.hidden && !r.hasAttribute('data-store-error') && /Not an official INEC result/.test(r.textContent); })()`), 'entry page shows the offline section with the provisional, not-official notice');
    check(await waitUntil(page, `/Online/.test(${panel('[data-em-conn]')}) && ${panel('[data-em-pending]')} === '0' && ${panel('[data-em-attention]')} === '0'`), 'sync panel: online indicator, pending and attention counts');

    console.log('-- device drafts (Phase C)');
    await evaluate(page, fill({ accredited_voters: 120, [`votes[${cands[0]}]`]: 40 }));
    check(await waitUntil(page, `/Draft saved on this device/.test(document.querySelector('[data-em-draft-note]').textContent)`), 'typing autosaves a draft on the device');
    await reload(page);
    await evaluate(page, userExpr);
    check(await evaluate(page, `document.getElementById('accredited_voters').value === '120' && document.getElementById('votes-${cands[0]}').value === '40' && /Restored the draft/.test(document.querySelector('[data-em-draft-note]').textContent)`), 'draft survives a page reload and is restored');
    check(resultRows(u[0]).length === 0, 'a device draft is not sent to the server');

    await setOffline(page, true);
    check(await waitUntil(page, `navigator.onLine === false && /Offline/.test(${panel('[data-em-conn]')})`), 'indicator switches to Offline');
    await evaluate(page, fill({ accredited_voters: 121 }));
    const before = await evaluate(page, 'location.href');
    await evaluate(page, click('save'));
    check(await waitUntil(page, `/Draft saved on this device only/.test(${statusText})`) && (await evaluate(page, 'location.href')) === before && resultRows(u[0]).length === 0, 'offline "Save draft" saves on the device without leaving the page or reaching the server');

    console.log('-- formal submission while offline (Phase C)');
    await evaluate(page, fill({ ...figures(cands), [`votes[${cands[1]}]`]: 31 }));
    await evaluate(page, click('submit'));
    check(await waitUntil(page, `/do not add up/.test(${statusText})`) && (await records(page)).length === 0, 'figures that do not add up are refused before saving (same rules as the server)');

    await evaluate(page, fill(figures(cands)));
    const csrf = await evaluate(page, `document.querySelector('input[name="csrf_token"]').value`);
    await evaluate(page, `(() => { const b = document.querySelector('button[name="intent"][value="submit"]'); b.click(); b.click(); b.click(); return true; })()`);
    check(await waitUntil(page, `!!document.querySelector('[data-em-records] [data-state="pending"]') && document.getElementById('em-offline').hasAttribute('data-ui-ms')`), 'submit offline: saved on the device immediately');
    const dump = await evaluate(page, dumpIdb);
    const subs = dump.submissions.filter((s) => s.polling_unit_id === u[0]);
    const rec0 = subs[0] || {};
    check(subs.length === 1, 'three rapid clicks store exactly one submission');
    check(UUID_V4.test(rec0.submission_id || '') && rec0.schema_version === 1 && rec0.user_id === agent.id && rec0.state === 'pending' && rec0.figures.accredited_voters === 120 && rec0.votes[cands[0]] === 40 && typeof rec0.created_ms === 'number' && /Z$/.test(rec0.client_created_at), 'record holds a random v4 submission ID, user, unit, figures, creation time, sync state, schema version');
    check(!dump.drafts.some((d) => d.polling_unit_id === u[0]), 'the formal submission replaced the device draft (kept separate)');
    const serialized = JSON.stringify(dump);
    check(!serialized.includes(csrf) && !serialized.includes(agent.password) && !/EM_PORTAL|csrf|password|cookie|token/i.test(serialized), 'no password, cookie, or CSRF token is stored on the device');
    check(await evaluate(page, `/has not reached the server yet/.test(${statusText}) && !/received by (the )?server/i.test(${statusText})`), 'message says saved on device and does not claim server receipt');
    check(await evaluate(page, `[...document.querySelectorAll('button[name="intent"]')].every((b) => b.hidden) && document.getElementById('accredited_voters').readOnly`), 'form locks after the formal submission');
    check(await waitUntil(page, `${panel('[data-em-pending]')} === '1' && /Saved on device · Pending sync/.test(${panel('[data-em-list]')})`), 'pending list shows the unit as saved on device, pending sync');
    await sleep(1000);
    check(posts(rec0.submission_id) === 0 && resultRows(u[0]).length === 0, 'nothing is sent while the browser is offline');
    timings.local_save = { local_save_ms: Number(await evaluate(page, `document.getElementById('em-offline').getAttribute('data-local-save-ms')`)), ui_ms: Number(await evaluate(page, `document.getElementById('em-offline').getAttribute('data-ui-ms')`)) };

    console.log('-- automatic sync when the connection returns');
    const back = Date.now();
    await setOffline(page, false);
    check(await waitUntil(page, stateIs(u[0], 'received'), 15000), 'connection returns: the queued result is sent automatically and marked received');
    timings.recovery_after_reconnect_ms = Date.now() - back;
    const got0 = await latest(page, u[0]);
    const server0 = resultRows(u[0]);
    check(got0.submission_id === rec0.submission_id && got0.server && got0.server.state === 'under_review' && got0.server.replayed === false && server0.length === 1 && server0[0].status === 'submitted' && submissionRows(rec0.submission_id).length === 1, 'server holds one submitted result under its original submission ID, awaiting review');
    check(await waitUntil(page, `/Received by the server at/.test(${statusText}) && /Not an official INEC result/.test(${statusText})`), 'entry page reports server receipt only after the acknowledgement, still labelled provisional');
    check(await waitUntil(page, `${panel('[data-em-pending]')} === '0' && /Received by server · Under review/.test(${panel('[data-em-list]')}) && /Last server sync/.test(${panel('[data-em-last]')})`), 'panel: pending count drops, item shows Under review, last sync time set');
    check(sql(`SELECT COUNT(*) c FROM audit_log WHERE action = 'offline_submit' AND summary LIKE '%${rec0.submission_id}%'`)[0].c == 1, 'audit log records the device submission ID once');
    timings.ack_healthy = (await evaluate(page, 'EmOfflineSync.metrics')).map((m) => m.ack_ms);
    await reload(page);
    check(await evaluate(page, `!!document.querySelector('[data-em-records] [data-submission-id="${rec0.submission_id}"][data-state="received"]') && document.getElementById('accredited_voters').readOnly`), 'after reload the result shows as received and the form stays locked');

    console.log('-- two tabs');
    const tabA = page;
    const tabB = await newPage(browser.cdp);
    await goto(tabA, entry(u[1]));
    await goto(tabB, entry(u[1]));
    await evaluate(tabA, userExpr);
    await evaluate(tabB, userExpr);
    await setOffline(tabA, true);
    await setOffline(tabB, true);
    await evaluate(tabA, fill(figures(cands, 40, 30)));
    await evaluate(tabB, fill(figures(cands, 41, 29)));
    await Promise.all([evaluate(tabA, click('submit')), evaluate(tabB, click('submit'))]);
    await waitUntil(tabA, `!!document.querySelector('[data-em-records] [data-state="pending"]')`);
    await waitUntil(tabB, `!!document.querySelector('[data-em-records] [data-state="pending"]')`);
    const tabRecords = await records(tabA, u[1]);
    const messages = [await evaluate(tabA, statusText), await evaluate(tabB, statusText)];
    check(tabRecords.length === 1 && messages.some((m) => /already saved on this device/.test(m)), 'two tabs submitting the same unit: one stored, the other told it already exists');
    check(await evaluate(tabA, `document.getElementById('accredited_voters').readOnly`) && await evaluate(tabB, `document.getElementById('accredited_voters').readOnly`), 'both tabs lock after one of them queues the result');
    await Promise.all([setOffline(tabA, false), setOffline(tabB, false)]);
    check(await waitUntil(tabA, stateIs(u[1], 'received'), 15000), 'both tabs come online together: the result is received');
    await sleep(1500);
    check(posts(tabRecords[0].submission_id) === 1 && submissionRows(tabRecords[0].submission_id).length === 1 && resultRows(u[1]).length === 1, 'only one tab sent it (one request, one server record)');

    console.log('-- lost response after the server committed');
    await goto(tabA, entry(u[6]));
    await evaluate(tabA, userExpr);
    const lost = await intercept(tabA, 'Response', [{ fail: 'ConnectionReset' }]);
    await evaluate(tabA, fill(figures(cands)));
    await evaluate(tabA, click('submit'));
    check(await waitUntil(tabA, stateIs(u[6], 'pending', "r.last_error && r.last_error.code === 'network'"), 10000), 'response lost: item stays pending with a network error, not marked received');
    await lost.stop();
    const rec6 = await latest(tabA, u[6]);
    check(submissionRows(rec6.submission_id).length === 1 && resultRows(u[6]).length === 1, 'the server had already committed it once');
    check(await waitUntil(tabA, stateIs(u[6], 'received', 'r.server && r.server.replayed === true'), 15000), 'automatic retry with the same ID gets the original outcome replayed');
    const got6 = await latest(tabA, u[6]);
    check(got6.submission_id === rec6.submission_id && posts(rec6.submission_id) === 2 && submissionRows(rec6.submission_id).length === 1 && resultRows(u[6]).length === 1 && sql(`SELECT COUNT(*) c FROM audit_log WHERE action = 'offline_submit' AND summary LIKE '%${rec6.submission_id}%'`)[0].c == 1, 'two sends, one submission record, one result, one audit entry');
    timings.duplicate_retry = (await evaluate(tabA, 'EmOfflineSync.metrics')).filter((m) => m.replayed).map((m) => ({ ack_ms: m.ack_ms, attempts: m.attempts }));

    console.log('-- temporary failures, backoff, Retry-After');
    await goto(tabA, entry(u[7]));
    await evaluate(tabA, userExpr);
    await evaluate(tabA, `(() => { window.__emLog = []; EmOfflineStore.onChange((d) => { if (d.polling_unit_id === ${u[7]} || d.type === 'queued') { window.__emLog.push(d.type); if (d.type === 'sending') window.__emSends.push(Date.now()); } }); window.__emSends = []; return true; })()`);
    const flaky = await intercept(tabA, 'Request', [{ fail: 'InternetDisconnected' }, { status: 503, headers: { 'Retry-After': '2' }, body: { ok: false, code: 'temporarily_unavailable', message: 'Down for a moment.' } }, 'continue']);
    await evaluate(tabA, fill(figures(cands)));
    await evaluate(tabA, click('submit'));
    check(await waitUntil(tabA, stateIs(u[7], 'pending', "r.last_error && r.last_error.code === 'temporarily_unavailable' && r.next_attempt_ms - r.last_attempt_ms >= 2000"), 10000), '503 with Retry-After: kept pending and next attempt scheduled no sooner than the server asked');
    check(await waitUntil(tabA, stateIs(u[7], 'received'), 15000), 'after the network drop and server error it is delivered automatically');
    await flaky.stop();
    const sends = await evaluate(tabA, 'window.__emSends');
    const gaps = sends.slice(1).map((t, i) => t - sends[i]);
    check(sends.length === 3 && gaps[0] >= 900 && gaps[0] <= 2600 && gaps[1] >= 2000, `backoff with jitter after the network failure (${gaps[0]} ms), Retry-After honoured after the 503 (${gaps[1]} ms)`);
    const log7 = await evaluate(tabA, 'window.__emLog');
    check(JSON.stringify(log7) === JSON.stringify(['queued', 'sending', 'retry', 'sending', 'retry', 'sending', 'received']), 'status transitions: saved → sending → retry → sending → retry → sending → received (' + log7.join(', ') + ')');
    check(resultRows(u[7]).length === 1 && submissionRows((await latest(tabA, u[7])).submission_id).length === 1, 'one server record after three attempts');

    await goto(tabA, entry(u[8]));
    await evaluate(tabA, userExpr);
    const limited = await intercept(tabA, 'Request', [{ status: 429, headers: { 'Retry-After': '3' }, body: { ok: false, code: 'rate_limited', retry_after: 3 } }, 'continue']);
    await evaluate(tabA, fill(figures(cands)));
    await evaluate(tabA, click('submit'));
    check(await waitUntil(tabA, stateIs(u[8], 'pending', "r.last_error && r.last_error.code === 'rate_limited'"), 8000), 'rate limited: kept pending with a clear reason');
    check(await waitUntil(tabA, stateIs(u[8], 'received'), 15000), 'rate-limited item delivered after the wait');
    await limited.stop();
    check(limited.times.length === 2 && limited.times[1] - limited.times[0] >= 3000, `Retry-After honoured for 429 (${limited.times[1] - limited.times[0]} ms)`);

    console.log('-- browser restart with a pending submission');
    const rec2 = await queueOffline(tabA, u[2], figures(cands));
    check(rec2 && rec2.state === 'pending', 'queued while offline');
    await close(browser);
    allPages.length = 0;
    browser = await launch(profile);
    let p2 = await newPage(browser.cdp);
    await login(p2, base, agent);
    await evaluate(p2, userExpr);
    check(await waitUntil(p2, stateIs(u[2], 'received'), 15000), 'after restart and sign-in the dashboard sends it automatically');
    const got2 = await latest(p2, u[2]);
    check(got2.submission_id === rec2.submission_id && submissionRows(rec2.submission_id).length === 1 && resultRows(u[2]).length === 1, 'same submission ID, one server record');

    console.log('-- expired session');
    await goto(p2, entry(u[9]));
    await evaluate(p2, userExpr);
    await p2.send('Network.deleteCookies', { name: 'EM_PORTAL', url: base });
    await evaluate(p2, fill(figures(cands)));
    await evaluate(p2, click('submit'));
    check(await waitUntil(p2, stateIs(u[9], 'pending', "r.last_error && r.last_error.code === 'auth_required'"), 8000), 'session expired: item kept pending, marked as waiting for sign-in');
    check(await waitUntil(p2, `/Sign in again/.test(${panel('[data-em-conn]')}) && /Sign in again/.test(${panel('[data-em-sync-msg]')})`), 'panel asks the agent to sign in again');
    const rec9 = await latest(p2, u[9]);
    await sleep(3000);
    await evaluate(p2, `document.querySelector('#em-sync [data-em-sync-now]').click()`);
    await sleep(1500);
    check(posts(rec9.submission_id) === 1 && resultRows(u[9]).length === 0, 'no further submit attempts while signed out (Sync now only re-checks the session)');
    await login(p2, base, agent);
    await evaluate(p2, userExpr);
    check(await waitUntil(p2, stateIs(u[9], 'received'), 15000), 'after signing in again it is sent automatically');
    check((await latest(p2, u[9])).submission_id === rec9.submission_id && submissionRows(rec9.submission_id).length === 1, 'retried with the original submission ID');

    console.log('-- revoked assignment and inactive unit');
    const rec10 = await queueOffline(p2, u[10], figures(cands));
    sqlExec(`UPDATE polling_units SET agent_user_id = NULL, updated_at = updated_at WHERE id = ${u[10]}`);
    await setOffline(p2, false);
    check(await waitUntil(p2, stateIs(u[10], 'needs_attention', "r.last_error.code === 'unit_not_assigned'"), 10000), 'revoked assignment: needs attention, not accepted');
    check(await waitUntil(p2, `${panel('[data-em-attention]')} === '1' && /Needs attention/.test(${panel('[data-em-list]')}) && /not assigned to your account/.test(${panel('[data-em-list]')})`) && await waitUntil(p2, `/Not accepted by the server/.test(${statusText})`), 'panel and entry page explain the refusal');
    check(resultRows(u[10]).length === 0 && submissionRows(rec10.submission_id).length === 0, 'nothing stored on the server');
    await evaluate(p2, `document.querySelector('#em-sync [data-em-sync-now]').click()`);
    await sleep(3500);
    check(posts(rec10.submission_id) === 1 && (await latest(p2, u[10])).state === 'needs_attention', 'not retried automatically (Sync now does not resend refused items)');
    sqlExec(`UPDATE polling_units SET agent_user_id = ${agent.id}, updated_at = updated_at WHERE id = ${u[10]}`);
    await evaluate(p2, `[...document.querySelectorAll('#em-sync li[data-submission-id="${rec10.submission_id}"] button')].find((b) => b.textContent === 'Retry').click()`);
    check(await waitUntil(p2, stateIs(u[10], 'received'), 10000) && (await latest(p2, u[10])).submission_id === rec10.submission_id, 'assignment restored: Retry sends the same submission and it is received');

    const rec11 = await queueOffline(p2, u[11], figures(cands));
    sqlExec(`UPDATE polling_units SET is_active = 0, updated_at = updated_at WHERE id = ${u[11]}`);
    await setOffline(p2, false);
    check(await waitUntil(p2, stateIs(u[11], 'needs_attention', "r.last_error.code === 'unit_inactive'"), 10000), 'inactive polling unit: needs attention');
    sqlExec(`UPDATE polling_units SET is_active = 1, updated_at = updated_at WHERE id = ${u[11]}`);
    check(resultRows(u[11]).length === 0 && (await records(p2, u[11])).some((r) => r.submission_id === rec11.submission_id), 'refused result kept on the device, nothing on the server');

    console.log('-- candidates and figures checked by the server');
    const rec12 = await queueOffline(p2, u[12], figures(cands));
    sqlExec(`UPDATE candidates SET is_active = 0 WHERE id = ${cands[1]}`);
    await setOffline(p2, false);
    check(await waitUntil(p2, stateIs(u[12], 'needs_attention', "r.last_error.code === 'candidates_changed'"), 10000), 'candidate list changed since entry: needs attention');
    sqlExec(`UPDATE candidates SET is_active = 1 WHERE id = ${cands[1]}`);
    check(!(await evaluate(p2, `[...document.querySelectorAll('#em-sync li[data-submission-id="${rec12.submission_id}"] button')].some((b) => b.textContent === 'Retry')`)), 'no plain Retry offered when the same payload cannot succeed');
    await reload(p2);
    await evaluate(p2, userExpr);
    await evaluate(p2, `document.querySelector('#em-sync [data-em-edit="${rec12.submission_id}"]').click()`);
    check(await waitUntil(p2, `!document.getElementById('accredited_voters').readOnly && document.getElementById('accredited_voters').value === '120'`), 'Edit & resubmit unlocks the form with the saved figures');
    await evaluate(p2, click('submit'));
    check(await waitUntil(p2, stateIs(u[12], 'received', `r.submission_id !== '${rec12.submission_id}'`), 10000), 'the corrected result is sent as a new submission and received');
    const all12 = await records(p2, u[12]);
    check(all12.length === 2 && all12.some((r) => r.submission_id === rec12.submission_id && r.state === 'superseded') && submissionRows(rec12.submission_id).length === 0 && resultRows(u[12]).length === 1, 'the refused submission is kept, marked replaced; the server has one result');

    await goto(p2, entry(u[13]));
    await evaluate(p2, userExpr);
    await evaluate(p2, `(async () => { const r = await EmOfflineStore.queueSubmission({ user_id: ${agent.id}, polling_unit_id: ${u[13]}, unit_code: 'BAD', config_fingerprint: '', figures: { accredited_voters: 120, rejected_ballots: 5, declared_valid_votes: 71, declared_total_votes: 76 }, votes: { ${cands[0]}: 40, ${cands[1]}: 30 } }); EmOfflineStore.notify({ type: 'queued', user_id: ${agent.id}, polling_unit_id: ${u[13]} }); return r.submission_id; })()`);
    check(await waitUntil(p2, stateIs(u[13], 'needs_attention', "r.last_error.code === 'validation_failed' && /do not add up/.test(r.last_error.message)"), 10000) && resultRows(u[13]).length === 0, 'figures that bypassed the device checks are refused by the server arithmetic (422)');
    await evaluate(p2, `(async () => { await EmOfflineStore.queueSubmission({ user_id: ${agent.id}, polling_unit_id: ${u[13]}, unit_code: 'BAD', config_fingerprint: '', figures: { accredited_voters: 120, rejected_ballots: 5, declared_valid_votes: 70, declared_total_votes: 75 }, votes: { ${cands[0]}: 40, ${cands[1]}: 20, 999999: 10 } }); EmOfflineStore.notify({ type: 'queued', user_id: ${agent.id}, polling_unit_id: ${u[13]} }); return true; })()`);
    check(await waitUntil(p2, stateIs(u[13], 'needs_attention', "r.last_error.code === 'candidates_changed'"), 10000) && resultRows(u[13]).length === 0, 'unknown candidate ID refused');

    console.log('-- submission ID reused with different figures (second device of the same agent)');
    const { browserContextId: deviceTwo } = await browser.cdp.send('Target.createBrowserContext');
    const other = await newPage(browser.cdp, { context: deviceTwo });
    await login(other, base, agent);
    await evaluate(other, `(async () => { await EmOfflineStore.open(); const db = await new Promise((res) => { const r = indexedDB.open('em-offline', 1); r.onsuccess = () => res(r.result); }); await new Promise((res, rej) => { const tx = db.transaction(['submissions'], 'readwrite'); tx.objectStore('submissions').put({ submission_id: '${rec0.submission_id}', schema_version: 1, user_id: ${agent.id}, polling_unit_id: ${u[0]}, unit_code: 'COPY', config_fingerprint: '', figures: { accredited_voters: 150, rejected_ballots: 5, declared_valid_votes: 70, declared_total_votes: 75 }, votes: { ${cands[0]}: 40, ${cands[1]}: 30 }, client_created_at: new Date().toISOString(), created_ms: Date.now(), updated_ms: Date.now(), state: 'pending', attempts: 0, next_attempt_ms: 0, last_error: null, server: null }); tx.oncomplete = res; tx.onerror = rej; }); db.close(); EmOfflineSync.sync('test', { manual: true }); return true; })()`);
    check(await waitUntil(other, `(async () => (await EmOfflineStore.listSubmissions(${agent.id})).some((r) => r.state === 'needs_attention' && r.last_error.code === 'submission_id_reused'))()`, 10000), 'same ID with different figures: refused (409) and marked needs attention');
    check(resultRows(u[0])[0].accredited_voters == 120 && submissionRows(rec0.submission_id).length === 1, 'server kept the original figures');
    await browser.cdp.send('Target.disposeBrowserContext', { browserContextId: deviceTwo });

    console.log('-- no endless retries; everything preserved');
    const counts = { [rec10.submission_id]: posts(rec10.submission_id), [rec11.submission_id]: posts(rec11.submission_id), [rec12.submission_id]: posts(rec12.submission_id) };
    await sleep(5000);
    check(Object.entries(counts).every(([id, n]) => posts(id) === n), 'refused items were not sent again in the background');
    const final = await records(p2);
    const byUnit = (unit) => final.filter((r) => r.polling_unit_id === unit);
    check([0, 1, 2, 6, 7, 8, 9, 10].every((i) => byUnit(u[i]).some((r) => r.state === 'received')) && byUnit(u[11]).some((r) => r.state === 'needs_attention') && byUnit(u[12]).length === 2 && byUnit(u[13]).length === 2, 'every device record is still present with its final state');
    check(sql(`SELECT COUNT(*) c FROM result_submissions WHERE user_id = ${agent.id}`)[0].c == 9 && sql(`SELECT COUNT(*) c FROM polling_unit_results WHERE polling_unit_id IN (${u.join(',')})`)[0].c == 9, 'server: exactly one record per delivered result (9), none for refused ones');

    console.log('-- leave warning (Phase C)');
    await goto(p2, entry(u[3]));
    const leaveBlocked = `(() => { const e = new Event('beforeunload', { cancelable: true }); window.dispatchEvent(e); return e.defaultPrevented; })()`;
    check(!(await evaluate(p2, leaveBlocked)), 'no leave warning on an unchanged page');
    await evaluate(p2, fill({ accredited_voters: 77 }));
    check(await evaluate(p2, leaveBlocked), 'leave warning while a change is not yet saved on the device');
    await waitUntil(p2, `/Draft saved on this device/.test(document.querySelector('[data-em-draft-note]').textContent)`);
    check(!(await evaluate(p2, leaveBlocked)), 'no leave warning once the draft is saved on the device');

    console.log('-- storage failures (Phase C)');
    const broken = await newPage(browser.cdp, { initScript: `Object.defineProperty(window, 'indexedDB', { configurable: true, get() { return { open() { throw new DOMException('Storage disabled for test', 'SecurityError'); } }; } });` });
    await goto(broken, entry(u[4]));
    check(await evaluate(broken, `document.getElementById('em-offline').getAttribute('data-store-error') === 'unavailable' && /Offline saving is unavailable/.test(${statusText})`), 'unavailable storage is reported honestly on load');
    await setOffline(broken, true);
    await evaluate(broken, fill(figures(cands)));
    await evaluate(broken, click('submit'));
    check(await waitUntil(broken, `/Not saved: this browser cannot store results offline/.test(${statusText}) && document.getElementById('accredited_voters').value === '120'`), 'offline without storage: "Not saved" and the figures stay on screen');
    await setOffline(broken, false);
    await navigating(broken, () => evaluate(broken, click('submit')));
    const classic = resultRows(u[4]);
    check(classic.length === 1 && classic[0].status === 'submitted', 'online without storage: the classic form submits through the existing rules');

    // Storage.overrideQuotaForOrigin is ignored by this Chrome build, so a full disk is simulated:
    // object-store writes throw the browser's QuotaExceededError while the flag is set.
    const quota = await newPage(browser.cdp, { initScript: `(() => { for (const m of ['add', 'put']) { const orig = IDBObjectStore.prototype[m]; IDBObjectStore.prototype[m] = function (...a) { if (window.__emQuotaFull) throw new DOMException('Simulated full storage', 'QuotaExceededError'); return orig.apply(this, a); }; } })();` });
    await goto(quota, entry(u[5]));
    await evaluate(quota, userExpr);
    await evaluate(quota, 'window.__emQuotaFull = true');
    await setOffline(quota, true);
    await evaluate(quota, fill(figures(cands)));
    await evaluate(quota, click('submit'));
    check(await waitUntil(quota, `/Not saved: .*out of storage space/.test(${statusText}) && document.getElementById('accredited_voters').value === '120'`), 'storage full (simulated QuotaExceededError): "Not saved" with the reason, figures kept');
    check(!(await records(quota, u[5])).length, 'nothing half-saved after the quota failure');
    check(await evaluate(quota, `[...document.querySelectorAll('button[name="intent"]')].every((b) => !b.hidden && !b.disabled)`), 'buttons re-enabled so the agent can retry');
    await evaluate(quota, 'window.__emQuotaFull = false');
    await evaluate(quota, click('submit'));
    check(await waitUntil(quota, stateIs(u[5], 'pending')), 'after space is freed the same figures save on retry');
    timings.local_save_after_quota = { local_save_ms: Number(await evaluate(quota, `document.getElementById('em-offline').getAttribute('data-local-save-ms')`)), ui_ms: Number(await evaluate(quota, `document.getElementById('em-offline').getAttribute('data-ui-ms')`)) };

    console.log('-- slow network');
    const slowStart = Date.now();
    await setSlow(quota);
    check(await waitUntil(quota, stateIs(u[5], 'received'), 30000), 'delivered over a slow connection (400 ms latency, 25 KB/s up)');
    timings.slow_network = { reconnect_to_received_ms: Date.now() - slowStart, ack_ms: (await evaluate(quota, 'EmOfflineSync.metrics')).map((m) => m.ack_ms) };
    await setOffline(quota, false);

    console.log('-- offline reopening (Phase D.1)');
    const headers = (await fetch(base + 'portal/login')).headers.get('content-security-policy') || '';
    check(/worker-src 'self'/.test(headers) && /script-src 'nonce-[^']+' https:\/\/cdn\.jsdelivr\.net;/.test(headers) && /frame-ancestors 'none'/.test(headers) && !/unsafe-eval/.test(headers), "CSP adds only worker-src 'self'; script and framing rules unchanged");
    const swSource = await (await fetch(base + 'results/offline-sw')).text();
    const swConfig = JSON.parse(/const CONFIG = (\{.*\});/.exec(swSource)[1]);
    const sw = await newPage(browser.cdp);
    await goto(sw, base + 'agent/dashboard');
    check(await waitUntil(sw, `document.getElementById('em-sync').getAttribute('data-offline-ready') === 'ready' && /Offline reopening is ready/.test(${panel('[data-em-offline-ready]')})`, 15000), 'first online visit: worker installed, shell and assets cached, unit copy saved; panel says ready');
    const reg = await evaluate(sw, `navigator.serviceWorker.getRegistration('${base}results/entry').then((r) => r ? { scope: r.scope, script: (r.active || {}).scriptURL } : null)`);
    check(!!reg && reg.scope === base + 'results/entry' && /results\/offline-sw$/.test(reg.script) && !(await evaluate(sw, `navigator.serviceWorker.getRegistration('${base}results/review').then((r) => !!r)`)), 'same-origin worker with the narrow scope results/entry (reviews, sheets, APIs outside it)');
    const cacheDump = `(async () => { const out = {}; for (const name of await caches.keys()) { const c = await caches.open(name); out[name] = []; for (const r of await c.keys()) { const res = await c.match(r); out[name].push({ url: r.url, body: await res.text() }); } } return out; })()`;
    let cached = await evaluate(sw, cacheDump);
    const entries = Object.values(cached).flat();
    const pathOf = (url) => new URL(url).pathname + new URL(url).search;
    check(Object.keys(cached).length === 1 && Object.keys(cached)[0] === 'em-offline-shell-' + swConfig.version && entries.length === swConfig.precache.length && entries.every((e) => swConfig.precache.includes(pathOf(e.url))), 'one versioned cache holding exactly the shell and the versioned offline assets');
    const liveToken = await evaluate(sw, `document.querySelector('input[name="csrf_token"]').value`);
    const shellBody = entries.find((e) => pathOf(e.url) === swConfig.shellUrl).body;
    check(entries.every((e) => !/api-(submit|status)|results\/entry|dashboard|sheet|incident|review/.test(pathOf(e.url))) && !entries.some((e) => e.body.includes(liveToken) || e.body.includes(agent.login) || e.body.includes('Offline Browser')) && !/csrf_token|\bunit_code\b|TEST CANDIDATE/.test(shellBody), 'no authenticated page, API response, CSRF token, candidate or user data in the cache');
    check(/Not an official INEC result/.test(shellBody), 'cached shell carries the provisional, not-official notice');
    const meta = (await evaluate(sw, dumpIdb)).meta;
    check(!JSON.stringify(meta).match(/csrf|token|password/i) && meta.some((m) => m.key === 'units:' + agent.id), 'device copy of unit details holds no token or password');

    await goto(sw, entry(u[3]));
    await allOffline(sw, true);
    await reload(sw);
    check(await waitUntil(sw, `document.body.getAttribute('data-shell-state') === 'ready'`, 10000), 'entry page reopens offline from the cached shell');
    check(await evaluate(sw, `!document.querySelector('input[name="csrf_token"]') && /Opened without a server connection/.test(document.querySelector('[data-em-shell-banner]').textContent) && /may be out of date/.test(document.querySelector('[data-em-shell-banner]').textContent) && /Not an official INEC result/.test(document.body.textContent) && /Offline/.test(${panel('[data-em-conn]')})`), 'offline page is labelled as a device copy, provisional, offline; it holds no CSRF token');
    check(await evaluate(sw, `!!document.getElementById('votes-${cands[0]}') && !!document.getElementById('votes-${cands[1]}') && document.getElementById('accredited_voters').value === '77'`), 'form built from the device copy; the draft saved earlier is restored');
    const apiOffline = await evaluate(sw, `fetch('${base}results/api-status').then(() => 'answered', () => 'failed')`);
    check(apiOffline === 'failed', 'API request fails offline (never answered from a cache)');
    await evaluate(sw, fill(figures(cands)));
    await evaluate(sw, click('submit'));
    check(await waitUntil(sw, stateIs(u[3], 'pending')) && await evaluate(sw, `/has not reached the server yet/.test(${statusText})`), 'offline save works in the reopened page (saved on device, not yet received)');
    const rec3 = await latest(sw, u[3]);
    await reload(sw);
    check(await waitUntil(sw, `document.body.getAttribute('data-shell-state') === 'ready' && !!document.querySelector('[data-em-records] [data-submission-id="${rec3.submission_id}"][data-state="pending"]') && document.getElementById('accredited_voters').readOnly`, 10000), 'after another offline reload the saved result is still listed and the form is locked');

    await allOffline(sw, false);
    check(await waitUntil(sw, stateIs(u[3], 'received'), 15000) && submissionRows(rec3.submission_id).length === 1 && resultRows(u[3]).length === 1, 'reconnect: sent through results/api-submit with its original ID and received');
    check(await evaluate(sw, `!document.querySelector('[data-em-back-online]').hidden`), 'reopened page offers to open the live page once the connection is back');
    await navigating(sw, () => evaluate(sw, `document.querySelector('[data-em-back-online] button').click()`));
    check(await evaluate(sw, `!!document.querySelector('input[name="csrf_token"]') && !document.getElementById('em-shell-app')`), 'online reload returns the live server page, not the shell');
    const apiResponses = allPages.flatMap((p) => p.responses).filter((r) => /api-(submit|status)/.test(r.url));
    const cachedNow = await evaluate(sw, `(async () => { const out = []; for (const n of await caches.keys()) { for (const req of await (await caches.open(n)).keys()) out.push(new URL(req.url).pathname); } return out; })()`);
    check(apiResponses.length > 0 && !apiResponses.some((r) => r.fromServiceWorker) && !cachedNow.some((p) => /api-|results\/entry/.test(p)), 'no API response came from the service worker; no entry page or API response was ever cached');

    const dropCached = (part) => `(async () => { for (const n of await caches.keys()) { const c = await caches.open(n); for (const r of await c.keys()) { if (r.url.includes('${part}')) await c.delete(r); } } return true; })()`;
    const hasCached = (part) => `(async () => { for (const n of await caches.keys()) { const c = await caches.open(n); if ((await c.keys()).some((r) => r.url.includes('${part}'))) return true; } return false; })()`;
    const settle = () => new Promise((r) => setTimeout(r, 1500));
    await settle();
    await evaluate(sw, dropCached('offline-entry.js'));
    await sw.send('Network.clearBrowserCache');
    await allOffline(sw, true);
    await reload(sw);
    check(await waitUntil(sw, `document.body.getAttribute('data-shell-state') === 'failed' && /required file is not stored/.test(document.getElementById('em-shell-boot').textContent) && document.getElementById('em-shell-app').hidden`, 10000), 'missing cached asset: offline page says so instead of half-working');
    await allOffline(sw, false);
    await reload(sw);
    check(await waitUntil(sw, hasCached('offline-entry.js'), 10000), 'next online visit restores the missing asset');
    await settle();
    await evaluate(sw, `(async () => { for (const n of await caches.keys()) { const c = await caches.open(n); for (const r of await c.keys()) { if (new URL(r.url).pathname === '${swConfig.shellUrl}') await c.delete(r); } } return true; })()`);
    await allOffline(sw, true);
    await reload(sw);
    check(await evaluate(sw, `/not available offline on this device/.test(document.body.textContent) && !document.getElementById('em-shell-app')`), 'shell not cached: honest "not prepared" page, nothing fabricated');
    await allOffline(sw, false);
    await reload(sw);
    check(await waitUntil(sw, `caches.match('${swConfig.shellUrl}').then((r) => !!r)`, 10000), 'next online visit restores the shell');

    console.log('-- service worker update');
    const recordsBefore = (await records(sw)).length;
    const draftsBefore = (await evaluate(sw, dumpIdb)).drafts.length;
    await evaluate(sw, `Promise.all([caches.open('em-offline-shell-obsolete').then((c) => c.put('/obsolete', new Response('old'))), caches.open('unrelated-app-cache').then((c) => c.put('/keep', new Response('keep')))]).then(() => true)`);
    const cssPath = path.join(ROOT, 'assets', 'css', 'offline-shell.css');
    const cssMtime = php('-r', `echo filemtime(${JSON.stringify(cssPath)});`).trim();
    php('-r', `touch(${JSON.stringify(cssPath)}, ${Number(cssMtime) + 120});`);
    try {
        const newConfig = JSON.parse(/const CONFIG = (\{.*\});/.exec(await (await fetch(base + 'results/offline-sw')).text())[1]);
        check(newConfig.version !== swConfig.version, 'changing an offline asset produces a new worker version');
        await evaluate(sw, `navigator.serviceWorker.getRegistration('${base}results/entry').then((r) => r.update()).then(() => true)`);
        check(await waitUntil(sw, `caches.keys().then((k) => k.includes('em-offline-shell-${newConfig.version}') && !k.includes('em-offline-shell-${swConfig.version}') && !k.includes('em-offline-shell-obsolete') && k.includes('unrelated-app-cache'))`, 15000), 'update: new cache created, this worker\'s obsolete caches removed, other caches untouched');
        check((await records(sw)).length === recordsBefore && (await evaluate(sw, dumpIdb)).drafts.length === draftsBefore, 'device drafts and submissions preserved across the update');
    } finally {
        php('-r', `touch(${JSON.stringify(cssPath)}, ${Number(cssMtime)});`);
    }

    console.log('-- sign-out clears the device copy');
    const kept = (await records(sw)).length;
    await goto(sw, base + 'agent/dashboard');
    await navigating(sw, () => evaluate(sw, `document.querySelector('form[action$="portal/logout"] button[type="submit"]').click()`));
    const afterLogout = await evaluate(sw, dumpIdb);
    check(/portal\/login/.test(await evaluate(sw, 'location.pathname')) && !afterLogout.meta.some((m) => m.key === 'agent' || /^units:/.test(m.key)) && afterLogout.submissions.length === kept, 'sign-out removes the unit copy; results saved on the device stay');
    await allOffline(sw, true);
    await goto(sw, entry(u[3]));
    check(await waitUntil(sw, `document.body.getAttribute('data-shell-state') === 'failed' && /No polling-agent account has been prepared/.test(document.body.textContent)`, 10000), 'after sign-out the offline page shows no unit details');
    await allOffline(sw, false);

    console.log('-- performance');
    const bench = (n) => `(async () => { const out = []; for (let i = 0; i < ${n}; i++) { const t = performance.now(); await EmOfflineStore.queueSubmission({ user_id: -1, polling_unit_id: 100000 + i + Math.floor(Math.random() * 1e6), unit_code: 'BENCH', config_fingerprint: 'x', figures: { accredited_voters: 1, rejected_ballots: 0, declared_valid_votes: 1, declared_total_votes: 1 }, votes: { 1: 1 } }); out.push(performance.now() - t); } return out; })()`;
    const normal = await evaluate(quota, bench(40));
    await quota.send('Emulation.setCPUThrottlingRate', { rate: 4 });
    const slowCpu = await evaluate(quota, bench(40));
    await quota.send('Emulation.setCPUThrottlingRate', { rate: 1 });
    timings.commit_normal = { median: +pct(normal, 0.5).toFixed(1), p95: +pct(normal, 0.95).toFixed(1), max: +Math.max(...normal).toFixed(1) };
    timings.commit_cpu4x = { median: +pct(slowCpu, 0.5).toFixed(1), p95: +pct(slowCpu, 0.95).toFixed(1), max: +Math.max(...slowCpu).toFixed(1) };
    check(timings.local_save.ui_ms < 100 && timings.commit_normal.p95 < 100, `local save + UI under 100 ms (measured ${timings.local_save.ui_ms} ms; commit p95 ${timings.commit_normal.p95} ms)`);
    console.log('TIMINGS ' + JSON.stringify(timings));
} catch (error) {
    failures++;
    console.log('FAIL test aborted: ' + (error && error.stack ? error.stack : error));
} finally {
    if (browser) await close(browser);
    try { rmSync(profile, { recursive: true, force: true }); } catch { /* temp profile */ }
    const down = JSON.parse(php('scripts/_offline_fixture.php', 'teardown'));
    check(down.ok === true, 'fixtures removed and polling units restored');
}
console.log(failures === 0 ? 'ALL PASS' : 'FAILURES ' + failures);
process.exit(failures === 0 ? 0 : 1);
