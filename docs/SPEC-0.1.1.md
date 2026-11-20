# TBT Dictionary 0.1.1 — Copy the old AYS dictionary

Brought forward from 0.3.0 at Mariusz's request (20 Nov 2026). An admin button copies every student's groups and words from **AYS Personal Dictionary** into TBT Dictionary, **with their progress**. AYS is only read, never changed, and stays switched on until Mariusz turns it off. Excel/CSV import stays in 0.3.0.

Live data (20 Nov 2026): **1 393 words, 63 students**.

No schema change: `TBTDIC_DB_VERSION` stays `1`. Copied words **earn no points** (they were added months or years ago).

Phase 0 was answered on 20 Nov 2026; the decisions are folded into the tasks below and listed under **Phase 0 answers**.

## The AYS tables (read only; `{prefix}` = `$wpdb->prefix`)

`{prefix}ayspd_categories` — the groups

| Column | Use |
|---|---|
| `id` | AYS group ID |
| `user_id` | owner |
| `name` | group name, e.g. "My English Words", "Słówka 1" |
| `parent`, `parent_id` | ignored: groups are copied flat |
| `date_created` | → `created_at` |

`{prefix}ayspd_words` — the words

| Column | Use |
|---|---|
| `id` | AYS word ID |
| `user_id` | owner |
| `category_id` | → the copied group |
| `word` | → `term` |
| `translation` | → `translation` |
| `point` | AYS points toward learned (e.g. 2.75) |
| `completed` | 1 = learned |
| `percentage` | `point × 10` with AYS's default settings (2.75 → 27.5) |
| `corrects_count`, `failed_count`, `attempts_count` | not copied |
| `date_created` | → `created_at` |
| `date_modified` | not copied |

Not read: `ayspd_globsettings`, `ayspd_reports`, `ayspd_settings`.

---

## Phase 0 — read-only (stop and report)

1. Confirm 0.1.0 is on `main` as specified; give the names of the classes and methods you will reuse (word insert, group find-or-create by name, `TBTDIC_Words::clean_term()` / `key()`, the AI fill-in).
2. Confirm nothing in the 0.1.0 insert path awards points or calls Notes when used from PHP (the migration must award nothing).
3. Report anything that contradicts this spec. **Hard stop** if anything does.

### Phase 0 answers (Mariusz, 20 Nov 2026)

- **A. First answer wins.** 0.1.0's fill read `pending`, called the AI, then updated without re-checking. Now every fill (page and job) saves through `TBTDIC_DB::save_fill()`, which updates only `WHERE ai_status = 'pending'`. A rare duplicate AI call is possible; a duplicate save is not.
- **B. Copied + skipped = AYS total.** Words of users without an account count in `words_skipped` too; `users_missing` counts **students**, not words. Wording: "Skipped 13 (already there, empty, or no account)."
- **C. Time budget.** Each job run stops starting new words after about 20 s; the rest stay `pending` for the next run. The settings note says the job runs when the site has visitors (WP-Cron).
- **D.** Accepted as proposed: a daily-limit bypass flag on `TBTDIC_AI::fill()` for the job; no key at copy time → `skipped` (not filled later); the per-student limits (5 000 words, 200 groups) are respected and the excess counts as skipped; a blank AYS group name → **My words**; an empty or zero AYS date → now; the README paragraph on AYS is corrected. Two changes:
  1. **The page fills only recent words.** On load the page fills only `pending` words created in the **last 10 minutes** (an interrupted fill of a word just added); the server checks the same window. Every older `pending` word is left to the background job, whose fills never count against the daily limit, so a student with 300 copied words does not use up their limit. Because of that the job is **scheduled permanently while the plugin is active** (not only after a copy), and also picks up interrupted fills older than 10 minutes.
  2. **HTML entities in AYS text.** On copy, the word, translation and group name go through `html_entity_decode( $s, ENT_QUOTES | ENT_HTML5, 'UTF-8' )` before cleaning, so `&amp;` and `&#039;` become `&` and `'`.

Reused from 0.1.0: `TBTDIC_DB::insert_word()` (now with optional `progress`, `ai_status`, `created_at`), `TBTDIC_DB::group_by_name()` / `insert_group()` (optional `created_at`), the new public `TBTDIC_DB::find_or_create_group()` (was the private `TBTDIC_Rest::make_group()` logic), `TBTDIC_Words::clean_term()` / `clean_name()` / `clean()` / `key()`, and `TBTDIC_AI::fill()` behind the new `TBTDIC_AI::fill_and_save()`. Nothing in `TBTDIC_DB` awards points or calls Notes; only the REST create route awards points.

---

## Task 1 — The copy (`includes/class-tbtdic-ays.php`)

`TBTDIC_AYS::available()`: both AYS tables exist (`SHOW TABLES LIKE`). Everything below does nothing when they don't.

`TBTDIC_AYS::summary()`: AYS words, AYS students, and how many of those words are already in TBT Dictionary (by user + `term_key`).

`TBTDIC_AYS::copy()`: one run over all users, in batches of 500 AYS rows (ordered by `user_id`, `id`), returning `{students, groups_created, words_copied, words_skipped, users_missing}`.

Per AYS word:

1. **User:** when `get_userdata( user_id )` is false, skip the word (count it in `words_skipped`) and count the student once in `users_missing`.
2. **Group:** the AYS category's `name`, entities decoded, cleaned and cut to 60; find the user's TBT group by that name (the same `_ci` match as 0.1.0) or create it with `created_at` from the AYS category. A word whose category is missing, or whose name is blank, goes to a group called **My words**. Two AYS groups whose names collide for one user merge into one.
3. **Word:** entities decoded, then `clean_term( word )`; skip if empty. Skip (count as `words_skipped`) if the user already has that `term_key` anywhere in TBT Dictionary. This makes the copy **safe to run again** and keeps the first copy when AYS has the same word twice for one user. A word past the student's 5 000-word limit, or needing a new group past the 200-group limit, is skipped too.
4. **Fields:** `translation` entities decoded, cleaned, cut to 190; `phonetic` and `example` empty; `ai_status` `pending` (or `skipped` when no API key is available).
5. **Progress:** `completed = 1` → **10** (learned). Otherwise `min( 10, percentage / 10 )`, rounded to two decimals. (`percentage` rather than `point`, so a site that changed AYS's "learnt" points still copies the right share.) `TBTDIC_LEARNT_DEFAULT` stays 10.
6. **Dates:** `created_at` = `get_gmt_from_date( date_created )` (AYS stores site-local time); an empty or zero date → now. `updated_at` = now (UTC).
7. **No points, no Notes:** the copy never calls `tbt_points_record_event()` or `tbt_notes_record_activity()`.

Store the last run's result and time in option `tbtdic_ays_last_copy`. Every AYS word is either copied or skipped, so `words_copied + words_skipped` = the AYS total. A transient lock stops two copies running at once.

## Task 2 — AI fill-in in the background

Copied words have no phonetics or example yet. A WP-Cron job fills them a few at a time, so the button never times out:

- Class `TBTDIC_Fill` (`includes/class-tbtdic-fill.php`). Hook `tbtdic_fill_pending`, custom interval **2 minutes**, **scheduled permanently while the plugin is active** (checked on `init`, cleared on deactivation). With nothing pending a run is one cheap SELECT.
- Each run takes the **10** oldest `pending` words (any user) **created more than 10 minutes ago** and runs the 0.1.0 fill-in on each (AI never overwrites the AYS translation). It starts no new word after about **20 s**; the rest wait for the next run. About 1 400 words take roughly 5 hours, more if the site has few visitors (WP-Cron runs on page visits).
- These fills **do not count** against the students' daily AI limit.
- Fail open as in 0.1.0: a failed word becomes `failed` and is not retried.
- **The page fills only words created in the last 10 minutes** (its own new word, or one whose fill was interrupted); the REST fill route checks the same window. Older `pending` words, including every copied word, are the job's. Both save through `TBTDIC_DB::save_fill()`, which only updates a word still `pending`, so the first answer wins.

## Task 3 — Settings section

On the Dictionary settings page, below the AI settings, when `available()`:

**Old dictionary (AYS Personal Dictionary)**
- "Found 1 393 words from 63 students. 0 of them are already in TBT Dictionary." (from `summary()`)
- Button **Copy to TBT Dictionary** (`manage_options`, nonce, POST). It disables itself while running ("Copying…").
- After a run: "Copied 1 380 words into 140 groups for 63 students. Skipped 13 (already there, empty, or no account). 2 students no longer have an account." (the last sentence only when there are any) plus the time of the run.
- While words wait: "Adding phonetics and examples in the background: 1 380 words to go." Refreshes on reload.
- Note: "AYS is only read, never changed. Running the copy again only adds words that are new in AYS. Copied words earn no points." plus: phonetics and examples are added about 10 words every 2 minutes while the site has visitors, and do not count against the students' daily AI limit.

When AYS is not installed (tables missing), the section is not shown.

## Task 4 — Version

`TBTDIC_VERSION` and header → `0.1.1`. README: one paragraph on the copy.

## Validation

```bash
for f in tbt-dictionary.php includes/*.php; do php -l "$f"; done
node --check assets/js/dictionary.js
php tests/test-words.php
```

Add tests for the pure part: the progress mapping (`completed` 1 → 10; 27.5 → 2.75; 150 → 10; 0 → 0; negative → 0) — `TBTDIC_Words::ays_progress()`.

---

## Acceptance (live)

- [ ] Settings → Dictionary shows "Found 1 393 words from 63 students".
- [ ] **Copy to TBT Dictionary** finishes in a few seconds with the summary.
- [ ] My dictionary (Mariusz's account): **My English Words** with its words; "undervalued" shows **27.5%**, with its translation "niedoceniony".
- [ ] A word that was learned in AYS shows **Learned**.
- [ ] My points: **no** new rows from the copy.
- [ ] A few minutes later, some copied words have phonetics and an example; the count "words to go" drops on reload.
- [ ] Opening My dictionary while copied words are still pending does **not** fill them from the page (no fill requests in the browser's network tab for old words); a newly added word is still filled at once.
- [ ] Run the copy again: "Copied 0 words … Skipped 1 393".
- [ ] The old AYS page still shows everything unchanged.
- [ ] Hard refresh shows `?ver=0.1.1`.
