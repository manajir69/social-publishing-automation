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
    if(array_filter((new DatabaseUpgradeService($db))->pending(),static fn(string $change):bool=>str_starts_with($change,'table.social_connections')||str_starts_with($change,'social_connections.'))!==[])throw new RuntimeException('Run Database Upgrade before connecting LinkedIn.');
    $pageId=max(0,(int)($_POST['page_id']??0));$page=$db->prepare('SELECT id FROM social_pages WHERE id=?');$page->execute([$pageId]);if(!$page->fetchColumn())throw new RuntimeException('Choose a valid Brand Page.');
    $organizationId=trim((string)($_POST['organization_id']??''));if(!preg_match('/^[0-9]+$/',$organizationId))throw new RuntimeException('LinkedIn Organization ID must contain numbers only.');
    $displayName=trim((string)($_POST['display_name']??''));if($displayName==='')throw new RuntimeException('LinkedIn Company Page name is required.');
    $duplicate=$db->prepare('SELECT id FROM social_connections WHERE platform="linkedin" AND (page_id=? OR account_id=?)');$duplicate->execute([$pageId,$organizationId]);if($duplicate->fetchColumn())throw new RuntimeException('This Brand Page or LinkedIn Company Page is already connected.');
    $clientId=trim((string)config('linkedin.client_id',''));$redirectUri=trim((string)config('linkedin.redirect_uri',''));
    if($clientId===''||trim((string)config('linkedin.client_secret',''))==='')throw new RuntimeException('Add LINKEDIN_CLIENT_ID and LINKEDIN_CLIENT_SECRET to the live .env file first.');
    if(!filter_var($redirectUri,FILTER_VALIDATE_URL)||!str_starts_with(strtolower($redirectUri),'https://')||str_contains($redirectUri,'?')||str_contains($redirectUri,'#'))throw new RuntimeException('LINKEDIN_REDIRECT_URI must be the exact static HTTPS URL registered in LinkedIn.');
    $state=bin2hex(random_bytes(32));$_SESSION['linkedin_oauth']=['state'=>$state,'page_id'=>$pageId,'organization_id'=>$organizationId,'display_name'=>substr($displayName,0,190),'created_at'=>time()];
    $authorize='https://www.linkedin.com/oauth/v2/authorization?'.http_build_query(['response_type'=>'code','client_id'=>$clientId,'redirect_uri'=>$redirectUri,'state'=>$state,'scope'=>'rw_organization_admin w_organization_social'],'','&',PHP_QUERY_RFC3986);
    redirect($authorize);
}catch(Throwable $e){flash('error',$e->getMessage());redirect('index.php?view=connections&platform=linkedin');}
