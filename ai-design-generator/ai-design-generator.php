<?php
/**
 * Plugin Name:       AI Design Generator
 * Plugin URI:        https://github.com/yourname/ai-design-generator
 * Description:       Генерує блоки, сторінки, цілі теми ТА РЕДАГУЄ ІСНУЮЧІ ТЕМИ через OpenAI. Автоматичне створення/редагування з бекапами, 100% адаптивність, Tailwind CSS, dark mode.
 * Version:           4.0.0
 * Author:            Grok + Руслан
 * License:           GPL-2.0+
 * Text Domain:       ai-design-generator
 * Requires PHP:      8.2
 * Requires at least: 6.4
 */

if (!defined('ABSPATH')) {
    exit; // Security
}

class AI_Design_Generator {
    private static $instance = null;
    private $version = '4.0.0';

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
        add_action('admin_init', [$this, 'cleanup_old_zips']);
    }

    public function get_api_key() {
        if (defined('OPENAI_API_KEY') && OPENAI_API_KEY) return OPENAI_API_KEY;
        $env = getenv('OPENAI_API_KEY');
        if ($env) return $env;
        return get_option('ai_design_openai_key', '');
    }

    public function register_block() {
        register_block_type('ai-design/generator', [
            'editor_script'   => 'ai-design-block-editor',
            'render_callback' => [$this, 'render_block'],
            'attributes'      => [
                'prompt'        => ['type' => 'string', 'default' => ''],
                'generatedHtml' => ['type' => 'string', 'default' => ''],
                'mode'          => ['type' => 'string', 'default' => 'single'],
                'templateType'  => ['type' => 'string', 'default' => 'landing'],
                'includeDemo'   => ['type' => 'boolean', 'default' => true],
                'themeName'     => ['type' => 'string', 'default' => 'ai-theme'],
                'selectedTheme' => ['type' => 'string', 'default' => ''],
                'selectedFile'  => ['type' => 'string', 'default' => ''],
                'zipUrl'        => ['type' => 'string', 'default' => ''],
                'themeSlug'     => ['type' => 'string', 'default' => ''],
                'editSuccess'   => ['type' => 'boolean', 'default' => false],
            ],
            'supports' => [
                'align' => ['wide', 'full'],
                'html'  => false,
            ],
        ]);
    }

    public function render_block($attributes) {
        if (empty($attributes['generatedHtml'])) {
            return '<div class="ai-design-placeholder p-8 text-center border-2 border-dashed border-gray-300 rounded-3xl text-gray-400">AI Design Generator 4.0<br>Генеруй або редагуй теми</div>';
        }

        $allowed = wp_kses_allowed_html('post');
        $allowed['style']  = true;
        $allowed['script'] = ['src' => true, 'async' => true, 'type' => true];
        $allowed['div']['data-*'] = true;
        $allowed['div']['class']  = true;
        $allowed['section'] = true;
        $allowed['nav']     = true;
        $allowed['header']  = true;
        $allowed['footer']  = true;

        $output = '<div class="ai-generated-block">' . wp_kses($attributes['generatedHtml'], $allowed) . '</div>';

        if (!empty($attributes['themeSlug'])) {
            $output .= '<div class="mt-6 p-4 bg-green-100 border border-green-400 rounded-2xl text-green-800">
                <strong>✅ Тема створена!</strong><br>
                Перейдіть у <a href="' . admin_url('themes.php') . '" class="underline">Зовнішній вигляд → Теми</a> і активуйте її.
            </div>';
        }

        if (!empty($attributes['editSuccess'])) {
            $output .= '<div class="mt-6 p-4 bg-blue-100 border border-blue-400 rounded-2xl text-blue-800">
                <strong>✅ Файл успішно оновлено!</strong><br>
                <a href="' . admin_url('theme-editor.php?file=' . urlencode($attributes['selectedFile']) . '&theme=' . urlencode($attributes['selectedTheme'])) . '" class="underline font-medium" target="_blank">Відкрити в Theme Editor</a>
            </div>';
        }

        if (!empty($attributes['zipUrl'])) {
            $output .= '<div class="mt-4"><a href="' . esc_url($attributes['zipUrl']) . '" class="button button-primary" download>📦 Завантажити ZIP (резерв)</a></div>';
        }

        return $output;
    }

    public function register_rest_routes() {
        register_rest_route('ai-design/v1', '/generate', [
            'methods'             => 'POST',
            'callback'            => [$this, 'generate_design'],
            'permission_callback' => function () { return current_user_can('edit_posts') && current_user_can('edit_themes'); },
        ]);

        register_rest_route('ai-design/v1', '/themes', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_themes_list'],
            'permission_callback' => function () { return current_user_can('edit_themes'); },
        ]);

        register_rest_route('ai-design/v1', '/theme-files', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_theme_files'],
            'permission_callback' => function () { return current_user_can('edit_themes'); },
            'args'                => ['theme' => ['required' => true]],
        ]);

        register_rest_route('ai-design/v1', '/get-file-content', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_file_content'],
            'permission_callback' => function () { return current_user_can('edit_themes'); },
            'args'                => [
                'theme' => ['required' => true],
                'file'  => ['required' => true],
            ],
        ]);
    }

    public function get_themes_list() {
        $themes = wp_get_themes();
        $list = [];
        foreach ($themes as $slug => $theme) {
            $list[] = ['slug' => $slug, 'name' => $theme->get('Name')];
        }
        return rest_ensure_response($list);
    }

    public function get_theme_files(WP_REST_Request $request) {
        $theme_slug = sanitize_text_field($request->get_param('theme'));
        $theme_dir = WP_CONTENT_DIR . '/themes/' . $theme_slug . '/';
        if (!is_dir($theme_dir)) {
            return new WP_Error('not_found', 'Тема не знайдена', ['status' => 404]);
        }
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($theme_dir));
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $rel = str_replace($theme_dir, '', $file->getPathname());
                if (in_array(pathinfo($rel, PATHINFO_EXTENSION), ['html', 'css', 'json', 'php'])) {
                    $files[] = $rel;
                }
            }
        }
        return rest_ensure_response($files);
    }

    public function get_file_content(WP_REST_Request $request) {
        $theme = sanitize_text_field($request->get_param('theme'));
        $file  = sanitize_text_field($request->get_param('file'));
        $path  = WP_CONTENT_DIR . '/themes/' . $theme . '/' . $file;

        if (!file_exists($path)) {
            return new WP_Error('not_found', 'Файл не знайдено', ['status' => 404]);
        }

        // Автоматичний бекап
        copy($path, $path . '.bak-' . time());

        return rest_ensure_response(['content' => file_get_contents($path)]);
    }

    public function generate_design(WP_REST_Request $request) {
        $prompt  = trim($request->get_param('prompt'));
        $mode    = $request->get_param('mode');
        $api_key = $this->get_api_key();

        if (empty($prompt) || empty($api_key)) {
            return new WP_Error('error', 'Промпт або API-ключ відсутній', ['status' => 400]);
        }

        // === РЕДАГУВАННЯ ТЕМИ ===
        if ($mode === 'edit_theme') {
            $theme = sanitize_text_field($request->get_param('selectedTheme'));
            $file  = sanitize_text_field($request->get_param('selectedFile'));

            if (empty($theme) || empty($file)) {
                return new WP_Error('missing_params', 'Виберіть тему і файл', ['status' => 400]);
            }

            $path = WP_CONTENT_DIR . '/themes/' . $theme . '/' . $file;
            if (!file_exists($path)) {
                return new WP_Error('file_not_found', 'Файл не знайдено', ['status' => 404]);
            }

            $current_content = file_get_contents($path);

            $system_prompt = "Ти — експерт WordPress Block Theme. Онови наданий файл точно за промптом користувача. Повертай ТІЛЬКИ чистий оновлений код (без пояснень, без markdown). Збережи Tailwind, адаптивність, dark mode та сучасний дизайн.";

            $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $api_key,
                    'Content-Type'  => 'application/json',
                ],
                'body' => wp_json_encode([
                    'model'       => 'gpt-4o-mini',
                    'messages'    => [
                        ['role' => 'system', 'content' => $system_prompt],
                        ['role' => 'user',   'content' => "Поточний код:\n\n" . $current_content . "\n\nЗміни, які потрібно внести: " . $prompt],
                    ],
                    'temperature' => 0.7,
                    'max_tokens'  => 6000,
                ]),
                'timeout' => 120,
            ]);

            if (is_wp_error($response)) {
                return new WP_Error('openai_error', $response->get_error_message(), ['status' => 500]);
            }

            $body = json_decode(wp_remote_retrieve_body($response), true);
            $new_content = trim($body['choices'][0]['message']['content'] ?? '');

            if (empty($new_content)) {
                return new WP_Error('empty_response', 'AI не повернув код', ['status' => 400]);
            }

            file_put_contents($path, $new_content);

            return rest_ensure_response([
                'html'        => '<h3 class="text-2xl font-bold mb-4">✅ Файл успішно оновлено!</h3>',
                'editSuccess' => true,
                'selectedTheme' => $theme,
                'selectedFile'  => $file,
            ]);
        }

        // === СТВОРЕННЯ НОВОЇ ТЕМИ ===
        if ($mode === 'theme') {
            $themeName = sanitize_text_field($request->get_param('themeName') ?: 'AI Theme');
            $slug = sanitize_title($themeName);
            if (wp_get_theme($slug)->exists()) {
                $slug .= '-' . wp_rand(100, 999);
            }

            $system_prompt = "Ти — експерт Block Themes WordPress. Створи повну сучасну тему. Повертай ТІЛЬКИ валідний JSON з ключами theme_name, theme_slug, files (style.css, functions.php, theme.json, templates/index.html, templates/single.html, templates/header.html, templates/footer.html). Тема має бути 100% адаптивною (mobile-first), з Tailwind CDN, dark mode, градієнтами та анімаціями.";

            $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
                'headers' => ['Authorization' => 'Bearer ' . $api_key, 'Content-Type' => 'application/json'],
                'body' => wp_json_encode([
                    'model'       => 'gpt-4o-mini',
                    'messages'    => [
                        ['role' => 'system', 'content' => $system_prompt],
                        ['role' => 'user',   'content' => $prompt],
                    ],
                    'temperature' => 0.75,
                    'max_tokens'  => 6500,
                ]),
                'timeout' => 120,
            ]);

            $body = json_decode(wp_remote_retrieve_body($response), true);
            $json = json_decode($body['choices'][0]['message']['content'] ?? '', true);

            if (!$json || empty($json['files'])) {
                return new WP_Error('invalid_json', 'AI не повернув правильний JSON', ['status' => 400]);
            }

            $result = $this->create_theme_in_themes_folder($json, $slug);

            return rest_ensure_response([
                'html'      => '<h3 class="text-2xl font-bold mb-4">🎉 Повна тема створена!</h3>',
                'themeSlug' => $slug,
                'themeName' => $themeName,
                'zipUrl'    => $result['zip_url'] ?? ''
            ]);
        }

        // === ОДИН БЛОК АБО ПОВНИЙ ШАБЛОН ===
        $system_prompt = $mode === 'full' 
            ? "Ти — світовий експерт з UI/UX. Створи ПОВНИЙ HTML-шаблон сторінки з Tailwind CSS (CDN). Додай скрипт Tailwind на початку. 100% адаптивний, сучасний, dark mode."
            : "Ти — світовий експерт з UI/UX. Створи один красивий HTML-блок з Tailwind CSS (CDN). Додай скрипт Tailwind. 100% адаптивний.";

        $full_prompt = $prompt . ($mode === 'full' ? "\n\nТип: " . $request->get_param('templateType') : '');

        $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
            'headers' => ['Authorization' => 'Bearer ' . $api_key, 'Content-Type' => 'application/json'],
            'body' => wp_json_encode([
                'model'       => 'gpt-4o-mini',
                'messages'    => [
                    ['role' => 'system', 'content' => $system_prompt],
                    ['role' => 'user',   'content' => $full_prompt],
                ],
                'temperature' => 0.8,
                'max_tokens'  => $mode === 'full' ? 4000 : 2800,
            ]),
            'timeout' => 90,
        ]);

        if (is_wp_error($response)) {
            return new WP_Error('openai_error', $response->get_error_message(), ['status' => 500]);
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        $html = $body['choices'][0]['message']['content'] ?? '';

        return rest_ensure_response(['html' => trim($html), 'mode' => $mode]);
    }

    private function create_theme_in_themes_folder($theme_data, $slug) {
        $theme_dir = WP_CONTENT_DIR . '/themes/' . $slug . '/';
        wp_mkdir_p($theme_dir);

        foreach ($theme_data['files'] as $filename => $content) {
            $path = $theme_dir . $filename;
            wp_mkdir_p(dirname($path));
            file_put_contents($path, $content);
        }

        $upload_dir = wp_upload_dir();
        $zip_dir = $upload_dir['basedir'] . '/ai-themes/';
        wp_mkdir_p($zip_dir);
        $zip_path = $zip_dir . $slug . '-' . time() . '.zip';

        $zip = new ZipArchive();
        if ($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
            foreach ($theme_data['files'] as $filename => $content) {
                $zip->addFromString($filename, $content);
            }
            $zip->close();
        }

        return ['zip_url' => $upload_dir['baseurl'] . '/ai-themes/' . basename($zip_path)];
    }

    public function cleanup_old_zips() {
        $upload_dir = wp_upload_dir();
        $path = $upload_dir['basedir'] . '/ai-themes/';
        if (!is_dir($path)) return;
        $files = glob($path . '*.zip');
        foreach ($files as $file) {
            if (filemtime($file) < time() - 3600) unlink($file);
        }
    }

    public function enqueue_editor_assets() {
        wp_register_script('ai-design-block-editor', '', ['wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-i18n'], $this->version, true);

        wp_localize_script('ai-design-block-editor', 'aiDesign', [
            'restUrl'        => rest_url('ai-design/v1/generate'),
            'themesUrl'      => rest_url('ai-design/v1/themes'),
            'filesUrl'       => rest_url('ai-design/v1/theme-files'),
            'fileContentUrl' => rest_url('ai-design/v1/get-file-content'),
            'nonce'          => wp_create_nonce('wp_rest'),
            'version'        => $this->version,
        ]);

        $js = <<<'JS'
(function() {
    const { registerBlockType } = wp.blocks;
    const { useBlockProps, InspectorControls } = wp.blockEditor;
    const { PanelBody, TextareaControl, Button, SelectControl, ToggleControl, Notice, TextControl, Spinner } = wp.components;
    const { useState, useEffect } = wp.element;
    const { __ } = wp.i18n;

    registerBlockType('ai-design/generator', {
        title: __('AI Дизайн Генератор 4.0', 'ai-design-generator'),
        icon: 'format-image',
        category: 'design',
        attributes: {
            prompt: { type: 'string', default: '' },
            generatedHtml: { type: 'string', default: '' },
            mode: { type: 'string', default: 'single' },
            templateType: { type: 'string', default: 'landing' },
            includeDemo: { type: 'boolean', default: true },
            themeName: { type: 'string', default: 'ai-theme' },
            selectedTheme: { type: 'string', default: '' },
            selectedFile: { type: 'string', default: '' },
            zipUrl: { type: 'string', default: '' },
            themeSlug: { type: 'string', default: '' },
            editSuccess: { type: 'boolean', default: false },
        },
        edit: function(props) {
            const { attributes, setAttributes } = props;
            const blockProps = useBlockProps();
            const [isGenerating, setIsGenerating] = useState(false);
            const [error, setError] = useState('');
            const [themes, setThemes] = useState([]);
            const [files, setFiles] = useState([]);
            const [loadingThemes, setLoadingThemes] = useState(false);
            const [loadingFiles, setLoadingFiles] = useState(false);

            useEffect(() => {
                if (attributes.mode === 'edit_theme') {
                    setLoadingThemes(true);
                    fetch(aiDesign.themesUrl, {
                        headers: { 'X-WP-Nonce': aiDesign.nonce }
                    })
                    .then(r => r.json())
                    .then(setThemes)
                    .finally(() => setLoadingThemes(false));
                }
            }, [attributes.mode]);

            useEffect(() => {
                if (attributes.mode === 'edit_theme' && attributes.selectedTheme) {
                    setLoadingFiles(true);
                    fetch(`${aiDesign.filesUrl}?theme=${attributes.selectedTheme}`, {
                        headers: { 'X-WP-Nonce': aiDesign.nonce }
                    })
                    .then(r => r.json())
                    .then(setFiles)
                    .finally(() => setLoadingFiles(false));
                }
            }, [attributes.selectedTheme]);

            const generate = async () => {
                if (!attributes.prompt.trim()) {
                    setError('Введіть опис!');
                    return;
                }
                setIsGenerating(true);
                setError('');

                try {
                    const res = await fetch(aiDesign.restUrl, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': aiDesign.nonce },
                        body: JSON.stringify({
                            prompt: attributes.prompt,
                            mode: attributes.mode,
                            templateType: attributes.templateType,
                            includeDemo: attributes.includeDemo,
                            themeName: attributes.themeName,
                            selectedTheme: attributes.selectedTheme,
                            selectedFile: attributes.selectedFile
                        })
                    });

                    const data = await res.json();
                    if (!res.ok) throw new Error(data.message || 'Помилка');

                    setAttributes({
                        generatedHtml: data.html || '',
                        zipUrl: data.zip_url || '',
                        themeSlug: data.themeSlug || '',
                        editSuccess: !!data.editSuccess,
                        selectedTheme: data.selectedTheme || attributes.selectedTheme,
                        selectedFile: data.selectedFile || attributes.selectedFile
                    });
                } catch (err) {
                    setError(err.message);
                } finally {
                    setIsGenerating(false);
                }
            };

            return wp.element.createElement('div', blockProps,
                wp.element.createElement(InspectorControls, {},
                    wp.element.createElement(PanelBody, { title: __('AI Генерація 4.0', 'ai-design-generator'), initialOpen: true },
                        wp.element.createElement(SelectControl, {
                            label: __('Режим'),
                            value: attributes.mode,
                            options: [
                                { label: 'Один блок', value: 'single' },
                                { label: 'Повний шаблон', value: 'full' },
                                { label: 'Нова тема', value: 'theme' },
                                { label: 'Редагувати тему', value: 'edit_theme' }
                            ],
                            onChange: (val) => setAttributes({ mode: val })
                        }),

                        attributes.mode === 'edit_theme' && wp.element.createElement(SelectControl, {
                            label: __('Тема'),
                            value: attributes.selectedTheme,
                            options: themes.map(t => ({ label: t.name, value: t.slug })),
                            onChange: (val) => setAttributes({ selectedTheme: val })
                        }),

                        attributes.mode === 'edit_theme' && attributes.selectedTheme && wp.element.createElement(SelectControl, {
                            label: __('Файл'),
                            value: attributes.selectedFile,
                            options: files.map(f => ({ label: f, value: f })),
                            onChange: (val) => setAttributes({ selectedFile: val })
                        }),

                        attributes.mode === 'theme' && wp.element.createElement(TextControl, {
                            label: __('Назва теми'),
                            value: attributes.themeName,
                            onChange: (val) => setAttributes({ themeName: val })
                        }),

                        (attributes.mode === 'full' || attributes.mode === 'theme') && wp.element.createElement(ToggleControl, {
                            label: __('З демо-даними'),
                            checked: attributes.includeDemo,
                            onChange: (val) => setAttributes({ includeDemo: val })
                        }),

                        wp.element.createElement(TextareaControl, {
                            label: __('Опис / що змінити'),
                            value: attributes.prompt,
                            onChange: (val) => setAttributes({ prompt: val }),
                            placeholder: attributes.mode === 'edit_theme' ? 'Наприклад: зроби темну тему, додай секцію відгуків...' : 'Опишіть, що хочете...'
                        }),

                        wp.element.createElement(Button, {
                            isPrimary: true,
                            isBusy: isGenerating,
                            disabled: isGenerating,
                            onClick: generate,
                            style: { marginTop: '16px', width: '100%' }
                        }, isGenerating ? 'AI працює...' : '🚀 Виконати'),

                        error && wp.element.createElement(Notice, { status: 'error', isDismissible: true, onDismiss: () => setError('') }, error)
                    )
                ),
                attributes.generatedHtml && wp.element.createElement('div', {
                    dangerouslySetInnerHTML: { __html: attributes.generatedHtml },
                    style: { border: '2px dashed #22c55e', padding: '20px', borderRadius: '16px', minHeight: '300px' }
                })
            );
        },
        save: function({ attributes }) {
            if (!attributes.generatedHtml) return null;
            return wp.element.createElement('div', {
                className: 'ai-generated-block',
                dangerouslySetInnerHTML: { __html: attributes.generatedHtml }
            });
        }
    });
})();
JS;

        wp_add_inline_script('ai-design-block-editor', $js, 'after');
    }

    public function add_settings_page() {
        add_options_page('AI Design Generator', 'AI Design Generator', 'manage_options', 'ai-design-generator', [$this, 'settings_page_html']);
    }

    public function register_settings() {
        register_setting('ai_design_settings', 'ai_design_openai_key', 'sanitize_text_field');
        add_settings_section('ai_design_main', 'Основні налаштування', null, 'ai-design-generator');
        add_settings_field('ai_design_openai_key', 'OpenAI API Key', [$this, 'field_api_key'], 'ai-design-generator', 'ai_design_main');
    }

    public function field_api_key() {
        $value = get_option('ai_design_openai_key', '');
        echo '<input type="password" name="ai_design_openai_key" value="' . esc_attr($value) . '" class="regular-text" />';
        echo '<p class="description">Найбезпечніше — додати в wp-config.php: define(\'OPENAI_API_KEY\', \'sk-...\');</p>';
    }

    public function settings_page_html() {
        ?>
        <div class="wrap">
            <h1>AI Design Generator 4.0 — Налаштування</h1>
            <form method="post" action="options.php">
                <?php
                settings_fields('ai_design_settings');
                do_settings_sections('ai-design-generator');
                submit_button();
                ?>
            </form>
            <p><strong>Готово!</strong> Тепер ти можеш створювати і редагувати теми прямо в Gutenberg.</p>
        </div>
        <?php
    }
}

AI_Design_Generator::get_instance();
