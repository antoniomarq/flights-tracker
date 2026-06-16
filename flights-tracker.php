<?php
/**
 * Plugin Name: Flights Tracker
 * Description: Buscador móvil de vuelos con guardado por usuario desde la tabla vuelos_live.
 * Version: 0.3.5
 * Author: Antonio Marquez
 * Text Domain: flights-tracker
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Flights_Tracker_Plugin
{
    private const VERSION = '0.3.5';
    private const LIVE_TABLE = 'vuelos_live';
    private const NONCE_ACTION = 'flights_tracker_nonce';
    private const PER_PAGE = 25;

    public static function init(): void
    {
        $plugin = new self();

        register_activation_hook(__FILE__, [$plugin, 'activate']);

        add_action('wp_enqueue_scripts', [$plugin, 'register_assets']);
        add_action('init', [$plugin, 'maybe_upgrade']);
        add_shortcode('flights_tracker', [$plugin, 'render_tracker_shortcode']);
        add_shortcode('flights_tracker_saved', [$plugin, 'render_saved_shortcode']);
        add_shortcode('flights_tracker_debug', [$plugin, 'render_debug_shortcode']);

        add_action('wp_ajax_flights_tracker_search', [$plugin, 'ajax_search']);
        add_action('wp_ajax_nopriv_flights_tracker_search', [$plugin, 'ajax_search']);
        add_action('wp_ajax_flights_tracker_matches', [$plugin, 'ajax_matches']);
        add_action('wp_ajax_nopriv_flights_tracker_matches', [$plugin, 'ajax_matches']);
        add_action('wp_ajax_flights_tracker_save', [$plugin, 'ajax_save']);
        add_action('wp_ajax_flights_tracker_saved', [$plugin, 'ajax_saved']);
        add_action('wp_ajax_flights_tracker_delete_saved', [$plugin, 'ajax_delete_saved']);
        add_action('wp_ajax_flights_tracker_complete_saved', [$plugin, 'ajax_complete_saved']);
        add_action('wp_ajax_flights_tracker_download_saved_pdf', [$plugin, 'download_saved_pdf']);
    }

    public function activate(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table = $this->saved_table();
        $charset = $wpdb->get_charset_collate();

        dbDelta("CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            primary_flight_id bigint(20) unsigned NOT NULL,
            related_flight_id bigint(20) unsigned DEFAULT NULL,
            base_iata char(3) NOT NULL DEFAULT 'AGP',
            registration varchar(20) DEFAULT NULL,
            note varchar(255) DEFAULT NULL,
            completed_at datetime DEFAULT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY idx_user_created (user_id, created_at),
            KEY idx_user_completed (user_id, completed_at),
            KEY idx_user_registration (user_id, registration),
            UNIQUE KEY uq_user_pair (user_id, primary_flight_id, related_flight_id)
        ) {$charset};");

        update_option('flights_tracker_db_version', self::VERSION, false);
    }

    public function maybe_upgrade(): void
    {
        if (get_option('flights_tracker_db_version') === self::VERSION) {
            return;
        }

        $this->activate();
    }

    public function register_assets(): void
    {
        $base_url = plugin_dir_url(__FILE__);

        wp_register_style('flights-tracker', $base_url . 'assets/css/flights-tracker.css', [], self::VERSION);
        wp_register_script('flights-tracker', $base_url . 'assets/js/flights-tracker.js', [], self::VERSION, true);
    }

    public function render_tracker_shortcode($atts): string
    {
        $atts = shortcode_atts([
            'base' => 'AGP',
            'refresh' => 60,
            'table' => '',
            'per_page' => self::PER_PAGE,
        ], $atts, 'flights_tracker');

        $base = strtoupper(sanitize_text_field($atts['base']));
        $refresh = max(15, absint($atts['refresh']));
        $table = $this->sanitize_table_name((string) $atts['table']);
        $per_page = max(5, min(50, absint($atts['per_page'])));
        $today = wp_date('Y-m-d');
        $search_id = wp_unique_id('ft-query-');

        $this->enqueue_assets($base, $per_page, $refresh, $table);

        ob_start();
        ?>
        <div class="ft-app" data-ft-app data-base="<?php echo esc_attr($base); ?>" data-per-page="<?php echo esc_attr((string) $per_page); ?>" data-table="<?php echo esc_attr($table); ?>">
            <form class="ft-search" data-ft-search-form>
                <label class="ft-search__label" for="<?php echo esc_attr($search_id); ?>">Buscar vuelo</label>
                <div class="ft-search__row">
                    <input id="<?php echo esc_attr($search_id); ?>" class="ft-search__input" data-ft-query type="search" placeholder="Matricula, vuelo o compania" autocomplete="off">
                    <button class="ft-button ft-button--primary" type="submit">Buscar</button>
                </div>

                <div class="ft-filters">
                    <label>
                        <span>Desde</span>
                        <input type="date" data-ft-date-from value="<?php echo esc_attr($today); ?>">
                    </label>
                    <label>
                        <span>Hasta</span>
                        <input type="date" data-ft-date-to value="<?php echo esc_attr($today); ?>">
                    </label>
                    <label>
                        <span>Tipo</span>
                        <select data-ft-direction>
                            <option value="all">Llegada/salida</option>
                            <option value="arrival">Llegada</option>
                            <option value="departure">Salida</option>
                        </select>
                    </label>
                </div>
            </form>

            <div class="ft-toolbar">
                <span data-ft-summary>Actualizando vuelos...</span>
                <button class="ft-button ft-button--ghost" type="button" data-ft-refresh>Actualizar</button>
            </div>

            <div class="ft-alert" data-ft-alert hidden></div>
            <div class="ft-pagination ft-pagination--top" data-ft-pagination hidden></div>
            <div class="ft-list" data-ft-results></div>
            <div class="ft-pagination" data-ft-pagination hidden></div>

            <div class="ft-modal" data-ft-modal hidden>
                <div class="ft-modal__panel" role="dialog" aria-modal="true" aria-labelledby="ft-modal-title">
                    <button class="ft-modal__close" type="button" data-ft-modal-close aria-label="Cerrar">x</button>
                    <h3 id="ft-modal-title">Guardar vuelo</h3>
                    <p data-ft-modal-intro></p>
                    <div class="ft-match-list" data-ft-match-list></div>
                </div>
            </div>
        </div>
        <?php

        return (string) ob_get_clean();
    }

    public function render_saved_shortcode($atts = []): string
    {
        if (!is_user_logged_in()) {
            return '<div class="ft-app"><div class="ft-empty">Debes iniciar sesion para ver tus vuelos guardados.</div></div>';
        }

        $atts = shortcode_atts(['table' => ''], $atts, 'flights_tracker_saved');
        $table = $this->sanitize_table_name((string) $atts['table']);

        $this->enqueue_assets('AGP', self::PER_PAGE, 60, $table);

        ob_start();
        ?>
        <div class="ft-app" data-ft-saved-app data-table="<?php echo esc_attr($table); ?>">
            <div class="ft-toolbar">
                <span data-ft-saved-summary>Cargando tus vuelos guardados...</span>
                <div class="ft-toolbar__actions">
                    <button class="ft-button ft-button--ghost" type="button" data-ft-saved-refresh>Actualizar</button>
                    <button class="ft-button ft-button--primary" type="button" data-ft-saved-pdf>Descargar PDF</button>
                </div>
            </div>
            <div class="ft-alert" data-ft-alert hidden></div>
            <div class="ft-list" data-ft-saved-results></div>
        </div>
        <?php

        return (string) ob_get_clean();
    }

    public function render_debug_shortcode($atts = []): string
    {
        if (!current_user_can('manage_options')) {
            return '';
        }

        $atts = shortcode_atts(['base' => 'AGP', 'table' => ''], $atts, 'flights_tracker_debug');

        global $wpdb;

        $base = strtoupper(sanitize_text_field($atts['base']));
        $table = $this->live_table((string) $atts['table']);
        $current_db = $wpdb->get_var('SELECT DATABASE()');
        $total = $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        $first_error = $wpdb->last_error;
        $base_total = null;
        $directions = [];
        $sample = [];

        if ($first_error === '') {
            $base_total = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE base_iata = %s", $base));
            $direction_rows = $wpdb->get_results($wpdb->prepare("SELECT direction, COUNT(*) AS total FROM {$table} WHERE base_iata = %s GROUP BY direction", $base), ARRAY_A);
            $sample = $wpdb->get_results($wpdb->prepare("SELECT id, base_iata, direction, numero_vuelo, aerolinea, registration, hora_programada, hora_real, estado FROM {$table} WHERE base_iata = %s ORDER BY id DESC LIMIT 5", $base), ARRAY_A);

            if (is_array($direction_rows)) {
                foreach ($direction_rows as $row) {
                    $directions[] = ($row['direction'] ?? '-') . ': ' . ($row['total'] ?? '0');
                }
            }
        }

        ob_start();
        ?>
        <div class="ft-app">
            <div class="ft-alert">
                <strong>Diagnostico Flights Tracker</strong><br>
                Base de datos actual de WordPress: <code><?php echo esc_html((string) $current_db); ?></code><br>
                Tabla consultada: <code><?php echo esc_html($table); ?></code><br>
                Base IATA: <code><?php echo esc_html($base); ?></code><br>
                Total tabla: <code><?php echo esc_html($first_error === '' ? (string) $total : 'ERROR'); ?></code><br>
                Total <?php echo esc_html($base); ?>: <code><?php echo esc_html($first_error === '' ? (string) $base_total : 'ERROR'); ?></code><br>
                Direcciones: <code><?php echo esc_html($directions ? implode(', ', $directions) : '-'); ?></code><br>
                Ultimo error SQL: <code><?php echo esc_html($first_error !== '' ? $first_error : 'sin error'); ?></code>
            </div>
            <?php if ($sample) : ?>
                <pre class="ft-debug-pre"><?php echo esc_html(wp_json_encode($sample, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); ?></pre>
            <?php endif; ?>
        </div>
        <?php

        return (string) ob_get_clean();
    }

    public function ajax_search(): void
    {
        $this->verify_nonce();

        $filters = [
            'query' => isset($_POST['query']) ? sanitize_text_field(wp_unslash($_POST['query'])) : '',
            'base' => isset($_POST['base']) ? strtoupper(sanitize_text_field(wp_unslash($_POST['base']))) : 'AGP',
            'table' => isset($_POST['table']) ? sanitize_text_field(wp_unslash($_POST['table'])) : '',
            'direction' => isset($_POST['direction']) ? sanitize_text_field(wp_unslash($_POST['direction'])) : 'all',
            'date_from' => isset($_POST['dateFrom']) ? sanitize_text_field(wp_unslash($_POST['dateFrom'])) : wp_date('Y-m-d'),
            'date_to' => isset($_POST['dateTo']) ? sanitize_text_field(wp_unslash($_POST['dateTo'])) : wp_date('Y-m-d'),
            'page' => isset($_POST['page']) ? max(1, absint($_POST['page'])) : 1,
            'per_page' => isset($_POST['perPage']) ? max(5, min(50, absint($_POST['perPage']))) : self::PER_PAGE,
            'offset' => isset($_POST['offset']) && $_POST['offset'] !== '' ? max(0, absint($_POST['offset'])) : null,
            'initial_page' => !empty($_POST['initialPage']),
        ];

        wp_send_json_success($this->find_flights($filters));
    }

    public function ajax_matches(): void
    {
        $this->verify_nonce();

        $flight_id = isset($_POST['flightId']) ? absint($_POST['flightId']) : 0;
        $table = isset($_POST['table']) ? sanitize_text_field(wp_unslash($_POST['table'])) : '';
        $flight = $this->get_flight($flight_id, $table);

        if (!$flight) {
            wp_send_json_error(['message' => 'No se ha encontrado el vuelo.'], 404);
        }

        wp_send_json_success([
            'flight' => $this->format_flight($flight),
            'matches' => array_map([$this, 'format_flight'], $this->find_related_flights($flight, $table)),
            'isLoggedIn' => is_user_logged_in(),
        ]);
    }

    public function ajax_save(): void
    {
        $this->verify_nonce();

        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => 'Debes iniciar sesion para guardar vuelos.'], 401);
        }

        global $wpdb;

        $primary_id = isset($_POST['primaryFlightId']) ? absint($_POST['primaryFlightId']) : 0;
        $related_id = isset($_POST['relatedFlightId']) ? absint($_POST['relatedFlightId']) : 0;
        $table = isset($_POST['table']) ? sanitize_text_field(wp_unslash($_POST['table'])) : '';
        $primary = $this->get_flight($primary_id, $table);
        $related = $related_id ? $this->get_flight($related_id, $table) : null;

        if (!$primary) {
            wp_send_json_error(['message' => 'El vuelo principal no existe.'], 404);
        }

        if ($related_id && !$related) {
            wp_send_json_error(['message' => 'El vuelo relacionado no existe.'], 404);
        }

        if ($related && $primary['registration'] !== $related['registration']) {
            wp_send_json_error(['message' => 'Los vuelos seleccionados no tienen la misma matricula.'], 400);
        }

        $now = current_time('mysql');
        $related_sql = $related_id ? '%d' : 'NULL';
        $params = [get_current_user_id(), $primary_id];

        if ($related_id) {
            $params[] = $related_id;
        }

        array_push($params, $primary['base_iata'], $primary['registration'], $now, $now);

        $inserted = $wpdb->query($wpdb->prepare(
            "INSERT INTO {$this->saved_table()}
                (user_id, primary_flight_id, related_flight_id, base_iata, registration, created_at, updated_at)
             VALUES (%d, %d, {$related_sql}, %s, %s, %s, %s)
             ON DUPLICATE KEY UPDATE updated_at = VALUES(updated_at)",
            $params
        ));

        if ($inserted === false) {
            wp_send_json_error(['message' => 'No se ha podido guardar el vuelo.'], 500);
        }

        wp_send_json_success(['message' => 'Vuelo guardado.']);
    }

    public function ajax_saved(): void
    {
        $this->verify_nonce();

        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => 'Debes iniciar sesion.'], 401);
        }

        $table = isset($_POST['table']) ? sanitize_text_field(wp_unslash($_POST['table'])) : '';

        wp_send_json_success([
            'saved' => $this->get_saved_flights($table),
            'serverTime' => wp_date('H:i:s'),
        ]);
    }

    public function ajax_delete_saved(): void
    {
        $this->verify_nonce();

        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => 'Debes iniciar sesion.'], 401);
        }

        global $wpdb;

        $saved_id = isset($_POST['savedId']) ? absint($_POST['savedId']) : 0;
        $deleted = $wpdb->delete($this->saved_table(), ['id' => $saved_id, 'user_id' => get_current_user_id()], ['%d', '%d']);

        if ($deleted === false) {
            wp_send_json_error(['message' => 'No se ha podido eliminar.'], 500);
        }

        wp_send_json_success(['message' => 'Vuelo eliminado.']);
    }

    public function ajax_complete_saved(): void
    {
        $this->verify_nonce();

        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => 'Debes iniciar sesion.'], 401);
        }

        global $wpdb;

        $saved_id = isset($_POST['savedId']) ? absint($_POST['savedId']) : 0;
        $completed = isset($_POST['completed']) ? absint($_POST['completed']) : 1;

        $updated = $wpdb->update(
            $this->saved_table(),
            [
                'note' => $completed ? 'done' : null,
                'completed_at' => $completed ? current_time('mysql') : null,
                'updated_at' => current_time('mysql'),
            ],
            [
                'id' => $saved_id,
                'user_id' => get_current_user_id(),
            ],
            ['%s', '%s', '%s'],
            ['%d', '%d']
        );

        if ($updated === false) {
            wp_send_json_error(['message' => 'No se ha podido marcar como realizado.'], 500);
        }

        wp_send_json_success(['message' => $completed ? 'Vuelo realizado.' : 'Vuelo pendiente.']);
    }

    public function download_saved_pdf(): void
    {
        $nonce = isset($_GET['nonce']) ? sanitize_text_field(wp_unslash($_GET['nonce'])) : '';

        if (!wp_verify_nonce($nonce, self::NONCE_ACTION)) {
            wp_die('Sesion no valida.', '', ['response' => 403]);
        }

        if (!is_user_logged_in()) {
            wp_die('Debes iniciar sesion.', '', ['response' => 401]);
        }

        $table = isset($_GET['table']) ? sanitize_text_field(wp_unslash($_GET['table'])) : '';
        $items = $this->get_saved_flights($table, 1000);
        $user = wp_get_current_user();
        $filename = 'mis-vuelos-' . sanitize_file_name($user->user_login ?: 'usuario') . '-' . wp_date('Y-m-d') . '.pdf';
        $pdf = $this->build_saved_flights_pdf($items, $user);

        nocache_headers();
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($pdf));
        echo $pdf;
        exit;
    }

    private function enqueue_assets(string $base, int $per_page, int $refresh, string $table = ''): void
    {
        wp_enqueue_style('flights-tracker');
        wp_enqueue_script('flights-tracker');

        wp_localize_script('flights-tracker', 'FlightsTracker', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce(self::NONCE_ACTION),
            'base' => $base,
            'table' => $table,
            'perPage' => $per_page,
            'refreshMs' => $refresh * 1000,
            'isLoggedIn' => is_user_logged_in(),
            'loginUrl' => wp_login_url(get_permalink()),
        ]);
    }

    private function verify_nonce(): void
    {
        $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';

        if (!wp_verify_nonce($nonce, self::NONCE_ACTION)) {
            wp_send_json_error(['message' => 'Sesion no valida. Recarga la pagina.'], 403);
        }
    }

    private function find_flights(array $filters): array
    {
        global $wpdb;

        $table = $this->live_table($filters['table']);
        $where = ['base_iata = %s'];
        $params = [$filters['base']];

        $range = $this->date_range($filters['date_from'], $filters['date_to']);
        $where[] = 'COALESCE(hora_real, hora_programada) >= %s';
        $where[] = 'COALESCE(hora_real, hora_programada) < %s';
        array_push($params, $range['start'], $range['end']);

        if ($filters['direction'] === 'arrival' || $filters['direction'] === 'departure') {
            $where[] = 'direction = %s';
            $params[] = $filters['direction'];
        }

        if ($filters['query'] !== '') {
            $like = '%' . $wpdb->esc_like($filters['query']) . '%';
            $where[] = '(registration LIKE %s OR numero_vuelo LIKE %s OR aerolinea LIKE %s)';
            array_push($params, $like, $like, $like);
        }

        $where_sql = implode(' AND ', $where);
        $total = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE {$where_sql}", $params));
        $pages = max(1, (int) ceil($total / $filters['per_page']));
        $page = (int) $filters['page'];
        $offset = null;

        if (!empty($filters['initial_page']) && $this->can_start_from_current_time($filters, $range)) {
            $anchor = $this->initial_page_anchor_time();
            $before_params = array_merge($params, [$anchor]);
            $offset = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$table}
                 WHERE {$where_sql}
                 AND COALESCE(hora_real, hora_programada) < %s",
                $before_params
            ));
        } elseif ($filters['offset'] !== null) {
            $offset = (int) $filters['offset'];
        }

        if ($offset !== null) {
            $offset = min(max(0, $offset), max(0, $total - 1));
            $page = (int) floor($offset / (int) $filters['per_page']) + 1;
        } else {
            $page = min(max(1, $page), $pages);
            $offset = ($page - 1) * (int) $filters['per_page'];
        }

        $query_params = array_merge($params, [(int) $filters['per_page'], $offset]);

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table}
             WHERE {$where_sql}
             ORDER BY COALESCE(hora_real, hora_programada), hora_programada, id
             LIMIT %d OFFSET %d",
            $query_params
        ), ARRAY_A);

        return [
            'flights' => is_array($rows) ? array_map([$this, 'format_flight'], $rows) : [],
            'pagination' => [
                'page' => $page,
                'perPage' => (int) $filters['per_page'],
                'offset' => $offset,
                'previousOffset' => max(0, $offset - (int) $filters['per_page']),
                'nextOffset' => min($total, $offset + (int) $filters['per_page']),
                'pages' => $pages,
                'total' => $total,
            ],
            'serverTime' => wp_date('H:i:s'),
            'rangeLabel' => $this->range_label($range),
        ];
    }

    private function get_flight(int $flight_id, string $table_override = ''): ?array
    {
        if (!$flight_id) {
            return null;
        }

        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->live_table($table_override)} WHERE id = %d", $flight_id), ARRAY_A);

        return is_array($row) ? $row : null;
    }

    private function find_related_flights(array $flight, string $table_override = ''): array
    {
        $registration = trim((string) ($flight['registration'] ?? ''));

        if ($registration === '') {
            return [];
        }

        global $wpdb;

        $opposite = $flight['direction'] === 'arrival' ? 'departure' : 'arrival';
        $operator = $flight['direction'] === 'arrival' ? '>=' : '<=';
        $order = $flight['direction'] === 'arrival' ? 'ASC' : 'DESC';
        $reference_time = $this->best_time_for_query($flight);

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->live_table($table_override)}
             WHERE base_iata = %s AND direction = %s AND registration = %s AND COALESCE(hora_real, hora_programada) {$operator} %s
             ORDER BY COALESCE(hora_real, hora_programada) {$order}, hora_programada {$order}, id {$order}
             LIMIT 20",
            $flight['base_iata'],
            $opposite,
            $registration,
            $reference_time
        ), ARRAY_A) ?: [];
    }

    private function get_saved_flights(string $table_override = '', int $limit = 200): array
    {
        global $wpdb;

        $saved = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->saved_table()} WHERE user_id = %d ORDER BY created_at DESC LIMIT %d",
            get_current_user_id(),
            $limit
        ), ARRAY_A);

        if (!is_array($saved)) {
            return [];
        }

        $items = [];

        foreach ($saved as $row) {
            $primary = $this->get_flight((int) $row['primary_flight_id'], $table_override);
            $related = !empty($row['related_flight_id']) ? $this->get_flight((int) $row['related_flight_id'], $table_override) : null;

            if (!$primary) {
                continue;
            }

            $formatted_primary = $this->format_flight($primary);
            $formatted_related = $related ? $this->format_flight($related) : null;
            $arrival = $formatted_primary['direction'] === 'arrival' ? $formatted_primary : null;
            $departure = $formatted_primary['direction'] === 'departure' ? $formatted_primary : null;

            if ($formatted_related) {
                if ($formatted_related['direction'] === 'arrival') {
                    $arrival = $formatted_related;
                }

                if ($formatted_related['direction'] === 'departure') {
                    $departure = $formatted_related;
                }
            }

            $items[] = [
                'id' => (int) $row['id'],
                'createdAt' => $this->format_local_datetime($row['created_at']),
                'completed' => (string) ($row['note'] ?? '') === 'done' || !empty($row['completed_at']),
                'completedAt' => $this->format_local_datetime($row['completed_at'] ?? null),
                'primary' => $formatted_primary,
                'related' => $formatted_related,
                'arrival' => $arrival,
                'departure' => $departure,
            ];
        }

        return $items;
    }

    private function build_saved_flights_pdf(array $items, WP_User $user): string
    {
        $rows = [];

        foreach ($items as $item) {
            $arrival = $item['arrival'] ?? null;
            $departure = $item['departure'] ?? null;
            $registration = $arrival['registration'] ?? $departure['registration'] ?? $item['primary']['registration'] ?? '-';
            $route_parts = array_filter([
                $arrival ? $arrival['route'] : '',
                $departure ? $departure['route'] : '',
            ]);
            $scheduled_times = array_filter([
                $arrival ? $arrival['scheduledTime'] : '',
                $departure ? $departure['scheduledTime'] : '',
            ]);
            $real_times = array_filter([
                $arrival ? $arrival['realTime'] : '',
                $departure ? $departure['realTime'] : '',
            ]);

            $rows[] = [
                'saved' => $item['createdAt'] ?: '-',
                'status' => !empty($item['completed']) ? 'Realizado' : 'Pendiente',
                'completed' => !empty($item['completed']) ? ($item['completedAt'] ?: 'Sin hora') : '-',
                'registration' => $registration ?: '-',
                'arrival' => $arrival ? ($arrival['flightNumberDisplay'] ?: $arrival['flightNumber'] ?: '-') : '-',
                'departure' => $departure ? ($departure['flightNumberDisplay'] ?: $departure['flightNumber'] ?: '-') : '-',
                'route' => $route_parts ? implode(' / ', $route_parts) : '-',
                'scheduledTime' => $scheduled_times ? implode(' / ', $scheduled_times) : '-',
                'realTime' => $real_times ? implode(' / ', $real_times) : '-',
            ];
        }

        return $this->render_saved_flights_table_pdf($rows, $user, count($items));
    }

    private function render_saved_flights_table_pdf(array $rows, WP_User $user, int $total): string
    {
        $width = 842;
        $height = 595;
        $margin = 32;
        $table_width = $width - ($margin * 2);
        $header_height = 24;
        $row_height = 48;
        $bottom = 42;
        $columns = [
            ['label' => 'Guardado', 'key' => 'saved', 'width' => 58],
            ['label' => 'Estado', 'key' => 'status', 'width' => 56],
            ['label' => 'Realizado', 'key' => 'completed', 'width' => 64],
            ['label' => 'Matricula', 'key' => 'registration', 'width' => 58],
            ['label' => 'Llegada', 'key' => 'arrival', 'width' => 54],
            ['label' => 'Salida', 'key' => 'departure', 'width' => 54],
            ['label' => 'Ruta', 'key' => 'route', 'width' => 228],
            ['label' => 'Hora programada', 'key' => 'scheduledTime', 'width' => 103],
            ['label' => 'Hora real', 'key' => 'realTime', 'width' => 103],
        ];
        $title = 'Mis vuelos';
        $user_name = $this->normalize_pdf_text($user->display_name ?: $user->user_login ?: 'Usuario');
        $generated = wp_date('d/m/Y H:i');
        $completed_total = 0;
        $pending_total = 0;
        $pages = [];
        $page_rows = [];
        $table_top = 460;
        $y = $table_top - $header_height;

        foreach ($rows as $row) {
            if ($row['status'] === 'Realizado') {
                $completed_total++;
            } else {
                $pending_total++;
            }
        }

        foreach ($rows as $row) {
            if ($y - $row_height < $bottom) {
                $pages[] = $page_rows;
                $page_rows = [];
                $y = $table_top - $header_height;
            }

            $page_rows[] = $row;
            $y -= $row_height;
        }

        if ($page_rows || !$pages) {
            $pages[] = $page_rows;
        }

        $objects = [];
        $objects[] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[] = '';
        $page_refs = [];
        $content_object_number = 3 + count($pages);
        $regular_font_object_number = 3 + (count($pages) * 2);
        $bold_font_object_number = $regular_font_object_number + 1;

        foreach ($pages as $index => $page_rows_for_page) {
            $page_refs[] = (string) (3 + $index) . ' 0 R';
            $objects[] = sprintf(
                '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %d %d] /Resources << /Font << /F1 %d 0 R /F2 %d 0 R >> >> /Contents %d 0 R >>',
                $width,
                $height,
                $regular_font_object_number,
                $bold_font_object_number,
                $content_object_number + $index
            );
        }

        foreach ($pages as $page_index => $page_rows_for_page) {
            $stream = '';
            $stream .= $this->pdf_rect(0, 0, $width, $height, '0.97 0.98 0.99');
            $stream .= $this->pdf_rect(0, $height - 86, $width, 86, '0.08 0.20 0.31');
            $stream .= $this->pdf_text($title, $margin, $height - 45, 22, 'F2', '1 1 1');
            $stream .= $this->pdf_text('Registro descargable de vuelos guardados', $margin, $height - 66, 9, 'F1', '0.82 0.90 0.96');
            $stream .= $this->pdf_text('Usuario: ' . $user_name, $width - 300, $height - 42, 10, 'F2', '1 1 1');
            $stream .= $this->pdf_text('Generado: ' . $generated, $width - 300, $height - 60, 9, 'F1', '0.82 0.90 0.96');

            $stream .= $this->pdf_rect($margin, $height - 118, 155, 38, '1 1 1');
            $stream .= $this->pdf_text('Total guardados', $margin + 12, $height - 96, 8, 'F1', '0.39 0.46 0.54');
            $stream .= $this->pdf_text((string) $total, $margin + 112, $height - 97, 15, 'F2', '0.08 0.20 0.31');

            $stream .= $this->pdf_rect($margin + 166, $height - 118, 170, 38, '1 1 1');
            $stream .= $this->pdf_text('Realizados', $margin + 178, $height - 96, 8, 'F1', '0.39 0.46 0.54');
            $stream .= $this->pdf_text((string) $completed_total, $margin + 290, $height - 97, 15, 'F2', '0.09 0.42 0.26');

            $stream .= $this->pdf_rect($margin + 348, $height - 118, 170, 38, '1 1 1');
            $stream .= $this->pdf_text('Pendientes', $margin + 360, $height - 96, 8, 'F1', '0.39 0.46 0.54');
            $stream .= $this->pdf_text((string) $pending_total, $margin + 472, $height - 97, 15, 'F2', '0.70 0.15 0.12');

            $stream .= $this->pdf_rect($margin, $table_top - $header_height, $table_width, $header_height, '0.12 0.28 0.42');

            $x = $margin;
            foreach ($columns as $column) {
                $stream .= $this->pdf_text($column['label'], $x + 5, $table_top - 17, 7.5, 'F2', '1 1 1');
                $x += $column['width'];
            }

            if (!$rows) {
                $stream .= $this->pdf_rect($margin, $table_top - $header_height - 54, $table_width, 54, '1 1 1');
                $stream .= $this->pdf_text('No hay vuelos guardados.', $margin + 14, $table_top - $header_height - 31, 10, 'F1', '0.39 0.46 0.54');
            }

            $row_y = $table_top - $header_height - $row_height;
            foreach ($page_rows_for_page as $row_index => $row) {
                $fill = $row_index % 2 === 0 ? '1 1 1' : '0.94 0.97 1';
                $stream .= $this->pdf_rect($margin, $row_y, $table_width, $row_height, $fill);
                $stream .= $this->pdf_line($margin, $row_y, $margin + $table_width, $row_y, '0.83 0.88 0.93', 0.5);

                $x = $margin;
                foreach ($columns as $column) {
                    $value = (string) ($row[$column['key']] ?? '-');
                    $font = $column['key'] === 'status' ? 'F2' : 'F1';
                    $color = '0.12 0.18 0.25';

                    if ($column['key'] === 'status' && $value === 'Realizado') {
                        $color = '0.09 0.42 0.26';
                    } elseif ($column['key'] === 'status') {
                        $color = '0.70 0.15 0.12';
                    }

                    $max_lines = in_array($column['key'], ['route', 'scheduledTime', 'realTime'], true) ? 2 : 1;
                    $stream .= $this->pdf_cell_text($value, $x + 5, $row_y + $row_height - 13, $column['width'] - 10, 6.8, $font, $color, $max_lines);
                    $x += $column['width'];
                }

                $row_y -= $row_height;
            }

            $stream .= $this->pdf_text('Pagina ' . ($page_index + 1) . ' de ' . count($pages), $width - 92, 24, 8, 'F1', '0.39 0.46 0.54');
            $objects[] = "<< /Length " . strlen($stream) . " >>\nstream\n" . $stream . "endstream";
        }

        $objects[] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objects[] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        $objects[1] = '<< /Type /Pages /Kids [' . implode(' ', $page_refs) . '] /Count ' . count($pages) . ' >>';

        $pdf = "%PDF-1.4\n";
        $offsets = [0];

        foreach ($objects as $index => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1) . " 0 obj\n" . $object . "\nendobj\n";
        }

        $xref_offset = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objects) + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";

        for ($i = 1; $i <= count($objects); $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }

        $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\n";
        $pdf .= "startxref\n" . $xref_offset . "\n%%EOF";

        return $pdf;
    }

    private function pdf_cell_text(string $text, float $x, float $y, float $width, float $size, string $font, string $color, int $max_lines = 1): string
    {
        $max_chars = max(4, (int) floor($width / ($size * 0.48)));
        $lines = explode("\n", wordwrap($this->normalize_pdf_text($text), $max_chars, "\n", true));
        $lines = array_slice($lines, 0, $max_lines);

        if (!$lines) {
            $lines = ['-'];
        }

        $stream = '';

        foreach ($lines as $index => $line) {
            $suffix = $index === $max_lines - 1 && count(explode("\n", wordwrap($this->normalize_pdf_text($text), $max_chars, "\n", true))) > $max_lines ? '...' : '';
            $stream .= $this->pdf_text(rtrim($line) . $suffix, $x, $y - ($index * ($size + 2)), $size, $font, $color);
        }

        return $stream;
    }

    private function pdf_text(string $text, float $x, float $y, float $size, string $font = 'F1', string $color = '0 0 0'): string
    {
        return sprintf(
            "BT\n/%s %.2F Tf\n%s rg\n1 0 0 1 %.2F %.2F Tm\n(%s) Tj\nET\n",
            $font,
            $size,
            $color,
            $x,
            $y,
            $this->escape_pdf_text($this->normalize_pdf_text($text))
        );
    }

    private function pdf_rect(float $x, float $y, float $width, float $height, string $fill): string
    {
        return sprintf("q\n%s rg\n%.2F %.2F %.2F %.2F re\nf\nQ\n", $fill, $x, $y, $width, $height);
    }

    private function pdf_line(float $x1, float $y1, float $x2, float $y2, string $stroke, float $width = 1): string
    {
        return sprintf("q\n%s RG\n%.2F w\n%.2F %.2F m\n%.2F %.2F l\nS\nQ\n", $stroke, $width, $x1, $y1, $x2, $y2);
    }

    private function normalize_pdf_text(string $text): string
    {
        $text = remove_accents($text);
        $text = str_replace(['→', '·'], ['->', '-'], $text);

        return preg_replace('/[^\x20-\x7E]/', '', $text) ?: '';
    }

    private function escape_pdf_text(string $text): string
    {
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
    }

    private function format_flight(array $row): array
    {
        $direction = (string) ($row['direction'] ?? '');
        $flight_number = (string) ($row['numero_vuelo'] ?? '');

        return [
            'id' => (int) $row['id'],
            'baseIata' => (string) ($row['base_iata'] ?? ''),
            'direction' => $direction,
            'directionLabel' => $direction === 'arrival' ? 'Llegada' : 'Salida',
            'flightNumber' => $flight_number,
            'flightNumberDisplay' => $this->short_flight_number($flight_number),
            'airline' => (string) ($row['aerolinea'] ?? ''),
            'origin' => (string) ($row['origen'] ?? ''),
            'destination' => (string) ($row['destino'] ?? ''),
            'route' => $this->route_label($row),
            'scheduledTime' => $this->format_datetime($row['hora_programada'] ?? null),
            'realTime' => $this->format_datetime($row['hora_real'] ?? null),
            'status' => (string) ($row['estado'] ?? ''),
            'statusType' => $this->status_type((string) ($row['estado'] ?? '')),
            'aircraftType' => (string) ($row['aircraft_type'] ?? ''),
            'registration' => (string) ($row['registration'] ?? ''),
            'lastSeenAt' => $this->format_datetime($row['last_seen_at'] ?? null),
        ];
    }

    private function route_label(array $row): string
    {
        $base = (string) ($row['base_iata'] ?? '');

        if (($row['direction'] ?? '') === 'arrival') {
            return trim((string) ($row['origen'] ?? '')) . ' -> ' . $base;
        }

        return $base . ' -> ' . trim((string) ($row['destino'] ?? ''));
    }

    private function short_flight_number(string $flight_number): string
    {
        preg_match_all('/\d+/', $flight_number, $matches);
        $digits = implode('', $matches[0] ?? []);

        if ($digits === '') {
            return $flight_number;
        }

        return substr($digits, -4);
    }

    private function status_type(string $status): string
    {
        $status = strtolower($status);

        if (strpos($status, 'landed') === 0) {
            return 'landed';
        }

        if (strpos($status, 'departed') === 0) {
            return 'departed';
        }

        if (strpos($status, 'delayed') === 0) {
            return 'delayed';
        }

        if (strpos($status, 'estimated') === 0) {
            return 'estimated';
        }

        if (strpos($status, 'scheduled') === 0) {
            return 'scheduled';
        }

        return 'unknown';
    }

    private function format_datetime($value): string
    {
        if (!$value || $value === '0000-00-00 00:00:00') {
            return '';
        }

        $timestamp = strtotime((string) $value . ' UTC');

        if (!$timestamp) {
            return '';
        }

        if (!(bool) apply_filters('flights_tracker_times_are_utc', true)) {
            $timestamp = strtotime((string) $value);
        }

        $flight_date = wp_date('Y-m-d', $timestamp);
        $today = wp_date('Y-m-d');

        return $flight_date === $today ? wp_date('H:i', $timestamp) : wp_date('d/m/y H:i', $timestamp);
    }

    private function format_local_datetime($value): string
    {
        if (!$value || $value === '0000-00-00 00:00:00') {
            return '';
        }

        $datetime = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', (string) $value, wp_timezone());

        if (!$datetime) {
            return '';
        }

        $timestamp = $datetime->getTimestamp();
        $date = wp_date('Y-m-d', $timestamp);

        return $date === wp_date('Y-m-d') ? wp_date('H:i', $timestamp) : wp_date('d/m/y H:i', $timestamp);
    }

    private function date_range(string $from, string $to): array
    {
        $today = wp_date('Y-m-d');

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
            $from = $today;
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            $to = $from;
        }

        if ($to < $from) {
            $to = $from;
        }

        return [
            'from' => $from,
            'to' => $to,
            'start' => $from . ' 00:00:00',
            'end' => gmdate('Y-m-d H:i:s', strtotime($to . ' 00:00:00 UTC') + DAY_IN_SECONDS),
        ];
    }

    private function range_label(array $range): string
    {
        if ($range['from'] === $range['to']) {
            return wp_date('d/m/y', strtotime($range['from'] . ' 00:00:00'));
        }

        return wp_date('d/m/y', strtotime($range['from'] . ' 00:00:00')) . ' - ' . wp_date('d/m/y', strtotime($range['to'] . ' 00:00:00'));
    }

    private function can_start_from_current_time(array $filters, array $range): bool
    {
        return $filters['query'] === ''
            && $range['from'] === wp_date('Y-m-d')
            && $range['to'] === wp_date('Y-m-d');
    }

    private function initial_page_anchor_time(): string
    {
        $anchor = current_datetime()->modify('-30 minutes');

        if ((bool) apply_filters('flights_tracker_times_are_utc', true)) {
            $anchor = $anchor->setTimezone(new DateTimeZone('UTC'));
        }

        return $anchor->format('Y-m-d H:i:s');
    }

    private function best_time_for_query(array $flight): string
    {
        $real = trim((string) ($flight['hora_real'] ?? ''));
        $scheduled = trim((string) ($flight['hora_programada'] ?? ''));

        return $real !== '' ? $real : $scheduled;
    }

    private function live_table(string $override = ''): string
    {
        $table = $this->sanitize_table_name($override);

        if ($table === '' && defined('FLIGHTS_TRACKER_LIVE_TABLE')) {
            $table = $this->sanitize_table_name((string) FLIGHTS_TRACKER_LIVE_TABLE);
        }

        if ($table === '') {
            $table = $this->sanitize_table_name((string) apply_filters('flights_tracker_live_table', self::LIVE_TABLE));
        }

        if ($table === '') {
            $table = self::LIVE_TABLE;
        }

        return implode('.', array_map(static function (string $part): string {
            return '`' . str_replace('`', '``', $part) . '`';
        }, explode('.', $table)));
    }

    private function sanitize_table_name(string $table): string
    {
        $table = trim($table);

        if ($table === '') {
            return '';
        }

        return preg_match('/^[A-Za-z0-9_]+(\.[A-Za-z0-9_]+)?$/', $table) ? $table : '';
    }

    private function saved_table(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'flights_tracker_saved';
    }
}

Flights_Tracker_Plugin::init();
