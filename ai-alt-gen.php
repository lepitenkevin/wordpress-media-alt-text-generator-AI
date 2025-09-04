<?php
/*
Plugin Name: AI ALT text Generator (openrouter.ai   based   )
Description: Adds a button to generate AI-based ALT text in Media Library.
Version: 2.0
Author: Kevin Victor Lepiten
Author URI: https://kevinlepiten.com
*/


if (!defined('ABSPATH')) exit;

/**
 * ========================
 * ADMIN SETTINGS PAGE
 * ========================
 */
add_action('admin_menu', function () {
    add_options_page(
        'AI ALT Generator Settings',
        'AI ALT Generator',
        'manage_options',
        'ai-alt-generator',
        'ai_alt_generator_settings_page'
    );
});


function ai_alt_generator_settings_page() {
    ?>
    <div class="wrap">
        <h1>AI ALT Generator Settings</h1>
        <form method="post" action="options.php">
            <?php
            settings_fields('ai_alt_generator_options');
            do_settings_sections('ai-alt-generator');
            submit_button();
            ?>
        </form>
    </div>
    <?php
}

add_action('admin_init', function () {
    register_setting('ai_alt_generator_options', 'ai_alt_generator_api_key');

    add_settings_section(
        'ai_alt_generator_main',
        'API Configuration',
        null,
        'ai-alt-generator'
    );

    add_settings_field(
        'ai_alt_generator_api_key',
        'OpenRouter API Key',
        function () {
            $value = esc_attr(get_option('ai_alt_generator_api_key', ''));
            echo '<input type="text" name="ai_alt_generator_api_key" value="' . $value . '" style="width: 400px;" />';
            echo '<p class="description">Enter your OpenRouter API key. You can get one from <a href="https://openrouter.ai/" target="_blank">openrouter.ai</a>.</p>';
        },
        'ai-alt-generator',
        'ai_alt_generator_main'
    );
});


/**
 * ========================
 * ENQUEUE SCRIPTS
 * ========================
 */
// ✨ CHANGED: Use admin_enqueue_scripts to add our JS file.
add_action('admin_enqueue_scripts', function($hook) {
    // Only load our script on the Media Library page.
    if ('upload.php' !== $hook) {
        return;
    }

    // Register and enqueue the script.
    wp_enqueue_script(
        'ai-alt-generator-script', // Unique handle
        plugin_dir_url(__FILE__) . 'admin.js', // Path to the JS file
        ['jquery', 'media-views'], // Dependencies
        '1.3', // Version number
        true // Load in footer
    );

    // ✨ CHANGED: Pass data (like our nonce) to the script securely.
    wp_localize_script(
        'ai-alt-generator-script',
        'aiAltGenerator', // JS object name to access the data
        [
            'nonce' => wp_create_nonce('ai_alt_generate_nonce'), // Create and pass the nonce
        ]
    );
});


/**
 * ========================
 * AJAX HANDLER
 * ========================
 */
add_action('wp_ajax_ai_generate_alt', function() {
    // ✨ CHANGED: Verify the nonce for security.
    check_ajax_referer('ai_alt_generate_nonce', 'security');

    $attachment_id = isset($_POST['attachment_id']) ? intval($_POST['attachment_id']) : 0;
    if (!$attachment_id) {
        wp_send_json_error("Could not find attachment ID.");
    }

    $image_url = wp_get_attachment_url($attachment_id);
    if (!$image_url) {
        wp_send_json_error("Image URL not found.");
    }

    $api_key = get_option('ai_alt_generator_api_key', '');
    if (empty($api_key)) {
        wp_send_json_error("API key not set. Please add it in Settings → AI ALT Generator.");
    }
    
    // ✨ CHANGED: Use the WordPress HTTP API instead of cURL.
    $api_url = "https://openrouter.ai/api/v1/chat/completions";
    $body = [
        "model" => "openai/gpt-4o-mini",
        "messages" => [
            ["role" => "system", "content" => "You are an assistant that writes concise, descriptive ALT text for images."],
            ["role" => "user", "content" => "Generate a short descriptive alt text for this image only (do not include 'ALT text:' prefix or quotes): " . $image_url]
        ],
        "max_tokens" => 60,
        "temperature" => 0.4
    ];

    $response = wp_remote_post($api_url, [
        'headers' => [
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type'  => 'application/json',
        ],
        'body'    => json_encode($body),
        'timeout' => 20, // Add a timeout
    ]);

    if (is_wp_error($response)) {
        wp_send_json_error("API request failed: " . $response->get_error_message());
    }

    $response_body = wp_remote_retrieve_body($response);
    $data = json_decode($response_body, true);

    if (isset($data['choices'][0]['message']['content'])) {
        $alt_text = trim($data['choices'][0]['message']['content']);

        // Cleanup: remove "ALT text:" and quotes if present
        $alt_text = preg_replace('/^alt text:\s*/i', '', $alt_text);
        $alt_text = trim($alt_text, "\"'");

        wp_send_json_success($alt_text);
    } elseif (isset($data['error']['message'])) {
        // Provide a more specific error from the API if available
        wp_send_json_error("API Error: " . $data['error']['message']);
    } else {
        wp_send_json_error("Failed to generate ALT text. Unexpected API response.");
    }
});

/**
 * ========================
 * BULK ACTION FOR MEDIA
 * ========================
 */
add_filter('bulk_actions-upload', function($bulk_actions) {
    $bulk_actions['ai_generate_alt_bulk'] = __('Generate ALT Text (AI)', 'ai-alt-generator');
    return $bulk_actions;
});

add_filter('handle_bulk_actions-upload', function($redirect_to, $doaction, $attachment_ids) {
    if ($doaction !== 'ai_generate_alt_bulk') {
        return $redirect_to;
    }

    $generated = 0;
    $failed = 0;

    foreach ($attachment_ids as $attachment_id) {
        $image_url = wp_get_attachment_url($attachment_id);
        if (!$image_url) {
            $failed++;
            continue;
        }

        $api_key = get_option('ai_alt_generator_api_key', '');
        if (empty($api_key)) {
            $failed++;
            continue;
        }

        // Build request
        $api_url = "https://openrouter.ai/api/v1/chat/completions";
        $body = [
            "model" => "openai/gpt-4o-mini",
            "messages" => [
                ["role" => "system", "content" => "You are an assistant that writes concise, descriptive ALT text for images."],
                ["role" => "user", "content" => "Generate a short descriptive alt text for this image only (do not include 'ALT text:' prefix or quotes): " . $image_url]
            ],
            "max_tokens" => 60,
            "temperature" => 0.4
        ];

        $response = wp_remote_post($api_url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ],
            'body'    => json_encode($body),
            'timeout' => 20,
        ]);

        if (is_wp_error($response)) {
            $failed++;
            continue;
        }

        $response_body = wp_remote_retrieve_body($response);
        $data = json_decode($response_body, true);

        if (isset($data['choices'][0]['message']['content'])) {
            $alt_text = trim($data['choices'][0]['message']['content']);
            $alt_text = preg_replace('/^alt text:\s*/i', '', $alt_text);
            $alt_text = trim($alt_text, "\"'");

            // ✅ Update the attachment alt meta
            update_post_meta($attachment_id, '_wp_attachment_image_alt', sanitize_text_field($alt_text));

            $generated++;
        } else {
            $failed++;
        }
    }

    // Add query args for admin notice
    $redirect_to = add_query_arg([
        'ai_alt_generated' => $generated,
        'ai_alt_failed'    => $failed
    ], $redirect_to);

    return $redirect_to;
}, 10, 3);

/**
 * ========================
 * ADMIN NOTICE AFTER BULK ACTION
 * ========================
 */
add_action('admin_notices', function() {
    if (!empty($_GET['ai_alt_generated']) || !empty($_GET['ai_alt_failed'])) {
        $generated = intval($_GET['ai_alt_generated']);
        $failed = intval($_GET['ai_alt_failed']);
        
        if ($generated > 0) {
            echo '<div class="updated notice"><p>' . esc_html($generated) . ' ALT texts generated successfully.</p></div>';
        }
        if ($failed > 0) {
            echo '<div class="error notice"><p>' . esc_html($failed) . ' ALT texts failed.</p></div>';
        }
    }
});


