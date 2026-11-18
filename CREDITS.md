# PublishRank — credits and licences

PublishRank is developed and maintained by Muhammad Umar Jabbar and released under the GNU General Public License v2 or later (see `LICENSE.txt`). All PublishRank modules — including Access Shield (sign-in protection, private login address, two-factor, CAPTCHA, lockouts and the sign-in page designer), Traffic Guard, the FAQ block, the SEO Inspector and the schema engine — are first-party PublishRank code.

## Bundled third-party components

| Component | Version | Used for | Licence | Location |
|---|---|---|---|---|
| Font Awesome Free | 7.3.1 | Admin interface icons | Icons CC BY 4.0, fonts SIL OFL 1.1, code MIT | `assets/vendor/fontawesome-free-7.3.1/` (licence included) |
| qrcode-generator (Kazuhiko Arase) | 1.4.4 | QR code shown when setting up two-factor authentication | MIT | `assets/vendor/qrcode-generator/` (licence included) |
| Country names from umpirsky/country-list | — | English country names in the country database | MIT | `src/Core/Countries.php`, licence in `COUNTRY-LIST-LICENSE.txt` |
| Natural Earth 1:110m Admin 0 countries | — | Shapes for the analytics world map, rasterised into `assets/js/pr-worldmap.js` | Public domain | https://www.naturalearthdata.com/about/terms-of-use/ |

ISO 3166-1 alpha-3 and numeric codes in the country database were cross-checked against the Debian `iso-codes` data set. Country codes themselves are facts defined by the ISO 3166 standard.

"QR Code" is a registered trademark of DENSO WAVE INCORPORATED. Google, Cloudflare, hCaptcha and other names mentioned in the interface belong to their owners and are used only to describe compatibility.

## No other bundled code

PublishRank loads no third-party scripts, styles or fonts from a CDN. Optional connections to outside services (IndexNow, CAPTCHA providers, the breached-password check, Google Search Console, the PublishRank news feed) are off until you switch them on, and are described under "External services" in `readme.txt`.
