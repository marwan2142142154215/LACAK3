# SMB

SMB is a device operations platform with a Laravel API, AdonisJS realtime gateway, Vue dashboard, SMB Master Android app, SMB Lacak Android agent, and a Windows launcher. PostgreSQL is the source of truth. Redis holds transient state and presence. Remote commands are authorized and persisted by Laravel before the gateway delivers them.

## Run locally on Windows

Requirements: Docker Desktop with Compose, PowerShell, Git, and (for Android builds) JDK 17 plus Android SDK 36.

1. From a clean checkout, create local environment files. The script generates independent random local credentials, does not print them, and refuses to overwrite existing `.env` files.

   ```bash
   powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\scripts\Initialize-SmbLocal.ps1
   ```

2. Build and start the local stack.

   ```bash
   docker compose up -d --build
   ```

3. Apply migrations, install roles and permissions, then create the first administrator. The command prompts for a username and password without echoing the password.

   ```bash
   docker compose exec api php artisan migrate --force
   ```

   ```bash
   docker compose exec api php artisan db:seed --class=RolesAndPermissionsSeeder --force
   ```

   ```bash
   docker compose exec api php artisan smb:bootstrap-admin --name="SMB Administrator" --username="mastermarwan"
   ```

4. Open the dashboard at `http://localhost:8080`. The API is available at `http://localhost:8000`; its liveness endpoint is `/up` and its dependency report is `/api/v1/health`. First login requires TOTP enrollment. Configure TOTP before adding other administrators.

Compose does not start Cloudflare Tunnel. The Windows launcher can start the stack and check the installed Cloudflared Windows service; use it only on a host configured for that tunnel. For local work, Compose services are bound to loopback and do not expose PostgreSQL, Redis, Laravel, or the gateway to the LAN.

To stop the containers without deleting database volumes:

```bash
docker compose stop
```

## Local tests and builds

Laravel uses a separate PostgreSQL test service backed by temporary storage. It does not run against the application database:

```bash
docker compose --profile test run --build --rm api-test
```

Web tests and production build:

```bash
cd smb-web
npm ci
npm test -- --run
npm run build
```

Gateway tests, typecheck, and build:

```bash
cd smb-gateway
npm ci
npm test
npm run typecheck
npm run build
```

Android debug checks (run in each Android project):

```bash
.\gradlew.bat testDebugUnitTest assembleDebug lintDebug
```

Device instrumentation tests require USB debugging and authorized ADB devices:

```bash
.\gradlew.bat connectedDebugAndroidTest
```

## Current published APKs

The authenticated Download Center is [app.lacaksmbbot.com/downloads](https://app.lacaksmbbot.com/downloads). Releases are stored on the server's private persistent disk. The publication command verifies the APK signer fingerprint and re-reads the stored file to check its SHA-256 before adding it to the catalog. Links are short-lived and download access requires an authenticated user.

| App | Release | Version code | SHA-256 |
| --- | --- | ---: | --- |
| SMB Lacak | 1.1.0-beta.3 | 4 | `f86d97a5d7e037fc525722c3c14e23190063f7bb80830a8c30e666c9f0632b7a` |
| SMB Master | 1.1.0-beta.5 | 6 | `f1937bcfadba2389298b7f63fa1dafae559699fc349537299dbcfa11248dde47` |

Both APKs use the same release signing certificate and target Android API 36 with minimum API 26. The Download Center requires a Site/Team registration session for SMB Lacak. After installing, Lacak still requires the corresponding one-time registration code to activate. A copied APK can open its activation screen; it cannot register or start the managed agent without a valid server-issued code. Android does not provide a reliable way for an app to prove which website/browser delivered its APK. Packages with Site/Team credentials embedded into the binary and zero manual activation are not implemented.

## System services and endpoints

| Service | Local address | Responsibility |
| --- | --- | --- |
| SMB Web | `http://localhost:8080` | Admin dashboard and authenticated Download Center |
| SMB API | `http://localhost:8000` | Login/2FA, RBAC, sites, devices, registration, commands, locations, audit, health |
| SMB Gateway | `http://localhost:3333` and WSS `/ws` | Device WebSocket, authenticated session and realtime delivery |
| PostgreSQL | loopback port 5432 | Durable records and source of truth |
| Redis | internal/loopback | Cache, replay guard, and expiring presence |

Public staging domains use Cloudflare Tunnel: `app.lacaksmbbot.com`, `api.lacaksmbbot.com`, and `ws.lacaksmbbot.com`. The Windows `.exe` is a local launcher; it is not a cloud server and does not itself run on the Internet. The launcher executable is currently unsigned, so Windows Application Control may block it. `SMB Server.cmd` and the PowerShell Doctor are available as alternatives.

## Implemented flows

- Username/password login, first-login TOTP enrollment, TOTP verification, recovery codes, Sanctum sessions, user/role administration, and server-side permission/scope checks.
- Site/team management, one-use registration codes, device credentials stored as hashes, heartbeat, location history, and network policy violation records/Telegram notifications.
- PostgreSQL-backed command outbox, gateway delivery, acknowledgements, HTTPS fallback sync, durable Android receipts, and honest command states. A queued request is not reported as device success.
- SMB Master inventory, summary dashboard, device details, site/team access, sessions, audit, location request, command requests, and BLE scan UI according to granted permissions.
- SMB Lacak registration, encrypted device credential storage, WSS with HTTPS fallback, heartbeat, location request handling, BLE ephemeral advertisement, and Device Owner-only lock behavior.
- Telegram polling and server-mediated actions when a bot token is configured. Telegram identities require explicit user mapping and approval.
- Disk health checks perform actual write/read/delete operations. Release artifacts are checksum checked and served through short-lived signed URLs.

## Known limits and release blockers

This repository is not a claim of complete production acceptance. Current verified physical devices are Galaxy A05s (Android 15/API 35) and Galaxy A06 (Android 16/API 36); the complete API 26–36 compatibility matrix and registered-device BLE/command flows are not verified. Android may restrict background service execution; reliable zero-touch enrollment/always-on behavior requires supported Android Enterprise or OEM provisioning. Camera capture, remote keyguard unlock, a signed Windows executable, backup/restore drills, and full security/load/recovery acceptance remain incomplete.

The Gateway dependency audit currently reports 10 high-severity findings through `braces` ≤3.0.3, with no patched version published in the advisory. Do not downgrade AdonisJS major versions as an automatic fix. See [GHSA-vfj7-8cjw-p6xm](https://github.com/advisories/GHSA-vfj7-8cjw-p6xm). Web npm audit currently reports zero findings. Production Cloudflare edge rate-limit rules also need a Cloudflare token with ruleset edit permission; origin Laravel/Nginx throttles remain enabled.

The previous Cloudflare and R2 credentials were shared in chat and must be rotated before production use. No such credentials, `.env` files, release keystore, APKs, runtime storage, or database backups belong in Git. Local `.env` files, `.secrets/`, `runtime/`, `.tools/`, signing keys, and APK artifacts are ignored.

## Repository workflow

Use a feature branch and open a review before merging to `main`. Never commit production data or secrets. The staging deployment is separate from a GitHub push; source must be reviewed and tested before it is published.
