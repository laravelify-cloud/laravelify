/*
 * Laravelify's additions to GlitchTip (added to its pages by nginx, see scripts/host-apply.sh
 * glitchtip-nginx). The panel manages GlitchTip's organization, admin user, password and API
 * token, so the menus that change them (Organization, Profile) and the GlitchTip project's
 * Support links are hidden, their pages lead back to the issues, and the pages that stay empty
 * until an app sends more than errors (Performance, Logs, Releases) say how to fill them.
 */
(function () {
    'use strict';

    var base = '/glitchtip';
    var home = base + '/laravelify/issues';

    // Sidebar menus, by the Material icon name their button starts with.
    var hiddenMenus = ['account_tree', 'account_circle', 'help'];

    // Pages the panel manages instead (paths after /glitchtip).
    var managed = [/^\/profile(\/|$)/, /^\/organizations\/new/, /^\/[^/]+\/settings(\/|$)/, /^\/accept\//];

    var tips = [
        {
            match: /^\/[^/]+\/performance(\/|$)/,
            title: 'Performance: how fast pages and API calls are',
            body: 'Each request or page load becomes a transaction with its duration, grouped by route, so slow ones stand out. It stays empty until the app sends traces: add <code>tracesSampleRate: 0.1</code> (one request in ten) to <code>Sentry.init({ ... })</code>. Browser apps also add <code>integrations: [Sentry.browserTracingIntegration()]</code>. Laravel: set <code>SENTRY_TRACES_SAMPLE_RATE=0.1</code> in the Environment tab. Then deploy.'
        },
        {
            match: /^\/[^/]+\/logs(\/|$)/,
            title: 'Logs: your apps\' log lines, next to their errors',
            body: 'Shows the log lines an app sends with Sentry\'s logger, searchable and linked to the errors around them. Add <code>enableLogs: true</code> to <code>Sentry.init({ ... })</code>, then write lines with <code>Sentry.logger.info(\'Order paid\', { orderId })</code> (also <code>warn</code> and <code>error</code>). Then deploy. Server logs stay in the panel\'s Logs tab too.'
        },
        {
            match: /^\/[^/]+\/releases(\/|$)/,
            title: 'Releases: which deploy brought an error in, and which fixed it',
            body: 'A release is one version of an app\'s code. With one set, each error shows the release it first and last happened in. Add <code>release: \'&lt;git commit&gt;\'</code> to <code>Sentry.init({ ... })</code>, or set <code>SENTRY_RELEASE</code> in the Environment tab (Node and Laravel read it). Some SDKs (Nuxt, Next.js) set it from the build by themselves, which is the release you may already see here.'
        }
    ];

    function path() {
        var current = location.pathname;

        return current.indexOf(base) === 0 ? current.slice(base.length) || '/' : current;
    }

    function guard() {
        var current = path();

        for (var i = 0; i < managed.length; i++) {
            if (managed[i].test(current)) {
                location.replace(home);

                return true;
            }
        }

        return false;
    }

    function hideMenus() {
        var buttons = document.querySelectorAll('mat-sidenav button, .mat-drawer button, mat-nav-list button');

        for (var i = 0; i < buttons.length; i++) {
            var text = (buttons[i].textContent || '').trim();

            for (var j = 0; j < hiddenMenus.length; j++) {
                if (text.indexOf(hiddenMenus[j]) === 0 && !buttons[i].hasAttribute('data-laravelify-hidden')) {
                    buttons[i].setAttribute('data-laravelify-hidden', '');
                }
            }
        }

        // Project and organization settings (in the Projects list, for one).
        var links = document.querySelectorAll('a[href*="/settings"]');

        for (var k = 0; k < links.length; k++) {
            if (!links[k].hasAttribute('data-laravelify-hidden')) {
                links[k].setAttribute('data-laravelify-hidden', '');
            }
        }
    }

    function backLink() {
        var list = document.querySelector('mat-sidenav mat-nav-list, .mat-drawer mat-nav-list');

        if (!list || document.getElementById('laravelify-back')) {
            return;
        }

        var link = document.createElement('a');
        link.id = 'laravelify-back';
        link.href = '/';
        link.className = 'laravelify-back';
        link.innerHTML = '<span class="laravelify-back-arrow" aria-hidden="true">&larr;</span> Back to Laravelify';
        list.appendChild(link);
    }

    function tip() {
        var current = path();
        var found = null;

        for (var i = 0; i < tips.length; i++) {
            if (tips[i].match.test(current)) {
                found = tips[i];
            }
        }

        var shown = document.getElementById('laravelify-tip');

        if (shown && (!found || shown.getAttribute('data-title') !== found.title)) {
            shown.remove();
            shown = null;
        }

        if (!found || shown) {
            return;
        }

        var content = document.querySelector('mat-sidenav-content, .mat-drawer-content, main');

        if (!content) {
            return;
        }

        var box = document.createElement('aside');
        box.id = 'laravelify-tip';
        box.className = 'laravelify-tip';
        box.setAttribute('data-title', found.title);
        box.innerHTML = '<strong>' + found.title + '</strong><p>' + found.body + '</p>';
        content.insertBefore(box, content.firstChild);
    }

    function update() {
        if (guard()) {
            return;
        }

        hideMenus();
        backLink();
        tip();
    }

    // GlitchTip is a single-page app: follow its page changes too.
    ['pushState', 'replaceState'].forEach(function (name) {
        var original = history[name];

        history[name] = function () {
            var result = original.apply(this, arguments);
            setTimeout(update, 0);

            return result;
        };
    });
    window.addEventListener('popstate', update);

    var scheduled = false;
    new MutationObserver(function () {
        if (scheduled) {
            return;
        }

        scheduled = true;
        requestAnimationFrame(function () {
            scheduled = false;
            update();
        });
    }).observe(document.documentElement, { childList: true, subtree: true });

    update();
})();
