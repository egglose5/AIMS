# AIMS Headless Deployment Profiles

This document defines how the current headless AIMS Core should be understood across different hosting environments.

## Current Reality

The current standalone `ames-core` build should be treated as an `IONOS-style` deployment profile, not a universal WordPress-host profile.

Today the headless path assumes:

- writable filesystem directories for `ames-core/sink`, `ames-core/vault`, `ames-core/logs`, and `ames-core/config`
- direct execution of a standalone PHP entrypoint at `ames-core/index.php`
- reliable local persistence for SQLite and archive files
- server behavior that honors `.htaccess` and/or `web.config` protections
- PHP extensions such as `pdo_sqlite` for the current shared-host storage path

Not every WordPress host exposes those capabilities. Because of that, the current headless implementation should not yet be described as host-agnostic or safe for all WordPress hosting environments.

## Deployment Profiles

### IONOS-Style Shared Host Profile

This is the current active headless profile.

- standalone `ames-core` router
- SQLite-backed hot operational store
- local filesystem directories for sink, vault, logs, and config
- local Parquet/archive strategy
- WordPress plugin acting as a thin client where possible

This profile is useful because it keeps heavy operational writes away from the WordPress runtime and takes advantage of filesystem access when the host allows it.

#### Logged IONOS Findings

The current IONOS findings support treating this as a plausible standalone-sibling deployment profile, but not yet a universally verified one.

- IONOS Click & Build WordPress runs as part of a web-hosting/webspace model rather than as a sealed runtime appliance.
- IONOS webspace plans advertise manual application upload/installation, PHP, MySQL, SFTP, SSH, and WP-CLI access.
- IONOS documents FTP/SFTP-style upload into webspace, which matches the practical reality that a customer may have multiple file-access entry points for projects in the same hosting account.
- IONOS documents SFTP/SSH account creation and says those accounts can target `/`, meaning broad webspace access is possible when the contract supports it.
- IONOS also warns that directory-limited SFTP accounts are not true isolation boundaries, so a sibling-app deployment should not automatically be treated as strongly isolated from the WordPress install.
- IONOS documents PHP CLI execution for Linux hosting packages with SSH, which makes a standalone PHP application beside WordPress plausible when SSH is available.

These findings mean a custom PHP application beside WordPress is likely possible on at least some IONOS hosting plans. They do not, by themselves, prove that every IONOS Click & Build contract exposes the exact filesystem layout, entrypoint routing, or extension set that the current `ames-core` build expects.

#### Sources

- Click & Build WordPress: <https://www.ionos.com/help/hosting/click-build-applications/install-wordpress/>
- Webspace features and manual upload/install: <https://www.ionos.com/hosting/webspace>
- Creating an SFTP/SSH account: <https://www.ionos.com/help/hosting/setting-up-and-managing-ftp-access/creating-an-sftpssh-account/>
- Executing PHP files via PHP CLI: <https://www.ionos.com/help/hosting/using-php-for-web-projects/executing-php-files-via-the-command-line-php-cli/>

#### Still Unverified

The following still need live-host or VM confirmation for the user's exact IONOS setup:

- whether `ames-core/index.php` can be exposed cleanly beside the Click & Build WordPress install
- whether sibling writable directories behave the way the current headless build expects
- whether `pdo_sqlite` and required PHP runtime features are available in the target contract
- whether the desired separation is better implemented as a sibling directory, subdomain, or separate webspace

### Generic WordPress Host Profile

This profile is not fully built yet and should be treated as a planned fork point.

- same AIMS domain rules
- same API contracts
- same bucket/custody/FIFO logic
- different storage/runtime assumptions
- likely MySQL or MariaDB-backed stores instead of SQLite/file-heavy hot paths
- no assumption that the host exposes writable sibling directories or direct standalone routing in the same way

### Standard VPS / Cloud Profile

This is the long-term richer profile.

- standalone AIMS Core service
- dedicated database
- background jobs/workers
- stronger scheduling, queueing, and archive options
- same domain layer, different infrastructure

## Architectural Rule

The business logic should remain shared across profiles:

- inbound records capture cost
- internal movement records stay lean
- outbound sale records capture the actual amount paid
- buckets, custody, FIFO, and physical truth belong to AIMS Core

Only the deployment/runtime layer should vary by host profile.

## Required Fork Point

Because the original split happened after filesystem-heavy headless work had already begun, the portability fork point needs to be formalized now rather than treated as already solved.

That fork point should be:

1. domain layer shared
2. storage adapters swappable
3. deployment profile selected per host environment

## Current Documentation Rule

Until the generic-host storage/runtime profile exists, repository docs should describe the current headless path as:

- `IONOS-style`
- `filesystem-capable shared-host profile`
- `not yet guaranteed for all WordPress hosts`
