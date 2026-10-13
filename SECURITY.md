# Security policy

Laravelify runs with access to your server, so security reports are taken seriously.

## Reporting a vulnerability

**Do not open a public issue.** Use GitHub's private reporting instead:
repository → **Security** → **Report a vulnerability**.

Include the version (sidebar → GitHub, or `cat /opt/laravelify/VERSION`), the steps to reproduce, and the impact. You will get an answer within a few days, and a fix is released as soon as possible. You are credited in the release notes unless you prefer not to be.

## Supported versions

Only the latest release gets security fixes. Update with:

```bash
sudo bash /opt/laravelify/scripts/update.sh
```
