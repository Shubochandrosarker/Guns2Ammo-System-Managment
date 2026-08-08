<?php

define( 'ABSPATH', __DIR__ . '/' );
function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $value ) ); }
function __( $value ) { return $value; }
class WP_Error {
    public function __construct( private string $code, private string $message ) {}
    public function get_error_code(): string { return $this->code; }
    public function get_error_message(): string { return $this->message; }
}
require_once dirname( __DIR__ ) . '/includes/Providers/SMS_Provider_Interface.php';
require_once dirname( __DIR__ ) . '/includes/Providers/Abstract_Provider.php';
require_once dirname( __DIR__ ) . '/includes/Providers/SMSGate_Provider.php';
require_once dirname( __DIR__ ) . '/includes/Compliance/Message_Classification.php';
require_once dirname( __DIR__ ) . '/includes/Compliance/Provider_Eligibility.php';
require_once dirname( __DIR__ ) . '/includes/Compliance/Pilot_Policy.php';
require_once dirname( __DIR__ ) . '/includes/Compliance/Promotion_Policy.php';
