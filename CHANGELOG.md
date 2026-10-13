# Changelog

All notable changes are listed here. Releases follow [semantic versioning](https://semver.org).

## [0.2.0-beta.2] - 2026-10-13

- Installer: running it on a server that already has Laravelify now only updates Laravelify. It downloads the release, shows the installed and new version, asks "Update Laravelify only? [Y/n]", then runs the update (files, migrations, worker restart). PHP, MySQL, phpMyAdmin, nginx, Node, pm2, and the other packages are not checked or reinstalled. Answer `n`, or set `LARAVELIFY_REINSTALL=1`, for a full repair install.
- A full install also skips what is already there: the PHP repository, PHP versions with all extensions, MySQL, phpMyAdmin, pm2, `n`, and Node 20/22/24.
- Update: a downloaded release no longer fails the "stale build" check.

## [0.2.0-beta.1] - 2026-10-13

- Deploy more than Laravel and Next.js. Adding a website now offers Backend (PHP: Laravel, plain PHP; TypeScript / JavaScript: Express, NestJS, Node.js), Frontend (React, Vue.js, Angular, Svelte, Astro, Static HTML), and Full-stack (Next.js, Nuxt, SvelteKit, Laravel + Livewire, Laravel + Inertia).
- Three ways to run a site: PHP-FPM, a Node server under PM2, or built files served by nginx (with client-side route fallback for single-page apps).
- Per-site install, build, and start commands, and an output folder for front-end builds (detected after the build when empty, including Angular's `dist/<project>/browser`). Plain PHP sites choose their web root.
- Sites without a process (plain PHP, front-end builds) have no Processes tab, no Supervisor files, and no PM2 checks.
- Run `sudo bash /opt/laravelify/scripts/update.sh` to get the new database columns and the updated server script.

## [0.1.0-beta.3] - 2026-10-13

- Installer: running it again shows the settings from the previous install (passwords masked) and asks "Use these settings? [Y/n]", so a repair or retry needs no retyping. Answers are kept root-only in `/etc/laravelify/install.env` (removed by the uninstaller); older installs are read back from their `.env`, nginx, and tunnel files.
- `https://cdn.laravelify.cloud/install.sh` is now a small bootstrap that always runs the latest release's installer, so new releases never need a CDN cache purge.

## [0.1.0-beta.2] - 2026-10-13

- Installer: `curl … | sudo bash` stopped with "BASH_SOURCE[0]: unbound variable" while configuring phpMyAdmin. The panel is now downloaded first, before any package installs, so its helper scripts are always there and a bad download fails right away.
- Installer: install `debconf-utils` so phpMyAdmin's answers are loaded without a warning.

## [0.1.0-beta.1] - 2026-10-13

First public beta.

- One-command installer for Ubuntu 22.04, 24.04, and 26.04, and one-command updates from GitHub releases.
- Laravel sites (PHP 8.0–8.5, Supervisor workers, Reverb, MySQL databases) and Next.js sites (standalone builds under PM2, npm/pnpm/Yarn).
- GitHub App and token sources, push-to-deploy webhooks, live deploy logs.
- Cloudflare tunnels, editable nginx, Supervisor, and PM2 files.
- Website monitoring: live request feed, charts, uptime, top routes, countries, grouped Laravel issues, attack detection, IP blocking, heartbeats.
- Server monitoring: live CPU, memory, load, network, services, top processes.
- Telegram alerts for deploys, downtime, workers, errors, attacks, disk, and patches.
- Security tab: search engine and crawler blocking, basic auth, blocked IPs.
- One admin with required two-factor authentication; secrets revealed only with the code.
- File manager, terminal with autocomplete, logs, health checks, phpMyAdmin one-click sign-in.
- Timezone and date format settings, searchable dropdowns.
