# TBT Dictionary 0.3.0 — lookup route for the lesson glossary

Flashcards 2.4.0 adds a **+ My Dictionary** button to every row of a lesson glossary. When the glossary loads for a logged-in student, it needs to know two things: the student's groups (for the group picker) and which glossary words are already in their dictionary (those rows show **✓ In my dictionary** instead of the button). Today the only route that answers this is `GET /state`, which sends back every word the student has. 0.3.0 adds one small, read-only route that answers exactly this question.

Nothing else changes. Adding a word from the glossary uses the existing `POST /words` (with `group_name`) and `POST /words/{id}/fill`, so the duplicate check, the AI fill-in and the Points award all work exactly as on the Dictionary page.

Repo: `aparatofan/tbt-dictionary`, from `main` at **0.2.0**.

## Phase 0 — read-only inventory (stop and report)

1. Confirm `main` is at 0.2.0 and list any open PRs.
2. Show `TBTDIC_Rest::can()`, `create_word()`, `group_out()` and `TBTDIC_Words::key()` (file and line). Confirm `POST /words` with `{group_name, term, translation}` creates the group when it doesn't exist, and returns `{word, group, points}`. Also confirm that a duplicate returns 409 with the existing group's name in the message.
3. Confirm there is an index on `(user_id, term_key)` (Dictionary 0.1.0 Task 1).
4. **Report and STOP.** **Hard stop** if any of this differs.

## Task 1 — `POST /lookup`

Namespace `tbt-dictionary/v1`, `permission_callback` → `can()` (logged in, tables present, no-cache headers), nonce `wp_rest`. It's a `POST` only because the list of terms can be long; it changes nothing.

**Request:** `{ "terms": ["sleep deprivation", "to doze off", …] }`
- At most **300** terms (400 with a plain message above that). Each is cut to 120 characters. Empty strings are ignored.

**Response:**
```json
{
  "groups": [ { "id": 12, "name": "Science words", "word_count": 23 }, … ],
  "have":   { "drowsy": "Science words", "to doze off": "Why we sleep" }
}
```
- `groups`: all the student's groups, A–Z by name (case-insensitive), with their word counts. Only id, name and word_count: no progress figures and no word lists.
- `have`: for each term **as sent** whose `TBTDIC_Words::key()` matches a word in the student's dictionary, the name of the group that word is in. Terms that aren't there are left out.
- Two queries in total: one for the groups (with counts), and one `SELECT term_key, group_id … WHERE user_id = %d AND term_key IN (…)` with the keys of the sent terms. Keys are de-duplicated before the query.

## Task 2 — Version and docs

`TBTDIC_VERSION` and the header → `0.3.0`. Add a CHANGELOG entry. README: document the route and say it's used by the Flashcards glossary.

## Task 3 — Tests

In `tests/test-words.php`, add a test for the pure part, if there is one: building the `have` map from sent terms and found keys, with duplicates, case and accents. If this logic lives only in the REST method, move it into `TBTDIC_Words::have_map( array $terms, array $found )` and test that.

## Validation

```bash
for f in tbt-dictionary.php includes/*.php; do php -l "$f"; done
php tests/test-words.php
```

## Acceptance (live)

- [ ] Logged in, in the browser console on any page:
  ```js
  fetch('/wp-json/tbt-dictionary/v1/lookup', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': wpApiSettings?.nonce },
    body: JSON.stringify({ terms: ['drowsy'] })
  })
  ```
  This returns your groups. If `drowsy` is in your dictionary, it appears in `have`. (If `wpApiSettings` isn't on the page, test it from the glossary after Flashcards 2.4.0.)
- [ ] Logged out: 401.
- [ ] The Dictionary page and its games work exactly as before.
- [ ] PHP error log: nothing new from `tbt-dictionary`.
