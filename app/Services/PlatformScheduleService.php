<?php
declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;
use DateTimeZone;
use PDO;

final class PlatformScheduleService
{
    public static function applyAutomatic(PDO $db,int $postId,int $pageId,string $postType):void
    {
        if($postType==='story')return;
        $column=in_array($postType,['video','reel'],true)?'auto_publish_video':'auto_publish_feed';
        $query=$db->prepare("SELECT platform FROM social_connections WHERE page_id=? AND status='active' AND {$column}=1");
        $query->execute([$pageId]);$platforms=array_column($query->fetchAll(),'platform');
        $linkedin=in_array('linkedin',$platforms,true);$tiktok=in_array('tiktok',$platforms,true);
        $db->prepare('UPDATE social_posts SET publish_to_linkedin=?,linkedin_status=?,publish_to_tiktok=?,tiktok_status=? WHERE id=?')
            ->execute([$linkedin?1:0,$linkedin?'pending':'off',$tiktok?1:0,$tiktok?'pending':'off',$postId]);
        self::scheduleSelected($db,$postId);
    }

    public static function scheduleSelected(PDO $db,int $postId):void
    {
        $query=$db->prepare('SELECT id,page_id,post_type,scheduled_at,created_at,publish_to_linkedin,publish_to_tiktok,linkedin_status,tiktok_status FROM social_posts WHERE id=?');
        $query->execute([$postId]);$post=$query->fetch();if(!$post||($post['post_type']??'feed')==='story')return;
        $timezone=new DateTimeZone((string)\config('timezone','Asia/Dhaka'));
        $base=new DateTimeImmutable((string)($post['scheduled_at']?:$post['created_at']?:'now'),$timezone);

        if(!empty($post['publish_to_tiktok'])&&($post['tiktok_status']??'off')!=='published'){
            $connection=self::connection($db,(int)$post['page_id'],'tiktok');
            if($connection){
                $delay=max(0,min(10080,(int)($connection['feed_delay_minutes']??60)));
                $due=$base->modify('+'.$delay.' minutes')->format('Y-m-d H:i:s');
                $db->prepare("UPDATE social_posts SET tiktok_status='pending',tiktok_scheduled_at=? WHERE id=?")->execute([$due,$postId]);
            }
        }

        if(!empty($post['publish_to_linkedin'])&&($post['linkedin_status']??'off')!=='published'){
            $connection=self::connection($db,(int)$post['page_id'],'linkedin');
            if($connection)self::scheduleLinkedIn($db,$post,$connection,$base);
        }
    }

    private static function scheduleLinkedIn(PDO $db,array $post,array $connection,DateTimeImmutable $base):void
    {
        $limit=max(1,min(7,(int)($connection['weekly_post_limit']??1)));
        $weekday=max(1,min(7,(int)($connection['weekly_publish_weekday']??1)));
        $time=substr((string)($connection['weekly_publish_time']??'10:00:00'),0,5);
        if(!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/',$time))$time='10:00';
        $weekStart=$base->modify('monday this week')->setTime(0,0);
        $target=$weekStart->modify('+'.($weekday-1).' days')->setTime((int)substr($time,0,2),(int)substr($time,3,2));
        if($target<$base)$target=$target->modify('+7 days');
        $targetWeek=$target->modify('monday this week')->setTime(0,0);$weekEnd=$targetWeek->modify('+7 days');
        $count=$db->prepare("SELECT COUNT(*) FROM social_posts WHERE page_id=? AND id<>? AND publish_to_linkedin=1 AND linkedin_status IN ('pending','publishing','retrying','published') AND linkedin_scheduled_at>=? AND linkedin_scheduled_at<?");
        $count->execute([(int)$post['page_id'],(int)$post['id'],$targetWeek->format('Y-m-d H:i:s'),$weekEnd->format('Y-m-d H:i:s')]);
        $allocated=(int)$count->fetchColumn();
        if($allocated>=$limit){
            $db->prepare("UPDATE social_posts SET linkedin_status='skipped',linkedin_scheduled_at=NULL,linkedin_error='Skipped by weekly LinkedIn limit.' WHERE id=?")->execute([(int)$post['id']]);
            return;
        }
        $slot=$allocated;
        $due=$target->modify('+'.$slot.' minutes')->format('Y-m-d H:i:s');
        $db->prepare("UPDATE social_posts SET linkedin_status='pending',linkedin_scheduled_at=?,linkedin_error=NULL WHERE id=?")->execute([$due,(int)$post['id']]);
    }

    private static function connection(PDO $db,int $pageId,string $platform):array|false
    {
        $query=$db->prepare('SELECT * FROM social_connections WHERE page_id=? AND platform=? AND status="active" LIMIT 1');
        $query->execute([$pageId,$platform]);return $query->fetch();
    }
}
