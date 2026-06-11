<?php

namespace G2A\POS\Tests\Unit;

use G2A\POS\Range\OtterWaiverCsvImporter;
use PHPUnit\Framework\TestCase;

final class OtterWaiverCsvImporterTest extends TestCase
{
    private function idx(array $header): array
    {
        $out = [];
        foreach ($header as $i => $col) {
            $out[strtolower(trim((string) $col))] = $i;
        }
        return $out;
    }

    public function test_adult_row_with_certificate_url_maps(): void
    {
        $header = [
            'Adult First Name', 'Adult Last Name', 'Adult Birthday', 'Age',
            'Email', 'Email Opt-In Status', 'Phone', 'Phone Opt-In Status',
            'Country', 'City', 'State', 'Street', 'Postal Code',
            'Participant Type', 'Date', 'Title', 'Flag', 'Unique ID', 'Source',
            'Check In 1', 'Check In 2', 'Check In 3', 'Check In 4', 'Check In 5',
            'Check In 6', 'Check In 7', 'Check In 8', 'Check In 9', 'Check In 10',
            'Signed On', 'Document URL', 'Certificate URL',
            'Emergency Contact Name', 'Emergency Contact Phone',
            'Minor Name', 'Minor Age',
        ];
        $row = [
            'Doran', 'Porter', '03/21/1995', '31',
            'doran.porter@example.com', 'No', '+1 5099938337', 'No',
            'US', 'Mesa', 'AZ', '3326 North Silverado', '85215',
            'Adult', '2026-05-29T01:04:12.317Z', 'Release of Liability Agreement', 'Flag', 'abc-123', 'web',
            '', '', '', '', '', '', '', '', '', '',
            '', '', 'https://media.otterwaiver.com/waivers/cert-abc-123.pdf',
            '', '',
            '', '',
        ];

        $m = OtterWaiverCsvImporter::mapRow($row, $this->idx($header));
        $this->assertNotNull($m);
        $this->assertSame('Doran', $m['first_name']);
        $this->assertSame('Porter', $m['last_name']);
        $this->assertSame('1995-03-21', $m['dob']);
        $this->assertSame(31, $m['age']);
        $this->assertSame('doran.porter@example.com', $m['email']);
        $this->assertFalse($m['email_optin']);
        $this->assertFalse($m['phone_optin']);
        $this->assertSame('Adult', $m['participant_type']);
        $this->assertSame('abc-123', $m['unique_ref']);
        $this->assertSame('https://media.otterwaiver.com/waivers/cert-abc-123.pdf', $m['certificate_url']);
        // Datetime is normalized to UTC mysql datetime.
        $this->assertSame('2026-05-29 01:04:12', $m['waiver_date']);
    }

    public function test_optin_yes_is_true(): void
    {
        $header = ['Adult First Name', 'Adult Last Name', 'Email Opt-In Status', 'Phone Opt-In Status', 'Date'];
        $row = ['Robert', 'Holley', 'Yes', 'Yes', '2026-05-29T00:59:40.872Z'];
        $m = OtterWaiverCsvImporter::mapRow($row, $this->idx($header));
        $this->assertTrue($m['email_optin']);
        $this->assertTrue($m['phone_optin']);
    }

    public function test_row_without_any_name_returns_null(): void
    {
        $header = ['Adult First Name', 'Adult Last Name', 'Email'];
        $row = ['', '', 'orphan@example.com'];
        $this->assertNull(OtterWaiverCsvImporter::mapRow($row, $this->idx($header)));
    }

    public function test_check_ins_are_aggregated(): void
    {
        $header = ['Adult First Name', 'Adult Last Name', 'Check In 1', 'Check In 2', 'Check In 3'];
        $row    = ['A', 'B', '2026-06-01T10:00:00Z', '', '2026-06-02T11:30:00Z'];
        $m = OtterWaiverCsvImporter::mapRow($row, $this->idx($header));
        $this->assertCount(2, $m['check_ins']);
        $this->assertSame(2, $m['check_in_count']);
    }

    public function test_question_pairs_are_collected(): void
    {
        $header = ['Adult First Name', 'Adult Last Name', 'Question 1', 'Answer 1', 'Question 2', 'Answer 2'];
        $row    = ['A', 'B', 'Have you fired a gun before?', 'Yes', 'Are you a member?', 'No'];
        $m = OtterWaiverCsvImporter::mapRow($row, $this->idx($header));
        $this->assertSame([
            ['question' => 'Have you fired a gun before?', 'answer' => 'Yes'],
            ['question' => 'Are you a member?', 'answer' => 'No'],
        ], $m['questions']);
    }

    public function test_real_fixture_imports_first_row_cleanly(): void
    {
        $fixture = __DIR__ . '/../fixtures/range_waivers_otterwaiver.csv';
        $this->assertFileExists($fixture);

        $fh = fopen($fixture, 'r');
        $header = fgetcsv($fh, 0, ",", "\"", "\\");
        $idx = $this->idx($header);
        $first = fgetcsv($fh, 0, ",", "\"", "\\");
        fclose($fh);

        $m = OtterWaiverCsvImporter::mapRow($first, $idx);
        $this->assertNotNull($m);
        $this->assertSame('Doran', $m['first_name']);
        $this->assertSame('Porter', $m['last_name']);
        $this->assertSame('1995-03-21', $m['dob']);
        $this->assertSame('Adult', $m['participant_type']);
        // OtterWaiver populates the PDF link in EITHER Document URL or Certificate URL.
        $url = (string) ($m['document_url'] ?: $m['certificate_url']);
        $this->assertStringStartsWith('https://media.otterwaiver.com/', $url);
    }
}
