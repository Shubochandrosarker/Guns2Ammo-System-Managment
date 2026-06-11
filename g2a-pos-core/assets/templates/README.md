# 4473 PDF templates

Drop the official **ATF Form 5300.9 (Aug 2023)** PDF here as `4473.pdf` to enable
overlay mode in `Form4473Pdf::render()`. The form is not bundled because:

1. ATF revises it periodically — bundling a stale form risks generating an
   out-of-date record.
2. The form's licensing terms are clearer when the licensee downloads their own
   copy from atf.gov.

## Steps

1. Download from <https://www.atf.gov/firearms/docs/form/form-4473-firearms-transaction-record/download> (or current ATF URL).
2. Save as `assets/templates/4473.pdf`.
3. Copy `4473-fields.example.json` to `4473-fields.json` and tune the
   coordinates to match the official template. Field positions are in
   millimeters from the top-left of each page.
4. Generate a sample 4473, hit `GET /wp-json/g2a-pos/v1/atf/4473/{id}/pdf`,
   compare against the official form, adjust `x`/`y` values, repeat.

Until both files exist, `Form4473Pdf::render()` falls back to a clean
multi-page transcript (FPDF generated). The transcript is suitable for
internal records but is not a substitute for the official 4473.

## Override

You can store the template anywhere on disk by setting the
`g2a_pos_4473_template_path` option:

```php
update_option('g2a_pos_4473_template_path', '/path/to/4473.pdf');
```

(Keep it outside the plugin directory if your deploy process wipes the
plugin folder on update.)
