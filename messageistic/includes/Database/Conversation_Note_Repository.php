<?php
/** Inbox notes and event audit. @package Messageistic\Database */
namespace Messageistic\Database;
defined( 'ABSPATH' ) || exit;
final class Conversation_Note_Repository {
    public static function notes( int $conversation_id ): array { global $wpdb;$t=$wpdb->prefix.'messageistic_conversation_notes';return(array)$wpdb->get_results($wpdb->prepare("SELECT n.*,u.display_name FROM {$t} n LEFT JOIN {$wpdb->users} u ON u.ID=n.user_id WHERE conversation_id=%d ORDER BY n.id DESC",$conversation_id),ARRAY_A); }
    public static function add( int $conversation_id,string $note ): void { global $wpdb;$wpdb->insert($wpdb->prefix.'messageistic_conversation_notes',['conversation_id'=>$conversation_id,'user_id'=>get_current_user_id(),'note'=>sanitize_textarea_field($note),'created_at'=>current_time('mysql')]); }
    public static function event( int $conversation_id,string $type,string $old,string $new ): void { global $wpdb;$wpdb->insert($wpdb->prefix.'messageistic_conversation_events',['conversation_id'=>$conversation_id,'event_type'=>sanitize_key($type),'old_value'=>sanitize_text_field($old),'new_value'=>sanitize_text_field($new),'user_id'=>get_current_user_id(),'created_at'=>current_time('mysql')]); }
    public static function events( int $conversation_id ): array { global $wpdb;$t=$wpdb->prefix.'messageistic_conversation_events';return(array)$wpdb->get_results($wpdb->prepare("SELECT * FROM {$t} WHERE conversation_id=%d ORDER BY id DESC LIMIT 100",$conversation_id),ARRAY_A); }
}
