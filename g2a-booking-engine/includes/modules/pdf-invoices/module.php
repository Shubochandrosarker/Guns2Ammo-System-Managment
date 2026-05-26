<?php
/**
 * Module manifest: PDF Invoices.
 *
 * @package G2AB\Modules\PDFInvoices
 */
if ( ! defined( 'ABSPATH' ) ) exit;

require_once __DIR__ . '/class-invoice-engine.php';
require_once __DIR__ . '/class-invoice-settings.php';
require_once __DIR__ . '/class-invoice-renderer.php';
require_once __DIR__ . '/class-pdf-invoices.php';

return array(
	'id'             => 'pdf_invoices',
	'name'           => 'Branded PDF Invoices',
	'desc'           => 'Auto-generate branded PDF invoices for paid bookings. Logo upload, color customization, payment-link embed, email attachment.',
	'tier'           => 'free',
	'status'         => 'active',
	'category'       => 'sales',
	'icon'           => 'I',
	'color'          => '#1F3864',
	'default_active' => true,
	'bootstrap'      => 'G2AB_Module_PDF_Invoices',
	'configure'      => 'admin.php?page=g2ab-settings&tab=pdf_invoices',
);
