# Upgrading

## 2.6.x → 2.7.0

This is a **security-hardening** release. It is designed to be a **safe,
non-breaking upgrade**: every behavior change that could affect an existing
integration is behind a config flag that **defaults to the current behavior**.
You can upgrade, run the migrations, and ship — then opt into the hardened
posture flag by flag.

### 1. Required: run the new migrations

```bash
php artisan migrate
```

Two migrations are added:

| Migration | What it does | Risk |
|-----------|--------------|------|
| `widen_auth_otp_codes_type` | Changes `auth_otp_codes.type` from an `enum` to `string(40)` | None — superset of the old values |
| `add_last_totp_timestep_to_auth_two_factor_methods` | Adds a nullable `last_totp_timestep` column | None — additive |

> **Why the first one matters:** the `type` enum never included the 2FA email
> purposes (`two_factor_email`, `two_factor_email_enroll`), so **email-based 2FA
> could not store its code on strict MySQL / PostgreSQL / SQLite** (the insert
> was rejected). If you offer email 2FA, this migration is what makes it work.

### 2. Applied automatically (no action needed)

These are pure fixes/hardening with no API change:

- **Login no longer leaks account existence via timing** — a missing-email
  login now performs the same bcrypt work as a wrong-password login.
- **Token-refresh responses** are now run through the same `password` /
  `remember_token` stripping as every other response (`safeUserArray`).
- **Step-up cache keys are UUID-safe** — the sudo / 2FA-stamp keys are now
  consistent across `PasswordConfirmController`, `RequireStepUp`, and
  `Require2FA` (previously the latter cast the user key to `int`, breaking
  step-up for string/UUID primary keys).
- **TOTP codes can no longer be replayed** within their validity window
  (the verified time-step is recorded and re-use is rejected — RFC 6238 §5.2).
- **`POST /auth/2fa/challenge/switch`** is now rate-limited (it delivers a code)
  and **`POST /auth/password/confirm`** is throttled **per user** so a hijacked
  session can't brute-force the account password for a sudo window.
- **Registration input can no longer self-assign** the package's own gating
  columns (`account_status`, `phone_verified_at`, `two_factor_required`, …).
- **Boot fails fast if `APP_KEY` is missing** — it is the pepper for OTP /
  backup-code hashing and the key for 2FA-secret encryption.

### 3. Opt-in hardening (flags default to today's behavior)

Flip these on for the strongest posture. Each is **off by default** so nothing
changes on upgrade.

| Env / config | Default | Turn it on to… | Heads-up when enabled |
|--------------|---------|----------------|-----------------------|
| `AUTH_2FA_REQUIRED=true` (`two_factor.required`) | `false` | Force enrollment: package routes return `must_enroll_2fa` until the user enrolls (enroll/login/logout/`me`/`password/confirm` stay reachable) | Users without 2FA can only reach the enrollment endpoints until they enroll |
| `AUTH_API_TOKENS_STRICT=true` (`api_tokens.strict_abilities`) | `false` | Restrict user-issued API-token abilities to `api_tokens.grantable_abilities`; forbid self-granting `*` (admins keep `*`) | Clients that self-issued broad/`*` abilities will be rejected — review `grantable_abilities` first |
| `AUTH_API_TOKENS_REQUIRE_STEP_UP=true` (`api_tokens.require_step_up`) | `false` | Require a fresh step-up (sudo password / 2FA) before creating an API token | Automated token creation must first call `POST /auth/password/confirm` |
| `AUTH_API_TOKENS_MAX_TTL_DAYS=365` (`api_tokens.max_ttl_days`) | `null` | Cap (and stop) non-expiring user tokens | Requests over the cap are clamped; non-expiring requests get the cap |
| `AUTH_ACCOUNT_STATUS_HIERARCHY=true` (`account.status.admin_actions.enforce_role_hierarchy`) | `false` | An admin may only change a strictly lower-ranked account — never a peer, a higher role, or themselves; `deleted` can't be set via the status endpoint | Set `account.status.admin_actions.role_ranks` to match your roles |
| `AUTH_SOCIAL_ENFORCE_STATE=true` (`social.enforce_state`) | `false` | Verify a one-time server-managed OAuth `state` on the **stateless** (mobile/SPA) callback | Stateless clients must round-trip the `state` from the redirect URL |
| `AUTH_LOCKOUT_SCOPE=email_and_ip` (`security.lockout.scope`) | `email` | Lock the (email, IP) pair instead of the email alone — stops a known-email **targeted lockout DoS** | A distributed attack still trips per-IP locks; legitimate owners on other IPs are unaffected |
| `AUTH_TRUST_REG_DEVICE_LEVEL=medium` (`trusted_devices.registration_device_level`) | `high` | Record a lower starting trust for the registration device | Cosmetic unless you use a custom resolver — effective trust is already time-based (a fresh device resolves to `low`) |

### Recommended production profile

```dotenv
AUTH_2FA_REQUIRED=true
AUTH_API_TOKENS_STRICT=true
AUTH_API_TOKENS_REQUIRE_STEP_UP=true
AUTH_ACCOUNT_STATUS_HIERARCHY=true
AUTH_SOCIAL_ENFORCE_STATE=true
AUTH_LOCKOUT_SCOPE=email_and_ip
```

### Notes

- `api_tokens.mode` (`customer_auth` | `third_party`) documents how you treat
  user vs. machine tokens; it does not change runtime auth on its own.
- A strict-MySQL CI job now runs the full suite; if you maintain a fork, set
  `DB_CONNECTION=mysql` to run the suite against MySQL locally.
