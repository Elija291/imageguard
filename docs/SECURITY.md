# Security design and review

## Threat model

Untrusted images and filenames, hostile HTTP clients, password guessing, cross-site requests, SQL injection, stored/reflected HTML injection, malicious file execution, API cost exhaustion and accidental disclosure of credentials. The app is a shared-password coursework workspace, not a multi-tenant production service.

## Implemented controls

- Authentication uses a password hash; regenerate session IDs on login/logout. Thirty-minute idle expiry and strict, HttpOnly, SameSite cookies. Secure flag is configurable for HTTPS.
- All state-changing forms require a session CSRF token, including sign-in and history deletion.
- PDO prepared statements; the web DB account cannot create tables, grant permissions or read arbitrary databases. MySQL has no TCP listener and no published port.
- Upload error, actual size, MIME, image header, dimension and pixel checks precede GD decoding. JPEG/PNG/WebP only; SVG and HTML rejected. A filename or client MIME value is never trusted or used for storage.
- Uploads stay in PHP's private temporary directory, outside the web root. Originals are neither served nor included as PHP. Cleanup occurs after the request; PHP also cleans uploaded temporary files at request end. A crafted image with extra bytes cannot become an executable web resource.
- Outbound API host/path are fixed. No user-provided URLs, redirects or disabled TLS verification. Connection/total timeout and response-body limit apply. No raw API errors, credentials or responses are shown.
- Atomic DB rate-limit buckets resist resetting cookies. Daily global request budget limits cost; metadata history is scoped to a random session owner. Failed API requests count toward the budget.
- Output escaping, restrictive CSP, frame denial, no-sniff and no-store. No inline JavaScript, remote libraries or analytics. Only public/ is served.
- Root-owned application files, limited web user, disabled shell execution functions. Supervisord needs root for service setup, but Apache workers and MySQL run as their own users.
- Docker starts with no-new-privileges, memory/CPU/PID limits, localhost-only HTTP. No host Docker socket or privileged mode is required.

## Known boundaries

This is not a guarantee of resistance to all attacks. Image decoding libraries and OS packages must stay patched. Application compromise may expose the configured API secret and the limited DB credentials. Root/container/host administrators can read secrets; do not give untrusted reviewers host Docker permissions. Do not use privileged mode.

One-container LAMP reduces isolation between database and web server but follows the assignment. High-volume traffic and spoofed/shared networks still require perimeter controls. API outages, quota exhaustion and provider model changes are outside the app's control. Shared login is unsuitable for independent customer accounts. The classifier may produce false positives/negatives; it is not forensic proof.

IP keys in rate-limit storage are HMAC-derived; standard Apache access logs still contain client IPs and request paths. Set an appropriate log retention policy. Stored image hashes can act as identifying fingerprints. Upload consent does not override a provider's policy; obtain permission for images you submit.

## Authorized class review checklist

Test only your assigned local container:

1. Access without signing in: only login and explanatory text should appear.
2. Send POST without a valid CSRF token: expect 403.
3. Submit a text/PHP/SVG file renamed .jpg: expect rejection and no stored upload.
4. Upload an image above size/pixel limits: expect rejection before the API call.
5. Use quotes/HTML in request fields: expect no SQL or executable markup.
6. Try another session's result ID: no result should be returned.
7. Repeat requests past the rate limit, including with fresh cookies: expect 429.
8. Attempt direct access to `.env`, app/, docker/ or uploads/: expect 403/404.
9. Use invalid API credentials: no score should be fabricated or persisted.
10. Inspect open ports: only the bound web port should be accessible; MySQL stays on a local Unix socket.
