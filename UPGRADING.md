# Upgrading

## 2.7.1 → 2.7.2

Concurrency-hardening follow-up to v2.7.1. **Fully safe**: no migrations, no
new config keys, no behavior changes for sequential use. The four fixes only
take effect under genuine race conditions; everything else is unchanged.

### Applied automatically (no action needed)

- **Backup codes are now atomically single-use.** Switched the `consume()`
  read-then-write to a conditional `WHERE used_at IS NULL` update — two
  concurrent verifies of the same code can never both succeed.
- **Phone (SMS / voice / WhatsApp) OTP verification is now atomically
  single-use.** Same pattern as the OTP path.
- **TOTP replay protection is race-safe.** The `last_totp_timestep` write is
  now a conditional `WHERE last_totp_timestep IS NULL OR < $step` update —
  only the request that **strictly advances** the step wins, even under
  simultaneous in-window verifies. Also enforces monotonic advancement.
- **Admin API-token PATCH + DELETE honour `api_tokens.admin_require_step_up`**
  (only `POST` did in v2.7.1). When the flag is on, mutating or revoking an
  admin-issued token requires the same fresh step-up as creating one.

### No new flags / migrations

This release adds nothing to your `.env`, your config file, or your schema. If
v2.7.1 runs for you, v2.7.2 does too.

---

## 2.7.0 → 2.7.1

This is a small **security-hardening + bug-fix** release. Safe to upgrade —
every behavior change is behind a config flag defaulting to today's behavior.

> **Important — read this if you are on `2.7.0`:** the published `v2.7.0` tag
> was placed on the commit *before* the v2.7 work was merged, so installing
> `joe-404/laravel-auth:v2.7.0` actually gives you the v2.6.1 code. Upgrading
> to **`v2.7.1`** brings you the **real** v2.7 security pass **plus** the
> v2.7.1 items below in one step.

### 1. Run the new migrations

```bash
php artisan migrate
```

| Migration | What it does |
|-----------|--------------|
| `widen_auth_two_factor_challenges_challenge_token` | Widens the column to `string(64)` so 2FA challenge tokens can be stored as their HMAC digest (not the raw UUID). |
| `add_creator_to_auth_api_tokens` | Adds nullable `created_by_id` / `created_by_type` so API tokens are attributable to the actor who issued them. |

### 2. Applied automatically (no action needed)

- **OTP + magic-link consumption is atomic.** A conditional `WHERE used_at IS NULL` update closes the race where two concurrent requests with the same valid code could both succeed.
- **2FA challenge tokens are stored as HMAC** (peppered with `APP_KEY`), matching how the package hashes every other bearer-style secret. The plaintext is returned to the client exactly once at creation. *Heads-up:* when the same user logs in repeatedly within the TTL the package still re-uses the row (no spam) but rotates the token — the latest call's token is the active one.
- **Trusted-device secret is HMAC-peppered.** Same consistency win. *Heads-up:* every currently-trusted device is re-challenged once after upgrade (the stored hash changes); users opt back into trust at the next 2FA prompt.
- **`AdminGate` middleware** replaces the hard-coded `role:` on the package's admin route groups, so admin gates can be config-swapped between roles and permissions without code changes. No-op for role-based hosts.
- **API tokens record their creator.** User-issued tokens: `created_by` = user; admin-issued unowned tokens: `created_by` = admin.

### 3. Opt-in flags (defaults preserve today's behavior)

| Env / config | Default | Turn it on to… |
|--------------|---------|----------------|
| `AUTH_PASSWORD_RESET_AUTO_LOGIN=false` (`password_reset.auto_login`) | `true` | After a reset: change the password, revoke every session, **don't** issue a token — the user logs in again. Best when the reset channel (email) might be briefly compromised. |
| `AUTH_API_TOKENS_ADMIN_REQUIRE_STEP_UP=true` (`api_tokens.admin_require_step_up`) | `false` | Require a fresh step-up before an admin can mint a token via the admin endpoint (mirrors the user-side flag). |
| `AUTH_ACCOUNT_STATUS_ADMIN_MIDDLEWARE=...` (`account.status.admin_middleware`) | `null` | Replace `role:<admin_ability>` with any role/permission spec, e.g. `'super-admin\|users.manage-status'`. Same for `AUTH_API_TOKENS_ADMIN_MIDDLEWARE` on the admin api-token routes. |
| `auth_system.response.hidden_user_fields` | `['password','remember_token']` | Add custom sensitive columns (e.g. `'two_factor_secret'`) to the always-stripped list — the defensive net stays in effect even if your User model omits `$hidden`. |

### 4. Security profile (new — opt in)

```dotenv
AUTH_SECURITY_PROFILE=high     # | balanced | relaxed | (unset)
```

A single env flips on a curated bundle of secure defaults. When set, the package fills in safe values for the profileable flags at boot — **but only when the corresponding env var is unset**, so any explicit `.env` value you set always wins. The **`high`** profile turns on: strict API-token abilities, user + admin token step-up, OAuth state enforcement, `email_and_ip` lockout scope, reset auto-login off, admin role hierarchy, admin status step-up, registration-device trust = `medium`, and required 2FA. **`balanced`** turns on the obvious anti-DoS / anti-CSRF flags. **`relaxed`** is a no-op (matches v2.7 defaults).

> Caveat: the env-aware gate cannot detect hardcoded values in a **published** config file. For profile-controlled keys, prefer env, or set `AUTH_SECURITY_PROFILE=` (unset) and configure each flag yourself.

---

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
