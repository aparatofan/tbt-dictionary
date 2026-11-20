# TBT Dictionary 0.2.0 — Games

Students practise their own words with two games, **Find the translation** and **Find the word**, exactly as in AYS. Each answer moves the word's progress; a word that reaches "learned" leaves the games. A finished round goes to TBT Results and earns Points like every other tool. After this release Mariusz can switch AYS off.

Design: the **Games** tab of `docs/prototypes/dictionary.html` (approved 20 Nov 2026), the same rules as 0.1.0 (spec wins; prototype decides where the spec is silent; its sample data and "Prototype" bar are not product).

Companion: **TBT Points 0.1.5** adds the **Dictionary game** rule (default 5). Deploy it first or together.

## Decisions (Mariusz, 20 Nov 2026)

1. A finished game earns through Points' own **Dictionary game** line, default **5**, plus the usual bonus at 80 %.
2. Only a **completed** round counts (last question answered). **End game** part-way keeps the word progress already earned but sends no result and no points.
3. Defaults follow AYS's real settings, worked out from the copied data ("undervalued": 3 right, 1 wrong → 2.75 points): **right +1, wrong −0.25, learned at 10**.

## Decisions after Phase 0 (Mariusz, 20 Nov 2026)

4. **Results label.** Dictionary had no `tbtr_tool_labels` filter yet (0.1.x words never reach Results). The bootstrap adds it as part of Task 3: `'dictionary' => 'Dictionary'`.
5. **The AYS copy uses "Points to learn a word".** `TBTDIC_Words::ays_progress( $completed, $percentage, $learnt )` takes the setting: a word AYS marked learned always comes in as `learnt` (learned, whatever the setting), and otherwise AYS's percentage carries over as that share of `learnt` (27.5 % → 2.75 of 10, or 5.5 of 20). The copy passes the setting. Tested.

## How a game reports

Game results are finished work, so — unlike new words — they **do** go through the Notes activity log, server-side, with `tbt_notes_record_activity()`:

| Field | Value |
|---|---|
| `tool` | `dictionary` |
| `object_ref` | `'tr:' . md5( ids )` or `'w:' . md5( ids )`, where `ids` = the chosen group IDs sorted ascending and joined with `-`. Replaying the same groups is a **repeat** in Points (Points' `repeat` rate, once a day); a new choice of groups earns the full rate. |
| `object_title` | `Find the translation: Science Words, HR words` (game name, colon, group names in the order shown; cut to 120 characters with "…") |
| `score`, `score_max` | right answers, words in the round |

That one row reaches TBT Results (label via `tbtr_tool_labels`: `'dictionary' => 'Dictionary'`), the Notes Class Progress panel (as "✓ Find the translation: … · 8/10") and Points (the Notes listener). Teachers in no class are not recorded (Notes returns `WP_Error`; ignored).

---

## Phase 0 — read-only (stop and report)

1. `main` is Dictionary **0.1.1**; list the files/functions you will extend (REST class, `TBTDIC_Words`, settings page, `dictionary.js` tabs/render, the `TABS` list).
2. Notes: `tbt_notes_record_activity()` arguments and return (row ID or `WP_Error`); the `object_title` length limit.
3. Points: whether **0.1.5** is merged or in a PR; `tbtp_points_for_activity( $activity_id )` exists and what it returns.
4. Results: `tbtr_tool_labels` shape (as 0.1.0 Phase 0).
5. Report anything that contradicts this spec. **Hard stop** if anything does.

---

## Task 1 — Settings: learning points

Dictionary settings page, new section **Learning**, `manage_options`:

| Option | Label | Default | Range |
|---|---|---|---|
| `tbtdic_learnt` | Points to learn a word | 10 | 1–100 |
| `tbtdic_right` | Points for a right answer | 1 | 0–100 (step 0.25) |
| `tbtdic_wrong` | Points taken for a wrong answer | 0.25 | 0–100 (step 0.25) |

Help text: "Progress is stored in points, so changing *Points to learn a word* changes every word's percentage at once." `TBTDIC_Words::settings()` (or a small settings getter) replaces every use of `TBTDIC_LEARNT_DEFAULT` at runtime; the constant stays as the default. The page's boot data sends `learnt` from the setting. *(Built as `TBTDIC_Settings::learning()`, over the pure, tested `TBTDIC_Words::learning()`.)* The AYS copy uses the setting too (decision 5).

## Task 2 — Pure helpers (`TBTDIC_Words`, tested)

- `answer( $progress, $correct, $right, $wrong, $learnt )`: `progress + right` or `progress − wrong`, clamped to `0 … learnt`, two decimals.
- `game_ref( $type, array $group_ids )`: `'tr:'|'w:'` + md5 of the sorted, de-duplicated IDs joined by `-`. Unknown type → `''`.
- `game_title( $type, array $names )`: "Find the translation: A, B" / "Find the word: A, B", cut to 120 characters with "…".
- `ays_progress( $completed, $percentage, $learnt )` (decision 5): learned in AYS → `learnt`; otherwise `percentage` % of `learnt`, clamped, two decimals.

## Task 3 — REST

| Route | Does |
|---|---|
| `POST /words/{id}/answer` `{correct: bool}` | Own word only (else 404). Applies `answer()` with the settings and saves. Returns the word. Called after **every** answer, so progress is kept even if the round is abandoned. |
| `POST /games/finish` `{type: "tr"\|"w", group_ids: int[], score: int, total: int}` | Validates: `type` known; every group ID belongs to the user (else 404); `1 ≤ total ≤ 5000`; `0 ≤ score ≤ total`. Records the Notes row (table above) when `function_exists( 'tbt_notes_record_activity' )`, inside `try/catch \Throwable`. Returns `{recorded: bool, points: int}`, where `points` = `tbtp_points_for_activity( $row_id )` when that function exists and a row was recorded, else 0. Never fails because Notes or Points is missing. |

The score is reported by the browser, as Swipe and Drill do; the repeat rule and the daily cap limit what a replayed round can earn.

`GET /state` adds `settings: {learnt, right, wrong}`.

The bootstrap adds the `tbtr_tool_labels` filter, `'dictionary' => 'Dictionary'` (decision 4).

## Task 4 — The page

1. **Tab strip appears:** **Groups · Games** (Import stays for 0.3.0). The strip hides while searching, as in the prototype.
2. **Play buttons:** on each group card (next to the progress bar, before ⋮) and on the group page header ("Play this group"). Disabled, with a tooltip "Needs at least 5 words you haven't learned yet", when the group has fewer than 5 playable words. A Play button opens the Games tab with only that group chosen.
3. **Games tab — setup** (prototype): 1. Choose a game — two cards, **Find the translation** / **Find the word**, plus a dashed, non-clickable "More games coming" card (it must not look clickable: no hover, default cursor). 2. Choose your words — group chips with "N to learn", **Number of words** 5 / 10 / 15 / 20 / All (default 10), "Learned words are left out." **Start** (pill CTA) is disabled until the chosen groups have ≥ 5 playable words; the line under it says why.
   *Playable* = not learned and has a translation.
4. **Round** (prototype): count bar, "N / M", **End game**; Find the translation shows the English word, its phonetics and the speaker (when audio is available); Find the word shows the translation. Four options: the right one plus three others from **the student's whole dictionary** (words with a translation, other than this one, no two options with the same text). *(As built: a word whose prompt text matches the question's — two words sharing one translation — is not offered either, or it would also be right.)* Keys **1–4** answer, **Enter** goes on. Right → green ✓ "Correct"; wrong → red with the jitter, the right option green, and "word = translation". Each answer calls `/answer` at once (no waiting for the response before Next).
5. **End of a finished round:** big "8 / 10", a short line (perfect / well done / keep going) and "N words learned." when some crossed 100 %; then `/games/finish`; when `recorded` → the green pill "✓ Saved to My results", and when `points` > 0 → "+N points" next to it. Table: ✓/✗, word, translation, new progress. Buttons **Play again** (same settings, new shuffle), **Change settings**, **Back to my dictionary**.
6. **End game part-way:** the same end screen for the words answered so far, but **no** `/games/finish`, and instead of the pill a muted line: "Only finished rounds go to My results." If nothing was answered, back to the setup.
7. Learned words show **Learned** (green bar) everywhere, as in 0.1.0; group and info-strip counts update after each round without a reload.

Tokens only, as 0.1.0 (correct/incorrect colours are `--tbt-success*` / `--tbt-error*`).

## Task 5 — Version and docs

`TBTDIC_VERSION` and header → `0.2.0`. README: the games and the three Learning settings. CLAUDE.md "Start here" → `docs/SPEC-0.2.0.md`.

## Validation

```bash
for f in tbt-dictionary.php includes/*.php; do php -l "$f"; done
node --check assets/js/dictionary.js
php tests/test-words.php
```

Tests: `answer()` (right, wrong, clamp at 0 and at learnt, quarter steps), `game_ref()` (order and duplicates of IDs don't change it; unknown type), `game_title()` (cut with "…"), `ays_progress()` with a non-default *Points to learn a word* (a word learned in AYS comes in learned; the percentage carries over) (decision 5).

---

## Acceptance (live, after Points 0.1.5 and Dictionary 0.2.0)

- [ ] Settings → Dictionary → Learning shows 10 / 1 / 0.25.
- [ ] Groups shows the tab strip **Groups · Games**; each card has **Play** (disabled on a group with fewer than 5 playable words).
- [ ] Play on **My English Words** → Games with that group chosen. Start a 5-word **Find the translation** round: keys 1–4 work; a wrong answer jitters and shows the right one.
- [ ] After each answer the word's progress moves: right +10 %, wrong −2.5 % (with 10 / 1 / 0.25).
- [ ] Finish a round with 4/5 or better: "✓ Saved to My results" and "+N points" = your Rules (defaults: 5 + 5 bonus at 80 %+, plus 2 if it is the first activity of the day). The same groups again the same day: repeat points (default 1) once, then 0.
- [ ] TBT Results (student and teacher): a **Dictionary** row "Find the translation: My English Words", score 4/5 etc.
- [ ] Notes Class Progress (a student in a class): "✓ Find the translation: … · 4/5".
- [ ] **End game** after 2 answers: end screen for 2 words, "Only finished rounds go to My results", nothing in Results or Points; the 2 words' progress stayed.
- [ ] A word answered right until 100 % shows **Learned** and no longer appears in games; **Reset progress** brings it back.
- [ ] **Find the word** works the same way (translation shown, English options).
- [ ] Hard refresh shows `?ver=0.2.0`.

## After this release — switching AYS off (Mariusz)

1. Settings → Dictionary → **Copy to TBT Dictionary** once more (catches words added to AYS since).
2. Point the Learning Tools menu at **My dictionary**; unpublish the AYS page.
3. Deactivate AYS Personal Dictionary (keep its tables for a while; delete the plugin later).
