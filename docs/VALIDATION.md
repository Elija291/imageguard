# Verification record — 22 October 2026

## Executed successfully in the development workspace

- PHP 8.3 syntax validation: all six PHP files passed.
- `tests/unit.php`: **25 checks passed** using PHP 8.3 with GD and Fileinfo.
  Covered score boundaries, out-of-range/nonfinite scores, malformed/provider-failure responses, executable-text/SVG/empty rejection, real JPEG/PNG/WebP decoding and file/dimension limits.
- Bash syntax: entrypoint and smoke-test scripts passed `bash -n`.
- Isolated PHP built-in-server checks: login rendered with HTTP 200 and no-store; missing CSRF returned 403; a valid-CSRF analysis attempt without authentication returned 401. Temporary test paths/configuration were substituted in the test copy only. These checks did not use Apache or MySQL.

## Not executed here

Docker Engine was unavailable. A full image build, Apache configuration validation, MySQL initialization/grants, container health check, restart/persistence checks, and the included container smoke test remain to be run on a Docker host. No API credentials were supplied, so no external image classification call or accuracy evaluation was performed. Do not describe these as passed.

## Acceptance run on your Docker host

1. Follow README.md to configure, build and start the container.
2. Confirm the container becomes healthy; inspect logs for initialization failures.
3. Run `docker exec imageguard php /opt/imageguard/tests/unit.php`.
4. Run `bash tests/smoke.sh` from the extracted source directory.
5. Log in; upload an owned valid photograph with consent. Confirm a real provider score, dimensions, digest and history row.
6. Repeat with a known generated image. Record actual results, even if surprising; this is not an accuracy benchmark.
7. Restart the container; verify health and database persistence. Existing logged-in browser sessions may persist because the container writable layer is retained; recreating it loses sessions.
8. Confirm spoofed extensions, excessive sizes, bad tokens and private paths are rejected. Follow the authorized security checklist.
9. Verify the original image separately using the official SynthID portal. Label this as an external manual check.
10. Review error handling with missing/invalid provider credentials, no quota and unavailable network. No verdict should be recorded on failure.

The code and test scripts are ready for this acceptance run; live deployment and provider integration have not been certified in this workspace.
