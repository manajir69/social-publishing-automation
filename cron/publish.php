<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Database;
use App\Services\AI\AIProviderFactory;
use App\Services\AI\PostCopyFormatter;
use App\Services\AnalyticsService;
use App\Services\ContentQualityService;
use App\Services\DatabaseUpgradeService;
use App\Services\FacebookService;
use App\Services\FolderContentService;
use App\Services\InstagramService;
use App\Services\LinkedInService;
use App\Services\ImageBrandingService;
use App\Services\NotificationService;
use App\Services\PlatformScheduleService;
use App\Services\TikTokService;
use App\Services\WooCommerceService;

$db = Database::connection();
$facebook = new FacebookService();
$instagram = new InstagramService();
$linkedin = new LinkedInService();
$tiktok = new TikTokService();
$processed = 0;

$pendingDatabaseChanges = (new DatabaseUpgradeService($db))->pending();
if ($pendingDatabaseChanges === []) {
    prepareDailyWooCommercePosts($db);
    prepareDailyFolderPosts($db);
    prepareDailyStories($db);
} else {
    echo "Automation is waiting for Admin Panel > Database Upgrade.\n";
    exit(0);
}

$db->exec("UPDATE social_posts SET status='scheduled',locked_at=NULL,scheduled_at=NOW(),error_message='Recovered after an interrupted publishing worker.' WHERE status='publishing' AND locked_at<DATE_SUB(NOW(),INTERVAL 30 MINUTE)");

while ($processed < 10) {
    $db->beginTransaction();
    $stmt = $db->prepare("SELECT s.id FROM social_posts s JOIN social_pages p ON p.id=s.page_id WHERE s.status='scheduled' AND s.scheduled_at<=? AND p.status='active' ORDER BY s.scheduled_at ASC LIMIT 1 FOR UPDATE SKIP LOCKED");
    $stmt->execute([date('Y-m-d H:i:s')]);
    $id = $stmt->fetchColumn();
    if (!$id) {
        $db->commit();
        break;
    }
    $claim = $db->prepare("UPDATE social_posts SET status='publishing',locked_at=NOW(),retry_count=retry_count+1 WHERE id=? AND status='scheduled'");
    $claim->execute([(int) $id]);
    $db->commit();
    if ($claim->rowCount() !== 1) continue;

    $stmt = $db->prepare('SELECT s.*,p.facebook_page_id,p.encrypted_access_token,p.instagram_enabled,p.instagram_user_id,p.encrypted_instagram_access_token FROM social_posts s JOIN social_pages p ON p.id=s.page_id WHERE s.id=?');
    $stmt->execute([(int) $id]);
    $post = $stmt->fetch();

    $manualVideo=$post&&in_array((string)($post['post_type']??''),['video','reel'],true);
    if($post&&!$manualVideo&&!empty($post['instagram_enabled'])&&empty($post['publish_to_instagram'])){
        $db->prepare("UPDATE social_posts SET publish_to_instagram=1,instagram_status='pending' WHERE id=?")->execute([(int)$id]);
        $post['publish_to_instagram']=1;$post['instagram_status']='pending';
    }
    if($post&&!$manualVideo&&empty($post['instagram_enabled'])&&!empty($post['publish_to_instagram'])&&($post['instagram_status']??'off')!=='published'){
        $db->prepare("UPDATE social_posts SET publish_to_instagram=0,instagram_status='off',instagram_error=NULL WHERE id=?")->execute([(int)$id]);
        $post['publish_to_instagram']=0;$post['instagram_status']='off';
    }

    try {
        if(!$post)throw new RuntimeException('Post was not found.');
        $publishFacebook=!isset($post['publish_to_facebook'])||!empty($post['publish_to_facebook']);
        if($publishFacebook&&empty($post['encrypted_access_token']))throw new RuntimeException('Page access token is missing.');
        $facebookId=(string)($post['facebook_post_id']??'');
        if($publishFacebook&&$facebookId===''){
            $result = in_array((string)($post['post_type']??''),['video','reel'],true)?$facebook->publishVideo($post,$post):((($post['post_type']??'feed')==='story')?$facebook->publishStory($post,$post):$facebook->publish($post,$post));
            $facebookId=$result['id'];
            $db->prepare("UPDATE social_posts SET facebook_post_id=?,facebook_published_at=NOW() WHERE id=? AND status='publishing'")->execute([$facebookId,(int)$id]);
        }
        if(!empty($post['publish_to_instagram'])&&($post['instagram_status']??'pending')!=='published'){
            $db->prepare("UPDATE social_posts SET instagram_status='publishing',instagram_error=NULL WHERE id=?")->execute([(int)$id]);
            $post['id']=(int)$id;$igResult=$instagram->publish($post,$post);
            $db->prepare("UPDATE social_posts SET instagram_status='published',instagram_media_id=?,instagram_published_at=NOW(),instagram_error=NULL WHERE id=?")->execute([$igResult['id'],(int)$id]);
        }
        if(!empty($post['publish_to_linkedin'])&&in_array(($post['linkedin_status']??'pending'),['pending','retrying'],true)&&platformDue($post['linkedin_scheduled_at']??null)){
            $connection=activeSocialConnection($db,(int)$post['page_id'],'linkedin');
            $db->prepare("UPDATE social_posts SET linkedin_status='publishing',linkedin_error=NULL WHERE id=?")->execute([(int)$id]);
            $post['id']=(int)$id;$liResult=$linkedin->publish($connection,$post);
            $db->prepare("UPDATE social_posts SET linkedin_status='published',linkedin_post_id=?,linkedin_published_at=NOW(),linkedin_error=NULL WHERE id=?")->execute([$liResult['id'],(int)$id]);
        }
        if(!empty($post['publish_to_tiktok'])&&in_array(($post['tiktok_status']??'pending'),['pending','retrying'],true)&&platformDue($post['tiktok_scheduled_at']??null)){
            $connection=activeSocialConnection($db,(int)$post['page_id'],'tiktok');
            $db->prepare("UPDATE social_posts SET tiktok_status='publishing',tiktok_error=NULL WHERE id=?")->execute([(int)$id]);
            $post['id']=(int)$id;$ttResult=$tiktok->publish($connection,$post);
            $db->prepare("UPDATE social_posts SET tiktok_status='published',tiktok_publish_id=?,tiktok_published_at=NOW(),tiktok_error=NULL WHERE id=?")->execute([$ttResult['id'],(int)$id]);
        }
        $db->beginTransaction();
        $q=$db->prepare("UPDATE social_posts SET status='published',published_at=COALESCE(published_at,NOW()),error_message=NULL,locked_at=NULL WHERE id=? AND status='publishing'");
        $q->execute([(int)$id]);
        logAttempt($db,(int)$id,(int)$post['retry_count'],'success',200,($publishFacebook?'Facebook published as '.$facebookId:'Facebook skipped').(!empty($post['publish_to_instagram'])?' · Instagram published':'').(!empty($post['publish_to_linkedin'])?' · LinkedIn published':'').(!empty($post['publish_to_tiktok'])?' · TikTok submitted':''));
        $db->commit();
        echo "Published post {$id}\n";
    } catch (Throwable $e) {
        $message = substr($e->getMessage(),0,1000);
        $type=NotificationService::classify($message);$tokenProblem=$type==='token_invalid';$retry=shouldRetryPublishFailure($message,$type,(int)($post['retry_count']??1));
        $facebookSucceeded=empty($post['publish_to_facebook'])||!empty($post['facebook_post_id']);
        if(!$facebookSucceeded){$check=$db->prepare('SELECT facebook_post_id FROM social_posts WHERE id=?');$check->execute([(int)$id]);$facebookSucceeded=(string)$check->fetchColumn()!=='';}
        // A successful destination is never sent twice because its platform ID is
        // stored immediately. Keep the overall row failed when another selected
        // destination fails so the problem remains visible under Issues.
        $next=$retry?'scheduled':'failed';
        // Transient failures get three bounded retries: 5, 10 and 20 minutes
        // after attempts 1, 2 and 3. The existing draft and caption are reused;
        // publishing retries never make another AI request.
        $delay=$retry?date('Y-m-d H:i:s',time()+publishRetryDelaySeconds((int)($post['retry_count']??1))):($post['scheduled_at']??null);
        $db->beginTransaction();
        $instagramFailure=str_contains($message,'Instagram ');$linkedinFailure=str_contains($message,'LinkedIn ');$tiktokFailure=str_contains($message,'TikTok ');
        $q=$db->prepare('UPDATE social_posts SET status=?,scheduled_at=?,error_message=?,locked_at=NULL,instagram_status=IF(?,IF(?,"retrying","failed"),instagram_status),instagram_error=IF(?,?,instagram_error),linkedin_status=IF(?,IF(?,"retrying","failed"),linkedin_status),linkedin_error=IF(?,?,linkedin_error),tiktok_status=IF(?,IF(?,"retrying","failed"),tiktok_status),tiktok_error=IF(?,?,tiktok_error) WHERE id=? AND status="publishing"');
        $q->execute([$next,$delay,$message,$instagramFailure?1:0,$retry?1:0,$instagramFailure?1:0,$message,$linkedinFailure?1:0,$retry?1:0,$linkedinFailure?1:0,$message,$tiktokFailure?1:0,$retry?1:0,$tiktokFailure?1:0,$message,(int)$id]);
        logAttempt($db,(int)$id,(int)($post['retry_count']??1),$retry?'temporary_failure':'permanent_failure',null,$message);
        $db->commit();
        $title=$tokenProblem?'Access token expired or invalid':($instagramFailure?($retry?'Instagram retry scheduled':'Instagram cross-post failed'):($linkedinFailure?($retry?'LinkedIn retry scheduled':'LinkedIn publish failed'):($tiktokFailure?($retry?'TikTok retry scheduled':'TikTok publish failed'):($retry?'Facebook retry scheduled':'Facebook publish failed'))));
        NotificationService::create($db,$type,$title,$message,(int)($post['page_id']??0),(int)$id,$retry?'warning':'error',$type.':'.$id.':'.date('Y-m-d'));
        echo "Failed post {$id}: {$message}\n";
    }
    $processed++;
}

processDuePlatformPosts($db,$linkedin,$tiktok,20);

if($pendingDatabaseChanges===[]){$analyticsCount=(new AnalyticsService())->refreshDue($db,10);echo "Analytics refreshed for {$analyticsCount} post(s).\n";}

echo "Worker finished; processed {$processed} post(s).\n";

function prepareDailyWooCommercePosts(PDO $db): void
{
    $pages = $db->query("SELECT * FROM social_pages WHERE status='active' AND woo_daily_mode IN ('draft','schedule') AND woo_store_url IS NOT NULL AND woo_store_url<>''")->fetchAll();
    foreach ($pages as $page) {
        $created=0;
        $lockName=null;
        try {
            $timezone = new DateTimeZone((string)($page['timezone'] ?: 'Asia/Dhaka'));
            $now = new DateTimeImmutable('now', $timezone);
            $today = $now->format('Y-m-d');
            $dailyCount = max(1, min(3, (int)($page['woo_posts_per_day'] ?? 1)));
            $postTimes = dailyPostTimes($page, 'woo', $dailyCount);
            if ($now->format('H:i') < $postTimes[0]) continue;
            $lockName=dailyGenerationLockName('woo',(int)$page['id'],$today);
            if(!acquireDailyGenerationLock($db,$lockName))continue;
            $dailyStartIndex=dailyPostCount($db,(int)$page['id'],'feed','woocommerce',$today);
            if($dailyStartIndex>=$dailyCount)continue;

            for ($dailyIndex = $dailyStartIndex; $dailyIndex < $dailyCount; $dailyIndex++) {
            $prepared = (new WooCommerceService())->prepareNext($page);
            $query = $db->prepare('SELECT c.*,p.page_name AS social_page_name,p.ai_provider,p.ai_model,p.encrypted_ai_api_key,p.ai_prompt,p.default_hashtags,p.instagram_enabled,p.instagram_caption_max,p.banned_words,p.post_instruction,p.caption_examples FROM content_items c JOIN social_pages p ON p.id=c.page_id WHERE c.id=? AND c.status="active"');
            $query->execute([(int)$prepared['item_id']]);
            $item = $query->fetch();
            if (!$item) throw new RuntimeException('Prepared WooCommerce product was not found.');

            ContentQualityService::assertTitleAvailable($db,$item,$item);

            $imageUrls = json_decode((string)($item['woo_batch_images_json'] ?? ''), true);
            if (!is_array($imageUrls) || $imageUrls === []) throw new RuntimeException('No prepared WooCommerce image batch was found.');
            $imageUrls = array_values(array_slice(array_filter($imageUrls, fn($url)=>is_string($url)&&str_starts_with($url,'https://')), 0, 10));
            if ($imageUrls === []) throw new RuntimeException('No valid WooCommerce image was found.');
            $selectedImageUrls=$imageUrls;
            $imageUrls=ContentQualityService::filterValidImages($imageUrls,false);

            $generated = AIProviderFactory::make($item)->generate([
                'page_name' => $item['social_page_name'],
                'title' => $item['title'],
                'description' => $item['description'],
                'price' => $item['price'],
                'features' => $item['features'],
                'category' => $item['category'],
                'source_url' => $item['source_url'],
                'post_instruction' => $item['post_instruction'] ?? '',
                'caption_examples' => $item['caption_examples'] ?? '',
            ], (string)$item['ai_prompt']);
            $copy = PostCopyFormatter::normalize($generated, (string)$item['default_hashtags']);

            $status = $page['woo_daily_mode'] === 'schedule' ? 'scheduled' : 'draft';
            $scheduledAt = $status === 'scheduled' ? $today . ' ' . $postTimes[$dailyIndex] . ':00' : null;
            $publishInstagram=!empty($item['instagram_enabled']);
            $quality=ContentQualityService::prepare($db,$item,$item,$copy);$igCopy=$publishInstagram?ContentQualityService::instagramCopy($quality,(int)($item['instagram_caption_max']??500)):['caption'=>'','hashtags'=>''];
            $insert = $db->prepare('INSERT INTO social_posts(page_id,content_item_id,caption,hashtags,call_to_action,image_url,image_urls_json,scheduled_at,status,title_fingerprint,caption_fingerprint,publish_to_instagram,instagram_caption,instagram_hashtags,instagram_status) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $insert->execute([
                (int)$item['page_id'],(int)$item['id'],$quality['caption'],$quality['hashtags'],$quality['call_to_action'],$imageUrls[0],
                json_encode($imageUrls,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),$scheduledAt,$status,$quality['title_fingerprint'],$quality['caption_fingerprint'],$publishInstagram?1:0,$igCopy['caption'],$igCopy['hashtags'],$publishInstagram?'pending':'off',
            ]);
            $postId = (int)$db->lastInsertId();
            applyAutomaticDestinations($db,$postId,(int)$item['page_id'],'feed');
            $created++;
            markDailyContentBatchUsed($db, $item, $selectedImageUrls);
            $audit = $db->prepare('INSERT INTO audit_logs(admin_id,action,entity_type,entity_id,details) VALUES(NULL,?,?,?,?)');
            $audit->execute(['woocommerce.daily_' . $status, 'social_post', $postId, json_encode(['product' => $item['title']], JSON_UNESCAPED_UNICODE)]);
            echo "Created daily {$status} " . ($dailyIndex + 1) . "/{$dailyCount} for page {$page['id']}\n";
            }
            $db->prepare('UPDATE social_pages SET woo_last_run_on=? WHERE id=?')->execute([$today,(int)$page['id']]);
        } catch (Throwable $e) {
            notifyAutomationFailure($db,$page,$e,'WooCommerce automation');
            echo "Daily WooCommerce automation failed for page {$page['id']}: " . substr($e->getMessage(), 0, 500) . "\n";
        } finally {
            if($lockName!==null)releaseDailyGenerationLock($db,$lockName);
        }
    }
}

function prepareDailyFolderPosts(PDO $db): void
{
    $pages=$db->query("SELECT * FROM social_pages WHERE status='active' AND folder_daily_mode IN ('draft','schedule') AND folder_source_slug IS NOT NULL AND folder_source_slug<>''")->fetchAll();
    foreach($pages as $page){
        $created=0;
        $lockName=null;
        try{
            $timezone=new DateTimeZone((string)($page['timezone']?:'Asia/Dhaka'));$now=new DateTimeImmutable('now',$timezone);$today=$now->format('Y-m-d');
            $dailyCount=max(1,min(3,(int)($page['folder_posts_per_day']??1)));
            $postTimes=dailyPostTimes($page,'folder',$dailyCount);
            if($now->format('H:i')<$postTimes[0])continue;
            $lockName=dailyGenerationLockName('folder',(int)$page['id'],$today);
            if(!acquireDailyGenerationLock($db,$lockName))continue;
            $dailyStartIndex=dailyPostCount($db,(int)$page['id'],'feed','server_folder',$today);
            if($dailyStartIndex>=$dailyCount)continue;

            for($dailyIndex=$dailyStartIndex;$dailyIndex<$dailyCount;$dailyIndex++){
            $prepared=(new FolderContentService())->prepareNext($page);
            $query=$db->prepare('SELECT c.*,p.page_name AS social_page_name,p.ai_provider,p.ai_model,p.encrypted_ai_api_key,p.ai_prompt,p.default_hashtags,p.instagram_enabled,p.instagram_caption_max,p.banned_words,p.post_instruction,p.caption_examples FROM content_items c JOIN social_pages p ON p.id=c.page_id WHERE c.id=? AND c.status="active"');
            $query->execute([(int)$prepared['item_id']]);$item=$query->fetch();if(!$item)throw new RuntimeException('Prepared folder content was not found.');
            $imageRefs=json_decode((string)($item['folder_batch_images_json']??''),true);
            if(!is_array($imageRefs)||$imageRefs===[])throw new RuntimeException('No folder image batch was found.');
            $folderImageLimit=max(1,min(10,(int)($page['folder_images_per_post']??10)));
            $imageRefs=array_values(array_slice(array_filter($imageRefs,fn($ref)=>is_string($ref)&&str_starts_with($ref,'local://')),0,$folderImageLimit));
            if($imageRefs===[])throw new RuntimeException('No valid folder image was found.');
            $selectedImageRefs=$imageRefs;
            $imageRefs=ImageBrandingService::applyBatch($page,$imageRefs,false);
            $imageRefs=ContentQualityService::filterValidImages($imageRefs,false);

            $generated=AIProviderFactory::make($item)->generate(['page_name'=>$item['social_page_name'],'title'=>$item['title'],'description'=>$item['description'],'price'=>'','features'=>'','category'=>'','source_url'=>'','post_instruction'=>$item['post_instruction']??'','caption_examples'=>$item['caption_examples']??''],(string)$item['ai_prompt']);
            $copy=PostCopyFormatter::normalize($generated,(string)$item['default_hashtags']);
            $status=$page['folder_daily_mode']==='schedule'?'scheduled':'draft';$scheduledAt=$status==='scheduled'?$today.' '.$postTimes[$dailyIndex].':00':null;
            $publishInstagram=!empty($item['instagram_enabled']);
            // Folder projects may contain several unused image batches under
            // one stable project title. Protect the exact caption, while the
            // image rotation prevents the same batch from being reused.
            // Folder image batches have their own deterministic round-robin
            // state. Reusing a project title/caption must not pause its next
            // image batch for the generic 15-day text-repeat window.
            $quality=ContentQualityService::prepare($db,$item,$item,$copy,null,'feed',false,false);$igCopy=$publishInstagram?ContentQualityService::instagramCopy($quality,(int)($item['instagram_caption_max']??500)):['caption'=>'','hashtags'=>''];
            $insert=$db->prepare('INSERT INTO social_posts(page_id,content_item_id,caption,hashtags,call_to_action,image_url,image_urls_json,scheduled_at,status,title_fingerprint,caption_fingerprint,publish_to_instagram,instagram_caption,instagram_hashtags,instagram_status) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $insert->execute([(int)$item['page_id'],(int)$item['id'],$quality['caption'],$quality['hashtags'],$quality['call_to_action'],$imageRefs[0],json_encode($imageRefs,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$scheduledAt,$status,$quality['title_fingerprint'],$quality['caption_fingerprint'],$publishInstagram?1:0,$igCopy['caption'],$igCopy['hashtags'],$publishInstagram?'pending':'off']);
            $postId=(int)$db->lastInsertId();applyAutomaticDestinations($db,$postId,(int)$item['page_id'],'feed');$created++;FolderContentService::markBatchUsed($db,(int)$item['id'],$selectedImageRefs);
            $audit=$db->prepare('INSERT INTO audit_logs(admin_id,action,entity_type,entity_id,details) VALUES(NULL,?,?,?,?)');
            $audit->execute(['folder.daily_'.$status,'social_post',$postId,json_encode(['folder'=>$prepared['folder']],JSON_UNESCAPED_UNICODE)]);
            echo "Created folder {$status} ".($dailyIndex+1)."/{$dailyCount} for page {$page['id']}\n";
            }
            $db->prepare('UPDATE social_pages SET folder_last_run_on=? WHERE id=?')->execute([$today,(int)$page['id']]);
        }catch(Throwable $e){notifyAutomationFailure($db,$page,$e,'Folder automation');echo "Daily folder automation failed for page {$page['id']}: ".substr($e->getMessage(),0,500)."\n";}
        finally{if($lockName!==null)releaseDailyGenerationLock($db,$lockName);}
    }
}

function prepareDailyStories(PDO $db): void
{
    $pages=$db->query("SELECT * FROM social_pages WHERE status='active' AND story_daily_mode='schedule'")->fetchAll();
    foreach($pages as $page){
        $created=0;
        $lockName=null;
        try{
            $timezone=new DateTimeZone((string)($page['timezone']?:'Asia/Dhaka'));
            $now=new DateTimeImmutable('now',$timezone);$today=$now->format('Y-m-d');
            $dailyCount=max(1,min(3,(int)($page['story_posts_per_day']??1)));
            $postTimes=dailyPostTimes($page,'story',$dailyCount);
            if($now->format('H:i')<$postTimes[0])continue;
            $lockName=dailyGenerationLockName('story',(int)$page['id'],$today);
            if(!acquireDailyGenerationLock($db,$lockName))continue;
            $occupiedStorySlots=dailyStorySlots($db,(int)$page['id'],$today,$postTimes);
            if(count($occupiedStorySlots)>=$dailyCount)continue;

            for($dailyIndex=0;$dailyIndex<$dailyCount;$dailyIndex++){
                if(isset($occupiedStorySlots[$postTimes[$dailyIndex]]))continue;
                $source=($page['story_source']??'server_folder')==='woocommerce'?'woocommerce':'server_folder';
                $storyPage=$page;
                if($source==='woocommerce'){
                    $storyPage['woo_image_batch_mode']='fixed';$storyPage['woo_images_per_post']=1;
                    $prepared=(new WooCommerceService())->prepareNext($storyPage,'story');
                }else{
                    $storyPage['folder_images_per_post']=1;
                    $prepared=(new FolderContentService())->prepareNext($storyPage,true,'story');
                }
                $query=$db->prepare('SELECT * FROM content_items WHERE id=? AND page_id=? AND status="active"');
                $query->execute([(int)$prepared['item_id'],(int)$page['id']]);$item=$query->fetch();
                if(!$item)throw new RuntimeException('Prepared Story content was not found.');
                // A folder Story may reuse a project name with a new image.
                // WooCommerce still keeps its separate 15-day product rule.
                if($source==='woocommerce')ContentQualityService::assertTitleAvailable($db,$page,$item,null,'story');
                $batchField=$source==='woocommerce'?'woo_batch_images_json':'folder_batch_images_json';
                $images=json_decode((string)($item[$batchField]??''),true);
                $image=(string)($images[0]??'');
                if(!str_starts_with($image,'https://')&&!str_starts_with($image,'local://'))throw new RuntimeException('No valid Story image was prepared.');
                $selectedImage=$image;
                if($source==='server_folder')$image=(string)(ImageBrandingService::applyBatch($page,[$image],true)[0]??'');
                ContentQualityService::validateImages([$image],false);
                $scheduledAt=$today.' '.$postTimes[$dailyIndex].':00';
                $publishInstagram=!empty($page['instagram_enabled']);
                $insert=$db->prepare("INSERT INTO social_posts(page_id,content_item_id,post_type,caption,hashtags,call_to_action,image_url,image_urls_json,scheduled_at,publish_to_instagram,instagram_caption,instagram_hashtags,instagram_status,status) VALUES(?,?,'story',?,?,?,?,?,?,?,?,?,?,'scheduled')");
                $insert->execute([(int)$page['id'],(int)$item['id'],(string)$item['title'],'','',$image,json_encode([$image],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$scheduledAt,$publishInstagram?1:0,'','',$publishInstagram?'pending':'off']);
                $storyId=(int)$db->lastInsertId();$created++;
                if($source==='woocommerce')markDailyContentBatchUsed($db,$item,[$selectedImage]);else FolderContentService::markBatchUsed($db,(int)$item['id'],[$selectedImage]);
                $audit=$db->prepare('INSERT INTO audit_logs(admin_id,action,entity_type,entity_id,details) VALUES(NULL,?,?,?,?)');
                $audit->execute(['story.daily_scheduled','social_post',$storyId,json_encode(['source'=>$source,'title'=>$item['title']],JSON_UNESCAPED_UNICODE)]);
                echo "Created Story ".($dailyIndex+1)."/{$dailyCount} for page {$page['id']} at {$postTimes[$dailyIndex]}\n";
            }
            $db->prepare('UPDATE social_pages SET story_last_run_on=? WHERE id=?')->execute([$today,(int)$page['id']]);
        }catch(Throwable $e){notifyAutomationFailure($db,$page,$e,'Story automation');echo "Daily Story automation failed for page {$page['id']}: ".substr($e->getMessage(),0,500)."\n";}
        finally{if($lockName!==null)releaseDailyGenerationLock($db,$lockName);}
    }
}

function markDailyContentBatchUsed(PDO $db, array $item, array $usedImages): void
{
    $all = json_decode((string)($item['woo_images_json'] ?? ''), true);
    $start = (int)($item['woo_next_image_index'] ?? 0);
    $next = is_array($all) && $start + count($usedImages) < count($all) ? $start + count($usedImages) : 0;
    $query = $db->prepare('UPDATE content_items SET last_drafted_at=?,woo_next_image_index=?,woo_batch_images_json=NULL WHERE id=?');
    $query->execute([date('Y-m-d H:i:s'), $next, (int)$item['id']]);
}

function dailyPostCount(PDO $db,int $pageId,string $postType,?string $sourceType,string $day):int
{
    // A terminally failed post has already consumed its daily time slot (the
    // publisher gave it the bounded 5/10/20-minute retries). Count it here so the
    // generator cannot create a replacement at a later slot and accidentally
    // publish two posts together.
    $nextDay=(new DateTimeImmutable($day))->modify('+1 day')->format('Y-m-d');
    $sql='SELECT COUNT(*) FROM social_posts s LEFT JOIN content_items c ON c.id=s.content_item_id WHERE s.page_id=? AND s.post_type=? AND s.archived_at IS NULL AND s.status IN ("draft","approved","scheduled","publishing","published","failed") AND s.created_at>=? AND s.created_at<?';
    $params=[$pageId,$postType,$day.' 00:00:00',$nextDay.' 00:00:00'];
    if($sourceType!==null){$sql.=' AND c.source_type=?';$params[]=$sourceType;}
    $query=$db->prepare($sql);$query->execute($params);return (int)$query->fetchColumn();
}

/** @param string[] $configuredTimes @return array<string,true> */
function dailyStorySlots(PDO $db,int $pageId,string $day,array $configuredTimes):array
{
    $nextDay=(new DateTimeImmutable($day))->modify('+1 day')->format('Y-m-d');
    // Drafts without a schedule are intentionally excluded. They are manual
    // work-in-progress and must not consume an automatic Story slot.
    $query=$db->prepare('SELECT scheduled_at,status FROM social_posts WHERE page_id=? AND post_type="story" AND archived_at IS NULL AND scheduled_at>=? AND scheduled_at<? AND status IN ("approved","scheduled","publishing","published","failed") ORDER BY id ASC');
    $query->execute([$pageId,$day.' 00:00:00',$nextDay.' 00:00:00']);
    $configured=array_fill_keys($configuredTimes,true);$occupied=[];$floating=0;
    foreach($query->fetchAll() as $row){
        $scheduled=trim((string)($row['scheduled_at']??''));$time=$scheduled!==''?substr($scheduled,11,5):'';
        if(isset($configured[$time]))$occupied[$time]=true;else$floating++;
    }
    // Retry times move by 5/10/20 minutes and terminal failures may have no
    // scheduled_at. Keep them as one consumed slot so cron cannot create a
    // duplicate while a bounded retry is in progress.
    foreach($configuredTimes as $time){if($floating<1)break;if(isset($occupied[$time]))continue;$occupied[$time]=true;$floating--;}
    return $occupied;
}

function dailyGenerationLockName(string $scope,int $pageId,string $day):string
{
    return 'social_daily_'.preg_replace('/[^a-z0-9_]/i','_',$scope).'_'.$pageId.'_'.str_replace('-','',$day);
}

function acquireDailyGenerationLock(PDO $db,string $lockName):bool
{
    $query=$db->prepare('SELECT GET_LOCK(?,0)');$query->execute([$lockName]);return (int)$query->fetchColumn()===1;
}

function releaseDailyGenerationLock(PDO $db,string $lockName):void
{
    try{$query=$db->prepare('SELECT RELEASE_LOCK(?)');$query->execute([$lockName]);}catch(Throwable){}
}

function shouldRetryPublishFailure(string $message,string $type,int $attempt):bool
{
    if($attempt>=4||$type==='token_invalid')return false;
    $lower=strtolower($message);

    // Authentication, permission and malformed-request failures cannot heal
    // with time. Retrying them only creates API traffic and noisy alerts.
    if(str_contains($lower,'permission')
        || str_contains($lower,'not authorized')
        || str_contains($lower,'unsupported get request')
        || preg_match('/\bcode\s*(?:10|100|190|200)\b/i',$message))return false;

    // Retry only network timeouts, rate limits and Meta server-side failures.
    return str_contains($lower,'timeout')
        || str_contains($lower,'timed out')
        || str_contains($lower,'temporarily unavailable')
        || str_contains($lower,'connection reset')
        || str_contains($lower,"couldn't connect")
        || preg_match('/http\s+(?:408|429|5\d\d)\b/i',$message)===1;
}

function publishRetryDelaySeconds(int $attempt):int
{
    return match($attempt){1=>300,2=>600,default=>1200};
}

function activeSocialConnection(PDO $db,int $pageId,string $platform):array
{
    $query=$db->prepare('SELECT * FROM social_connections WHERE page_id=? AND platform=? AND status="active" ORDER BY id LIMIT 1');$query->execute([$pageId,$platform]);$connection=$query->fetch();
    if(!$connection)throw new RuntimeException(ucfirst($platform).' connection is missing or paused.');
    if(!empty($connection['token_expires_at'])&&strtotime((string)$connection['token_expires_at'])<=time()+300){
        $connection=$platform==='tiktok'?(new TikTokService())->refreshConnection($db,$connection):(new LinkedInService())->refreshConnection($db,$connection);
    }
    return $connection;
}

function applyAutomaticDestinations(PDO $db,int $postId,int $pageId,string $kind):void
{
    if($kind==='story')return;
    PlatformScheduleService::applyAutomatic($db,$postId,$pageId,$kind);
}

function platformDue(mixed $value):bool{return $value===null||$value===''||strtotime((string)$value)<=time();}

function processDuePlatformPosts(PDO $db,LinkedInService $linkedin,TikTokService $tiktok,int $limit):void
{
    $query=$db->prepare("SELECT s.* FROM social_posts s JOIN social_pages p ON p.id=s.page_id WHERE s.status='published' AND p.status='active' AND ((s.publish_to_linkedin=1 AND s.linkedin_status IN ('pending','retrying') AND (s.linkedin_scheduled_at IS NULL OR s.linkedin_scheduled_at<=NOW())) OR (s.publish_to_tiktok=1 AND s.tiktok_status IN ('pending','retrying') AND (s.tiktok_scheduled_at IS NULL OR s.tiktok_scheduled_at<=NOW()))) ORDER BY COALESCE(s.linkedin_scheduled_at,s.tiktok_scheduled_at,s.published_at) LIMIT {$limit}");
    $query->execute();
    foreach($query->fetchAll() as $post){
        foreach(['linkedin'=>$linkedin,'tiktok'=>$tiktok] as $platform=>$service){
            $enabled=!empty($post['publish_to_'.$platform]);$status=(string)($post[$platform.'_status']??'off');$due=$post[$platform.'_scheduled_at']??null;
            if(!$enabled||!in_array($status,['pending','retrying'],true)||!platformDue($due))continue;
            try{
                $connection=activeSocialConnection($db,(int)$post['page_id'],$platform);
                $db->prepare("UPDATE social_posts SET {$platform}_status='publishing',{$platform}_error=NULL WHERE id=? AND {$platform}_status IN ('pending','retrying')")->execute([(int)$post['id']]);
                $result=$service->publish($connection,$post);
                $idColumn=$platform==='linkedin'?'linkedin_post_id':'tiktok_publish_id';
                $db->prepare("UPDATE social_posts SET {$platform}_status='published',{$idColumn}=?,{$platform}_published_at=NOW(),{$platform}_error=NULL WHERE id=?")->execute([$result['id'],(int)$post['id']]);
                echo ucfirst($platform)." published for post {$post['id']}\n";
            }catch(Throwable $error){
                $message=substr($error->getMessage(),0,1000);
                $db->prepare("UPDATE social_posts SET {$platform}_status='failed',{$platform}_error=? WHERE id=?")->execute([$message,(int)$post['id']]);
                NotificationService::create($db,NotificationService::classify($message),ucfirst($platform).' scheduled publish failed',$message,(int)$post['page_id'],(int)$post['id'],'error',$platform.':scheduled:'.$post['id']);
                echo ucfirst($platform)." failed for post {$post['id']}: {$message}\n";
            }
        }
    }
}

/** @return string[] Sorted HH:MM posting times for the enabled daily slots. */
function dailyPostTimes(array $page, string $source, int $count): array
{
    $defaults = match($source){'woo'=>['10:00','15:00','20:00'],'story'=>['09:00','15:00','21:00'],default=>['11:00','16:00','21:00']};
    $base = match($source){'woo'=>'woo_post_time','story'=>'story_post_time',default=>'folder_post_time'};
    $times = [];
    for ($slot = 1; $slot <= max(1, min(3, $count)); $slot++) {
        $column = $slot === 1 ? $base : $base . '_' . $slot;
        $value = substr((string)($page[$column] ?? $defaults[$slot - 1]), 0, 5);
        $times[] = preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $value) ? $value : $defaults[$slot - 1];
    }
    sort($times, SORT_STRING);
    return array_values($times);
}

function logAttempt(PDO $db,int $postId,int $attempt,string $result,?int $status,string $message):void
{
    $q=$db->prepare('INSERT INTO publishing_attempts(post_id,attempt_number,result,http_status,response_message) VALUES(?,?,?,?,?)');
    $q->execute([$postId,$attempt,$result,$status,$message]);
}

function notifyAutomationFailure(PDO $db,array $page,Throwable $error,string $context):void
{
    $type=NotificationService::classify($error->getMessage());$title=match($type){'folder_empty'=>'Folder or image unavailable','token_invalid'=>'Meta token expired or invalid','ai_key_or_balance'=>'AI key or balance problem',default=>$context.' failed'};
    NotificationService::create($db,$type,$title,$error->getMessage(),(int)($page['id']??0),null,$type==='folder_empty'?'warning':'error',$type.':'.($page['id']??0).':'.date('Y-m-d'));
}
