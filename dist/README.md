# dist — installable builds

Production-clean ZIPs of every current component, ready to upload via
**WordPress → Plugins → Add New → Upload Plugin** (or **Appearance → Themes**
for the theme).

Builds exclude development tooling (tests, `composer.json`/`.lock`, `phpunit.xml`,
`node_modules`, CI config) but **include** runtime dependencies — `g2a-pos-core`
ships its `vendor/` (FPDI/FPDF for receipt PDFs), and the booking engine ships
`assets/vendor/` (FullCalendar, qrcode).

Install order and pre-flight steps: see the root [README](../README.md#installing-on-the-site).

Rebuild with:

```bash
scripts/build-release-zips.sh
```

Historical builds are in `archives/releases-legacy/`.
