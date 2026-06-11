<?php

namespace G2A\POS\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class SecurityRegressionTest extends TestCase
{
    public function test_ai_settings_route_requires_ai_manager(): void
    {
        $routes = (string) file_get_contents(G2A_POS_CORE_PATH . 'includes/API/Routes.php');
        $start = strpos($routes, "'/ai/settings'");
        $block = substr($routes, $start, 350);
        $this->assertStringContainsString("current_user_can( 'g2a_pos_manage_ai' )", $block);
        $this->assertStringNotContainsString("current_user_can( 'g2a_pos_use_ai' )", $block);
    }

    public function test_ai_controller_redacts_api_key_and_checks_conversation_owner(): void
    {
        $source = (string) file_get_contents(G2A_POS_CORE_PATH . 'includes/API/AiController.php');
        $this->assertStringContainsString("unset( \$config['api_key'] )", $source);
        $this->assertStringContainsString('canAccessConversation( $conv )', $source);
        $this->assertStringContainsString("current_user_can( 'g2a_pos_manage_ai' )", $source);
    }

    public function test_lane_does_not_persist_bearer_token_in_local_storage(): void
    {
        $source = (string) file_get_contents(G2A_POS_CORE_PATH . 'assets/lane/modules/api.js');
        $this->assertStringContainsString("sessionStorage.setItem('g2a_pos_token'", $source);
        $this->assertStringNotContainsString("localStorage.setItem('g2a_pos_token'", $source);
    }

    public function test_plugin_checks_schema_version_during_boot(): void
    {
        $source = (string) file_get_contents(G2A_POS_CORE_PATH . 'includes/Core/Plugin.php');
        $this->assertStringContainsString('self::maybe_upgrade();', $source);
        $this->assertStringContainsString("get_option( 'g2a_pos_core_db_version' )", $source);
    }
}
