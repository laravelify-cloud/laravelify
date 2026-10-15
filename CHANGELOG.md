# Changelog

All notable changes are listed here. Releases follow [semantic versioning](https://semver.org).

## [0.3.0-beta.18] - 2026-10-15

- **Fixed: the first deploy of a Laravel or PHP site with a database failed with "Access denied".** The deploy ran the migrations before it created the database and its user. The database now comes first.
- **A panel-made database keeps its own login.** When an `.env` from an old server is pasted in, a deploy puts the panel's `DB_*` values back (and says so in the log), and MCP's create-website leaves those keys out.
- **Fixed: a quoted APP_KEY was replaced on every deploy.** Deploys now keep any existing key, quoted or not, and the `.env` editor writes keys unquoted like Laravel does.
- **New domains no longer cut the panel off.** Adding a domain restarts the Cloudflare tunnel three seconds later, so the page or MCP call that added it gets its answer first, and the tunnel is not restarted when its routes did not change.
- **MCP: get-nginx and update-nginx**, to add rules like OAuth or payment callback paths sent to a Laravel API. nginx checks the file first, and a rejected file is put back.

## [0.3.0-beta.17] - 2026-10-15

- **MCP: upload-file.** The assistant can put files the repository does not carry into a site, like a Firebase key in `storage/app/private`, or unpack a `.zip`, `.tar.gz`, or `.tgz` into a folder (up to 20 MB). Paths stay inside the site folder, links in archives are skipped, and deploys keep the files.
- **Ports nothing uses.** New websites skip every port in use: other websites' Node, HTTP, and Reverb ports, Laravelify's services, and anything listening on the server right now (like an app left from an old server). Ports handed out while creating one site never repeat. A port you choose is refused when something holds it, naming what holds it.
- **MCP: list-ports** shows which port is used by what.

## [0.3.0-beta.16] - 2026-10-15

- **MCP server: deploy with an AI assistant.** Connect Claude Code, Claude Desktop, or Cursor to `https://your-panel/mcp` and ask in plain words: "Deploy acme/shop on shop.example.com with a MySQL database". Tools: list and create groups, list site types, sources (GitHub Apps, repositories, tunnels), list and show websites, create a website (repository or example project, domain or HTTP port, env variables, database) and deploy it, deploy again, follow a deployment's status and log, set or remove `.env` variables, and turn a site off or on. The server tells the assistant what it can do and how to deploy, including a local folder (pushed to a private GitHub repository first), and offers a **deploy-project** prompt.
- **Settings → MCP:** create tokens (after your authenticator code, shown once, stored as a hash), see when each was last used, revoke them, and copy the ready-made connect command for each AI app.
- Deploys started from MCP show **Mcp** as their trigger.
- Adding a website from the form and from MCP now share the same code, so both check and set up a site the same way.

## [0.3.0-beta.15] - 2026-10-15

- **GitHub App: removed "Pull request previews" and "GitHub Actions runners".** Both came from Coolify's form, but Laravelify has neither feature, so they only asked GitHub for permissions nothing used. A new App now asks for Contents, Metadata, and Email (read) and push events, which is all automatic deploys need.

## [0.3.0-beta.14] - 2026-10-15

- **GitHub App sites deploy automatically, and the page now says so.** A site that uses a connected GitHub App shows **Automatic** on the Deployments tab with "Ready. Push to main and it deploys", instead of "Waiting for GitHub" and the steps for a repository webhook (those are still there under "Use a repository webhook instead").
- Events from the App other than pushes (pull requests, installations) no longer replace each site's last deploy status with "Ignored a pull_request event".
- New GitHub Apps leave **Pull request previews** off: Laravelify does not build previews yet, so the App no longer asks for that permission.

## [0.3.0-beta.13] - 2026-10-15

- **Security → Access:** a browser sign-in box in front of the panel and phpMyAdmin, before any page loads. It can ask for your authenticator code, a username and password, or the password followed by the code. Allowed IPs (single IPs or CIDR ranges, IPv4 and IPv6) skip it, a right answer is remembered for 1 to 72 hours, and five wrong answers block an IP for 15 minutes. Webhooks and the health check stay open. phpMyAdmin is protected through nginx `auth_request`, so its own login is untouched. If you lock yourself out: `sudo -u www-data php /opt/laravelify/artisan laravelify:access-off`.

## [0.3.0-beta.12] - 2026-10-15

- **Fixed "mkdir(): Permission denied"** on Server monitoring and other pages that run commands. On installs from before 0.3.0, the folder the panel's tools use (`storage/app/process-home`) belonged to root, so a newer sub-folder could not be created. The panel now falls back to a private temp folder instead of failing, the updater gives `storage` back to `www-data` every time, and new installs create the folder for `www-data`.

## [0.3.0-beta.11] - 2026-10-15

- **Account → Security** (the menu under your name): change your password, see your authenticator key, or change it to a new key or a key you already use. Every change asks for your current authenticator code after you press the button; a new key also needs a code from the new key first, so you cannot lock yourself out.
- **Use your own authenticator key** when setting up the superadmin: paste a key you already use elsewhere (or an otpauth:// link) and your app gives codes for both. New keys are now 32 characters, and **Make a different key** creates another one.
- File manager trash: the authenticator code dialog no longer fails with an error when it opens.

## [0.3.0-beta.10] - 2026-10-14

- **Turn on / Turn off runs in the background** for groups and selected websites, like backups. A progress card shows which website it is on ("Turning off shop (3 of 15)"), and lists every website with its result: turned off, already off, or the error that stopped it. Rows still waiting show "Switching…", and a website that failed keeps its state.
- **Database backups are grouped by database:** the list shows the newest backup of each database, with "N older backups" to open the rest underneath. File backups are grouped by schedule. The list now covers the latest 100.

## [0.3.0-beta.9] - 2026-10-14

- **Turn websites and groups off and on.** A turned-off site answers with a short "This site is turned off" page (503) on its own address, its workers and Node process stop (and stay stopped after a reboot), and deploys, GitHub pushes, and down alerts pause. Files, database, environment, and settings stay; Turn on brings it all back. Turning a group off turns off every website in it.
- **Select several** on the Groups list and a group's websites: Turn on, Turn off, and Delete for every selected row. Only empty groups are deleted; deleted websites keep their folders in /var/www.
- Trash: tick several items to restore them or delete them for good (with your authenticator code) at once.
- Groups and websites show their status: On, Turned off, or how many websites of a group are off.

## [0.3.0-beta.8] - 2026-10-14

- **Dates in your own time:** everything is still stored in UTC, but by default each person now sees their browser's timezone, and dates and times in their country's format (14.10.2026 13:05 in Azerbaijan, 10/14/2026 1:05 PM in the US). Settings → General can still fix a timezone or a format for everyone; "Automatic" is the new default, and earlier saved defaults (UTC, 2026-10-12, 14:05) switch to Automatic once.
- Trash: room around the notice, the table lines up with it, and versions hang under their item with a guide line.
- The global file manager no longer shows its title twice; error notes in the file manager have space around them.

## [0.3.0-beta.7] - 2026-10-14

- **Trash** in the file manager: deleting moves files and folders to the trash instead of removing them. Restore puts them back where they were (as "name (restored)" if the name is taken). Every delete of the same path is kept as a version you can pick. Delete for good and Empty trash need your authenticator code.
- **Toasts** are easier to read: a title, the message, a copy button, and a countdown line that stops while you hover; errors stay longer.
- The background task list has room above and below it.

## [0.3.0-beta.6] - 2026-10-14

- **Telegram:** backup messages were never sent when Telegram events had been saved before backups existed. Events added in a later version are now on by default for everyone, and stay as you set them once you save.
- Background tasks look like alerts: a colored icon, the result, and a status pill (Done, Failed, Waiting, or the percent) centered on the right, with the time.
- Delete dialogs (file manager and the rest of the panel): a warning callout, the list of what will be deleted, and the text to type with a copy button.

## [0.3.0-beta.5] - 2026-10-14

- File manager, **Compress to .zip**: a dialog lists the folders inside the selection a zip usually does not need (node_modules, vendor, .git, .next, .nuxt, .output, .svelte-kit, .angular, .astro, .cache, Laravel cached views), ticked to leave out; dist, build, and logs are listed unticked. Untick to keep, name the zip, and several apps go into one file.

## [0.3.0-beta.4] - 2026-10-14

- Database backups never include phpMyAdmin's own settings database (`phpmyadmin`), like `information_schema`, `mysql`, `performance_schema`, and `sys`.
- Full backup names every database it starts; each one is its own file.

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
