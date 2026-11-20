# TBT Dictionary

Each student keeps their own dictionary on The Blue Tree: words in groups they name themselves. A student types a word; AI fills in the translation (if left empty), the British phonetics and an example sentence; the word can be heard. Every new word earns TBT Points.

It replaces the third-party AYS Personal Dictionary in stages: 0.1.0 is the dictionary itself, 0.1.1 copies every student's AYS words across, 0.2.0 adds games, 0.3.0 adds Excel/CSV import.

## Shortcode

`[tbt_dictionary]` on a page (e.g. **My dictionary** in the Learning Tools menu). Logged-out visitors see a login card. Tablet and desktop; no page hero (the Divi page supplies its header).

A dictionary belongs to one user: nobody else, teachers included, can see or change it.

## Settings

**TBT → Dictionary Settings** (administrators; its own menu when TBT Hub is not active):

- **OpenAI API key** — blank uses TBT Swipe's key. Enter `-` to remove a saved key. With no key anywhere, AI fill-in is off and words save as typed.
- **Model** — blank uses TBT Swipe's model, else `gpt-4o-mini`.
- **AI fill-ins per student per day** — default 100; 0 means no limit. Past the limit a word saves without AI.

## Copying the old AYS dictionary (0.1.1)

When AYS Personal Dictionary's tables are on the site, **Dictionary Settings** shows an **Old dictionary** section with a **Copy to TBT Dictionary** button. It copies every student's AYS groups and words, with their progress (learned in AYS → Learned; otherwise AYS's percentage), into their TBT dictionary. AYS is only read, never changed, and stays on until it is switched off. Running the copy again only adds words that are new in AYS; a word the student already has is skipped. Copied words earn no points. Their phonetics and examples are added by a background job, about 10 words every 2 minutes while the site has visitors, without using the students' daily AI limit; the page itself only fills words added in the last 10 minutes.

Developers: the `tbtdic_translation_language` filter changes the translation language (default `Polish`).

## Optional companions

Nothing here is required; without any of them the dictionary still works.

| Plugin | What it adds |
|---|---|
| TBT Points 0.1.4+ | A point per new word (the **Dictionary: new word** rule), once per word per student, plus Points' day bonus and daily cap. Words are not recorded in the Notes activity log, so they never appear in TBT Results or the Class Progress panel. |
| TBT Swipe | Its OpenAI key and model, when the dictionary has none of its own. |
| TBT Flashcards | Audio: with its ElevenLabs key set, each word gets a speaker button through Flashcards' TTS proxy (shared voice and MP3 cache). |
| TBT Hub | The admin menu and the shared design tokens (a byte-identical copy of tokens 2.2 is bundled). |

## Development

Pure PHP and vanilla JavaScript, no build step. See `CLAUDE.md` and `docs/SPEC-0.1.0.md`.

```bash
for f in tbt-dictionary.php includes/*.php; do php -l "$f"; done
node --check assets/js/dictionary.js
php tests/test-words.php
```

Fonts: Roboto Slab and Roboto Mono (500), both Apache 2.0, self-hosted.
