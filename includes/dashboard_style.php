<?php
declare(strict_types=1);

function dashboard_css(): string
{
    return <<<'CSS'
:root {
    --cms-navy: #070b16;
    --cms-navy-mid: #0d1428;
    --cms-navy-light: #151f3d;
    --cms-surface: rgba(13, 20, 40, 0.92);
    --cms-glass: rgba(17, 26, 51, 0.72);
    --cms-glass-border: rgba(255, 255, 255, 0.07);
    --cms-gold: #fbfe06;
    --cms-gold-light: #fdff66;
    --cms-gold-glow: rgba(251, 254, 6, 0.35);
    --cms-blue: #3b82f6;
    --cms-blue-soft: rgba(59, 130, 246, 0.15);
    --cms-success: #22c55e;
    --cms-text: #e8edf8;
    --cms-text-muted: #8b95a8;
    --cms-text-dim: #5c6678;
    --cms-sidebar-w: 280px;
    --cms-sidebar-collapsed: 76px;
    --cms-topbar-h: 68px;
    --cms-radius: 12px;
    --cms-radius-lg: 18px;
    --cms-font: "Inter", system-ui, sans-serif;
    --cms-display: "Poppins", var(--cms-font);
    --cms-ease: cubic-bezier(0.4, 0, 0.2, 1);
    --cms-shadow: 0 8px 32px rgba(0, 0, 0, 0.35);
    --cms-shadow-lg: 0 24px 64px rgba(0, 0, 0, 0.45);
    --cms-transition: 0.28s var(--cms-ease);
}

*, *::before, *::after { box-sizing: border-box; }

html { -webkit-font-smoothing: antialiased; }

body.cms-app {
    margin: 0;
    min-height: 100vh;
    font-family: var(--cms-font);
    font-size: 0.9375rem;
    line-height: 1.6;
    color: var(--cms-text);
    background: var(--cms-navy);
    overflow-x: hidden;
}

button, input, select { font-family: inherit; }
button { cursor: pointer; }
a { color: inherit; text-decoration: none; }
ul { margin: 0; padding: 0; list-style: none; }
img { max-width: 100%; display: block; }

.visually-hidden {
    position: absolute;
    width: 1px;
    height: 1px;
    padding: 0;
    margin: -1px;
    overflow: hidden;
    clip: rect(0, 0, 0, 0);
    white-space: nowrap;
    border: 0;
}

.cms-layout { display: flex; min-height: 100vh; }

.cms-sidebar {
    position: fixed;
    top: 0;
    left: 0;
    z-index: 200;
    width: var(--cms-sidebar-w);
    height: 100vh;
    height: 100dvh;
    display: flex;
    flex-direction: column;
    background: var(--cms-surface);
    border-right: 1px solid var(--cms-glass-border);
    transition: width var(--cms-transition), transform var(--cms-transition);
    overflow: hidden;
}

.cms-sidebar.is-collapsed { width: var(--cms-sidebar-collapsed); }

.cms-sidebar__brand {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 20px 18px;
    border-bottom: 1px solid var(--cms-glass-border);
}

.cms-sidebar__logo {
    width: 42px;
    height: 42px;
    border-radius: 50%;
    overflow: hidden;
    border: 2px solid color-mix(in srgb, var(--cms-gold) 55%, transparent);
    flex-shrink: 0;
}

.cms-sidebar__logo img, .cms-sidebar__logo svg { width: 100%; height: 100%; object-fit: cover; display: block; }

.cms-sidebar__brand-text { overflow: hidden; transition: opacity var(--cms-transition); }
.cms-sidebar.is-collapsed .cms-sidebar__brand-text { opacity: 0; width: 0; }

.cms-sidebar__brand-text strong {
    display: block;
    font-family: var(--cms-display);
    font-size: 0.82rem;
    font-weight: 700;
    letter-spacing: 0.04em;
    white-space: nowrap;
}

.cms-sidebar__brand-text span {
    font-size: 0.7rem;
    color: var(--cms-text-muted);
    white-space: nowrap;
}

.cms-sidebar__nav {
    flex: 1;
    overflow-y: auto;
    padding: 12px 10px 24px;
}

.cms-nav-group { margin-bottom: 8px; }

.cms-nav-group__label {
    display: flex;
    align-items: center;
    justify-content: space-between;
    width: 100%;
    padding: 8px 12px;
    border: 0;
    background: transparent;
    color: var(--cms-text-dim);
    font-size: 0.68rem;
    font-weight: 600;
    letter-spacing: 0.1em;
    text-transform: uppercase;
}

.cms-nav-group__label i.chevron { font-size: 0.6rem; transition: transform var(--cms-transition); }
.cms-nav-group.is-collapsed .cms-nav-group__label i.chevron { transform: rotate(-90deg); }
.cms-nav-group.is-collapsed .cms-nav-group__items { display: none; }

.cms-nav-item a,
.cms-nav-item__static {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px 12px;
    border-radius: var(--cms-radius);
    font-size: 0.875rem;
    font-weight: 500;
    color: var(--cms-text-muted);
    position: relative;
}

.cms-nav-item a:hover { color: var(--cms-text); background: rgba(255, 255, 255, 0.04); }

.cms-nav-item.is-active a {
    color: var(--cms-gold-light);
    background: color-mix(in srgb, var(--cms-gold) 14%, transparent);
}

.cms-nav-item.is-active a::before {
    content: "";
    position: absolute;
    left: 0;
    top: 50%;
    transform: translateY(-50%);
    width: 3px;
    height: 60%;
    background: var(--cms-gold);
    border-radius: 0 3px 3px 0;
}

.cms-nav-item__icon { width: 20px; text-align: center; flex-shrink: 0; }

.cms-nav-item__badge {
    margin-left: auto;
    padding: 2px 8px;
    font-size: 0.65rem;
    font-weight: 600;
    border-radius: 20px;
    background: var(--cms-blue-soft);
    color: var(--cms-blue);
}

.cms-nav-item.is-soon { opacity: 0.72; }

.cms-sidebar.is-collapsed .cms-nav-item span,
.cms-sidebar.is-collapsed .cms-nav-item__badge,
.cms-sidebar.is-collapsed .cms-nav-group__label span,
.cms-sidebar.is-collapsed .cms-sidebar__collapse span { display: none; }

.cms-sidebar__footer {
    padding: 14px;
    border-top: 1px solid var(--cms-glass-border);
}

.cms-sidebar__collapse {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    width: 100%;
    padding: 10px;
    border-radius: var(--cms-radius);
    border: 1px solid var(--cms-glass-border);
    background: transparent;
    color: var(--cms-text-muted);
    font-size: 0.8rem;
}

.cms-sidebar__collapse:hover {
    color: var(--cms-text);
    border-color: color-mix(in srgb, var(--cms-gold) 45%, transparent);
    background: color-mix(in srgb, var(--cms-gold) 8%, transparent);
}

.cms-main {
    flex: 1;
    margin-left: var(--cms-sidebar-w);
    min-width: 0;
    min-height: 100vh;
    transition: margin-left var(--cms-transition);
}

.cms-sidebar.is-collapsed ~ .cms-main,
body.sidebar-collapsed .cms-main { margin-left: var(--cms-sidebar-collapsed); }

.cms-topbar {
    position: sticky;
    top: 0;
    z-index: 100;
    display: flex;
    align-items: center;
    gap: 16px;
    height: var(--cms-topbar-h);
    padding: 0 24px;
    background: rgba(7, 11, 22, 0.88);
    border-bottom: 1px solid var(--cms-glass-border);
}

.cms-topbar__menu {
    display: none;
    width: 40px;
    height: 40px;
    align-items: center;
    justify-content: center;
    border-radius: var(--cms-radius);
    border: 1px solid var(--cms-glass-border);
    background: transparent;
    color: var(--cms-text-muted);
}

.cms-topbar__search { flex: 1; max-width: 420px; position: relative; }

.cms-topbar__search input {
    width: 100%;
    padding: 10px 16px 10px 42px;
    background: var(--cms-glass);
    border: 1px solid var(--cms-glass-border);
    border-radius: var(--cms-radius);
    color: var(--cms-text);
    font-size: 16px;
}

.cms-topbar__search input:focus {
    outline: none;
    border-color: color-mix(in srgb, var(--cms-gold) 55%, transparent);
    box-shadow: 0 0 0 3px var(--cms-gold-glow);
}

.cms-topbar__search input::placeholder { color: var(--cms-text-dim); }

.cms-topbar__search > i {
    position: absolute;
    left: 14px;
    top: 50%;
    transform: translateY(-50%);
    color: var(--cms-text-dim);
    font-size: 0.85rem;
    pointer-events: none;
}

.cms-topbar__actions { display: flex; align-items: center; gap: 8px; margin-left: auto; }

.cms-theme-picker {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 6px 10px;
    border: 1px solid var(--cms-glass-border);
    border-radius: var(--cms-radius);
    background: var(--cms-glass);
}

.cms-theme-picker__label {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 0.78rem;
    color: var(--cms-text-muted);
    white-space: nowrap;
}

.cms-theme-picker__select {
    min-width: 118px;
    max-width: 160px;
    padding: 6px 8px;
    border: 1px solid var(--cms-glass-border);
    border-radius: 8px;
    color: var(--cms-text);
    background: rgba(0, 0, 0, 0.25);
    font-size: 0.78rem;
}

.cms-topbar__btn {
    width: 40px;
    height: 40px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: var(--cms-radius);
    border: 1px solid var(--cms-glass-border);
    background: transparent;
    color: var(--cms-text-muted);
}

.cms-topbar__btn:hover,
.cms-profile__trigger:hover { background: rgba(255, 255, 255, 0.04); }

.cms-profile { position: relative; }

.cms-profile__trigger {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 6px 10px 6px 6px;
    border-radius: var(--cms-radius);
    border: 1px solid var(--cms-glass-border);
    background: transparent;
    color: inherit;
}

.cms-profile__avatar {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    background: linear-gradient(135deg, var(--cms-gold), var(--cms-navy-light));
    color: #111;
    font-size: 0.75rem;
    font-weight: 700;
}

.cms-profile__info { text-align: left; min-width: 0; }
.cms-profile__info strong,
.cms-profile__info span { display: block; }
.cms-profile__info strong {
    max-width: 160px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    font-size: 0.8rem;
}
.cms-profile__info span { font-size: 0.7rem; color: var(--cms-text-muted); }
.cms-profile__chevron { font-size: 0.65rem; color: var(--cms-text-dim); transition: transform var(--cms-transition); }
.cms-profile.is-open .cms-profile__chevron { transform: rotate(180deg); }

.cms-dropdown,
.cms-notify-panel {
    position: absolute;
    top: calc(100% + 8px);
    right: 0;
    background: var(--cms-navy-mid);
    border: 1px solid var(--cms-glass-border);
    border-radius: var(--cms-radius-lg);
    box-shadow: var(--cms-shadow-lg);
    opacity: 0;
    visibility: hidden;
    transform: translateY(-8px);
    transition: opacity 0.22s var(--cms-ease), transform 0.22s var(--cms-ease), visibility 0.22s;
    z-index: 300;
}

.cms-dropdown { min-width: 240px; padding: 8px; }
.cms-dropdown.is-open,
.cms-notify-panel.is-open { opacity: 1; visibility: visible; transform: translateY(0); }

.cms-dropdown__identity { padding: 8px 12px 4px; }
.cms-dropdown__identity strong,
.cms-dropdown__identity span { display: block; }
.cms-dropdown__identity span { color: var(--cms-text-muted); font-size: 0.78rem; overflow-wrap: anywhere; }

.cms-dropdown button,
.cms-dropdown__link {
    display: flex;
    align-items: center;
    gap: 10px;
    width: 100%;
    padding: 10px 12px;
    border: 0;
    border-radius: var(--cms-radius);
    background: transparent;
    color: var(--cms-text-muted);
    font-size: 0.85rem;
    text-align: left;
}

.cms-dropdown button:hover,
.cms-dropdown__link:hover { color: var(--cms-text); background: rgba(255, 255, 255, 0.05); }
.cms-dropdown__link { text-decoration: none; }
.cms-dropdown__divider { height: 1px; margin: 6px 0; background: var(--cms-glass-border); }

.cms-notify-panel { width: min(360px, calc(100vw - 32px)); }
.cms-notify-panel__head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 14px 16px;
    border-bottom: 1px solid var(--cms-glass-border);
    font-family: var(--cms-display);
    font-weight: 600;
}
.cms-notify-empty { padding: 24px 16px; text-align: center; color: var(--cms-text-muted); font-size: 0.82rem; }

.cms-page {
    padding: 24px;
    animation: cmsPageIn 0.45s var(--cms-ease) both;
}

@keyframes cmsPageIn {
    from { opacity: 0; transform: translateY(12px); }
    to { opacity: 1; transform: translateY(0); }
}

.cms-page__header {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-start;
    justify-content: space-between;
    gap: 16px;
    margin-bottom: 28px;
}

.cms-page__title {
    margin: 0;
    font-family: var(--cms-display);
    font-size: clamp(1.4rem, 2.5vw, 1.85rem);
    font-weight: 700;
    letter-spacing: -0.02em;
    line-height: 1.2;
}

.cms-page__subtitle { margin: 6px 0 0; max-width: 560px; color: var(--cms-text-muted); font-size: 0.9rem; }
.cms-page__actions { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; }
.cms-page__footnote { margin: 0; padding: 0 24px 24px; color: var(--cms-text-dim); font-size: 0.82rem; }

.cms-section-label {
    display: flex;
    align-items: center;
    gap: 10px;
    margin: 0 0 16px;
    font-family: var(--cms-display);
    font-size: 0.78rem;
    font-weight: 600;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: var(--cms-text-dim);
}

.cms-section-label::after { content: ""; flex: 1; height: 1px; background: var(--cms-glass-border); }

.cms-stats {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 16px;
    margin-bottom: 24px;
}

.cms-card {
    background: var(--cms-glass);
    border: 1px solid var(--cms-glass-border);
    border-radius: var(--cms-radius-lg);
    box-shadow: var(--cms-shadow);
}

.cms-stat { padding: 20px; position: relative; overflow: hidden; }
.cms-stat::after {
    content: "";
    position: absolute;
    top: -40%;
    right: -20%;
    width: 120px;
    height: 120px;
    border-radius: 50%;
    background: radial-gradient(circle, var(--cms-gold-glow), transparent 70%);
    opacity: 0.4;
    pointer-events: none;
}

.cms-stat__top { display: flex; align-items: center; justify-content: space-between; margin-bottom: 14px; }
.cms-stat__icon {
    width: 44px;
    height: 44px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.1rem;
}
.cms-stat__icon--gold { background: color-mix(in srgb, var(--cms-gold) 18%, transparent); color: var(--cms-gold); }
.cms-stat__icon--blue { background: var(--cms-blue-soft); color: var(--cms-blue); }
.cms-stat__icon--green { background: rgba(34, 197, 94, 0.15); color: var(--cms-success); }
.cms-stat__icon--purple { background: rgba(139, 92, 246, 0.15); color: #a78bfa; }

.cms-stat__value {
    position: relative;
    z-index: 1;
    font-family: var(--cms-display);
    font-size: 1.85rem;
    font-weight: 700;
    letter-spacing: -0.02em;
    line-height: 1.1;
    margin-bottom: 6px;
    color: var(--cms-text);
}

.cms-stat__value--text { font-size: 1.15rem; }
.cms-stat__label { position: relative; z-index: 1; font-size: 0.82rem; color: var(--cms-text-muted); }
.cms-stat__brand {
    position: relative;
    z-index: 1;
    display: inline-block;
    margin-top: 8px;
    padding: 3px 8px;
    border-radius: 6px;
    background: color-mix(in srgb, var(--cms-gold) 16%, transparent);
    color: var(--cms-gold);
    font-size: 0.65rem;
    font-weight: 600;
    letter-spacing: 0.06em;
    text-transform: uppercase;
}

.cms-card__head { padding: 18px 20px; border-bottom: 1px solid var(--cms-glass-border); }
.cms-card__title { margin: 0; font-family: var(--cms-display); font-size: 0.95rem; font-weight: 600; }
.cms-card__body { padding: 20px; }
.cms-card__subtitle, .cms-muted { color: var(--cms-text-muted); }
.cms-muted { margin: 12px 0 0; }

.cms-meta-list { display: grid; gap: 0.85rem; margin: 0; }
.cms-meta-list > div { display: grid; gap: 0.2rem; }
.cms-meta-list dt { font-size: 0.75rem; color: var(--cms-text-muted); text-transform: uppercase; letter-spacing: 0.05em; }
.cms-meta-list dd { margin: 0; font-weight: 600; overflow-wrap: anywhere; }

.cms-grid { display: grid; gap: 20px; }
.cms-grid--2 { grid-template-columns: repeat(2, minmax(0, 1fr)); }

.cms-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 10px 18px;
    border-radius: var(--cms-radius);
    border: 1px solid transparent;
    font-size: 0.875rem;
    font-weight: 600;
}

.cms-btn--primary {
    background: linear-gradient(135deg, var(--cms-gold), #c9cc05);
    color: var(--cms-navy);
    box-shadow: 0 4px 20px var(--cms-gold-glow);
}

.cms-badge {
    display: inline-flex;
    align-items: center;
    padding: 4px 10px;
    border-radius: 20px;
    font-size: 0.72rem;
    font-weight: 600;
}

.cms-badge--muted { background: rgba(255, 255, 255, 0.06); color: var(--cms-text-muted); }

.cms-sidebar-overlay {
    position: fixed;
    inset: 0;
    z-index: 190;
    background: rgba(0, 0, 0, 0.6);
}

.cms-sidebar-overlay[hidden] { display: none; }

body.cms-app[data-theme="ocean"] {
    --cms-gold: #38bdf8;
    --cms-gold-light: #7dd3fc;
    --cms-gold-glow: rgba(56, 189, 248, 0.35);
}

body.cms-app[data-theme="emerald"] {
    --cms-gold: #34d399;
    --cms-gold-light: #6ee7b7;
    --cms-gold-glow: rgba(52, 211, 153, 0.35);
}

body.cms-app[data-theme="royal"] {
    --cms-gold: #a78bfa;
    --cms-gold-light: #c4b5fd;
    --cms-gold-glow: rgba(167, 139, 250, 0.35);
}

body.cms-app[data-theme="sunset"] {
    --cms-gold: #fb923c;
    --cms-gold-light: #fdba74;
    --cms-gold-glow: rgba(251, 146, 60, 0.35);
}

body.cms-app[data-mode="light"] {
    --cms-navy: #f3f6fb;
    --cms-navy-mid: #ffffff;
    --cms-navy-light: #e8edf7;
    --cms-surface: rgba(255, 255, 255, 0.96);
    --cms-glass: rgba(255, 255, 255, 0.92);
    --cms-glass-border: rgba(15, 23, 42, 0.1);
    --cms-text: #0f172a;
    --cms-text-muted: #475569;
    --cms-text-dim: #64748b;
    --cms-shadow: 0 8px 24px rgba(15, 23, 42, 0.08);
    --cms-shadow-lg: 0 24px 48px rgba(15, 23, 42, 0.14);
}

body.cms-app[data-mode="light"] .cms-topbar { background: rgba(255, 255, 255, 0.92); }
body.cms-app[data-mode="light"] .cms-theme-picker__select,
body.cms-app[data-mode="light"] .cms-topbar__search input { background: #fff; }
body.cms-app[data-mode="light"] .cms-profile__avatar { color: #111; }

@media (max-width: 1100px) {
    .cms-stats { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .cms-theme-picker__label { display: none; }
}

@media (max-width: 1024px) {
    .cms-sidebar { transform: translateX(-100%); width: var(--cms-sidebar-w); }
    .cms-sidebar.is-mobile-open { transform: translateX(0); }
    .cms-main,
    .cms-sidebar.is-collapsed ~ .cms-main,
    body.sidebar-collapsed .cms-main { margin-left: 0; }
    .cms-topbar__menu { display: flex; }
    .cms-profile__info,
    .cms-profile__chevron { display: none; }
    .cms-profile__trigger { padding-right: 6px; }
    .cms-grid--2 { grid-template-columns: 1fr; }
}

@media (max-width: 640px) {
    .cms-page { padding: 16px; }
    .cms-page__footnote { padding: 0 16px 16px; }
    .cms-topbar { padding: 0 12px; gap: 8px; }
    .cms-theme-picker { display: none; }
    .cms-stats { grid-template-columns: 1fr; }
    .cms-page__header { flex-direction: column; }
}

.pu-crumb { display: flex; flex-wrap: wrap; gap: 8px; margin: 0 0 8px; color: var(--cms-text-muted); font-size: 0.82rem; }
.pu-crumb a { color: var(--cms-gold); }
.pu-note { margin: 0 0 16px; color: var(--cms-text-muted); }
.pu-stats { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 16px; margin-bottom: 24px; }
.pu-ward-counts { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 10px; }
.pu-ward-count {
    display: grid;
    gap: 2px;
    min-width: 0;
    padding: 12px;
    border: 1px solid var(--cms-glass-border);
    border-radius: 12px;
    background: color-mix(in srgb, var(--cms-navy-light) 65%, transparent);
    color: var(--cms-text);
}
.pu-ward-count strong {
    font-family: var(--cms-display);
    font-size: 1.45rem;
    line-height: 1.1;
    color: var(--cms-text);
}
.pu-ward-count span { color: var(--cms-text-muted); font-size: 0.78rem; overflow-wrap: anywhere; }
.pu-ward-count.is-current { border-color: var(--cms-gold); }
.pu-alert { margin: 0 0 16px; padding: 12px 14px; border-radius: 12px; border: 1px solid var(--cms-glass-border); }
.pu-alert--success { background: rgba(34, 197, 94, 0.12); }
.pu-alert--error { background: rgba(248, 113, 113, 0.12); }
.pu-alert--warn { background: color-mix(in srgb, var(--cms-gold) 12%, transparent); }
.pu-filters { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 190px), 1fr)); gap: 12px; align-items: end; margin-bottom: 16px; }
.pu-filters > .cms-btn { width: 100%; min-height: 42px; }
.pu-field { display: grid; gap: 6px; }
.pu-field label { font-size: 0.75rem; font-weight: 600; letter-spacing: 0.04em; text-transform: uppercase; color: var(--cms-text-muted); }
.pu-field input, .pu-field select {
    width: 100%;
    padding: 10px 12px;
    border-radius: 10px;
    border: 1px solid var(--cms-glass-border);
    background: var(--cms-navy-light);
    color: var(--cms-text);
}
.pu-field input:focus, .pu-field select:focus { outline: 2px solid var(--cms-gold); outline-offset: 1px; }
.pu-error { color: #fca5a5; font-size: 0.8rem; }
.pu-check { display: flex; align-items: flex-start; gap: 8px; margin: 8px 0 0; }
.pu-check input { margin-top: 4px; }
.pu-form-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px; }
.pu-form-actions { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 16px; }
.pu-form-actions form { display: flex; flex: 1 1 160px; min-width: 0; }
.pu-form-actions .cms-btn { flex: 1 1 140px; white-space: nowrap; }
.pu-form-actions form .cms-btn { width: 100%; }
.pu-table-wrap { max-width: 100%; overflow-x: auto; }
.pu-table { width: 100%; border-collapse: collapse; }
.pu-table th, .pu-table td { padding: 12px 14px; text-align: left; border-bottom: 1px solid var(--cms-glass-border); vertical-align: top; overflow-wrap: anywhere; }
.pu-table th { font-size: 0.72rem; letter-spacing: 0.05em; text-transform: uppercase; color: var(--cms-text-muted); font-weight: 600; }
.pu-table tbody tr:hover { background: rgba(255, 255, 255, 0.03); }
.pu-list { display: grid; gap: 12px; }
.pu-record { display: grid; gap: 12px; min-width: 0; padding: 14px; border: 1px solid var(--cms-glass-border); border-radius: 12px; background: color-mix(in srgb, var(--cms-navy-light) 65%, transparent); }
.pu-record__head { display: flex; gap: 12px; align-items: flex-start; min-width: 0; }
.pu-record__sn { flex: 0 0 auto; min-width: 2rem; padding: 4px 8px; border-radius: 8px; background: rgba(255, 255, 255, 0.06); color: var(--cms-text-muted); font-size: 0.78rem; font-weight: 700; text-align: center; }
.pu-record__id { min-width: 0; }
.pu-record__id strong, .pu-record__id p { overflow-wrap: anywhere; }
.pu-record__id p { margin: 2px 0 0; }
.pu-record__meta { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 150px), 1fr)); gap: 10px 14px; margin: 0; }
.pu-record__meta dt { font-size: 0.72rem; letter-spacing: 0.04em; text-transform: uppercase; color: var(--cms-text-muted); }
.pu-record__meta dd { margin: 2px 0 0; overflow-wrap: anywhere; }
.pu-record__meta dd .cms-badge { margin: 0 6px 6px 0; }
.pu-record__meta .cms-muted { display: block; }
.pu-row-actions { display: flex; flex-wrap: wrap; gap: 8px; }
.pu-row-actions .cms-btn { flex: 1 1 96px; white-space: nowrap; }
.pu-pager { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 12px; margin-top: 16px; }
.pu-pager .pu-note { margin: 0; }
.pu-stack, .pu-stack .cms-card, .pu-stack .cms-card__body, .pu-field, .pu-field input, .pu-field select { min-width: 0; max-width: 100%; }
body.cms-app[data-page="polling-units"] .cms-page__actions,
body.cms-app[data-page="results"] .cms-page__actions,
body.cms-app[data-page="collation"] .cms-page__actions,
body.cms-app[data-page="reports"] .cms-page__actions,
body.cms-app[data-page="live"] .cms-page__actions { width: auto; max-width: 100%; }
body.cms-app[data-page="polling-units"] .cms-page__actions .cms-btn,
body.cms-app[data-page="results"] .cms-page__actions .cms-btn,
body.cms-app[data-page="collation"] .cms-page__actions .cms-btn,
body.cms-app[data-page="reports"] .cms-page__actions .cms-btn,
body.cms-app[data-page="live"] .cms-page__actions .cms-btn { white-space: nowrap; }
.pu-pager .is-disabled { opacity: 0.45; }
.pu-stack { display: grid; gap: 20px; }
.cms-badge--ok { background: rgba(34, 197, 94, 0.15); color: #4ade80; }
.cms-badge--warn { background: color-mix(in srgb, var(--cms-gold) 16%, transparent); color: var(--cms-gold); }
.cms-badge--off { background: rgba(248, 113, 113, 0.12); color: #fca5a5; }
.cms-btn--primary { color: #111827; }
.cms-btn--ghost { background: transparent; border-color: var(--cms-glass-border); color: var(--cms-text); }
.cms-btn--danger { background: rgba(248, 113, 113, 0.12); color: #fca5a5; }
.cms-btn--sm { padding: 6px 10px; font-size: 0.78rem; }
button.cms-btn { background-color: transparent; }
button.cms-btn.cms-btn--primary { background: linear-gradient(135deg, var(--cms-gold), #c9cc05); color: #111827; }
button.cms-btn.cms-btn--danger { background: rgba(248, 113, 113, 0.12); }

@media (min-width: 1200px) {
    .pu-list { grid-template-columns: 1fr 1fr; }
}

@media (max-width: 1100px) {
    .pu-stats { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}

@media (max-width: 640px) {
    .pu-stats, .pu-form-grid { grid-template-columns: 1fr; }
    .pu-record__meta { grid-template-columns: 1fr; }
    .pu-row-actions,
    .pu-form-actions,
    .pu-pager .pu-row-actions,
    body.cms-app[data-page="polling-units"] .cms-page__actions,
    body.cms-app[data-page="results"] .cms-page__actions,
    body.cms-app[data-page="collation"] .cms-page__actions,
    body.cms-app[data-page="reports"] .cms-page__actions,
    body.cms-app[data-page="live"] .cms-page__actions { flex-direction: column; align-items: stretch; width: 100%; }
    .pu-row-actions .cms-btn,
    .pu-form-actions form,
    .pu-form-actions .cms-btn,
    body.cms-app[data-page="polling-units"] .cms-page__actions .cms-btn,
    body.cms-app[data-page="results"] .cms-page__actions .cms-btn,
    body.cms-app[data-page="collation"] .cms-page__actions .cms-btn,
    body.cms-app[data-page="reports"] .cms-page__actions .cms-btn,
    body.cms-app[data-page="live"] .cms-page__actions .cms-btn { width: 100%; }
}

.sheet-preview { width: 100%; max-height: 320px; object-fit: contain; border-radius: 10px; background: rgba(0, 0, 0, 0.25); }
.sheet-hash { font-family: ui-monospace, Consolas, monospace; font-size: 0.78rem; overflow-wrap: anywhere; word-break: break-all; }
.pu-field textarea { width: 100%; max-width: 100%; min-height: 72px; padding: 10px 12px; border: 1px solid var(--cms-glass-border); border-radius: 10px; background: var(--cms-navy-light); color: var(--cms-text); font: inherit; }

.em-offline { margin: 0 0 16px; }
.em-offline__notice { margin: 0 0 12px; font-size: 0.8rem; color: var(--cms-text-muted); }
.em-offline__record { flex-wrap: wrap; }
.em-offline__detail { margin: 6px 0 0; flex-basis: 100%; overflow-wrap: anywhere; }
.em-offline__draft { margin: 8px 0 0; font-size: 0.8rem; min-height: 1.2em; }
.em-offline__action { margin-left: 6px; min-height: 36px; }
.pu-field input[aria-invalid="true"] { border-color: #f87171; }

.em-sync { margin: 0 0 16px; }
.em-sync__bar { display: flex; flex-wrap: wrap; align-items: center; gap: 8px 14px; font-size: 0.85rem; }
.em-sync__conn { display: inline-flex; align-items: center; gap: 6px; padding: 4px 10px; border-radius: 999px; font-weight: 600; background: rgba(34, 197, 94, 0.15); color: #4ade80; }
.em-sync__conn::before { content: ""; width: 8px; height: 8px; border-radius: 50%; background: currentColor; }
.em-sync__conn[data-conn="offline"] { background: rgba(248, 113, 113, 0.12); color: #fca5a5; }
.em-sync__conn[data-conn="attention"] { background: color-mix(in srgb, var(--cms-gold) 16%, transparent); color: var(--cms-gold); }
.em-sync__stat { color: var(--cms-text-muted); }
.em-sync__stat strong { color: var(--cms-text); }
.em-sync__now { margin-left: auto; min-height: 36px; }
.em-sync__msg { margin: 10px 0 0; font-size: 0.85rem; }
.em-sync__msg:empty { display: none; }
.em-sync__msg--error { color: #fca5a5; }
.em-sync__msg--warn { color: var(--cms-gold); }
.em-sync__msg--note { color: var(--cms-text-muted); }
.em-sync__details { margin-top: 12px; }
.em-sync__details summary { cursor: pointer; min-height: 32px; font-size: 0.85rem; }
.em-sync__items { list-style: none; margin: 8px 0 0; padding: 0; display: grid; gap: 8px; }
.em-sync__item { padding: 10px 12px; border: 1px solid var(--cms-glass-border); border-radius: 10px; }
.em-sync__item-head { display: flex; flex-wrap: wrap; align-items: center; gap: 6px 10px; }
.em-sync__when { color: var(--cms-text-muted); font-size: 0.8rem; }
.em-sync__detail { margin: 6px 0 0; font-size: 0.8rem; color: var(--cms-text-muted); overflow-wrap: anywhere; }
.em-sync__actions { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 8px; }
.em-sync__actions .cms-btn { min-height: 36px; }
.em-sync__offline { margin: 10px 0 0; font-size: 0.8rem; color: var(--cms-text-muted); }
.em-sync__offline:empty { display: none; }
.em-sync__notice { margin: 10px 0 0; font-size: 0.75rem; color: var(--cms-text-muted); }
@media (max-width: 600px) {
    .em-sync__now { margin-left: 0; width: 100%; }
}

@media (prefers-reduced-motion: reduce) {
    *, *::before, *::after {
        animation: none !important;
        transition: none !important;
    }
}
CSS;
}

