# Releasing Ledgerly API

Gitflow: work lands on `develop` through `feature/*` branches; `main` only ever receives
`release/x.y.z` (and `hotfix/*`) merges. **Pushing `main` deploys to production** — Render
redeploys on every push (`render.yaml`, `autoDeploy: true`), and the web app ships from its
own repo the same way (Vercel). Release both repos together with the same version number.

## 1. Before cutting a release
- [ ] Everything for the release is merged into `develop` (one `--no-ff` merge per story).
- [ ] CI is green on the **latest `develop` commit**: Pint, PHP 8.3 and 8.4 tests, **PostgreSQL**
      and MySQL tests, and the Docker image build/boot. Production runs PostgreSQL and
      `php:8.3-apache`, so the pgsql and Docker jobs are the ones that matter most.
- [ ] `composer audit` is clean. Don't take dependency upgrades that need PHP 8.4.
- [ ] New migrations are listed in the release notes (they run automatically on boot).
- [ ] New environment variables, if any, are added in Render **before** releasing.

## 2. Cut and publish
```bash
git fetch origin
git switch -c release/1.4.0 origin/develop          # bump nothing here: the API has no version file
git switch main && git pull --ff-only
git merge --no-ff release/1.4.0 -m "Merge release/1.4.0 into main"
git diff --quiet release/1.4.0 HEAD && echo "main matches the release"
git tag -a v1.4.0 -m "Ledgerly 1.4.0 (Sprint N) ... release notes ..."
git push origin main v1.4.0                          # ← deploys
git switch develop && git merge --no-ff release/1.4.0 -m "Merge release/1.4.0 into develop"
git push origin develop
git branch -d release/1.4.0
```
Tag notes list the stories (US-xx), migrations and anything operators must do.

## 3. After deploying
- [ ] CI is green on `main`.
- [ ] Render deploy finished; the log shows migrations ran and the admin check passed.
- [ ] `GET /up` returns 200; signing in works.
- [ ] Smoke test: dashboard, trial balance, one export (CSV and PDF), and any screen the release changed.
- [ ] If a migration seeds reference data (e.g. tax codes), spot-check it in the app.

## Hotfixes
Branch `hotfix/x.y.z` from `main`, fix, release to `main` with a tag as above, then merge the
hotfix into `develop`.

## Rolling back
- **Code only:** in Render, redeploy the previous successful deploy (or `git revert` the
  release merge on `main` and push). Tags stay; don't move or delete them.
- **With migrations:** migrations are written to be additive. Prefer rolling forward with a
  fix; only run `php artisan migrate:rollback --step=N` against production after a database
  backup and when the migration's `down()` is known to be safe.
