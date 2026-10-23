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
        add_action('admin_post_slr_add_mapping', [$this, 'admin_add_mapping']);
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

        ?>
        <div class="wrap">
            <h1>SLR - Language Mappings</h1>

            <h2>Añadir mapping</h2>
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

            <h2>Mappings existentes</h2>
            <table class="widefat striped">
                <thead><tr>
                    <th>ID</th><th>Source</th><th>Source Lang</th><th>Target Lang</th><th>Target URL</th><th>Activo</th><th>Acciones</th>
                </tr></thead>
                <tbody>
                <?php if ($mappings): foreach($mappings as $m): ?>
                    <tr>
                        <td><?php echo intval($m->id); ?></td>
                        <td style="max-width:300px;word-break:break-all;"><?php echo esc_html($m->source_url ?: $m->source_id); ?></td>
                        <td><?php echo esc_html($m->source_lang); ?></td>
                        <td><?php echo esc_html($m->target_lang); ?></td>
                        <td style="max-width:300px;word-break:break-all;"><?php echo esc_url($m->target_url); ?></td>
                        <td><?php echo $m->active ? 'Sí' : 'No'; ?></td>
                        <td>
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
