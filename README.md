# deploy-kit

Deployer recipes for zero-downtime Laravel deployments (Caddy + PHP-FPM, code delivered by rsync).

## Versions

- Deployer: **8.0.5**, the newest stable release (published 2026-05-18, verified on Packagist and the GitHub releases of deployphp/deployer). Pinned as `^8.0.5`. No 7.x fallback was needed.
- PHP: Deployer 8.0.5 requires `^8.3`. The package manifest says `^8.2` as specified, but Deployer itself will not install below 8.3.
- Confirmed present in 8.0.5 (read from the installed source): `localhost()`, `upload()`, `set()`/`get()`, `task()`/`before()`/`after()`/`fail()`, the `recipe/common.php` tasks `deploy:setup`, `deploy:lock`, `deploy:release`, `deploy:shared`, `deploy:symlink`, `deploy:unlock`, `deploy:cleanup` and `rollback`, and the `keep_releases` setting (Deployer's default is 10).
- `localhost()` runs commands on the local machine without SSH (checked with a trivial task).

## Tests

```
composer install
vendor/bin/phpunit
```
