# Changelog

All notable changes are listed here. Releases follow [semantic versioning](https://semver.org).

## [0.3.0-beta.47] - 2026-10-16

- **Database tool: search values of an encrypted column by the text you type.** In a marked column's header filter, **Search values** now finds the typed text as it is stored and also the same text encrypted, so a decrypted value is found however rare it is (before, only the most common values were searched).

## [0.3.0-beta.46] - 2026-10-16

- **Database tool: mark a column encrypted.** Pick a column's encryption method once, from **⋮ → Encrypted with… ▸** on its header or in the **Structure** tab. The panel then handles that column everywhere:
  - The grid shows its values decrypted, with a lock in the header. **Show as stored** shows the stored values.
  - The header filter lists and searches the decrypted values. **Is exactly** and **Is not** encrypt the value first.
  - The table search and the Database page search also find the encrypted value, without picking a method.
  - The record shows the value decrypted, and the row form edits it decrypted and saves it encrypted.
  - Only the setting is saved. The data is not changed, and decrypted values are never stored.

## [0.3.0-beta.45] - 2026-10-16

- **Database tool: the grid reads rows from the server as you scroll.** No more pages: blocks of 100 rows load while you scroll, sorted and filtered in MySQL, so any table size works.
- **Filter from a column header, like a spreadsheet.** The funnel on a header lists the column's values from the database with how many rows have each (search them, tick some, **(Empty)** for NULL), or takes a condition (contains, is exactly, starts with, a pattern, empty…). The active filter shows on the funnel and in the filters above the grid.
- **A ⋮ menu on every header:** sort ascending or descending, pin left or right, fit this or all columns to their content, hide the column, choose the columns to show, and reset the columns. These are built on the free AG Grid Community.
- **Fixed column widths:** numbers fit 7 digits, dates and times their format, text 220px; a column is never too narrow for its name. Hovering a cut value shows it whole, clicking a cell opens it (with **Copy** and **Open record**), and a double click opens the record.

## [0.3.0-beta.44] - 2026-10-16

- **Database tool: rows in AG Grid.** The rows of a table are an AG Grid (Community edition, MIT: free, no watermark) in the panel's colours: resize, move, and pin columns (kept per table in your browser), tick rows to delete them, row numbers, tooltips with each column's type. Sorting, paging, the search, and filters still run in MySQL; a click on a header sorts there. Foreign-key values are links to the row they point at, and a value other tables point at opens a list of them (like DataGrip). The grid loads only on table pages.
- **Encryption methods (Database → Encryption).** For websites that save some columns encrypted (like `encryptString()` in their helpers): add the website's method as PHP code that takes `$text` and returns text, like `return encryptString($text);`, plus an optional decrypt. It runs in the website's folder as the website's user, with its Laravel app loaded, so it uses the website's own helpers and keys; the panel never sees them. Then:
  - **Search** can encrypt what you type first and look for the encrypted value (**Encrypt first with**).
  - A table **filter** can be marked **Encrypted with** a method.
  - A **record** can be shown decrypted (**Show decrypted with…**).
  - **Try a method** encrypts a text and decrypts it again to check it. The code is stored encrypted.

## [0.3.0-beta.43] - 2026-10-16

- **Full backup: tick or untick whole databases.** Each database in the dialog has its own tick box next to its tables. Unticked databases are skipped and stay unticked for the next full backup; databases added later are backed up.
- **Database tool, row form:** each column gets a field that fits it. Date, datetime, and time columns use the browser's date and time pickers (with **Now** and **Clear**), enum columns a list of their values, long text a text box; the type never runs over the field any more, and **NULL** sits on the right. Values go back to MySQL's own format (`2025-02-23 17:59:37`). Zero dates and fractions of a second keep a plain field.
- **Database tool, SQL everywhere:** besides a database's own SQL tab, the Database page has an **SQL** tab with a database picker and every table has one that starts with `SELECT * FROM table LIMIT 100`. **Ctrl+Enter** runs.
- **Database tool, related rows:** a column that points at another table (a foreign key) shows `→ table` in its header and its values are links that open that table showing only the row it points at. A column other tables point at shows `← N`; clicking a value lists those tables (like `customers (currency_id)`) and opens one showing only the rows that point at it. The record dialog has the same links and a **Related rows** list.
- **Database tool, rows as a grid:** a dense, ruled grid with a sticky header, row numbers, right-aligned numbers, and key and link badges, only in the Database tool.

## [0.3.0-beta.42] - 2026-10-16

- **Fixed: the Logs tab listed each worker log twice.** Since beta.40 worker logs are written to `/var/log/laravelify/sites/<site>/` (under **Supervisor**), and the old files of the same name in the site's `storage/logs` (written before the update) also showed under **Other logs**. The old copies are no longer listed; they stay on disk and the File manager still opens them.

## [0.3.0-beta.41] - 2026-10-16

- **Older worker names are renamed by themselves.** Since beta.40 every Supervisor program of a site must start with the site name (so one site cannot replace another site's workers), and the update left a site with names like `[program:balances-listen]` running as before with "is not allowed". Now the update, the re-apply (`LARAVELIFY_REAPPLY=1 privileges.sh`), and **Save** on the Processes tab rename such programs to `<site>-balances-listen` (and `[group:...]` with its `programs=` list), apply them, and show the saved file. Names that already fit are left as they are; if a new name is taken, `-2` is added.

## [0.3.0-beta.40] - 2026-10-16

Security: website code can no longer reach the panel, other websites, or root. Before, the panel, every website's PHP, and the Laravel workers all ran as `www-data`, and Node apps ran as root, so a hacked upload in any site could read the panel's `.env` (MySQL root password, app key), rewrite every other site, and become root through the panel's sudo rule.

- **The panel runs as its own user, `laravelify`.** It has its own PHP-FPM pool (`/run/php/laravelify.sock`), queue worker, and scheduler. `/opt/laravelify` is `root:laravelify` (`751`), `.env` is `640`, and `storage/` and `bootstrap/cache` belong to the panel. Only `laravelify` may run `host-apply.sh` as root.
- **Each website runs as its own user, `lfy-<slug>`.** New sites get one. Existing sites move with `LARAVELIFY_SITE_USERS=1` or `php artisan laravelify:separate-sites`, and back with `scripts/rollback-site-users.sh`. A site that has not been moved yet stays `www-data`.
- **Each website has its own PHP-FPM pool, Supervisor user, and PM2 daemon** (`pm2-lfy-<slug>` restores it on boot). Deploy commands (git, Composer, npm, artisan), the terminal, and file manager changes run as that user, so repository code never runs as the panel. Home, Composer and npm caches, trash, and temp live in `/var/lib/laravelify/sites/<slug>`. PM2 app files live in `/var/lib/laravelify/pm2`.
- **Site folders stay readable by nginx.** Root sets the setgid bit (`2750`, group `laravelify-sites`). A fresh clone keeps the site folder's mode and group, and each deploy puts the owner and group back.
- **Example repositories live in the site user's home** (`<home>/examples/<slug>`), not in the panel's `storage/`, which that user cannot read. The next deploy moves an old copy and deletes it.
- **nginx's workers run as `laravelify-web`** (in `laravelify-sites`), so only nginx and the panel can open the panel's PHP-FPM socket; website code cannot send it FastCGI.
- **The panel keeps sessions, its queue, and its cache in MySQL** instead of Redis, which every site can reach without a password. Everyone signs in again once.
- **phpMyAdmin runs in the panel's pool;** its config and temp files belong to the panel user.
- **host-apply.sh checks what it installs.** Rendered files must come from the panel's storage. Site nginx files may only log under `/var/log/nginx` or `/var/log/laravelify`, include files under `/etc/nginx`, and keep temp and cache folders under nginx's own. Supervisor programs must run as the site's user and log to `/var/log/laravelify/sites/<slug>/` (the panel fills both in when you save). The tunnel unit is written by the script, only the token comes from the panel. Root never writes through a path inside `/var/www`. `host-apply` prints one line per PM2 start.
- **Worker logs moved** from `storage/logs` in the site to `/var/log/laravelify/sites/<slug>/` (Supervisor writes them as root). The **Logs** tab reads both.
- **The update moves an existing server over by itself** (`scripts/privileges.sh`, safe to run again). It lets a running deploy finish, saves the old files to `/var/lib/laravelify/rollback/before-privilege-separation`, and applies every site's workers again. The panel answers 502 for a few seconds; each worker and Node app restarts once. `scripts/rollback-privileges.sh` undoes it. See the docs: Security → Users and permissions.

## [0.3.0-beta.39] - 2026-10-16

Security fixes from an audit of the panel, the landing site, and the docs.

- **Login is rate limited.** Five wrong answers (password or authenticator code) per account and IP, or twenty per IP, pause logins for five minutes. The password and the code are both checked before a session starts; before, a right password with a wrong code signed in for a moment.
- **First setup needs the installer's token.** `/setup` (creating the superadmin) opens only with the link the installer prints (`/setup?token=...`, also `LARAVELIFY_SETUP_TOKEN` in `.env`), so nobody who finds a fresh panel first can claim it. Existing panels are not affected.
- **GitHub App callback and install links need the signed-in admin.** They live under `/hooks` (open for webhooks), so anyone could call them; now they send you to the login first, a registered App cannot be overwritten by a second callback, and the installation id must be a number.
- **Only local proxies are trusted.** Forwarded headers (`X-Forwarded-For`, `-Proto`, `-Host`) are believed only from this server's own cloudflared and nginx, not from any visitor.
- **Browser protections on every panel page:** `X-Frame-Options` and `frame-ancestors` (no clickjacking), `X-Content-Type-Options: nosniff`, `Referrer-Policy: same-origin`, and a `Permissions-Policy`.
- **Git values that look like options are refused.** A repository or branch starting with `-` (like `--upload-pack=...`, which runs a command) is rejected on save and before every deploy, and git gets `--` before the repository.
- **A webhook without a secret is never accepted.**
- Dependencies: `composer audit` and `npm audit` (panel and landing) report no known vulnerabilities.

## [0.3.0-beta.38] - 2026-10-16

- **Database tool: sort by clicking a column header.** Databases (name, tables, size) and tables (name, rows, size, engine, last change); click again to reverse.
- **Fixed: tables showed 0 rows.** MySQL 8 keeps table sizes and row counts for a day; the Database tool now reads them as they are.
- **Fixed: sorting rows by a column did nothing in phpMyAdmin.** On newer PHP versions phpMyAdmin put PHP's deprecation notices into its AJAX answers, which broke clicks like sorting. The panel's phpMyAdmin settings (`/etc/phpmyadmin/conf.d/zz-laravelify-hide.php`, written by installs and updates) now leave deprecation notices out.

## [0.3.0-beta.37] - 2026-10-16

- **The panel's own database is hidden.** `laravelify` (the database in the panel's `.env`) no longer shows in Tools → Database, the backup pickers, the Full backup dialog, or "All databases" backups, and phpMyAdmin no longer lists it (or its own `phpmyadmin` database): installs and updates write `/etc/phpmyadmin/conf.d/zz-laravelify-hide.php` with `hide_db` for every phpMyAdmin server.

## [0.3.0-beta.36] - 2026-10-16

- **New Tools → Database.** Every MySQL database with its size and tables, without leaving the panel:
  - **Databases and tables** with rows and size, sortable and searchable, and **Back up** for one database (to your backup storage).
  - **Rows** a page at a time (25 to 500), sorted by any column, a search in every column, and filters on single columns (contains, =, ≠, starts or ends with, regex, >, <, NULL). Click a row to see every value; **Edit**, **Add row**, and **Delete** (one or many).
  - **Triggers, events, procedures, and functions**: view, change (if MySQL refuses the new one, the old one is put back), create, and drop.
  - **Search everywhere**: a value in every table of every database (or chosen ones), optionally only in columns named like `firstname`, as contains, exact, starts with, or regex; or tables and columns by name. A result opens its record.
  - **Changes (checkpoints)**: every row added, edited, or deleted and every trigger, event, procedure, or function changed here is logged with what was there before. **Undo** puts back only that row or object, so rows others added meanwhile stay; when the row changed again since, it asks before writing over it. Deleting can save a copy of the table first (restorable from the log) and start a backup of the whole database.
- **Skip tables picker shows sizes.** Each table's size, sorting by name or size, and the total and skipped size of the database.

## [0.3.0-beta.35] - 2026-10-16

- **Skip tables in database backups.** Like skipped folders in file backups: a database schedule has a **Skip tables** picker (open a database, untick tables such as cache, sessions, logs, or data that never changes), and `*.table` skips a table in every database. Skipped tables are left out of the dump with `mysqldump --ignore-table`, listed in the run's log, and not offered in its restore. A restore leaves those tables in the target database as they are.
- **Full backup asks first (databases).** **Full backup** on Database backups opens a dialog with every database and its tables: untick what you do not need and press **Back up**. Each database is still dumped to its own file, and the choice is kept for the next full backup.
- **Database backups are grouped by schedule.** One row per schedule (like **FULL BACKUP · 3 dumps, one per database · 2 runs**), the newest run's dump of each database under it, older runs behind **older runs**, as for file backups.

## [0.3.0-beta.34] - 2026-10-16

- **File backups are grouped by schedule.** One row per schedule (like **FULL BACKUP · 6 archives, one per website · 2 runs**), the newest run's archive of each website under it, and the older runs, each with its archives, behind **older runs**. Before, every run of the same schedule was a separate group.
- **Sites on a port open from outside.** A website without a domain links to `http://SERVER_IP:port` (the server's public IP, found by itself or set with `LARAVELIFY_PUBLIC_IP`) instead of `127.0.0.1`, and its port is opened in the firewall (ufw) when its nginx file is written, and closed again when the port changes or the site is deleted. Health checks and the uptime monitor still check it on `127.0.0.1`.
- **Add website: Used ports.** The HTTP port field of the Add website form has the same **Used ports** list as the General tab, with the next free port one click away.

## [0.3.0-beta.33] - 2026-10-16

- **Backups without a bucket.** Backup storage has a new provider, **This server (a folder)**: give a full path like `/backups` and backups are saved there instead of S3. Laravelify makes the folder (owned by the panel's user) and tests it; it must be outside `/var/www` and system folders. Backups, restores, look inside, and download work the same. "S3 storage" is now **Backup storage**.
- **One archive per website.** A file schedule with several folders (or several websites) packs each into its own `tar.gz`, so one website can be looked at, downloaded, or restored without the others. **Everything** stays one archive.
- **The backup list shows a run, then its websites.** A full backup (or any run with several archives) is one row, like **FULL BACKUP · 6 archives, one per website**, with each website's archive under it. Before, the first website's row looked like the parent of the others.
- **File editor: find shows every match.** Matches are highlighted in the text and the current one stands out, with its line number next to the count. Before, the match was selected but not visible while typing in the find box.
- **Fixed: a select list sometimes opened empty.** After moving to another page, the list was attached to the old page. It is made again when needed. Form fields without an id or name get a name, so the browser no longer warns about them.

## [0.3.0-beta.32] - 2026-10-15

- **Tunnel redirects work for a bare domain that receives mail.** The tunnel's CNAME is no longer refused because the domain has MX, TXT, or CAA records (Cloudflare flattens a CNAME at the bare domain and keeps them); only an existing A, AAAA, or CNAME is reported. Switching a redirect between **Cloudflare tunnel** and **Server IP** removes the record the panel made for the other way first.

## [0.3.0-beta.31] - 2026-10-15

- **Fixed: Regenerate (and MCP `update-website` with `regenerate_files`) dropped the Redirects tab's nginx blocks.** The redirects stay in the file now.
- **Server → Nginx:** a passing `nginx -t` shows a green check instead of the yellow warning box.

## [0.3.0-beta.30] - 2026-10-15

- **nginx variable table raised for many sites.** Writing a website's nginx files now also installs `/etc/nginx/conf.d/laravelify-hash.conf` with `variables_hash_bucket_size 128;` (unless you already set that directive yourself), so a server with many sites and security rules no longer fails `nginx -t` with "could not build variables_hash", even for security files written by older versions.

## [0.3.0-beta.29] - 2026-10-15

- **New Server → Nginx page.** A **Test nginx** button (`nginx -t`, with nginx's own message when it fails), every server block with its domains, ports, target (proxy, PHP, files, redirect), owner website and file, a warning when two blocks answer for the same domain and port, the ports nginx listens on, nginx's error log (`/var/log/nginx/error.log`), and every config file, read-only.

## [0.3.0-beta.28] - 2026-10-15

- **Fixed: blocking an IP failed with "could not build variables_hash" on websites with a long name.** nginx keeps variable names in a small table, and the Security tab's variables (`$laravelify_blocked_<site>`) overflowed it for slugs like `apply-coupons-main-nextjs`. A long site name now becomes a short name (its first letters plus a hash), so `nginx -t` passes. Short names are unchanged.

## [0.3.0-beta.27] - 2026-10-15

- **Server IP redirects accept a `www` CNAME to the bare domain.** `www.example.com CNAME example.com` already reaches the server when the bare domain is pointed at it, so it is no longer reported as a conflict.

## [0.3.0-beta.26] - 2026-10-15

- **An A record can sit next to mail records.** Redirects on the **Server IP** way no longer stop at an existing MX, TXT, or CAA record of the domain (a CNAME cannot sit next to them, an A record can); only an existing A, AAAA, or CNAME record is reported. This makes it the way to redirect a bare domain that receives mail.
- The type, match, and "how it reaches the server" lists on the Redirects tab use the browser's own list, which opens and selects reliably in these rows.

## [0.3.0-beta.25] - 2026-10-15

- **No www for subdomains.** "Also open on www" and "+ Add www" are offered only for a bare domain (`example.com`, `example.co.uk`), never for `shop.example.com`, and `include_www` in MCP is ignored for a subdomain.

## [0.3.0-beta.24] - 2026-10-15

- **Redirects without a tunnel route.** A redirecting domain can now be set to **Server IP (A record, port 80)** instead of **Cloudflare tunnel**. nginx answers it on port 80 (open in the firewall), no tunnel route is added, and with a Cloudflare API token Laravelify creates a proxied A record to the server's public IP. Set the domain's Cloudflare SSL/TLS mode to **Flexible** (Cloudflare reaches the server on port 80). MCP `set-redirects` takes `via`.

## [0.3.0-beta.23] - 2026-10-15

- **Redirects from the panel.** A new **Redirects** tab on every website (Settings group). A domain redirect sends another domain, like `apply.coupons` or `www.apply.coupons`, to this site or any URL (path and query kept by default; 301, 302, 307, or 308), and a page redirect sends `/old-page` (or a whole folder) to a new path or URL. Saving writes managed blocks into the site's nginx file (tested first, rolled back when rejected), and redirecting domains are added to the Cloudflare tunnel and DNS like the site's other domains. The security include (basic auth, blocked IPs) skips the redirect servers, and a domain used as a redirect cannot be taken by another website. MCP gets `set-redirects` (37 tools).

## [0.3.0-beta.22] - 2026-10-15

- **Log times follow your timezone.** The Logs tab showed times as the app wrote them (UTC unless its `.env` sets `APP_TIMEZONE`). The viewer now shows the timestamp at the start of each line in your timezone (Settings → General, or your browser's), for Laravel and worker logs, nginx's error log, and nginx's access log (its `+0000` becomes your offset). The clock button above the log switches back to the original times, search and copy follow what you see, and a timestamp inside a message is never changed.
- **Fixed: the Monitoring tab failed with "incomplete object UptimeCheck".** The uptime summary kept an Eloquent model in the cache, and Laravel does not unserialize classes from the cache. The last check is now read from the database instead.

## [0.3.0-beta.21] - 2026-10-15

- **www and several domains for one website.** The Add website form offers **Also open on www.example.com** as soon as you type a domain, and **Other domains for the same website** takes a second domain (a line each), so one app never needs a second website. On the General tab the same list is editable, with an **Add www** button. nginx's `server_name`, the Cloudflare tunnel's ingress, and the website's URL list all follow. A domain can belong to only one website.
- **Fixed: changing the domain on the General tab did not update nginx.** It does now (only the `server_name` line; hand-made nginx changes stay), and a file nginx rejects is rolled back.
- **Cloudflare: tunnel route and DNS by themselves.** Save a Cloudflare API token in **Settings → Cloudflare** (Account → Cloudflare Tunnel → Edit, Zone → DNS → Edit, Zone → Zone → Read) and every domain of a website on a tunnel gets its public hostname and a proxied CNAME to `<tunnel>.cfargotunnel.com`, when you create the site or save its domains. Other routes and DNS records are never touched, an existing record for something else is reported instead of replaced, and a domain's route and CNAME are removed again when you take it off or delete the site. Without a token everything works as before.
- **MCP grew from 15 to 36 tools.** New: `update-website`, `set-domains`, `sync-cloudflare`, `delete-website`, `update-group`, `delete-group`, `get-environment` (secrets hidden unless asked), `list-deployments`, `cancel-deployment`, `get-logs`, `check-website`, `restart-website`, `run-command`, `list-files`, `read-file`, `delete-file` (to the Trash), `import-database` and `get-task` (a dump from the site's folder, in the background, as the site's own database user), `list-backups`, `run-backup`, and `server-status`. `create-website` takes `include_www` and `aliases`, and a website is found by any of its domains.

## [0.3.0-beta.20] - 2026-10-15

- **phpMyAdmin imports big dumps, and the file manager takes big files.** Ubuntu's PHP accepted only 2 MB uploads, so phpMyAdmin dropped larger .sql files without a clear error. phpMyAdmin and the panel now accept uploads up to 256 MB (nginx and PHP), with 10 minutes for long imports. Updates add this to existing servers (`php artisan laravelify:upload-limits`, which puts a file back when nginx rejects it); new installs have it from the start.

## [0.3.0-beta.19] - 2026-10-15

- **Fixed: migrations with triggers failed with MySQL error 1419** ("You do not have the SUPER privilege and binary logging is enabled"). MySQL 8 keeps a binary log, and then site users cannot create triggers, functions, or procedures. Updates now turn on `log_bin_trust_function_creators` with `SET PERSIST` (no restart), new installs have it in MySQL's config, and `php artisan laravelify:mysql-defaults` applies it by hand.

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
