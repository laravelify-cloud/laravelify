<?php

namespace App\Mcp\Servers;

use App\Mcp\Prompts\DeployProject;
use App\Mcp\Tools\CreateGroup;
use App\Mcp\Tools\CreateWebsite;
use App\Mcp\Tools\DeployWebsite;
use App\Mcp\Tools\GetDeployment;
use App\Mcp\Tools\GetNginx;
use App\Mcp\Tools\GetWebsite;
use App\Mcp\Tools\ListGroups;
use App\Mcp\Tools\ListPorts;
use App\Mcp\Tools\ListSiteTypes;
use App\Mcp\Tools\ListSources;
use App\Mcp\Tools\ListWebsites;
use App\Mcp\Tools\SetWebsiteEnabled;
use App\Mcp\Tools\UpdateEnvironment;
use App\Mcp\Tools\UpdateNginx;
use App\Mcp\Tools\UploadFile;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('Laravelify')]
#[Version('1.0.0')]
#[Instructions(<<<'MARKDOWN'
    This is a Laravelify panel: a self-hosted deployment panel on the user's own Ubuntu server
    (docs: https://docs.laravelify.cloud). You can deploy and manage the user's websites here.

    What you can do:
    - Deploy an app from a Git repository on a domain or an HTTP port: create-website (it deploys right away).
    - Follow a deploy and read its log: get-deployment. Deploy again: deploy-website.
    - See what runs on the server: list-websites, get-website, list-groups.
    - Set or remove .env variables: update-environment. Turn a site off or on: set-website-enabled.
    - Put files the repository does not carry into a site (a Firebase key in storage/app/private, or a .zip/.tgz unpacked into
      a folder): upload-file, after the site's first deploy has finished. Deploys keep these files.
    - Ports are picked by themselves: every new site gets a port nothing else uses, including apps still running from an old
      server. Ports in an old server's nginx files do not matter, only the domains. list-ports shows what holds which port.
    - Special nginx rules from an old server (like OAuth, webhook, or payment callback paths sent to the Laravel API):
      get-nginx, then update-nginx with location blocks added. A rejected file is rolled back.
    - A site with create_database keeps the database login the panel made; DB_* values from an old .env are left out.
      There is no database import through MCP: tell the user to import their dump into the new database.
    - Laravel, PHP, Express, NestJS, Node.js, React, Vue, Angular, Svelte, Astro, static HTML, Next.js, Nuxt, and SvelteKit (list-site-types).

    To deploy a repository:
    1. list-sources: the connected GitHub Apps (private repositories, auto deploy on every push) and the Cloudflare tunnels (HTTPS for domains).
    2. Pick the type from the project's files (composer.json with laravel/framework → laravel; package.json with next → nextjs; vite + react → react; and so on). Use list-site-types when unsure.
    3. create-website with a group, name, type, repository, branch, and either a domain plus cloudflare_tunnel_id, or nothing for a free HTTP port.
       Laravel and PHP apps that need MySQL: create_database true. Pass the app's required settings in env ("KEY=value").
    4. get-deployment every 10–20 seconds until finished. On failure read log_tail, fix the cause (env, build command, branch), and deploy-website again.
    5. Private files the app needs (like storage/app/private keys from a backup): upload-file now. Deploy again only if the build reads them.
    6. Tell the user the URL. For a domain, the DNS record is added by the tunnel; it can take a minute.

    To deploy a project folder on the user's computer: the server cannot read local files. Push it to GitHub first, from the user's
    machine: `gh repo create <name> --private --source . --push` (or add a remote and push). Make sure the Laravelify GitHub App
    can read that repository (GitHub → Settings → Applications → the App → Repository access), then create-website with owner/repo.
    Every later push deploys by itself.

    Never print secrets back to the user unless they ask. Ask before turning a site off or changing a production .env.
    MARKDOWN)]
class LaravelifyServer extends Server
{
    protected array $tools = [
        ListGroups::class,
        CreateGroup::class,
        ListSiteTypes::class,
        ListSources::class,
        ListWebsites::class,
        GetWebsite::class,
        CreateWebsite::class,
        DeployWebsite::class,
        GetDeployment::class,
        UpdateEnvironment::class,
        UploadFile::class,
        GetNginx::class,
        UpdateNginx::class,
        SetWebsiteEnabled::class,
        ListPorts::class,
    ];

    protected array $resources = [];

    protected array $prompts = [
        DeployProject::class,
    ];
}
