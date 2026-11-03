<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Database;
use App\Security\Auth;
use App\Security\Crypto;
use App\Security\Csrf;
use App\Services\AI\AIProviderFactory;
use App\Services\AI\OpenAICompatibleProvider;
use App\Services\AI\PostCopyFormatter;
use App\Services\BlogAIService;
use App\Services\BlogAutomationService;
use App\Services\ContentQualityService;
use App\Services\DatabaseUpgradeService;
use App\Services\FolderContentService;
use App\Services\InstagramService;
use App\Services\ImageBrandingService;
use App\Services\BrandingText;
use App\Services\LinkedInService;
use App\Services\NotificationService;
use App\Services\PlatformScheduleService;
use App\Services\VideoUploadService;
use App\Services\WooCommerceService;
use App\Services\WordPressBlogService;

$route = (string) ($_GET['view'] ?? 'dashboard');
$allowedRoutes = ['dashboard','pages','connections','content','posts','notifications','analytics','upgrade','login','logout','media','ig-media','video-media','video-stage','branding-logo'];
if (!in_array($route, $allowedRoutes, true)) $route = 'dashboard';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if (!Auth::check() && !isset($_GET['view'])) {
    render('Social Publishing Studio', publicLanding());
    exit;
}

if ($route === 'login') {
    if (Auth::check()) redirect('index.php?view=dashboard');
    if ($method === 'POST') {
        Csrf::verify();
        if (Auth::attempt((string) ($_POST['email'] ?? ''), (string) ($_POST['password'] ?? ''))) redirect('index.php?view=dashboard');
        flash('error', 'Email or password is incorrect.');
    }
    render('Login', loginForm());
    exit;
}

if ($route === 'logout' && $method === 'POST') {
    Csrf::verify(); Auth::logout(); redirect('index.php?view=login');
}

$db = Database::connection();

if ($route === 'ig-media') {
    serveInstagramMedia($db);
    exit;
}
if ($route === 'video-media') { serveVideoMedia($db); exit; }

Auth::require();

if(isset($_GET['state'])&&isset($_SESSION['linkedin_oauth'])){
    try{
        $pending=$_SESSION['linkedin_oauth'];unset($_SESSION['linkedin_oauth']);
        if(!is_array($pending)||empty($pending['state'])||empty($pending['page_id'])||time()-(int)($pending['created_at']??0)>600)throw new RuntimeException('LinkedIn connection session expired. Start Connect with LinkedIn again.');
        $state=(string)$_GET['state'];if($state===''||!hash_equals((string)$pending['state'],$state))throw new RuntimeException('LinkedIn security state did not match. Start the connection again.');
        if(!empty($_GET['error']))throw new RuntimeException('LinkedIn permission was not granted: '.substr((string)($_GET['error_description']??$_GET['error']),0,400));
        $code=trim((string)($_GET['code']??''));if($code==='')throw new RuntimeException('LinkedIn did not return an authorization code.');
        $token=(new LinkedInService())->exchangeAuthorizationCode($code,trim((string)config('linkedin.redirect_uri','')));
        $scope=preg_split('/[ ,]+/',(string)$token['scope'],-1,PREG_SPLIT_NO_EMPTY)?:[];if($scope!==[]&&!in_array('w_organization_social',$scope,true))throw new RuntimeException('LinkedIn did not grant w_organization_social. Reconnect after Community Management API access is approved.');
        $pageId=(int)$pending['page_id'];$organizationId=(string)$pending['organization_id'];$duplicate=$db->prepare('SELECT id FROM social_connections WHERE platform="linkedin" AND (page_id=? OR account_id=?)');$duplicate->execute([$pageId,$organizationId]);if($duplicate->fetchColumn())throw new RuntimeException('This Brand Page or LinkedIn Company Page is already connected.');
        $expiresAt=date('Y-m-d H:i:s',time()+(int)$token['expires_in']);$insert=$db->prepare('INSERT INTO social_connections(page_id,platform,display_name,account_id,encrypted_access_token,encrypted_refresh_token,token_expires_at,auto_publish_feed,auto_publish_video,default_privacy,allow_comments,allow_duet,allow_stitch,caption_suffix,status) VALUES(?,"linkedin",?,?,?,?,?,1,1,"PUBLIC_TO_EVERYONE",0,0,0,"","active")');$insert->execute([$pageId,(string)$pending['display_name'],$organizationId,Crypto::encrypt((string)$token['access_token']),Crypto::encrypt((string)$token['refresh_token']),$expiresAt]);$connectionId=(int)$db->lastInsertId();audit($db,'connection.created','social_connection',$connectionId);flash('success','LinkedIn Company Page connected successfully.');redirect('index.php?view=connections&platform=linkedin');
    }catch(Throwable $e){flash('error',$e->getMessage());redirect('index.php?view=connections&platform=linkedin');}
}

if ($route === 'video-stage') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        if ($method !== 'POST') throw new RuntimeException('Upload request must use POST.');
        Csrf::verify();
        $pageId=max(0,(int)($_POST['page_id']??0));
        $db=Database::connection();$page=$db->prepare('SELECT id FROM social_pages WHERE id=? AND status="active"');$page->execute([$pageId]);
        if(!$page->fetchColumn())throw new RuntimeException('Choose an active Brand Page before selecting the video.');
        $uploadId=trim((string)($_POST['upload_id']??''));$chunkIndex=(int)($_POST['chunk_index']??-1);$chunkTotal=(int)($_POST['chunk_total']??0);
        if($uploadId!==''){$result=VideoUploadService::saveChunk($pageId,$uploadId,$chunkIndex,$chunkTotal,$_FILES['video_file']??[]);echo json_encode(['ok'=>true]+$result,JSON_UNESCAPED_SLASHES);exit;}
        $path=VideoUploadService::save($pageId,$_FILES['video_file']??[]);echo json_encode(['ok'=>true,'complete'=>true,'path'=>$path],JSON_UNESCAPED_SLASHES);exit;
    } catch (Throwable $e) {
        http_response_code(422);echo json_encode(['ok'=>false,'message'=>$e->getMessage()],JSON_UNESCAPED_SLASHES);exit;
    }
}

if ($route === 'branding-logo') {
    serveBrandingLogo($db);
    exit;
}

if ($route === 'media') {
    servePostImage($db);
    exit;
}

if ($method === 'POST') {
    Csrf::verify();
    try {
        handlePost($route, $db);
    } catch (Throwable $e) {
        try{
            if(!in_array('table.notifications',(new DatabaseUpgradeService($db))->pending(),true)){
                $type=NotificationService::classify($e->getMessage());$titles=['folder_empty'=>'Folder or image unavailable','token_invalid'=>'Meta token problem','ai_key_or_balance'=>'AI key or balance problem','publish_failed'=>'Action failed'];
                NotificationService::create($db,$type,$titles[$type]??'Action failed',$e->getMessage(),max(0,(int)($_POST['page_id']??0))?:null,null,'error');
            }
        }catch(Throwable){}
        $_SESSION['old'] = $_POST;
        flash('error', $e->getMessage());
        $errorTarget=$route==='content'&&($_POST['action']??'')==='video_upload'?'index.php?view=content&tab=tools':'index.php?view='.urlencode($route);
        redirect($errorTarget);
    }
}

$content = match ($route) {
    'pages' => pagesScreen($db),
    'connections' => connectionsScreen($db),
    'content' => contentScreen($db),
    'posts' => postsScreen($db),
    'notifications' => notificationsScreen($db),
    'analytics' => analyticsScreen($db),
    'upgrade' => upgradeScreen($db),
    default => dashboardScreen($db),
};
render(ucfirst($route), $content);

function handlePost(string $route, PDO $db): never
{
    if($route==='notifications'){
        $action=(string)($_POST['action']??'read_all');
        if($action==='read'){$db->prepare('UPDATE notifications SET read_at=NOW() WHERE id=?')->execute([(int)($_POST['id']??0)]);}
        elseif($action==='delete_read'){$db->exec('DELETE FROM notifications WHERE read_at IS NOT NULL');}
        else{$db->exec('UPDATE notifications SET read_at=NOW() WHERE read_at IS NULL');}
        redirect('index.php?view=notifications');
    }
    if ($route === 'upgrade') {
        $applied=(new DatabaseUpgradeService($db))->run();
        audit($db,'database.upgraded','database',0);
        flash('success',$applied===[]?'Database is already up to date.':'Database upgrade complete: '.count($applied).' change(s) applied.');
        redirect('index.php?view=upgrade');
    }

    if($route==='connections'){
        if(array_filter((new DatabaseUpgradeService($db))->pending(),static fn(string $change):bool=>str_starts_with($change,'table.social_connections')||str_starts_with($change,'social_connections.'))!==[])throw new RuntimeException('Run Database Upgrade before adding LinkedIn or TikTok accounts.');
        $action=(string)($_POST['action']??'save');$connectionId=max(0,(int)($_POST['id']??0));
        if($action==='delete'){
            $check=$db->prepare('SELECT id FROM social_connections WHERE id=?');$check->execute([$connectionId]);if(!$check->fetchColumn())throw new RuntimeException('Social connection was not found.');
            $db->prepare('DELETE FROM social_connections WHERE id=?')->execute([$connectionId]);audit($db,'connection.deleted','social_connection',$connectionId);flash('success','Connection removed. Existing published posts are not affected.');redirect('index.php?view=connections');
        }
        $pageId=max(0,(int)($_POST['page_id']??0));$platform=in_array($_POST['platform']??'', ['linkedin','tiktok'],true)?(string)$_POST['platform']:'';
        $displayName=trim((string)($_POST['display_name']??''));$accountId=trim((string)($_POST['account_id']??''));
        if($pageId<1||$platform===''||$displayName===''||$accountId==='')throw new RuntimeException('Brand Page, platform, account name and account ID are required.');
        $pageCheck=$db->prepare('SELECT id FROM social_pages WHERE id=?');$pageCheck->execute([$pageId]);if(!$pageCheck->fetchColumn())throw new RuntimeException('Brand Page was not found.');
        if($platform==='linkedin'&&!preg_match('/^[0-9]+$/',$accountId))throw new RuntimeException('LinkedIn Organization ID must contain numbers only.');
        if(strlen($displayName)>190||strlen($accountId)>190)throw new RuntimeException('Account name or ID is too long.');
        $duplicate=$db->prepare('SELECT id FROM social_connections WHERE page_id=? AND platform=? AND id<>?');$duplicate->execute([$pageId,$platform,$connectionId]);if($duplicate->fetchColumn())throw new RuntimeException('This Brand Page already has a '.ucfirst($platform).' connection. Edit or remove it first.');
        $current=null;if($connectionId>0){$q=$db->prepare('SELECT * FROM social_connections WHERE id=?');$q->execute([$connectionId]);$current=$q->fetch();if(!$current)throw new RuntimeException('Social connection was not found.');}
        $accessToken=trim((string)($_POST['access_token']??''));$refreshToken=trim((string)($_POST['refresh_token']??''));
        $encryptedAccess=$accessToken!==''?Crypto::encrypt($accessToken):(string)($current['encrypted_access_token']??'');
        $encryptedRefresh=$refreshToken!==''?Crypto::encrypt($refreshToken):(string)($current['encrypted_refresh_token']??'');
        if($encryptedAccess==='')throw new RuntimeException('Access token is required for a new connection.');
        $expires=normalizeDate((string)($_POST['token_expires_at']??''));$privacy=in_array($_POST['default_privacy']??'', ['PUBLIC_TO_EVERYONE','MUTUAL_FOLLOW_FRIENDS','FOLLOWER_OF_CREATOR','SELF_ONLY'],true)?(string)$_POST['default_privacy']:'SELF_ONLY';
        $feedDelay=max(0,min(10080,(int)($_POST['feed_delay_minutes']??60)));$weeklyLimit=max(1,min(7,(int)($_POST['weekly_post_limit']??1)));$weeklyDay=max(1,min(7,(int)($_POST['weekly_publish_weekday']??1)));$weeklyTime=preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/',(string)($_POST['weekly_publish_time']??''))?(string)$_POST['weekly_publish_time']:'10:00';
        $values=[$pageId,$platform,$displayName,$accountId,$encryptedAccess,$encryptedRefresh,$expires,isset($_POST['auto_publish_feed'])?1:0,isset($_POST['auto_publish_video'])?1:0,$privacy,isset($_POST['allow_comments'])?1:0,isset($_POST['allow_duet'])?1:0,isset($_POST['allow_stitch'])?1:0,trim((string)($_POST['caption_suffix']??'')),$feedDelay,$weeklyLimit,$weeklyDay,$weeklyTime,($_POST['status']??'active')==='inactive'?'inactive':'active'];
        if($connectionId>0){$q=$db->prepare('UPDATE social_connections SET page_id=?,platform=?,display_name=?,account_id=?,encrypted_access_token=?,encrypted_refresh_token=?,token_expires_at=?,auto_publish_feed=?,auto_publish_video=?,default_privacy=?,allow_comments=?,allow_duet=?,allow_stitch=?,caption_suffix=?,feed_delay_minutes=?,weekly_post_limit=?,weekly_publish_weekday=?,weekly_publish_time=?,status=? WHERE id=?');$q->execute([...$values,$connectionId]);}
        else{$q=$db->prepare('INSERT INTO social_connections(page_id,platform,display_name,account_id,encrypted_access_token,encrypted_refresh_token,token_expires_at,auto_publish_feed,auto_publish_video,default_privacy,allow_comments,allow_duet,allow_stitch,caption_suffix,feed_delay_minutes,weekly_post_limit,weekly_publish_weekday,weekly_publish_time,status) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');$q->execute($values);$connectionId=(int)$db->lastInsertId();}
        audit($db,$current?'connection.updated':'connection.created','social_connection',$connectionId);flash('success',ucfirst($platform).' connection saved. New posts can now use this destination without extra AI calls.');redirect('index.php?view=connections');
    }

    if ($route === 'blog') {
        $pending = (new DatabaseUpgradeService($db))->pending();
        if (array_filter($pending, static fn(string $change): bool => str_contains($change, 'blog_')) !== []) throw new RuntimeException('Run Database Upgrade before configuring Blog Automation.');
        $action = (string)($_POST['action'] ?? 'save');
        $siteId = max(0, (int)($_POST['site_id'] ?? 0));
        if ($action === 'test_connection') {
            $query = $db->prepare('SELECT * FROM blog_settings WHERE id=?');
            $query->execute([$siteId]);
            $settings = $query->fetch();
            if (!$settings) throw new RuntimeException('Select and save a WordPress site first.');
            $password = Crypto::decrypt((string)($settings['encrypted_wp_app_password'] ?? ''));
            $status = (new WordPressBlogService())->testConnection($settings, $password);
            if (empty($status['yoast_active'])) throw new RuntimeException('WordPress connected, but Yoast SEO is not active.');
            flash('success', 'WordPress connected as ' . (string)$status['user'] . '; Yoast SEO ' . (string)$status['yoast_version'] . ' detected.');
            redirect('index.php?view=blog&site=' . $siteId);
        }
        if ($action === 'run') {
            set_time_limit(240);
            $result = (new BlogAutomationService($db))->runSite($siteId, true);
            audit($db, 'blog.manual_run', 'blog_article', (int)($result['article_id'] ?? 0));
            flash($result['result'] === 'success' ? 'success' : 'error', $result['message']);
            redirect('index.php?view=blog&site=' . $siteId);
        }

        $siteName = trim((string)($_POST['site_name'] ?? ''));
        if ($siteName === '') throw new RuntimeException('Website name is required.');
        $siteUrl = rtrim(trim((string)($_POST['site_url'] ?? '')), '/');
        if (!filter_var($siteUrl, FILTER_VALIDATE_URL) || !str_starts_with(strtolower($siteUrl), 'https://')) {
            throw new RuntimeException('WordPress Site URL must be a valid HTTPS URL.');
        }
        $wpUsername = trim((string)($_POST['wp_username'] ?? ''));
        if ($wpUsername === '') throw new RuntimeException('WordPress username is required.');
        $topics = trim((string)($_POST['topics'] ?? ''));
        if ($topics === '') throw new RuntimeException('Add at least one blog topic area.');
        $frequencyDays = max(1, min(30, (int)($_POST['frequency_days'] ?? 7)));
        $postTime = preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', (string)($_POST['post_time'] ?? '')) ? (string)$_POST['post_time'] : '03:00';
        $publishMode = ($_POST['publish_mode'] ?? 'draft') === 'publish' ? 'publish' : 'draft';
        $model = trim((string)($_POST['ai_model'] ?? '')) ?: 'gpt-5-mini';
        $current = null;
        if ($siteId > 0) {
            $currentQuery = $db->prepare('SELECT * FROM blog_settings WHERE id=?');
            $currentQuery->execute([$siteId]);
            $current = $currentQuery->fetch();
            if (!$current) throw new RuntimeException('WordPress site not found.');
        }
        $wpPassword = trim((string)($_POST['wp_app_password'] ?? ''));
        $apiKey = trim((string)($_POST['ai_api_key'] ?? ''));
        $encryptedWpPassword = $wpPassword !== '' ? Crypto::encrypt($wpPassword) : (string)($current['encrypted_wp_app_password'] ?? '');
        $encryptedApiKey = $apiKey !== '' ? Crypto::encrypt($apiKey) : (string)($current['encrypted_ai_api_key'] ?? '');
        $values = [$siteName, $siteUrl, $wpUsername, $encryptedWpPassword, isset($_POST['enabled']) ? 1 : 0, $frequencyDays, $postTime, $publishMode, $model, $encryptedApiKey, $topics, trim((string)($_POST['system_prompt'] ?? '')), max(0, (int)($_POST['category_id'] ?? 0)) ?: null, max(0, (int)($_POST['featured_media_id'] ?? 0)) ?: null];
        if ($siteId > 0) {
            $query = $db->prepare('UPDATE blog_settings SET site_name=?,site_url=?,wp_username=?,encrypted_wp_app_password=?,enabled=?,frequency_days=?,post_time=?,publish_mode=?,ai_model=?,encrypted_ai_api_key=?,topics=?,system_prompt=?,category_id=?,featured_media_id=? WHERE id=?');
            $query->execute([...$values, $siteId]);
        } else {
            $query = $db->prepare('INSERT INTO blog_settings(site_name,site_url,wp_username,encrypted_wp_app_password,enabled,frequency_days,post_time,publish_mode,ai_model,encrypted_ai_api_key,topics,system_prompt,category_id,featured_media_id) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $query->execute($values);
            $siteId = (int)$db->lastInsertId();
        }
        audit($db, $current ? 'blog.site_updated' : 'blog.site_created', 'blog_setting', $siteId);
        flash('success', $current ? 'Website settings updated.' : 'New WordPress website added.');
        redirect('index.php?view=blog&site=' . $siteId);
    }

    if ($route === 'pages') {
        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT) ?: null;
        $name = trim((string) ($_POST['page_name'] ?? ''));
        $facebookId = trim((string) ($_POST['facebook_page_id'] ?? ''));
        if ($name === '' || !preg_match('/^[0-9]{5,30}$/', $facebookId)) throw new RuntimeException('Page name and a valid numeric Facebook Page ID are required.');
        $provider = in_array($_POST['ai_provider'] ?? '', ['none','openai','anthropic','qwen'], true) ? $_POST['ai_provider'] : 'none';
        $timezone = 'Asia/Dhaka';
        $token = trim((string) ($_POST['access_token'] ?? ''));
        $apiKey = trim((string) ($_POST['ai_api_key'] ?? ''));
        $wooUrl = rtrim(trim((string)($_POST['woo_store_url'] ?? '')), '/');
        if($wooUrl!==''&&(!filter_var($wooUrl,FILTER_VALIDATE_URL)||!str_starts_with($wooUrl,'https://')))throw new RuntimeException('WooCommerce Store URL must be a valid HTTPS URL.');
        $wooKey=trim((string)($_POST['woo_consumer_key']??''));$wooSecret=trim((string)($_POST['woo_consumer_secret']??''));
        $wooRepeatDays=15;
        $wooImagesPerPost=max(1,min(10,(int)($_POST['woo_images_per_post']??7)));
        $wooImageBatchMode=in_array($_POST['woo_image_batch_mode']??'', ['half','fixed'], true)?(string)$_POST['woo_image_batch_mode']:'half';
        $wooDailyMode=in_array($_POST['woo_daily_mode']??'', ['off','draft','schedule'], true)?(string)$_POST['woo_daily_mode']:'off';
        $wooPostsPerDay=max(1,min(3,(int)($_POST['woo_posts_per_day']??1)));
        $wooPostTime=preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/',(string)($_POST['woo_post_time']??''))?(string)$_POST['woo_post_time']:'10:00';
        $wooPostTime2=preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/',(string)($_POST['woo_post_time_2']??''))?(string)$_POST['woo_post_time_2']:'15:00';
        $wooPostTime3=preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/',(string)($_POST['woo_post_time_3']??''))?(string)$_POST['woo_post_time_3']:'20:00';
        $folderInput=trim((string)($_POST['folder_source_slug']??''));
        $folderSlug=$folderInput===''?'':FolderContentService::normalizeName($folderInput);
        $folderDailyMode=in_array($_POST['folder_daily_mode']??'', ['off','draft','schedule'], true)?(string)$_POST['folder_daily_mode']:'off';
        $folderPostsPerDay=max(1,min(3,(int)($_POST['folder_posts_per_day']??1)));
        $folderImagesPerPost=max(1,min(10,(int)($_POST['folder_images_per_post']??10)));
        $folderPostTime=preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/',(string)($_POST['folder_post_time']??''))?(string)$_POST['folder_post_time']:'11:00';
        $folderPostTime2=preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/',(string)($_POST['folder_post_time_2']??''))?(string)$_POST['folder_post_time_2']:'16:00';
        $folderPostTime3=preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/',(string)($_POST['folder_post_time_3']??''))?(string)$_POST['folder_post_time_3']:'21:00';
        $folderBrandingEnabled=isset($_POST['folder_branding_enabled'])?1:0;
        $folderLogoSize=max(5,min(40,(int)($_POST['folder_logo_size_pct']??12)));
        $brandingPositions=ImageBrandingService::positions();
        $folderLogoPosition=in_array($_POST['folder_logo_position']??'', $brandingPositions, true)?(string)$_POST['folder_logo_position']:'top-right';
        $folderBrandText=trim((string)($_POST['folder_brand_text']??''));
        if(preg_match_all('/./us',$folderBrandText)>300)throw new RuntimeException('Branding text must be 300 characters or fewer.');
        BrandingText::validate($folderBrandText);
        $folderTextBackgroundOpacity=max(0,min(100,(int)($_POST['folder_text_background_opacity']??35)));
        $folderTextColor=preg_match('/^#[0-9A-Fa-f]{6}$/',(string)($_POST['folder_text_color']??''))?strtoupper((string)$_POST['folder_text_color']):'#FFFFFF';
        $folderTextBackgroundEnabled=isset($_POST['folder_text_background_enabled'])?1:0;
        $folderTextBackgroundColor=preg_match('/^#[0-9A-Fa-f]{6}$/',(string)($_POST['folder_text_background_color']??''))?strtoupper((string)$_POST['folder_text_background_color']):'#050F19';
        $folderTextPosition=in_array($_POST['folder_text_position']??'', $brandingPositions, true)?(string)$_POST['folder_text_position']:'bottom-center';
        $folderTextOpacity=max(10,min(100,(int)($_POST['folder_text_opacity']??90)));
        $folderTextSize=max(1,min(8,(int)($_POST['folder_text_size_pct']??3)));
        $storyDailyMode=($_POST['story_daily_mode']??'off')==='schedule'?'schedule':'off';
        $storySource=in_array($_POST['story_source']??'', ['woocommerce','server_folder'], true)?(string)$_POST['story_source']:'server_folder';
        $storyPostsPerDay=max(1,min(3,(int)($_POST['story_posts_per_day']??1)));
        $storyPostTime=preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/',(string)($_POST['story_post_time']??''))?(string)$_POST['story_post_time']:'09:00';
        $storyPostTime2=preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/',(string)($_POST['story_post_time_2']??''))?(string)$_POST['story_post_time_2']:'15:00';
        $storyPostTime3=preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/',(string)($_POST['story_post_time_3']??''))?(string)$_POST['story_post_time_3']:'21:00';
        $instagramEnabled=isset($_POST['instagram_enabled'])?1:0;
        $instagramUserId=trim((string)($_POST['instagram_user_id']??''));
        if($instagramEnabled&&!preg_match('/^[0-9]{5,30}$/',$instagramUserId))throw new RuntimeException('A valid numeric Instagram Business Account ID is required when cross-posting is enabled.');
        $instagramToken=trim((string)($_POST['instagram_access_token']??''));
        $instagramCaptionMax=max(120,min(1000,(int)($_POST['instagram_caption_max']??500)));
        $bannedWords=trim((string)($_POST['banned_words']??''));
        $postInstruction=trim((string)($_POST['post_instruction']??''));
        $captionExamples=trim((string)($_POST['caption_examples']??''));
        if(count(array_unique(array_slice([$wooPostTime,$wooPostTime2,$wooPostTime3],0,$wooPostsPerDay)))!==$wooPostsPerDay)throw new RuntimeException('WooCommerce post times must be different.');
        if(count(array_unique(array_slice([$folderPostTime,$folderPostTime2,$folderPostTime3],0,$folderPostsPerDay)))!==$folderPostsPerDay)throw new RuntimeException('Folder post times must be different.');
        if(count(array_unique(array_slice([$storyPostTime,$storyPostTime2,$storyPostTime3],0,$storyPostsPerDay)))!==$storyPostsPerDay)throw new RuntimeException('Story post times must be different.');
        $modelDefaults=['openai'=>'gpt-5-mini','anthropic'=>'claude-sonnet-4-6','qwen'=>'qwen-plus','none'=>''];
        $aiModel=trim((string)($_POST['ai_model']??''))?:$modelDefaults[$provider];
        $current=null;
        if ($id) {
            $current = $db->prepare('SELECT * FROM social_pages WHERE id=?'); $current->execute([$id]); $current = $current->fetch();
            if (!$current) throw new RuntimeException('Page not found.');
            $sameProvider=$provider===(string)($current['ai_provider']??'none');
            $encryptedAiKey=$apiKey!==''?Crypto::encrypt($apiKey):($sameProvider?(string)$current['encrypted_ai_api_key']:'');
            $stmt = $db->prepare('UPDATE social_pages SET page_name=?,facebook_page_id=?,encrypted_access_token=?,woo_store_url=?,encrypted_woo_consumer_key=?,encrypted_woo_consumer_secret=?,woo_repeat_days=?,woo_images_per_post=?,woo_image_batch_mode=?,woo_daily_mode=?,woo_posts_per_day=?,woo_post_time=?,woo_post_time_2=?,woo_post_time_3=?,folder_source_slug=?,folder_daily_mode=?,folder_posts_per_day=?,folder_images_per_post=?,folder_post_time=?,folder_post_time_2=?,folder_post_time_3=?,story_daily_mode=?,story_source=?,story_posts_per_day=?,story_post_time=?,story_post_time_2=?,story_post_time_3=?,ai_provider=?,ai_model=?,encrypted_ai_api_key=?,ai_prompt=?,default_hashtags=?,timezone=?,status=? WHERE id=?');
            $stmt->execute([$name,$facebookId,$token !== '' ? Crypto::encrypt($token) : $current['encrypted_access_token'],$wooUrl,$wooKey!==''?Crypto::encrypt($wooKey):$current['encrypted_woo_consumer_key'],$wooSecret!==''?Crypto::encrypt($wooSecret):$current['encrypted_woo_consumer_secret'],$wooRepeatDays,$wooImagesPerPost,$wooImageBatchMode,$wooDailyMode,$wooPostsPerDay,$wooPostTime,$wooPostTime2,$wooPostTime3,$folderSlug,$folderDailyMode,$folderPostsPerDay,$folderImagesPerPost,$folderPostTime,$folderPostTime2,$folderPostTime3,$storyDailyMode,$storySource,$storyPostsPerDay,$storyPostTime,$storyPostTime2,$storyPostTime3,$provider,$aiModel,$encryptedAiKey,trim((string)$_POST['ai_prompt']),trim((string)$_POST['default_hashtags']),$timezone,$_POST['status']==='inactive'?'inactive':'active',$id]);
        } else {
            $stmt = $db->prepare('INSERT INTO social_pages(page_name,facebook_page_id,encrypted_access_token,woo_store_url,encrypted_woo_consumer_key,encrypted_woo_consumer_secret,woo_repeat_days,woo_images_per_post,woo_image_batch_mode,woo_daily_mode,woo_posts_per_day,woo_post_time,woo_post_time_2,woo_post_time_3,folder_source_slug,folder_daily_mode,folder_posts_per_day,folder_images_per_post,folder_post_time,folder_post_time_2,folder_post_time_3,story_daily_mode,story_source,story_posts_per_day,story_post_time,story_post_time_2,story_post_time_3,ai_provider,ai_model,encrypted_ai_api_key,ai_prompt,default_hashtags,timezone,status) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $stmt->execute([$name,$facebookId,Crypto::encrypt($token),$wooUrl,Crypto::encrypt($wooKey),Crypto::encrypt($wooSecret),$wooRepeatDays,$wooImagesPerPost,$wooImageBatchMode,$wooDailyMode,$wooPostsPerDay,$wooPostTime,$wooPostTime2,$wooPostTime3,$folderSlug,$folderDailyMode,$folderPostsPerDay,$folderImagesPerPost,$folderPostTime,$folderPostTime2,$folderPostTime3,$storyDailyMode,$storySource,$storyPostsPerDay,$storyPostTime,$storyPostTime2,$storyPostTime3,$provider,$aiModel,Crypto::encrypt($apiKey),trim((string)$_POST['ai_prompt']),trim((string)$_POST['default_hashtags']),$timezone,$_POST['status']==='inactive'?'inactive':'active']);
        }
        $savedPageId=$id ?: (int)$db->lastInsertId();
        $oldLogoPath=(string)($current['folder_logo_path']??'');
        $savedLogoPath=isset($_POST['remove_folder_logo'])?'':$oldLogoPath;
        $logoUpload=$_FILES['folder_logo']??null;
        if(is_array($logoUpload)&&(int)($logoUpload['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE){
            $savedLogoPath=ImageBrandingService::saveLogo($savedPageId,$logoUpload);
        }
        if($folderBrandingEnabled&&$savedLogoPath===''&&$folderBrandText==='')throw new RuntimeException('Enable folder branding only after adding a logo or branding text.');
        $savedInstagramToken=isset($_POST['clear_instagram_access_token'])?'':($instagramToken!==''?Crypto::encrypt($instagramToken):(string)($current['encrypted_instagram_access_token']??''));
        $extra=$db->prepare('UPDATE social_pages SET instagram_enabled=?,instagram_user_id=?,encrypted_instagram_access_token=?,instagram_caption_max=?,banned_words=?,post_instruction=?,caption_examples=?,folder_branding_enabled=?,folder_logo_path=?,folder_logo_size_pct=?,folder_logo_position=?,folder_brand_text=?,folder_text_color=?,folder_text_background_enabled=?,folder_text_background_color=?,folder_text_background_opacity=?,folder_text_position=?,folder_text_opacity=?,folder_text_size_pct=? WHERE id=?');
        $extra->execute([$instagramEnabled,$instagramUserId,$savedInstagramToken,$instagramCaptionMax,$bannedWords,$postInstruction,$captionExamples,$folderBrandingEnabled,$savedLogoPath!==''?$savedLogoPath:null,$folderLogoSize,$folderLogoPosition,$folderBrandText,$folderTextColor,$folderTextBackgroundEnabled,$folderTextBackgroundColor,$folderTextBackgroundOpacity,$folderTextPosition,$folderTextOpacity,$folderTextSize,$savedPageId]);
        if($oldLogoPath!==''&&$oldLogoPath!==$savedLogoPath)ImageBrandingService::deleteLogo($oldLogoPath);
        audit($db, $id ? 'page.updated' : 'page.created', 'social_page', $savedPageId);
        flash('success', 'Facebook Page configuration saved.'); redirect('index.php?view=pages');
    }

    if ($route === 'content') {
        if(($_POST['action']??'')==='video_upload'){
            $pageId=(int)($_POST['page_id']??0);$q=$db->prepare('SELECT * FROM social_pages WHERE id=? AND status="active"');$q->execute([$pageId]);$page=$q->fetch();if(!$page)throw new RuntimeException('Active Facebook Page not found.');
            $type=($_POST['video_type']??'reel')==='video'?'video':'reel';$title=trim((string)($_POST['video_title']??''));$caption=trim((string)($_POST['video_caption']??''));$hashtags=trim((string)($_POST['video_hashtags']??''));$cta=trim((string)($_POST['video_cta']??''));
            if($title===''||strlen($title)>255)throw new RuntimeException('Add a video title up to 255 characters.');
            if(strlen($caption)>5000||strlen($hashtags)>1000||strlen($cta)>500)throw new RuntimeException('Video text is too long.');
            $toFacebook=isset($_POST['publish_to_facebook'])?1:0;$toInstagram=isset($_POST['publish_to_instagram'])?1:0;$toLinkedIn=isset($_POST['publish_to_linkedin'])?1:0;$toTikTok=isset($_POST['publish_to_tiktok'])?1:0;if(!$toFacebook&&!$toInstagram&&!$toLinkedIn&&!$toTikTok)throw new RuntimeException('Select at least one publishing destination.');
            if($toInstagram&&empty($page['instagram_enabled']))throw new RuntimeException('Enable Instagram cross-posting for this Page before selecting Instagram.');
            foreach(['linkedin'=>$toLinkedIn,'tiktok'=>$toTikTok] as $platform=>$selected){if(!$selected)continue;$connected=$db->prepare('SELECT COUNT(*) FROM social_connections WHERE page_id=? AND platform=? AND status="active" AND auto_publish_video=1');$connected->execute([$pageId,$platform]);if(!(int)$connected->fetchColumn())throw new RuntimeException('Add an active '.ucfirst($platform).' connection with video publishing enabled first.');}
            $videoPath=trim((string)($_POST['staged_video_path']??''));
            if($videoPath!==''){
                if(!str_starts_with($videoPath,'page-'.$pageId.'/'))throw new RuntimeException('The uploaded video does not belong to the selected Brand Page. Select it again.');
                VideoUploadService::resolve($videoPath);
            }else{$videoPath=VideoUploadService::save($pageId,$_FILES['video_file']??[]);}
            $delivery=(string)($_POST['video_delivery']??'now');$scheduledAt=date('Y-m-d H:i:s');
            if($delivery==='schedule'){$scheduledAt=normalizeDate((string)($_POST['video_scheduled_at']??''))??'';if($scheduledAt===''||strtotime($scheduledAt)<=time())throw new RuntimeException('Choose a future date and time for the video.');}
            $insert=$db->prepare('INSERT INTO social_posts(page_id,content_item_id,post_type,caption,hashtags,call_to_action,image_url,image_urls_json,video_path,video_title,publish_to_facebook,publish_to_instagram,instagram_caption,instagram_hashtags,instagram_status,publish_to_linkedin,linkedin_status,publish_to_tiktok,tiktok_status,scheduled_at,status) VALUES(?,NULL,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,"scheduled")');
            $insert->execute([$pageId,$type,$caption,$hashtags,$cta,'','[]',$videoPath,$title,$toFacebook,$toInstagram,trim($caption."\n\n".$cta),$hashtags,$toInstagram?'pending':'off',$toLinkedIn,$toLinkedIn?'pending':'off',$toTikTok,$toTikTok?'pending':'off',$scheduledAt]);
            $videoPostId=(int)$db->lastInsertId();PlatformScheduleService::scheduleSelected($db,$videoPostId);
            audit($db,'video.queued','social_post',$videoPostId);flash('success',$delivery==='schedule'?'Video/Reel scheduled.':'Video/Reel queued for the next cron run.');redirect('index.php?view=posts&scope=queue&page='.$pageId);
        }
        if(($_POST['action']??'')==='story_manual'){
            $pageId=(int)($_POST['page_id']??0);
            $source=in_array($_POST['story_source']??'', ['woocommerce','server_folder'], true)?(string)$_POST['story_source']:'server_folder';
            $delivery=($_POST['story_delivery']??'draft')==='queue'?'queue':'draft';
            $q=$db->prepare('SELECT * FROM social_pages WHERE id=? AND status="active"');$q->execute([$pageId]);$page=$q->fetch();
            if(!$page)throw new RuntimeException('Active Facebook Page not found.');
            if($source==='woocommerce'){
                if(trim((string)($page['woo_store_url']??''))==='')throw new RuntimeException('This Page has no WooCommerce source configured.');
                $storyPage=$page;$storyPage['woo_image_batch_mode']='fixed';$storyPage['woo_images_per_post']=1;
                $prepared=(new WooCommerceService())->prepareNext($storyPage,'story');
            }else{
                if(trim((string)($page['folder_source_slug']??''))==='')throw new RuntimeException('This Page has no server image folder configured.');
                $storyPage=$page;$storyPage['folder_images_per_post']=1;
                $prepared=(new FolderContentService())->prepareNext($storyPage,true,'story');
            }
            $q=$db->prepare('SELECT * FROM content_items WHERE id=? AND page_id=? AND status="active"');$q->execute([(int)$prepared['item_id'],$pageId]);$item=$q->fetch();
            if(!$item)throw new RuntimeException('Prepared Story content was not found.');
            $batchField=$source==='woocommerce'?'woo_batch_images_json':'folder_batch_images_json';
            $images=json_decode((string)($item[$batchField]??''),true);$image=(string)($images[0]??'');
            if(!str_starts_with($image,'https://')&&!str_starts_with($image,'local://'))throw new RuntimeException('No valid Story image was prepared.');
            $selectedImage=$image;
            if($source==='server_folder')$image=(string)(ImageBrandingService::applyBatch($page,[$image],true)[0]??'');
            ContentQualityService::validateImages([$image],false);
            $status=$delivery==='queue'?'scheduled':'draft';$scheduledAt=$status==='scheduled'?date('Y-m-d H:i:s'):null;
            $publishInstagram=!empty($page['instagram_enabled']);
            $insert=$db->prepare("INSERT INTO social_posts(page_id,content_item_id,post_type,caption,hashtags,call_to_action,image_url,image_urls_json,scheduled_at,publish_to_instagram,instagram_caption,instagram_hashtags,instagram_status,status) VALUES(?,?,'story',?,?,?,?,?,?,?,?,?,?,?)");
            $insert->execute([$pageId,(int)$item['id'],(string)$item['title'],'','',$image,json_encode([$image],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$scheduledAt,$publishInstagram?1:0,'','',$publishInstagram?'pending':'off',$status]);
            $postId=(int)$db->lastInsertId();
            if($source==='woocommerce')markContentBatchUsed($db,$item,[$selectedImage]);else FolderContentService::markBatchUsed($db,(int)$item['id'],[$selectedImage]);
            audit($db,'story.manual_'.$status,'social_post',$postId);
            flash('success',$status==='scheduled'?'Story queued. Cron will publish it shortly.':'Story draft created. Review it in Posts.');
            redirect('index.php?view=posts&scope=queue');
        }
        if(($_POST['action']??'')==='folder_next'){
            $pageId=(int)($_POST['page_id']??0);$q=$db->prepare('SELECT * FROM social_pages WHERE id=? AND status="active"');$q->execute([$pageId]);$page=$q->fetch();
            if(!$page)throw new RuntimeException('Active Facebook Page not found.');
            $result=(new FolderContentService())->prepareNext($page);
            audit($db,'folder.content_prepared','content_item',(int)$result['item_id']);
            flash('success','Folder prepared: '.$result['folder'].' — '.$result['image_count'].' image(s).');redirect('index.php?view=content');
        }
        if(($_POST['action']??'')==='folder_selected'){
            $pageId=(int)($_POST['page_id']??0);$folderName=trim((string)($_POST['folder_name']??''));$q=$db->prepare('SELECT * FROM social_pages WHERE id=? AND status="active"');$q->execute([$pageId]);$page=$q->fetch();
            if(!$page)throw new RuntimeException('Active Facebook Page not found.');
            $result=(new FolderContentService())->prepareNext($page,false,'feed',true,$folderName);
            audit($db,'folder.selected_prepared','content_item',(int)$result['item_id']);
            flash('success','Selected folder prepared: '.$result['folder'].' — '.$result['image_count'].' image(s).');redirect('index.php?view=posts');
        }
        if(($_POST['action']??'')==='woo_next'){
            $pageId=(int)($_POST['page_id']??0);$q=$db->prepare('SELECT * FROM social_pages WHERE id=? AND status="active"');$q->execute([$pageId]);$page=$q->fetch();
            if(!$page)throw new RuntimeException('Active Facebook Page not found.');
            $result=(new WooCommerceService())->prepareNext($page);
            audit($db,'woocommerce.product_prepared','content_item',(int)$result['item_id']);
            $message=$result['reused']?'Older product image batch prepared: ':'Product image batch prepared: ';
            flash('success',$message.$result['title'].' — '.$result['image_count'].' image(s), starting at image '.$result['image_start'].'.');redirect('index.php?view=content');
        }
        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT) ?: null;
        $pageId = (int) ($_POST['page_id'] ?? 0); $title = trim((string) ($_POST['title'] ?? '')); $image = trim((string) ($_POST['image_url'] ?? ''));
        if ($pageId < 1 || $title === '' || !filter_var($image, FILTER_VALIDATE_URL) || !str_starts_with($image, 'https://')) throw new RuntimeException('Page, title and a public HTTPS image URL are required.');
        foreach (['source_url'] as $field) if ($_POST[$field] !== '' && !filter_var($_POST[$field], FILTER_VALIDATE_URL)) throw new RuntimeException('Source URL is invalid.');
        $values=[$pageId,$_POST['content_type']==='service'?'service':'product',$title,trim((string)$_POST['description']),trim((string)$_POST['price']),trim((string)$_POST['features']),trim((string)$_POST['category']),trim((string)$_POST['source_url']),$image,$_POST['status']==='inactive'?'inactive':'active'];
        if ($id) {
            $stmt=$db->prepare('UPDATE content_items SET page_id=?,content_type=?,title=?,description=?,price=?,features=?,category=?,source_url=?,image_url=?,status=? WHERE id=?');
            $stmt->execute([...$values,$id]);
            if($stmt->rowCount()===0){$check=$db->prepare('SELECT id FROM content_items WHERE id=?');$check->execute([$id]);if(!$check->fetchColumn())throw new RuntimeException('Content item not found.');}
            audit($db,'content.updated','content_item',$id);
            flash('success','Content updated.');
        } else {
            $stmt=$db->prepare('INSERT INTO content_items(page_id,content_type,title,description,price,features,category,source_url,image_url,status) VALUES(?,?,?,?,?,?,?,?,?,?)');
            $stmt->execute($values);
            audit($db,'content.created','content_item',(int)$db->lastInsertId());
            flash('success','Content saved.');
        }
        redirect('index.php?view=content');
    }

    if ($route === 'posts') {
        $action = (string) ($_POST['action'] ?? 'create');
        $returnPage=max(0,(int)($_POST['return_page']??0));$returnPageQuery=$returnPage>0?'&page='.$returnPage:'';
        if (in_array($action, ['archive','restore','delete'], true)) {
            $postId=(int)($_POST['id']??0);
            $returnScope=in_array($_POST['return_scope']??'', ['queue','published','issues','history','archived'], true)?(string)$_POST['return_scope']:'queue';
            $current=$db->prepare('SELECT status,archived_at FROM social_posts WHERE id=?');
            $current->execute([$postId]); $post=$current->fetch();
            if(!$post)throw new RuntimeException('Post not found.');
            if($action==='archive'){
                if(in_array($post['status'],['scheduled','publishing'],true))throw new RuntimeException('Cancel a scheduled post before archiving it.');
                $db->prepare('UPDATE social_posts SET archived_at=NOW() WHERE id=? AND archived_at IS NULL')->execute([$postId]);
                audit($db,'post.archived','social_post',$postId); flash('success','Post moved to Archived.');
                redirect('index.php?view=posts&scope='.$returnScope.$returnPageQuery);
            }
            if($action==='restore'){
                $db->prepare('UPDATE social_posts SET archived_at=NULL WHERE id=? AND archived_at IS NOT NULL')->execute([$postId]);
                audit($db,'post.restored','social_post',$postId); flash('success','Post restored.');
                redirect('index.php?view=posts&scope=archived'.$returnPageQuery);
            }
            if(empty($post['archived_at']))throw new RuntimeException('Archive the post before deleting it permanently.');
            audit($db,'post.deleted','social_post',$postId);
            $db->prepare('DELETE FROM social_posts WHERE id=? AND archived_at IS NOT NULL')->execute([$postId]);
            flash('success','Admin record permanently deleted. The Facebook post was not deleted.');
            redirect('index.php?view=posts&scope=archived'.$returnPageQuery);
        }
        if ($action === 'regenerate') {
            $postId=(int)($_POST['id']??0);
            $stmt=$db->prepare('SELECT s.id,s.status,s.page_id,s.post_type,s.publish_to_instagram,c.title,c.description,c.price,c.features,c.category,c.source_url,p.page_name AS social_page_name,p.ai_provider,p.ai_model,p.encrypted_ai_api_key,p.ai_prompt,p.default_hashtags,p.banned_words,p.instagram_caption_max,p.post_instruction,p.caption_examples FROM social_posts s JOIN content_items c ON c.id=s.content_item_id JOIN social_pages p ON p.id=s.page_id WHERE s.id=? AND s.archived_at IS NULL AND s.status IN ("draft","approved")');
            $stmt->execute([$postId]); $item=$stmt->fetch();
            if(!$item) throw new RuntimeException('Only a draft or approved post can be regenerated.');
            ContentQualityService::assertTitleAvailable($db,$item,$item,$postId,(string)($item['post_type']??'feed'));
            $generated=AIProviderFactory::make($item)->generate(['page_name'=>$item['social_page_name'],'title'=>$item['title'],'description'=>$item['description'],'price'=>$item['price'],'features'=>$item['features'],'category'=>$item['category'],'source_url'=>$item['source_url'],'post_instruction'=>$item['post_instruction']??'','caption_examples'=>$item['caption_examples']??''],(string)$item['ai_prompt']);
            $copy=PostCopyFormatter::normalize($generated,(string)$item['default_hashtags']);
            $quality=ContentQualityService::prepare($db,$item,$item,$copy,$postId,(string)($item['post_type']??'feed'));
            $igCopy=!empty($item['publish_to_instagram'])?ContentQualityService::instagramCopy($quality,(int)($item['instagram_caption_max']??500)):['caption'=>'','hashtags'=>''];
            $update=$db->prepare('UPDATE social_posts SET caption=?,hashtags=?,call_to_action=?,title_fingerprint=?,caption_fingerprint=?,instagram_caption=?,instagram_hashtags=?,instagram_status=IF(publish_to_instagram=1,"pending","off"),instagram_error=NULL,status="draft",scheduled_at=NULL,error_message=NULL WHERE id=?');
            $update->execute([$quality['caption'],$quality['hashtags'],$quality['call_to_action'],$quality['title_fingerprint'],$quality['caption_fingerprint'],$igCopy['caption'],$igCopy['hashtags'],$postId]);
            audit($db,'post.regenerated','social_post',$postId); flash('success','Caption regenerated. Review the updated draft.'); redirect('index.php?view=posts&scope=queue'.$returnPageQuery);
        }
        if ($action === 'status') {
            $id=(int)$_POST['id']; $status=(string)$_POST['status'];
            $allowed=['approved','scheduled','cancelled']; if(!in_array($status,$allowed,true)) throw new RuntimeException('Invalid status.');
            $current=$db->prepare('SELECT status,archived_at FROM social_posts WHERE id=?');$current->execute([$id]);$currentPost=$current->fetch();
            if(!$currentPost||!empty($currentPost['archived_at']))throw new RuntimeException('Archived post must be restored before changing its status.');
            $currentStatus=$currentPost['status'];
            if($status==='scheduled'&&$currentStatus!=='approved') throw new RuntimeException('Approve the draft before scheduling it.');
            $scheduled = $status === 'scheduled' ? normalizeDate((string)($_POST['scheduled_at']??'')) : null;
            if ($status === 'scheduled' && (!$scheduled || strtotime($scheduled)<=time())) throw new RuntimeException('A future schedule date/time is required.');
            $stmt=$db->prepare('UPDATE social_posts SET status=?,scheduled_at=? WHERE id=? AND archived_at IS NULL AND status IN ("draft","approved","scheduled","failed")'); $stmt->execute([$status,$scheduled,$id]);
            if($status==='scheduled')PlatformScheduleService::scheduleSelected($db,$id);
            audit($db,'post.status_'.$status,'social_post',$id); flash('success','Post status updated.'); redirect('index.php?view=posts&scope=queue'.$returnPageQuery);
        }
        $requestedPostType=($_POST['post_type']??'feed')==='story'?'story':'feed';$contentSelection=trim((string)($_POST['content_item_id']??''));$contentId=0;
        if(str_starts_with($contentSelection,'folder:')){
            $parts=explode(':',$contentSelection,3);$folderPageId=(int)($parts[1]??0);$folderName=rawurldecode((string)($parts[2]??''));$pageQuery=$db->prepare('SELECT * FROM social_pages WHERE id=? AND status="active"');$pageQuery->execute([$folderPageId]);$folderPage=$pageQuery->fetch();
            if(!$folderPage)throw new RuntimeException('The selected Content Page was not found.');
            $prepared=(new FolderContentService())->prepareNext($folderPage,$requestedPostType==='story',$requestedPostType,true,$folderName);$contentId=(int)$prepared['item_id'];
        }else{$contentId=(int)$contentSelection;}
        $stmt=$db->prepare('SELECT c.*,p.page_name AS social_page_name,p.ai_provider,p.ai_model,p.encrypted_ai_api_key,p.ai_prompt,p.default_hashtags,p.folder_source_slug,p.folder_images_per_post,p.folder_branding_enabled,p.folder_logo_path,p.folder_logo_size_pct,p.folder_logo_position,p.folder_brand_text,p.folder_text_color,p.folder_text_background_enabled,p.folder_text_background_color,p.folder_text_background_opacity,p.folder_text_position,p.folder_text_opacity,p.folder_text_size_pct,p.instagram_enabled,p.instagram_caption_max,p.banned_words,p.post_instruction,p.caption_examples FROM content_items c JOIN social_pages p ON p.id=c.page_id WHERE c.id=? AND c.status="active"'); $stmt->execute([$contentId]); $item=$stmt->fetch();
        if(!$item) throw new RuntimeException('Content item not found.');
        $postType=$requestedPostType;
        if(($item['source_type']??'')==='server_folder'&&empty($item['folder_batch_images_json'])){
            $prepared=(new FolderContentService())->prepareNext(['id'=>(int)$item['page_id'],'folder_source_slug'=>(string)($item['folder_source_slug']??''),'folder_images_per_post'=>(int)($item['folder_images_per_post']??10)],$postType==='story',$postType,true,(string)$item['category']);
            $reload=$db->prepare('SELECT * FROM content_items WHERE id=?');$reload->execute([(int)$prepared['item_id']]);$fresh=$reload->fetch();if(!$fresh)throw new RuntimeException('The selected folder could not be prepared.');$item=array_replace($item,$fresh);
        }
        $caption=trim((string)($_POST['caption']??'')); $hashtags=trim((string)($_POST['hashtags']??'')); $cta=trim((string)($_POST['call_to_action']??''));
        if ($action === 'generate') {
            if($postType==='story')throw new RuntimeException('Facebook photo Stories do not need an AI caption. Choose Save manual draft.');
            ContentQualityService::assertTitleAvailable($db,$item,$item);
            $generated=AIProviderFactory::make($item)->generate(['page_name'=>$item['social_page_name'],'title'=>$item['title'],'description'=>$item['description'],'price'=>$item['price'],'features'=>$item['features'],'category'=>$item['category'],'source_url'=>$item['source_url'],'post_instruction'=>$item['post_instruction']??'','caption_examples'=>$item['caption_examples']??''],(string)$item['ai_prompt']);
            $copy=PostCopyFormatter::normalize($generated,(string)$item['default_hashtags']);
            $caption=$copy['caption']; $hashtags=$copy['hashtags']; $cta=$copy['call_to_action'];
        }
        if($postType==='feed'&&$caption==='') throw new RuntimeException('Caption is required for a feed post.');
        if($postType==='story'&&$caption==='')$caption=(string)$item['title'];
        $imageUrls=match($item['source_type']??'manual'){
            'woocommerce'=>json_decode((string)($item['woo_batch_images_json']??''),true),
            'server_folder'=>json_decode((string)($item['folder_batch_images_json']??''),true),
            default=>[$item['image_url']],
        };
        if(!is_array($imageUrls)||$imageUrls===[])throw new RuntimeException('No prepared image batch is available.');
        $imageLimit=$postType==='story'?1:(($item['source_type']??'manual')==='server_folder'?max(1,min(10,(int)($item['folder_images_per_post']??10))):10);
        $imageUrls=array_values(array_slice(array_filter($imageUrls,fn($ref)=>is_string($ref)&&(str_starts_with($ref,'https://')||str_starts_with($ref,'local://'))),0,$imageLimit));
        if($imageUrls===[])throw new RuntimeException('No valid image is available.');
        if($postType==='feed'){
            $publishFacebook=isset($_POST['publish_to_facebook']);$publishInstagram=isset($_POST['publish_to_instagram']);$publishLinkedIn=isset($_POST['publish_to_linkedin']);$publishTikTok=isset($_POST['publish_to_tiktok']);
            if(!$publishFacebook&&!$publishInstagram&&!$publishLinkedIn&&!$publishTikTok)throw new RuntimeException('Select at least one publishing destination.');
            if($publishInstagram&&empty($item['instagram_enabled']))throw new RuntimeException('Instagram is not enabled for the selected Content Page.');
            foreach(['linkedin'=>$publishLinkedIn,'tiktok'=>$publishTikTok] as $platform=>$selected){if(!$selected)continue;$connection=$db->prepare('SELECT COUNT(*) FROM social_connections WHERE page_id=? AND platform=? AND status="active"');$connection->execute([(int)$item['page_id'],$platform]);if(!(int)$connection->fetchColumn())throw new RuntimeException('Connect an active '.ucfirst($platform).' account to this Content Page first.');}
        }else{$publishFacebook=true;$publishInstagram=!empty($item['instagram_enabled']);$publishLinkedIn=false;$publishTikTok=false;}
        $selectedImageUrls=$imageUrls;
        if(($item['source_type']??'manual')==='server_folder')$imageUrls=ImageBrandingService::applyBatch($item,$imageUrls,$postType==='story');
        $imageUrls=ContentQualityService::filterValidImages($imageUrls,false);
        $copy=PostCopyFormatter::normalize(['caption'=>$caption,'hashtags'=>$hashtags,'call_to_action'=>$cta],(string)$item['default_hashtags']);
        $quality=ContentQualityService::prepare($db,$item,$item,$copy,null,$postType);
        $igCopy=$publishInstagram&&$postType==='feed'?ContentQualityService::instagramCopy($quality,(int)($item['instagram_caption_max']??500)):['caption'=>'','hashtags'=>''];
        $stmt=$db->prepare('INSERT INTO social_posts(page_id,content_item_id,post_type,caption,hashtags,call_to_action,image_url,image_urls_json,title_fingerprint,caption_fingerprint,publish_to_facebook,publish_to_instagram,instagram_caption,instagram_hashtags,instagram_status,publish_to_linkedin,linkedin_status,publish_to_tiktok,tiktok_status,status) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,"draft")');
        $stmt->execute([$item['page_id'],$item['id'],$postType,$quality['caption'],$postType==='story'?'':$quality['hashtags'],$postType==='story'?'':$quality['call_to_action'],$imageUrls[0],json_encode($imageUrls,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),$quality['title_fingerprint'],$quality['caption_fingerprint'],$publishFacebook?1:0,$publishInstagram?1:0,$igCopy['caption'],$igCopy['hashtags'],$publishInstagram?'pending':'off',$publishLinkedIn?1:0,$publishLinkedIn?'pending':'off',$publishTikTok?1:0,$publishTikTok?'pending':'off']);
        $manualPostId=(int)$db->lastInsertId();PlatformScheduleService::scheduleSelected($db,$manualPostId);
        markContentBatchUsed($db,$item,$selectedImageUrls);
        audit($db,$postType==='story'?'story.manual_draft':'post.created','social_post',$manualPostId); flash('success',$postType==='story'?'Story draft created. Review it before scheduling.':'Draft post created. Review it before approval.'); redirect('index.php?view=posts');
    }
    throw new RuntimeException('Unknown action.');
}

function dashboardScreen(PDO $db): string
{
    $archiveReady=!in_array('social_posts.archived_at',(new DatabaseUpgradeService($db))->pending(),true);
    $counts=[]; foreach(['draft','scheduled','published','failed'] as $s){$q=$db->prepare('SELECT COUNT(*) FROM social_posts WHERE status=?'.($archiveReady?' AND archived_at IS NULL':''));$q->execute([$s]);$counts[$s]=(int)$q->fetchColumn();}
    $pages=(int)$db->query('SELECT COUNT(*) FROM social_pages WHERE status="active"')->fetchColumn();
    return '<div class="cards">'.card('Connected pages',$pages).card('Draft posts',$counts['draft']).card('Scheduled',$counts['scheduled']).card('Published',$counts['published']).card('Failed',$counts['failed']).'</div><div class="panel"><h2>Safe workflow</h2><p>Add a Page → add Content → generate or write a Draft → review → approve/schedule → cron publishes it.</p><p>Nothing is automatically published while a post remains a draft.</p></div>';
}

function upgradeScreen(PDO $db): string
{
    $pending=(new DatabaseUpgradeService($db))->pending();
    $html='<div class="panel"><h1>Database Upgrade</h1>';
    if($pending===[])return $html.'<p class="success">Database is up to date.</p></div>';
    $html.='<p>'.count($pending).' database change(s) are ready. This process does not delete products, posts or settings.</p><ul>';
    foreach($pending as $change)$html.='<li>'.e($change).'</li>';
    return $html.'</ul><form method="post">'.Csrf::input().'<button>Run Database Upgrade</button></form></div>';
}

function pagesScreen(PDO $db): string
{
    $rows=$db->query('SELECT * FROM social_pages ORDER BY id DESC')->fetchAll();$edit=null;$adding=isset($_GET['new']);
    if(isset($_GET['edit'])){$q=$db->prepare('SELECT * FROM social_pages WHERE id=?');$q->execute([(int)$_GET['edit']]);$edit=$q->fetch();}
    $showForm=$adding||is_array($edit);
    $promptValue=$edit&&trim((string)($edit['ai_prompt']??''))!==''?(string)$edit['ai_prompt']:OpenAICompatibleProvider::defaultPrompt();

    $wooMode=str_replace('<select ','<select data-daily-mode="woo" ',selectField('Daily WooCommerce automation','woo_daily_mode',['off'=>'Off','draft'=>'Create AI draft(s) daily','schedule'=>'Create and publish AI post(s) daily'],$edit['woo_daily_mode']??'off'));
    $wooCount=str_replace('<select ','<select data-daily-count="woo" ',selectField('WooCommerce posts per day','woo_posts_per_day',['1'=>'1 post','2'=>'2 posts','3'=>'3 posts'],$edit['woo_posts_per_day']??1));
    $folderMode=str_replace('<select ','<select data-daily-mode="folder" ',selectField('Daily folder automation','folder_daily_mode',['off'=>'Off','draft'=>'Create AI draft(s) daily','schedule'=>'Create and publish AI post(s) daily'],$edit['folder_daily_mode']??'off'));
    $folderCount=str_replace('<select ','<select data-daily-count="folder" ',selectField('Folder posts per day','folder_posts_per_day',['1'=>'1 post','2'=>'2 posts','3'=>'3 posts'],$edit['folder_posts_per_day']??1));
    $storyMode=str_replace('<select ','<select data-daily-mode="story" ',selectField('Automatic Facebook Stories','story_daily_mode',['off'=>'Off','schedule'=>'Create and publish automatically'],$edit['story_daily_mode']??'off'));
    $storySource=str_replace('<select ','<select data-daily-dependent="story" ',selectField('Story image source','story_source',['server_folder'=>'Image folder','woocommerce'=>'WooCommerce products'],$edit['story_source']??'server_folder'));
    $storyCount=str_replace('<select ','<select data-daily-count="story" ',selectField('Stories per day','story_posts_per_day',['1'=>'1 story','2'=>'2 stories','3'=>'3 stories'],$edit['story_posts_per_day']??1));
    $brandingPositions=['top-left'=>'Top left','top-center'=>'Top centre','top-right'=>'Top right','middle-left'=>'Middle left','middle-center'=>'Middle centre','middle-right'=>'Middle right','bottom-left'=>'Bottom left','bottom-center'=>'Bottom centre','bottom-right'=>'Bottom right'];

    $pages='<section class="panel pages-list-card"><div class="section-heading"><div><h2>Connected Pages</h2><p>Choose Edit to manage an existing Page. The full setup form stays hidden until you need it.</p></div><a class="button-link" href="index.php?view=pages&new=1">+ Add new page</a></div><div class="table"><table><tr><th>Name</th><th>Facebook ID</th><th>AI provider / model</th><th>Status</th><th></th></tr>';
    foreach($rows as $row)$pages.='<tr><td>'.e(displayText($row['page_name'])).'</td><td>'.e($row['facebook_page_id']).'</td><td>'.e(aiLabel($row)).'</td><td>'.badge($row['status']).'</td><td><a href="index.php?view=pages&edit='.(int)$row['id'].'">Edit</a></td></tr>';
    $pages.='</table></div></section>';
    if(!$showForm)return $pages;

    $instagramChecked=!empty($edit['instagram_enabled'])?' checked':'';
    $identity='<section class="panel page-config-card page-identity-card"><h2>'.($edit?'Edit':'Add').' Facebook Page</h2>'.field('Page name','page_name',$edit['page_name']??'',true).field('Facebook Page ID','facebook_page_id',$edit['facebook_page_id']??'',true).field('Page access token','access_token','',false,'password','Leave blank to keep the current token').field('Timezone','timezone',$edit['timezone']??'Asia/Dhaka',true).'<div class="sub-settings"><h3>Instagram cross-posting</h3><label class="check-row"><input type="checkbox" name="instagram_enabled" value="1"'.$instagramChecked.'> Publish Facebook feed posts and Stories to Instagram too</label>'.field('Instagram Business Account / User ID','instagram_user_id',$edit['instagram_user_id']??'',false,'text','Facebook Login: connected Business Account ID. Instagram Login: numeric IG User ID supplied by that setup—not a username or Facebook Page ID.').field('Instagram Login access token (optional)','instagram_access_token','',false,'password','Enter the token generated with instagram_business_basic and instagram_business_content_publish. Leave blank only when using the connected Facebook Page token.').(!empty($edit['encrypted_instagram_access_token'])?'<label class="check-row"><input type="checkbox" name="clear_instagram_access_token" value="1"> Clear saved Instagram token and use the Facebook Page token</label>':'').field('Instagram caption maximum','instagram_caption_max',$edit['instagram_caption_max']??500,true,'number','A separate short feed caption is created automatically; allowed 120–1000 characters.').'<p class="settings-note">Instagram Login uses its own token, IG User ID and graph.instagram.com. Facebook Login uses the Page token and resolves the connected Instagram Business ID. Feed posts use the first JPEG image; Stories use the same Story image.</p></div></section>';
    $ai='<section class="panel page-config-card settings-card-ai page-ai-card"><h2>AI caption provider</h2>'.selectField('AI provider','ai_provider',['none'=>'None','openai'=>'OpenAI','anthropic'=>'Claude (Anthropic)','qwen'=>'Qwen (Alibaba Cloud)'],$edit['ai_provider']??'none').field('AI model','ai_model',$edit['ai_model']??'',false,'text','Defaults: gpt-5-mini, claude-sonnet-4-6, or qwen-plus').field('AI API key','ai_api_key','',false,'password','Enter the selected provider key; leave blank only to keep the same provider/key').areaRows('System prompt — edit this to control the AI writing style','ai_prompt',$promptValue,10).areaRows('Natural caption examples — add 2 or 3 captions you wrote','caption_examples',$edit['caption_examples']??'',10).'<p class="settings-note">Paste your best real captions here, separated by a blank line. AI follows their voice and pacing but cannot copy facts or contact details from them.</p>'.areaRows('Per-page post instruction / call to action','post_instruction',$edit['post_instruction']??'',4).'<p class="settings-note">Example: Call or WhatsApp us at +1 202 555 0142. AI keeps the supplied number, while varying the CTA sentence naturally in every new Facebook and Instagram feed caption.</p>'.area('Default hashtags','default_hashtags',$edit['default_hashtags']??'').area('Banned or sensitive words — comma or one per line','banned_words',$edit['banned_words']??'').'</section>';
    $woo='<section class="panel page-config-card settings-card-woo page-woo-card"><h2>WooCommerce area</h2>'.field('Store URL','woo_store_url',$edit['woo_store_url']??'',false,'url','Leave blank for a folder-based Page').field('Consumer key','woo_consumer_key','',false,'password','Read-only key; leave blank to keep current').field('Consumer secret','woo_consumer_secret','',false,'password','Leave blank to keep current').'<input type="hidden" name="woo_repeat_days" value="15"><p class="settings-note"><strong>Product repeat protection:</strong> 15 days.</p>'.selectField('WooCommerce image batch mode','woo_image_batch_mode',['half'=>'50% first, remaining 50% next time','fixed'=>'Fixed maximum images; continue next time'],$edit['woo_image_batch_mode']??'half').field('Maximum images per post','woo_images_per_post',$edit['woo_images_per_post']??7,true,'number','Used in Fixed mode. Allowed: 1–10 images.').$wooMode.$wooCount.dailyTimeField('WooCommerce post time 1','woo_post_time',substr((string)($edit['woo_post_time']??'10:00'),0,5),'Daily time slot 1; Asia/Dhaka','woo',1).dailyTimeField('WooCommerce post time 2','woo_post_time_2',substr((string)($edit['woo_post_time_2']??'15:00'),0,5),'Used when posts per day is 2 or 3','woo',2).dailyTimeField('WooCommerce post time 3','woo_post_time_3',substr((string)($edit['woo_post_time_3']??'20:00'),0,5),'Used when posts per day is 3','woo',3).'</section>';
    $brandingChecked=!empty($edit['folder_branding_enabled'])?' checked':'';
    $currentLogo=!empty($edit['folder_logo_path'])&&$edit?'<img id="branding-logo-preview" src="index.php?view=branding-logo&amp;page='.(int)$edit['id'].'" alt="Current Page logo">':'<img id="branding-logo-preview" alt="Selected Page logo" hidden>';
    $branding='<div class="sub-settings branding-settings" id="folder-branding-settings"><h3>Automatic image branding</h3><label class="check-row"><input type="checkbox" name="folder_branding_enabled" value="1"'.$brandingChecked.'> Add this branding to every folder image used in Feed posts and Stories</label><label>Page logo<input type="file" name="folder_logo" id="folder-logo-input" accept="image/png,image/jpeg,image/webp"><small>Transparent PNG or WebP works best. Maximum 5 MB. The original folder image is never changed.</small></label>'.(!empty($edit['folder_logo_path'])?'<label class="check-row"><input type="checkbox" name="remove_folder_logo" value="1"> Remove the saved logo</label>':'').'<div class="branding-control-grid">'.rangeField('Logo size (%)','folder_logo_size_pct',$edit['folder_logo_size_pct']??12,5,40,'5–40% of image width').selectField('Logo position','folder_logo_position',$brandingPositions,$edit['folder_logo_position']??'top-right').areaRows('Branding text — one line per pill','folder_brand_text',$edit['folder_brand_text']??'',4).'<p class="branding-text-help">Each non-empty line becomes a separate pill, displayed side by side in one row. Up to 300 characters. You can paste multiple monochrome Unicode icons, such as ☎ ✆ ◎ 🌐 📞 📍. Combined emoji sequences are not supported.</p>'.selectField('Text position','folder_text_position',$brandingPositions,$edit['folder_text_position']??'bottom-center').colorField('Text & border color','folder_text_color',$edit['folder_text_color']??'#FFFFFF').colorField('Background color','folder_text_background_color',$edit['folder_text_background_color']??'#050F19').'<label class="check-row branding-background-toggle"><input type="checkbox" name="folder_text_background_enabled" value="1"'.(!isset($edit['folder_text_background_enabled'])||!empty($edit['folder_text_background_enabled'])?' checked':'').'> Show color behind the text</label>'.rangeField('Text & border opacity (%)','folder_text_opacity',$edit['folder_text_opacity']??90,10,100,'10–100%; controls text, icons and border').rangeField('Background opacity (%)','folder_text_background_opacity',$edit['folder_text_background_opacity']??35,0,100,'0% transparent; 100% solid fill inside the border').rangeField('Text size (%)','folder_text_size_pct',$edit['folder_text_size_pct']??3,1,8,'1–8% of the shorter image side; shrinks to fit').'</div><div class="branding-preview" id="branding-preview" aria-label="Branding position preview"><div class="branding-preview-photo"></div>'.$currentLogo.'<div id="branding-text-preview" aria-live="polite"></div></div><p class="settings-note"><strong>No API cost:</strong> PHP GD creates one cached JPEG locally. Retries reuse that same file, and original images remain untouched.</p></div>';
    $folder='<section class="panel page-config-card settings-card-folder page-folder-card"><h2>Image folder area</h2>'.field('Folder source name','folder_source_slug',$edit['folder_source_slug']??'',false,'text','Example: my-second-page → page-content/my-second-page/').$branding.'<p class="settings-note"><strong>Folder rotation:</strong> One post is taken from each folder in order. After the last folder, rotation returns to the first folder and uses its next unused image batch. When every image has been consumed, a fresh cycle starts.</p>'.$folderMode.$folderCount.selectField('Folder images per post','folder_images_per_post',['1'=>'1 image','2'=>'2 images','3'=>'3 images','4'=>'4 images','5'=>'5 images','6'=>'6 images','7'=>'7 images','8'=>'8 images','9'=>'9 images','10'=>'10 images'],$edit['folder_images_per_post']??10).dailyTimeField('Folder post time 1','folder_post_time',substr((string)($edit['folder_post_time']??'11:00'),0,5),'Daily time slot 1; Asia/Dhaka','folder',1).dailyTimeField('Folder post time 2','folder_post_time_2',substr((string)($edit['folder_post_time_2']??'16:00'),0,5),'Used when posts per day is 2 or 3','folder',2).dailyTimeField('Folder post time 3','folder_post_time_3',substr((string)($edit['folder_post_time_3']??'21:00'),0,5),'Used when posts per day is 3','folder',3).'</section>';
    $story='<section class="panel page-config-card settings-card-story page-story-card"><h2>Story settings</h2>'.$storyMode.$storySource.$storyCount.dailyTimeField('Story time 1','story_post_time',substr((string)($edit['story_post_time']??'09:00'),0,5),'Daily story slot 1; Asia/Dhaka','story',1).dailyTimeField('Story time 2','story_post_time_2',substr((string)($edit['story_post_time_2']??'15:00'),0,5),'Used when stories per day is 2 or 3','story',2).dailyTimeField('Story time 3','story_post_time_3',substr((string)($edit['story_post_time_3']??'21:00'),0,5),'Used when stories per day is 3','story',3).'<p class="settings-note">Each Story uses one image and no AI tokens. A vertical 9:16 image gives the best result. Folder Stories share the tracked folder-and-image rotation, with no 15-day waiting period.</p></section>';
    $actions='<section class="panel page-save-card">'.selectField('Page status','status',['active'=>'Active','inactive'=>'Inactive'],$edit['status']??'active').'<button type="submit">'.($edit?'Update Facebook Page':'Add Facebook Page').'</button>'.($edit?' <a class="cancel-link" href="index.php?view=pages">Cancel editing</a>':'').'</section>';
    $hidden=Csrf::input().($edit?'<input type="hidden" name="id" value="'.(int)$edit['id'].'">':'');
    $editorHeading='<div class="page-editor-heading"><div><span class="eyebrow">Page setup</span><h2>'.($edit?'Edit '.e(displayText($edit['page_name'])):'Add a new Facebook Page').'</h2><p>Identity, AI, content source and Story settings are grouped below.</p></div><a class="cancel-link" href="index.php?view=pages">Close setup</a></div>';
    return $pages.'<section class="page-editor-shell">'.$editorHeading.'<form method="post" enctype="multipart/form-data" class="page-settings-form">'.$hidden.'<div class="page-settings-columns"><div class="page-settings-column">'.$identity.$ai.'</div><div class="page-settings-column">'.$woo.$folder.$story.'</div></div>'.$actions.'</form></section>';
}

function connectionsScreen(PDO $db):string
{
    if(array_filter((new DatabaseUpgradeService($db))->pending(),static fn(string $change):bool=>str_starts_with($change,'table.social_connections')||str_starts_with($change,'social_connections.'))!==[])return '<section class="panel"><h2>Connections update required</h2><p>Run Database Upgrade once, then return here to add LinkedIn and TikTok accounts.</p><a class="button-link" href="index.php?view=upgrade">Open Database Upgrade</a></section>';
    $platform=in_array($_GET['platform']??'linkedin',['linkedin','tiktok'],true)?(string)($_GET['platform']??'linkedin'):'linkedin';
    $edit=null;if(isset($_GET['edit'])){$q=$db->prepare('SELECT * FROM social_connections WHERE id=?');$q->execute([(int)$_GET['edit']]);$edit=$q->fetch();if($edit)$platform=(string)$edit['platform'];}
    $pages=$db->query('SELECT id,page_name FROM social_pages ORDER BY page_name')->fetchAll();$pageOptions=[];foreach($pages as $page)$pageOptions[(int)$page['id']]=displayText($page['page_name']);
    if($pageOptions===[])return '<section class="panel"><h2>Add a Meta brand Page first</h2><p>LinkedIn and TikTok accounts are attached to a Page so they can share its content source, AI settings and schedule.</p><a class="button-link" href="index.php?view=pages&new=1">Add Page</a></section>';
    $rows=$db->query('SELECT c.*,p.page_name FROM social_connections c JOIN social_pages p ON p.id=c.page_id ORDER BY c.platform,c.display_name')->fetchAll();
    $count=['linkedin'=>0,'tiktok'=>0];foreach($rows as $row)$count[(string)$row['platform']]++;
    $html='<div class="connection-overview"><section class="connection-hero"><span class="eyebrow">Publishing destinations</span><h2>Connect every brand in one place</h2><p>Facebook and Instagram remain under Pages. LinkedIn organizations and TikTok accounts live here, with independent delivery and retry status.</p><div class="cost-guard"><strong>One caption, many destinations</strong><span>Publishing and retries reuse the saved caption—no extra AI request.</span></div></section><div class="connection-stat linkedin"><strong>'.$count['linkedin'].'</strong><span>LinkedIn Pages</span></div><div class="connection-stat tiktok"><strong>'.$count['tiktok'].'</strong><span>TikTok accounts</span></div></div>';
    $html.='<nav class="tabs connection-tabs"><a class="'.($platform==='linkedin'?'active':'').'" href="index.php?view=connections&platform=linkedin">LinkedIn</a><a class="'.($platform==='tiktok'?'active':'').'" href="index.php?view=connections&platform=tiktok">TikTok</a></nav>';
    $connectedPageIds=[];foreach($rows as $row){if((string)$row['platform']===$platform)$connectedPageIds[(int)$row['page_id']]=true;}
    $availablePageOptions=array_filter($pageOptions,static fn(string $name,int $id):bool=>!isset($connectedPageIds[$id]),ARRAY_FILTER_USE_BOTH);
    $newBrandLink='<p class="settings-note">Businessটি dropdown-এ নেই? <a href="index.php?view=pages&amp;new=1">প্রথমে নতুন Brand/content Page যোগ করুন</a>, তারপর এখানে ফিরে account connect করুন।</p>';
    if($platform==='tiktok'&&!$edit){
        $oauthReady=trim((string)config('tiktok.client_key',''))!==''&&trim((string)config('tiktok.client_secret',''))!==''&&trim((string)config('tiktok.redirect_uri',''))!=='';
        $connectForm=$availablePageOptions===[]?'<div class="oauth-config-warning"><strong>Every Brand/content Page already has a TikTok account.</strong><span>Add another Brand/content Page before connecting another TikTok account.</span></div>':($oauthReady?'<form method="post" action="tiktok-connect.php">'.Csrf::input().selectField('Business / Brand content Page','page_id',$availablePageOptions,array_key_first($availablePageOptions)).'<button>Connect another TikTok account</button>'.$newBrandLink.'</form>':'<div class="oauth-config-warning"><strong>TikTok app credentials are not configured yet.</strong><span>Add the three TIKTOK values to the live .env file, then reload this page.</span></div>');
        $html.='<section class="panel tiktok-oauth-card"><div class="tiktok-oauth-copy"><span class="connection-icon">♪</span><div><span class="eyebrow">Add a publishing account</span><h2>Connect a TikTok account</h2><p>Select the business that owns this TikTok account, then sign in to that account and approve publishing permission. Repeat this process for every business.</p><div class="connection-flags"><span>One account per business</span><span>Encrypted tokens</span><span>Testing starts as Only me</span></div></div></div>'.$connectForm.'<details class="manual-token-details"><summary>Advanced: add tokens manually</summary><p>Use the manual form only when OAuth cannot be used or you already have the Open ID and both tokens.</p><a class="cancel-link" href="index.php?view=connections&platform=tiktok&new=1">Open manual TikTok form</a></details></section>';
    }
    if($platform==='linkedin'&&!$edit){
        $oauthReady=trim((string)config('linkedin.client_id',''))!==''&&trim((string)config('linkedin.client_secret',''))!==''&&trim((string)config('linkedin.redirect_uri',''))!=='';
        $connectForm=$availablePageOptions===[]?'<div class="oauth-config-warning"><strong>Every Brand/content Page already has a LinkedIn Company Page.</strong><span>Add another Brand/content Page before connecting another LinkedIn Page.</span></div>':($oauthReady?'<form method="post" action="linkedin-connect.php">'.Csrf::input().selectField('Business / Brand content Page','page_id',$availablePageOptions,array_key_first($availablePageOptions)).'<div class="form-grid">'.field('LinkedIn Company Page name','display_name','',true,'text','Example: Your Business Name').field('LinkedIn Organization ID','organization_id','',true,'text','Numbers only; copy it from the Company Page admin URL.').'</div><button>Connect another LinkedIn Company Page</button>'.$newBrandLink.'</form>':'<div class="oauth-config-warning"><strong>LinkedIn app credentials are not configured yet.</strong><span>Add LINKEDIN_CLIENT_ID, LINKEDIN_CLIENT_SECRET and LINKEDIN_REDIRECT_URI to the live .env file.</span></div>');
        $html.='<section class="panel tiktok-oauth-card"><div class="tiktok-oauth-copy"><span class="connection-icon">in</span><div><span class="eyebrow">Add a publishing account</span><h2>Connect a LinkedIn Company Page</h2><p>Select the business that owns this Company Page, enter its Page identity, then approve organization publishing. Repeat this process for every business.</p><div class="connection-flags"><span>One Company Page per business</span><span>Encrypted tokens</span><span>Company Page posts</span></div></div></div>'.$connectForm.'<details class="manual-token-details"><summary>Advanced: add tokens manually</summary><p>Use this only when OAuth is unavailable or you already have a valid organization token.</p><a class="cancel-link" href="index.php?view=connections&amp;platform=linkedin&amp;new=1">Open manual LinkedIn form</a></details></section>';
    }
    $filtered=array_values(array_filter($rows,static fn(array $row):bool=>$row['platform']===$platform));
    $label=$platform==='linkedin'?'LinkedIn Company Pages':'TikTok accounts';$idLabel=$platform==='linkedin'?'Organization ID':'TikTok Open ID';
    $html.='<section class="panel connection-list"><div class="section-heading"><div><span class="eyebrow">'.e(ucfirst($platform)).'</span><h2>'.e($label).'</h2><p>Add, edit, pause or remove a destination at any time.</p></div>'.($platform==='linkedin'?'<a class="button-link" href="index.php?view=connections&platform=linkedin&new=1">+ Add LinkedIn</a>':'').'</div>';
    if($filtered===[])$html.='<div class="empty">No '.e($platform).' connection has been added yet.</div>';
    else{$html.='<div class="connection-cards">';foreach($filtered as $row){$expires=(string)($row['token_expires_at']??'');$expiry=$expires!==''?'Token expiry: '.date('M j, Y g:i A',strtotime($expires)):'Expiry date not saved';$html.='<article class="connection-card platform-'.e($platform).'"><div class="connection-icon">'.($platform==='linkedin'?'in':'♪').'</div><div class="connection-card-copy"><strong>'.e(displayText($row['display_name'])).'</strong><span>'.e(displayText($row['page_name'])).' · '.e($idLabel).' '.e($row['account_id']).'</span><small>'.e($expiry).'</small><div class="connection-flags">'.badge($row['status']).(!empty($row['auto_publish_feed'])?'<span>Feed on</span>':'<span>Feed off</span>').(!empty($row['auto_publish_video'])?'<span>Video on</span>':'<span>Video off</span>').'</div></div><div class="connection-actions"><a href="index.php?view=connections&platform='.e($platform).'&edit='.(int)$row['id'].'">Edit</a><form method="post" onsubmit="return confirm(\'Remove this connection? Existing published posts will remain.\')">'.Csrf::input().'<input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="'.(int)$row['id'].'"><button class="danger">Remove</button></form></div></article>';}$html.='</div>';}
    $html.='</section>';
    if(isset($_GET['new'])||$edit){
        $selectedPlatform=(string)($edit['platform']??$platform);$isTikTok=$selectedPlatform==='tiktok';
        $scheduleFields=$isTikTok?'<div class="platform-schedule-box"><span class="eyebrow">TikTok feed schedule</span><h3>Publish every Facebook feed post later</h3><p>Every Facebook feed post is reused. TikTok waits this long after the Facebook schedule; no post or AI caption is created again.</p>'.field('Delay after Facebook (minutes)','feed_delay_minutes',$edit['feed_delay_minutes']??60,true,'number','Example: 60 publishes each TikTok post one hour after its Facebook time. Maximum 10080 minutes (7 days).').'<input type="hidden" name="weekly_post_limit" value="1"><input type="hidden" name="weekly_publish_weekday" value="1"><input type="hidden" name="weekly_publish_time" value="10:00"></div>':'<div class="platform-schedule-box"><span class="eyebrow">LinkedIn feed schedule</span><h3>Weekly publishing limit</h3><p>The first eligible Facebook feed post is reused for each weekly slot. Remaining Facebook posts are skipped for LinkedIn without creating new content.</p><div class="form-grid">'.selectField('Posts per week','weekly_post_limit',['1'=>'1 post','2'=>'2 posts','3'=>'3 posts','4'=>'4 posts','5'=>'5 posts','6'=>'6 posts','7'=>'7 posts'],$edit['weekly_post_limit']??1).selectField('Publishing day','weekly_publish_weekday',[1=>'Monday',2=>'Tuesday',3=>'Wednesday',4=>'Thursday',5=>'Friday',6=>'Saturday',7=>'Sunday'],$edit['weekly_publish_weekday']??1).'</div>'.field('LinkedIn publish time','weekly_publish_time',substr((string)($edit['weekly_publish_time']??'10:00'),0,5),true,'time','Asia/Dhaka time. Multiple weekly slots are spaced safely from this time.').'<input type="hidden" name="feed_delay_minutes" value="60"></div>';
        $html.='<section class="panel connection-editor platform-'.e($selectedPlatform).'"><div class="section-heading"><div><span class="eyebrow">Secure account setup</span><h2>'.($edit?'Edit ':'Add ').e(ucfirst($selectedPlatform)).'</h2><p>Tokens are encrypted before they are stored. Leave token fields blank while editing to keep their current values.</p></div><a class="cancel-link" href="index.php?view=connections&platform='.e($selectedPlatform).'">Close</a></div><form method="post">'.Csrf::input().($edit?'<input type="hidden" name="id" value="'.(int)$edit['id'].'">':'').'<input type="hidden" name="platform" value="'.e($selectedPlatform).'"><div class="form-grid">'.selectField('Brand / content Page','page_id',$pageOptions,$edit['page_id']??array_key_first($pageOptions)).field('Account display name','display_name',$edit['display_name']??'',true,'text',$isTikTok?'Example: Example Studio TikTok':'Example: Example Studio LinkedIn').'</div><div class="form-grid">'.field($isTikTok?'TikTok Open ID':'LinkedIn Organization ID','account_id',$edit['account_id']??'',true,'text',$isTikTok?'Returned by TikTok OAuth; this is not the @username.':'Numbers only; found in the LinkedIn organization URL/API.').field('Token expiry (recommended)','token_expires_at',!empty($edit['token_expires_at'])?date('Y-m-d\TH:i',strtotime((string)$edit['token_expires_at'])):'',false,'datetime-local','Used to warn before publishing stops.').'</div><div class="secret-grid">'.field('Access token','access_token','',!$edit,'password','Paste directly here; it is encrypted at rest.').field('Refresh token (if issued)','refresh_token','',false,'password','TikTok normally issues one. LinkedIn availability depends on approved access.').'</div><div class="connection-options"><label class="check-row"><input type="checkbox" name="auto_publish_feed" value="1"'.(!isset($edit['auto_publish_feed'])||!empty($edit['auto_publish_feed'])?' checked':'').'><span><strong>Automatic feed/photo posts</strong><small>New scheduled feed posts include this destination.</small></span></label><label class="check-row"><input type="checkbox" name="auto_publish_video" value="1"'.(!isset($edit['auto_publish_video'])||!empty($edit['auto_publish_video'])?' checked':'').'><span><strong>Automatic video posts</strong><small>Manual and scheduled video jobs may include this destination.</small></span></label></div>'.$scheduleFields.($isTikTok?selectField('Default TikTok privacy','default_privacy',['SELF_ONLY'=>'Only me (safe for testing)','PUBLIC_TO_EVERYONE'=>'Public','MUTUAL_FOLLOW_FRIENDS'=>'Friends','FOLLOWER_OF_CREATOR'=>'Followers'],$edit['default_privacy']??'SELF_ONLY'):'<input type="hidden" name="default_privacy" value="PUBLIC_TO_EVERYONE">').areaRows('Platform-specific suffix (optional)','caption_suffix',$edit['caption_suffix']??'',3).'<p class="settings-note">Example: a LinkedIn website line or TikTok-specific hashtags. This is added locally and does not call AI.</p>'.selectField('Connection status','status',['active'=>'Active','inactive'=>'Paused'],$edit['status']??'active').'<div class="inline"><button>'.($edit?'Update connection':'Save connection').'</button><a class="cancel-link" href="index.php?view=connections&platform='.e($selectedPlatform).'">Cancel</a></div></form></section>';
    }
    if(isset($selectedPlatform)&&$selectedPlatform==='tiktok'){
        $controls='<div class="tiktok-interaction-settings"><h3>TikTok interaction controls</h3><div class="connection-options"><label class="check-row"><input type="checkbox" name="allow_comments" value="1"'.(!isset($edit['allow_comments'])||!empty($edit['allow_comments'])?' checked':'').'><span><strong>Allow comments</strong><small>Subject to creator account settings.</small></span></label><label class="check-row"><input type="checkbox" name="allow_duet" value="1"'.(!isset($edit['allow_duet'])||!empty($edit['allow_duet'])?' checked':'').'><span><strong>Allow Duet</strong><small>Video posts only.</small></span></label><label class="check-row"><input type="checkbox" name="allow_stitch" value="1"'.(!isset($edit['allow_stitch'])||!empty($edit['allow_stitch'])?' checked':'').'><span><strong>Allow Stitch</strong><small>Video posts only.</small></span></label></div><p class="settings-note">Enable automatic publishing only with the TikTok account owner’s express consent. The app checks creator privacy choices again before every upload.</p></div>';
        $html=str_replace('<label>Platform-specific suffix',$controls.'<label>Platform-specific suffix',$html);
    }
    return $html;
}

function contentScreen(PDO $db): string
{
    $pages=$db->query('SELECT id,page_name,folder_source_slug FROM social_pages WHERE status="active" ORDER BY page_name')->fetchAll();
    $edit=null;
    if(isset($_GET['edit'])){$q=$db->prepare('SELECT * FROM content_items WHERE id=?');$q->execute([(int)$_GET['edit']]);$edit=$q->fetch();}
    $tab=in_array($_GET['tab']??'library',['library','tools','manual'],true)?(string)($_GET['tab']??'library'):'library';
    if(is_array($edit))$tab='manual';
    $options=[];foreach($pages as $p)$options[$p['id']]=displayText($p['page_name']);
    $folderPageOptions='';$folderOptions='';$firstFolderPage=(int)($pages[0]['id']??0);
    foreach($pages as $p){$pageId=(int)$p['id'];$folderPageOptions.='<option value="'.$pageId.'">'.e(displayText($p['page_name'])).'</option>';try{$names=FolderContentService::listFolders($p);}catch(Throwable){$names=[];}foreach($names as $name)$folderOptions.='<option value="'.e($name).'" data-page="'.$pageId.'"'.($pageId===$firstFolderPage?'':' hidden disabled').'>'.e($name).'</option>';}
    if($folderOptions==='')$folderOptions='<option value="" disabled>No server folders found</option>';
    $selectedFolderTool='<div class="selected-folder-tool"><h3>Prepare a specific folder</h3><p class="settings-note">Choose any folder manually. Its next unused image batch will be prepared without removing the automatic next-folder tool.</p><form method="post">'.Csrf::input().'<input type="hidden" name="action" value="folder_selected"><label>Content Page<select id="selected-folder-page" name="page_id" required>'.$folderPageOptions.'</select></label><label>Server folder<select id="selected-folder-name" name="folder_name" required>'.$folderOptions.'</select></label><button>Prepare selected folder</button></form></div>';
    $html='<nav class="tabs content-tabs" aria-label="Content sections"><a class="'.($tab==='library'?'active':'').'" href="index.php?view=content&tab=library">Content Library</a><a class="'.($tab==='tools'?'active':'').'" href="index.php?view=content&tab=tools">Automation tools</a><a class="'.($tab==='manual'?'active':'').'" href="index.php?view=content&tab=manual">+ Add manual product/service</a></nav>';
    if($tab==='tools'){
        $stagedOld=trim((string)($_SESSION['old']['staged_video_path']??''));$stagedReady=false;
        if($stagedOld!==''&&preg_match('/^page-\d+\/[A-Za-z0-9._-]+$/',$stagedOld)){try{VideoUploadService::resolve($stagedOld);$stagedReady=true;}catch(Throwable){$stagedOld='';}}
        $videoFile='<label class="video-file-field">Video file<div class="video-file-row"><input id="video-file-input" type="file" name="video_file" accept="video/mp4,video/quicktime,.mp4,.mov"'.($stagedReady?' disabled':' required').'><span id="video-upload-state" class="video-upload-state'.($stagedReady?' ready':'').'" aria-live="polite">'.($stagedReady?'Ready to queue · 100%':'Choose a video to upload').'</span></div><span class="video-upload-progress" aria-hidden="true"><span id="video-upload-progress"'.($stagedReady?' style="width:100%"':'').'></span></span><small>MP4 or MOV, maximum 200 MB. Wait for “Ready to queue” before continuing.</small></label><input id="staged-video-path" type="hidden" name="staged_video_path" value="'.e($stagedOld).'">';
        $video='<section class="panel video-upload-tool"><div class="section-heading"><div><span class="eyebrow">Multi-platform video publishing</span><h2>Upload a video or Reel</h2><p>Upload once and send the saved file to any connected destination. No AI call is made when you write the caption yourself.</p></div><span class="no-ai-pill">0 AI calls</span></div><form id="video-upload-form" method="post" enctype="multipart/form-data">'.Csrf::input().'<input type="hidden" name="action" value="video_upload"><div class="form-grid">'.selectField('Brand / Meta Page','page_id',$options,$_SESSION['old']['page_id']??'').selectField('Facebook format','video_type',['reel'=>'Short vertical video / Reel','video'=>'Regular video'],$_SESSION['old']['video_type']??'reel').'</div>'.$videoFile.field('Title','video_title',$_SESSION['old']['video_title']??'',true,'text','Used as the video title where the platform supports one.').areaRows('Description / caption','video_caption',$_SESSION['old']['video_caption']??'',5).area('Hashtags','video_hashtags',$_SESSION['old']['video_hashtags']??'').field('Call to action','video_cta',$_SESSION['old']['video_cta']??'',false,'text','Optional: Call or WhatsApp us at 01XXXXXXXXX').'<div class="video-destinations"><label class="check-row"><input type="checkbox" name="publish_to_facebook" value="1"'.(!isset($_SESSION['old'])||isset($_SESSION['old']['publish_to_facebook'])?' checked':'').'><span><strong>Facebook</strong><small>Video or Reel</small></span></label><label class="check-row"><input type="checkbox" name="publish_to_instagram" value="1"'.(isset($_SESSION['old']['publish_to_instagram'])?' checked':'').'><span><strong>Instagram</strong><small>Published as Reel</small></span></label><label class="check-row"><input type="checkbox" name="publish_to_linkedin" value="1"'.(isset($_SESSION['old']['publish_to_linkedin'])?' checked':'').'><span><strong>LinkedIn</strong><small>Company Page video</small></span></label><label class="check-row"><input type="checkbox" name="publish_to_tiktok" value="1"'.(isset($_SESSION['old']['publish_to_tiktok'])?' checked':'').'><span><strong>TikTok</strong><small>Direct video post</small></span></label></div>'.field('Schedule date and time','video_scheduled_at',$_SESSION['old']['video_scheduled_at']??'',false,'datetime-local','Only required when you choose Schedule. Asia/Dhaka time.').'<div class="inline"><button class="video-queue-button" name="video_delivery" value="now"'.($stagedReady?'':' disabled').'>Queue for publishing now</button><button class="secondary video-queue-button" name="video_delivery" value="schedule"'.($stagedReady?'':' disabled').'>Schedule video</button></div></form></section>';
        return $html.'<div class="content-tools-grid"><section class="panel tool-card tool-woo"><span class="eyebrow">WooCommerce</span><h2>Prepare a product</h2><p>Select one eligible random product. It cannot repeat within 15 days and its next image batch is used when it returns.</p><form method="post">'.Csrf::input().'<input type="hidden" name="action" value="woo_next">'.selectField('Facebook Page','page_id',$options,'').'<button>Prepare random product</button></form></section><section class="panel tool-card tool-folder"><span class="eyebrow">Server folders</span><h2>Prepare a folder</h2><p>New folders receive priority once. The tracked rotation then continues with unused image batches.</p><form method="post">'.Csrf::input().'<input type="hidden" name="action" value="folder_next">'.selectField('Facebook Page','page_id',$options,'').'<button>Prepare next folder</button></form>'.$selectedFolderTool.'</section><section class="panel tool-card tool-story"><span class="eyebrow">Stories</span><h2>Facebook + Instagram Story</h2><p>Use one new image from the chosen source. Instagram receives it when cross-posting is enabled.</p><form method="post">'.Csrf::input().'<input type="hidden" name="action" value="story_manual">'.selectField('Facebook Page','page_id',$options,'').selectField('Story image source','story_source',['server_folder'=>'Server image folder','woocommerce'=>'WooCommerce product'],'server_folder').'<div class="inline"><button name="story_delivery" value="draft">Save draft</button><button class="secondary" name="story_delivery" value="queue">Queue now</button></div></form></section></div>'.$video;
    }
    if($tab==='manual')return $html.'<section class="panel manual-content-panel"><div class="section-heading"><div><span class="eyebrow">Manual content</span><h2>'.($edit?'Edit product/service':'Add manual product/service').'</h2><p>Add a one-off product or service that does not come from WooCommerce or a server folder.</p></div></div><form method="post" class="manual-content-form">'.Csrf::input().($edit?'<input type="hidden" name="id" value="'.(int)$edit['id'].'">':'').'<div class="form-grid">'.selectField('Facebook Page','page_id',$options,$edit['page_id']??'').selectField('Type','content_type',['product'=>'Product','service'=>'Service'],$edit['content_type']??'product').'</div>'.field('Title','title',$edit['title']??'',true).area('Description','description',$edit['description']??'').'<div class="form-grid">'.field('Price (exact, optional)','price',$edit['price']??'').field('Category','category',$edit['category']??'').'</div>'.area('Features','features',$edit['features']??'').field('Source URL','source_url',$edit['source_url']??'',false,'url').field('Public HTTPS image URL','image_url',$edit['image_url']??'',true,'url').selectField('Status','status',['active'=>'Active','inactive'=>'Inactive'],$edit['status']??'active').'<div class="inline"><button>'.($edit?'Update':'Save').' Content</button>'.($edit?'<a class="cancel-link" href="index.php?view=content&tab=library">Cancel editing</a>':'').'</div></form></section>';
    $filterPage=max(0,(int)($_GET['content_page']??0));$filterSource=in_array($_GET['source']??'all',['all','manual','woocommerce','server_folder'],true)?(string)($_GET['source']??'all'):'all';$where=[];$params=[];
    if($filterPage>0){$where[]='c.page_id=?';$params[]=$filterPage;}if($filterSource!=='all'){$where[]='c.source_type=?';$params[]=$filterSource;}
    $sql='SELECT c.*,p.page_name FROM content_items c JOIN social_pages p ON p.id=c.page_id'.($where?' WHERE '.implode(' AND ',$where):'').' ORDER BY c.id DESC LIMIT 100';$q=$db->prepare($sql);$q->execute($params);$items=$q->fetchAll();
    $pageFilterOptions=['0'=>'All Pages']+$options;$sourceOptions=['all'=>'All sources','manual'=>'Manual','woocommerce'=>'WooCommerce','server_folder'=>'Server folder'];
    $html.='<section class="panel content-library"><div class="section-heading"><div><span class="eyebrow">Organized library</span><h2>Content Library</h2><p>Existing WooCommerce products and tracked folders are updated—not duplicated. Only new products, new folders and manual entries add new rows.</p></div><span class="count-pill">Showing '.count($items).' of latest 100</span></div><form method="get" class="content-filter"><input type="hidden" name="view" value="content"><input type="hidden" name="tab" value="library">'.selectField('Facebook Page','content_page',$pageFilterOptions,(string)$filterPage).selectField('Source','source',$sourceOptions,$filterSource).'<button>Apply filters</button><a class="cancel-link" href="index.php?view=content&tab=library">Clear</a></form><div class="table content-table"><table><tr><th>Page</th><th>Content</th><th>Source</th><th>Status</th><th></th></tr>';
    foreach($items as $r)$html.='<tr><td><strong>'.e(displayText($r['page_name'])).'</strong></td><td class="content-title-cell"><strong title="'.e(displayText($r['title'])).'">'.e(shortText(displayText($r['title']),105)).'</strong><small>#'.(int)$r['id'].' · '.e(ucwords(str_replace('_',' ',$r['content_type']))).'</small></td><td>'.e(ucwords(str_replace('_',' ',$r['source_type']))).'</td><td>'.badge($r['status']).'</td><td>'.($r['source_type']==='manual'?'<a href="index.php?view=content&tab=manual&edit='.(int)$r['id'].'">Edit</a>':'<span class="muted">Automated</span>').'</td></tr>';
    return $html.'</table></div><p class="library-note"><strong>Will content keep growing?</strong> The database keeps your content history, but this screen intentionally shows only the newest 100 matching items. Re-importing the same WooCommerce product or tracked folder updates its existing record.</p></section>';
}

function postsScreen(PDO $db): string
{
    if(in_array('social_posts.archived_at',(new DatabaseUpgradeService($db))->pending(),true))return '<div class="panel"><h1>Posts update required</h1><p>Run Database Upgrade once to enable Queue, History and Archived posts.</p><a href="index.php?view=upgrade">Open Database Upgrade</a></div>';
    $scope=in_array($_GET['scope']??'', ['queue','published','issues','history','archived'], true)?(string)$_GET['scope']:'queue';
    $pageRows=$db->query('SELECT id,page_name,instagram_enabled,folder_source_slug FROM social_pages WHERE status="active" ORDER BY page_name')->fetchAll();
    $connectionPlatforms=[];foreach($db->query('SELECT page_id,platform FROM social_connections WHERE status="active"')->fetchAll() as $connectionRow)$connectionPlatforms[(int)$connectionRow['page_id']][(string)$connectionRow['platform']]=true;
    $pageFilterId=max(0,(int)($_GET['page']??0));$validPageIds=array_map(static fn(array $page):int=>(int)$page['id'],$pageRows);if(!in_array($pageFilterId,$validPageIds,true))$pageFilterId=$validPageIds[0]??0;
    $items=$db->query('SELECT c.id,c.page_id,p.page_name,c.title,c.category,c.source_type FROM content_items c JOIN social_pages p ON p.id=c.page_id WHERE c.status="active" AND p.status="active" AND c.source_type<>"server_folder" AND ((SELECT COUNT(*) FROM content_items newer WHERE newer.page_id=c.page_id AND newer.status="active" AND newer.id>c.id)<3 OR c.woo_batch_images_json IS NOT NULL) ORDER BY p.page_name,c.woo_batch_images_json IS NOT NULL DESC,c.id DESC')->fetchAll();
    $trackedFolders=[];foreach($db->query('SELECT c.id,c.page_id,p.page_name,c.category FROM content_items c JOIN social_pages p ON p.id=c.page_id WHERE c.status="active" AND p.status="active" AND c.source_type="server_folder"')->fetchAll() as $folderItem)$trackedFolders[(int)$folderItem['page_id']][(string)$folderItem['category']]=$folderItem;
    foreach($pageRows as $pageRow){if(trim((string)($pageRow['folder_source_slug']??''))==='')continue;try{$folderNames=FolderContentService::listFolders($pageRow);}catch(Throwable){$folderNames=[];}foreach($folderNames as $folderName){$tracked=$trackedFolders[(int)$pageRow['id']][$folderName]??null;$items[]=['id'=>$tracked['id']??('folder:'.(int)$pageRow['id'].':'.rawurlencode($folderName)),'page_id'=>(int)$pageRow['id'],'page_name'=>$pageRow['page_name'],'title'=>$folderName,'category'=>$folderName,'source_type'=>'server_folder'];}}
    usort($items,static function(array $a,array $b):int{$page=strnatcasecmp((string)$a['page_name'],(string)$b['page_name']);if($page!==0)return $page;$folderA=($a['source_type']??'')==='server_folder';$folderB=($b['source_type']??'')==='server_folder';if($folderA!==$folderB)return $folderA?-1:1;return strnatcasecmp((string)($a['category']?:$a['title']),(string)($b['category']?:$b['title']));});
    $contentPages=[];foreach($items as $i)$contentPages[(int)$i['page_id']]=displayText($i['page_name']);$selectedContentPage=(int)(array_key_first($contentPages)??0);
    $contentPageSelect='<label>Content Page<select id="content-page-filter">';foreach($contentPages as $id=>$name){$pageInfo=null;foreach($pageRows as $candidate)if((int)$candidate['id']===$id){$pageInfo=$candidate;break;}$contentPageSelect.='<option value="'.$id.'" data-instagram="'.(!empty($pageInfo['instagram_enabled'])?'1':'0').'" data-linkedin="'.(!empty($connectionPlatforms[$id]['linkedin'])?'1':'0').'" data-tiktok="'.(!empty($connectionPlatforms[$id]['tiktok'])?'1':'0').'">'.e($name).'</option>';}$contentPageSelect.='</select></label>';
    $contentSelect='<label>Content item<select name="content_item_id" id="content-item-select" required>';foreach($items as $i){$itemLabel=($i['source_type']??'')==='server_folder'&&trim((string)($i['category']??''))!==''?(string)$i['category']:(string)$i['title'];$contentSelect.='<option value="'.e((string)$i['id']).'" data-page="'.(int)$i['page_id'].'" data-title="'.e(displayText($itemLabel)).'"'.((int)$i['page_id']===$selectedContentPage?'':' hidden').'>'.e(shortText(displayText($itemLabel),88)).'</option>';}$contentSelect.='</select></label>';
    $scopeWhere=match($scope){
        'published'=>'s.archived_at IS NULL AND s.status="published"',
        'issues'=>'s.archived_at IS NULL AND s.status IN ("cancelled","failed")',
        'history'=>'s.archived_at IS NULL AND s.status IN ("published","cancelled","failed")',
        'archived'=>'s.archived_at IS NOT NULL',
        default=>'s.archived_at IS NULL AND s.status IN ("draft","approved","scheduled","publishing")',
    };
    $where=$scopeWhere.($pageFilterId>0?' AND s.page_id='.$pageFilterId:' AND 1=0');
    $posts=$db->query('SELECT s.*,p.page_name,p.ai_provider,p.ai_model,c.title FROM social_posts s JOIN social_pages p ON p.id=s.page_id LEFT JOIN content_items c ON c.id=s.content_item_id WHERE '.$where.' ORDER BY s.id DESC LIMIT 100')->fetchAll();
    $pageCountSql=$pageFilterId>0?' AND page_id='.$pageFilterId:' AND 1=0';
    $queueCount=(int)$db->query('SELECT COUNT(*) FROM social_posts WHERE archived_at IS NULL AND status IN ("draft","approved","scheduled","publishing")'.$pageCountSql)->fetchColumn();
    $publishedCount=(int)$db->query('SELECT COUNT(*) FROM social_posts WHERE archived_at IS NULL AND status="published"'.$pageCountSql)->fetchColumn();
    $issuesCount=(int)$db->query('SELECT COUNT(*) FROM social_posts WHERE archived_at IS NULL AND status IN ("cancelled","failed")'.$pageCountSql)->fetchColumn();
    $historyCount=(int)$db->query('SELECT COUNT(*) FROM social_posts WHERE archived_at IS NULL AND status IN ("published","cancelled","failed")'.$pageCountSql)->fetchColumn();
    $archivedCount=(int)$db->query('SELECT COUNT(*) FROM social_posts WHERE archived_at IS NOT NULL'.$pageCountSql)->fetchColumn();
    $pageCounts=[];foreach($db->query('SELECT s.page_id,COUNT(*) total FROM social_posts s WHERE '.$scopeWhere.' GROUP BY s.page_id')->fetchAll() as $countRow)$pageCounts[(int)$countRow['page_id']]=(int)$countRow['total'];
    $pageParam=$pageFilterId>0?'&page='.$pageFilterId:'';
    $firstPageId=(int)(array_key_first($contentPages)??0);$firstPageInfo=null;foreach($pageRows as $candidate)if((int)$candidate['id']===$firstPageId){$firstPageInfo=$candidate;break;}
    $destinations='<div id="feed-destinations" class="video-destinations feed-destinations"><label class="check-row"><input type="checkbox" name="publish_to_facebook" value="1" data-feed-platform="facebook" checked><span><strong>Facebook</strong><small>Feed photo post</small></span></label><label class="check-row"><input type="checkbox" name="publish_to_instagram" value="1" data-feed-platform="instagram"'.(!empty($firstPageInfo['instagram_enabled'])?' checked':' disabled').'><span><strong>Instagram</strong><small>Feed carousel/photo</small></span></label><label class="check-row"><input type="checkbox" name="publish_to_tiktok" value="1" data-feed-platform="tiktok"'.(!empty($connectionPlatforms[$firstPageId]['tiktok'])?' checked':' disabled').'><span><strong>TikTok</strong><small>Photo post · Only me while under review</small></span></label><label class="check-row"><input type="checkbox" name="publish_to_linkedin" value="1" data-feed-platform="linkedin"'.(!empty($connectionPlatforms[$firstPageId]['linkedin'])?' checked':' disabled').'><span><strong>LinkedIn</strong><small>Company Page image post</small></span></label></div><p class="settings-note" id="feed-destination-note">One saved caption and image batch will be reused for every selected platform. No extra AI request.</p>';
    $html='<div class="panel post-composer"><div class="section-heading"><div><span class="eyebrow">New draft</span><h2>Create feed post or Story</h2><p>Choose one content item, then send the same saved caption and photos to every selected platform.</p></div></div><form method="post">'.Csrf::input().selectField('Post type','post_type',['feed'=>'Multi-platform feed photo post','story'=>'Facebook photo Story'],'feed').'<div class="content-picker-grid">'.$contentPageSelect.$contentSelect.'</div><div id="content-picker-preview" class="content-picker-preview" aria-live="polite"></div>'.$destinations.area('Caption (required for feed; optional for Story)','caption','').area('Hashtags (feed only)','hashtags','').field('Call to action (feed only)','call_to_action','').'<div class="inline"><button name="action" value="create">Save manual draft</button><button class="secondary" name="action" value="generate">Generate feed caption with AI</button></div></form></div><div class="panel posts-browser"><h2>Posts</h2>';
    $html.='<div class="post-filter-block"><div class="page-filter-label">Filter posts by Page</div><div class="tabs page-tabs">';
    foreach($pageRows as $page){$id=(int)$page['id'];$html.='<a class="'.($pageFilterId===$id?'active':'').'" href="index.php?view=posts&scope='.e($scope).'&page='.$id.'">'.e(displayText($page['page_name'])).' ('.($pageCounts[$id]??0).')</a>';}$html.='</div></div>';
    $html.='<div class="tabs status-tabs"><a class="scope-queue '.($scope==='queue'?'active':'').'" href="index.php?view=posts&scope=queue'.$pageParam.'">Queue ('.$queueCount.')</a><a class="scope-published '.($scope==='published'?'active':'').'" href="index.php?view=posts&scope=published'.$pageParam.'">Publish ('.$publishedCount.')</a><a class="scope-issues '.($scope==='issues'?'active':'').'" href="index.php?view=posts&scope=issues'.$pageParam.'">Cancel or Fail ('.$issuesCount.')</a><a class="scope-history '.($scope==='history'?'active':'').'" href="index.php?view=posts&scope=history'.$pageParam.'">History ('.$historyCount.')</a><a class="scope-archived '.($scope==='archived'?'active':'').'" href="index.php?view=posts&scope=archived'.$pageParam.'">Archived ('.$archivedCount.')</a></div>';
    if($posts===[])$html.='<p class="empty">No posts in this section.</p>';
    foreach($posts as $p){
        $returnPageInput='<input type="hidden" name="return_page" value="'.$pageFilterId.'">';
        $instagramBadge=!empty($p['publish_to_instagram'])?' '.badge('instagram-'.$p['instagram_status']):'';
        $linkedinBadge=!empty($p['publish_to_linkedin'])?' '.badge('linkedin-'.$p['linkedin_status']):'';
        $tiktokBadge=!empty($p['publish_to_tiktok'])?' '.badge('tiktok-'.$p['tiktok_status']):'';
        $publishedValue=(string)($p['facebook_published_at']??$p['published_at']??'');
        $publishedTime=$p['status']==='published'&&$publishedValue!==''?'<span class="publish-time">Published '.e(date('M j, Y · g:i A',strtotime($publishedValue))).'</span>':'';
        $videoPost=in_array((string)($p['post_type']??''),['video','reel'],true);$postTitle=$videoPost?(string)($p['video_title']??'Uploaded video'):(string)($p['title']??'Content removed');
        $html.='<article class="post"><div><strong>'.e(displayText($p['page_name'].' — '.$postTitle)).'</strong> '.badge($p['post_type']??'feed').' '.badge($p['status']).$instagramBadge.$linkedinBadge.$tiktokBadge.$publishedTime.' <small>'.($videoPost?'Manual video upload':((($p['post_type']??'feed')==='story')?'Facebook photo Story':'AI: '.e(aiLabel($p)))).'</small></div>'.postImagesHtml($p).'<p>'.nl2br(e($p['caption'])).'</p>'.($p['call_to_action']?'<p>'.e($p['call_to_action']).'</p>':'').'<p>'.e($p['hashtags']).'</p>'.(!empty($p['publish_to_instagram'])?'<div class="instagram-copy"><strong>Instagram caption</strong><p>'.nl2br(e((string)$p['instagram_caption'])).'</p><p>'.e((string)$p['instagram_hashtags']).'</p></div>':'');
        if(empty($p['archived_at'])&&in_array($p['status'],['draft','approved','scheduled','failed'],true)){
            $html.='<form method="post" class="inline">'.Csrf::input().$returnPageInput.'<input type="hidden" name="action" value="status"><input type="hidden" name="id" value="'.(int)$p['id'].'"><input type="datetime-local" name="scheduled_at" value="'.e($p['scheduled_at']?date('Y-m-d\TH:i',strtotime($p['scheduled_at'])):'').'"><button name="status" value="approved">Approve</button><button name="status" value="scheduled">Schedule</button><button class="danger" name="status" value="cancelled">Cancel</button></form>';
        }
        if(($p['post_type']??'feed')==='feed'&&empty($p['archived_at'])&&in_array($p['status'],['draft','approved'],true)){
            $html.='<form method="post" class="inline">'.Csrf::input().$returnPageInput.'<input type="hidden" name="action" value="regenerate"><input type="hidden" name="id" value="'.(int)$p['id'].'"><button class="secondary">Regenerate caption</button></form>';
        }
        if(empty($p['archived_at'])&&!in_array($p['status'],['scheduled','publishing'],true)){
            $html.='<form method="post" class="inline archive-form">'.Csrf::input().$returnPageInput.'<input type="hidden" name="action" value="archive"><input type="hidden" name="return_scope" value="'.e($scope).'"><input type="hidden" name="id" value="'.(int)$p['id'].'"><button class="secondary">Archive</button></form>';
        }
        if(!empty($p['archived_at'])){
            $html.='<div class="inline"><form method="post">'.Csrf::input().$returnPageInput.'<input type="hidden" name="action" value="restore"><input type="hidden" name="id" value="'.(int)$p['id'].'"><button>Restore</button></form><form method="post">'.Csrf::input().$returnPageInput.'<input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="'.(int)$p['id'].'"><button class="danger" onclick="return confirm(&quot;Permanently delete this admin record? The Facebook post will remain.&quot;)">Delete permanently</button></form></div>';
        }
        if($p['error_message'])$html.='<p class="error">'.e($p['error_message']).'</p>';
        if(!empty($p['instagram_error']))$html.='<p class="error">Instagram: '.e($p['instagram_error']).'</p>';
        if(!empty($p['linkedin_error']))$html.='<p class="error">LinkedIn: '.e($p['linkedin_error']).'</p>';
        if(!empty($p['tiktok_error']))$html.='<p class="error">TikTok: '.e($p['tiktok_error']).'</p>';
        $html.='</article>';
    }
    return $html.'</div>';
}

function notificationsScreen(PDO $db):string
{
    if(in_array('table.notifications',(new DatabaseUpgradeService($db))->pending(),true))return '<div class="panel"><h2>Notifications update required</h2><p>Run Database Upgrade once to enable alerts.</p><a href="index.php?view=upgrade">Open Database Upgrade</a></div>';
    $rows=$db->query('SELECT n.*,p.page_name FROM notifications n LEFT JOIN social_pages p ON p.id=n.page_id ORDER BY n.read_at IS NULL DESC,n.created_at DESC LIMIT 200')->fetchAll();
    $html='<div class="panel notification-center"><div class="section-heading"><div><h2>Notifications</h2><p>Folder, token, AI and publishing problems appear here.</p></div><div class="inline"><form method="post">'.Csrf::input().'<button name="action" value="read_all">Mark all read</button></form><form method="post">'.Csrf::input().'<button class="secondary" name="action" value="delete_read">Clear read</button></form></div></div>';
    if($rows===[])return $html.'<p class="empty">No notifications.</p></div>';
    foreach($rows as $row){$unread=empty($row['read_at']);$html.='<article class="notification-item severity-'.e($row['severity']).($unread?' unread':'').'"><div><span class="notification-dot"></span><strong>'.e(displayText($row['title'])).'</strong> '.badge($row['severity']).'</div><p>'.e(displayText($row['message'])).'</p><small>'.e(displayText($row['page_name']??'System')).' · '.e(date('M j, Y g:i A',strtotime($row['created_at']))).'</small>'.($unread?'<form method="post">'.Csrf::input().'<input type="hidden" name="id" value="'.(int)$row['id'].'"><button class="secondary" name="action" value="read">Mark read</button></form>':'').'</article>';}
    return $html.'</div>';
}

function analyticsScreen(PDO $db):string
{
    $pending=(new DatabaseUpgradeService($db))->pending();if(in_array('table.post_analytics',$pending,true))return '<div class="panel"><h2>Analytics update required</h2><p>Run Database Upgrade once. Analytics will then refresh automatically through cron.</p><a href="index.php?view=upgrade">Open Database Upgrade</a></div>';
    $pages=$db->query('SELECT id,page_name FROM social_pages ORDER BY page_name')->fetchAll();$html='<div class="analytics-grid">';
    foreach($pages as $page){
        $summary=$db->prepare('SELECT COUNT(DISTINCT a.post_id) posts,SUM(a.reactions) reactions,SUM(a.comments) comments,SUM(a.shares) shares,SUM(a.engagement_score) score FROM post_analytics a JOIN social_posts s ON s.id=a.post_id WHERE s.page_id=?');$summary->execute([(int)$page['id']]);$stats=$summary->fetch()?:[];
        $best=$db->prepare('SELECT HOUR(COALESCE(s.facebook_published_at,s.published_at)) post_hour,COUNT(*) samples,ROUND(AVG(a.engagement_score),1) average_score FROM post_analytics a JOIN social_posts s ON s.id=a.post_id WHERE s.page_id=? AND a.platform="facebook" GROUP BY post_hour ORDER BY average_score DESC,samples DESC LIMIT 1');$best->execute([(int)$page['id']]);$recommendation=$best->fetch();
        $facebookMeasured=$db->prepare('SELECT COUNT(*) FROM post_analytics a JOIN social_posts s ON s.id=a.post_id WHERE s.page_id=? AND a.platform="facebook"');$facebookMeasured->execute([(int)$page['id']]);$facebookPostCount=(int)$facebookMeasured->fetchColumn();
        $bestText=$recommendation&&$facebookPostCount>=2?date('g:i A',mktime((int)$recommendation['post_hour'],0)).' ('.$facebookPostCount.' posts measured)':'Collecting data — at least 2 published Facebook posts are needed.';
        $html.='<section class="panel analytics-card"><h2>'.e(displayText($page['page_name'])).'</h2><div class="analytics-stats"><span><strong>'.(int)($stats['posts']??0).'</strong>Measured posts</span><span><strong>'.(int)($stats['reactions']??0).'</strong>Likes/reactions</span><span><strong>'.(int)($stats['comments']??0).'</strong>Comments</span><span><strong>'.(int)($stats['shares']??0).'</strong>Shares</span></div><div class="best-time"><small>Recommended posting time</small><strong>'.e($bestText).'</strong></div></section>';
    }
    if($pages===[])$html.='<div class="panel"><p class="empty">Add a Facebook Page to begin analytics.</p></div>';
    return $html.'</div><div class="panel"><p>Engagement score = reactions + (comments × 2) + (shares × 3). Cron refreshes published-post analytics approximately every 6 hours when Meta permissions allow it.</p></div>';
}

function blogScreen(PDO $db): string
{
    $pending = (new DatabaseUpgradeService($db))->pending();
    if (array_filter($pending, static fn(string $change): bool => str_contains($change, 'blog_')) !== []) {
        return '<div class="panel"><h2>Blog Automation update required</h2><p>Run Database Upgrade once. Existing Facebook Pages, products and posts will not be changed.</p><a class="button-link" href="index.php?view=upgrade">Open Database Upgrade</a></div>';
    }

    $sites = $db->query('SELECT s.*,(SELECT COUNT(*) FROM blog_articles a WHERE a.blog_site_id=s.id) article_count FROM blog_settings s ORDER BY s.site_name,s.id')->fetchAll();
    $requestedId = max(0, (int)($_GET['site'] ?? 0));
    $adding = isset($_GET['new']);
    if (!$adding && $requestedId === 0 && $sites !== []) $requestedId = (int)$sites[0]['id'];
    $settings = [];
    foreach ($sites as $site) if ((int)$site['id'] === $requestedId) {$settings = $site; break;}
    if (!$adding && $requestedId > 0 && $settings === []) $requestedId = $sites !== [] ? (int)$sites[0]['id'] : 0;
    if ($settings === [] && !$adding && $requestedId > 0) foreach ($sites as $site) if ((int)$site['id'] === $requestedId) {$settings = $site; break;}
    $siteId = (int)($settings['id'] ?? 0);
    $defaultTopics = "Main products, services and customer questions\nIndustry trends and practical buying guides\nMaintenance, comparison and how-to articles\nTopics relevant to the website's Bangladesh audience";
    $promptValue = trim((string)($settings['system_prompt'] ?? '')) !== '' ? (string)$settings['system_prompt'] : BlogAIService::defaultPrompt();
    $articles = [];
    $runs = [];
    if ($siteId > 0) {
        $articleQuery = $db->prepare('SELECT * FROM blog_articles WHERE blog_site_id=? ORDER BY id DESC LIMIT 30');
        $articleQuery->execute([$siteId]);
        $articles = $articleQuery->fetchAll();
        $runQuery = $db->prepare('SELECT * FROM blog_runs WHERE blog_site_id=? ORDER BY id DESC LIMIT 12');
        $runQuery->execute([$siteId]);
        $runs = $runQuery->fetchAll();
    }

    $html = '<div class="panel site-manager"><div class="section-heading"><div><h2>WordPress websites</h2><p>Add multiple websites. Every site keeps its own credentials, topics, schedule and article history.</p></div><a class="button-link" href="index.php?view=blog&new=1">+ Add website</a></div><div class="site-tabs">';
    if ($sites === []) $html .= '<span class="empty">No website added yet.</span>';
    foreach ($sites as $site) {
        $active = !$adding && (int)$site['id'] === $siteId ? 'active' : '';
        $html .= '<a class="'.$active.'" href="index.php?view=blog&site='.(int)$site['id'].'"><span>'.e(displayText($site['site_name'])).'</span><small>'.(int)$site['article_count'].' articles · '.(!empty($site['enabled'])?'enabled':'paused').'</small></a>';
    }
    $html .= '</div></div>';

    $formTitle = $siteId > 0 ? 'Edit website automation' : 'Add WordPress website';
    $html .= '<div class="blog-layout"><div class="panel"><div class="section-heading"><div><h2>'.$formTitle.'</h2><p>Research the web, write an English article with a short Bangla summary, optimize it for Yoast and send it to this WordPress site.</p></div>'.($siteId > 0 ? badge(!empty($settings['enabled']) ? 'active' : 'inactive') : '').'</div><form method="post">'.Csrf::input().'<input type="hidden" name="action" value="save"><input type="hidden" name="site_id" value="'.$siteId.'">';
    $html .= '<label class="check-row"><input type="checkbox" name="enabled" value="1" '.(!empty($settings['enabled']) ? 'checked' : '').'><span><strong>Enable scheduled blog automation</strong><small>The separate blog cron creates an article only when its interval is due.</small></span></label>';
    $html .= field('Website name','site_name',$settings['site_name']??'',true,'text','Example: Example Studio Store or My Second Website');
    $html .= '<div class="form-grid">'.field('WordPress Site URL','site_url',$settings['site_url']??'',true,'url').field('WordPress username','wp_username',$settings['wp_username']??'',true,'text','Use a dedicated Editor account where possible.').'</div>';
    $html .= field('WordPress Application Password','wp_app_password','',false,'password','Create it in WordPress → Users → Profile. Leave blank to keep the saved password.');
    $html .= '<div class="form-grid">'.selectField('Article interval','frequency_days',['3'=>'Every 3 days','7'=>'Every 7 days','14'=>'Every 14 days'],(string)($settings['frequency_days']??7)).field('Research start time','post_time',substr((string)($settings['post_time']??'03:00'),0,5),true,'time','Asia/Dhaka; the cron may start a few minutes later.').'</div>';
    $html .= selectField('WordPress delivery mode','publish_mode',['draft'=>'Create WordPress Draft (recommended)','publish'=>'Publish immediately'],(string)($settings['publish_mode']??'draft'));
    $html .= '<div class="form-grid">'.field('OpenAI model','ai_model',$settings['ai_model']??'gpt-5-mini',true,'text','Must support the Responses API web_search tool.').field('OpenAI API key','ai_api_key','',false,'password','Leave blank to reuse the existing OpenAI key from an active Facebook Page.').'</div>';
    $html .= area('Topic areas — one per line','topics',$settings['topics']??$defaultTopics);
    $html .= areaRows('System prompt — editable writing and SEO rules','system_prompt',$promptValue,18);
    $html .= '<div class="form-grid">'.field('WordPress Category ID','category_id',$settings['category_id']??'',false,'number','Optional. Find it in the category edit URL: tag_ID=123.').field('Featured Media ID','featured_media_id',$settings['featured_media_id']??'',false,'number','Optional image from your WordPress Media Library.').'</div>';
    $html .= '<button>'.($siteId > 0 ? 'Update Website Settings' : 'Add Website').'</button></form></div>';

    $html .= '<div><div class="panel"><h2>Test this website</h2><p>Test WordPress first without using AI credits. Then run one live web-researched article and inspect its Yoast analysis in WordPress.</p>';
    if ($siteId > 0) $html .= '<div class="inline"><form method="post">'.Csrf::input().'<input type="hidden" name="action" value="test_connection"><input type="hidden" name="site_id" value="'.$siteId.'"><button class="secondary">Test WordPress connection</button></form><form method="post">'.Csrf::input().'<input type="hidden" name="action" value="run"><input type="hidden" name="site_id" value="'.$siteId.'"><button>Generate one researched article now</button></form></div><p class="muted">Article generation uses OpenAI tokens and web search. Keep Draft mode enabled for the first articles.</p>';
    else $html .= '<p class="empty">Save this website before testing or generating an article.</p>';
    $html .= '</div>';
    $html .= '<div class="panel"><h2>Recent runs</h2>';
    if ($runs === []) $html .= '<p class="empty">No blog automation run yet.</p>';
    else {
        $html .= '<div class="run-list">';
        foreach ($runs as $run) $html .= '<div class="run-item"><div>'.badge((string)$run['result']).'<time>'.e((string)$run['created_at']).'</time></div><p>'.e((string)$run['message']).'</p></div>';
        $html .= '</div>';
    }
    $html .= '</div></div></div>';

    $selectedName = (string)($settings['site_name'] ?? 'this website');
    $html .= '<div class="panel"><div class="section-heading"><div><h2>Generated blog articles</h2><p>'.e(displayText($selectedName)).' — local research and delivery history. Edit the final Draft inside WordPress.</p></div><span class="count-pill">'.count($articles).' recent</span></div>';
    if ($articles === []) return $html.'<p class="empty">No researched article has been generated for this website.</p></div>';
    foreach ($articles as $article) {
        $checks = json_decode((string)($article['seo_checks_json'] ?? ''), true);
        if (!is_array($checks)) $checks = [];
        $score = (int)$article['seo_score'];
        $html .= '<article class="blog-article"><div class="section-heading"><div><h3>'.e(displayText($article['title'])).'</h3><p><strong>Focus keyphrase:</strong> '.e($article['focus_keyphrase']).'</p></div>'.badge($article['status']).'</div>';
        $html .= '<div class="seo-meter"><span style="width:'.max(0,min(100,$score)).'%"></span></div><p class="seo-score">Automation SEO checklist: <strong>'.$score.'%</strong> · WordPress post ID: '.($article['wp_post_id'] ? (int)$article['wp_post_id'] : 'not delivered').'</p>';
        if ($checks !== []) {
            $html .= '<div class="seo-checks">';
            foreach ($checks as $label => $passed) $html .= '<span class="'.($passed?'passed':'warning').'">'.($passed?'✓':'!').' '.e((string)$label).'</span>';
            $html .= '</div>';
        }
        if (!empty($article['wp_post_url'])) $html .= '<p><a href="'.e($article['wp_post_url']).'" target="_blank" rel="noopener">Open WordPress post ↗</a></p>';
        if (!empty($article['error_message'])) $html .= '<p class="error">'.e($article['error_message']).'</p>';
        $html .= '</article>';
    }
    return $html.'</div>';
}

function postImagesHtml(array $post): string
{
    if(in_array((string)($post['post_type']??''),['video','reel'],true)&&!empty($post['video_path'])){
        return '<video class="post-video" controls preload="metadata" src="'.e(InstagramService::signedVideoUrl((int)$post['id'])).'"></video><small>1 uploaded video</small>';
    }
    $images=json_decode((string)($post['image_urls_json']??''),true);
    if(!is_array($images)||$images===[])$images=[(string)($post['image_url']??'')];
    $images=array_values(array_unique(array_slice(array_filter($images,fn($ref)=>is_string($ref)&&(str_starts_with($ref,'https://')||str_starts_with($ref,'local://'))),0,10)));
    if($images===[])return '';
    $html='<div class="post-images">';
    foreach($images as $index=>$ref){$src=str_starts_with($ref,'local://')?'index.php?view=media&post_id='.(int)$post['id'].'&index='.$index:$ref;$html.='<img src="'.e($src).'" alt="Post image '.($index+1).'" loading="lazy">';}
    return $html.'</div><small>'.count($images).' image'.(count($images)===1?'':'s').' attached</small>';
}

function serveVideoMedia(PDO $db):never
{
    $postId=(int)($_GET['post_id']??0);$expires=(int)($_GET['expires']??0);$signature=(string)($_GET['sig']??'');
    if($postId<1||!InstagramService::verifyVideoSignature($postId,$expires,$signature)){http_response_code(403);exit('Invalid or expired video link.');}
    $q=$db->prepare('SELECT video_path FROM social_posts WHERE id=? AND post_type IN ("video","reel")');$q->execute([$postId]);$relative=(string)($q->fetchColumn()?:'');
    try{$path=VideoUploadService::resolve($relative);}catch(Throwable){http_response_code(404);exit('Video not found.');}
    $size=filesize($path);$start=0;$end=$size-1;$status=200;
    if(isset($_SERVER['HTTP_RANGE'])&&preg_match('/bytes=(\d*)-(\d*)/',(string)$_SERVER['HTTP_RANGE'],$m)){$start=$m[1]!==''?max(0,(int)$m[1]):0;$end=$m[2]!==''?min($end,(int)$m[2]):$end;if($start>$end){http_response_code(416);exit;}$status=206;}
    $length=$end-$start+1;http_response_code($status);header('Content-Type: '.(str_ends_with(strtolower($path),'.mov')?'video/quicktime':'video/mp4'));header('Accept-Ranges: bytes');header('Content-Length: '.$length);if($status===206)header('Content-Range: bytes '.$start.'-'.$end.'/'.$size);header('Cache-Control: public, max-age=3600');
    $handle=fopen($path,'rb');if($handle===false){http_response_code(500);exit;}fseek($handle,$start);$remaining=$length;while($remaining>0&&!feof($handle)){$chunk=fread($handle,min(1024*1024,$remaining));if($chunk===false||$chunk==='')break;echo $chunk;$remaining-=strlen($chunk);}fclose($handle);exit;
}

function serveBrandingLogo(PDO $db): never
{
    $pageId=(int)($_GET['page']??0);
    $query=$db->prepare('SELECT folder_logo_path FROM social_pages WHERE id=?');
    $query->execute([$pageId]);
    $relative=(string)($query->fetchColumn()?:'');
    $logo=ImageBrandingService::readLogo($relative);
    if($logo===null){http_response_code(404);exit('Logo not found.');}
    header('Content-Type: '.$logo['mime']);
    header('Content-Length: '.strlen($logo['data']));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=3600');
    echo $logo['data'];exit;
}

function servePostImage(PDO $db): never
{
    $postId=(int)($_GET['post_id']??0);$index=(int)($_GET['index']??-1);
    $query=$db->prepare('SELECT image_urls_json,image_url FROM social_posts WHERE id=?');$query->execute([$postId]);$post=$query->fetch();
    if(!$post||$index<0){http_response_code(404);exit('Image not found.');}
    $images=json_decode((string)($post['image_urls_json']??''),true);if(!is_array($images)||$images===[])$images=[(string)$post['image_url']];
    $reference=(string)($images[$index]??'');
    try{$path=FolderContentService::resolve($reference);}catch(Throwable){http_response_code(404);exit('Image not found.');}
    $info=getimagesize($path);$mime=(string)($info['mime']??'application/octet-stream');
    header('Content-Type: '.$mime);header('Content-Length: '.filesize($path));header('X-Content-Type-Options: nosniff');header('Cache-Control: private, max-age=3600');
    readfile($path);exit;
}

function serveInstagramMedia(PDO $db): never
{
    $postId=(int)($_GET['post_id']??0);$index=(int)($_GET['index']??-1);$expires=(int)($_GET['expires']??0);$signature=(string)($_GET['sig']??'');
    if($postId<1||$index<0||!InstagramService::verifyMediaSignature($postId,$index,$expires,$signature)){http_response_code(403);exit('Invalid or expired media link.');}
    $query=$db->prepare('SELECT image_urls_json,image_url,publish_to_instagram,publish_to_tiktok,post_type FROM social_posts WHERE id=?');$query->execute([$postId]);$post=$query->fetch();
    if(!$post||(empty($post['publish_to_instagram'])&&empty($post['publish_to_tiktok']))){http_response_code(404);exit('Image not found.');}
    $images=json_decode((string)($post['image_urls_json']??''),true);if(!is_array($images)||$images===[])$images=[(string)$post['image_url']];
    try{$data=ContentQualityService::imageData((string)($images[$index]??''));}
    catch(Throwable){http_response_code(404);exit('Image not found.');}
    $info=@getimagesizefromstring($data);if($info===false){http_response_code(415);exit('Invalid image.');}
    [$width,$height]=$info;$mime=(string)($info['mime']??'');$story=(string)($post['post_type']??'feed')==='story';
    $minimumRatio=$story?0.5:0.8;$maximumRatio=1.91;$ratio=$width/$height;
    if($mime==='image/jpeg'&&$ratio>=$minimumRatio&&$ratio<=$maximumRatio&&strlen($data)<=8*1024*1024){
        instagramImageHeaders($postId,$index,strlen($data));echo $data;exit;
    }
    if(!function_exists('imagecreatefromstring')||!function_exists('imagecreatetruecolor')||!function_exists('imagejpeg')){http_response_code(500);exit('PHP GD is required for Instagram image conversion.');}
    $source=@imagecreatefromstring($data);if($source===false){http_response_code(415);exit('Image conversion failed.');}
    $targetWidth=$width;$targetHeight=$height;
    if($ratio<$minimumRatio)$targetWidth=(int)ceil($height*$minimumRatio);
    elseif($ratio>$maximumRatio)$targetHeight=(int)ceil($width/$maximumRatio);
    $canvas=imagecreatetruecolor($targetWidth,$targetHeight);$white=imagecolorallocate($canvas,255,255,255);imagefill($canvas,0,0,$white);
    imagecopy($canvas,$source,(int)(($targetWidth-$width)/2),(int)(($targetHeight-$height)/2),0,0,$width,$height);imagedestroy($source);
    $jpeg='';foreach([90,82,74] as $quality){ob_start();imagejpeg($canvas,null,$quality);$jpeg=(string)ob_get_clean();if(strlen($jpeg)<=8*1024*1024)break;}imagedestroy($canvas);
    if($jpeg===''||strlen($jpeg)>8*1024*1024){http_response_code(413);exit('Converted Instagram image is larger than 8 MB.');}
    instagramImageHeaders($postId,$index,strlen($jpeg));echo $jpeg;exit;
}

function instagramImageHeaders(int $postId,int $index,int $length):void
{
    header('Content-Type: image/jpeg');
    header('Content-Length: '.$length);
    header('Content-Disposition: inline; filename="instagram-'.$postId.'-'.$index.'.jpg"');
    header('Accept-Ranges: bytes');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: public, max-age=3600');
}

function aiLabel(array $row): string
{
    $provider=(string)($row['ai_provider']??'none');
    if($provider==='none')return 'none';
    $model=trim((string)($row['ai_model']??''));
    if($model==='')$model=['qwen'=>'qwen-plus','anthropic'=>'claude-sonnet-4-6','openai'=>'gpt-5-mini'][$provider]??'';
    return $provider.' / '.$model;
}

function render(string $title,string $content):void
{
    $flashes=$_SESSION['flash']??[];unset($_SESSION['flash'],$_SESSION['old']);$auth=Auth::check();
    $current=(string)($_GET['view']??'dashboard');
    $descriptions=['dashboard'=>'Your publishing activity at a glance.','pages'=>'Manage Meta Pages, content sources and AI writing settings.','connections'=>'Add and manage LinkedIn and TikTok publishing destinations.','content'=>'Prepare products and manage your content sources.','posts'=>'Review, approve and track every automated social post.','notifications'=>'Review token, folder, AI and publishing alerts.','analytics'=>'Measure engagement and find the best posting time.','upgrade'=>'Keep the application database ready for new features.'];
    $unread=0;if($auth){try{$unread=(int)Database::connection()->query('SELECT COUNT(*) FROM notifications WHERE read_at IS NULL')->fetchColumn();}catch(Throwable){}}
    $nav=['dashboard'=>'Dashboard','pages'=>'Meta Pages','connections'=>'Connections','content'=>'Content','posts'=>'Posts','notifications'=>'Notifications'.($unread?' ('.$unread.')':''),'analytics'=>'Analytics','upgrade'=>'Database Upgrade'];
    ?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="description" content="Example Studio Social Automation is a secure publishing studio for authorized business teams to prepare, schedule and publish media across connected social platforms."><meta name="theme-color" content="#071827"><title><?=e($title)?> — Social Automation</title><link rel="icon" type="image/png" sizes="512x512" href="assets/social-automation-icon.svg"><link rel="apple-touch-icon" href="assets/social-automation-icon.svg"><link rel="stylesheet" href="assets/app.css?v=20261002-branding-row-hex"></head><body><?php if($auth):?><header><div class="header-inner"><a class="brand" href="index.php?view=dashboard"><img class="brand-icon" src="assets/social-automation-icon.svg" alt=""><span class="brand-copy">Social Automation<small>Publishing Studio</small></span></a><nav aria-label="Primary navigation"><?php foreach($nav as $view=>$label):?><a class="<?=$current===$view?'active':''?>" href="index.php?view=<?=e($view)?>"><?=e($label)?></a><?php endforeach?><form method="post" action="index.php?view=logout"><?=Csrf::input()?><button class="link">Logout</button></form></nav></div></header><?php endif?><main class="<?=$auth?'':'public-main'?>"><?php if($auth):?><div class="page-heading"><div><h1><?=e($title)?></h1><p><?=e($descriptions[$current]??'Manage your social publishing workflow.')?></p></div><span class="status-pill">Automation online</span></div><?php endif?><?php foreach($flashes as $f):?><div class="flash <?=e($f['type'])?>"><?=e($f['message'])?></div><?php endforeach?><?=$content?></main><footer class="legal-footer"><span>Example Studio · Social Automation</span><div class="legal-footer-links" role="navigation" aria-label="Legal information"><a href="/">About</a><a href="privacy-policy.html">Privacy Policy</a><a href="terms-of-service.html">Terms of Service</a><a href="mailto:mail@example.com">Contact</a></div></footer><script>(()=>{const sync=source=>{const mode=document.querySelector('[data-daily-mode="'+source+'"]');const count=document.querySelector('[data-daily-count="'+source+'"]');if(!mode||!count)return;const enabled=mode.value!=='off';const countRow=count.closest('label');if(countRow)countRow.style.display=enabled?'':'none';document.querySelectorAll('[data-daily-dependent="'+source+'"]').forEach(control=>{const row=control.closest('label');if(row)row.style.display=enabled?'':'none';});const selected=Math.max(1,Math.min(3,Number(count.value)||1));document.querySelectorAll('[data-daily-source="'+source+'"]').forEach(row=>{row.style.display=enabled&&Number(row.dataset.dailySlot)<=selected?'':'none';});};['woo','folder','story'].forEach(source=>{const mode=document.querySelector('[data-daily-mode="'+source+'"]');const count=document.querySelector('[data-daily-count="'+source+'"]');if(mode)mode.addEventListener('change',()=>sync(source));if(count)count.addEventListener('change',()=>sync(source));sync(source);});const page=document.querySelector('#content-page-filter');const item=document.querySelector('#content-item-select');const preview=document.querySelector('#content-picker-preview');const filterContent=()=>{if(!page||!item)return;let first=null;Array.from(item.options).forEach(option=>{const visible=option.dataset.page===page.value;option.hidden=!visible;option.disabled=!visible;if(visible&&!first)first=option;});if(item.selectedOptions[0]?.disabled&&first)first.selected=true;if(!item.selectedOptions.length&&first)first.selected=true;if(preview){const selected=item.selectedOptions[0];preview.textContent=selected?selected.dataset.title:'No active content is available for this Page.';}};if(page&&item){const postType=document.querySelector('[name="post_type"]');const destinations=document.querySelector('#feed-destinations');const destinationNote=document.querySelector('#feed-destination-note');const syncDestinations=()=>{if(!destinations)return;const story=postType?.value==='story';const option=page.selectedOptions[0];destinations.hidden=story;if(destinationNote)destinationNote.hidden=story;destinations.querySelectorAll('[data-feed-platform]').forEach(control=>{const platform=control.dataset.feedPlatform;const available=platform==='facebook'||option?.dataset[platform]==='1';control.disabled=story||!available;if(!available)control.checked=false;if(available&&platform!=='facebook'&&!story)control.checked=true;});};page.addEventListener('change',()=>{filterContent();syncDestinations();});item.addEventListener('change',filterContent);postType?.addEventListener('change',syncDestinations);filterContent();syncDestinations();}document.querySelectorAll('input[type="range"]').forEach(control=>{const output=document.querySelector('[data-range-value="'+control.name+'"]');const show=()=>{if(output)output.textContent=control.value+'%';};control.addEventListener('input',show);show();});const selectedFolderPage=document.querySelector('#selected-folder-page');const selectedFolderName=document.querySelector('#selected-folder-name');if(selectedFolderPage&&selectedFolderName){const syncSelectedFolders=()=>{let first=null;Array.from(selectedFolderName.options).forEach(option=>{const visible=option.dataset.page===selectedFolderPage.value;option.hidden=!visible;option.disabled=!visible;if(visible&&!first)first=option;});if(first)first.selected=true;};selectedFolderPage.addEventListener('change',syncSelectedFolders);syncSelectedFolders();}const videoForm=document.querySelector('#video-upload-form');if(videoForm){const file=videoForm.querySelector('#video-file-input');const pageSelect=videoForm.querySelector('[name="page_id"]');const csrf=videoForm.querySelector('[name="_csrf"]');const staged=videoForm.querySelector('#staged-video-path');const state=videoForm.querySelector('#video-upload-state');const bar=videoForm.querySelector('#video-upload-progress');const buttons=videoForm.querySelectorAll('.video-queue-button');let request=null;const lock=value=>buttons.forEach(button=>button.disabled=value);const reset=message=>{staged.value='';bar.style.width='0%';state.className='video-upload-state';state.textContent=message;lock(true);};file.addEventListener('change',()=>{if(request)request.abort();const selected=file.files?.[0];if(!selected){reset('Choose a video to upload');return;}if(selected.size>200*1024*1024){file.value='';reset('Video exceeds 200 MB');state.classList.add('error');return;}const chunkSize=5*1024*1024;const total=Math.ceil(selected.size/chunkSize);const bytes=new Uint8Array(16);crypto.getRandomValues(bytes);const uploadId=Array.from(bytes,value=>value.toString(16).padStart(2,'0')).join('');state.className='video-upload-state uploading';state.textContent='Uploading… 0%';lock(true);const fail=message=>{request=null;reset(message);state.classList.add('error');};const sendChunk=index=>{const begin=index*chunkSize;const piece=selected.slice(begin,Math.min(selected.size,begin+chunkSize));const data=new FormData();data.append('_csrf',csrf.value);data.append('page_id',pageSelect.value);data.append('upload_id',uploadId);data.append('chunk_index',String(index));data.append('chunk_total',String(total));data.append('video_file',piece,selected.name);request=new XMLHttpRequest();request.open('POST','index.php?view=video-stage');request.upload.addEventListener('progress',event=>{if(!event.lengthComputable)return;const loaded=Math.min(selected.size,begin+event.loaded);const percent=Math.min(99,Math.round(loaded/selected.size*100));bar.style.width=percent+'%';state.textContent='Uploading… '+percent+'%';});request.addEventListener('load',event=>{const xhr=event.target;let result={};try{result=JSON.parse(xhr.responseText||'{}');}catch(e){}if(xhr.status>=200&&xhr.status<300&&result.ok){if(result.complete){request=null;staged.value=result.path;bar.style.width='100%';state.className='video-upload-state ready';state.textContent='Ready to queue · 100%';file.disabled=true;lock(false);}else{sendChunk(index+1);}}else{const messages={413:'A video part is larger than the hosting upload limit.',419:'The page session expired. Refresh and select the video again.',503:'The hosting PHP worker stopped while receiving a video part.'};fail(result.message||messages[xhr.status]||('Upload failed (HTTP '+xhr.status+'). Choose the file again.'));}});request.addEventListener('error',()=>fail('Upload failed before reaching the server. Check the connection and try again.'));request.send(data);};sendChunk(0);});pageSelect.addEventListener('change',()=>{if(staged.value!==''){file.disabled=false;file.value='';reset('Page changed—choose the video again');}});videoForm.addEventListener('submit',event=>{if(staged.value===''){event.preventDefault();state.classList.add('error');state.textContent='Wait for the video upload to finish.';}});}})();</script><script src="assets/branding-preview.js?v=20261002-branding-row-hex"></script></body></html><?php
}

function publicLanding():string
{
    return '<div class="public-site"><header class="public-nav"><a class="public-brand" href="/"><img src="assets/social-automation-icon.svg" alt="Social Automation app icon"><span>Social Automation<small>by Example Studio</small></span></a><nav aria-label="Public navigation"><a href="#features">Features</a><a href="privacy-policy.html">Privacy</a><a href="terms-of-service.html">Terms</a><a class="public-login-link" href="index.php?view=login">Admin login</a></nav></header><section class="public-hero"><div><span class="eyebrow">Authorized business publishing</span><h1>Plan once. Publish clearly across your connected channels.</h1><p>Example Studio Social Automation is a secure publishing workspace for authorized business teams. It prepares, schedules and delivers approved photos, videos and captions to connected social platforms while keeping each destination\'s status separate.</p><div class="public-actions"><a class="public-primary" href="#features">Explore the service</a><a class="public-secondary" href="mailto:mail@example.com">Contact Example Studio</a></div><div class="public-trust"><span>Encrypted credentials</span><span>Controlled schedules</span><span>Platform-specific delivery</span></div></div><div class="public-product-card" aria-label="Publishing workflow preview"><div class="product-card-top"><img src="assets/social-automation-icon.svg" alt=""><div><strong>Publishing Studio</strong><span>Connected workflow</span></div><b>Online</b></div><div class="workflow-step"><i>1</i><div><strong>Prepare</strong><span>Organize approved media and captions</span></div></div><div class="workflow-step"><i>2</i><div><strong>Schedule</strong><span>Choose the Page, destination and time</span></div></div><div class="workflow-step"><i>3</i><div><strong>Publish</strong><span>Track each platform independently</span></div></div></div></section><section class="public-section" id="features"><div class="public-section-heading"><span class="eyebrow">What the service does</span><h2>A practical workflow for business content</h2><p>The application is operated by Example Studio for authorized account administrators. It does not request or store social-platform passwords.</p></div><div class="public-features"><article><span>01</span><h3>Media preparation</h3><p>Prepare approved product, project, photo and video content, including optional local image branding.</p></article><article><span>02</span><h3>Scheduling</h3><p>Set destination-specific schedules and review content before automated jobs become due.</p></article><article><span>03</span><h3>Connected publishing</h3><p>Send content through official platform APIs after the account owner grants the required permissions.</p></article><article><span>04</span><h3>Delivery tracking</h3><p>Keep separate status, publishing identifiers and error details for every connected destination.</p></article></div></section><section class="public-section public-safety"><div><span class="eyebrow">Account control</span><h2>Connections remain under the account owner\'s control.</h2></div><div><p>Administrators can pause or remove a connection, and account owners can revoke authorization in the destination platform. TikTok authorization takes place on TikTok; this service never asks for a TikTok password.</p><div class="public-policy-links"><a href="privacy-policy.html">Read Privacy Policy</a><a href="terms-of-service.html">Read Terms of Service</a></div></div></section><section class="public-contact"><div><span class="eyebrow">Operator</span><h2>Example Studio</h2><p>123 Example Street, Example City (fictional).</p></div><div><a href="mailto:mail@example.com">mail@example.com</a><a href="tel:+88+1 202 555 0142">+1 202 555 0142</a><a href="https://example.com">example.com</a></div></section></div>';
}

function loginForm():string{return '<div class="login panel"><h1>Admin login</h1><form method="post">'.Csrf::input().field('Email','email','',true,'email').field('Password','password','',true,'password').'<button>Log in</button></form></div>';}
function card(string $label,int $value):string{return '<div class="card"><span>'.e($label).'</span><strong>'.$value.'</strong></div>';}
function badge(string $v):string{return '<span class="badge '.e($v).'">'.e($v).'</span>';}
function displayText(mixed $value):string{return html_entity_decode((string)$value,ENT_QUOTES|ENT_HTML5,'UTF-8');}
function shortText(string $value,int $limit=90):string
{
    $value=trim(preg_replace('/\s+/u',' ',$value)??$value);
    if(function_exists('mb_strlen')&&function_exists('mb_substr'))return mb_strlen($value,'UTF-8')>$limit?mb_substr($value,0,$limit-1,'UTF-8').'…':$value;
    return strlen($value)>$limit?substr($value,0,$limit-1).'…':$value;
}
function field(string $label,string $name,mixed $value='',bool|string $required=false,string $type='text',string $hint=''):string{return '<label>'.e($label).'<input type="'.e($type).'" name="'.e($name).'" value="'.e($value).'" '.($required?'required':'').'>'.($hint?'<small>'.e($hint).'</small>':'').'</label>';}
function colorField(string $label,string $name,mixed $value):string{return '<label>'.e($label).'<span class="branding-color-pair"><input type="color" name="'.e($name).'" value="'.e($value).'" aria-label="'.e($label).' picker" required><input type="text" class="branding-hex" data-color-for="'.e($name).'" value="'.e($value).'" maxlength="7" pattern="#[0-9A-Fa-f]{6}" placeholder="#FFFFFF" aria-label="'.e($label).' HEX code" spellcheck="false" required></span></label>';}

function rangeField(string $label,string $name,mixed $value,int $min,int $max,string $hint=''):string{return '<label><span class="range-heading">'.e($label).'<output data-range-value="'.e($name).'">'.e($value).'%</output></span><input type="range" name="'.e($name).'" value="'.e($value).'" min="'.$min.'" max="'.$max.'" step="1" required>'.($hint?'<small>'.e($hint).'</small>':'').'</label>';}
function dailyTimeField(string $label,string $name,mixed $value,string $hint,string $source,int $slot):string{return '<label data-daily-source="'.e($source).'" data-daily-slot="'.max(1,min(3,$slot)).'">'.e($label).'<input type="time" name="'.e($name).'" value="'.e($value).'" required>'.($hint?'<small>'.e($hint).'</small>':'').'</label>';}
function area(string $label,string $name,mixed $value):string{return '<label>'.e($label).'<textarea name="'.e($name).'" rows="4">'.e($value).'</textarea></label>';}
function areaRows(string $label,string $name,mixed $value,int $rows):string{return '<label>'.e($label).'<textarea name="'.e($name).'" rows="'.max(2,min(30,$rows)).'">'.e($value).'</textarea></label>';}
function selectField(string $label,string $name,array $options,mixed $selected):string{$h='<label>'.e($label).'<select name="'.e($name).'" required>';foreach($options as $v=>$t)$h.='<option value="'.e($v).'" '.((string)$v===(string)$selected?'selected':'').'>'.e($t).'</option>';return $h.'</select></label>';}
function normalizeDate(string $value):?string{if($value==='')return null;$d=DateTimeImmutable::createFromFormat('Y-m-d\TH:i',$value,new DateTimeZone((string)config('timezone')));return $d?$d->format('Y-m-d H:i:s'):null;}
function assignPostDestinations(PDO $db,int $postId,int $pageId,string $postType):void
{
    if($postType==='story'||array_filter((new DatabaseUpgradeService($db))->pending(),static fn(string $change):bool=>str_starts_with($change,'table.social_connections')||str_starts_with($change,'social_connections.'))!==[])return;
    PlatformScheduleService::applyAutomatic($db,$postId,$pageId,$postType);
}
function markContentBatchUsed(PDO $db,array $item,array $usedImages):void{if(($item['source_type']??'manual')==='server_folder'){FolderContentService::markBatchUsed($db,(int)$item['id'],$usedImages);return;}$next=0;if(($item['source_type']??'manual')==='woocommerce'){$all=json_decode((string)($item['woo_images_json']??''),true);$start=(int)($item['woo_next_image_index']??0);if(is_array($all)&&$start+count($usedImages)<count($all))$next=$start+count($usedImages);}$db->prepare('UPDATE content_items SET last_drafted_at=?,woo_next_image_index=?,woo_batch_images_json=NULL WHERE id=?')->execute([date('Y-m-d H:i:s'),$next,(int)$item['id']]);}
