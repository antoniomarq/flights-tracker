<?php
/**
 * Plugin Name: Flights Tracker
 * Description: Buscador vivo de vuelos con guardado por usuario desde la tabla vuelos_live.
 * Version: 0.1.0
 * Author: Antonio Marquez
 * Text Domain: flights-tracker
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Flights_Tracker_Plugin
{
    private const VERSION = '0.1.0';
    private const LIVE_TABLE = 'vuelos_live';
    private const NONCE_ACTION = 'flights_tracker_nonce';

    public static function init(): void
    {
        $plugin = new self();

        register_activation_hook(__FILE__, [$plugin, 'activate']);

        add_action('wp_enqueue_scripts', [$plugin, 'register_assets']);
        add_shortcode('flights_tracker', [$plugin, 'render_tracker_shortcode']);
        add_shortcode('flights_tracker_saved', [$plugin, 'render_saved_shortcode']);

        add_action('wp_ajax_flights_tracker_search', [$plugin, 'ajax_search']);
        add_action('wp_ajax_nopriv_flights_tracker_search', [$plugin, 'ajax_search']);
        add_action('wp_ajax_flights_tracker_matches', [$plugin, 'ajax_matches']);
        add_action('wp_ajax_nopriv_flights_tracker_matches', [$plugin, 'ajax_matches']);
        add_action('wp_ajax_flights_tracker_save', [$plugin, 'ajax_save']);
        add_action('wp_ajax_flights_tracker_saved', [$plugin, 'ajax_saved']);
        add_action('wp_ajax_flights_tracker_delete_saved', [$plugin, 'ajax_delete_saved']);
    }

    public function activate(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table = $this->saved_table();
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            primary_flight_id bigint(20) unsigned NOT NULL,
            related_flight_id bigint(20) unsigned DEFAULT NULL,
            base_iata char(3) NOT NULL DEFAULT 'AGP',
            registration varchar(20) DEFAULT NULL,
            note varchar(255) DEFAULT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY idx_user_created (user_id, created_at),
            KEY idx_user_registration (user_id, registration),
            UNIQUE KEY uq_user_pair (user_id, primary_flight_id, related_flight_id)
        ) {$charset};";

        dbDelta($sql);
    }

    public function register_assets(): void
    {
        $base_url = plugin_dir_url(__FILE__);

        wp_register_style(
            'flights-tracker',
            $base_url . 'assets/css/flights-tracker.css',
            [],
            self::VERSION
        );

        wp_register_script(
            'flights-tracker',
            $base_url . 'assets/js/flights-tracker.js',
            [],
            self::VERSION,
            true
        );
    }

    public function render_tracker_shortcode($atts): string
    {
        $atts = shortcode_atts(
            [
                'base' => 'AGP',
                'limit' => 80,
                'refresh' => 60,
            ],
            $atts,
            'flights_tracker'
        );

        $base = strtoupper(sanitize_text_field($atts['base']));
        $limit = max(1, min(200, absint($atts['limit'])));
        $refresh = max(15, absint($atts['refresh']));

        $this->enqueue_assets($base, $limit, $refresh);
        $search_id = wp_unique_id('ft-query-');

        ob_start();
        ?>
        <div class="ft-app" data-ft-app data-base="<?php echo esc_attr($base); ?>" data-limit="<?php echo esc_attr((string) $limit); ?>">
            <form class="ft-search" data-ft-search-form>
                <label class="ft-search__label" for="<?php echo esc_attr($search_id); ?>">
                    Buscar vuelo
                </label>
                <div class="ft-search__row">
                    <input
                        id="<?php echo esc_attr($search_id); ?>"
                        class="ft-search__input"
                        data-ft-query
                        type="search"
                        placeholder="Matrícula, vuelo o compañía"
                        autocomplete="off"
                    >
                    <button class="ft-button ft-button--primary" type="submit">Buscar</button>
                </div>
            </form>

            <div class="ft-toolbar">
                <span data-ft-summary>Actualizando vuelos...</span>
                <button class="ft-button ft-button--ghost" type="button" data-ft-refresh>Actualizar</button>
            </div>

            <div class="ft-alert" data-ft-alert hidden></div>
            <div class="ft-list" data-ft-results></div>

            <div class="ft-modal" data-ft-modal hidden>
                <div class="ft-modal__panel" role="dialog" aria-modal="true" aria-labelledby="ft-modal-title">
                    <button class="ft-modal__close" type="button" data-ft-modal-close aria-label="Cerrar">×</button>
                    <h3 id="ft-modal-title">Guardar vuelo</h3>
                    <p data-ft-modal-intro></p>
                    <div class="ft-match-list" data-ft-match-list></div>
                </div>
            </div>
        </div>
        <?php

        return (string) ob_get_clean();
    }

    public function render_saved_shortcode(): string
    {
        if (!is_user_logged_in()) {
            return '<div class="ft-app"><div class="ft-empty">Debes iniciar sesión para ver tus vuelos guardados.</div></div>';
        }

        $this->enqueue_assets('AGP', 80, 60);

        ob_start();
        ?>
        <div class="ft-app" data-ft-saved-app>
            <div class="ft-toolbar">
                <span data-ft-saved-summary>Cargando tus vuelos guardados...</span>
                <button class="ft-button ft-button--ghost" type="button" data-ft-saved-refresh>Actualizar</button>
            </div>
            <div class="ft-alert" data-ft-alert hidden></div>
            <div class="ft-list" data-ft-saved-results></div>
        </div>
        <?php

        return (string) ob_get_clean();
    }

    public function ajax_search(): void
    {
        $this->verify_nonce();

        $query = isset($_POST['query']) ? sanitize_text_field(wp_unslash($_POST['query'])) : '';
        $base = isset($_POST['base']) ? strtoupper(sanitize_text_field(wp_unslash($_POST['base']))) : 'AGP';
        $limit = isset($_POST['limit']) ? absint($_POST['limit']) : 80;
        $limit = max(1, min(200, $limit));

        wp_send_json_success([
            'flights' => $this->find_flights($base, $query, $limit),
            'serverTime' => wp_date('H:i:s'),
        ]);
    }

    public function ajax_matches(): void
    {
        $this->verify_nonce();

        $flight_id = isset($_POST['flightId']) ? absint($_POST['flightId']) : 0;
        $flight = $this->get_flight($flight_id);

        if (!$flight) {
            wp_send_json_error(['message' => 'No se ha encontrado el vuelo.'], 404);
        }

        $matches = $this->find_related_flights($flight);

        wp_send_json_success([
            'flight' => $this->format_flight($flight),
            'matches' => array_map([$this, 'format_flight'], $matches),
            'isLoggedIn' => is_user_logged_in(),
        ]);
    }

    public function ajax_save(): void
    {
        $this->verify_nonce();

        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => 'Debes iniciar sesión para guardar vuelos.'], 401);
        }

        global $wpdb;

        $primary_id = isset($_POST['primaryFlightId']) ? absint($_POST['primaryFlightId']) : 0;
        $related_id = isset($_POST['relatedFlightId']) ? absint($_POST['relatedFlightId']) : 0;

        $primary = $this->get_flight($primary_id);
        $related = $related_id ? $this->get_flight($related_id) : null;

        if (!$primary) {
            wp_send_json_error(['message' => 'El vuelo principal no existe.'], 404);
        }

        if ($related_id && !$related) {
            wp_send_json_error(['message' => 'El vuelo relacionado no existe.'], 404);
        }

        if ($related && $primary['registration'] !== $related['registration']) {
            wp_send_json_error(['message' => 'Los vuelos seleccionados no tienen la misma matrícula.'], 400);
        }

        $now = current_time('mysql');
        $related_sql = $related_id ? '%d' : 'NULL';
        $params = [
            get_current_user_id(),
            $primary_id,
        ];

        if ($related_id) {
            $params[] = $related_id;
        }

        array_push(
            $params,
            $primary['base_iata'],
            $primary['registration'],
            $now,
            $now
        );

        $inserted = $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO {$this->saved_table()}
                    (user_id, primary_flight_id, related_flight_id, base_iata, registration, created_at, updated_at)
                 VALUES (%d, %d, {$related_sql}, %s, %s, %s, %s)
                 ON DUPLICATE KEY UPDATE updated_at = VALUES(updated_at)",
                $params
            )
        );

        if ($inserted === false) {
            wp_send_json_error(['message' => 'No se ha podido guardar el vuelo.'], 500);
        }

        wp_send_json_success(['message' => 'Vuelo guardado.']);
    }

    public function ajax_saved(): void
    {
        $this->verify_nonce();

        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => 'Debes iniciar sesión.'], 401);
        }

        wp_send_json_success([
            'saved' => $this->get_saved_flights(),
            'serverTime' => wp_date('H:i:s'),
        ]);
    }

    public function ajax_delete_saved(): void
    {
        $this->verify_nonce();

        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => 'Debes iniciar sesión.'], 401);
        }

        global $wpdb;

        $saved_id = isset($_POST['savedId']) ? absint($_POST['savedId']) : 0;

        $deleted = $wpdb->delete(
            $this->saved_table(),
            [
                'id' => $saved_id,
                'user_id' => get_current_user_id(),
            ],
            ['%d', '%d']
        );

        if ($deleted === false) {
            wp_send_json_error(['message' => 'No se ha podido eliminar.'], 500);
        }

        wp_send_json_success(['message' => 'Vuelo eliminado.']);
    }

    private function enqueue_assets(string $base, int $limit, int $refresh): void
    {
        wp_enqueue_style('flights-tracker');
        wp_enqueue_script('flights-tracker');

        wp_localize_script(
            'flights-tracker',
            'FlightsTracker',
            [
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce(self::NONCE_ACTION),
                'base' => $base,
                'limit' => $limit,
                'refreshMs' => $refresh * 1000,
                'isLoggedIn' => is_user_logged_in(),
                'loginUrl' => wp_login_url(get_permalink()),
            ]
        );
    }

    private function verify_nonce(): void
    {
        $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';

        if (!wp_verify_nonce($nonce, self::NONCE_ACTION)) {
            wp_send_json_error(['message' => 'Sesión no válida. Recarga la página.'], 403);
        }
    }

    private function find_flights(string $base, string $query, int $limit): array
    {
        global $wpdb;

        $table = $this->live_table();
        $where = ['base_iata = %s'];
        $params = [$base];

        if ($query !== '') {
            $like = '%' . $wpdb->esc_like($query) . '%';
            $where[] = '(registration LIKE %s OR numero_vuelo LIKE %s OR aerolinea LIKE %s)';
            array_push($params, $like, $like, $like);
        }

        $params[] = $limit;
        $sql = $wpdb->prepare(
            "SELECT *
             FROM {$table}
             WHERE " . implode(' AND ', $where) . "
             ORDER BY COALESCE(hora_real, hora_programada), hora_programada, id
             LIMIT %d",
            $params
        );

        $rows = $wpdb->get_results($sql, ARRAY_A);

        if (!is_array($rows)) {
            return [];
        }

        return array_map([$this, 'format_flight'], $rows);
    }

    private function get_flight(int $flight_id): ?array
    {
        if (!$flight_id) {
            return null;
        }

        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$this->live_table()} WHERE id = %d", $flight_id),
            ARRAY_A
        );

        return is_array($row) ? $row : null;
    }

    private function find_related_flights(array $flight): array
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

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT *
                 FROM {$this->live_table()}
                 WHERE base_iata = %s
                   AND direction = %s
                   AND registration = %s
                   AND COALESCE(hora_real, hora_programada) {$operator} %s
                 ORDER BY COALESCE(hora_real, hora_programada) {$order}, hora_programada {$order}, id {$order}
                 LIMIT 20",
                $flight['base_iata'],
                $opposite,
                $registration,
                $reference_time
            ),
            ARRAY_A
        ) ?: [];
    }

    private function get_saved_flights(): array
    {
        global $wpdb;

        $saved = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT *
                 FROM {$this->saved_table()}
                 WHERE user_id = %d
                 ORDER BY created_at DESC
                 LIMIT 200",
                get_current_user_id()
            ),
            ARRAY_A
        );

        if (!is_array($saved)) {
            return [];
        }

        $items = [];

        foreach ($saved as $row) {
            $primary = $this->get_flight((int) $row['primary_flight_id']);
            $related = !empty($row['related_flight_id']) ? $this->get_flight((int) $row['related_flight_id']) : null;

            if (!$primary) {
                continue;
            }

            $items[] = [
                'id' => (int) $row['id'],
                'createdAt' => $this->format_datetime($row['created_at']),
                'primary' => $this->format_flight($primary),
                'related' => $related ? $this->format_flight($related) : null,
            ];
        }

        return $items;
    }

    private function format_flight(array $row): array
    {
        $direction = (string) ($row['direction'] ?? '');

        return [
            'id' => (int) $row['id'],
            'baseIata' => (string) ($row['base_iata'] ?? ''),
            'direction' => $direction,
            'directionLabel' => $direction === 'arrival' ? 'Llegada' : 'Salida',
            'flightNumber' => (string) ($row['numero_vuelo'] ?? ''),
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

        $treat_as_utc = (bool) apply_filters('flights_tracker_times_are_utc', true);

        if (!$treat_as_utc) {
            $timestamp = strtotime((string) $value);
        }

        return wp_date('d/m/Y H:i', $timestamp);
    }

    private function best_time_for_query(array $flight): string
    {
        $real = trim((string) ($flight['hora_real'] ?? ''));
        $scheduled = trim((string) ($flight['hora_programada'] ?? ''));

        return $real !== '' ? $real : $scheduled;
    }

    private function live_table(): string
    {
        global $wpdb;

        $table = apply_filters('flights_tracker_live_table', self::LIVE_TABLE);

        return preg_replace('/[^A-Za-z0-9_]/', '', (string) $table) ?: self::LIVE_TABLE;
    }

    private function saved_table(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'flights_tracker_saved';
    }
}

Flights_Tracker_Plugin::init();