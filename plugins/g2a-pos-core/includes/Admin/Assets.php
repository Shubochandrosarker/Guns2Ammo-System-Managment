<?php

namespace G2A\POS\Admin;

defined('ABSPATH') || exit;

final class Assets
{
    public static function enqueue(string $hook): void
    {
        // SPA pages render their own assets inline; skip legacy admin.css/admin.js there.
        if (strpos($hook, 'g2a-pos') !== false) {
            return;
        }
    }
}
