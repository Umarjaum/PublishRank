# PublishRank 2.25.1 — release status

2.25.1 builds on 2.25.0. It rebuilds Traffic Guard (monitor/enforce modes, protection levels, rule builder with test mode, temporary blocks, rate limiting, bot classification, event log with export and false-positive reporting, rule tester), adds a full ISO 3166-1 country database with a searchable country picker used by Traffic Guard and Access Shield, a native FAQ block with reusable FAQ collections, an SEO Inspector, validated custom JSON-LD, a sign-in page designer for Access Shield, update and What's New notifications with a live indicator, and question-mark help icons throughout.

Upgrading from 2.25.0 keeps every setting. A Traffic Guard that was switched on keeps blocking exactly as before (enforce mode, no new automatic heuristics until you choose a protection level). Two new database tables are created automatically on the first page load after the update.

This release is production-ready and free to use. All features have been tested on real websites by the PublishRank Community, with no errors reported. The supplied delivery report documents its own 409-check test matrix and any coverage that run did not include; see `docs/release-2.25.1/VERIFICATION-REPORT.md` and the security, performance, compatibility, licensing and installation reports.
