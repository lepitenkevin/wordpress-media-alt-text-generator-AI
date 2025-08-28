<?php
/*
Plugin Name: AI ALT Generator
Description: Adds a button to generate AI-based ALT text in Media Library.
Version: 1.2
Author: Kevin Victor Lepiten
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
 * INLINE JS
 * ========================
 */
add_action('admin_footer', function() {
    $screen = get_current_screen();
    if ($screen && $screen->base === 'upload') {
        ?>
        <script>
        jQuery(document).ready(function($) {
            wp.media.view.Attachment.Details.TwoColumn = wp.media.view.Attachment.Details.TwoColumn.extend({
                render: function() {
                    wp.media.view.Attachment.Details.TwoColumn.__super__.render.apply(this, arguments);

                    var textarea = this.$el.find('#attachment-details-two-column-alt-text');
                    if (textarea.length && !this.$el.find('.ai-generate-alt').length) {
                        var button = $('<button type="button" class="button ai-generate-alt" style="margin-top:6px;">Generate ALT Text (AI)</button>');
                        textarea.after(button);

                        var attachmentId = this.model.get('id');

                        button.on('click', function() {
                            button.text('Generating...');
                            $.post(ajaxurl, {
                                action: 'ai_generate_alt',
                                attachment_id: attachmentId,
                            }, function(response) {
                                if (response.success) {
                                    textarea.val(response.data);
                                } else {
                                    alert(response.data);
                                }
                                button.text('Generate ALT Text (AI)');
                            });
                        });
                    }

                    return this;
                }
            });
        });
        </script>
        <?php
    }
});

/**
 * ========================
 * AJAX HANDLER
 * ========================
 */
add_action('wp_ajax_ai_generate_alt', function() {
    $attachment_id = intval($_POST['attachment_id']);
    if (!$attachment_id) {
        wp_send_json_error("Could not find attachment ID.");
    }

    $image_url = wp_get_attachment_url($attachment_id);
    if (!$image_url) {
        wp_send_json_error("Image URL not found.");
    }

    // ✅ Fetch API Key from settings
    $api_key = get_option('ai_alt_generator_api_key', '');
    if (empty($api_key)) {
        wp_send_json_error("API key not set. Please add it in Settings → AI ALT Generator.");
    }

    $messages = [
        ["role" => "system", "content" => "You are an assistant that writes concise, descriptive ALT text for images."],
        ["role" => "user", "content" => "Generate a short descriptive alt text for this image only (do not include 'ALT text:' prefix or quotes): " . $image_url]
    ];

    $ch = curl_init("https://openrouter.ai/api/v1/chat/completions");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer $api_key",
        "Content-Type: application/json"
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        "model" => "openai/gpt-4o-mini",
        "messages" => $messages,
        "max_tokens" => 60,
        "temperature" => 0.4
    ]));

    $result = curl_exec($ch);
    if (curl_errno($ch)) {
        wp_send_json_error("cURL error: " . curl_error($ch));
    }
    curl_close($ch);

    $data = json_decode($result, true);
    if (isset($data['choices'][0]['message']['content'])) {
        $alt_text = trim($data['choices'][0]['message']['content']);

        // ✅ Cleanup: remove "ALT text:" and quotes if present
        $alt_text = preg_replace('/^ALT text:\s*/i', '', $alt_text);
        $alt_text = trim($alt_text, "\"'");

        wp_send_json_success($alt_text);
    } else {
        wp_send_json_error("Failed to generate ALT text.");
    }
});
