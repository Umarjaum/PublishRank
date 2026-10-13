# Contributing to PublishRank

Thank you for helping improve a free, publisher-focused WordPress SEO plugin.

## Before opening an issue or pull request

1. Search existing issues and pull requests.
2. Reproduce the problem on a clean WordPress installation when possible.
3. Confirm the behavior with `WP_DEBUG` enabled.
4. Avoid including credentials, private URLs, personal data, or production exports.
5. For security vulnerabilities, do not open a public issue; contact the maintainer privately first.

## Development workflow

```bash
git clone https://github.com/Umarjaum/PublishRank.git
cd PublishRank

# Syntax validation.
find . -type f -name '*.php' -print0 | xargs -0 -n1 php -l

# Lightweight regression tests.
for test in tests/*.php; do php "$test" || exit 1; done
```

The XML sitemap test requires PHP SimpleXML. On Ubuntu/Debian:

```bash
sudo apt install php-xml
```

For behavior that depends on WordPress hooks, admin screens, rewrites, or frontend output, also test in a clean WordPress installation or WordPress Playground. Include the WordPress and PHP versions used in your report.

## Pull-request standards

- Use a focused branch and a descriptive title.
- Keep one logical change per pull request where practical.
- Explain the problem, the behavior before the change, and the behavior after it.
- Add or update a regression test for bug fixes.
- Keep PHP, JavaScript, and CSS human-readable.
- Use WordPress APIs for enqueueing, sanitization, escaping, capabilities, nonces, database access, and HTTP requests.
- Do not add remote executable assets, tracking, telemetry, license checks, or undisclosed external services.
- Document any external service, data sent, trigger, and administrator control in `readme.txt`.
- Keep public attribution and credits explicitly opt-in.
- Update user-facing documentation and the changelog when behavior changes.
- Do not bump the plugin version unless the maintainer asks for a release.

## Pull-request checklist

- [ ] The change is scoped and explained in the PR description.
- [ ] PHP lint passes for every PHP file.
- [ ] All scripts in `tests/` pass.
- [ ] Activation and frontend behavior were checked with `WP_DEBUG=true` when relevant.
- [ ] Output is escaped and database queries are prepared appropriately.
- [ ] Admin assets are scoped to the plugin’s own screens.
- [ ] No remote JavaScript, CSS, image, tracking, or undisclosed service was added.
- [ ] Readme, documentation, privacy, and external-service disclosures are updated when needed.
- [ ] Screenshots or before/after output are included for UI or markup changes.

## Commit and review guidance

Prefer clear imperative commit messages, such as `Fix News Sitemap UTC boundary`. Maintainers may request changes, squash commits, or close changes that conflict with WordPress.org guidelines, compatibility requirements, or the project’s publisher-first scope.
