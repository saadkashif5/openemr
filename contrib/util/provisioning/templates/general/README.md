# General practice template

Use with `"specialty": "general"` in a practice spec.

| File | What it adds |
|---|---|
| `seed.sql` | A "Wellness Visit" encounter form (Visit, Screening and Plan sections) and the two lists it uses |

Not every template needs every part. The provisioner also supports `globals.json` (settings) and a
`site/` folder (files copied over the new practice's folder), and this one uses neither.

To change the form or lists, edit `seed.sql`. It only affects practices created afterwards;
existing practices keep what they were created with.
