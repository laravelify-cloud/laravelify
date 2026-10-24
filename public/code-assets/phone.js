/*
 * VS Code (code-server) on phones, added by nginx to the editor's page: one part at a time.
 * Opening a file from the side bar (Explorer, Search, Source Control) closes the side bar, so
 * the file shows full screen; the activity bar icon opens it again. phone.css does the layout.
 */
(function () {
    // Touch screens draw the whole editor larger (rows, text, icons): VS Code's lists have a
    // fixed row height, so the page is laid out narrower and scaled up to the screen instead.
    function scaleUp() {
        var meta = document.querySelector('meta[name="viewport"]');

        if (!meta || !window.matchMedia('(pointer: coarse)').matches) return;

        var portrait = window.matchMedia('(orientation: portrait)').matches;
        var width = portrait ? Math.min(screen.width, screen.height) : Math.max(screen.width, screen.height);
        var scale = Math.min(screen.width, screen.height) <= 600 ? 1.25 : 1.15;
        meta.setAttribute('content', 'width=' + Math.round(width / scale) + ', initial-scale=' + scale + ', viewport-fit=cover');
    }

    scaleUp();
    window.matchMedia('(orientation: portrait)').addEventListener('change', scaleUp);

    var narrow = window.matchMedia('(max-width: 820px)');

    function activityItem() {
        return document.querySelector('.part.activitybar .action-item.checked .action-label');
    }

    function sidebarOpen() {
        var sidebar = document.querySelector('.part.sidebar');

        return !!sidebar && !sidebar.classList.contains('hidden') && sidebar.offsetWidth > 0;
    }

    // The fixed side bar sits between the title bar and the status bar, whatever their height.
    function measure() {
        var title = document.querySelector('.part.titlebar');
        var status = document.querySelector('.part.statusbar');
        var root = document.documentElement.style;
        root.setProperty('--lfy-top', (title ? title.offsetHeight : 35) + 'px');
        root.setProperty('--lfy-bottom', (status ? status.offsetHeight : 22) + 'px');
        document.body.classList.toggle('lfy-phone-sidebar-open', narrow.matches && sidebarOpen());
        drawers();
        closeSecondary();
        firstView();
        titlebarButtons();
        submenuBackRows();
        editorChanged();
    }

    // Another editor opened (from the command palette, a link, Go to File...): it shows, so the
    // full-screen side bar or panel in front of it closes. The window title names the editor.
    var lastTitle = null;

    function editorChanged() {
        var title = document.title;

        if (lastTitle === null || title === lastTitle) {
            lastTitle = title;

            return;
        }

        lastTitle = title;

        if (!narrow.matches) return;

        var item = activityItem();

        if (item && sidebarOpen() && document.body.classList.contains('lfy-sidebar-drawer')) item.click();
        closePanel();
    }

    function button(icon, label, onClick) {
        var b = document.createElement('button');
        b.type = 'button';
        b.title = label;
        b.setAttribute('aria-label', label);
        b.innerHTML = '<span class="codicon codicon-' + icon + '"></span>';
        b.addEventListener('click', onClick);

        return b;
    }

    // Every side view but the Explorer, and the panel, cover the whole screen (phone.css); the
    // side views get an ✕ that closes them like their activity bar icon.
    function drawers() {
        var item = activityItem();
        var label = (item && item.getAttribute('aria-label')) || '';
        var panel = document.querySelector('.part.panel');

        document.body.classList.toggle('lfy-sidebar-drawer', narrow.matches && sidebarOpen() && !/^Explorer/.test(label));
        document.body.classList.toggle('lfy-panel-drawer', narrow.matches && !!panel && !panel.classList.contains('hidden') && panel.offsetWidth > 0);

        panelTitle();

        var title = document.querySelector('.part.sidebar > .composite.title');

        if (title && !title.querySelector('.lfy-drawer-close')) {
            var close = document.createElement('span');
            close.className = 'lfy-drawer-close';
            close.setAttribute('role', 'button');
            close.setAttribute('aria-label', 'Close');
            close.innerHTML = '<span class="codicon codicon-close"></span>';
            close.addEventListener('click', function () {
                var open = activityItem();

                if (open && sidebarOpen()) open.click();
            });
            title.appendChild(close);
        }
    }

    // The panel's tabs (Problems, Output, Terminal...) do not fit a phone: VS Code folds them into
    // "···". Its title says which one is open instead, and opens that list to switch.
    var PANEL_NAMES = {
        'workbench.panel.markers': 'Problems',
        'workbench.panel.output': 'Output',
        'workbench.panel.repl': 'Debug Console',
        'terminal': 'Terminal',
        'workbench.panel.testResults': 'Test Results',
    };

    function panelTitle() {
        var panel = document.querySelector('.part.panel:not(.hidden)');
        var bar = panel && panel.querySelector(':scope > .composite.title');

        if (!narrow.matches || !bar) return;

        var open = [].slice.call(panel.querySelectorAll(':scope > .content > .composite')).filter(function (view) { return view.offsetWidth > 0; })[0];
        var id = open ? open.id.replace(/^workbench\.panel\.terminal$/, 'terminal') : '';
        var name = PANEL_NAMES[id] || (/forwardedPorts/i.test(id) ? 'Ports' : /terminal/i.test(id) ? 'Terminal' : 'Panel');
        var button = bar.querySelector(':scope > .lfy-panel-title');

        if (!button) {
            button = document.createElement('button');
            button.type = 'button';
            button.className = 'lfy-panel-title';
            button.setAttribute('aria-label', 'Switch panel');
            button.innerHTML = '<span></span><span class="codicon codicon-chevron-down"></span>';
            button.addEventListener('click', function () {
                var more = panel.querySelector('.composite-bar .action-label[aria-label="Additional Views"]') || panel.querySelector('.composite-bar .action-label');

                if (more) {
                    ['mousedown', 'mouseup', 'click'].forEach(function (type) { more.dispatchEvent(new MouseEvent(type, { bubbles: true, button: 0 })); });
                }
            });
            bar.insertBefore(button, bar.firstChild);
        }

        if (button.firstChild.textContent !== name) button.firstChild.textContent = name;
    }

    // A reload button on the left of the title bar.
    function titlebarButtons() {
        var left = document.querySelector('.part.titlebar .titlebar-left');

        if (left && !left.querySelector('.lfy-titlebar-buttons')) {
            var reload = document.createElement('div');
            reload.className = 'lfy-titlebar-buttons';
            reload.appendChild(button('refresh', 'Reload window', function () { location.reload(); }));
            left.appendChild(reload);
        }
    }

    // Slide out, then let VS Code close it: the side bar to the left, the panel down.
    var closing = false;

    function slideOut(view, transform, done) {
        if (!view || !view.animate) return done();

        closing = true;
        var slide = view.animate([{ transform: 'none' }, { transform: transform }], { duration: 180, easing: 'ease-in', fill: 'forwards' });
        var finished = false;
        var finish = function () {
            if (finished) return;

            finished = true;
            done();
            closing = false;
            setTimeout(function () { slide.cancel(); }, 50);
        };

        slide.onfinish = finish;
        // A page in the background does not run animations: close anyway.
        setTimeout(finish, 260);
    }

    document.addEventListener('click', function (event) {
        if (!narrow.matches || closing || !event.target.closest) return;

        var target = event.target.closest('.action-label');

        if (!target) return;

        var sidebarToggle = target.closest('.part.activitybar .action-item.checked') && sidebarOpen();
        var panelClose = target.matches('.part.panel [aria-label^="Hide Panel"], .part.panel [aria-label^="Close Panel"], .part.titlebar [aria-label^="Toggle Panel"]')
            && document.querySelector('.part.panel:not(.hidden)');

        if (!sidebarToggle && !panelClose) return;

        event.preventDefault();
        event.stopImmediatePropagation();

        var part = document.querySelector(sidebarToggle ? '.part.sidebar' : '.part.panel');
        slideOut(part && part.parentElement, sidebarToggle ? 'translateX(-100%)' : 'translateY(100%)', function () { target.click(); });
    }, true);

    // A submenu covers its menu on a phone: a Back row on top hides it again (VS Code's own Left
    // Arrow does not, as focus stays on the menu); tapping a menu entry shows its submenu again.
    document.addEventListener('click', function (event) {
        if (event.target.closest && event.target.closest('.monaco-submenu-item')) {
            document.querySelectorAll('.monaco-submenu').forEach(function (submenu) { submenu.style.display = ''; });
        }
    }, true);

    // In a drawer menu only a tap opens a submenu: VS Code opens one when the pointer rests on its
    // entry, which a mouse (or a phone's emulated hover) does by passing over it.
    ['mouseover', 'mouseenter', 'mousemove', 'mouseout', 'mouseleave', 'pointerover', 'pointerenter', 'pointermove', 'pointerout', 'pointerleave'].forEach(function (type) {
        window.addEventListener(type, function (event) {
            if (narrow.matches && event.target.closest && event.target.closest('.monaco-menu-container')) {
                event.stopImmediatePropagation();
            }
        }, true);
    });

    // VS Code closes the whole menu on a press outside its items, listening on the window: this
    // script runs first, so its window listeners keep the Back row's presses to itself.
    ['pointerdown', 'pointerup', 'mousedown', 'mouseup', 'touchstart', 'touchend', 'click'].forEach(function (type) {
        window.addEventListener(type, function (event) {
            var row = event.target.closest && event.target.closest('.lfy-menu-back, .lfy-menu-head');

            if (!row) return;

            event.stopImmediatePropagation();
            if (type !== 'touchstart' && type !== 'touchend') event.preventDefault();
            if (type !== 'click') return;

            // Close: a press outside the menu (on the editor) closes it, as on a computer; the ☰
            // menu also closes when focus leaves it.
            if (row.classList.contains('lfy-menu-head')) {
                if (!event.target.closest('.lfy-menu-close')) return;

                var menu = row.parentElement;
                var slide = menu.animate([{ transform: 'none' }, { transform: 'translateX(-100%)' }], { duration: 180, easing: 'ease-in', fill: 'forwards' });
                slide.onfinish = function () {
                        // The right-click menu's element is used again next time: drop the slide once it is closed.
                        setTimeout(function () { slide.cancel(); }, 50);
                        var outside = document.querySelector('.part.editor') || document.body;
                        ['pointerdown', 'mousedown'].forEach(function (name) {
                            outside.dispatchEvent(new MouseEvent(name, { bubbles: true, cancelable: true }));
                        });
                        if (document.activeElement) document.activeElement.blur();
                    };

                return;
            }

            // Back: focus goes to the submenu's own entry first, then the submenu slides away.
            var submenu = row.parentElement;
            var entry = submenu.parentElement && submenu.parentElement.querySelector(':scope > .action-menu-item');

            if (entry) entry.focus();
            submenu.animate([{ transform: 'none' }, { transform: 'translateX(100%)' }], { duration: 180, easing: 'ease-in' })
                .onfinish = function () { submenu.style.display = 'none'; };
        }, true);
    });

    function submenuBackRows() {
        if (!narrow.matches) return;

        document.querySelectorAll('.monaco-menu-container:not(.monaco-submenu)').forEach(function (menu) {
            if (menu.querySelector(':scope > .lfy-menu-head')) return;

            var head = document.createElement('div');
            head.className = 'lfy-menu-head';
            head.innerHTML = '<span>Menu</span><span class="lfy-menu-close" role="button" aria-label="Close menu"><span class="codicon codicon-close"></span></span>';
            menu.insertBefore(head, menu.firstChild);
        });

        document.querySelectorAll('.monaco-submenu').forEach(function (submenu) {
            if (submenu.querySelector(':scope > .lfy-menu-back')) return;

            var back = document.createElement('div');
            back.className = 'lfy-menu-back';
            var entry = submenu.parentElement && submenu.parentElement.querySelector(':scope > .action-menu-item .action-label');
            var name = document.createElement('span');
            name.textContent = (entry && entry.textContent.trim()) || 'Back';
            back.innerHTML = '<span class="codicon codicon-chevron-left"></span>';
            back.appendChild(name);
            submenu.insertBefore(back, submenu.firstChild);
        });
    }

    // The secondary side bar (VS Code's own Chat, which code-server cannot use) would leave the
    // editor a strip on a phone: close it once when the editor opens narrow.
    var secondaryClosed = false;

    function closeSecondary() {
        if (secondaryClosed || !narrow.matches) return;

        var bar = document.querySelector('.part.auxiliarybar');

        if (!bar || bar.classList.contains('hidden') || bar.offsetWidth === 0) return;

        var toggle = document.querySelector('.part.titlebar .action-label[aria-label*="Secondary Side Bar"]');

        if (toggle) {
            secondaryClosed = true;
            toggle.click();
        }
    }

    // The editor comes first when VS Code opens on a phone: a panel left open last time closes.
    var panelChecked = false;

    function firstView() {
        if (panelChecked || !narrow.matches || !document.querySelector('.part.titlebar .action-label[aria-label^="Toggle Panel"]')) return;

        panelChecked = true;
        closePanel();
    }

    // The panel (terminal) covers the editor on a phone: opening a file closes it again.
    function closePanel() {
        var panel = document.querySelector('.part.panel');

        if (!panel || panel.classList.contains('hidden') || panel.offsetWidth === 0) return;

        var toggle = document.querySelector('.part.titlebar .action-label[aria-label^="Toggle Panel"]');

        if (toggle) toggle.click();
    }

    document.addEventListener('click', function (event) {
        if (!narrow.matches) return;

        var row = event.target.closest('.part.sidebar .monaco-list-row');

        // A folder only opens or closes; a file (or a search result) opens in the editor.
        if (!row || row.querySelector('.monaco-tl-twistie.collapsible')) return;
        if (!row.closest('.explorer-folders-view, .search-view, .scm-view, .outline-pane, .open-editors')) return;

        setTimeout(function () {
            var item = activityItem();

            if (item && sidebarOpen()) item.click();
            closePanel();
        }, 200);
    }, true);

    // VS Code changes classes all the time: measure at most once a frame.
    var queued = false;
    new MutationObserver(function () {
        if (queued) return;
        queued = true;
        var run = function () { queued = false; measure(); };
        // A hidden page gets no animation frames.
        if (document.visibilityState === 'hidden') setTimeout(run, 16); else requestAnimationFrame(run);
    }).observe(document.documentElement, { subtree: true, childList: true, attributes: true, attributeFilter: ['class'] });
    window.addEventListener('resize', measure);
    narrow.addEventListener('change', measure);
    document.addEventListener('DOMContentLoaded', measure);
})();
