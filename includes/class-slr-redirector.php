<?php
if (!defined('ABSPATH')) exit;

class SLR_Redirector {

    private static $instance = null;
    private $table;

    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'slr_lang_map';

        // Frontend
        add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts']);
        add_action('wp_ajax_nopriv_slr_get_redirect', [$this, 'ajax_get_redirect']);
        add_action('wp_ajax_slr_get_redirect', [$this, 'ajax_get_redirect']);

        // Admin
        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_head', [$this, 'admin_styles']);
        add_action('admin_post_slr_add_mapping', [$this, 'admin_add_mapping']);
        add_action('admin_post_slr_toggle_mapping', [$this, 'admin_toggle_mapping']);
        add_action('admin_post_slr_delete_mapping', [$this, 'admin_delete_mapping']);
    }

    /**
     * Activation: crear tabla
     */
    public static function activate() {
        global $wpdb;
        $table = $wpdb->prefix . 'slr_lang_map';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            source_type VARCHAR(32) DEFAULT NULL,
            source_id BIGINT DEFAULT NULL,
            source_url VARCHAR(255) DEFAULT NULL,
            source_lang VARCHAR(10) NOT NULL,
            target_lang VARCHAR(10) NOT NULL,
            target_url VARCHAR(255) NOT NULL,
            redirect_type SMALLINT DEFAULT 301,
            active TINYINT(1) DEFAULT 1,
            created DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            INDEX source_idx (source_lang, source_id),
            INDEX url_idx (source_url(191))
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    public static function deactivate() {
        // no eliminamos la tabla por seguridad; podríamos implementarlo con un flag opcional
    }

    /**
     * Enqueue JS que intercepta el click
     */
    public function enqueue_scripts() {
        wp_register_script('slr-frontend', plugins_url('../assets/js/slr-frontend.js', __FILE__), ['jquery'], SLR_VERSION, true);
        wp_localize_script('slr-frontend', 'slr', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('slr_nonce'),
            'home'     => home_url('/'),
        ]);
        wp_enqueue_script('slr-frontend');
    }

    /**
     * AJAX handler: devuelve la URL destino según prioridad:
     * 1) mapping en tabla
     * 2) wpm_translate_object_id()
     * 3) fallback home del idioma
     */
    public function ajax_get_redirect() {
        check_ajax_referer('slr_nonce', 'nonce');

        $target_lang   = isset($_POST['lang']) ? sanitize_text_field($_POST['lang']) : '';
        $current_url   = isset($_POST['url']) ? esc_url_raw($_POST['url']) : '';
        $object_id     = isset($_POST['object_id']) ? intval($_POST['object_id']) : 0;
        $object_type   = isset($_POST['object_type']) ? sanitize_text_field($_POST['object_type']) : '';

        if (empty($target_lang)) {
            wp_send_json_error(['message' => 'missing lang']);
        }

        // 1) intentar encontrar mapping en tabla por object_id
        global $wpdb;
        $table = $this->table;

        if ($object_id) {
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$table} WHERE source_id = %d AND source_lang = %s AND target_lang = %s AND active = 1 LIMIT 1",
                $object_id, $this->detect_current_lang(), $target_lang
            ));
            if ($row) {
                wp_send_json_success(['url' => esc_url_raw($row->target_url)]);
            }
        }

        // 1b) intentar por URL exacta (path)
        if ($current_url) {
            // guardamos con and without trailing slash comparators
            $url_path = $this->normalize_path($current_url);
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$table} WHERE (source_url = %s OR source_url = %s) AND source_lang = %s AND target_lang = %s AND active = 1 LIMIT 1",
                $current_url,
                $url_path,
                $this->detect_current_lang(),
                $target_lang
            ));
            if (!empty($rows)) {
                $r = $rows[0];
                wp_send_json_success(['url' => esc_url_raw($r->target_url)]);
            }
        }

        // 2) intentar WPM translate si existe y si tenemos object_id
        if ($object_id && function_exists('wpm_translate_object_id')) {
            $translated = wpm_translate_object_id($object_id, $object_type ?: 'post', false, $target_lang);
            if ($translated) {
                if ($object_type === 'product_cat') {
                    $link = get_term_link($translated, 'product_cat');
                } else {
                    $link = get_permalink($translated);
                }
                if ($link && !is_wp_error($link)) {
                    wp_send_json_success(['url' => esc_url_raw($link)]);
                }
            }
        }

        // 3) fallback: home del idioma (intenta usar prefijo si aplica)
        $fallback = $this->build_fallback_home($target_lang);
        wp_send_json_success(['url' => esc_url_raw($fallback)]);
    }

    /**
     * Estilos del panel de mappings, aislados a esta pantalla.
     */
    public function admin_styles() {
        if (!isset($_GET['page']) || 'slr-mappings' !== sanitize_key(wp_unslash($_GET['page']))) {
            return;
        }
        echo '<style>
            .wrap.slr-admin-wrap { max-width: 1180px; }
            .slr-admin-header { display:flex; align-items:flex-end; justify-content:space-between; gap:20px; margin:24px 0 22px; padding:28px 30px; border:1px solid #173b63; border-radius:14px; background:linear-gradient(120deg,#102a43 0%,#17466f 62%,#23649a 100%); color:#fff; box-shadow:0 8px 24px rgba(15,42,67,.14); }
            .slr-admin-eyebrow { margin:0 0 8px; color:#b9d8f2; font-size:11px; font-weight:700; letter-spacing:.12em; text-transform:uppercase; }
            .slr-admin-header h1 { margin:0 0 7px; color:#fff; font-size:27px; font-weight:650; letter-spacing:-.025em; line-height:1.2; }
            .slr-admin-description { margin:0; color:#d7e7f5; font-size:13px; }
            .slr-admin-version { flex:0 0 auto; padding:7px 11px; border:1px solid rgba(255,255,255,.24); border-radius:999px; background:rgba(255,255,255,.1); color:#fff; font-size:12px; font-weight:600; }
            .slr-admin-stats { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:14px; margin:0 0 26px; }
            .slr-admin-stat { min-width:0; padding:19px 21px; border:1px solid #dce3ec; border-radius:11px; background:#fff; box-shadow:0 2px 8px rgba(11,17,32,.035); }
            .slr-admin-stat-label { display:block; margin-bottom:8px; color:#64748b; font-size:12px; font-weight:600; }
            .slr-admin-stat-value { display:block; color:#172b43; font-size:27px; font-weight:700; line-height:1.1; letter-spacing:-.03em; font-variant-numeric:tabular-nums; }
            .slr-admin-stat-note { display:block; margin-top:6px; color:#8492a6; font-size:11px; }
            .slr-admin-stat.is-active .slr-admin-stat-value { color:#16804a; }
            .slr-admin-stat.is-inactive .slr-admin-stat-value { color:#64748b; }
            .slr-admin-wrap h2 { margin-top: 28px; padding-bottom: 10px; border-bottom: 1px solid #dce3ec; font-size: 17px; }
            .slr-admin-wrap h2 { margin-top: 28px; padding-bottom: 10px; border-bottom: 1px solid #dce3ec; font-size: 17px; }
            .slr-admin-panel { background: #fff; border: 1px solid #dce3ec; border-radius: 12px; padding: 22px 24px; margin: 18px 0 26px; box-shadow: 0 3px 12px rgba(11,17,32,.04); }
            .slr-admin-panel .form-table th { width: 170px; padding: 16px 12px 16px 0; }
            .slr-admin-panel .form-table td { padding: 12px 10px; }
            .slr-admin-wrap input.regular-text { max-width: 100%; width: 440px; }
            .slr-admin-wrap input[type=text], .slr-admin-wrap select { min-height: 36px; border-color: #cbd5e1; border-radius: 6px; }
            .slr-admin-wrap input:focus, .slr-admin-wrap select:focus { border-color: #3B82C4; box-shadow: 0 0 0 1px #3B82C4; }
            .slr-admin-wrap .button-primary { min-height: 38px; padding: 3px 16px; border-radius: 6px; background: #3B82C4; border-color: #3B82C4; }
            .slr-admin-wrap .button-primary:hover { background: #286da9; border-color: #286da9; }
            .slr-admin-wrap .widefat { overflow: hidden; border: 1px solid #dce3ec; border-radius: 8px; }
            .slr-admin-wrap .widefat thead th { background: #f1f5f9; font-weight: 600; }
            .slr-admin-wrap .widefat td { vertical-align: middle; padding-top: 12px; padding-bottom: 12px; }
            .slr-admin-wrap .slr-url-cell { max-width: 300px; overflow-wrap: anywhere; }
            .slr-admin-wrap .slr-language-tag { display: inline-flex; align-items: center; gap: 7px; min-width: 2.2em; padding: 5px 9px; border: 1px solid #d6e4f2; border-radius: 7px; background: #eff6fc; color: #245b89; font-size: 12px; font-weight: 600; line-height: 1.2; text-align: center; text-transform: uppercase; white-space: nowrap; }
            .slr-admin-wrap .slr-language-flag { font-size: 16px; line-height: 1; }
            .slr-admin-wrap .slr-status-tag { display: inline-block; padding: 4px 9px; border: 1px solid transparent; border-radius: 999px; font-size: 12px; font-weight: 600; line-height: 1.2; }
            .slr-admin-wrap .slr-status-tag.is-active { border-color: #b7e4c7; background: #edf8f0; color: #176b36; }
            .slr-admin-wrap .slr-status-tag.is-inactive { border-color: #d8dee8; background: #f3f4f6; color: #596579; }
            .slr-admin-wrap .widefat td:nth-child(2), .slr-admin-wrap .widefat td:nth-child(5) { overflow-wrap: anywhere; }
            @media(max-width:782px) { .slr-admin-panel { padding: 14px; } .slr-admin-panel .form-table th { width: auto; } .slr-admin-header { align-items:flex-start; flex-direction:column; padding:22px; } .slr-admin-stats { grid-template-columns:1fr; gap:10px; } .slr-admin-stat { padding:15px 17px; } }
        </style>';
    }

    /**
     * Devuelve una bandera representativa para los códigos de idioma habituales.
     * Los códigos regionales pueden definir una bandera específica.
     */
    private function get_language_flag($language) {
        $language = strtolower(str_replace('_', '-', trim((string) $language)));
        $flags = [
            'es' => '🇪🇸', 'es-es' => '🇪🇸', 'es-mx' => '🇲🇽', 'es-ar' => '🇦🇷',
            'en' => '🇬🇧', 'en-gb' => '🇬🇧', 'en-us' => '🇺🇸',
            'fr' => '🇫🇷', 'fr-fr' => '🇫🇷', 'pt' => '🇵🇹', 'pt-br' => '🇧🇷',
            'de' => '🇩🇪', 'it' => '🇮🇹', 'nl' => '🇳🇱', 'ja' => '🇯🇵',
            'zh' => '🇨🇳', 'zh-cn' => '🇨🇳', 'zh-tw' => '🇹🇼',
            'ru' => '🇷🇺', 'ar' => '🇸🇦', 'ko' => '🇰🇷',
        ];

        $base_language = strtok($language, '-');

        return isset($flags[$language]) ? $flags[$language] : (isset($flags[$base_language]) ? $flags[$base_language] : '🌐');
    }

    /**
     * Renderiza un código de idioma como etiqueta visual accesible.
     */
    private function render_language_tag($language) {
        $language = (string) $language;
        $flag = $this->get_language_flag($language);

        return sprintf(
            '<span class="slr-language-tag"><span class="slr-language-flag" aria-hidden="true">%1$s</span><span>%2$s</span></span>',
            esc_html($flag),
            esc_html($language)
        );
    }

    /**
     * admin menu
     */
    public function admin_menu() {
        add_options_page('SLR - Mappings', 'SLR Mappings', 'manage_options', 'slr-mappings', [$this, 'admin_page']);
    }

    /**
     * Admin page con formulario simple para agregar mappings y listar existentes
     */
    public function admin_page() {
        if (!current_user_can('manage_options')) wp_die('No autorizado');

        global $wpdb;
        $table = $this->table;
        $mappings = $wpdb->get_results("SELECT * FROM {$table} ORDER BY created DESC LIMIT 200");
        $total_mappings = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        $active_mappings = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE active = 1");
        $inactive_mappings = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE active = 0");

        ?>
        <div class="wrap slr-admin-wrap">
            <header class="slr-admin-header">
                <div>
                    <p class="slr-admin-eyebrow">WP Language Redirector · Administration</p>
                    <h1>Language mappings</h1>
                    <p class="slr-admin-description">Manage language destinations and control which mapping rules are used.</p>
                </div>
                <span class="slr-admin-version">Version <?php echo esc_html(SLR_VERSION); ?></span>
            </header>

            <section class="slr-admin-stats" aria-label="Mapping overview">
                <div class="slr-admin-stat">
                    <span class="slr-admin-stat-label">Total mappings</span>
                    <strong class="slr-admin-stat-value"><?php echo esc_html(number_format_i18n($total_mappings)); ?></strong>
                    <span class="slr-admin-stat-note">All saved rules</span>
                </div>
                <div class="slr-admin-stat is-active">
                    <span class="slr-admin-stat-label">Active</span>
                    <strong class="slr-admin-stat-value"><?php echo esc_html(number_format_i18n($active_mappings)); ?></strong>
                    <span class="slr-admin-stat-note">Available to the redirect resolver</span>
                </div>
                <div class="slr-admin-stat is-inactive">
                    <span class="slr-admin-stat-label">Inactive</span>
                    <strong class="slr-admin-stat-value"><?php echo esc_html(number_format_i18n($inactive_mappings)); ?></strong>
                    <span class="slr-admin-stat-note">Saved but not used for redirects</span>
                </div>
            </section>

            <?php if (isset($_GET['slr_notice']) && 'updated' === sanitize_key(wp_unslash($_GET['slr_notice']))): ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Estado del mapping actualizado.', 'schefferlii-lang-redirector'); ?></p></div>
            <?php elseif (isset($_GET['slr_notice']) && 'error' === sanitize_key(wp_unslash($_GET['slr_notice']))): ?>
                <div class="notice notice-error"><p><?php esc_html_e('No se pudo actualizar el estado del mapping.', 'schefferlii-lang-redirector'); ?></p></div>
            <?php endif; ?>

            <h2>Añadir mapping</h2>
            <div class="slr-admin-panel">
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('slr_add_mapping'); ?>
                <input type="hidden" name="action" value="slr_add_mapping">
                <table class="form-table" role="presentation">
                    <tbody>
                    <tr>
                        <th><label>Source URL (path completa)</label></th>
                        <td><input name="source_url" type="text" class="regular-text" placeholder="https://tusitio.com/es/product-category/blusas-tops/" /></td>
                    </tr>
                    <tr>
                        <th><label>Source Lang</label></th>
                        <td><input name="source_lang" type="text" class="small-text" value="es" /></td>
                    </tr>
                    <tr>
                        <th><label>Target Lang</label></th>
                        <td><input name="target_lang" type="text" class="small-text" value="en" /></td>
                    </tr>
                    <tr>
                        <th><label>Target URL</label></th>
                        <td><input name="target_url" type="text" class="regular-text" placeholder="https://tusitio.com/en/product-category/blouses-tops/" /></td>
                    </tr>
                    <tr>
                        <th><label>Redirect Type</label></th>
                        <td>
                            <select name="redirect_type">
                                <option value="301">301 (Permanente)</option>
                                <option value="302">302 (Temporal)</option>
                            </select>
                        </td>
                    </tr>
                    </tbody>
                </table>
                <?php submit_button('Añadir mapping'); ?>
            </form>
            </div>

            <h2>Mappings existentes</h2>
            <div class="slr-admin-panel">
            <table class="widefat striped">
                <thead><tr>
                    <th>ID</th><th>Source</th><th>Source Lang</th><th>Target Lang</th><th>Target URL</th><th>Activo</th><th>Acciones</th>
                </tr></thead>
                <tbody>
                <?php if ($mappings): foreach($mappings as $m): ?>
                    <tr>
                        <td><?php echo intval($m->id); ?></td>
                        <td class="slr-url-cell"><?php echo esc_html($m->source_url ?: $m->source_id); ?></td>
                        <td><?php echo $this->render_language_tag($m->source_lang); ?></td>
                        <td><?php echo $this->render_language_tag($m->target_lang); ?></td>
                        <td class="slr-url-cell"><?php echo esc_url($m->target_url); ?></td>
                        <td><span class="slr-status-tag <?php echo $m->active ? 'is-active' : 'is-inactive'; ?>"><?php echo $m->active ? 'Activo' : 'Inactivo'; ?></span></td>
                        <td>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
                                <?php wp_nonce_field('slr_toggle_mapping_' . intval($m->id)); ?>
                                <input type="hidden" name="action" value="slr_toggle_mapping">
                                <input type="hidden" name="id" value="<?php echo intval($m->id); ?>">
                                <?php submit_button($m->active ? 'Desactivar' : 'Activar', 'small', 'submit', false); ?>
                            </form>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
                                <?php wp_nonce_field('slr_delete_mapping'); ?>
                                <input type="hidden" name="action" value="slr_delete_mapping">
                                <input type="hidden" name="id" value="<?php echo intval($m->id); ?>">
                                <?php submit_button('Eliminar', 'small delete', 'submit', false); ?>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="7">No hay mappings aún.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
            </div>
        </div>
        <?php
    }

    /**
     * Procesa formulario de añadir mapping (admin_post)
     */
    public function admin_add_mapping() {
        if (!current_user_can('manage_options')) wp_die('No autorizado');
        check_admin_referer('slr_add_mapping');

        global $wpdb;
        $table = $this->table;

        $source_url = isset($_POST['source_url']) ? esc_url_raw(trim($_POST['source_url'])) : '';
        $source_lang = isset($_POST['source_lang']) ? sanitize_text_field($_POST['source_lang']) : '';
        $target_lang = isset($_POST['target_lang']) ? sanitize_text_field($_POST['target_lang']) : '';
        $target_url = isset($_POST['target_url']) ? esc_url_raw(trim($_POST['target_url'])) : '';
        $redirect_type = isset($_POST['redirect_type']) ? intval($_POST['redirect_type']) : 301;

        // simple validation
        if (empty($source_lang) || empty($target_lang) || empty($target_url) || empty($source_url)) {
            wp_redirect(add_query_arg('slr_err', 'missing', wp_get_referer()));
            exit;
        }

        $wpdb->insert($table, [
            'source_type' => 'url',
            'source_id'   => null,
            'source_url'  => $source_url,
            'source_lang' => $source_lang,
            'target_lang' => $target_lang,
            'target_url'  => $target_url,
            'redirect_type' => $redirect_type,
            'active' => 1,
        ], ['%s','%d','%s','%s','%s','%s','%d','%d']);

        wp_redirect(admin_url('options-general.php?page=slr-mappings'));
        exit;
    }

    /**
     * Alterna el estado de un mapping existente.
     */
    public function admin_toggle_mapping() {
        if (!current_user_can('manage_options')) {
            wp_die('No autorizado');
        }

        $id = isset($_POST['id']) ? absint($_POST['id']) : 0;
        if (!$id) {
            wp_safe_redirect(add_query_arg('slr_notice', 'error', admin_url('options-general.php?page=slr-mappings')));
            exit;
        }

        check_admin_referer('slr_toggle_mapping_' . $id);

        global $wpdb;
        $current_status = $wpdb->get_var($wpdb->prepare(
            "SELECT active FROM {$this->table} WHERE id = %d",
            $id
        ));

        if (null === $current_status) {
            wp_safe_redirect(add_query_arg('slr_notice', 'error', admin_url('options-general.php?page=slr-mappings')));
            exit;
        }

        $new_status = (int) !$current_status;
        $updated = $wpdb->update(
            $this->table,
            ['active' => $new_status],
            ['id' => $id],
            ['%d'],
            ['%d']
        );

        $notice = false === $updated ? 'error' : 'updated';
        wp_safe_redirect(add_query_arg('slr_notice', $notice, admin_url('options-general.php?page=slr-mappings')));
        exit;
    }

    /**
     * Procesa eliminar mapping
     */
    public function admin_delete_mapping() {
        if (!current_user_can('manage_options')) wp_die('No autorizado');
        check_admin_referer('slr_delete_mapping');
        if (!isset($_POST['id'])) wp_redirect(admin_url('options-general.php?page=slr-mappings'));

        global $wpdb;
        $table = $this->table;
        $id = intval($_POST['id']);
        $wpdb->delete($table, ['id' => $id], ['%d']);

        wp_redirect(admin_url('options-general.php?page=slr-mappings'));
        exit;
    }

    /**
     * Detectar idioma actual con WPM
     */
    private function detect_current_lang() {
        if (function_exists('wpm_get_language')) {
            return wpm_get_language();
        }
        // fallback: intentar por prefijo en URL
        $home = home_url('/');
        $path = trim(str_replace($home, '', (isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '')), '/');
        $first = explode('/', $path)[0] ?? '';
        if (in_array($first, ['es','en','fr'])) return $first;
        return 'es';
    }

    /**
     * Construir fallback home del idioma (simple)
     */
    private function build_fallback_home($lang) {
        // intenta usar WP Multilang helper si existe
        if (function_exists('wpm_get_language_url')) {
            $url = wpm_get_language_url($lang);
            if ($url) return $url;
        }
        // usual prefijo
        return home_url("/{$lang}/");
    }

    /**
     * Normaliza la ruta para comparaciones: sin trailing slash
     */
    private function normalize_path($url) {
        $parsed = wp_parse_url($url);
        if (!$parsed) return $url;
        $scheme = isset($parsed['scheme']) ? $parsed['scheme'] . '://' : '';
        $host = $parsed['host'] ?? '';
        $path = $parsed['path'] ?? '';
        $normalized = rtrim($scheme . $host . $path, '/');
        return $normalized;
    }
}
