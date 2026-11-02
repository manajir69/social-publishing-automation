<?php
declare(strict_types=1);

namespace App\Services;

use App\Security\Crypto;
use PDO;

final class LinkedInService
{
    /** @return array{access_token:string,expires_in:int,refresh_token:string,refresh_token_expires_in:int,scope:string} */
    public function exchangeAuthorizationCode(string $code,string $redirectUri):array
    {
        $clientId=trim((string)\config('linkedin.client_id',''));$clientSecret=trim((string)\config('linkedin.client_secret',''));
        if($clientId===''||$clientSecret==='')throw new \RuntimeException('LinkedIn app credentials are not configured.');
        $ch=curl_init('https://www.linkedin.com/oauth/v2/accessToken');curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>60,CURLOPT_HTTPHEADER=>['Content-Type: application/x-www-form-urlencoded'],CURLOPT_POSTFIELDS=>http_build_query(['grant_type'=>'authorization_code','code'=>$code,'client_id'=>$clientId,'client_secret'=>$clientSecret,'redirect_uri'=>$redirectUri])]);$raw=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$error=curl_error($ch);curl_close($ch);$data=json_decode((string)$raw,true);
        $access=trim((string)($data['access_token']??''));if($raw===false||$status<200||$status>=300||$access==='')throw new \RuntimeException('LinkedIn authorization failed (HTTP '.$status.'): '.substr((string)($data['error_description']??$data['message']??$error?:'Unknown error'),0,500));
        return ['access_token'=>$access,'expires_in'=>max(60,(int)($data['expires_in']??5184000)),'refresh_token'=>trim((string)($data['refresh_token']??'')),'refresh_token_expires_in'=>max(0,(int)($data['refresh_token_expires_in']??0)),'scope'=>trim((string)($data['scope']??''))];
    }

    public function refreshConnection(PDO $db,array $connection):array
    {
        $refresh=Crypto::decrypt((string)($connection['encrypted_refresh_token']??''));$clientId=(string)\config('linkedin.client_id','');$clientSecret=(string)\config('linkedin.client_secret','');
        if($refresh===''||$clientId===''||$clientSecret==='')throw new \RuntimeException('LinkedIn token expired. Update the access token or configure the issued refresh token and LinkedIn app credentials.');
        $ch=curl_init('https://www.linkedin.com/oauth/v2/accessToken');curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>60,CURLOPT_HTTPHEADER=>['Content-Type: application/x-www-form-urlencoded'],CURLOPT_POSTFIELDS=>http_build_query(['grant_type'=>'refresh_token','refresh_token'=>$refresh,'client_id'=>$clientId,'client_secret'=>$clientSecret])]);$raw=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$error=curl_error($ch);curl_close($ch);$data=json_decode((string)$raw,true);
        $access=(string)($data['access_token']??'');$newRefresh=(string)($data['refresh_token']??$refresh);$expires=max(60,(int)($data['expires_in']??5184000));
        if($raw===false||$status<200||$status>=300||$access==='')throw new \RuntimeException('LinkedIn token refresh failed (HTTP '.$status.'): '.substr((string)($data['error_description']??$data['message']??$error?:'Unknown error'),0,500));
        $expiresAt=date('Y-m-d H:i:s',time()+$expires);$db->prepare('UPDATE social_connections SET encrypted_access_token=?,encrypted_refresh_token=?,token_expires_at=? WHERE id=?')->execute([Crypto::encrypt($access),Crypto::encrypt($newRefresh),$expiresAt,(int)$connection['id']]);
        $connection['encrypted_access_token']=Crypto::encrypt($access);$connection['encrypted_refresh_token']=Crypto::encrypt($newRefresh);$connection['token_expires_at']=$expiresAt;return $connection;
    }

    /** @return array{id:string} */
    public function publish(array $connection,array $post):array
    {
        $token=Crypto::decrypt((string)($connection['encrypted_access_token']??''));
        if($token==='')throw new \RuntimeException('LinkedIn access token is missing.');
        $accountId=trim((string)($connection['account_id']??''));
        if(!preg_match('/^[0-9]+$/',$accountId))throw new \RuntimeException('LinkedIn Organization ID is invalid.');
        $author='urn:li:organization:'.$accountId;
        $commentary=$this->caption($post,$connection);
        $content=null;

        if(in_array((string)($post['post_type']??''),['video','reel'],true)){
            $video=$this->uploadVideo($author,$token,VideoUploadService::resolve((string)($post['video_path']??'')));
            $content=['media'=>['id'=>$video,'title'=>(string)($post['video_title']??'Video')]];
        }else{
            $images=json_decode((string)($post['image_urls_json']??''),true);
            if(!is_array($images)||$images===[])$images=[(string)($post['image_url']??'')];
            $images=array_values(array_slice(array_filter($images,'is_string'),0,9));
            if($images===[])throw new \RuntimeException('LinkedIn post has no image.');
            $uploaded=[];
            foreach($images as $image)$uploaded[]=$this->uploadImage($author,$token,$image);
            $content=count($uploaded)===1
                ?['media'=>['id'=>$uploaded[0],'altText'=>(string)($post['video_title']??'')]]
                :['multiImage'=>['images'=>array_map(static fn(string $id):array=>['id'=>$id],$uploaded)]];
        }

        $payload=['author'=>$author,'commentary'=>$commentary,'visibility'=>'PUBLIC','distribution'=>['feedDistribution'=>'MAIN_FEED','targetEntities'=>[],'thirdPartyDistributionChannels'=>[]],'content'=>$content,'lifecycleState'=>'PUBLISHED','isReshareDisabledByAuthor'=>false];
        [$status,$headers,$body]=$this->request('POST','https://api.linkedin.com/rest/posts',$token,json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),['Content-Type: application/json']);
        if($status<200||$status>=300)throw new \RuntimeException('LinkedIn post publish failed (HTTP '.$status.'): '.$this->error($body));
        $id=$headers['x-restli-id']??'';
        if($id===''){
            $decoded=json_decode($body,true);$id=(string)($decoded['id']??'');
        }
        if($id==='')throw new \RuntimeException('LinkedIn published the request but did not return a post ID.');
        return ['id'=>$id];
    }

    private function uploadImage(string $owner,string $token,string $reference):string
    {
        $payload=json_encode(['initializeUploadRequest'=>['owner'=>$owner]],JSON_THROW_ON_ERROR);
        [$status,,$body]=$this->request('POST','https://api.linkedin.com/rest/images?action=initializeUpload',$token,$payload,['Content-Type: application/json']);
        $data=json_decode($body,true);$url=(string)($data['value']['uploadUrl']??'');$urn=(string)($data['value']['image']??'');
        if($status<200||$status>=300||$url===''||$urn==='')throw new \RuntimeException('LinkedIn image initialization failed (HTTP '.$status.'): '.$this->error($body));
        [$path,$temporary]=$this->mediaPath($reference);
        try{$this->uploadBinary($url,$path,'LinkedIn image upload');}finally{if($temporary&&is_file($path))unlink($path);}
        return $urn;
    }

    private function uploadVideo(string $owner,string $token,string $path):string
    {
        $payload=json_encode(['initializeUploadRequest'=>['owner'=>$owner,'fileSizeBytes'=>filesize($path),'uploadCaptions'=>false,'uploadThumbnail'=>false]],JSON_THROW_ON_ERROR);
        [$status,,$body]=$this->request('POST','https://api.linkedin.com/rest/videos?action=initializeUpload',$token,$payload,['Content-Type: application/json']);
        $data=json_decode($body,true);$urn=(string)($data['value']['video']??'');$instructions=$data['value']['uploadInstructions']??[];$uploadToken=(string)($data['value']['uploadToken']??'');
        if($status<200||$status>=300||$urn===''||!is_array($instructions)||$instructions===[])throw new \RuntimeException('LinkedIn video initialization failed (HTTP '.$status.'): '.$this->error($body));
        $handle=fopen($path,'rb');if($handle===false)throw new \RuntimeException('LinkedIn could not open the video file.');
        $parts=[];try{
            foreach($instructions as $part){
                $first=(int)($part['firstByte']??0);$last=(int)($part['lastByte']??-1);$url=(string)($part['uploadUrl']??'');$length=$last-$first+1;
                if($url===''||$length<1)throw new \RuntimeException('LinkedIn returned invalid video upload instructions.');
                fseek($handle,$first);$bytes=fread($handle,$length);if($bytes===false||strlen($bytes)!==$length)throw new \RuntimeException('LinkedIn could not read a video chunk.');
                [$partStatus,,$partHeaders]=$this->rawPut($url,$bytes,['Content-Type: application/octet-stream']);
                if($partStatus<200||$partStatus>=300)throw new \RuntimeException('LinkedIn video upload failed (HTTP '.$partStatus.').');
                $etag=(string)($partHeaders['etag']??'');if($etag!=='')$parts[]=trim($etag,'"');
            }
        }finally{fclose($handle);}
        if($parts!==[]){
            $final=json_encode(['finalizeUploadRequest'=>['video'=>$urn,'uploadToken'=>$uploadToken,'uploadedPartIds'=>$parts]],JSON_THROW_ON_ERROR);
            [$finalStatus,,$finalBody]=$this->request('POST','https://api.linkedin.com/rest/videos?action=finalizeUpload',$token,$final,['Content-Type: application/json']);
            if($finalStatus<200||$finalStatus>=300)throw new \RuntimeException('LinkedIn video finalize failed (HTTP '.$finalStatus.'): '.$this->error($finalBody));
        }
        for($attempt=0;$attempt<20;$attempt++){
            if($attempt>0)sleep(3);[$videoStatus,,$videoBody]=$this->request('GET','https://api.linkedin.com/rest/videos/'.rawurlencode($urn),$token,null,[]);$videoData=json_decode($videoBody,true);$state=strtoupper((string)($videoData['status']??''));
            if($videoStatus>=200&&$videoStatus<300&&$state==='AVAILABLE')return $urn;
            if(in_array($state,['PROCESSING_FAILED','FAILED'],true))throw new \RuntimeException('LinkedIn video processing failed.');
        }
        throw new \RuntimeException('LinkedIn video is still processing; it will retry without another AI request.');
    }

    private function caption(array $post,array $connection):string
    {
        return trim((string)($post['caption']??'')."\n\n".(string)($post['call_to_action']??'')."\n\n".(string)($post['hashtags']??'')."\n\n".(string)($connection['caption_suffix']??''));
    }

    /** @return array{0:string,1:bool} */
    private function mediaPath(string $reference):array
    {
        if(str_starts_with($reference,'local://'))return [FolderContentService::resolve($reference),false];
        if(!str_starts_with($reference,'https://'))throw new \RuntimeException('LinkedIn image URL is invalid.');
        $path=tempnam(sys_get_temp_dir(),'li-img-');if($path===false)throw new \RuntimeException('Could not create a temporary LinkedIn image file.');
        $ch=curl_init($reference);$out=fopen($path,'wb');if($out===false)throw new \RuntimeException('Could not open a temporary LinkedIn image file.');
        curl_setopt_array($ch,[CURLOPT_FILE=>$out,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_MAXREDIRS=>3,CURLOPT_TIMEOUT=>90,CURLOPT_CONNECTTIMEOUT=>10]);$ok=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$error=curl_error($ch);curl_close($ch);fclose($out);
        if(!$ok||$status<200||$status>=300){unlink($path);throw new \RuntimeException('LinkedIn could not download the image: '.($error?:'HTTP '.$status));}
        return [$path,true];
    }

    private function uploadBinary(string $url,string $path,string $operation):void
    {
        $data=file_get_contents($path);if($data===false)throw new \RuntimeException($operation.' could not read the file.');
        [$status]=$this->rawPut($url,$data,['Content-Type: application/octet-stream']);
        if($status<200||$status>=300)throw new \RuntimeException($operation.' failed (HTTP '.$status.').');
    }

    /** @return array{0:int,1:array<string,string>,2:string} */
    private function request(string $method,string $url,string $token,?string $body,array $extra=[]):array
    {
        $headers=['Authorization: Bearer '.$token,'LinkedIn-Version: '.(string)\config('linkedin.api_version','202608'),'X-Restli-Protocol-Version: 2.0.0',...$extra];$responseHeaders=[];
        $options=[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>180,CURLOPT_HTTPHEADER=>$headers,CURLOPT_HEADERFUNCTION=>static function($ch,string $line)use(&$responseHeaders):int{$parts=explode(':',$line,2);if(count($parts)===2)$responseHeaders[strtolower(trim($parts[0]))]=trim($parts[1]);return strlen($line);}];if($body!==null)$options[CURLOPT_POSTFIELDS]=$body;
        $ch=curl_init($url);curl_setopt_array($ch,$options);
        $raw=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$error=curl_error($ch);curl_close($ch);if($raw===false)throw new \RuntimeException('LinkedIn network error: '.$error);return [$status,$responseHeaders,(string)$raw];
    }

    /** @return array{0:int,1:string,2:array<string,string>} */
    private function rawPut(string $url,string $bytes,array $headers):array
    {
        $responseHeaders=[];$ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_CUSTOMREQUEST=>'PUT',CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>600,CURLOPT_HTTPHEADER=>$headers,CURLOPT_POSTFIELDS=>$bytes,CURLOPT_HEADERFUNCTION=>static function($ch,string $line)use(&$responseHeaders):int{$parts=explode(':',$line,2);if(count($parts)===2)$responseHeaders[strtolower(trim($parts[0]))]=trim($parts[1]);return strlen($line);}]);$raw=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$error=curl_error($ch);curl_close($ch);if($raw===false)throw new \RuntimeException('LinkedIn upload network error: '.$error);return [$status,(string)$raw,$responseHeaders];
    }

    private function error(string $body):string
    {
        $data=json_decode($body,true);return substr((string)($data['message']??$data['error']['message']??$body?:'Unknown LinkedIn API error'),0,500);
    }
}
