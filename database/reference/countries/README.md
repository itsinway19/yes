# Neutral country reference

The membership snapshot is Debian iso-codes' ISO 3166-1 database, redistributed **unchanged** by pycountry. Its pinned README identifies that upstream and explicitly forbids pycountry-specific data modifications. This is a maintained standard-derived identity source; it is not represented as an ISO-published translation or a live ISO feed. All 249 source entries (including standard territories) are retained. Reserved, user-assigned and region shortcuts do not enter the reference.

English and Arabic display labels come independently from pinned Unicode CLDR 48.2.3 territory files. CLDR's extra region and user-assigned labels do not affect membership. There is no manually authored label, fallback spelling, inferred location, Commercial catalog input or runtime network dependency.

See provenance.json for immutable source URLs, source snapshot dates, separate identity/localization hashes, and original source-byte hashes. Sources retain their license/copyright notices: Debian data is LGPL-2.1; pycountry distribution is LGPL-2.1-or-later; Unicode data is Unicode-3.0. Upstream attribution is retained in sources/pycountry-COPYRIGHT.txt and sources/pycountry-README.rst.

## Deterministic extraction

1. Read sources/iso_3166-1.json's `3166-1` array, extract `alpha_2`, verify uniqueness, and sort ascending. Write compact identities.json with one final LF (UTF-8, no BOM). Identity SHA-256 covers the **codes joined by LF with one final LF**, not this JSON file and never labels.
2. For every accepted code, read the exact `main.en.localeDisplayNames.territories[code]` and `main.ar.localeDisplayNames.territories[code]` entries from the checked-in CLDR snapshots. Require both, normalize each to Unicode NFC, and preserve all other spelling/diacritics. No `-alt-*` labels or fallback are selected.
3. Emit a code-sorted compact JSON array with fixed property order `code,name_en,name_ar` and one final LF. Its UTF-8 SHA-256 is the localization digest. A label-only change alters this digest while retaining identity count/digest.
4. Independently run `node --test tests/country-reference.test.mjs` from the repository root. It reproduces complete source membership and both label mappings, verifies raw-source hashes and both content digests, and proves that changing a label cannot change identity membership.

The pinned Git blob hashes additionally permit raw-byte comparison with the fetched upstream files. Installation copies only code and label fields into the neutral reference; sources and provenance remain installation evidence.
