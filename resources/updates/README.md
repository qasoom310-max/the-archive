# What's new — the system's own news

Every file here is one item on the **What's new** page (`/updates`, the megaphone
in the top bar), shown to everyone who is signed in.

**Whoever changes the system writes an entry in the same commit.** See CLAUDE.md §2 rule 10.

## One file per change

Name: `YYYY-MM-DD-short-name.json` (the date the change ships; lowercase letters, digits and dashes).

```json
{
    "app": "limousine",
    "title": { "en": "Short headline", "ar": "عنوان قصير" },
    "points": {
        "en": ["What changed, in one plain sentence.", "A second point if needed."],
        "ar": ["ما الذي تغيّر، في جملة واحدة بسيطة.", "نقطة ثانية عند الحاجة."]
    },
    "details": {
        "en": ["How it worked before and what was wrong with that.", "What it does now and why."],
        "ar": ["كيف كان يعمل سابقاً وما المشكلة في ذلك.", "ماذا يفعل الآن ولماذا."]
    },
    "guide": {
        "en": ["Open Limousine, then Bookings.", "Press the 3-dot menu on a trip.", "Press ..."],
        "ar": ["افتح الليموزين ثم الحجوزات.", "اضغط قائمة النقاط الثلاث على الرحلة.", "اضغط ..."]
    },
    "images": [
        {
            "before": "menu-before.png",
            "after": "menu-after.png",
            "caption": { "en": "The 3-dot menu on a trip.", "ar": "قائمة النقاط الثلاث على الرحلة." }
        }
    ]
}
```

- `points` is the short summary shown on the list. `details`, `guide` and `images` are the
  full story shown when the update is opened.
- **`details` and `guide` are required** on every entry dated 16 November 2026 or later.
  `details` = paragraphs: how it was, what was wrong, how it is now, why. `guide` = numbered
  steps a new member of staff could follow without help, naming the exact buttons.
- **`images` are required whenever the change can be seen on screen** (a new page, a moved
  or new button, a new column, a changed document). Take the "before" picture BEFORE you
  start changing the screen. A brand-new screen has only an "after". Skip pictures only
  for a change with nothing to look at (a calculation fix, a speed-up), and say so in
  `details`.
- Picture files go in `resources/updates/images/<the entry's file name without .json>/`.
  Names: lowercase letters, digits and dashes; `.png`, `.jpg` or `.webp`. Keep each under
  about 400 KB (crop to the part that changed; 1280 px wide is plenty).
- **Every computer needs this once:** the latest code (`git pull`), `npm install` in the
  project folder, and Google Chrome. See CLAUDE.md §6, first item.
- **Take pictures with the tool**, from the local demo copy:
  1. `scripts/demo-server.sh` in one terminal (first run builds `database/demo.sqlite`
     with made-up data; `scripts/demo-server.sh fresh` rebuilds it).
  2. `node scripts/update-shot.mjs <entry-key> <picture-name> <path>` in another, e.g.
     `node scripts/update-shot.mjs 2026-11-20-many-cars lines-before /app/rental/quotation/new`.
     Options: `--selector=CSS` (crop to one element), `--click=CSS` (open a menu first),
     `--wait=CSS`, `--full`, `--mobile`, `--ar` (Arabic screen), `--dark`. The top of
     `scripts/update-shot.mjs` lists them.
  3. Order of work: start the demo copy, create the demo records the screen needs, take the
     **before** picture, make the change, take the **after** picture with the same options.
  The tool only talks to `127.0.0.1:8765` and refuses any other address.
- **Pictures must show demo data only, never the live system.** A screenshot of the real
  site carries customers' names, phone numbers and money. Take pictures from a local copy
  seeded with made-up records.

- `app`: one of `system`, `limousine`, `rental`, `pos`, `accounting`, `purchases`,
  `inventory`, `contacts`, `project`, `whatsapp`, `website`.
- English **and** Arabic, the same number of points in each.
- Write for the person at the desk: what they will see or do differently. No class
  names, file names, migrations or test names.
- Never put customer names, phone numbers, amounts from real records, passwords or
  security details in an entry. Everyone who can sign in reads this page.

`tests/Feature/UpdatesPageTest.php` reads every file here and fails on a bad one.
