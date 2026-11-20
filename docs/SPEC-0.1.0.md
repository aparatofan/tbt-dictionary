# TBT Dictionary 0.1.0 — My dictionary (groups, words, AI fill-in, points)

Each student keeps their own dictionary: words in groups they name themselves. A student types a word; AI fills in the translation (if left empty), the phonetics and an example; the word can be heard. Every new word earns points through TBT Points. This replaces the third-party **AYS Personal Dictionary** (`[ayspd_userpage]`) over three releases.

Design: `docs/prototypes/dictionary.html` (approved 20 Nov 2026). Where this spec and the prototype disagree, the spec wins; where the spec is silent, the prototype decides. The prototype's dashed "Prototype" bar, its sample data and its mocked AI are not part of the product.

## Releases

| Release | Contents |
|---|---|
| **0.1.0 (this spec)** | Groups, words, **Add a word** with the group picker, quick-add inside a group, edit / move / reset / delete, search across all words, info strip, AI fill-in, audio, **+points per new word** |
| 0.2.0 | Games tab: **Find the translation** and **Find the word**; word and group progress; settings for learnt / right / wrong points; game results through the Notes activity log, so they reach TBT Results and Points like every other tool |
| 0.3.0 | Import tab (Excel / CSV); **migration of every student's AYS groups and words**; then AYS is switched off |

Nothing in 0.1.0 may assume games or import don't exist: the `progress` column is created now (always 0 in 0.1.0) so 0.2.0 needs no schema change, and the tab strip is built so 0.2.0 only adds tabs.

## How points flow (Points only)

Mariusz's decision (20 Nov 2026): new words show **only in Points**, never in TBT Results or the Notes Class Progress panel. Both of those read the Notes activity log, so a word is **not** recorded in Notes.

Instead Dictionary calls **`tbt_points_record_event()`**, added by **TBT Points 0.1.4** (separate small spec): tool **`dictionary_word`**, one event per word. Points applies its usual rules (the **Dictionary: new word** rule, default 1; the first-activity-of-the-day bonus; the daily cap; the start date; teachers never earn) and returns the points it actually added, which the toast shows. **Points 0.1.4 must be deployed first or together.** Without Points, words still save; they just earn nothing.

(Games in 0.2.0 are finished work, so they will go through Notes and appear in Results.)

## Files

```
tbt-dictionary.php
includes/class-tbtdic-db.php        both tables, every query
includes/class-tbtdic-words.php     pure helpers: normalising, limits, progress maths (unit-tested)
includes/class-tbtdic-ai.php        OpenAI fill-in (copy Translation Drill's TBTD_API pattern)
includes/class-tbtdic-points.php    the Points bridge
includes/class-tbtdic-rest.php
includes/class-tbtdic-pages.php     [tbt_dictionary], assets, login card
includes/class-tbtdic-settings.php  settings page under the TBT Hub menu
assets/css/tbt-tokens.css           byte-identical copy of Hub's tokens **2.2** (TBT Hub 1.6.0)
assets/css/fonts.css, assets/fonts/  as TBT Points, plus Roboto Mono from Translation Drill (covers IPA)
assets/css/dictionary.css, assets/js/dictionary.js
tests/test-words.php
.github/workflows/deploy.yml        copy TBT Points'; folder tbt-dictionary
CLAUDE.md, README.md
docs/prototypes/dictionary.html     already in the repo
```

Prefixes: `TBTDIC_` / `tbtdic_` / `tbtdic-`, REST `tbt-dictionary/v1`, text domain `tbt-dictionary`. (`TBTD_` is Translation Drill's.)

---

## Phase 0 — read-only inventory (stop and report)

1. **Repo.** `aparatofan/tbt-dictionary` starts empty: the first commit is `CLAUDE.md`, `docs/SPEC-0.1.0.md` and `docs/prototypes/dictionary.html` exactly as supplied.
2. **Pattern plugin.** In `aparatofan/tbt-points` (main): plugin bootstrap, `plugins_loaded` DB upgrade check, assets / vendored tokens / fonts, REST boot and nonce refresh, login card, Hub item (not its Notes version check: Dictionary 0.1.0 does not need Notes), deploy workflow including the `allow_create` first deploy. Dictionary copies these.
3. **AI and audio.** In `aparatofan/translation-drill`: `TBTD_API` (own key → Swipe's `tbts_api_key`; own model → Swipe's `tbts_model` → `gpt-4o-mini`; Chat Completions with `response_format: json_object`; timeouts; error handling), its per-day limit, its settings page under `TBT_HUB_SLUG`, and the Flashcards TTS proxy use (`tbt_fc_tts_proxy`, option `tbt_fc_elevenlabs_api_key`, action `tbt_fc_tts`, nonce `tbt_fc_tts_nonce`, the fetch in `player.js`).
4. **Points.** In `aparatofan/tbt-points`: confirm **0.1.4** is merged (or in an open PR) with `tbt_points_record_event( array $args ): int`, its arguments and return value. If it is not there yet, say so; build against the documented signature behind `function_exists()`.
5. **Dictionary does not use Notes or Results** in 0.1.0: confirm nothing in the patterns you copy would record to the Notes activity log.
6. **Report:** the patterns you will copy (file and line), the table names, and anything that contradicts this spec.

**Hard stop** if anything contradicts this spec. Otherwise end with the report and wait for Mariusz to say go.

---

## Task 1 — Tables (`class-tbtdic-db.php`)

Created by `dbDelta` on activation **and** from a `plugins_loaded` version check (FTP deploys never fire activation). `TBTDIC_DB_VERSION` = `1`.

`{prefix}tbtdic_groups`

| Column | Type | Notes |
|---|---|---|
| `id` | bigint unsigned PK | |
| `user_id` | bigint unsigned | owner; index |
| `name` | varchar(60) | trimmed; unique per user, case-insensitive (`UNIQUE (user_id, name)` with the table's `_ci` collation) |
| `created_at`, `updated_at` | datetime | UTC |

`{prefix}tbtdic_words`

| Column | Type | Notes |
|---|---|---|
| `id` | bigint unsigned PK | |
| `user_id` | bigint unsigned | owner; index `(user_id, group_id)` |
| `group_id` | bigint unsigned | |
| `term` | varchar(120) | as typed, trimmed, inner spaces collapsed |
| `term_key` | varchar(120) | `TBTDIC_Words::key()` of `term`; index `(user_id, term_key)` for the duplicate check |
| `translation` | varchar(190) | may be '' |
| `phonetic` | varchar(80) | '' until AI fills it |
| `example` | varchar(300) | '' until AI fills it |
| `progress` | decimal(6,2) default 0 | points toward "learned"; used from 0.2.0 |
| `ai_status` | varchar(10) | `pending`, `done`, `failed`, `skipped` |
| `created_at`, `updated_at` | datetime | UTC |

Every query takes `user_id` from the session and includes it in the `WHERE`. A row of another user is "not found" (404), never "forbidden".

## Task 2 — Pure helpers (`class-tbtdic-words.php`, no WordPress)

- `clean_term( $s )`: trim, collapse whitespace, strip control characters, cut to 120.
- `key( $s )`: `clean_term`, lower-case (mb), fold accents (as the prototype's `fold()`, including `ł → l`). Used for duplicates and search.
- `speak_text( $term )`: the term without bracketed notes, so `disruption (n.)` is spoken as `disruption`.
- `percent( $progress, $learnt )`: `min(100, progress / learnt × 100)`; `$learnt` is 10 in 0.1.0 (constant `TBTDIC_LEARNT_DEFAULT`; becomes a setting in 0.2.0).
- `group_percent( array $progresses, $learnt )`: average of the word percents, 0 for an empty group, one decimal.
- `parse_ai( string $json )`: decode, keep only `translation` (≤190), `phonetic` (≤80, **kept only if it starts and ends with `/`**), `example` (≤300); strip tags; return `null` on anything malformed.

## Task 3 — REST (`class-tbtdic-rest.php`)

Namespace `tbt-dictionary/v1`. Every route: logged-in only (`permission_callback` = `is_user_logged_in()`), nonce `wp_rest`, no-cache headers on reads. The user is always the session user; no route takes a `user_id`.

| Route | Does |
|---|---|
| `GET /state` | All the user's groups (with `word_count`, `learned`, `percent`) and all their words. The page loads once; search and filtering are client-side. |
| `POST /groups` `{name}` | Create. Empty → 400. Name exists (case-insensitive) → 409 with the existing group, message "You already have a group called …". |
| `PATCH /groups/{id}` `{name}` | Rename. Same checks. |
| `DELETE /groups/{id}` | Deletes the group and its words. |
| `POST /groups/{id}/reset` | Sets `progress` = 0 on its words. |
| `POST /words` `{group_id}` **or** `{group_name}`, `{term, translation}` | Create. With `group_name`, an existing group of that name (case-insensitive) is used, else it is **created** (the picker's "New group" case). Empty term → 400. Same `term_key` anywhere in the user's dictionary → 409, message "Already in your dictionary (Group name)". Inserts with `ai_status` `pending`, awards points (Task 5), returns `{word, group, points}`. |
| `POST /words/{id}/fill` | Runs the AI fill-in (Task 4), returns the updated word. Only for `pending`; otherwise returns the word unchanged. |
| `PATCH /words/{id}` `{term?, translation?, phonetic?, example?, group_id?}` | Edit or move. Changing `term` re-checks duplicates (409). Moving to a group of another user → 404. Never re-runs AI. |
| `DELETE /words/{id}` | Delete. Points already earned stay (Points' ledger is never edited). |
| `POST /words/{id}/reset` | `progress` = 0. |

Limits per user: 200 groups, 5 000 words (400 with a plain message past either).

## Task 4 — AI fill-in (`class-tbtdic-ai.php`)

Copy `TBTD_API`'s structure: key (own `tbtdic_api_key` → Swipe's `tbts_api_key`), model (own `tbtdic_model` → Swipe's `tbts_model` → `gpt-4o-mini`), Chat Completions, `response_format: json_object`, low temperature, short timeout (15 s).

- **Input:** the term and, if the student typed one, their translation.
- **Ask for** JSON `{translation, phonetic, example}`: translation into **Polish** (filter `tbtdic_translation_language`, default `Polish`), short, at most two meanings separated by a comma; **British** IPA between slashes; one natural example sentence, B1–B2, at most 15 words, using the term, naming no real person and no real place.
- **Store:** `phonetic` and `example` always (when `parse_ai()` accepts them); `translation` **only if the student left it empty**. `ai_status` = `done`.
- **Fail open.** No key, timeout, HTTP error, bad JSON → `ai_status` = `failed`, the word stays as typed, the route still returns 200 with the word. Never a fatal, never a lost word.
- **Daily limit:** option `tbtdic_ai_per_day` (default 100) fill-ins per student per day, site time zone, user-meta counter that moves only after a successful call (as `TBTD_Translator`). **0 means no limit**, as in Drill; the settings help text says so. To switch AI off, clear the key. Over the limit → `ai_status` = `skipped`.

## Task 5 — Points bridge (`class-tbtdic-points.php`)

- **On word create** (only there; never on edit, move or import in later releases unless a spec says so): if `function_exists( 'tbt_points_record_event' )`, call it with `user_id` = session user, `tool` = `dictionary_word`, `object_ref` = `'word:' . md5( term_key )`, `object_title` = the term. The reference is the **word itself**, not the row ID, so a word earns once per student **ever**: deleting it and adding it again earns nothing (Points 0.1.4 earns once per tool + reference). A genuinely different word still earns. Wrapped in `try/catch \Throwable`: the bridge never breaks the save.
- `points` in the create response = the function's return value (0 when Points is missing, the student is a teacher, the cap is reached, etc.). The toast shows only when `points` > 0.
- **Never** call `tbt_notes_record_activity()` for a word: words must not appear in TBT Results or the Class Progress panel.

## Task 6 — Settings (`class-tbtdic-settings.php`)

Under the TBT Hub menu (`TBT_HUB_SLUG`, fallback own menu), as Drill's: **OpenAI API key** (password field; blank keeps Swipe's; says which key is in use), **Model** (placeholder shows the model in use), **AI fill-ins per student per day** (0–1000, default 100). Admin only (`manage_options`). The key never reaches a page.

## Task 7 — The page (`class-tbtdic-pages.php`, `dictionary.js`, `dictionary.css`)

Shortcode `[tbt_dictionary]`. Logged out → the login card (Points' pattern). Assets only on that page; `?ver=TBTDIC_VERSION`. Tokens from the vendored `tbt-tokens.css` (Hub 2.2) only; no colour of its own. The prototype's literal colours map to tokens: `#4E6689` → `--tbt-ink-soft`; `#8299B8` → `--tbt-ink-faint`; card hover border `#B9CCE6` → none (the border stays `--tbt-border`; the shadow and lift carry the hover); shimmer `#DCE7F7` → a gradient from `--tbt-input` to `--tbt-selected-bg`. No page hero (Divi supplies it). Tablet and desktop (min 768 px).

Build it from the prototype. The parts that must not drift:

1. **Admin Bar:** title "My dictionary" (Roboto Slab 28/700, blue, 234 px title zone) · search 300 px ("Search your words…") · one flexible line · **Add a group** (250 × 53 pill, white, 1.5 px blue inset border, blue text) · **Add a word** (250 × 53 pill, solid TBT Blue). No sort dropdown. *Two pill CTAs is a Dictionary exception to Style Book §7, approved 20 Nov 2026.* `/` focuses the search; × and Escape clear it.
2. **Tab strip:** rendered only when there is more than one tab. In 0.1.0 there is one (Groups), so none shows.
3. **Info strip** (words in your dictionary · words learned · groups): **flat on the canvas** — no box, no background, no shadow; thin vertical dividers. *Platform rule from 20 Nov 2026: information that cannot be clicked must not look like a card.*
4. **Group cards** (clickable): white, 1 px border, **shadow at rest**, lift 2 px and stronger shadow on hover, arrow after the name that moves right on hover. Name, "N words · N learned", progress bar + percent, ⋮ menu (Rename, Reset progress, Delete group). **No Play button in 0.1.0** (arrives with 0.2.0). Order: oldest first.
5. **Group page:** "← All groups", name, count, progress, ⋮. Quick-add row: "English word or phrase" + "Translation (optional, AI fills it in)" + **Add word**; Enter in the word moves to the translation, Enter there saves; focus returns to the word field. Words newest first.
6. **Word row:** term (Roboto Slab 700, blue) · phonetic (Roboto Mono, `var(--tbt-ink-soft)`, the Flashcards IPA colour) · speaker button (only when audio is available) · translation · example in italics in curly quotes · progress · ⋮ (Edit, Move to another group, Reset progress, Delete). `pending` → shimmer line "Adding phonetics, an example and audio…". `failed`/`skipped` → nothing extra. No translation → "No translation yet · add one" (opens Edit).
7. **Add a word** dialog (from the Admin Bar): **Group** field = text input bound to a `<datalist>` of the user's groups (Swipe's class picker pattern: type to narrow, or pick); prefilled with the open group, else the last group used in this page load; hint under it: "Type a group name or pick one from the list." / "N words in this group." / "**New group:** "X" will be created when you add the word." Then word, translation. Enter moves field to field and saves on the last; the dialog **stays open**, keeps the group, clears word and translation, and lists what was added ("✓ resilient → Science Words"). Buttons: Cancel (→ "Done" after the first add) and **Add word**. Typed text survives background re-renders (the prototype keeps it in state). Escape closes.
8. **Save flow:** `POST /words` → row appears at once (shimmer) → toast "+N point(s) · new word" when `points` > 0 → `POST /words/{id}/fill` → row updates. A 409 shows its message under the word field and keeps the text.
9. **Search** (any tab): flat list of matching words across groups (term, translation or example; accent-folded), each with its group tag; "N words match "q" · Clear search". "No words match your search." when empty.
10. **Dialogs** for new group, rename, delete group ("Its N words will be deleted too. This can't be undone. Points you earned stay."), delete word, reset, move — built into the page (no `alert`/`confirm`).
11. **Audio:** when Flashcards' TTS proxy is available (Drill's `audio()` check), the speaker POSTs `speak_text(term)` to it and plays the MP3; otherwise no speaker button.
12. Empty dictionary: "Start your dictionary" panel with **Add a group**.

## Task 8 — Bootstrap and docs

`TBTDIC_VERSION` `0.1.0` and header. Hub item as Points registers its own. Dictionary must not fatal when Points, Swipe or Flashcards are missing: each integration checks before use. README: what it is, shortcode, settings, optional companions (Points 0.1.4+, Swipe's OpenAI key, Flashcards' ElevenLabs key). CLAUDE.md is already in the repo.

## Task 9 — Tests (`tests/test-words.php`)

Plain PHP, as Points' tests: `clean_term`, `key` (case, accents, `ł`, spaces), `speak_text`, `percent` and `group_percent` (empty group, cap at 100, one decimal), `parse_ai` (valid, phonetic without slashes dropped, over-long fields cut, tags stripped, malformed → null).

## Validation

```bash
for f in tbt-dictionary.php includes/*.php; do php -l "$f"; done
node --check assets/js/dictionary.js
php tests/test-words.php
```

---

## Acceptance checklist (live, after merge and first deploy with `allow_create`)

Before: deploy **TBT Hub 1.6.0** (tokens 2.2) and **TBT Points 0.1.4** (it adds `tbt_points_record_event()` and the rule). Add the FTP secrets to the repo and create the `/tbt-dictionary/` server folder. Create a page **My dictionary** (Learning Tools menu) with `[tbt_dictionary]`. AYS stays as it is until 0.3.0.

- [ ] Logged out: the login card. Logged in as the test student: "Start your dictionary".
- [ ] **Add a group** "Work" → the group opens with the cursor in the word field.
- [ ] Type `resilient`, Enter, Enter: the row appears at once with the shimmer, then a Polish translation, `/rɪˈzɪl.i.ənt/`-style phonetics and an example. Toast "+1 point · new word".
- [ ] Type a word **and** a translation: AI keeps your translation and adds phonetics and an example.
- [ ] Speaker plays the word (with Flashcards' ElevenLabs key set); `disruption (n.)` is spoken without "(n.)".
- [ ] **Add a word** from the Admin Bar: the picker lists your groups; typing narrows them. Type a new name "Travel": the hint says it will be created; after saving, a **Travel** card exists. The dialog stayed open and lists both words.
- [ ] Add `Resilient` again (any group): "Already in your dictionary (Work)", nothing saved, no point.
- [ ] Delete `resilient`, add it again: it saves, but **no point** and no toast.
- [ ] Phonetics are the same blue-grey as on Flashcards cards.
- [ ] My points (test student): one **Dictionary: new word** row per word, worth the Rules value; the toast showed the same number.
- [ ] Results (teacher and student) and the Notes Class Progress panel show **no** Dictionary rows.
- [ ] Remove the OpenAI key temporarily (and Swipe's): words still save, with no phonetics or example; put the key back.
- [ ] Search `zak` finds "zakłócenie"-type translations across groups; `/` focuses the search; Escape clears it.
- [ ] Edit, Move, Reset, Delete a word; Rename and Delete a group (with its words). Points earned stay.
- [ ] Info strip is flat (not a card); group cards lift on hover. No Play buttons yet.
- [ ] A second student cannot see or change the first student's words (try a word ID in the REST URL: 404).
- [ ] Hard refresh shows `?ver=0.1.0`.
