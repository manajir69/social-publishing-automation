<?php
declare(strict_types=1);

namespace App\Services;

use PDO;

final class ContentQualityService
{
    /**
     * Reject a recently used title for the same post type before AI is called.
     *
     * Caption duplication still has to be checked by prepare() after the
     * caption exists, but an exact content-title repeat must never spend AI
     * tokens merely to discover that the resulting post cannot be saved.
     */
    public static function assertTitleAvailable(PDO $db,array $page,array $item,?int $ignorePostId=null,string $postType='feed'):void
    {
        if(!self::isTitleAvailable($db,$page,$item,$ignorePostId,$postType)){
            throw new \RuntimeException('Content quality blocked an exact title used on this Page within the last 15 days. AI was not called.');
        }
    }

    /** Check title eligibility without aborting a content-rotation loop. */
    public static function isTitleAvailable(PDO $db,array $page,array $item,?int $ignorePostId=null,string $postType='feed'):bool
    {
        $postType=$postType==='story'?'story':'feed';
        $title=trim((string)($item['title']??''));
        $titleFingerprint=self::fingerprint($title);
        $pageId=(int)($page['page_id']??$page['id']??0);
        $params=[$pageId,$postType,$titleFingerprint,$title];
        $ignore='';
        if($ignorePostId){$ignore=' AND s.id<>?';$params[]=$ignorePostId;}
        $query=$db->prepare('SELECT s.id FROM social_posts s LEFT JOIN content_items c ON c.id=s.content_item_id WHERE s.page_id=? AND s.post_type=? AND s.archived_at IS NULL AND s.created_at>=DATE_SUB(NOW(),INTERVAL 15 DAY) AND (s.title_fingerprint=? OR c.title=?)'.$ignore.' LIMIT 1');
        $query->execute($params);
        return !$query->fetch();
    }

    /** @return array{caption:string,hashtags:string,call_to_action:string,title_fingerprint:string,caption_fingerprint:string} */
    public static function prepare(PDO $db,array $page,array $item,array $copy,?int $ignorePostId=null,string $postType='feed',bool $checkTitle=true,bool $checkCaption=true):array
    {
        $postType=$postType==='story'?'story':'feed';
        $caption=self::shorten(trim((string)($copy['caption']??'')),1800);
        $cta=self::shorten(trim((string)($copy['call_to_action']??'')),240);
        $hashtags=self::hashtags((string)($copy['hashtags']??''),5);
        self::assertNoBannedWords($page,trim($caption.' '.$cta.' '.$hashtags));
        $title=(string)($item['title']??'');
        $titleFingerprint=self::fingerprint($title);
        $captionFingerprint=self::fingerprint($caption);
        if($checkTitle)self::assertTitleAvailable($db,$page,$item,$ignorePostId,$postType);
        if($checkCaption){
            $pageId=(int)($page['page_id']??$page['id']??0);$params=[$pageId,$postType,$captionFingerprint,$caption];
            $ignore='';
            if($ignorePostId){$ignore=' AND s.id<>?';$params[]=$ignorePostId;}
            // Feed and Story rotation are independent. Using a project for one
            // type must not consume the other type's 15-day slot.
            $query=$db->prepare('SELECT s.id FROM social_posts s WHERE s.page_id=? AND s.post_type=? AND s.archived_at IS NULL AND s.created_at>=DATE_SUB(NOW(),INTERVAL 15 DAY) AND (s.caption_fingerprint=? OR s.caption=?)'.$ignore.' LIMIT 1');
            $query->execute($params);
            if($query->fetch())throw new \RuntimeException('Content quality blocked an exact caption used on this Page within the last 15 days.');
        }
        return ['caption'=>$caption,'hashtags'=>$hashtags,'call_to_action'=>$cta,'title_fingerprint'=>$titleFingerprint,'caption_fingerprint'=>$captionFingerprint];
    }

    public static function instagramCopy(array $copy,int $max=500):array
    {
        $caption=trim((string)($copy['caption']??''));
        $cta=trim((string)($copy['call_to_action']??''));
        if($cta!=='')$caption=trim($caption."\n\n".$cta);
        return ['caption'=>self::shorten($caption,max(120,min(1000,$max))),'hashtags'=>self::hashtags((string)($copy['hashtags']??''),5)];
    }

    /** @return string[] Valid images, preserving their original order. */
    public static function filterValidImages(array $images,bool $instagram=false,bool $story=false):array
    {
        $valid=[];$firstError=null;
        foreach($images as $image){
            try{self::validateImages([$image],$instagram,$story);$valid[]=$image;}
            catch(\Throwable $e){$firstError??=$e;}
        }
        if($valid===[])throw $firstError??new \RuntimeException('No valid image is available.');
        return $valid;
    }

    public static function validateImages(array $images,bool $instagram=false,bool $story=false):void
    {
        foreach($images as $reference){
            if(!is_string($reference))throw new \RuntimeException('Invalid image reference.');
            $data=self::imageData($reference);
            if($data===false||$data==='')throw new \RuntimeException('Image could not be read for quality validation.');
            $size=strlen($data);$max=$instagram?8*1024*1024:10*1024*1024;
            if($size>$max)throw new \RuntimeException('Image file is too large. Maximum '.($instagram?'8 MB for Instagram':'10 MB').'.');
            $info=@getimagesizefromstring($data);if($info===false)throw new \RuntimeException('Image dimension validation failed.');
            [$width,$height]=$info;$mime=(string)($info['mime']??'');
            if($width<320||$height<320)throw new \RuntimeException('Image is too small. Minimum dimensions are 320 × 320 pixels.');
            if($width>8192||$height>8192)throw new \RuntimeException('Image dimensions are too large. Maximum is 8192 × 8192 pixels.');
            if($instagram){
                if($mime!=='image/jpeg')throw new \RuntimeException('Instagram cross-posting requires a JPEG image.');
                $ratio=$width/$height;$minimumRatio=$story?0.5:0.8;
                if($ratio<$minimumRatio||$ratio>1.91)throw new \RuntimeException($story?'Instagram Story image aspect ratio must be between 1:2 and 1.91:1.':'Instagram feed image aspect ratio must be between 4:5 and 1.91:1.');
            }
        }
    }

    public static function imageData(string $reference):string
    {
        if(str_starts_with($reference,'local://')){
            $path=FolderContentService::resolve($reference);
            $data=file_get_contents($path);
            if($data===false||$data==='')throw new \RuntimeException('Image could not be read.');
            return $data;
        }
        return self::downloadPublicImage($reference);
    }

    private static function downloadPublicImage(string $url):string
    {
        if(!filter_var($url,FILTER_VALIDATE_URL)||!str_starts_with(strtolower($url),'https://'))throw new \RuntimeException('Image URL must use public HTTPS.');
        $host=(string)parse_url($url,PHP_URL_HOST);$ip=gethostbyname($host);
        if($ip===$host||filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE)===false)throw new \RuntimeException('Image host must resolve to a public address.');
        $data='';$tooLarge=false;$ch=curl_init($url);
        curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>false,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_TIMEOUT=>25,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_WRITEFUNCTION=>static function($ch,string $chunk)use(&$data,&$tooLarge):int{if(strlen($data)+strlen($chunk)>10*1024*1024){$tooLarge=true;return 0;}$data.=$chunk;return strlen($chunk);}]);
        $ok=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$error=curl_error($ch);curl_close($ch);
        if($tooLarge)throw new \RuntimeException('Image file is larger than 10 MB.');
        if($ok===false||$status<200||$status>=300)throw new \RuntimeException('Image validation download failed (HTTP '.$status.'): '.$error);
        return $data;
    }

    private static function assertNoBannedWords(array $page,string $text):void
    {
        $configured=(string)($page['banned_words']??'');$env=(string)\env('BANNED_WORDS','');
        $words=preg_split('/[,\r\n]+/u',$configured.','.$env,-1,PREG_SPLIT_NO_EMPTY)?:[];
        foreach($words as $word){$word=trim($word);if($word!==''&&stripos($text,$word)!==false)throw new \RuntimeException('Content quality blocked a banned or sensitive word: '.$word);}
    }

    private static function hashtags(string $text,int $limit):string
    {
        preg_match_all('/#[\p{L}\p{N}_]+/u',$text,$matches);$unique=[];
        foreach($matches[0]??[] as $tag){$key=function_exists('mb_strtolower')?mb_strtolower($tag,'UTF-8'):strtolower($tag);if(!isset($unique[$key]))$unique[$key]=$tag;if(count($unique)>=$limit)break;}
        return implode(' ',array_values($unique));
    }

    private static function shorten(string $text,int $limit):string
    {
        $length=function_exists('mb_strlen')?mb_strlen($text,'UTF-8'):strlen($text);if($length<=$limit)return trim($text);
        $cut=function_exists('mb_substr')?mb_substr($text,0,$limit-1,'UTF-8'):substr($text,0,$limit-1);
        $cut=(string)preg_replace('/\s+\S*$/u','',$cut);return rtrim($cut," \t\n\r\0\x0B,;:-").'…';
    }

    private static function fingerprint(string $text):string
    {
        $text=trim((string)preg_replace('/\s+/u',' ',strip_tags($text)));$text=function_exists('mb_strtolower')?mb_strtolower($text,'UTF-8'):strtolower($text);return hash('sha256',$text);
    }
}
