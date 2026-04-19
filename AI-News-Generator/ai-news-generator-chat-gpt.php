<?php
/**
 * Plugin Name:       AI News Generator Chat GPT
 * Plugin URI:        https://github.com/yourname/ai-news-generator-chat-gpt
 * Description:       Потужний AI-генератор новин на базі ChatGPT. Генерує повні новини, заголовки, Meta Description та Meta Keywords прямо в Gutenberg.
 * Version:           1.0.0
 * Author:            Grok + Руслан
 * License:           GPL-2.0+
 * Text Domain:       ai-news-generator-chat-gpt
 * Requires PHP:      8.2
 * Requires at least: 6.4
 */

if (!defined('ABSPATH')) {
    exit;
}

class AI_News_Generator_ChatGPT {
    private static $instance = null;
    private $version = '1.0.0';

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('init', [$this, 'register_block']);
        add_action('rest_api_init', [$this, 'register_rest_routes']);
        add_action('admin_menu', [$this, 'add_settings_page']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('enqueue_block_editor_assets', [$this, 'enqueue_editor_assets']);
    }

    public function get_api_key() {
        if (defined('OPENAI_API_KEY') && OPENAI_API_KEY) return OPENAI_API_KEY;
        return get_option('ai_news_chatgpt_key', '');
    }

    public function register_block() {
        register_block_type('ai-news-chatgpt/news-generator', [
            'apiVersion'      => 3,
            'editor_script'   => 'ai-news-chatgpt-block-editor',
            'render_callback' => [$this, 'render_block'],
            'attributes'      => [
                'title'           => ['type' => 'string', 'default' => ''],
                'sourceText'      => ['type' => 'string', 'default' => ''],
                'tone'            => ['type' => 'string', 'default' => 'neutral'],
                'length'          => ['type' => 'string', 'default' => 'medium'],
                'generateMeta'    => ['type' => 'boolean', 'default' => true],
                'generatedHtml'   => ['type' => 'string', 'default' => ''],
                'metaDescription' => ['type' => 'string', 'default' => ''],
                'metaKeywords'    => ['type' => 'string', 'default' => ''],
            ],
        ]);
    }

    public function render_block($attributes) {
        if (empty($attributes['generatedHtml'])) {
            return '<div class="news-placeholder p-8 text-center border-2 border-dashed border-purple-400 rounded-3xl text-gray-600">AI News Generator Chat GPT<br><strong>Натисніть у бічній панелі, щоб згенерувати новину</strong></div>';
        }

        $allowed = wp_kses_allowed_html('post');
        $allowed['style'] = true;
        $allowed['script'] = ['src' => true];

        return '<div class="ai-news-chatgpt-block">' . wp_kses($attributes['generatedHtml'], $allowed) . '</div>';
    }

    public function register_rest_routes() {
        register_rest_route('ai-news-chatgpt/v1', '/generate', [
            'methods'  => 'POST',
            'callback' => [$this, 'generate_news'],
            'permission_callback' => function () { return current_user_can('edit_posts'); },
        ]);
    }

    public function generate_news(WP_REST_Request $request) {
        $title        = sanitize_text_field($request->get_param('title'));
        $sourceText   = sanitize_textarea_field($request->get_param('sourceText'));
        $tone         = sanitize_text_field($request->get_param('tone'));
        $length       = sanitize_text_field($request->get_param('length'));
        $generateMeta = (bool)$request->get_param('generateMeta');

        if (empty($title) && empty($sourceText)) {
            return new WP_Error('empty', 'Введіть назву або короткий опис новини', ['status' => 400]);
        }

        $api_key = $this->get_api_key();
        if (empty($api_key)) {
            return new WP_Error('no_key', 'OpenAI API ключ не налаштований', ['status' => 400]);
        }

        $system_prompt = "Ти — професійний журналіст і SEO-спеціаліст. Створи повноцінну новину українською мовою. 
Тон: {$tone}. Довжина: {$length}. 
Зроби текст живим, сучасним, з чіткою структурою (H2, H3, абзаци). 
Повертай відповідь ТІЛЬКИ у форматі JSON:
{
  \"html\": \"повна HTML-нова з Tailwind CSS\",
  \"meta_description\": \"...\",
  \"meta_keywords\": \"...\"
}";

        $user_prompt = "Назва новини: {$title}\nКлючові факти / короткий опис: {$sourceText}";

        $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ],
            'body' => wp_json_encode([
                'model'       => 'gpt-4o-mini',
                'messages'    => [
                    ['role' => 'system', 'content' => $system_prompt],
                    ['role' => 'user',   'content' => $user_prompt],
                ],
                'temperature' => 0.78,
                'max_tokens'  => 4200,
            ]),
            'timeout' => 90,
        ]);

        if (is_wp_error($response)) {
            return new WP_Error('openai_error', $response->get_error_message(), ['status' => 500]);
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        $json = json_decode($body['choices'][0]['message']['content'] ?? '{}', true);

        return rest_ensure_response([
            'html'            => $json['html'] ?? '',
            'meta_description'=> $json['meta_description'] ?? '',
            'meta_keywords'   => $json['meta_keywords'] ?? ''
        ]);
    }

    public function enqueue_editor_assets() {
        wp_register_script('ai-news-chatgpt-block-editor', '', ['wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor'], $this->version, true);

        wp_localize_script('ai-news-chatgpt-block-editor', 'aiNewsChatGPT', [
            'restUrl' => rest_url('ai-news-chatgpt/v1/generate'),
            'nonce'   => wp_create_nonce('wp_rest'),
        ]);

        $js = <<<'JS'
(function() {
    const { registerBlockType } = wp.blocks;
    const { useBlockProps, InspectorControls } = wp.blockEditor;
    const { PanelBody, TextControl, TextareaControl, SelectControl, ToggleControl, Button, Notice } = wp.components;
    const { useState } = wp.element;

    registerBlockType('ai-news-chatgpt/news-generator', {
        title: 'AI News Generator Chat GPT',
        icon: 'media-document',
        category: 'common',
        attributes: {
            title: { type: 'string', default: '' },
            sourceText: { type: 'string', default: '' },
            tone: { type: 'string', default: 'neutral' },
            length: { type: 'string', default: 'medium' },
            generateMeta: { type: 'boolean', default: true },
            generatedHtml: { type: 'string', default: '' },
            metaDescription: { type: 'string', default: '' },
            metaKeywords: { type: 'string', default: '' },
        },
        edit: function(props) {
            const { attributes, setAttributes } = props;
            const [isGenerating, setIsGenerating] = useState(false);
            const [error, setError] = useState('');

            const generate = async () => {
                if (!attributes.title && !attributes.sourceText) {
                    setError('Введіть назву або короткий опис новини!');
                    return;
                }
                setIsGenerating(true);
                setError('');

                try {
                    const res = await fetch(aiNewsChatGPT.restUrl, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': aiNewsChatGPT.nonce },
                        body: JSON.stringify({
                            title: attributes.title,
                            sourceText: attributes.sourceText,
                            tone: attributes.tone,
                            length: attributes.length,
                            generateMeta: attributes.generateMeta
                        })
                    });

                    const data = await res.json();
                    if (!res.ok) throw new Error(data.message || 'Помилка генерації');

                    setAttributes({
                        generatedHtml: data.html,
                        metaDescription: data.meta_description,
                        metaKeywords: data.meta_keywords
                    });
                } catch (err) {
                    setError(err.message);
                } finally {
                    setIsGenerating(false);
                }
            };

            return wp.element.createElement('div', useBlockProps(),
                wp.element.createElement(InspectorControls, {},
                    wp.element.createElement(PanelBody, { title: 'AI News Generator Chat GPT', initialOpen: true },
                        wp.element.createElement(TextControl, { label: 'Назва новини', value: attributes.title, onChange: (v) => setAttributes({title: v}) }),
                        wp.element.createElement(TextareaControl, { label: 'Короткий опис або ключові факти', value: attributes.sourceText, onChange: (v) => setAttributes({sourceText: v}) }),
                        wp.element.createElement(SelectControl, { label: 'Тон новини', value: attributes.tone, options: [
                            {label: 'Нейтральний', value: 'neutral'},
                            {label: 'Офіційний', value: 'formal'},
                            {label: 'Сенсаційний', value: 'sensational'},
                            {label: 'Аналітичний', value: 'analytical'}
                        ], onChange: (v) => setAttributes({tone: v}) }),
                        wp.element.createElement(SelectControl, { label: 'Довжина новини', value: attributes.length, options: [
                            {label: 'Коротка', value: 'short'},
                            {label: 'Середня', value: 'medium'},
                            {label: 'Довга', value: 'long'}
                        ], onChange: (v) => setAttributes({length: v}) }),
                        wp.element.createElement(ToggleControl, { label: 'Генерувати Meta Description + Keywords', checked: attributes.generateMeta, onChange: (v) => setAttributes({generateMeta: v}) }),
                        wp.element.createElement(Button, { isPrimary: true, isBusy: isGenerating, onClick: generate, style: {marginTop: '15px', width: '100%'} },
                            isGenerating ? 'Генерую новину...' : '🚀 Згенерувати новину'
                        ),
                        error && wp.element.createElement(Notice, {status: 'error', isDismissible: true}, error)
                    )
                ),
                attributes.generatedHtml 
                    ? wp.element.createElement('div', {dangerouslySetInnerHTML: {__html: attributes.generatedHtml}, style: {padding: '20px', border: '2px dashed #8b5cf6', borderRadius: '12px'}})
                    : wp.element.createElement('div', {style: {padding: '40px', textAlign: 'center', color: '#666', border: '2px dashed #ccc', borderRadius: '12px'}}, 'Тут з’явиться згенерована новина')
            );
        },
        save: function({ attributes }) {
            if (!attributes.generatedHtml) return null;
            return wp.element.createElement('div', {dangerouslySetInnerHTML: {__html: attributes.generatedHtml}});
        }
    });
})();
JS;

        wp_add_inline_script('ai-news-chatgpt-block-editor', $js, 'after');
    }

    public function add_settings_page() {
        add_options_page('AI News Generator Chat GPT', 'AI News Chat GPT', 'manage_options', 'ai-news-chatgpt', [$this, 'settings_page']);
    }

    public function register_settings() {
        register_setting('ai_news_chatgpt_settings', 'ai_news_chatgpt_key');
    }

    public function settings_page() {
        ?>
        <div class="wrap">
            <h1>AI News Generator Chat GPT — Налаштування</h1>
            <form method="post" action="options.php">
                <?php settings_fields('ai_news_chatgpt_settings'); ?>
                <table class="form-table">
                    <tr>
                        <th>OpenAI API Key</th>
                        <td>
                            <input type="password" name="ai_news_chatgpt_key" value="<?php echo esc_attr(get_option('ai_news_chatgpt_key')); ?>" class="regular-text" />
                            <p class="description">Найбезпечніше — додати в wp-config.php: define('OPENAI_API_KEY', 'sk-...');</p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }
}

AI_News_Generator_ChatGPT::get_instance();
