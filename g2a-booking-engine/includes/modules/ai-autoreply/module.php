<?php
/**
 * Module manifest: AI Auto-Reply (OpenRouter).
 *
 * @package G2AB\Modules\AIAutoReply
 */
if ( ! defined( 'ABSPATH' ) ) exit;

require_once __DIR__ . '/class-openrouter-client.php';
require_once __DIR__ . '/class-autoreply-engine.php';
require_once __DIR__ . '/class-autoreply-settings.php';
require_once __DIR__ . '/class-ai-autoreply.php';

return array(
	'id'             => 'ai_autoreply',
	'name'           => 'AI Auto-Reply (OpenRouter)',
	'desc'           => 'Generate intelligent reply drafts to customer inquiries using OpenRouter free-tier LLMs (Llama 3.1, Mistral, Gemma). Knowledge-base aware.',
	'tier'           => 'free',
	'status'         => 'active',
	'category'       => 'notifications',
	'icon'           => 'A',
	'color'          => '#0F4C75',
	'default_active' => false,
	'bootstrap'      => 'G2AB_Module_AI_AutoReply',
	'configure'      => 'admin.php?page=g2ab-settings&tab=ai_autoreply',
);
