<?php
declare(strict_types=1);

namespace App\Services;

final class VideoUploadService
{
    private const ROOT = BASE_PATH . '/storage/video-uploads';

    /** @param array<string,mixed> $upload */
    public static function save(int $pageId,array $upload):string
    {
        $error=(int)($upload['error']??UPLOAD_ERR_NO_FILE);
        if($error!==UPLOAD_ERR_OK)throw new \RuntimeException($error===UPLOAD_ERR_INI_SIZE?'Video exceeds the server upload limit. Increase upload_max_filesize and post_max_size.':'Video upload failed (code '.$error.').');
        $tmp=(string)($upload['tmp_name']??'');$size=(int)($upload['size']??0);
        if($tmp===''||!is_file($tmp)||$size<1||$size>200*1024*1024)throw new \RuntimeException('Video must be between 1 byte and 200 MB.');
        $mime=(new \finfo(FILEINFO_MIME_TYPE))->file($tmp)?:'';
        $extensions=['video/mp4'=>'mp4','video/quicktime'=>'mov'];
        if(!isset($extensions[$mime]))throw new \RuntimeException('Upload an MP4 or MOV video. MP4 (H.264 video + AAC audio) is recommended.');
        $dir=self::ROOT.'/page-'.$pageId;if(!is_dir($dir)&&!mkdir($dir,0775,true)&&!is_dir($dir))throw new \RuntimeException('Could not create the video upload directory.');
        $relative='page-'.$pageId.'/'.date('Ymd-His').'-'.bin2hex(random_bytes(6)).'.'.$extensions[$mime];$destination=self::ROOT.'/'.$relative;
        if(!move_uploaded_file($tmp,$destination)&&!rename($tmp,$destination))throw new \RuntimeException('Could not save the uploaded video.');
        return $relative;
    }

    public static function resolve(string $relative):string
    {
        $relative=trim(str_replace('\\','/',$relative),'/');
        if($relative===''||str_contains($relative,'..')||str_contains($relative,"\0"))throw new \RuntimeException('Invalid video path.');
        $root=realpath(self::ROOT);$path=realpath(self::ROOT.'/'.$relative);
        if($root===false||$path===false||!is_file($path)||!str_starts_with($path,$root.DIRECTORY_SEPARATOR))throw new \RuntimeException('Uploaded video was not found.');
        return $path;
    }

    /** @param array<string,mixed> $upload */
    public static function saveChunk(int $pageId,string $uploadId,int $index,int $total,array $upload):array
    {
        if(!preg_match('/^[a-f0-9]{32}$/',$uploadId)||$index<0||$total<1||$total>100||$index>=$total)throw new \RuntimeException('Invalid video upload session.');
        $error=(int)($upload['error']??UPLOAD_ERR_NO_FILE);$tmp=(string)($upload['tmp_name']??'');$size=(int)($upload['size']??0);
        if($error!==UPLOAD_ERR_OK||$tmp===''||!is_uploaded_file($tmp)||$size<1||$size>6*1024*1024)throw new \RuntimeException('A video part could not be uploaded.');
        $stage=self::ROOT.'/.staging';if(!is_dir($stage)&&!mkdir($stage,0775,true)&&!is_dir($stage))throw new \RuntimeException('Could not create video staging storage.');
        $part=$stage.'/'.$uploadId.'.part';$metaPath=$stage.'/'.$uploadId.'.json';
        if($index===0){file_put_contents($part,'',LOCK_EX);$meta=['page_id'=>$pageId,'total'=>$total,'next'=>0,'created'=>time()];file_put_contents($metaPath,json_encode($meta),LOCK_EX);}
        $meta=json_decode((string)@file_get_contents($metaPath),true);
        if(!is_array($meta)||(int)($meta['page_id']??0)!==$pageId||(int)($meta['total']??0)!==$total||(int)($meta['next']??-1)!==$index)throw new \RuntimeException('Video parts arrived out of order. Select the file again.');
        $input=fopen($tmp,'rb');$output=fopen($part,'ab');if(!$input||!$output){if(is_resource($input))fclose($input);if(is_resource($output))fclose($output);throw new \RuntimeException('Could not write the video part.');}
        stream_copy_to_stream($input,$output);fclose($input);fclose($output);
        if((int)filesize($part)>200*1024*1024){@unlink($part);@unlink($metaPath);throw new \RuntimeException('Video exceeds the 200 MB application limit.');}
        $meta['next']=$index+1;file_put_contents($metaPath,json_encode($meta),LOCK_EX);
        if($index+1<$total)return ['complete'=>false,'path'=>''];
        $mime=(new \finfo(FILEINFO_MIME_TYPE))->file($part)?:'';$extensions=['video/mp4'=>'mp4','video/quicktime'=>'mov'];
        if(!isset($extensions[$mime])){@unlink($part);@unlink($metaPath);throw new \RuntimeException('Upload an MP4 or MOV video.');}
        $dir=self::ROOT.'/page-'.$pageId;if(!is_dir($dir)&&!mkdir($dir,0775,true)&&!is_dir($dir))throw new \RuntimeException('Could not create the video upload directory.');
        $relative='page-'.$pageId.'/'.date('Ymd-His').'-'.bin2hex(random_bytes(6)).'.'.$extensions[$mime];
        if(!rename($part,self::ROOT.'/'.$relative)){@unlink($part);throw new \RuntimeException('Could not finalize the uploaded video.');}
        @unlink($metaPath);return ['complete'=>true,'path'=>$relative];
    }
}
