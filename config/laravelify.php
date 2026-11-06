<?php

return [

    // Websites can use any of these; a missing one is installed when a website needs it.
    'php_versions' => ['7.4', '8.0', '8.1', '8.2', '8.3', '8.4', '8.5'],

    // The Node.js majors offered first; any release from 14 on (like 19.0.0) works too.
    'node_versions' => ['18', '20', '22', '24'],

    // GlitchTip was picked when the server was installed: set up once the admin exists.
    'install_glitchtip' => (bool) env('LARAVELIFY_INSTALL_GLITCHTIP', false),

    // The database engines websites can use, as the installer set up this server: mysql (MySQL or
    // MariaDB), postgresql, mongodb, and sqlite (a file, no server). Servers installed before
    // this setting existed have MySQL.
    'databases' => array_values(array_filter(preg_split('/[\s,]+/', (string) env('LARAVELIFY_DATABASES', 'mysql sqlite')) ?: [])),

    // The logins the panel makes websites' databases with. MySQL and MariaDB use the panel's own
    // database login (DB_USERNAME / DB_PASSWORD).
    // The installer signs in as postgres and MongoDB's admin; a server added later from the panel
    // gets its own login, laravelify.
    'postgres_user' => env('LARAVELIFY_POSTGRES_USER', 'postgres'),

    'postgres_password' => env('LARAVELIFY_POSTGRES_PASSWORD'),

    'mongodb_user' => env('LARAVELIFY_MONGODB_USER', 'admin'),

    'mongodb_password' => env('LARAVELIFY_MONGODB_PASSWORD'),

    'sites_root' => env('LARAVELIFY_SITES_ROOT', '/var/www'),

    // Where the global file manager starts. Empty uses sites_root. Set to / to browse the whole server.
    'files_root' => env('LARAVELIFY_FILES_ROOT'),

    // The panel runs as its own user (laravelify). Websites, their workers, deploys, and the
    // terminal run as the site's own user (lfy-<slug>), or this shared user for sites that have
    // not been moved to their own user yet. Neither can read the panel.
    'site_user' => env('LARAVELIFY_SITE_USER', 'www-data'),

    // HOME and the Composer, npm, and Corepack caches of deploy commands (owned by the site user).
    'site_home' => env('LARAVELIFY_SITE_HOME', '/var/lib/laravelify/home'),

    // The file manager's trash (owned by the site user, which moves files in and out).
    'trash_root' => env('LARAVELIFY_TRASH_ROOT', '/var/lib/laravelify/trash'),

    // Each website's own user (lfy-<slug>) keeps its HOME, caches, trash, and temp files in
    // <site_users_root>/<slug> (owned by that user).
    'site_users_root' => env('LARAVELIFY_SITE_USERS_ROOT', '/var/lib/laravelify/sites'),

    // Supervisor program logs, one folder per site (root-owned, readable by the panel).
    'site_log_dir' => env('LARAVELIFY_SITE_LOG_DIR', '/var/log/laravelify/sites'),

    'node_port_start' => 3000,

    'http_port_start' => 8081,

    'reverb_port_start' => 8080,

    'host_commands' => env('LARAVELIFY_HOST_COMMANDS'),

    // This server's public IP, for links to sites on a port. Found by itself when empty.
    'public_ip' => env('LARAVELIFY_PUBLIC_IP'),

    // Written by the installer: /setup (creating the superadmin) needs ?token=... until it is done.
    'setup_token' => env('LARAVELIFY_SETUP_TOKEN'),

    // Shown in the sidebar (Help). Set your own after publishing the docs and the repository.
    'docs_url' => env('LARAVELIFY_DOCS_URL', 'https://docs.laravelify.cloud'),

    'repository_url' => env('LARAVELIFY_REPOSITORY_URL', 'https://github.com/laravelify-cloud/laravelify'),

    // The release this copy came from. GitHub releases write the VERSION file.
    'version' => trim((string) @file_get_contents(base_path('VERSION'))) ?: 'dev',

    // nginx writes one JSON line per request here for the Monitoring tab (host-apply.sh).
    'metrics_log_dir' => env('LARAVELIFY_METRICS_LOG_DIR', '/var/log/laravelify'),

];
