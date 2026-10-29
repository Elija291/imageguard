# ImageGuard — secure raw-PHP AI image detection

A project using **one Ubuntu Docker container**, **Apache**, **MySQL** and **plain PHP**. No PHP framework, Composer dependency, Docker Compose, or Python inference service is used by the application.

## Start here

The complete source is included. Automated image classification uses Sightengine's documented `genai` API and **requires your own API user/secret and available quota**. The app starts without API keys but correctly disables analysis; it does not invent scores. The SynthID hint is addressed with an explicit official verification workflow, not a fake local watermark detector.

### 1. Prepare

On a computer or Linux VM with Docker Engine installed and running:

```bash
unzip imageguard-project.zip
cd imageguard
cp .env.example .env
chmod 600 .env
nano .env
```

Set `APP_PASSWORD` to a unique password of 16–72 bytes for the reserved **admin** username. Generate one locally with `openssl rand -hex 24` if available. Replace the example password. This is the administrator password, not a MySQL password. Public registration always creates an ordinary user.

Create your own account at https://dashboard.sightengine.com/ and copy its API user and secret into `.env`:

```text
APP_PASSWORD=your-own-long-random-password
SIGHTENGINE_API_USER=your-api-user
SIGHTENGINE_API_SECRET=your-api-secret
COOKIE_SECURE=0
DAILY_API_LIMIT=100
```

Do not paste real keys into chat or include `.env` in your submission. Check the provider's current plan, data policy and quota before use. Do not assume API use is unlimited or free.

### 2. Build and run

```bash
docker build --pull -t imageguard:1 .
docker volume create imageguard-db
docker run -d --name imageguard \
  --restart unless-stopped \
  --env-file .env \
  --security-opt no-new-privileges:true \
  --memory=1536m --cpus=2 --pids-limit=256 \
  -p 127.0.0.1:8080:80 \
  -v imageguard-db:/var/lib/mysql \
  imageguard:1
```

This binds the website to localhost and publishes no MySQL port. Allow roughly 1–2 minutes for initial database setup. The initial image build needs internet access to Ubuntu repositories; classification needs outbound HTTPS to `api.sightengine.com`.

```bash
docker ps
docker logs --tail 80 imageguard
docker inspect --format='{{.State.Health.Status}}' imageguard
```

Open **http://localhost:8080**, sign in as `admin` with `APP_PASSWORD` (or create a user account), choose a picture, tick the external-processing consent checkbox, and select **Analyse image**.

If Docker runs on your Ubuntu server, access it from your own computer through SSH (replace the host with its Tailscale IP or hostname):

```bash
ssh -L 8080:127.0.0.1:8080 sam@YOUR_SERVER
```

Then open http://localhost:8080 on that computer. Keep the terminal open. No changes to Proxmox networking are necessary.

### 3. Interpret the result

| Provider score | App label |
|---|---|
| 0.00–0.20 | Likely non-AI |
| Greater than 0.20 and less than 0.80 | Uncertain |
| 0.80–1.00 | Likely AI-generated |

These are explicit demonstration thresholds, not measured accuracy or a guarantee of authenticity. A score is the provider's estimate. Original images are not stored in MySQL; the app records a digest, dimensions, type, score, label and time. History belongs to the signed-in account and remains available after signing out and back in. Records older than seven days are pruned when a subsequent analysis succeeds; this is not a strict scheduled deletion service.

### 4. Use the SynthID hint correctly

Click **Open Google's SynthID Detector** and upload the same original file there. The link does not send your image automatically. Record the portal's actual result separately in your demonstration.

SynthID is an embedded-watermark system. A non-detection cannot prove that an image is human-made. Searching for the word `SynthID` in EXIF, prompting a vision model to guess, or running the open-source SynthID Text package does not verify image watermarks. This project does not claim native SynthID detection; its automatic pathway is the separate Sightengine classifier. If your assessor requires automated SynthID specifically, obtain an authorized documented image-verification API from them; do not present this classifier as SynthID.

## Project files

| Path | Purpose |
|---|---|
| `Dockerfile` | Ubuntu 24.04 LAMP image |
| `docker/entrypoint.sh` | Initialize database and start services |
| `docker/init.php` | Schema, limited DB user, private runtime configuration |
| `docker/apache.conf` | Web root, headers and request restrictions |
| `docker/mysql.cnf` | Local socket only; network and local-infile disabled |
| `docker/php.ini` | Upload/session/resource and PHP hardening |
| `docker/supervisord.conf` | Run Apache and MySQL in the required single container |
| `app/bootstrap.php` | Sessions, CSRF helpers, PDO and atomic rate limits |
| `app/accounts.php` | Registration, password verification, account status and admin actions |
| `app/detector.php` | Real image validation and fixed-endpoint API client |
| `app/health.php` | Container health check for database and web service |
| `public/index.php` | Login, consent, upload, results and history |
| `public/style.css` | Responsive interface; no third-party scripts/fonts |
| `tests/unit.php` | Deterministic detector/parser/upload tests |
| `tests/accounts.php` | Isolated MySQL account, session-revocation and migration tests |
| `tests/smoke.sh` | Basic container HTTP and hardening checks |
| `docs/SECURITY.md` | Threat model and review checklist |
| `docs/VALIDATION.md` | Actual verification status and acceptance checks |

## User accounts and administrator

- Visitors choose a username (3–32 ASCII letters, digits or underscores) and their own password (16–72 bytes). Usernames are case-insensitive. Passwords are hashed, never stored in plaintext.
- Sign in as **admin**, using `APP_PASSWORD` from your private `.env`. Select **Manage users** to block, unblock or delete ordinary accounts. Admin cannot be blocked or deleted through this screen.
- Blocking invalidates existing sessions on their next request and prevents login. Unblocking does not restore old sessions: the user must sign in again. Requests already sent to Sightengine cannot be recalled, but blocked/deleted users cannot save the returning result.
- Deleting requires confirmation and removes the account and its saved analysis history. A deleted username may be registered again with a new account ID; old sessions cannot access it. Use blocking to retain a disabled account. Public signup cannot prevent the same person registering a different username.
- All accounts use the administrator's server-side Sightengine credentials. No user form exposes or accepts provider credentials. Existing image validation, classification and global API budget are unchanged.
- Registration is limited to 3 attempts per source IP per hour and 30 globally per hour. Existing login/analysis limits remain in place. Behind a proxy, IP-based limits are shared until trusted-proxy handling is configured; do not trust arbitrary forwarded headers.
- This change does not add email verification, password-reset email or additional administrator creation.

### Upgrade an existing deployment

Back up the MySQL volume first. Build the updated source as a new image tag (for example `imageguard:2`), then recreate the container with the **same database volume and private environment file**. Do not remove the volume. The old `elijsam/imageguard:1` image does not contain these changes.

Startup creates the users table and seeds `admin` automatically. Existing analyses and rate-limit counters remain; old browser-session history is not automatically assigned to any new user. Old shared-password sessions must sign in again. Changing `APP_PASSWORD` and recreating the container updates only the admin password and invalidates previous admin sessions. Ordinary users and their password hashes survive recreation. New admin passwords must be 16–72 bytes, including when upgrading.

## Tests

```bash
docker exec imageguard php /opt/imageguard/tests/unit.php
docker exec imageguard php /opt/imageguard/tests/accounts.php
bash tests/smoke.sh
```

The account test creates a randomly named temporary MySQL database, tests registration and administrative actions, and drops only that test database afterward. Run it as the container's default root user. Tests do not send images to the API or consume quota. For end-to-end verification use images you own: one original camera photograph and one known generated image. A wrong classification is a model limitation, not a reason to hard-code results. Test failure handling with missing/invalid keys too.

## Operations

Restart: `docker restart imageguard`. Stop: `docker stop imageguard`.

Changing `.env` requires recreating the container (a restart does not reload Docker environment variables):

```bash
docker stop imageguard
docker rm imageguard
# Repeat the docker run command above, reusing imageguard-db.
```

Keep the volume to preserve stored metadata and rate-limit buckets. This project intentionally has no web-based SQL console, admin shell or phpMyAdmin.

For a remote public deployment, put an HTTPS reverse proxy in front of the localhost binding and set `COOKIE_SECURE=1` before recreating the container. Keep the HTTP listener private behind the proxy; public visitors must use HTTPS. Proxy addresses are not blindly trusted: the app uses `REMOTE_ADDR`; behind a proxy, visitors share its rate-limit bucket unless a carefully constrained real-IP configuration is added.

Rebuild regularly with `docker build --pull --no-cache -t imageguard:1 .` for OS security updates. Runtime API settings are not copied into the image. Docker administrators can still inspect container environment variables; protect host access.

## Troubleshooting

- **Setup needed:** both API credentials must be set; recreate the container after editing `.env`.
- **Service unavailable:** inspect `docker logs imageguard`, verify account/quota and outbound DNS/HTTPS. No successful score is stored on provider failure.
- **403 after form submission:** reload the page; the session or CSRF token may have expired. Uploads over PHP's POST limit also lose form fields.
- **429:** login limit is 10 attempts/IP/15 minutes and 100 globally; analyses are 5/IP/minute plus the global daily budget. Failed provider calls count toward that budget.
- **Large image rejected:** reduce dimensions below 8 megapixels and at most 6,000 pixels per side, and file size below 8 MiB. Keep the original for the separate SynthID check.
- **Cannot stay signed in on HTTP:** use `COOKIE_SECURE=0` only for localhost/SSH access; secure cookies require HTTPS.
- **Container unhealthy:** verify MySQL startup in logs and available disk/RAM. The health check does not test external detector credentials.

## Sources checked on 22 October 2026

- https://sightengine.com/docs/ai-generated-image-detection — request and response contract.
- https://deepmind.google/models/synthid/ — watermark purpose and official verification portal.
- https://synthid.com/ — manual watermark verification entry point.

## Submission contents

Submit this source folder without `.env`, API secrets, database volumes or private images. For your demonstration, show the Dockerfile, container health, upload validation, a live result, MySQL metadata, and the official SynthID verification separately. See `docs/VALIDATION.md` for the honest test record.
