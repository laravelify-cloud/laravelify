<?php

namespace App\Mcp\Servers;

use App\Mcp\Prompts\DeployProject;
use App\Mcp\Tools\CancelDeployment;
use App\Mcp\Tools\CheckWebsite;
use App\Mcp\Tools\CreateGroup;
use App\Mcp\Tools\CreateWebsite;
use App\Mcp\Tools\DeleteFile;
use App\Mcp\Tools\DeleteGroup;
use App\Mcp\Tools\DeleteWebsite;
use App\Mcp\Tools\DeployWebsite;
use App\Mcp\Tools\GetDeployment;
use App\Mcp\Tools\GetEnvironment;
use App\Mcp\Tools\GetLogs;
use App\Mcp\Tools\GetNginx;
use App\Mcp\Tools\GetTask;
use App\Mcp\Tools\GetWebsite;
use App\Mcp\Tools\ImportDatabase;
use App\Mcp\Tools\ListBackups;
use App\Mcp\Tools\ListDeployments;
use App\Mcp\Tools\ListFiles;
use App\Mcp\Tools\ListGroups;
use App\Mcp\Tools\ListPorts;
use App\Mcp\Tools\ListSiteTypes;
use App\Mcp\Tools\ListSources;
use App\Mcp\Tools\ListWebsites;
use App\Mcp\Tools\ReadFile;
use App\Mcp\Tools\RestartWebsite;
use App\Mcp\Tools\RunBackup;
use App\Mcp\Tools\RunCommand;
use App\Mcp\Tools\ServerStatus;
use App\Mcp\Tools\SetDomains;
use App\Mcp\Tools\SetRedirects;
use App\Mcp\Tools\SetWebsiteEnabled;
use App\Mcp\Tools\SyncCloudflare;
use App\Mcp\Tools\UpdateEnvironment;
use App\Mcp\Tools\UpdateGroup;
use App\Mcp\Tools\UpdateNginx;
use App\Mcp\Tools\UpdateWebsite;
use App\Mcp\Tools\UploadFile;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('Laravelify')]
#[Version('1.1.0')]
#[Instructions(<<<'MARKDOWN'
    This is a Laravelify panel: a self-hosted deployment panel on the user's own Ubuntu server
    (docs: https://docs.laravelify.cloud). You can deploy and manage the user's websites here.

    What you can do (tool names in brackets):
    - Deploy: create a website from a Git repository on a domain or an HTTP port [create-website, it deploys right away],
      deploy again [deploy-website], follow a deploy and read its log [get-deployment, list-deployments], stop one [cancel-deployment].
    - Domains: one website can answer on many domains, so one app never needs a second website [set-domains, or domain / include_www /
      aliases in create-website]. include_www adds www.<domain>. nginx, the Cloudflare tunnel and (with an API token in Settings → Cloudflare)
      Cloudflare DNS are all updated; if the result has "problems" or a note about the token, tell the user exactly what it says. [sync-cloudflare]
    - Redirects: send a domain (apply.coupons, www.apply.coupons) to this site or any URL, or one page to another [set-redirects, the complete list each time].
    - Look: websites, groups, settings, ports, server load and services [list-websites, get-website, list-groups, list-ports, server-status, list-site-types, list-sources].
    - Change a website: settings, branch, commands, versions [update-website]; environment variables [get-environment (secrets hidden), update-environment];
      nginx [get-nginx, update-nginx, rolled back when rejected]; on or off [set-website-enabled]; restart its workers or PM2 app [restart-website].
    - Debug: logs [get-logs], health checks [check-website], run one command in the site folder as the web user, like php artisan [run-command],
      read files [list-files, read-file; .env is only through get-environment].
    - Files and data: files the repository does not carry, like a Firebase key in storage/app/private [upload-file, up to 20 MB, a .zip/.tgz can be
      unpacked]; delete a file, which goes to the Trash [delete-file]; import a .sql or .sql.gz dump into the site's database [import-database, then get-task].
    - Backups to S3: [list-backups, run-backup].
    - Clean up: [delete-website, delete-group, update-group]. These keep the site's folder and database on the server. Always ask the user first.

    Ports are picked by themselves: every new site gets a port nothing else uses, including apps still running from an old server.
    Ports in an old server's nginx files do not matter, only the domains.

    To deploy a repository:
    1. list-sources: the connected GitHub Apps (private repositories, auto deploy on every push) and the Cloudflare tunnels (HTTPS for domains).
    2. Pick the type from the project's files (composer.json with laravel/framework → laravel; package.json with next → nextjs; vite + react → react; and so on). Use list-site-types when unsure.
    3. create-website with a group, name, type, repository, branch, and either a domain plus cloudflare_tunnel_id (and include_www when the user wants www too),
       or nothing for a free HTTP port. Laravel and PHP apps that need MySQL: create_database true. Pass the app's required settings in env ("KEY=value").
       DB_* values from an old .env are left out: the site uses the database the panel made.
    4. get-deployment every 10–20 seconds until finished. On failure read log_tail (and get-logs), fix the cause (env, build command, branch), and deploy-website again.
    5. Private files the app needs (like storage/app/private keys from a backup): upload-file. Data from an old server: put the dump in the site folder, then
       import-database (reset true when the migrations already made the tables; ask first: it empties the database).
    6. Special nginx rules from an old server (OAuth, webhook, or payment callback paths sent to the Laravel API): get-nginx, then update-nginx with location blocks added.
    7. check-website, then tell the user the URL.

    To deploy a project folder on the user's computer: the server cannot read local files. Push it to GitHub first, from the user's
    machine: `gh repo create <name> --private --source . --push` (or add a remote and push). Make sure the Laravelify GitHub App
    can read that repository (GitHub → Settings → Applications → the App → Repository access), then create-website with owner/repo.
    Every later push deploys by itself.

    Never print secrets back to the user unless they ask. Ask before turning a site off, deleting anything, resetting a database, or changing a production .env.
    MARKDOWN)]
class LaravelifyServer extends Server
{
    protected array $tools = [
        ListGroups::class,
        CreateGroup::class,
        UpdateGroup::class,
        DeleteGroup::class,
        ListSiteTypes::class,
        ListSources::class,
        ListWebsites::class,
        GetWebsite::class,
        CreateWebsite::class,
        UpdateWebsite::class,
        SetDomains::class,
        SetRedirects::class,
        SyncCloudflare::class,
        DeleteWebsite::class,
        SetWebsiteEnabled::class,
        DeployWebsite::class,
        ListDeployments::class,
        GetDeployment::class,
        CancelDeployment::class,
        GetEnvironment::class,
        UpdateEnvironment::class,
        GetNginx::class,
        UpdateNginx::class,
        RestartWebsite::class,
        GetLogs::class,
        CheckWebsite::class,
        RunCommand::class,
        ListFiles::class,
        ReadFile::class,
        UploadFile::class,
        DeleteFile::class,
        ImportDatabase::class,
        GetTask::class,
        ListBackups::class,
        RunBackup::class,
        ServerStatus::class,
        ListPorts::class,
    ];

    protected array $resources = [];

    protected array $prompts = [
        DeployProject::class,
    ];
}
