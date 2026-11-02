<?php
declare(strict_types=1);

namespace App\Services;

use PDO;

final class NotificationService
{
    public static function create(PDO $db,string $type,string $title,string $message,?int $pageId=null,?int $postId=null,string $severity='warning',?string $dedupeKey=null):void
    {
        $severity=in_array($severity,['info','warning','error'],true)?$severity:'warning';
        $dedupeKey=$dedupeKey?:hash('sha256',implode('|',[$type,$pageId??0,$postId??0,date('Y-m-d'),substr($message,0,160)]));
        $query=$db->prepare('INSERT INTO notifications(page_id,post_id,type,severity,title,message,dedupe_key) VALUES(?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE message=VALUES(message),severity=VALUES(severity),read_at=NULL,created_at=CURRENT_TIMESTAMP');
        $query->execute([$pageId,$postId,$type,$severity,substr($title,0,190),substr($message,0,4000),$dedupeKey]);
    }

    public static function classify(string $message):string
    {
        $lower=strtolower($message);
        if(str_contains($lower,'code 190')||str_contains($lower,'access token')||str_contains($lower,'oauth'))return 'token_invalid';
        if(str_contains($lower,'api key')||str_contains($lower,'insufficient_quota')||str_contains($lower,'billing')||str_contains($lower,'balance')||str_contains($lower,'credit'))return 'ai_key_or_balance';
        if(str_contains($lower,'folder')||str_contains($lower,'unused image')||str_contains($lower,'no valid image')||str_contains($lower,'no prepared image')||str_contains($lower,'no image'))return 'folder_empty';
        return 'publish_failed';
    }
}
