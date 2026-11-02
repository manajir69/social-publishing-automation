<?php
declare(strict_types=1);

namespace App\Services;

use App\Security\Crypto;

final class FacebookService
{
    /** @return array{id:string} */
    public function publish(array $page, array $post): array
    {
        $pageId = rawurlencode((string)$page['facebook_page_id']);
        $version = rawurlencode((string)\config('meta.graph_version', 'v23.0'));
        $token = Crypto::decrypt((string)$page['encrypted_access_token']);
        $message = trim($post['caption'] . "\n\n" . $post['call_to_action'] . "\n\n" . $post['hashtags']);
        $images = json_decode((string)($post['image_urls_json'] ?? ''), true);
        if (!is_array($images) || $images === []) $images = [(string)$post['image_url']];
        $images = array_values(array_slice(array_filter($images, fn($ref)=>is_string($ref)&&(str_starts_with($ref,'https://')||str_starts_with($ref,'local://'))), 0, 10));
        if ($images === []) throw new \RuntimeException('Facebook post has no valid image.');

        if (count($images) === 1) {
            return $this->request("https://graph.facebook.com/{$version}/{$pageId}/photos", $this->photoFields($images[0], $token, ['caption'=>$message]), 'Facebook photo publish', ['post_id','id']);
        }

        $attached = [];
        foreach ($images as $index => $image) {
            $uploaded = $this->request("https://graph.facebook.com/{$version}/{$pageId}/photos", $this->photoFields($image, $token, ['published'=>'false']), 'Facebook unpublished photo upload');
            $attached['attached_media[' . $index . ']'] = json_encode(['media_fbid'=>$uploaded['id']], JSON_THROW_ON_ERROR);
        }
        return $this->request("https://graph.facebook.com/{$version}/{$pageId}/feed", [
            'message'=>$message, 'access_token'=>$token, ...$attached,
        ], 'Facebook multi-photo publish');
    }

    /** Backward-compatible single entry point. */
    public function publishPhoto(array $page, array $post): array
    {
        return $this->publish($page, $post);
    }

    /** Publish one image as a Facebook Page Story. */
    public function publishStory(array $page, array $post): array
    {
        $pageId = rawurlencode((string)$page['facebook_page_id']);
        $version = rawurlencode((string)\config('meta.graph_version', 'v23.0'));
        $token = Crypto::decrypt((string)$page['encrypted_access_token']);
        $images = json_decode((string)($post['image_urls_json'] ?? ''), true);
        if (!is_array($images) || $images === []) $images = [(string)($post['image_url'] ?? '')];
        $image = (string)($images[0] ?? '');
        if (!str_starts_with($image, 'https://') && !str_starts_with($image, 'local://')) {
            throw new \RuntimeException('Facebook Story has no valid image.');
        }

        $uploaded = $this->request(
            "https://graph.facebook.com/{$version}/{$pageId}/photos",
            $this->photoFields($image, $token, ['published'=>'false']),
            'Facebook Story photo upload'
        );
        return $this->request(
            "https://graph.facebook.com/{$version}/{$pageId}/photo_stories",
            ['photo_id'=>$uploaded['id'],'access_token'=>$token],
            'Facebook Story publish',
            ['post_id','id']
        );
    }

    /** Publish a manually uploaded Page video or Reel. */
    public function publishVideo(array $page,array $post):array
    {
        $pageId=rawurlencode((string)$page['facebook_page_id']);$version=rawurlencode((string)\config('meta.graph_version','v23.0'));
        $token=Crypto::decrypt((string)$page['encrypted_access_token']);$path=VideoUploadService::resolve((string)($post['video_path']??''));
        $description=trim((string)($post['caption']??'')."\n\n".(string)($post['call_to_action']??'')."\n\n".(string)($post['hashtags']??''));
        if(($post['post_type']??'video')!=='reel'){
            $mime=(new \finfo(FILEINFO_MIME_TYPE))->file($path)?:'video/mp4';
            return $this->request("https://graph.facebook.com/{$version}/{$pageId}/videos",['source'=>new \CURLFile($path,$mime,basename($path)),'title'=>(string)($post['video_title']??''),'description'=>$description,'access_token'=>$token],'Facebook video publish');
        }
        $reelDescription=trim((string)($post['video_title']??'')."\n\n".$description);
        $start=$this->rawRequest("https://graph.facebook.com/{$version}/{$pageId}/video_reels",['upload_phase'=>'start','access_token'=>$token],'Facebook Reel upload start');
        $videoId=(string)($start['video_id']??'');$uploadUrl=(string)($start['upload_url']??'');
        if($videoId===''||!str_starts_with($uploadUrl,'https://'))throw new \RuntimeException('Facebook Reel upload start did not return an upload URL.');
        $handle=fopen($path,'rb');if($handle===false)throw new \RuntimeException('Could not open the Reel video.');
        $ch=curl_init($uploadUrl);curl_setopt_array($ch,[CURLOPT_CUSTOMREQUEST=>'POST',CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>600,CURLOPT_HTTPHEADER=>['Authorization: OAuth '.$token,'offset: 0','file_size: '.filesize($path),'Content-Type: application/octet-stream'],CURLOPT_INFILE=>$handle,CURLOPT_INFILESIZE=>filesize($path),CURLOPT_UPLOAD=>true]);
        $raw=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$error=curl_error($ch);curl_close($ch);fclose($handle);
        if($raw===false||$status<200||$status>=300){$data=json_decode((string)$raw,true);throw new \RuntimeException('Facebook Reel binary upload failed (HTTP '.$status.'): '.substr((string)($data['error']['message']??$error?:'Unknown error'),0,500));}
        $this->rawRequest("https://graph.facebook.com/{$version}/{$pageId}/video_reels",['upload_phase'=>'finish','video_id'=>$videoId,'video_state'=>'PUBLISHED','description'=>$reelDescription,'access_token'=>$token],'Facebook Reel publish');
        return ['id'=>$videoId];
    }

    private function rawRequest(string $url,array $fields,string $operation):array
    {
        $ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>120,CURLOPT_HTTPHEADER=>['Content-Type: application/x-www-form-urlencoded'],CURLOPT_POSTFIELDS=>http_build_query($fields)]);
        $raw=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$error=curl_error($ch);curl_close($ch);$data=json_decode((string)$raw,true);
        if($raw===false||$status<200||$status>=300||!is_array($data)||isset($data['error']))throw new \RuntimeException($operation.' failed (HTTP '.$status.'): '.substr((string)($data['error']['message']??$error?:'Unknown Facebook API error'),0,500));
        return $data;
    }

    /** @return array{id:string} */
    private function request(string $url, array $fields, string $operation, array $idKeys=['id']): array
    {
        $multipart=false;foreach($fields as $value){if($value instanceof \CURLFile){$multipart=true;break;}}
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST=>true, CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>90,
            CURLOPT_HTTPHEADER=>$multipart?[]:['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_POSTFIELDS=>$multipart?$fields:http_build_query($fields),
        ]);
        $raw=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$error=curl_error($ch);curl_close($ch);
        $data=json_decode((string)$raw,true);
        $resultId='';
        if(is_array($data))foreach($idKeys as $key)if(!empty($data[$key])){$resultId=(string)$data[$key];break;}
        if($raw===false||$status<200||$status>=300||$resultId===''){
            $safe=$data['error']['message']??$error?:'Unknown Facebook API error';
            $code=(int)($data['error']['code']??0);
            throw new \RuntimeException($operation.' failed (HTTP '.$status.', code '.$code.'): '.substr((string)$safe,0,500));
        }
        return ['id'=>$resultId];
    }

    private function photoFields(string $reference, string $token, array $extra=[]): array
    {
        if(str_starts_with($reference,'local://')){
            $path=FolderContentService::resolve($reference);$info=getimagesize($path);$mime=(string)($info['mime']??'application/octet-stream');
            return [...$extra,'source'=>new \CURLFile($path,$mime,basename($path)),'access_token'=>$token];
        }
        return [...$extra,'url'=>$reference,'access_token'=>$token];
    }
}
