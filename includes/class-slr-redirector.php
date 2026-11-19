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

        // Ensure the diagnostics table exists after plugin updates as well as activation.
        add_action('init', [$this, 'maybe_create_logs_table'], 1);

        // Admin
        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_post_slr_clear_logs', [$this, 'admin_clear_logs']);
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

        $logs_table = $wpdb->prefix . 'slr_redirect_logs';
        $logs_sql = "CREATE TABLE {$logs_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            event_type VARCHAR(64) NOT NULL,
            request_id CHAR(36) NOT NULL DEFAULT '',
            resolution_method VARCHAR(32) NOT NULL DEFAULT '',
            severity VARCHAR(16) NOT NULL DEFAULT 'warning',
            source_url VARCHAR(255) DEFAULT NULL,
            source_lang VARCHAR(10) DEFAULT NULL,
            target_lang VARCHAR(10) DEFAULT NULL,
            target_url VARCHAR(255) DEFAULT NULL,
            details TEXT DEFAULT NULL,
            created DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            INDEX created_idx (created),
            INDEX severity_idx (severity),
            INDEX request_idx (request_id)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($logs_sql);
        update_option('slr_logs_db_version', '2');

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

        dbDelta($sql);
    }

    /**
     * Crea la tabla de diagnósticos en instalaciones existentes tras actualizar el plugin.
     */
    public function maybe_create_logs_table() {
        if ('2' === get_option('slr_logs_db_version')) {
            return;
        }

        global $wpdb;
        $logs_table = $wpdb->prefix . 'slr_redirect_logs';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$logs_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            event_type VARCHAR(64) NOT NULL,
            request_id CHAR(36) NOT NULL DEFAULT '',
            resolution_method VARCHAR(32) NOT NULL DEFAULT '',
            severity VARCHAR(16) NOT NULL DEFAULT 'warning',
            source_url VARCHAR(255) DEFAULT NULL,
            source_lang VARCHAR(10) DEFAULT NULL,
            target_lang VARCHAR(10) DEFAULT NULL,
            target_url VARCHAR(255) DEFAULT NULL,
            details TEXT DEFAULT NULL,
            created DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            INDEX created_idx (created),
            INDEX severity_idx (severity),
            INDEX request_idx (request_id)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
        update_option('slr_logs_db_version', '2');
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
     * 3) registra una advertencia si no se encuentra destino
     */
    public function ajax_get_redirect() {
        $request_id = wp_generate_uuid4();
        $current_url = isset($_POST['url']) ? esc_url_raw(wp_unslash($_POST['url'])) : '';
        $source_lang = $this->detect_language_from_url($current_url);

        if (!check_ajax_referer('slr_nonce', 'nonce', false)) {
            $this->log_event('invalid_nonce', 'error', $current_url, $source_lang, '', '', 'La solicitud AJAX no superó la validación del nonce.', $request_id, 'request_validation');
            wp_send_json_error([
                'code' => 'invalid_nonce',
                'message' => 'La solicitud de cambio de idioma expiró o no es válida.',
                'request_id' => $request_id,
            ], 403);
        }

        $target_lang = isset($_POST['lang']) ? sanitize_key(wp_unslash($_POST['lang'])) : '';
        $object_id = isset($_POST['object_id']) ? absint($_POST['object_id']) : 0;
        $object_type = isset($_POST['object_type']) ? sanitize_key(wp_unslash($_POST['object_type'])) : '';

        if (empty($target_lang)) {
            $this->log_event('missing_target_language', 'warning', $current_url, $source_lang, '', '', 'El selector no proporcionó un idioma destino.', $request_id, 'request_validation');
            wp_send_json_error([
                'code' => 'missing_target_language',
                'message' => 'El selector no proporcionó un idioma destino.',
                'request_id' => $request_id,
            ], 422);
        }

        if (empty($source_lang)) {
            $this->log_event('source_language_unknown', 'warning', $current_url, '', $target_lang, '', 'No se pudo determinar el idioma de origen a partir de la URL frontend.', $request_id, 'language_detection');
            wp_send_json_error([
                'code' => 'source_language_unknown',
                'message' => 'No se pudo identificar el idioma de la página actual. Se conserva el enlace original.',
                'request_id' => $request_id,
            ], 422);
        }

        // 1) intentar encontrar mapping en tabla por object_id
        global $wpdb;
        $table = $this->table;

        if ($object_id) {
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$table} WHERE source_id = %d AND source_lang = %s AND target_lang = %s AND active = 1 LIMIT 1",
                $object_id, $source_lang, $target_lang
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
                $source_lang,
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

        // Registrar la resolución fallida sin almacenar parámetros de URL.
        $this->log_event(
            'translation_not_found',
            'warning',
            $current_url,
            $source_lang,
            $target_lang,
            '',
            $object_id ? 'No se encontró mapping ni traducción para el objeto solicitado.' : 'No se encontró mapping ni traducción; no se recibió un ID de objeto.',
            $request_id,
            'translation_lookup'
        );

        wp_send_json_error([
            'code' => 'translation_not_found',
            'message' => 'No se encontró un destino para el idioma solicitado.',
            'request_id' => $request_id,
        ], 404);
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
        add_submenu_page('options-general.php', 'SLR Diagnostics', 'SLR Diagnostics', 'manage_options', 'slr-diagnostics', [$this, 'admin_diagnostics_page']);
    }

    /**
     * Registra advertencias de resolución sin guardar query strings ni fragmentos.
     */
    private function log_event($event_type, $severity, $source_url, $source_lang, $target_lang, $target_url, $details, $request_id = '', $resolution_method = '') {
        global $wpdb;

        $logs_table = $wpdb->prefix . 'slr_redirect_logs';
        $wpdb->insert($logs_table, [
            'event_type' => sanitize_key($event_type),
            'request_id' => sanitize_text_field($request_id),
            'resolution_method' => sanitize_key($resolution_method),
            'severity' => sanitize_key($severity),
            'source_url' => $this->sanitize_log_url($source_url),
            'source_lang' => sanitize_text_field($source_lang),
            'target_lang' => sanitize_text_field($target_lang),
            'target_url' => $this->sanitize_log_url($target_url),
            'details' => sanitize_textarea_field($details),
            'created' => current_time('mysql'),
        ], ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s']);

        // Retención: 30 días y un máximo aproximado de 500 eventos.
        $wpdb->query("DELETE FROM {$logs_table} WHERE created < DATE_SUB(NOW(), INTERVAL 30 DAY)");
        $wpdb->query("DELETE FROM {$logs_table} WHERE id NOT IN (SELECT id FROM (SELECT id FROM {$logs_table} ORDER BY id DESC LIMIT 500) AS recent_logs)");
    }

    /**
     * Guarda solo host y ruta para evitar persistir tokens u otros parámetros sensibles.
     */
    private function sanitize_log_url($url) {
        if (empty($url)) {
            return '';
        }

        $parsed = wp_parse_url($url);
        if (!$parsed || empty($parsed['host'])) {
            return '';
        }

        $scheme = isset($parsed['scheme']) ? $parsed['scheme'] . '://' : '';
        $path = isset($parsed['path']) ? $parsed['path'] : '/';

        return esc_url_raw($scheme . $parsed['host'] . $path);
    }

    /**
     * Pantalla de diagnóstico de eventos anómalos.
     */
    public function admin_diagnostics_page() {
        if (!current_user_can('manage_options')) {
            wp_die('No autorizado');
        }

        global $wpdb;
        $logs_table = $wpdb->prefix . 'slr_redirect_logs';
        $logs = $wpdb->get_results("SELECT * FROM {$logs_table} ORDER BY created DESC, id DESC LIMIT 100");
        $total_logs = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$logs_table}");
        $warning_logs = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$logs_table} WHERE severity = 'warning'");

        ?>
        <div class="wrap">
            <h1>SLR Diagnostics</h1>
            <p>Advertencias de resolución de idioma. Se conservan hasta 30 días, con un máximo de 500 eventos.</p>
            <p><strong><?php echo esc_html(number_format_i18n($total_logs)); ?></strong> eventos registrados ·
                <strong><?php echo esc_html(number_format_i18n($warning_logs)); ?></strong> advertencias</p>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin: 16px 0;">
                <?php wp_nonce_field('slr_clear_logs'); ?>
                <input type="hidden" name="action" value="slr_clear_logs">
                <?php submit_button('Limpiar registros', 'secondary', 'submit', false); ?>
            </form>

            <table class="widefat striped">
                <thead>
                    <tr>
                        <th>Fecha</th><th>Evento</th><th>Request ID</th><th>Etapa</th><th>Origen</th><th>Idioma</th><th>Destino</th><th>Detalle</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($logs): foreach ($logs as $log): ?>
                    <tr>
                        <td><?php echo esc_html($log->created); ?></td>
                        <td><strong><?php echo esc_html($log->event_type); ?></strong><br><span class="description"><?php echo esc_html($log->severity); ?></span></td>
                        <td><code title="<?php echo esc_attr($log->request_id); ?>"><?php echo esc_html(substr($log->request_id, 0, 8)); ?></code></td>
                        <td><?php echo esc_html($log->resolution_method ?: '—'); ?></td>
                        <td style="max-width:260px;overflow-wrap:anywhere;"><?php echo esc_html($log->source_url); ?></td>
                        <td><?php echo esc_html($log->source_lang ?: '—'); ?> → <?php echo esc_html($log->target_lang ?: '—'); ?></td>
                        <td style="max-width:260px;overflow-wrap:anywhere;"><?php echo esc_html($log->target_url ?: '—'); ?></td>
                        <td><?php echo esc_html($log->details); ?></td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="8">Todavía no hay eventos registrados.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /**
     * Elimina los registros mediante una acción protegida por capability y nonce.
     */
    public function admin_clear_logs() {
        if (!current_user_can('manage_options')) {
            wp_die('No autorizado');
        }

        check_admin_referer('slr_clear_logs');

        global $wpdb;
        $logs_table = $wpdb->prefix . 'slr_redirect_logs';
        $wpdb->query("TRUNCATE TABLE {$logs_table}");

        wp_safe_redirect(admin_url('options-general.php?page=slr-diagnostics'));
        exit;
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
                    <p class="slr-admin-eyebrow">WP Language Redirector · Administración</p>
                    <h1>Mapeos de idiomas</h1>
                    <p class="slr-admin-description">Administrá los destinos por idioma y controlá qué reglas participan en la resolución.</p>
                </div>
                <span class="slr-admin-version">Versión <?php echo esc_html(SLR_VERSION); ?></span>
            </header>

            <section class="slr-admin-stats" aria-label="Resumen de mapeos">
                <div class="slr-admin-stat">
                    <span class="slr-admin-stat-label">Total de mapeos</span>
                    <strong class="slr-admin-stat-value"><?php echo esc_html(number_format_i18n($total_mappings)); ?></strong>
                    <span class="slr-admin-stat-note">Todas las reglas guardadas</span>
                </div>
                <div class="slr-admin-stat is-active">
                    <span class="slr-admin-stat-label">Activos</span>
                    <strong class="slr-admin-stat-value"><?php echo esc_html(number_format_i18n($active_mappings)); ?></strong>
                    <span class="slr-admin-stat-note">Disponibles para resolver destinos</span>
                </div>
                <div class="slr-admin-stat is-inactive">
                    <span class="slr-admin-stat-label">Inactivos</span>
                    <strong class="slr-admin-stat-value"><?php echo esc_html(number_format_i18n($inactive_mappings)); ?></strong>
                    <span class="slr-admin-stat-note">Guardados, pero no utilizados</span>
                </div>
            </section>

            <?php if (isset($_GET['slr_notice']) && 'updated' === sanitize_key(wp_unslash($_GET['slr_notice']))): ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Estado del mapping actualizado.', 'schefferlii-lang-redirector'); ?></p></div>
            <?php elseif (isset($_GET['slr_notice']) && 'error' === sanitize_key(wp_unslash($_GET['slr_notice']))): ?>
                <div class="notice notice-error"><p><?php esc_html_e('No se pudo actualizar el estado del mapping.', 'schefferlii-lang-redirector'); ?></p></div>
            <?php endif; ?>

            <h2>Crear un mapeo</h2>
            <div class="slr-admin-panel">
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('slr_add_mapping'); ?>
                <input type="hidden" name="action" value="slr_add_mapping">
                <table class="form-table" role="presentation">
                    <tbody>
                    <tr>
                        <th><label for="slr-source-url">URL de origen</label></th>
                        <td><input id="slr-source-url" name="source_url" type="url" class="regular-text" placeholder="https://tusitio.com/es/categoria/" required />
                            <p class="description">URL completa de la página actual, incluyendo el dominio y la ruta.</p></td>
                    </tr>
                    <tr>
                        <th><label for="slr-source-lang">Idioma de origen</label></th>
                        <td><input id="slr-source-lang" name="source_lang" type="text" class="small-text" value="es" maxlength="10" required />
                            <p class="description">Código del idioma actual. Ejemplo: <code>es</code>.</p></td>
                    </tr>
                    <tr>
                        <th><label for="slr-target-lang">Idioma de destino</label></th>
                        <td><input id="slr-target-lang" name="target_lang" type="text" class="small-text" value="en" maxlength="10" required />
                            <p class="description">Código del idioma al que se quiere dirigir. Ejemplo: <code>en</code>.</p></td>
                    </tr>
                    <tr>
                        <th><label for="slr-target-url">URL de destino</label></th>
                        <td><input id="slr-target-url" name="target_url" type="url" class="regular-text" placeholder="https://tusitio.com/en/category/" required />
                            <p class="description">URL completa que se utilizará cuando se solicite el idioma de destino.</p></td>
                    </tr>
                    <tr>
                        <th><label for="slr-redirect-type">Tipo de redirección</label></th>
                        <td>
                            <select id="slr-redirect-type" name="redirect_type">
                                <option value="301">301 (Permanente)</option>
                                <option value="302">302 (Temporal)</option>
                            </select>
                            <p class="description">Se almacena como dato del mapeo. Actualmente no cambia el código HTTP de la navegación JavaScript.</p>
                        </td>
                    </tr>
                    </tbody>
                </table>
                <?php submit_button('Añadir mapping'); ?>
            </form>
            </div>

            <h2>Mapeos existentes</h2>
            <div class="slr-admin-panel">
            <table class="widefat striped">
                <thead><tr>
                    <th>ID</th><th>Origen</th><th>Idioma origen</th><th>Idioma destino</th><th>Destino</th><th>Estado</th><th>Acciones</th>
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
                    <tr><td colspan="7">Todavía no hay mapeos. Creá el primero con el formulario superior.</td></tr>
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
     * Detecta el idioma de origen a partir de la URL frontend enviada por el navegador.
     *
     * No utiliza REQUEST_URI ni wpm_get_language() en AJAX: ambos pueden representar
     * el contexto de admin-ajax.php o el idioma por defecto, no la página visitada.
     */
    private function detect_language_from_url($url) {
        if (empty($url)) {
            return '';
        }

        $parsed = wp_parse_url($url);
        if (!$parsed) {
            return '';
        }

        if (!empty($parsed['query'])) {
            parse_str($parsed['query'], $query);
            if (!empty($query['lang'])) {
                $query_lang = sanitize_key($query['lang']);
                if (in_array($query_lang, ['es', 'en', 'fr'], true)) {
                    return $query_lang;
                }
            }
        }

        $path = isset($parsed['path']) ? trim($parsed['path'], '/') : '';
        $segments = $path === '' ? [] : explode('/', $path);
        $first_segment = isset($segments[0]) ? sanitize_key($segments[0]) : '';

        return in_array($first_segment, ['es', 'en', 'fr'], true) ? $first_segment : '';
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
