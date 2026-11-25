# Changelog

## 0.3.0

- New read-only route `POST /tbt-dictionary/v1/lookup` for the TBT Flashcards 2.4.0 lesson glossary: `{terms}` (at most 300) → the student's groups A–Z with word counts, and `have`, each sent term already in the dictionary with its group's name. Two queries; logged in only, nonce `wp_rest`, no-cache headers.
- `TBTDIC_Words::lookup_keys()`, `have_map()` and `sort_by_name()`, with tests.
- No schema change; the Dictionary page and games are unchanged.

## 0.2.0

- Games: Find the translation and Find the word. Answers move each word's progress; a finished round is recorded in TBT Notes as tool `dictionary`.
- Settings: a Learning section (points to learn a word, right and wrong answer points).

## 0.1.1

- Copy every student's AYS Personal Dictionary words, with progress, into TBT Dictionary; background AI fill-in for copied words.

## 0.1.0

- The dictionary: groups, words, AI translation, phonetics and example, audio, TBT Points for new words.
