<?php
declare(strict_types=1);

/*
 * Service worker for reopening polling-agent result entry offline. Registered only from agent
 * pages with scope results/entry. It caches a public, data-free page shell and versioned
 * offline assets, fetched without credentials. It never caches authenticated pages, API
 * responses, CSRF tokens, or result data, and never answers POST or API requests.
 */

require_once dirname(__DIR__) . '/includes/bootstrap.php';

$assets = [
    'assets/css/offline-shell.css',
    'assets/js/offline-store.js',
    'assets/js/offline-sync.js',
    'assets/js/offline-entry.js',
    'assets/js/offline-shell.js',
];
$shellUrl = url('results/offline-shell.php');
$precache = array_merge([$shellUrl], array_map('asset_url', $assets));
$sources = [__FILE__, __DIR__ . '/offline-shell.php'];
$fingerprint = json_encode($precache);
foreach ($sources as $source) {
    $fingerprint .= '|' . hash_file('sha256', $source);
}

$config = [
    'version' => substr(hash('sha256', $fingerprint), 0, 16),
    'entryPath' => url('results/entry.php'),
    'shellUrl' => $shellUrl,
    'precache' => $precache,
    'navigationTimeoutMs' => 15000,
];

header('Content-Type: application/javascript; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header("Content-Security-Policy: default-src 'self'");
echo 'const CONFIG = ' . json_encode($config, JSON_UNESCAPED_SLASHES) . ";\n";
?>
const CACHE_PREFIX = 'em-offline-shell-';
const CACHE = CACHE_PREFIX + CONFIG.version;
const ASSETS = new Set(CONFIG.precache.filter((u) => u !== CONFIG.shellUrl));

function precacheRequest(url) {
    return new Request(url, { credentials: 'omit', cache: 'reload', redirect: 'error' });
}

/* Adds any precache entry missing from the current cache (all or nothing on install). */
async function ensurePrecache(cache, strict) {
    const missing = [];
    for (const url of CONFIG.precache) {
        if (!(await cache.match(url))) {
            missing.push(url);
        }
    }
    if (!missing.length) {
        return;
    }
    if (strict) {
        await cache.addAll(missing.map(precacheRequest));
        return;
    }
    await Promise.all(missing.map((url) => cache.add(precacheRequest(url)).catch(() => null)));
}

self.addEventListener('install', (event) => {
    event.waitUntil(caches.open(CACHE).then((cache) => ensurePrecache(cache, true)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(keys.filter((key) => key.startsWith(CACHE_PREFIX) && key !== CACHE).map((key) => caches.delete(key))))
            .then(() => self.clients.claim())
    );
});

function timeout(ms) {
    return new Promise((resolve, reject) => setTimeout(() => reject(new Error('timeout')), ms));
}

const NOT_PREPARED = '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
    + '<title>Offline entry not prepared</title></head><body><main>'
    + '<h1>Result entry is not available offline on this device</h1>'
    + '<p>This device has not stored the files needed to open result entry without internet. Reconnect, sign in, and open your result page once while online.</p>'
    + '<p>Results already saved on this device are kept and will be sent when you reconnect.</p>'
    + '</main></body></html>';

async function entryNavigation(event) {
    try {
        const response = await Promise.race([fetch(event.request), timeout(CONFIG.navigationTimeoutMs)]);
        event.waitUntil(caches.open(CACHE).then((cache) => ensurePrecache(cache, false)).catch(() => null));
        return response;
    } catch (error) {
        const cache = await caches.open(CACHE);
        const shell = await cache.match(CONFIG.shellUrl);
        if (shell) {
            return shell;
        }
        return new Response(NOT_PREPARED, {
            status: 503,
            headers: {
                'Content-Type': 'text/html; charset=utf-8',
                'Cache-Control': 'no-store',
                'Content-Security-Policy': "default-src 'none'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'",
            },
        });
    }
}

async function cachedAsset(request) {
    const cache = await caches.open(CACHE);
    const hit = await cache.match(request.url);
    return hit || fetch(request);
}

self.addEventListener('fetch', (event) => {
    const request = event.request;
    if (request.method !== 'GET') {
        return;
    }
    const url = new URL(request.url);
    if (url.origin !== self.location.origin) {
        return;
    }
    if (request.mode === 'navigate') {
        if (url.pathname === CONFIG.entryPath) {
            event.respondWith(entryNavigation(event));
        }
        return;
    }
    if (ASSETS.has(url.pathname + url.search)) {
        event.respondWith(cachedAsset(request));
    }
});
