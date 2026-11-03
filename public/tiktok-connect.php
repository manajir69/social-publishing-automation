<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Database;
use App\Security\Auth;
use App\Security\Csrf;
use App\Services\DatabaseUpgradeService;

Auth::require();

if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST'){http_response_code(405);exit('Method not allowed.');}
Csrf::verify();

try{
    $db=Database::connection();
    if(array_filter((new DatabaseUpgradeService($db))->pending(),static fn(string $change):bool=>str_starts_with($change,'table.social_connections')||str_starts_with($change,'social_connections.'))!==[])throw new RuntimeException('Run Database Upgrade before connecting TikTok.');
    $pageId=max(0,(int)($_POST['page_id']??0));$page=$db->prepare('SELECT id,page_name FROM social_pages WHERE id=?');$page->execute([$pageId]);$pageRow=$page->fetch();if(!$pageRow)throw new RuntimeException('Choose a valid Brand Page.');
    $duplicate=$db->prepare('SELECT id FROM social_connections WHERE page_id=? AND platform="tiktok"');$duplicate->execute([$pageId]);if($duplicate->fetchColumn())throw new RuntimeException('This Brand Page already has a TikTok connection. Remove or edit it first.');
    $clientKey=trim((string)config('tiktok.client_key',''));$redirectUri=trim((string)config('tiktok.redirect_uri',''));
    if($clientKey===''||trim((string)config('tiktok.client_secret',''))==='')throw new RuntimeException('Add TIKTOK_CLIENT_KEY and TIKTOK_CLIENT_SECRET to the live .env file first.');
    if(!filter_var($redirectUri,FILTER_VALIDATE_URL)||!str_starts_with(strtolower($redirectUri),'https://')||str_contains($redirectUri,'?')||str_contains($redirectUri,'#'))throw new RuntimeException('TIKTOK_REDIRECT_URI must be a static HTTPS URL without query parameters, for example https://social.example.com/tiktok-callback.php');
    $state=bin2hex(random_bytes(32));$_SESSION['tiktok_oauth']=['state'=>$state,'page_id'=>$pageId,'created_at'=>time()];
    $authorize='https://www.tiktok.com/v2/auth/authorize/?'.http_build_query(['client_key'=>$clientKey,'scope'=>'user.info.basic,video.publish','response_type'=>'code','redirect_uri'=>$redirectUri,'state'=>$state],'','&',PHP_QUERY_RFC3986);
    redirect($authorize);
}catch(Throwable $e){flash('error',$e->getMessage());redirect('index.php?view=connections&platform=tiktok');}
