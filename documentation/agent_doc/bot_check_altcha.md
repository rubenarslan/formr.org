# `bot_check` — self-hosted Altcha + Argon2id

The `bot_check` item is a **local-only, GDPR-clean "are you human" gate** for
form_v2. It is backed by [Altcha](https://altcha.org) (MIT) running in its free
**self-hosted proof-of-work** mode — *not* the Sentinel SaaS. The challenge is
minted and verified entirely on the formr server; nothing about the participant
(IP, headers, behaviour) leaves the box, so it adds **no GDPR processor
relationship** the way Cloudflare Turnstile / reCAPTCHA `siteverify` would.

> Altcha replaced an earlier *custom* hand-rolled PoW implementation, so the two
> could be compared side-by-side. That work was prototyped on `feature/altcha`
> and now lives on `feature/form_v2` — this document describes the shipped
> Altcha implementation, not a branch-local experiment. See **§7 Comparison**
> for what changed and why.

## 1. Why a PoW gate, and why self-hosted

- **What it stops:** the dominant survey-fraud classes — direct-to-endpoint HTTP
  bots (no JS engine at all) and naive headless/JS automation that won't pay the
  memory-hard cost at scale. Each solve burns real RAM-bound work; the *server*
  pays ~7 ms (one verification hash), the *client/farm* pays the whole search.
- **What it does NOT stop:** a clean real browser driven by OS-level input, or a
  human click-farm. No self-hosted check can. This is a cost/friction gate, not
  an identity proof. Don't advertise it as bot-proof.
- **Why not Turnstile/reCAPTCHA:** those ship the participant's IP + request
  metadata to a US service on every solve — a processor relationship a research
  ethics board has to paper over, and "freely given consent" is dubious when the
  check is a precondition to participating. Self-hosted PoW sidesteps all of it.

## 2. Components / files

| Layer | File | Role |
| --- | --- | --- |
| Item | `application/Model/Item/BotCheck.php` | Renders `<altcha-widget>`; `validateInput()` calls `BotCheckChallenge::verify()`, stores the marker `verified`. |
| Service | `application/Services/BotCheckChallenge.php` | `mint()` / `verify()` / `secret()` / `subject()`. The whole protocol. |
| Endpoint | `RunController::formBotChallengeAction` | `GET /{run}/form-bot-challenge` → fresh signed challenge JSON. |
| Client | `webroot/assets/form/js/items/bot-check.js` | Loads Altcha, registers the Argon2id worker, wires the lazy challenge URL. |
| Validation | `webroot/assets/form/js/validation/feedback.js` | Client gate: required + unverified blocks submit with the domain message. |
| PHP dep | `composer.json` → `altcha-org/altcha ^2.0` | Mint/verify/solve (`AltchaOrg\Altcha\…`). |
| JS dep | `package.json` → `altcha ^3.0.11` | Widget + Argon2id worker; **not webpack-bundled** (see §5). |
| Backend test | `bin/bot_check_smoke.php` | Mint → solve → verify + negatives (7 assertions). |
| E2E test | `tests/e2e/bot-check-v2.spec.js` | Render / gate / solve / accept / forged-reject (5 tests). |

## 3. Data flow

```
render        BotCheck_Item::render_input()
              → <div.fmr-botcheck data-fmr-botcheck data-challenge-path="form-bot-challenge">
                  <altcha-widget name="<item>" auto="off" configuration='{…}'>
              (no challenge baked in — stays inert)

mount (JS)    initBotCheck() sets challenge="<runUrl>/form-bot-challenge?item=<name>"
              (the item NAME, never a difficulty — the server looks the item up
               in the current study and derives the difficulty itself)
              loadAltcha() injects the self-hosted standalone module → window.$altcha
              registerArgon2id() wires the self-hosted memory-hard worker

solve         participant clicks → widget GETs the challenge (minted fresh, signed,
              session-bound) → runs Argon2id PoW in the worker → writes the base64
              payload into the widget's hidden input named "<item>", ticks the box

submit        form-page-submit POSTs <item>=<base64 payload>
              BotCheck_Item::validateInput() → BotCheckChallenge::verify()
              true  → stored value "verified"
              false → $item->error set → page blocked, inline message
```

**Lazy fetch is load-bearing.** form_v2 renders *every* page into one document at
load. A challenge baked in at render could go stale before the participant reaches
a later page. Fetching it when the widget *mounts* keeps it fresh, and the same
session cookie is present at mint and at submit, so `subject()` resolves
identically both times.

## 4. Protocol

**Challenge** (`mint()` → `$challenge->toArray()`, served by the endpoint):

```json
{ "parameters": { "algorithm": "ARGON2ID", "challenge": "…", "salt": "…",
                  "keyPrefix": "00", "cost": 1, "memoryCost": 4096,
                  "parallelism": 1, "expiresAt": 1733500000,
                  "data": { "uc": "<user_code>" } },
  "signature": "<hmac over the canonical parameters>" }
```

**Payload** (widget → server, base64-encoded JSON in the hidden input):

```json
{ "challenge": { "parameters": {…}, "signature": "…" },
  "solution":  { "counter": 12345, "derivedKey": "…" } }
```

`verify()` recomputes the HMAC from the server secret over the canonical
parameters (**including `data.uc`**) — so the signature *pins* every knob:
difficulty/memory can't be lowered, the session binding can't be re-pointed, and
the PoW solution can't be forged. Then it checks (a) the **difficulty floor**
re-derived server-side for *this* item (`meetsDifficultyFloor()`) — a genuinely
signed but easier challenge, e.g. one minted at the config default for a
`bot_check 3` item, is refused; (b) the Altcha solution verifies; (c)
`data.uc === subject()` (session binding); (d) freshness/expiry; and (e) that the
token has not been used before — a verified token is **burned** (single-use, via
RegistrationGuard's session burn list, remembered until it would have expired
anyway), so one solved PoW can't be replayed at the next `bot_check` the
participant meets within the TTL.

## 5. Why Altcha is loaded as a standalone script (not webpack-bundled)

The npm `altcha` browser build is ESM whose plugin loader uses a **dynamic
`require()`** webpack can't resolve (`"require is not defined"` at bundle init),
and webpack `noParse` is invalid on ESM. So instead of bundling, webpack
**copies two prebuilt files verbatim** next to the form bundle and the client
loads them at runtime, self-hosted (no CDN, no third party):

- `node_modules/altcha/dist/external/altcha.min.js` → `…/js/altcha/altcha.min.js`
  — injected as a `<script type="module">` by `bot-check.js` on demand; it
  `customElements.define`s `<altcha-widget>` and sets `window.$altcha`.
- `node_modules/altcha/dist/workers/argon2id.js` → `…/js/altcha/argon2id.js`
  — the memory-hard worker (inline WASM, no network), registered via
  `window.$altcha.algorithms.set('ARGON2ID', () => new Worker(workerUrl))`.

`templates/run/form_index.php` exposes both URLs as `window.formr.altchaScriptUrl`
/ `window.formr.altchaWorkerUrl`, resolved against the active `build/` vs
`dev-build/` dir so they always match the loaded bundle. (CopyPlugin entries:
`webpack.config.js`.)

### 5b. Styling — the external build ships CSS *separately*

Gotcha that cost us a "broken layout": the **external** build
(`dist/external/altcha.min.js`) does **not** inject its own stylesheet at
runtime (the default build does). Its CSS lives in
`node_modules/altcha/dist/external/altcha.css` (exposed as `altcha/altcha.css`)
and you must load it yourself, or `<altcha-widget>` renders with **no
border/padding/background** — the consuming rules
(`.altcha-main { border; padding; border-radius; background; max-width }`) are
simply absent, so it looks like an unstyled checkbox + label. We bundle it via a
plain `import 'altcha/altcha.css';` at the top of `items/bot-check.js` (webpack's
`MiniCssExtractPlugin` extracts it into `form.bundle.css`). The modern CSS in it
(`oklch()`, `light-dark()`, `@layer`) survives the production minifier intact.

On top of that base layer, `form.scss` **themes** the widget to match formr's
clean inline pill (the look the prior hand-rolled `.fmr-botcheck-box` had):
Altcha renders into **light DOM**, so `.fmr-botcheck altcha-widget …` selectors
reach inside it. Two moves: (1) override Altcha's `--altcha-*` design tokens
(border/radius/colors/checkbox-size) onto Bootstrap-5 theme vars + the pill
geometry; (2) flatten `.altcha-main` from its stock column card into a single
centred row with hover/`:focus-within` ring and a green `[data-state="verified"]`
tint. Higher selector specificity beats Altcha's own `.altcha-*` rules.

## 6. Algorithm + difficulty knobs

PBKDF2/SHA-* are bundled into the widget; **Argon2id is modular** and provided as
the worker above. The server picks **Argon2id when `ext-sodium` is present**
(`sodium_crypto_pwhash` + `SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13`), else falls back
to bounded **SHA-256**. The widget reads the algorithm from the challenge JSON, so
client and server always agree.

Defaults (constants in `BotCheckChallenge`, each overridable via `Config`):

| Knob | Const | Default | Config key |
| --- | --- | --- | --- |
| Memory | `DEFAULT_MEMORY_COST` | 4096 KiB (4 MiB) | `bot_check_argon_memory` |
| Iterations | `DEFAULT_TIME_COST` | 1 | `bot_check_argon_time` |
| Prefix bytes (PoW length) | `DEFAULT_PREFIX_BYTES` | 1 (~256 expected hashes) | `bot_check_prefix_bytes` |
| Key length | `DEFAULT_KEY_LENGTH` | 16 bytes | — |
| Challenge TTL | `TTL` | 86400 s (24 h) | `bot_check_ttl` |
| HMAC secret | — | derived from the at-rest crypto key | `bot_check_secret` |

`memoryCost` is the lever: 4 MiB resists GPU/ASIC fan-out; the server still only
does one verification hash, so **raising it costs the attacker, not us**. Native
median ~0.3 s across the widget's parallel workers; in-browser WASM a few× slower
(sub-second to ~2 s on a normal device).

**Per-item difficulty is a server decision.** `bot_check N` in the spreadsheet
(`type_options`) maps to prefix bytes 1..3 via `BotCheck_Item::chosenDifficulty()`;
without it `Config('bot_check_prefix_bytes')` applies, and
`BotCheckChallenge::expectedPrefixBytes()` clamps anything outside 1..3. The
client never sends a difficulty: the challenge URL carries the item **name**
(`?item=<name>`), `RunController::formBotChallengeAction` looks that item up in
the participant's current study and mints for it, and `verify()` re-derives the
same floor from the item row — so neither a tampered query nor a harvested,
genuinely-signed easier challenge can weaken the gate. (`data-difficulty` on the
wrapper is informational only and is deliberately not sent. The sheet reader
treats an all-digit token after `bot_check` as this difficulty, not as a
choice-list name — a list that relabels the widget is `bot_check mylist`,
see §9.)

**`optional`:** an optional `bot_check` may be left alone — an absent/empty
token stores NULL and doesn't block the page, like any other optional item. A
token that *was* submitted is still verified fail-closed; optional means
"skippable", never "forgeable".

**Bounded, not unbounded.** Prefix-byte PoW has bounded variance (~256 expected
attempts/byte), so legitimate slow devices don't hit a runaway search — a
deliberate fix for the old custom gate's high-variance timing.

## 7. Security properties + threat model

- **Session binding.** `mint()` folds the participant's `user_code` into the
  signed `data.uc`; `verify()` requires `data.uc === subject()`. A harvested token
  is **worthless to another participant**, and a direct-POST bot can't forge one
  (no secret). Defence in depth: the signature already proves `data.uc` is what we
  minted; the equality check additionally proves it was minted *for this session*.
- **Signature pins everything.** Difficulty, memory, expiry, and the binding are
  all inside the HMAC-covered canonical parameters. Lowering any of them breaks the
  signature.
- **Freshness.** `expiresAt` is enforced inside `verifySolution`; `verify()` also
  rejects clock-skew "issued in the future" tokens. Slowness is *not* a bot
  signal — a long TTL grants an attacker nothing (binding + PoW cost are unchanged).
- **Fails closed, always.** If the server has no crypto key, `secret()` is
  null → `mint()` returns null (the endpoint logs and answers 503) and
  `verify()` refuses *every* token, including an empty one. The bot_check item
  then shows "Human verification is temporarily unavailable…" rather than
  waving everyone through. The earlier fail-open branch meant one unreadable
  key file silently disarmed every bot_check while the admin UI still showed
  it armed. `available()` is the probe for that state.
- **The server is the boundary.** The client gate (`feedback.js`) is UX only; the
  `tests/e2e` "forged token" case deliberately bypasses it and asserts the server
  rejects. Never treat the widget's green check as the security decision.

## 8. Comparison vs the prior custom implementation

| Aspect | Custom (pre-Altcha) | Altcha (this branch) |
| --- | --- | --- |
| PoW | hand-rolled hash search | Altcha's audited PoW + widget |
| Algorithm | SHA-based, fixed | Argon2id (memory-hard) w/ SHA-256 fallback |
| Timing variance | high (unbounded search) | bounded (prefix-byte, ~256/byte) |
| Widget/UX | bespoke DOM + JS | maintained `<altcha-widget>` (a11y, i18n) |
| Maintenance | all ours | library tracks browser/PoW changes |
| GDPR posture | self-hosted | self-hosted (unchanged — still no PII off-box) |
| Session binding | yes | yes (`data.uc`, signature-pinned) |

Net: same privacy posture and same self-hosted property, but the PoW core and the
widget are a maintained library instead of bespoke code, the algorithm is genuinely
memory-hard, and the timing is bounded. The custom code path is preserved on the
parent branch for the side-by-side comparison this branch exists to enable.

## 9. Customisable copy (consent / affirmation reuse)

`bot_check` can double as a consent / "I'm answering myself" affirmation gate.
Optional spreadsheet `choice1/2/3` map to Altcha's i18n `strings`:

- `label`   ← `choice1` (the clickable box text, default "Verify you are human")
- `verified`← `choice2` (after solving, default "Verified")
- `verifying`←`choice3` (in-progress, default "Verifying…")

The item `label` column is the prompt/statement above the box. Choices are
optional here (the base validator's "no choices on a non-choice type" rule is
suppressed in `BotCheck_Item::validate()` and restored for rendering).

## 10. Testing

- **Backend:** `docker exec formr_app php /var/www/formr/bin/bot_check_smoke.php`
  — mints a real challenge, solves it with the PHP lib, asserts verify; plus
  negatives (empty, garbage, forged signature, wrong PoW counter, re-bound
  session). 7/7 with Argon2id.
- **E2E:** `npm run test:e2e -- bot-check-v2` — renders the widget wired to the
  lazy challenge URL; required+unverified blocks with the domain message; a
  trusted click solves Argon2id and writes the payload; a solved submit stores
  `verified`; a forged payload (client gate bypassed) is rejected server-side.
  Fixture: persistent public run `e2e-botcheck-v2` (study `e2e_bot_check`).
