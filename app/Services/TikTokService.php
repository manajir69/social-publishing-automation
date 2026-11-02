<?php
declare(strict_types=1);

namespace App\Services;

use App\Security\Crypto;
use PDO;

final class TikTokService
{
    /** @return array{access_token:string,refresh_token:string,open_id:string,expires_in:int,scope:string} */
    public function exchangeAuthorizationCode(string $code,string $redirectUri):array
    {
        $clientKey=trim((string)\config('tiktok.client_key',''));$clientSecret=trim((string)\config('tiktok.client_secret',''));
        if($clientKey===''||$clientSecret==='')throw new \RuntimeException('TikTok app credentials are missing. Add TIKTOK_CLIENT_KEY and TIKTOK_CLIENT_SECRET to the live .env file.');
        $data=$this->formRequest('https://open.tiktokapis.com/v2/oauth/token/',['client_key'=>$clientKey,'client_secret'=>$clientSecret,'code'=>$code,'grant_type'=>'authorization_code','redirect_uri'=>$redirectUri],'TikTok authorization');
        $access=trim((string)($data['access_token']??''));$refresh=trim((string)($data['refresh_token']??''));$openId=trim((string)($data['open_id']??''));
        if($access===''||$refresh===''||$openId==='')throw new \RuntimeException('TikTok authorization did not return a complete access token, refresh token and Open ID.');
        return ['access_token'=>$access,'refresh_token'=>$refresh,'open_id'=>$openId,'expires_in'=>max(60,(int)($data['expires_in']??86400)),'scope'=>(string)($data['scope']??'')];
    }

    /** @return array{open_id:string,display_name:string} */
    public function userInfo(string $accessToken):array
    {
        $url='https://open.tiktokapis.com/v2/user/info/?fields=open_id,display_name';$ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>60,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$accessToken]]);$raw=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$error=curl_error($ch);curl_close($ch);$data=json_decode((string)$raw,true);$apiError=(string)($data['error']['code']??'ok');
        if($raw===false||$status<200||$status>=300||!is_array($data)||$apiError!=='ok')throw new \RuntimeException('TikTok profile lookup failed (HTTP '.$status.'): '.substr((string)($data['error']['message']??$apiError?:$error),0,500));
        $user=is_array($data['data']['user']??null)?$data['data']['user']:[];return ['open_id'=>(string)($user['open_id']??''),'display_name'=>trim((string)($user['display_name']??''))];
    }

    public function refreshConnection(PDO $db,array $connection):array
    {
        $refresh=Crypto::decrypt((string)($connection['encrypted_refresh_token']??''));$clientKey=(string)\config('tiktok.client_key','');$clientSecret=(string)\config('tiktok.client_secret','');
        if($refresh===''||$clientKey===''||$clientSecret==='')throw new \RuntimeException('TikTok token expired. Add TIKTOK_CLIENT_KEY, TIKTOK_CLIENT_SECRET and a refresh token.');
        $ch=curl_init('https://open.tiktokapis.com/v2/oauth/token/');curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>60,CURLOPT_HTTPHEADER=>['Content-Type: application/x-www-form-urlencoded'],CURLOPT_POSTFIELDS=>http_build_query(['client_key'=>$clientKey,'client_secret'=>$clientSecret,'grant_type'=>'refresh_token','refresh_token'=>$refresh])]);$raw=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$error=curl_error($ch);curl_close($ch);$data=json_decode((string)$raw,true);
        $access=(string)($data['access_token']??'');$newRefresh=(string)($data['refresh_token']??$refresh);$expires=max(60,(int)($data['expires_in']??86400));
        if($raw===false||$status<200||$status>=300||$access==='')throw new \RuntimeException('TikTok token refresh failed (HTTP '.$status.'): '.substr((string)($data['error_description']??$data['message']??$error?:'Unknown error'),0,500));
        $expiresAt=date('Y-m-d H:i:s',time()+$expires);$db->prepare('UPDATE social_connections SET encrypted_access_token=?,encrypted_refresh_token=?,token_expires_at=? WHERE id=?')->execute([Crypto::encrypt($access),Crypto::encrypt($newRefresh),$expiresAt,(int)$connection['id']]);
        $connection['encrypted_access_token']=Crypto::encrypt($access);$connection['encrypted_refresh_token']=Crypto::encrypt($newRefresh);$connection['token_expires_at']=$expiresAt;return $connection;
    }

    /** @return array{id:string} */
    public function publish(array $connection,array $post):array
    {
        $token=Crypto::decrypt((string)($connection['encrypted_access_token']??''));
        if($token==='')throw new \RuntimeException('TikTok access token is missing.');
        $creator=$this->jsonRequest('POST','https://open.tiktokapis.com/v2/post/publish/creator_info/query/',$token,'{}','TikTok creator settings');
        $privacyOptions=$creator['data']['privacy_level_options']??[];$privacy=(string)($connection['default_privacy']??'SELF_ONLY');
        if(!is_array($privacyOptions)||!in_array($privacy,$privacyOptions,true))$privacy=(string)($privacyOptions[0]??'SELF_ONLY');
        $title=$this->caption($post,$connection);
        if(in_array((string)($post['post_type']??''),['video','reel'],true))return $this->publishVideo($token,$privacy,$title,$post,$connection);
        if(($post['post_type']??'feed')==='story')throw new \RuntimeException('TikTok does not receive Facebook/Instagram Story jobs.');
        return $this->publishPhotos($token,$privacy,$title,$post,$connection);
    }

    /** @return array{id:string} */
    private function publishVideo(string $token,string $privacy,string $title,array $post,array $connection):array
    {
        $path=VideoUploadService::resolve((string)($post['video_path']??''));$size=(int)filesize($path);
        $chunkSize=$size<=64*1024*1024?$size:50*1024*1024;$chunkCount=max(1,(int)floor($size/$chunkSize));
        $payload=['post_info'=>['title'=>$title,'privacy_level'=>$privacy,'disable_duet'=>empty($connection['allow_duet']),'disable_comment'=>empty($connection['allow_comments']),'disable_stitch'=>empty($connection['allow_stitch']),'video_cover_timestamp_ms'=>1000],'source_info'=>['source'=>'FILE_UPLOAD','video_size'=>$size,'chunk_size'=>$chunkSize,'total_chunk_count'=>$chunkCount]];
        $data=$this->jsonRequest('POST','https://open.tiktokapis.com/v2/post/publish/video/init/',$token,json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),'TikTok video initialization');
        $uploadUrl=(string)($data['data']['upload_url']??'');$publishId=(string)($data['data']['publish_id']??'');
        if($uploadUrl===''||$publishId==='')throw new \RuntimeException('TikTok did not return a video upload URL.');
        $handle=fopen($path,'rb');if($handle===false)throw new \RuntimeException('TikTok could not read the video file.');
        try{
            $offset=0;
            for($part=0;$part<$chunkCount;$part++){
                $length=$part===$chunkCount-1?$size-$offset:$chunkSize;$bytes=fread($handle,$length);
                if($bytes===false||strlen($bytes)!==$length)throw new \RuntimeException('TikTok could not read video chunk '.($part+1).'.');
                $last=$offset+$length-1;$ch=curl_init($uploadUrl);curl_setopt_array($ch,[CURLOPT_CUSTOMREQUEST=>'PUT',CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>900,CURLOPT_HTTPHEADER=>['Content-Type: video/mp4','Content-Length: '.$length,'Content-Range: bytes '.$offset.'-'.$last.'/'.$size],CURLOPT_POSTFIELDS=>$bytes]);$raw=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$error=curl_error($ch);curl_close($ch);
                if($raw===false||!in_array($status,[200,201,206],true))throw new \RuntimeException('TikTok video upload failed at chunk '.($part+1).' (HTTP '.$status.'): '.substr($error?:$raw,0,500));
                $offset=$last+1;
            }
        }finally{fclose($handle);}
        return ['id'=>$publishId];
    }

    /** @return array{id:string} */
    private function publishPhotos(string $token,string $privacy,string $title,array $post,array $connection):array
    {
        $images=json_decode((string)($post['image_urls_json']??''),true);if(!is_array($images)||$images===[])$images=[(string)($post['image_url']??'')];
        $images=array_values(array_slice(array_filter($images,'is_string'),0,35));if($images===[])throw new \RuntimeException('TikTok photo post has no images.');
        $urls=[];foreach(array_keys($images) as $index)$urls[]=InstagramService::signedMediaUrl((int)$post['id'],(int)$index);
        $payload=['post_info'=>['title'=>$title,'description'=>'','privacy_level'=>$privacy,'disable_comment'=>empty($connection['allow_comments']),'auto_add_music'=>true],'source_info'=>['source'=>'PULL_FROM_URL','photo_cover_index'=>0,'photo_images'=>$urls],'post_mode'=>'DIRECT_POST','media_type'=>'PHOTO'];
        $data=$this->jsonRequest('POST','https://open.tiktokapis.com/v2/post/publish/content/init/',$token,json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),'TikTok photo publish');
        $id=(string)($data['data']['publish_id']??'');if($id==='')throw new \RuntimeException('TikTok did not return a photo publish ID.');return ['id'=>$id];
    }

    private function caption(array $post,array $connection):string
    {
        $text=trim((string)($post['caption']??'')."\n\n".(string)($post['call_to_action']??'')."\n\n".(string)($post['hashtags']??'')."\n\n".(string)($connection['caption_suffix']??''));
        return function_exists('mb_substr')?mb_substr($text,0,2200,'UTF-8'):substr($text,0,2200);
    }

    private function jsonRequest(string $method,string $url,string $token,string $body,string $operation):array
    {
        $ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>120,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$token,'Content-Type: application/json; charset=UTF-8'],CURLOPT_POSTFIELDS=>$body]);$raw=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$error=curl_error($ch);curl_close($ch);$data=json_decode((string)$raw,true);$apiError=(string)($data['error']['code']??'ok');
        if($raw===false||$status<200||$status>=300||!is_array($data)||$apiError!=='ok')throw new \RuntimeException($operation.' failed (HTTP '.$status.'): '.substr((string)($data['error']['message']??$apiError?:$error),0,500));
        return $data;
    }

    private function formRequest(string $url,array $fields,string $operation):array
    {
        $ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>60,CURLOPT_HTTPHEADER=>['Content-Type: application/x-www-form-urlencoded','Cache-Control: no-cache'],CURLOPT_POSTFIELDS=>http_build_query($fields,'','&',PHP_QUERY_RFC3986)]);$raw=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$error=curl_error($ch);curl_close($ch);$data=json_decode((string)$raw,true);
        if($raw===false||$status<200||$status>=300||!is_array($data)||isset($data['error']))throw new \RuntimeException($operation.' failed (HTTP '.$status.'): '.substr((string)($data['error_description']??$data['message']??$data['error']??$error?:'Unknown error'),0,500));
        return $data;
    }
}
