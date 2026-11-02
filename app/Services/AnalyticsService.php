<?php
declare(strict_types=1);

namespace App\Services;

use App\Security\Crypto;
use PDO;

final class AnalyticsService
{
    public function refreshDue(PDO $db,int $limit=10):int
    {
        // Feed engagement fields are not valid for Facebook/Instagram Stories.
        // Story analytics needs the platform-specific insights endpoints and
        // metrics, so do not repeatedly send feed queries for Story object IDs.
        $query=$db->query("SELECT s.*,p.encrypted_access_token,p.encrypted_instagram_access_token FROM social_posts s JOIN social_pages p ON p.id=s.page_id WHERE s.status='published' AND s.post_type='feed' AND (s.analytics_checked_at IS NULL OR s.analytics_checked_at<DATE_SUB(NOW(),INTERVAL 6 HOUR)) ORDER BY COALESCE(s.analytics_checked_at,'1970-01-01') ASC LIMIT ".max(1,min(50,$limit)));
        $count=0;$blockedPages=[];
        foreach($query->fetchAll() as $post){
            $pageId=(int)$post['page_id'];
            if(isset($blockedPages[$pageId]))continue;
            try{
                if(!empty($post['facebook_post_id'])){$data=$this->facebook($post);$this->save($db,(int)$post['id'],'facebook',$data);}
                if(!empty($post['instagram_media_id'])){$data=$this->instagram($post);$this->save($db,(int)$post['id'],'instagram',$data);}
                $db->prepare('UPDATE social_posts SET analytics_checked_at=NOW() WHERE id=?')->execute([(int)$post['id']]);$count++;
            }catch(\Throwable $e){
                $message=$e->getMessage();

                // A missing analytics permission affects every historical post
                // belonging to the same Page. Report it once per Page/day and
                // back off the Page instead of generating one warning per post.
                if($this->isPermissionError($message)){
                    $blockedPages[$pageId]=true;
                    NotificationService::create($db,'analytics_permission','Analytics permission required',$message,$pageId,null,'warning','analytics:permission:'.$pageId.':'.date('Y-m-d'));
                    $db->prepare("UPDATE social_posts SET analytics_checked_at=DATE_ADD(NOW(),INTERVAL 18 HOUR) WHERE page_id=? AND status='published' AND post_type='feed'")->execute([$pageId]);
                    continue;
                }

                // Deleted, expired or non-feed Meta object IDs have no usable
                // engagement data. They are not publishing failures and should
                // not keep filling Notifications on every cron run.
                if($this->isUnavailableObjectError($message)){
                    $db->prepare("UPDATE social_posts SET analytics_checked_at=DATE_ADD(NOW(),INTERVAL 7 DAY) WHERE id=?")->execute([(int)$post['id']]);
                    continue;
                }

                $type=NotificationService::classify($message);$title=$type==='token_invalid'?'Meta token expired or invalid':'Analytics refresh failed';
                NotificationService::create($db,$type,$title,$message,$pageId,(int)$post['id'],$type==='token_invalid'?'error':'warning','analytics:'.$type.':'.$post['id'].':'.date('Y-m-d'));
                if($type==='token_invalid'){
                    // One expired Page token invalidates analytics for every post
                    // on that Page. Back the whole Page off for 24 hours so a
                    // one-minute cron cannot hammer Meta once per historical post.
                    $blockedPages[$pageId]=true;
                    $db->prepare("UPDATE social_posts SET analytics_checked_at=DATE_ADD(NOW(),INTERVAL 18 HOUR) WHERE page_id=? AND status='published' AND post_type='feed'")->execute([$pageId]);
                }else{
                    // A permission, deleted-object or temporary Graph error must
                    // also wait for the normal six-hour refresh window.
                    $db->prepare('UPDATE social_posts SET analytics_checked_at=NOW() WHERE id=?')->execute([(int)$post['id']]);
                }
            }
        }
        return $count;
    }

    private function facebook(array $post):array
    {
        $token=Crypto::decrypt((string)$post['encrypted_access_token']);$id=rawurlencode((string)$post['facebook_post_id']);$version=rawurlencode((string)\config('meta.graph_version','v23.0'));
        $data=$this->get("https://graph.facebook.com/{$version}/{$id}",['fields'=>'reactions.limit(0).summary(true)','access_token'=>$token]);
        $reactions=(int)($data['reactions']['summary']['total_count']??0);
        $comments=0;
        // Some Page tokens can read engagement but do not have the additional
        // permission Meta asks for when comments are requested. Keep the valid
        // reaction analytics instead of failing the whole refresh.
        try{$commentData=$this->get("https://graph.facebook.com/{$version}/{$id}",['fields'=>'comments.limit(0).summary(true)','access_token'=>$token]);$comments=(int)($commentData['comments']['summary']['total_count']??0);}catch(\Throwable $e){if(!$this->isPermissionError($e->getMessage()))throw $e;}
        $shares=0;
        try{$shareData=$this->get("https://graph.facebook.com/{$version}/{$id}",['fields'=>'shares','access_token'=>$token]);$shares=(int)($shareData['shares']['count']??0);}catch(\Throwable){}
        return ['reactions'=>$reactions,'comments'=>$comments,'shares'=>$shares,'views'=>0];
    }

    private function instagram(array $post):array
    {
        $instagramToken=Crypto::decrypt((string)($post['encrypted_instagram_access_token']??''));$instagramLogin=$instagramToken!=='';
        $token=$instagramLogin?$instagramToken:Crypto::decrypt((string)$post['encrypted_access_token']);
        $id=rawurlencode((string)$post['instagram_media_id']);$version=rawurlencode((string)\config('meta.graph_version','v23.0'));
        $hosts=$instagramLogin?['graph.instagram.com']:['graph.facebook.com'];$data=null;$lastError=null;
        foreach($hosts as $host){try{$data=$this->get("https://{$host}/{$version}/{$id}",['fields'=>'like_count,comments_count','access_token'=>$token]);break;}catch(\Throwable $e){$lastError=$e;}}
        if(!is_array($data))throw $lastError??new \RuntimeException('Instagram analytics request failed.');
        return ['reactions'=>(int)($data['like_count']??0),'comments'=>(int)($data['comments_count']??0),'shares'=>0,'views'=>0];
    }

    private function save(PDO $db,int $postId,string $platform,array $data):void
    {
        $score=(int)$data['reactions']+2*(int)$data['comments']+3*(int)$data['shares'];
        $query=$db->prepare('INSERT INTO post_analytics(post_id,platform,reactions,comments,shares,views,engagement_score,captured_at) VALUES(?,?,?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE reactions=VALUES(reactions),comments=VALUES(comments),shares=VALUES(shares),views=VALUES(views),engagement_score=VALUES(engagement_score),captured_at=NOW()');
        $query->execute([$postId,$platform,(int)$data['reactions'],(int)$data['comments'],(int)$data['shares'],(int)$data['views'],$score]);
    }

    private function isPermissionError(string $message):bool
    {
        $lower=strtolower($message);
        return str_contains($lower,'pages_read_user_content')
            || str_contains($lower,'pages_read_engagement')
            || str_contains($lower,'page public content access')
            || str_contains($lower,'application does not have permission for this action')
            || str_contains($lower,'permissions error');
    }

    private function isUnavailableObjectError(string $message):bool
    {
        $lower=strtolower($message);
        return str_contains($lower,'unsupported get request')
            || str_contains($lower,'object with id')
            || str_contains($lower,'nonexisting field (reactions)')
            || str_contains($lower,'nonexisting field (likes)');
    }

    private function get(string $url,array $params):array
    {
        $ch=curl_init($url.'?'.http_build_query($params));curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>45]);$raw=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$error=curl_error($ch);curl_close($ch);$data=json_decode((string)$raw,true);
        if($raw===false||$status<200||$status>=300){$message=(string)($data['error']['message']??$error?:'Unknown analytics API error');throw new \RuntimeException('Analytics request failed (HTTP '.$status.'): '.substr($message,0,500));}return is_array($data)?$data:[];
    }
}
