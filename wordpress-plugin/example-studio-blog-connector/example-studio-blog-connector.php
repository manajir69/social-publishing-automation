<?php
/**
 * Plugin Name: Social Blog Automation Connector
 * Description: Securely receives researched blog drafts and saves Yoast SEO fields for the Social Automation system.
 * Version: 1.1.0
 * Author: Social Automation
 * Requires at least: 6.0
 * Requires PHP: 8.1
 */

declare(strict_types=1);

if (!defined('ABSPATH')) exit;

add_action('rest_api_init', static function (): void {
    register_rest_route('example-studio/v1', '/status', [
        'methods' => WP_REST_Server::READABLE,
        'permission_callback' => static function (): bool {
            return current_user_can('edit_posts');
        },
        'callback' => static function (): WP_REST_Response {
            return new WP_REST_Response([
                'connected' => true,
                'user' => wp_get_current_user()->user_login,
                'can_publish' => current_user_can('publish_posts'),
                'yoast_active' => defined('WPSEO_VERSION'),
                'yoast_version' => defined('WPSEO_VERSION') ? WPSEO_VERSION : null,
            ]);
        },
    ]);
    register_rest_route('example-studio/v1', '/articles', [
        'methods' => WP_REST_Server::CREATABLE,
        'permission_callback' => static function (): bool {
            return current_user_can('edit_posts');
        },
        'callback' => 'bi_blog_connector_create_article',
    ]);
});

/** @return WP_REST_Response|WP_Error */
function bi_blog_connector_create_article(WP_REST_Request $request)
{
    $title = sanitize_text_field((string)$request->get_param('title'));
    $content = (string)$request->get_param('content_html');
    $focusKeyphrase = sanitize_text_field((string)$request->get_param('focus_keyphrase'));
    $seoTitle = sanitize_text_field((string)$request->get_param('seo_title'));
    $metaDescription = sanitize_text_field((string)$request->get_param('meta_description'));
    if ($title === '' || trim(wp_strip_all_tags($content)) === '' || $focusKeyphrase === '' || $seoTitle === '' || $metaDescription === '') {
        return new WP_Error('bi_missing_fields', 'Title, article content and Yoast SEO fields are required.', ['status' => 422]);
    }

    $status = (string)$request->get_param('status') === 'publish' ? 'publish' : 'draft';
    if ($status === 'publish' && !current_user_can('publish_posts')) {
        return new WP_Error('bi_cannot_publish', 'This WordPress user cannot publish posts.', ['status' => 403]);
    }

    $content = preg_replace_callback('/<\/?h1\b[^>]*>/i', static function (array $match): string {
        return str_starts_with(strtolower($match[0]), '</') ? '</h2>' : '<h2>';
    }, $content) ?: $content;
    $content = wp_kses_post($content);

    $post = [
        'post_type' => 'post',
        'post_status' => $status,
        'post_title' => $title,
        'post_name' => sanitize_title((string)$request->get_param('slug')),
        'post_excerpt' => sanitize_textarea_field((string)$request->get_param('excerpt')),
        'post_content' => $content,
        'post_author' => get_current_user_id(),
    ];

    $categoryId = absint($request->get_param('category_id'));
    if ($categoryId > 0 && term_exists($categoryId, 'category')) $post['post_category'] = [$categoryId];

    $postId = wp_insert_post(wp_slash($post), true);
    if (is_wp_error($postId)) return $postId;

    update_post_meta($postId, '_yoast_wpseo_focuskw', $focusKeyphrase);
    update_post_meta($postId, '_yoast_wpseo_title', $seoTitle);
    update_post_meta($postId, '_yoast_wpseo_metadesc', $metaDescription);
    update_post_meta($postId, '_bi_automation_generated', '1');

    $sources = bi_blog_connector_clean_sources((array)$request->get_param('sources'));
    if ($sources !== []) update_post_meta($postId, '_bi_research_sources', wp_json_encode($sources, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

    $tagIds = [];
    foreach (array_slice((array)$request->get_param('tags'), 0, 8) as $tag) {
        $name = sanitize_text_field((string)$tag);
        if ($name === '') continue;
        $term = term_exists($name, 'post_tag');
        if (!$term) $term = wp_insert_term($name, 'post_tag');
        if (is_wp_error($term)) continue;
        $tagIds[] = (int)(is_array($term) ? $term['term_id'] : $term);
    }
    if ($tagIds !== []) wp_set_post_terms($postId, array_values(array_unique($tagIds)), 'post_tag', false);

    $featuredMediaId = absint($request->get_param('featured_media_id'));
    if ($featuredMediaId > 0 && wp_attachment_is_image($featuredMediaId) && current_user_can('edit_post', $featuredMediaId)) {
        set_post_thumbnail($postId, $featuredMediaId);
        $imageAlt = sanitize_text_field((string)$request->get_param('image_alt'));
        if ($imageAlt !== '') update_post_meta($featuredMediaId, '_wp_attachment_image_alt', $imageAlt);
    }

    // Trigger normal save hooks once more after Yoast fields are present.
    wp_update_post(['ID' => $postId]);
    clean_post_cache($postId);

    return new WP_REST_Response([
        'id' => $postId,
        'status' => get_post_status($postId),
        'link' => get_permalink($postId),
        'edit_link' => get_edit_post_link($postId, 'raw'),
        'yoast' => [
            'focus_keyphrase' => $focusKeyphrase,
            'seo_title' => $seoTitle,
            'meta_description' => $metaDescription,
        ],
    ], 201);
}

/** @return array<int,array{name:string,url:string}> */
function bi_blog_connector_clean_sources(array $sources): array
{
    $clean = [];
    foreach (array_slice($sources, 0, 15) as $source) {
        if (!is_array($source)) continue;
        $url = esc_url_raw((string)($source['url'] ?? ''), ['https']);
        if ($url === '' || !str_starts_with(strtolower($url), 'https://')) continue;
        $clean[] = [
            'name' => sanitize_text_field((string)($source['name'] ?? wp_parse_url($url, PHP_URL_HOST))),
            'url' => $url,
        ];
    }
    return $clean;
}

add_action('admin_notices', static function (): void {
    if (!current_user_can('manage_options') || !isset($_GET['activate'])) return;
    echo '<div class="notice notice-success is-dismissible"><p><strong>Social Blog Automation Connector activated.</strong> Create a dedicated WordPress Application Password and add this website in Social Automation → Blog Automation.</p></div>';
});
