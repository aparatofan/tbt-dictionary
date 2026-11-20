# TBT Dictionary — Claude Code guidance

Keep this file concise. It is loaded at the start of every Claude Code session.

## Start here

- Work from the task, not from a full-repository scan.
- The current release is specified in `docs/SPEC-0.2.0.md` (earlier: `docs/SPEC-0.1.1.md`, `docs/SPEC-0.1.0.md`). Follow it in order. Phase 0 is read-only and ends with a report; do not edit anything before Mariusz answers it.
- `docs/prototypes/*.html` are the approved designs. Where the spec and a prototype disagree, the spec wins; where the spec is silent, the prototype decides.
- Sibling code is the reference for patterns: `aparatofan/tbt-points` (bootstrap, DB upgrade check, assets, tokens, fonts, REST boot, login card, Hub item, deploy), `aparatofan/translation-drill` (OpenAI key/model fallback to Swipe, per-day AI limit, settings page, Flashcards TTS proxy), `aparatofan/tbt-swipe` (the class picker: text input + datalist) and `aparatofan/tbt_notes` (`tbt_notes_record_activity()`, for games from 0.2.0).
- Do not change unrelated code, formatting, wording, or styling.

## Project basics

- WordPress 6.x plugin; PHP 8.0+. Pure PHP + vanilla JavaScript. No jQuery, build system, CDN, Composer, or npm dependency.
- Bootstrap: `tbt-dictionary.php`. Server-side: `includes/`. Assets: `assets/`. Standalone tests: `tests/`.
- Prefixes: `TBTDIC_` (classes, constants), `tbtdic_` (functions, options, filters), `tbtdic-` (CSS), REST namespace `tbt-dictionary/v1`. `TBTD_` belongs to Translation Drill. Never use unprefixed `.tbt-*` class names; TBT Hub claims them.
- `assets/css/tbt-tokens.css` is a byte-identical copy of Hub's tokens (2.2+). Never edit it. No stylesheet here defines a colour of its own.

## Architecture to preserve

- **A dictionary belongs to one user.** Every query takes the user from the session and filters on it. Another user's row is 404, never 403. No route accepts a `user_id`.
- **New words earn through `tbt_points_record_event()`** (TBT Points 0.1.4+), tool `dictionary_word`, once per word. They are **never** recorded in the Notes activity log, so they stay out of TBT Results and the Class Progress panel (Mariusz's decision). Game results (0.2.0+) do go through Notes. The bridge never throws and never blocks a save.
- **AI fails open.** No key, a timeout or bad JSON leaves the word saved as typed. AI never overwrites a translation the student typed.
- Pure logic lives in `TBTDIC_Words` (no WordPress) and is unit-tested. Change the tests with the logic.
- Information that cannot be clicked never looks like a card (flat on the canvas); clickable cards have a shadow at rest and lift on hover.
- Dates are stored in UTC; "today" is the site time zone.
- No page hero: Divi supplies the header. Tablet and desktop only (min 768 px).
- The plugin must not fatal when Notes, Points, Swipe or Flashcards are missing.

## Security rules

- Every REST route has a real `permission_callback`; nonce `wp_rest`; no-cache headers on reads.
- `$wpdb->prepare()` for every query with a value; `$wpdb->esc_like()` for search.
- Escape all output. Never expose the API key or real configuration values to a page.

## Validation

```bash
for f in tbt-dictionary.php includes/*.php; do php -l "$f"; done
node --check assets/js/dictionary.js
php tests/test-words.php
```

State clearly what still needs a browser or live-site check.

## Git and deployment

- **Never merge a PR and never push to `main`.** A merge to `main` deploys to the live site; that is Mariusz's decision. Stop at an open PR and report.
- Use a focused feature branch. Keep commits task-focused.
- Every release bumps `TBTDIC_VERSION` and the plugin header version; the version is the cache buster.
- Never touch `TBTDIC_DB_VERSION` unless the schema really changes.
- **Never rename `tbt-dictionary.php` or the `/tbt-dictionary/` server folder.** The FTP deploy uploads but never deletes.
- Markdown files, `.github/`, `docs/` and `tests/` are excluded from the upload.

## Context discipline

- Prefer targeted search and narrow reads over broad exploration.
- Summarise completion as: what changed, what was checked, and what still needs live-site verification.
