<?php

return [

    'php_versions' => ['8.0', '8.1', '8.2', '8.3', '8.4', '8.5'],

    'node_versions' => ['20', '22', '24'],

    'sites_root' => env('LARAVELIFY_SITES_ROOT', '/var/www'),

    // Where the global file manager starts. Empty uses sites_root. Set to / to browse the whole server.
    'files_root' => env('LARAVELIFY_FILES_ROOT'),

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
