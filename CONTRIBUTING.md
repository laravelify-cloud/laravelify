# Contributing to Laravelify

Thanks for helping. Bug reports, docs fixes, and pull requests are all welcome.

## Run it locally

```bash
git clone https://github.com/laravelify-cloud/laravelify.git
cd laravelify
composer install
cp .env.example .env          # set DB_* (MySQL), or DB_CONNECTION=sqlite
php artisan key:generate
php artisan migrate
npm install
npm run dev
php artisan serve
```

On Windows and macOS (or with `LARAVELIFY_HOST_COMMANDS=false`), system commands are not executed: every nginx, Supervisor, and PM2 file is written to `storage/app/rendered` so you can see what would be applied.

## Before you open a pull request

```bash
php artisan test --compact
vendor/bin/pint --test
```

- Branch from `main` and keep the change focused.
- Add or update a test for every behavior change.
- For UI changes, add a screenshot to the pull request.
- The docs live in [`laravelify-cloud/laravelify-docs`](https://github.com/laravelify-cloud/laravelify-docs). Preview them with `npx mint dev`.

## Releases (maintainers)

1. Update `CHANGELOG.md`.
2. Update `VERSION`, then tag and push: `git tag v0.2.0-beta.1 && git push origin v0.2.0-beta.1`.
3. The **Release** workflow builds the assets and attaches `laravelify.tar.gz` and `install.sh` to the GitHub release. That is all: `https://cdn.laravelify.cloud/install.sh` is a small bootstrap (`scripts/get.sh`) that always runs the latest release's installer, so there is nothing to upload or purge. Changes to `scripts/get.sh` itself are published by the **Publish installer** workflow.
