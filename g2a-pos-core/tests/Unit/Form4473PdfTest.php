<?php

namespace G2A\POS\Tests\Unit;

use G2A\POS\Compliance\ATF\Form4473Pdf;
use PHPUnit\Framework\TestCase;

final class Form4473PdfTest extends TestCase
{
    public function test_fallback_produces_valid_pdf_bytes(): void
    {
        // Build an in-memory form by short-circuiting the repository call.
        $form = [
            'form_serial' => 'G2A-4473-TEST',
            'transaction_status' => 'in_progress',
            'transferee_last' => 'Smith',
            'transferee_first' => 'John',
            'transferee_middle' => 'W',
            'transferee_dob' => '1990-01-15',
            'transferee_address' => '123 Main St',
            'transferee_city' => 'Mesa',
            'transferee_state' => 'AZ',
            'transferee_zip' => '85210',
            'transferee_phone' => '555-0100',
            'us_citizen' => 1,
            'id_type' => 'drivers_license',
            'id_number' => 'D12345678',
            'id_issuer' => 'AZ',
            'id_expiration' => '2030-01-15',
            'firearms' => [
                ['manufacturer' => 'Glock', 'model' => '19', 'serial_number' => 'ABC123', 'firearm_type' => 'handgun', 'caliber' => '9mm'],
            ],
            'section_b_answers' => ['21a' => false, '21b' => false],
            'nics_response' => 'proceed',
            'nics_response_date' => '2026-06-02 10:00:00',
            'transferee_signature_at' => '2026-06-02 10:05:00',
            'transferor_signature_at' => '2026-06-02 10:06:00',
            'transferred_at' => '2026-06-02 10:10:00',
            'retention_until' => '2046-06-02',
        ];

        $pdf = invoke_private_render_fallback($form);
        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertStringEndsWith("%%EOF\n", $pdf);
        $this->assertGreaterThan(1000, strlen($pdf));
    }

    public function test_template_available_returns_false_without_template(): void
    {
        $this->assertFalse(Form4473Pdf::template_available());
    }
}

// Workaround: invoke the private fallback path via reflection.
function invoke_private_render_fallback(array $form): string
{
    $ref = new \ReflectionClass(Form4473Pdf::class);
    $method = $ref->getMethod('render_fallback');
    $method->setAccessible(true);
    return (string) $method->invoke(null, $form);
}
