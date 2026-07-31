<?php
// Minimal WP stubs used by unit-only tests. Heavy logic should use Brain\Monkey instead.

// WP_Error lives here rather than inline in one test file so every test sees the
// same shape. BrainServiceBackendTest defines its own copy guarded by
// class_exists(), which becomes a no-op now the bootstrap loads this first —
// that copy only implemented get_error_message(), and repositories returning
// WP_Error also need the code and the data payload.
if (!class_exists('WP_Error')) {
    class WP_Error
    {
        /** @param array<string,mixed> $data */
        public function __construct(
            private string $code = '',
            private string $message = '',
            private array $data = []
        ) {
        }

        public function get_error_code(): string
        {
            return $this->code;
        }

        public function get_error_message(): string
        {
            return $this->message;
        }

        /** @return array<string,mixed> */
        public function get_error_data(): array
        {
            return $this->data;
        }
    }
}
if (!function_exists('is_wp_error')) {
    function is_wp_error($thing): bool
    {
        return $thing instanceof \WP_Error;
    }
}

if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data, int $options = 0, int $depth = 512): string|false
    {
        return json_encode($data, $options, $depth);
    }
}
if (!function_exists('wp_generate_password')) {
    function wp_generate_password(int $length = 12, bool $special = true, bool $extra = false): string
    {
        return substr(bin2hex(random_bytes(max(1, (int) ceil($length / 2)))), 0, $length);
    }
}
if (!function_exists('wp_generate_uuid4')) {
    function wp_generate_uuid4(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
if (!function_exists('wp_salt')) {
    function wp_salt(string $scheme = 'auth'): string
    {
        return 'g2a-test-salt-' . $scheme;
    }
}
if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($str): string
    {
        return trim((string) $str);
    }
}
if (!function_exists('sanitize_key')) {
    function sanitize_key($k): string
    {
        return strtolower(preg_replace('/[^a-z0-9_\-]/i', '', (string) $k));
    }
}
if (!function_exists('sanitize_file_name')) {
    // Mirrors core's behaviour closely enough for filename assertions:
    // strip the characters core strips, collapse whitespace/dashes to '-',
    // then trim leading/trailing dots, dashes and underscores.
    function sanitize_file_name($name): string
    {
        $name = str_replace(
            ['?', '[', ']', '/', '\\', '=', '<', '>', ':', ';', ',', "'", '"', '&', '$', '#', '*', '(', ')', '|', '~', '`', '!', '{', '}', '%', '+', chr(0)],
            '',
            (string) $name
        );
        $name = preg_replace('/[\s\-]+/', '-', $name);
        return trim($name, '.-_');
    }
}
if (!function_exists('sanitize_textarea_field')) {
    function sanitize_textarea_field($str): string
    {
        return trim((string) $str);
    }
}
if (!function_exists('sanitize_email')) {
    function sanitize_email($e): string
    {
        return filter_var((string) $e, FILTER_SANITIZE_EMAIL) ?: '';
    }
}
if (!function_exists('current_time')) {
    function current_time(string $type = 'mysql', $gmt = 0): string
    {
        return $type === 'mysql' ? date('Y-m-d H:i:s') : date($type);
    }
}
if (!function_exists('wp_timezone')) {
    function wp_timezone(): \DateTimeZone
    {
        return new \DateTimeZone('UTC');
    }
}
if (!function_exists('get_current_user_id')) {
    function get_current_user_id(): int
    {
        return 1;
    }
}
if (!function_exists('get_option')) {
    function get_option(string $key, $default = false)
    {
        return $GLOBALS['__wp_options'][$key] ?? $default;
    }
}
if (!function_exists('update_option')) {
    function update_option(string $key, $val): bool
    {
        $GLOBALS['__wp_options'][$key] = $val;
        return true;
    }
}
