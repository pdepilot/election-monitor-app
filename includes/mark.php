<?php
declare(strict_types=1);

function portal_mark_svg(int $size = 42): void
{
    $size = max(16, min($size, 128));
    ?>
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 80 80" width="<?= $size ?>" height="<?= $size ?>" aria-hidden="true">
    <rect width="80" height="80" rx="18" fill="#0e2a4a"/>
    <rect x="18" y="20" width="44" height="40" rx="5" fill="#ffffff"/>
    <rect x="24" y="28" width="32" height="4" rx="2" fill="#1d6b45"/>
    <rect x="24" y="37" width="24" height="3" rx="1.5" fill="#c5d0dc"/>
    <rect x="24" y="44" width="18" height="3" rx="1.5" fill="#c5d0dc"/>
    <circle cx="52" cy="48" r="9" fill="#1d6b45"/>
    <path d="M48.2 48.1 l2.4 2.4 5-5.2" fill="none" stroke="#ffffff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
</svg>
    <?php
}

function portal_favicon_href(): string
{
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 80 80"><rect width="80" height="80" rx="18" fill="#0e2a4a"/><rect x="18" y="20" width="44" height="40" rx="5" fill="#fff"/><rect x="24" y="28" width="32" height="4" rx="2" fill="#1d6b45"/><circle cx="52" cy="48" r="9" fill="#1d6b45"/></svg>';

    return 'data:image/svg+xml,' . rawurlencode($svg);
}
