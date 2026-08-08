# dist — installable builds

Production-clean ZIPs of every current component, ready to upload via
**WordPress → Plugins → Add New → Upload Plugin** (or **Appearance → Themes**
for the theme).

Builds exclude development tooling (tests, `composer.json`/`.lock`, `phpunit.xml`,
`node_modules`, CI config) but **include** runtime dependencies — `g2a-pos-core`
ships its `vendor/` (FPDI/FPDF for receipt PDFs), and the booking engine ships
`assets/vendor/` (FullCalendar, qrcode).

Every plugin in `plugins/` and the theme are packaged here — including
`g2a-business-api`, which the staff dashboard at app.guns2ammo.com depends on
and which was missing from earlier builds.

Install order and pre-flight steps: see the root [README](../README.md#installing-on-the-site).

Rebuild everything, or just one component:

```bash
scripts/build-release-zips.sh
scripts/build-release-zips.sh plugins/g2a-booking-engine
```

The build refuses to produce an artifact that would not install: each archive
is checked to have the component directory at its root (not `plugins/...`), and
a component with no declared version is an error rather than a `-dev.zip`.

Historical builds are in `archives/releases-legacy/`.
