<p align="center">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="brand/laravelify-brand-assets/04-social/png/laravelify-readme-banner-dark-1600.png">
    <img alt="Laravelify, self-hosted deploys for Laravel, Node.js, and front-end apps" src="brand/laravelify-brand-assets/04-social/png/laravelify-readme-banner-light-1600.png">
  </picture>
</p>

<p align="center">
  <a href="https://github.com/laravelify-cloud/laravelify/actions/workflows/tests.yml"><img alt="Tests" src="https://github.com/laravelify-cloud/laravelify/actions/workflows/tests.yml/badge.svg"></a>
  <a href="https://github.com/laravelify-cloud/laravelify/releases/latest"><img alt="Latest release" src="https://img.shields.io/github/v/release/laravelify-cloud/laravelify?color=6B16ED"></a>
  <a href="LICENSE"><img alt="MIT license" src="https://img.shields.io/badge/license-MIT-FCD452"></a>
  <img alt="Status: public beta" src="https://img.shields.io/badge/status-public%20beta-8B5CF6">
</p>

<p align="center">
  <a href="https://laravelify.cloud"><b>Website</b></a> ·
  <a href="https://docs.laravelify.cloud"><b>Documentation</b></a> ·
  <a href="https://docs.laravelify.cloud/quickstart">Quickstart</a> ·
  <a href="https://github.com/laravelify-cloud/laravelify/issues">Issues</a> ·
  <a href="https://github.com/laravelify-cloud/laravelify/releases">Releases</a>
</p>

---

> [!NOTE]
> Laravelify is in **public beta**. It runs real sites, but things can still change between versions. Try it on a fresh server first, keep backups, and [report what breaks](https://github.com/laravelify-cloud/laravelify/issues).

**Laravelify** is an open-source, self-hosted control panel that turns one Ubuntu server into a home for your apps: **Laravel** and PHP backends, **Express**, **NestJS**, and other Node.js servers, **React**, **Vue.js**, **Angular**, **Svelte**, and **Astro** front ends, and full-stack **Next.js**, **Nuxt**, and **SvelteKit**. Push to GitHub and your site deploys. Open the panel and you see live traffic, errors, attacks, and server load. Your server, your data, no monthly bill.

Inspired by [Coolify](https://coolify.io), built with Laravel, Livewire, Alpine, and Tailwind, and **without Docker**: PHP runs on nginx + PHP-FPM (Laravel with Supervisor workers), Node servers run under PM2, and front-end builds are served as files by nginx.

## Install

On a fresh Ubuntu 22.04 / 24.04 / 26.04 server:

```bash
curl -fsSL https://cdn.laravelify.cloud/install.sh | sudo bash
```

It asks for a MySQL root password, the panel and phpMyAdmin domains, and a Cloudflare tunnel token, then installs nginx, PHP 8.0–8.5, MySQL 8.4, Redis, Node 20/22/24, PM2, Supervisor, cloudflared, and a firewall. Open `https://your-panel-domain/setup` when it finishes.

Update later with one command:

```bash
sudo bash /opt/laravelify/scripts/update.sh
```

See the [installation guide](https://docs.laravelify.cloud/installation/install) for requirements and options.

## Features

| | |
| --- | --- |
| 🚀 **Git push to deploy** | GitHub App or token, webhooks, live build logs, npm, pnpm, and Yarn |
| 🧱 **Backend** | Laravel and plain PHP (PHP 8.0–8.5, Composer, queue and scheduler workers, Reverb, one-click MySQL), Express, NestJS, any Node.js server |
| 🎨 **Frontend** | React, Vue.js, Angular, Svelte, Astro, and static HTML, built on the server and served by nginx |
| ▲ **Full-stack** | Next.js, Nuxt, SvelteKit, Laravel + Livewire, Laravel + Inertia (Node 20/22/24 under PM2) |
| 📈 **Live monitoring** | Requests, response times (p95), uptime, top routes, countries, a live request feed |
| 🐞 **Issues** | Laravel errors grouped by exception and line, with stack traces |
| 🛡️ **Attack detection** | SQL injection, XSS, scanners, attack tools, with one-click IP blocking |
| 💓 **Heartbeats** | Know when a cron job stops |
| 🖥️ **Server monitoring** | Live CPU, memory, load, network, services, and top processes |
| 💬 **Telegram alerts** | Deploys, downtime, crashed workers, error spikes, slow responses, attacks, disk, patches |
| ☁️ **Cloudflare tunnels** | HTTPS without open ports; one tunnel serves many sites |
| 🔐 **Secure by default** | One admin, 2FA required, secrets revealed only with your code, least-privilege host access |
| 🧰 **Tools** | File manager, terminal with Artisan/PM2 autocomplete, logs, health checks, phpMyAdmin one-click sign-in |

## Documentation

Everything is at **[docs.laravelify.cloud](https://docs.laravelify.cloud)**. The docs live in their own repository, [`laravelify-cloud/laravelify-docs`](https://github.com/laravelify-cloud/laravelify-docs), so you can fix a page with a pull request there.

## Contributing

Pull requests are welcome. Read [CONTRIBUTING.md](CONTRIBUTING.md) to run Laravelify locally and run the tests. Report security issues privately as described in [SECURITY.md](SECURITY.md).

## License

Laravelify is open-source software licensed under the [MIT license](LICENSE). Brand assets are in [`brand/`](brand).
