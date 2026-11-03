<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Database;
use App\Security\Auth;
use App\Security\Crypto;
use App\Services\TikTokService;

Auth::require();

try{
    $pending=$_SESSION['tiktok_oauth']??null;unset($_SESSION['tiktok_oauth']);
    if(!is_array($pending)||empty($pending['state'])||empty($pending['page_id'])||time()-(int)($pending['created_at']??0)>600)throw new RuntimeException('TikTok connection session expired. Start Connect with TikTok again.');
    $state=(string)($_GET['state']??'');if($state===''||!hash_equals((string)$pending['state'],$state))throw new RuntimeException('TikTok security state did not match. Start the connection again.');
    if(!empty($_GET['error']))throw new RuntimeException('TikTok permission was not granted: '.substr((string)($_GET['error_description']??$_GET['error']),0,400));
    $code=trim((string)($_GET['code']??''));if($code==='')throw new RuntimeException('TikTok did not return an authorization code.');
    $redirectUri=trim((string)config('tiktok.redirect_uri',''));$service=new TikTokService();$token=$service->exchangeAuthorizationCode($code,$redirectUri);
    $granted=array_filter(array_map('trim',explode(',',(string)$token['scope'])));foreach(['user.info.basic','video.publish'] as $required){if(!in_array($required,$granted,true))throw new RuntimeException('TikTok did not grant the required '.$required.' permission. Reconnect and approve every requested permission.');}
    $profile=$service->userInfo((string)$token['access_token']);$openId=trim((string)($profile['open_id']?:$token['open_id']));$displayName=trim((string)$profile['display_name'])?:'TikTok account';$pageId=(int)$pending['page_id'];$db=Database::connection();
    $page=$db->prepare('SELECT id FROM social_pages WHERE id=?');$page->execute([$pageId]);if(!$page->fetchColumn())throw new RuntimeException('The selected Brand Page no longer exists.');
    $duplicate=$db->prepare('SELECT id,page_id FROM social_connections WHERE platform="tiktok" AND (page_id=? OR account_id=?) LIMIT 1');$duplicate->execute([$pageId,$openId]);$existing=$duplicate->fetch();if($existing)throw new RuntimeException((int)$existing['page_id']===$pageId?'This Brand Page already has a TikTok connection.':'This TikTok account is already connected to another Brand Page.');
    $expiresAt=date('Y-m-d H:i:s',time()+(int)$token['expires_in']);$insert=$db->prepare('INSERT INTO social_connections(page_id,platform,display_name,account_id,encrypted_access_token,encrypted_refresh_token,token_expires_at,auto_publish_feed,auto_publish_video,default_privacy,allow_comments,allow_duet,allow_stitch,caption_suffix,status) VALUES(?,"tiktok",?,?,?,?,?,1,1,"SELF_ONLY",1,1,1,"","active")');$insert->execute([$pageId,$displayName,$openId,Crypto::encrypt((string)$token['access_token']),Crypto::encrypt((string)$token['refresh_token']),$expiresAt]);$connectionId=(int)$db->lastInsertId();
    audit($db,'connection.created','social_connection',$connectionId);flash('success','TikTok connected successfully. Testing privacy is set to Only me; edit the connection after your first successful test.');redirect('index.php?view=connections&platform=tiktok');
}catch(Throwable $e){flash('error',$e->getMessage());redirect('index.php?view=connections&platform=tiktok');}
