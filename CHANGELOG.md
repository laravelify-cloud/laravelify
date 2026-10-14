# Changelog

All notable changes are listed here. Releases follow [semantic versioning](https://semver.org).

## [0.3.0-beta.3] - 2026-10-14

- **Full backup:** one button backs up every database, or every website, now. It uses a "Full backup" schedule that only runs by hand.
- **Every website** option for file backups: one archive per website (including ones added later), skipping what a deploy rebuilds by site type: node_modules and .git everywhere, vendor and Laravel caches and logs, dist, build, .next, .nuxt, .output, .svelte-kit, .angular, .astro. Adding a website folder by hand skips the same folders.
- **Bulk actions:** run, pause, resume, or delete several schedules; delete several backups from the bucket.
- **Progress:** a running backup shows its step (dumping, packing, uploading) and the upload percent with a progress bar.
- **Telegram:** one message per finished schedule, with a 24-hour download link for each backup (signed with AWS Signature V4, so it works with every S3 provider; the keys are never in the link). "A backup finished" is now on by default.
- New schedule frequency: "Only when started by hand".

## [0.3.0-beta.2] - 2026-10-13

- **Backups, Run now:** each database shows up in the list at once as Waiting, and the page updates by itself until it is finished. A schedule's menu can cancel runs that wait for a stopped queue worker.
- **Restore wizard:** Restore at the top of the page goes database, then backup, then whole database or searchable tables (with row counts). File backups are browsed like folders and any folders or files can be ticked.
- **Look inside a backup:** tables (25 per page, searchable, with CREATE TABLE), stored procedures, functions, triggers, and events with their SQL. Folders and files for file backups.
- **Download:** pick tables (all or some), procedures, functions, triggers, and events, and get one runnable .sql file; or download the whole backup file. Prepared on the server, then downloaded by the browser.
- **File backup picker** looks and works like the file manager: tick folders and files, then Add to backup or Skip; every row shows Included or Skipped.
- **Compact actions:** Run now plus a menu on schedules; look inside, download, restore, and a menu on backups.
- **File manager:** zipping, extracting, folder downloads (.zip), and big copies and deletes run in the queue with a progress list, so selecting everything and pressing Compress no longer leaves the page doing nothing. A message tells when each job is done, and folder downloads start by themselves.

## [0.3.0-beta.1] - 2026-10-13

- **S3 backups.** New Backups section in the sidebar: back up databases and folders to your own S3 bucket (Amazon S3, DigitalOcean Spaces, Cloudflare R2, Backblaze B2, Wasabi, MinIO) with `rclone`, which the installer and the update add. Keys are stored encrypted, and saving a storage tests it with a small file.
- **Database backups:** one dump per database, for all databases or the ones you pick. Run every few minutes, hourly, every few hours, daily, weekly, or on a custom cron. Keep the last N backups and/or delete after N days. Two shortcuts create "all databases every hour" and "every day".
- **File backups:** browse and pick any folders under `/var/www`, exclude folders (`node_modules`, `vendor`, `.git`, `storage/logs`, or any pattern).
- **Restore:** a whole database (into the same or a new name), only some tables, everything in a file backup, or one folder, into a new restore folder or the original place. Runs are logged, and Telegram can tell you when a backup or restore fails.
- All icons are now Material Design icons, so none go missing or look broken, and the live monitoring Pause button shows a pause icon.

## [0.2.0-beta.6] - 2026-10-13

- Add website: choose **Example project** instead of a Git repository to get a small working app for the framework you picked (all 14 types). It is copied into its own local git repository, so you can try a deploy without writing or hosting any code.

## [0.2.0-beta.5] - 2026-10-13

- Fixed Node sites (Nuxt, SvelteKit, and any project with `"type": "module"`) failing to start in PM2 with "module is not defined in ES module scope". The PM2 file is now `ecosystem.config.cjs`; files from older versions are renamed on the next update or save.
- Tested end to end: every framework in the picker (Laravel, PHP, Express, NestJS, Node.js, React, Vue.js, Angular, Svelte, Astro, Static HTML, Next.js, Nuxt, SvelteKit) deploys from a git repository and answers over HTTP.

## [0.2.0-beta.4] - 2026-10-13

- New website form: Backend / Frontend / Full-stack tabs, a search box across all frameworks, and pins (kept in your browser) that move favourite frameworks to the top. Cards are shorter.
- Domain and HTTP port are now two tabs showing one field at a time.

## [0.2.0-beta.3] - 2026-10-13

- Update: fixed the panel returning 404 after an update. Copying the release reset `/opt/laravelify` to mode 700, so nginx could not read `public/`. The update now keeps the folder permissions and sets 755. If you hit this, run `sudo chmod 755 /opt/laravelify`.

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
