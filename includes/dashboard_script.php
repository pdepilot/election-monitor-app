<?php
declare(strict_types=1);

function dashboard_js(): string
{
    return <<<'JS'
(function () {
    var sidebar = document.getElementById('cmsSidebar');
    var collapseBtn = document.getElementById('cmsSidebarCollapse');
    var mobileBtn = document.getElementById('cmsMobileMenu');
    var overlay = document.getElementById('cmsSidebarOverlay');
    var profile = document.getElementById('cmsProfile');
    var profileTrigger = document.getElementById('cmsProfileTrigger');
    var profileDropdown = document.getElementById('cmsProfileDropdown');
    var notifyBtn = document.getElementById('cmsNotifyBtn');
    var notifyPanel = document.getElementById('cmsNotifyPanel');
    var themeSelect = document.getElementById('cmsThemeSelect');
    var modeSelect = document.getElementById('cmsModeSelect');
    var search = document.getElementById('cmsGlobalSearch');

    function preference(name, value) {
        var path = document.body.getAttribute('data-cookie-path') || '/';
        document.cookie = name + '=' + encodeURIComponent(value) + '; path=' + path + '; max-age=31536000; samesite=lax';
    }

    function closePanels() {
        if (profile) {
            profile.classList.remove('is-open');
        }
        if (profileDropdown) {
            profileDropdown.classList.remove('is-open');
        }
        if (profileTrigger) {
            profileTrigger.setAttribute('aria-expanded', 'false');
        }
        if (notifyPanel) {
            notifyPanel.classList.remove('is-open');
        }
        if (notifyBtn) {
            notifyBtn.setAttribute('aria-expanded', 'false');
        }
    }

    function closeMobileNav() {
        if (!sidebar || !overlay) {
            return;
        }
        sidebar.classList.remove('is-mobile-open');
        overlay.hidden = true;
    }

    if (collapseBtn && sidebar) {
        collapseBtn.addEventListener('click', function () {
            sidebar.classList.toggle('is-collapsed');
            document.body.classList.toggle('sidebar-collapsed');
            var icon = collapseBtn.querySelector('i');
            if (icon) {
                icon.className = sidebar.classList.contains('is-collapsed') ? 'fas fa-angles-right' : 'fas fa-angles-left';
            }
        });
    }

    if (mobileBtn && sidebar && overlay) {
        mobileBtn.addEventListener('click', function () {
            sidebar.classList.add('is-mobile-open');
            overlay.hidden = false;
        });
        overlay.addEventListener('click', closeMobileNav);
    }

    document.querySelectorAll('.cms-nav-group__label').forEach(function (button) {
        button.addEventListener('click', function () {
            var group = button.closest('.cms-nav-group');
            if (!group) {
                return;
            }
            var collapsed = group.classList.toggle('is-collapsed');
            button.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
        });
    });

    if (profileTrigger && profile && profileDropdown) {
        profileTrigger.addEventListener('click', function (event) {
            event.stopPropagation();
            var open = !profile.classList.contains('is-open');
            closePanels();
            if (open) {
                profile.classList.add('is-open');
                profileDropdown.classList.add('is-open');
                profileTrigger.setAttribute('aria-expanded', 'true');
            }
        });
    }

    if (notifyBtn && notifyPanel) {
        notifyBtn.addEventListener('click', function (event) {
            event.stopPropagation();
            var open = !notifyPanel.classList.contains('is-open');
            closePanels();
            if (open) {
                notifyPanel.classList.add('is-open');
                notifyBtn.setAttribute('aria-expanded', 'true');
            }
        });
    }

    document.addEventListener('click', function (event) {
        if (event.target instanceof Element && event.target.closest('.cms-dropdown, .cms-notify-panel, #cmsProfileTrigger, #cmsNotifyBtn')) {
            return;
        }
        closePanels();
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            closePanels();
            closeMobileNav();
        }
    });

    if (themeSelect) {
        themeSelect.addEventListener('change', function () {
            document.body.setAttribute('data-theme', themeSelect.value);
            preference('em_theme', themeSelect.value);
        });
    }

    if (modeSelect) {
        modeSelect.addEventListener('change', function () {
            document.body.setAttribute('data-mode', modeSelect.value);
            document.body.classList.toggle('dark', modeSelect.value === 'dark');
            preference('em_mode', modeSelect.value);
        });
    }

    if (search) {
        search.addEventListener('input', function () {
            var query = search.value.trim().toLowerCase();
            document.querySelectorAll('.cms-nav-item').forEach(function (item) {
                var text = (item.textContent || '').toLowerCase();
                item.hidden = query !== '' && text.indexOf(query) === -1;
            });
        });
    }
})();
JS;
}

