<?php
declare(strict_types=1);

namespace App\Services;

use App\Security\Crypto;

final class InstagramService
{
    /** @return array{id:string} */
    public function publish(array $page,array $post):array
    {
        $igId=trim((string)($page['instagram_user_id']??''));
        $instagramToken=Crypto::decrypt((string)($page['encrypted_instagram_access_token']??''));
        $facebookToken=Crypto::decrypt((string)($page['encrypted_access_token']??''));
        $instagramLogin=$instagramToken!=='';
        $token=$instagramLogin?$instagramToken:$facebookToken;
        if($token==='')throw new \RuntimeException('Instagram access token is missing.');
        if(!$instagramLogin&&$facebookToken!=='')$igId=$this->connectedInstagramId($page,$facebookToken,$igId);
        if(!preg_match('/^[0-9]{5,30}$/',$igId))throw new \RuntimeException('Instagram Business Account ID is missing or invalid.');
        $video=in_array((string)($post['post_type']??''),['video','reel'],true);
        if($video)return $this->publishVideo($igId,$token,$instagramLogin,$post);
        $images=json_decode((string)($post['image_urls_json']??''),true);if(!is_array($images)||$images===[])$images=[(string)($post['image_url']??'')];
        $image=(string)($images[0]??'');
        $story=($post['post_type']??'feed')==='story';
        // The signed media endpoint converts supported source formats to JPEG and
        // pads out-of-range images without cropping before Instagram downloads it.
        ContentQualityService::validateImages([$image],false,$story);
        $image=self::signedMediaUrl((int)$post['id'],0);
        $caption=trim((string)($post['instagram_caption']??'').' '.(string)($post['instagram_hashtags']??''));
        $version=rawurlencode((string)\config('meta.graph_version','v23.0'));
        $hosts=$instagramLogin?['graph.instagram.com']:['graph.facebook.com'];
        $fields=['image_url'=>$image,'access_token'=>$token];
        if($story)$fields['media_type']='STORIES';else $fields['caption']=$caption;
        $lastError=null;
        foreach($hosts as $host){
            try{$container=$this->request("https://{$host}/{$version}/{$igId}/media",$fields,$story?'Instagram Story container':'Instagram media container');}
            catch(\Throwable $e){$lastError=$e;continue;}
            return $this->request("https://{$host}/{$version}/{$igId}/media_publish",['creation_id'=>$container['id'],'access_token'=>$token],'Instagram media publish');
        }
        throw $lastError??new \RuntimeException('Instagram media container could not be created.');
    }

    private function publishVideo(string $igId,string $token,bool $instagramLogin,array $post):array
    {
        VideoUploadService::resolve((string)($post['video_path']??''));
        $caption=trim((string)($post['instagram_caption']??$post['caption']??'').' '.(string)($post['instagram_hashtags']??$post['hashtags']??''));
        $version=rawurlencode((string)\config('meta.graph_version','v23.0'));$hosts=$instagramLogin?['graph.instagram.com']:['graph.facebook.com'];$lastError=null;
        foreach($hosts as $host){
            try{
                $container=$this->request("https://{$host}/{$version}/{$igId}/media",['media_type'=>'REELS','video_url'=>self::signedVideoUrl((int)$post['id']),'caption'=>$caption,'share_to_feed'=>'true','access_token'=>$token],'Instagram Reel container');
                $this->waitUntilReady($host,$version,$container['id'],$token);
                return $this->request("https://{$host}/{$version}/{$igId}/media_publish",['creation_id'=>$container['id'],'access_token'=>$token],'Instagram Reel publish');
            }catch(\Throwable $e){$lastError=$e;}
        }
        throw $lastError??new \RuntimeException('Instagram Reel could not be published.');
    }

    private function waitUntilReady(string $host,string $version,string $containerId,string $token):void
    {
        for($attempt=0;$attempt<20;$attempt++){
            if($attempt>0)sleep(3);$url="https://{$host}/{$version}/".rawurlencode($containerId).'?'.http_build_query(['fields'=>'status_code,status','access_token'=>$token]);
            $ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>30]);$raw=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);$data=json_decode((string)$raw,true);$code=strtoupper((string)($data['status_code']??''));
            if($status>=200&&$status<300&&$code==='FINISHED')return;
            if(in_array($code,['ERROR','EXPIRED'],true))throw new \RuntimeException('Instagram Reel processing failed: '.substr((string)($data['status']??$code),0,400));
        }
        throw new \RuntimeException('Instagram Reel is still processing; it will be retried without another AI request.');
    }

    private function connectedInstagramId(array $page,string $token,string $fallback):string
    {
        $pageId=trim((string)($page['facebook_page_id']??''));if(!preg_match('/^[0-9]{5,30}$/',$pageId))return $fallback;
        $version=rawurlencode((string)\config('meta.graph_version','v23.0'));
        $url="https://graph.facebook.com/{$version}/".rawurlencode($pageId).'?'.http_build_query(['fields'=>'instagram_business_account','access_token'=>$token]);
        $ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>30,CURLOPT_CONNECTTIMEOUT=>8]);$raw=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);
        if($raw!==false&&$status>=200&&$status<300){$data=json_decode((string)$raw,true);$resolved=trim((string)($data['instagram_business_account']['id']??''));if(preg_match('/^[0-9]{5,30}$/',$resolved))return $resolved;}
        return $fallback;
    }

    public static function signedMediaUrl(int $postId,int $index):string
    {
        $base=rtrim((string)\config('url',''),'/');$key=(string)\config('key','');
        if($base===''||$key==='')throw new \RuntimeException('APP_URL and APP_KEY are required for Instagram folder-image publishing.');
        $expires=time()+3600;$payload=$postId.':'.$index.':'.$expires;$sig=hash_hmac('sha256',$payload,$key);
        // Keep a .jpg filename at the end of the URL as well as returning the
        // correct Content-Type. Some Meta media fetchers reject an otherwise
        // valid dynamic URL as an unknown media type when it has no filename.
        return $base.'/index.php?view=ig-media&post_id='.$postId.'&index='.$index.'&expires='.$expires.'&sig='.$sig.'&filename=instagram-'.$postId.'-'.$index.'.jpg';
    }

    public static function signedVideoUrl(int $postId):string
    {
        $base=rtrim((string)\config('url',''),'/');$key=(string)\config('key','');if($base===''||$key==='')throw new \RuntimeException('APP_URL and APP_KEY are required for Instagram video publishing.');
        $expires=time()+7200;$sig=hash_hmac('sha256',$postId.':video:'.$expires,$key);
        return $base.'/index.php?view=video-media&post_id='.$postId.'&expires='.$expires.'&sig='.$sig;
    }

    public static function verifyVideoSignature(int $postId,int $expires,string $signature):bool
    {
        if($expires<time()||$expires>time()+10800)return false;$key=(string)\config('key','');if($key==='')return false;
        return hash_equals(hash_hmac('sha256',$postId.':video:'.$expires,$key),$signature);
    }

    public static function verifyMediaSignature(int $postId,int $index,int $expires,string $signature):bool
    {
        if($expires<time()||$expires>time()+7200)return false;$key=(string)\config('key','');if($key==='')return false;
        return hash_equals(hash_hmac('sha256',$postId.':'.$index.':'.$expires,$key),$signature);
    }

    /** @return array{id:string} */
    private function request(string $url,array $fields,string $operation):array
    {
        $ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>90,CURLOPT_HTTPHEADER=>['Content-Type: application/x-www-form-urlencoded'],CURLOPT_POSTFIELDS=>http_build_query($fields)]);
        $raw=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$error=curl_error($ch);curl_close($ch);$data=json_decode((string)$raw,true);$id=(string)($data['id']??'');
        if($raw===false||$status<200||$status>=300||$id===''){$message=(string)($data['error']['message']??$error?:'Unknown Instagram API error');$code=(int)($data['error']['code']??0);throw new \RuntimeException($operation.' failed (HTTP '.$status.', code '.$code.'): '.substr($message,0,500));}
        return ['id'=>$id];
    }
}
