<?php
/** Automation steps and runs. @package Messageistic\Database */
namespace Messageistic\Database;
defined( 'ABSPATH' ) || exit;
final class Automation_Step_Repository {
    private static function table(): string { global $wpdb; return $wpdb->prefix.'messageistic_automation_steps'; }
    private static function runs(): string { global $wpdb; return $wpdb->prefix.'messageistic_automation_runs'; }
    public static function replace( int $automation_id, array $steps ): void { global $wpdb;$wpdb->delete(self::table(),['automation_id'=>$automation_id]);$now=current_time('mysql');foreach(array_values($steps) as $i=>$step){$wpdb->insert(self::table(),['automation_id'=>$automation_id,'step_order'=>$i,'step_type'=>sanitize_key((string)($step['step_type']??'send_sms')),'delay_seconds'=>max(0,(int)($step['delay_seconds']??0)),'template_id'=>!empty($step['template_id'])?(int)$step['template_id']:null,'config'=>wp_json_encode((array)($step['config']??[])),'created_at'=>$now,'updated_at'=>$now]);}}
    public static function for_automation( int $id ): array { global $wpdb;$t=self::table();return(array)$wpdb->get_results($wpdb->prepare("SELECT * FROM {$t} WHERE automation_id=%d ORDER BY step_order,id",$id),ARRAY_A); }
    public static function start_run( int $automation_id,int $version,int $contact_id,?int $location_id,array $context ): int { global $wpdb;$now=current_time('mysql');$wpdb->insert(self::runs(),['automation_id'=>$automation_id,'automation_version'=>$version,'contact_id'=>$contact_id,'location_id'=>$location_id,'current_step'=>0,'status'=>'running','context'=>wp_json_encode($context),'started_at'=>$now,'updated_at'=>$now]);return(int)$wpdb->insert_id; }
    public static function find_run( int $id ): ?array { global $wpdb;$t=self::runs();$r=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE id=%d",$id),ARRAY_A);return is_array($r)?$r:null; }
    public static function advance( int $id,int $next,string $status='running' ): void { global $wpdb;$row=['current_step'=>$next,'status'=>$status,'updated_at'=>current_time('mysql')];if('completed'===$status){$row['completed_at']=current_time('mysql');}$wpdb->update(self::runs(),$row,['id'=>$id]); }
}
