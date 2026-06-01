<?php

if (!defined('ABSPATH')) {
    exit;
}

final class WP_Rank_Tracker_Admin {
    private const OPTION_KEY = 'wp_rank_tracker_settings';
    private const REPORT_OPTION_KEY = 'wp_rank_tracker_gsc_report';
    private const REPORT_HISTORY_OPTION_KEY = 'wp_rank_tracker_gsc_report_history';
    private const SERP_REPORT_OPTION_KEY = 'wp_rank_tracker_serp_report';
    private const SERP_HISTORY_OPTION_KEY = 'wp_rank_tracker_serp_history';
    private const SERP_REQUEST_DEBUG_OPTION_KEY = 'wp_rank_tracker_serp_request_debug';
    private const MENU_SLUG = 'wp-rank-tracker';
    private const MENU_SLUG_LOCAL = 'wp-rank-tracker-local';
    private const MENU_SLUG_GOOGLE = 'wp-rank-tracker-google-search-console';
    private const MENU_SLUG_DATAFORSEO = 'wp-rank-tracker-dataforseo';
    private const NONCE_ACTION_SETTINGS = 'wp_rank_tracker_save_settings';
    private const NONCE_ACTION_IMPORT = 'wp_rank_tracker_import_gsc';
    private const NONCE_ACTION_IMPORT_SERP = 'wp_rank_tracker_import_serp';
    private const NONCE_ACTION_CONNECT = 'wp_rank_tracker_connect_google';
    private const NONCE_ACTION_DISCONNECT = 'wp_rank_tracker_disconnect_google';
    private const NONCE_ACTION_REFRESH_LOCAL = 'wp_rank_tracker_refresh_local';
    private const OAUTH_TRANSIENT_PREFIX = 'wp_rank_tracker_oauth_state_';

    public static function maybe_seed_defaults(): void {
        $existing = get_option(self::OPTION_KEY, null);

        if (is_array($existing)) {
            return;
        }

        add_option(self::OPTION_KEY, self::default_settings(), '', false);
    }

    public function boot(): void {
        self::maybe_seed_defaults();
        $this->maybe_register_site_with_central_service();

        add_action('admin_menu', [$this, 'register_admin_page']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('admin_post_wp_rank_tracker_save_settings', [$this, 'handle_save_settings']);
        add_action('admin_post_wp_rank_tracker_import_gsc', [$this, 'handle_import_gsc']);
        add_action('admin_post_wp_rank_tracker_import_serp', [$this, 'handle_import_serp']);
        add_action('admin_post_wp_rank_tracker_connect_google', [$this, 'handle_connect_google']);
        add_action('admin_post_wp_rank_tracker_google_oauth_callback', [$this, 'handle_google_oauth_callback']);
        add_action('admin_post_wp_rank_tracker_disconnect_google', [$this, 'handle_disconnect_google']);
        add_action('admin_post_wp_rank_tracker_refresh_local', [$this, 'handle_refresh_local']);
        add_filter('plugin_action_links_' . plugin_basename(WP_RANK_TRACKER_FILE), [$this, 'plugin_action_links']);
    }

    public function register_admin_page(): void {
        add_menu_page(
            __('WP Rank Tracker', 'wp-rank-tracker'),
            __('WP Rank Tracker', 'wp-rank-tracker'),
            'manage_options',
            self::MENU_SLUG,
            [$this, 'render_admin_page'],
            'dashicons-chart-line',
            58
        );

        add_submenu_page(
            self::MENU_SLUG,
            __('Tableau de bord', 'wp-rank-tracker'),
            __('Tableau de bord', 'wp-rank-tracker'),
            'manage_options',
            self::MENU_SLUG,
            [$this, 'render_admin_page']
        );

        add_submenu_page(
            self::MENU_SLUG,
            __('Local', 'wp-rank-tracker'),
            __('Local', 'wp-rank-tracker'),
            'manage_options',
            self::MENU_SLUG_LOCAL,
            [$this, 'render_admin_page']
        );

        add_submenu_page(
            self::MENU_SLUG,
            __('Google Search Console', 'wp-rank-tracker'),
            __('Google Search Console', 'wp-rank-tracker'),
            'manage_options',
            self::MENU_SLUG_GOOGLE,
            [$this, 'render_admin_page']
        );

        add_submenu_page(
            self::MENU_SLUG,
            __('DataForSEO', 'wp-rank-tracker'),
            __('DataForSEO', 'wp-rank-tracker'),
            'manage_options',
            self::MENU_SLUG_DATAFORSEO,
            [$this, 'render_admin_page']
        );
    }

    public function enqueue_assets(string $hook): void {
        $allowedHooks = [
            'toplevel_page_' . self::MENU_SLUG,
            self::MENU_SLUG . '_page_' . self::MENU_SLUG_LOCAL,
            self::MENU_SLUG . '_page_' . self::MENU_SLUG_GOOGLE,
            self::MENU_SLUG . '_page_' . self::MENU_SLUG_DATAFORSEO,
        ];

        if (!in_array($hook, $allowedHooks, true)) {
            return;
        }

        wp_enqueue_style(
            'wp-rank-tracker-admin',
            WP_RANK_TRACKER_URL . 'assets/css/admin.css',
            [],
            WP_RANK_TRACKER_VERSION
        );
    }

    public function handle_save_settings(): void {
        $this->assert_permissions();
        check_admin_referer(self::NONCE_ACTION_SETTINGS);

        $current = $this->get_settings();
        $settingsSection = sanitize_key((string) ($_POST['settings_section'] ?? ''));
        $postedClientSecret = sanitize_text_field(wp_unslash((string) ($_POST['google_client_secret'] ?? '')));
        $postedRefreshToken = sanitize_text_field(wp_unslash((string) ($_POST['google_refresh_token'] ?? '')));
        $postedDataForSeoPassword = sanitize_text_field(wp_unslash((string) ($_POST['dataforseo_password'] ?? '')));

        $settings = [
            'target_domain' => $this->sanitize_domain(wp_unslash((string) ($_POST['target_domain'] ?? ''))),
            'central_api_base_url' => 'https://api.mapage-wp.online',
            'central_site_token' => (string) ($current['central_site_token'] ?? ''),
            'gsc_property_uri' => sanitize_text_field(wp_unslash((string) ($_POST['gsc_property_uri'] ?? ''))),
            'google_client_id' => sanitize_text_field(wp_unslash((string) ($_POST['google_client_id'] ?? ''))),
            'google_client_secret' => $postedClientSecret !== '' ? $postedClientSecret : (string) ($current['google_client_secret'] ?? ''),
            'google_refresh_token' => $postedRefreshToken !== '' ? $postedRefreshToken : (string) ($current['google_refresh_token'] ?? ''),
            'report_days' => $this->sanitize_report_days($_POST['report_days'] ?? 28),
            'competitors' => $this->sanitize_line_list((string) ($_POST['competitors'] ?? '')),
            'tracked_keywords' => $this->sanitize_line_list((string) ($_POST['tracked_keywords'] ?? '')),
            'dataforseo_login' => sanitize_text_field(wp_unslash((string) ($_POST['dataforseo_login'] ?? ''))),
            'dataforseo_password' => $postedDataForSeoPassword !== '' ? $postedDataForSeoPassword : (string) ($current['dataforseo_password'] ?? ''),
            'dataforseo_location_name' => sanitize_text_field(wp_unslash((string) ($_POST['dataforseo_location_name'] ?? 'United States'))),
            'dataforseo_language_name' => sanitize_text_field(wp_unslash((string) ($_POST['dataforseo_language_name'] ?? 'English'))),
            'dataforseo_depth' => $this->sanitize_serp_depth($_POST['dataforseo_depth'] ?? 20),
        ];

        update_option(self::OPTION_KEY, $settings, false);

        $propertyUri = (string) ($settings['gsc_property_uri'] ?? '');
        if ($settingsSection === 'google' && $propertyUri !== '') {
            $centralStatus = $this->get_central_google_status($settings);
            if (!empty($centralStatus['connected'])) {
                $importResult = $this->run_google_import($settings);
                if (is_wp_error($importResult)) {
                    $this->redirect_with_notice('import-error', $importResult->get_error_message());
                }

                $this->store_google_report($importResult);
                $this->redirect_with_notice('settings-and-import-success');
            }
        }

        if ($settingsSection === 'dataforseo' && is_array($settings['tracked_keywords']) && $settings['tracked_keywords'] !== []) {
            $serpReport = $this->run_serp_import($settings);
            if (is_wp_error($serpReport)) {
                $this->redirect_with_notice('serp-error', $serpReport->get_error_message());
            }

            $this->store_serp_report($serpReport);
            $this->redirect_with_notice('settings-and-serp-success');
        }

        $this->redirect_with_notice('settings-saved');
    }

    public function handle_import_gsc(): void {
        $this->assert_permissions();
        check_admin_referer(self::NONCE_ACTION_IMPORT);

        $settings = $this->get_settings();
        $report = $this->run_google_import($settings);

        if (is_wp_error($report)) {
            $this->redirect_with_notice('import-error', $report->get_error_message());
        }

        $this->store_google_report($report);
        $this->redirect_with_notice('import-success');
    }

    public function handle_scheduled_google_refresh(): void {
        $settings = $this->get_settings();
        $propertyUri = (string) ($settings['gsc_property_uri'] ?? '');

        if ($propertyUri === '') {
            return;
        }

        $centralStatus = $this->get_central_google_status($settings);
        if (empty($centralStatus['connected']) && (string) ($settings['google_refresh_token'] ?? '') === '') {
            return;
        }

        $report = $this->run_google_import($settings);
        if (is_wp_error($report)) {
            return;
        }

        $this->store_google_report($report);
    }

    public function handle_import_serp(): void {
        $this->assert_permissions();
        check_admin_referer(self::NONCE_ACTION_IMPORT_SERP);

        $settings = $this->get_settings();
        $report = $this->run_serp_import($settings);

        if (is_wp_error($report)) {
            $this->redirect_with_notice('serp-error', $report->get_error_message());
        }

        $this->store_serp_report($report);
        $this->redirect_with_notice('serp-success');
    }

    public function handle_scheduled_serp_refresh(): void {
        $settings = $this->get_settings();
        $keywords = is_array($settings['tracked_keywords'] ?? null) ? $settings['tracked_keywords'] : [];

        if ($keywords === []) {
            return;
        }

        $report = $this->run_serp_import($settings);
        if (is_wp_error($report)) {
            return;
        }

        $this->store_serp_report($report);
    }

    public function render_admin_page(): void {
        if (!current_user_can('manage_options')) {
            return;
        }

        $settings = $this->get_settings();
        $report = $this->get_report();
        $previousReport = $this->get_previous_google_report();
        $serpReport = $this->get_serp_report();
        $serpPreviousReport = $this->get_previous_serp_report();
        $localAudit = $this->build_local_audit();
        $summary = $this->build_summary($report);
        $pageRows = $this->group_rows_by_page($report['rows']);
        $previousPageRows = $this->group_rows_by_page($previousReport['rows']);
        $comparisonRows = $this->build_comparison_rows($localAudit['pages'], $pageRows);
        $googleTrendRows = $this->build_google_trend_rows($pageRows, $previousPageRows);
        $googleQueryPodium = $this->build_google_query_podium($report['rows']);
        $pagesNearTopTen = $this->build_pages_near_top_ten_rows($pageRows);
        $lowCtrPages = $this->build_low_ctr_rows($pageRows);
        $decliningPages = $this->build_declining_pages_rows($googleTrendRows);
        $emergingQueries = $this->build_emerging_queries_rows($report['rows'], $previousReport['rows']);
        $priorityOpportunities = $this->build_priority_opportunities($settings, $localAudit['pages'], $pageRows, $comparisonRows, $serpReport);
        $marketRows = $this->build_market_watch_rows($settings, $localAudit['pages'], $report['rows']);
        $serpComparisonRows = $this->build_serp_comparison_rows($settings, $serpReport, $serpPreviousReport);
        $centralStatus = $this->get_central_google_status($settings);
        $centralSerpStatus = $this->get_central_serp_status($settings);
        $serpRequestDebug = $this->get_serp_request_debug();
        $isConnected = $centralStatus['connected'];
        $isCentralRegistered = !empty($settings['central_site_token']);
        $googleProperties = $this->get_google_properties($settings, $isConnected);
        $selectedProperty = $this->resolve_selected_property($settings, $centralStatus, $googleProperties);
        $currentSection = $this->get_current_section();
        $setupSteps = $this->build_setup_steps($settings, $localAudit, $isConnected, $selectedProperty, $serpReport);
        $dashboardMetrics = $this->build_dashboard_metrics($localAudit, $summary, $priorityOpportunities, $googleTrendRows, $serpComparisonRows);
        $dashboardAlerts = $this->build_dashboard_alerts($settings, $isConnected, $selectedProperty, $priorityOpportunities, $googleTrendRows, $serpComparisonRows);
        $quickActions = $this->build_quick_actions($settings, $priorityOpportunities);
        $googleClickChartRows = $this->build_google_click_chart_rows($pageRows);
        $serpVisibilityChartRows = $this->build_serp_visibility_chart_rows($settings, $serpReport);
        $dailySummary = $this->build_daily_summary($priorityOpportunities, $googleTrendRows, $serpComparisonRows);
        $nextActions = $this->build_next_actions($settings, $isConnected, $selectedProperty, $priorityOpportunities);
        $alertPages = $this->build_alert_pages($googleTrendRows);
        $quickWins = $this->build_quick_wins($priorityOpportunities);
        ?>
        <div class="wrap wrt-admin">
            <h1><?php esc_html_e('WP Rank Tracker', 'wp-rank-tracker'); ?></h1>
            <p class="wrt-intro"><?php esc_html_e('Le plugin commence par un bilan local de tes pages, puis te propose de connecter Google Search Console pour voir ce que Google observe reellement.', 'wp-rank-tracker'); ?></p>

            <?php $this->render_notice(); ?>

            <div class="wrt-hero">
                <div>
                    <p class="wrt-eyebrow"><?php esc_html_e('Lecture actuelle', 'wp-rank-tracker'); ?></p>
                    <div class="wrt-score"><?php echo esc_html((string) $localAudit['page_count']); ?><span><?php esc_html_e('pages auditees', 'wp-rank-tracker'); ?></span></div>
                    <p class="wrt-domain"><?php echo esc_html($settings['target_domain']); ?></p>
                </div>
                <div class="wrt-summary">
                    <p><?php esc_html_e('Etape 1 : estimation locale des mots-cles cibles par page. Etape 2 : connexion Google Search Console pour comparer avec les requetes et positions vues par Google.', 'wp-rank-tracker'); ?></p>
                    <div class="wrt-badges">
                        <span><?php printf(esc_html__('%d page(s) locales', 'wp-rank-tracker'), (int) $localAudit['page_count']); ?></span>
                        <span><?php printf(esc_html__('%d page(s) Google', 'wp-rank-tracker'), (int) $summary['page_count']); ?></span>
                        <span><?php echo esc_html($isConnected ? __('Google connecte', 'wp-rank-tracker') : __('Google non connecte', 'wp-rank-tracker')); ?></span>
                        <span><?php echo esc_html($isCentralRegistered ? __('Service central actif', 'wp-rank-tracker') : __('Service central en attente', 'wp-rank-tracker')); ?></span>
                        <span><?php echo esc_html($summary['last_fetch_label']); ?></span>
                    </div>
                </div>
            </div>

            <?php if ($currentSection === 'dashboard') : ?>
            <section class="wrt-card wrt-dashboard">
                <div class="wrt-section-head">
                    <h2><?php esc_html_e('Tableau de bord actionnable', 'wp-rank-tracker'); ?></h2>
                    <div class="wrt-quick-actions">
                        <?php foreach ($quickActions as $action) : ?>
                            <a class="button <?php echo esc_attr($action['class']); ?>" href="<?php echo esc_url($action['url']); ?>">
                                <?php echo esc_html($action['label']); ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="wrt-dashboard-grid">
                    <?php foreach ($dashboardMetrics as $metric) : ?>
                        <article class="wrt-metric-card">
                            <span class="wrt-metric-label"><?php echo esc_html($metric['label']); ?></span>
                            <strong class="wrt-metric-value"><?php echo esc_html($metric['value']); ?></strong>
                            <span class="wrt-metric-copy"><?php echo esc_html($metric['copy']); ?></span>
                        </article>
                    <?php endforeach; ?>
                </div>
                <div class="wrt-dashboard-panels">
                    <section class="wrt-panel">
                        <h3><?php esc_html_e('Resume du jour', 'wp-rank-tracker'); ?></h3>
                        <div class="wrt-summary-list">
                            <?php foreach ($dailySummary as $item) : ?>
                                <article class="wrt-summary-item">
                                    <strong><?php echo esc_html($item['value']); ?></strong>
                                    <span><?php echo esc_html($item['label']); ?></span>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    </section>
                    <section class="wrt-panel">
                        <h3><?php esc_html_e('A faire maintenant', 'wp-rank-tracker'); ?></h3>
                        <?php if ($nextActions === []) : ?>
                            <p class="wrt-empty-copy"><?php esc_html_e('Aucune action urgente pour le moment.', 'wp-rank-tracker'); ?></p>
                        <?php else : ?>
                            <div class="wrt-todo-list">
                                <?php foreach ($nextActions as $action) : ?>
                                    <article class="wrt-todo-item">
                                        <div>
                                            <strong><?php echo esc_html($action['title']); ?></strong>
                                            <p><?php echo esc_html($action['copy']); ?></p>
                                        </div>
                                        <a class="button button-primary" href="<?php echo esc_url($action['url']); ?>">
                                            <?php echo esc_html($action['label']); ?>
                                        </a>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </section>
                </div>
                <div class="wrt-dashboard-panels">
                    <section class="wrt-panel">
                        <h3><?php esc_html_e('Parcours conseille', 'wp-rank-tracker'); ?></h3>
                        <ol class="wrt-step-list">
                            <?php foreach ($setupSteps as $step) : ?>
                                <li class="wrt-step wrt-step-<?php echo esc_attr($step['status']); ?>">
                                    <span class="wrt-step-badge"><?php echo esc_html($step['badge']); ?></span>
                                    <div>
                                        <strong><?php echo esc_html($step['title']); ?></strong>
                                        <p><?php echo esc_html($step['copy']); ?></p>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ol>
                    </section>
                    <section class="wrt-panel">
                        <h3><?php esc_html_e('Alertes du moment', 'wp-rank-tracker'); ?></h3>
                        <?php if ($dashboardAlerts === []) : ?>
                            <p class="wrt-empty-copy"><?php esc_html_e('Aucune alerte bloquante pour le moment.', 'wp-rank-tracker'); ?></p>
                        <?php else : ?>
                            <div class="wrt-alert-list">
                                <?php foreach ($dashboardAlerts as $alert) : ?>
                                    <article class="wrt-alert wrt-alert-<?php echo esc_attr($alert['priority']); ?>">
                                        <strong><?php echo esc_html($alert['title']); ?></strong>
                                        <p><?php echo esc_html($alert['copy']); ?></p>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </section>
                </div>
                <div class="wrt-dashboard-panels">
                    <section class="wrt-panel">
                        <h3><?php esc_html_e('Pages en alerte', 'wp-rank-tracker'); ?></h3>
                        <?php if ($alertPages === []) : ?>
                            <p class="wrt-empty-copy"><?php esc_html_e('Aucune page en baisse notable pour le moment.', 'wp-rank-tracker'); ?></p>
                        <?php else : ?>
                            <div class="wrt-alert-page-list">
                                <?php foreach ($alertPages as $page) : ?>
                                    <article class="wrt-alert-page">
                                        <div>
                                            <strong><?php echo esc_html($page['title']); ?></strong>
                                            <p><?php echo wp_kses_post($page['trend']); ?></p>
                                        </div>
                                        <?php if ($page['actions'] !== []) : ?>
                                            <div class="wrt-row-actions">
                                                <?php foreach ($page['actions'] as $action) : ?>
                                                    <a class="button button-small" href="<?php echo esc_url($action['url']); ?>">
                                                        <?php echo esc_html($action['label']); ?>
                                                    </a>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </section>
                    <section class="wrt-panel">
                        <h3><?php esc_html_e('Opportunites rapides', 'wp-rank-tracker'); ?></h3>
                        <?php if ($quickWins === []) : ?>
                            <p class="wrt-empty-copy"><?php esc_html_e('Aucune opportunite rapide n a encore ete detectee.', 'wp-rank-tracker'); ?></p>
                        <?php else : ?>
                            <div class="wrt-quick-win-list">
                                <?php foreach ($quickWins as $item) : ?>
                                    <article class="wrt-quick-win">
                                        <span class="wrt-recommendation-priority wrt-priority-<?php echo esc_attr($item['priority']); ?>">
                                            <?php echo esc_html($item['impact_label']); ?>
                                        </span>
                                        <strong><?php echo esc_html($item['title']); ?></strong>
                                        <p><?php echo esc_html($item['copy']); ?></p>
                                        <?php if ($item['actions'] !== []) : ?>
                                            <div class="wrt-row-actions">
                                                <?php foreach ($item['actions'] as $action) : ?>
                                                    <a class="button button-small" href="<?php echo esc_url($action['url']); ?>">
                                                        <?php echo esc_html($action['label']); ?>
                                                    </a>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </section>
                </div>
                <div class="wrt-dashboard-panels">
                    <section class="wrt-panel">
                        <h3><?php esc_html_e('Pages qui apportent le plus de clics', 'wp-rank-tracker'); ?></h3>
                        <?php if ($googleClickChartRows === []) : ?>
                            <p class="wrt-empty-copy"><?php esc_html_e('Connecte Google pour afficher ce graphique.', 'wp-rank-tracker'); ?></p>
                        <?php else : ?>
                            <div class="wrt-chart-list">
                                <?php foreach ($googleClickChartRows as $row) : ?>
                                    <div class="wrt-chart-row">
                                        <div class="wrt-chart-head">
                                            <span><?php echo esc_html($row['label']); ?></span>
                                            <strong><?php echo esc_html($row['value_label']); ?></strong>
                                        </div>
                                        <div class="wrt-chart-bar"><span style="width: <?php echo esc_attr((string) $row['width']); ?>%"></span></div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </section>
                    <section class="wrt-panel">
                        <h3><?php esc_html_e('Positions de tes mots-cles suivis', 'wp-rank-tracker'); ?></h3>
                        <?php if ($serpVisibilityChartRows === []) : ?>
                            <p class="wrt-empty-copy"><?php esc_html_e('Ajoute des mots-cles et lance DataForSEO pour visualiser leur position actuelle.', 'wp-rank-tracker'); ?></p>
                        <?php else : ?>
                            <div class="wrt-chart-list">
                                <?php foreach ($serpVisibilityChartRows as $row) : ?>
                                    <div class="wrt-chart-row">
                                        <div class="wrt-chart-head">
                                            <span><?php echo esc_html($row['label']); ?></span>
                                            <strong><?php echo esc_html($row['value_label']); ?></strong>
                                        </div>
                                        <div class="wrt-chart-bar wrt-chart-bar-rank"><span style="width: <?php echo esc_attr((string) $row['width']); ?>%"></span></div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </section>
                </div>
            </section>
            <?php endif; ?>

            <?php if ($currentSection === 'local') : ?>
            <section class="wrt-card wrt-local-overview">
                <h2><?php esc_html_e('Lecture locale de tes pages', 'wp-rank-tracker'); ?></h2>
                <p><?php esc_html_e('Le plugin commence par analyser tes pages telles qu elles sont construites dans WordPress. Il repere pour chaque page le sujet principal qu elle semble cibler, avant de comparer ensuite cette lecture avec les donnees reelles de Google.', 'wp-rank-tracker'); ?></p>
                <div class="wrt-local-stats">
                    <div><strong><?php echo esc_html((string) $localAudit['page_count']); ?></strong><span><?php esc_html_e('pages analysees localement', 'wp-rank-tracker'); ?></span></div>
                    <div><strong><?php echo esc_html((string) $localAudit['with_focus_count']); ?></strong><span><?php esc_html_e('pages avec mot-cle principal detecte', 'wp-rank-tracker'); ?></span></div>
                </div>
            </section>

            <section class="wrt-card wrt-table-card">
                <div class="wrt-section-head">
                    <h2><?php esc_html_e('Mots-cles potentiels par page', 'wp-rank-tracker'); ?></h2>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="wp_rank_tracker_refresh_local" />
                        <?php wp_nonce_field(self::NONCE_ACTION_REFRESH_LOCAL); ?>
                        <?php submit_button(__('Mettre a jour', 'wp-rank-tracker'), 'secondary', 'submit', false); ?>
                    </form>
                </div>
                <?php if ($localAudit['pages'] === []) : ?>
                    <p><?php esc_html_e('Aucune page publiee analysee.', 'wp-rank-tracker'); ?></p>
                <?php else : ?>
                    <table class="widefat striped">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('Page', 'wp-rank-tracker'); ?></th>
                                <th><?php esc_html_e('Mot-cle probable', 'wp-rank-tracker'); ?></th>
                                <th><?php esc_html_e('Mots secondaires', 'wp-rank-tracker'); ?></th>
                                <th><?php esc_html_e('Ameliorations', 'wp-rank-tracker'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($localAudit['pages'] as $pageAudit) : ?>
                                <tr>
                                    <td>
                                        <strong><?php echo esc_html($pageAudit['title']); ?></strong><br />
                                        <span class="description"><?php echo esc_html($pageAudit['type_label'] . ' - ' . $pageAudit['url']); ?></span>
                                        <div class="wrt-row-actions">
                                            <?php foreach ($pageAudit['actions'] as $action) : ?>
                                                <a class="button button-small" href="<?php echo esc_url($action['url']); ?>">
                                                    <?php echo esc_html($action['label']); ?>
                                                </a>
                                            <?php endforeach; ?>
                                        </div>
                                    </td>
                                    <td><?php echo esc_html($pageAudit['primary_keyword']); ?></td>
                                    <td><?php echo esc_html(implode(', ', $pageAudit['secondary_keywords'])); ?></td>
                                    <td>
                                        <ul class="wrt-recommendations">
                                            <?php foreach ($pageAudit['recommendations'] as $recommendation) : ?>
                                                <li>
                                                    <span class="wrt-recommendation-priority wrt-priority-<?php echo esc_attr($recommendation['priority']); ?>">
                                                        <?php echo esc_html($recommendation['priority_label']); ?>
                                                    </span>
                                                    <strong><?php echo esc_html($recommendation['issue']); ?></strong>
                                                    <span class="wrt-recommendation-meta"><?php echo esc_html($recommendation['area_label']); ?></span>
                                                    <span class="wrt-recommendation-why"><?php echo esc_html($recommendation['why']); ?></span>
                                                    <span class="wrt-recommendation-action"><?php echo esc_html($recommendation['action']); ?></span>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
                </section>
            <?php endif; ?>

            <?php if ($currentSection === 'google') : ?>
            <section class="wrt-card wrt-table-card">
                <h2><?php esc_html_e('Google Search Console', 'wp-rank-tracker'); ?></h2>
                <p><?php esc_html_e('Connecte Google, choisis la propriete Search Console a analyser, puis enregistre. L import se lance automatiquement et te permettra de comparer ta lecture locale avec la vision reelle de Google.', 'wp-rank-tracker'); ?></p>
                <div class="wrt-local-stats">
                    <div><strong><?php echo esc_html($isConnected ? __('Oui', 'wp-rank-tracker') : __('Non', 'wp-rank-tracker')); ?></strong><span><?php esc_html_e('Google connecte', 'wp-rank-tracker'); ?></span></div>
                    <div><strong><?php echo esc_html($selectedProperty !== '' ? $selectedProperty : __('Aucune', 'wp-rank-tracker')); ?></strong><span><?php esc_html_e('propriete actuelle', 'wp-rank-tracker'); ?></span></div>
                    <div><strong><?php echo esc_html((string) count($googleProperties)); ?></strong><span><?php esc_html_e('proprietes detectees', 'wp-rank-tracker'); ?></span></div>
                    <div><strong><?php echo esc_html($previousReport['fetched_at'] !== '' ? __('Oui', 'wp-rank-tracker') : __('Non', 'wp-rank-tracker')); ?></strong><span><?php esc_html_e('historique disponible', 'wp-rank-tracker'); ?></span></div>
                </div>
                <div class="wrt-inline-actions">
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="wp_rank_tracker_connect_google" />
                        <?php wp_nonce_field(self::NONCE_ACTION_CONNECT); ?>
                        <?php submit_button($isConnected ? __('Reconnecter Google', 'wp-rank-tracker') : __('Connecter Google', 'wp-rank-tracker'), 'secondary', 'submit', false); ?>
                    </form>
                    <?php if ($isConnected) : ?>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                            <input type="hidden" name="action" value="wp_rank_tracker_disconnect_google" />
                            <?php wp_nonce_field(self::NONCE_ACTION_DISCONNECT); ?>
                            <?php submit_button(__('Deconnecter Google', 'wp-rank-tracker'), 'delete', 'submit', false); ?>
                        </form>
                    <?php endif; ?>
                </div>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="wp_rank_tracker_save_settings" />
                    <?php wp_nonce_field(self::NONCE_ACTION_SETTINGS); ?>
                    <input type="hidden" name="settings_section" value="google" />
                    <input type="hidden" name="target_domain" value="<?php echo esc_attr($settings['target_domain']); ?>" />
                    <input type="hidden" name="tracked_keywords" value="<?php echo esc_attr(implode("\n", $settings['tracked_keywords'])); ?>" />
                    <input type="hidden" name="competitors" value="<?php echo esc_attr(implode("\n", $settings['competitors'])); ?>" />
                    <table class="form-table" role="presentation">
                        <tbody>
                            <tr>
                                <th scope="row"><label for="wrt-gsc-property"><?php esc_html_e('Propriete Search Console', 'wp-rank-tracker'); ?></label></th>
                                <td>
                                    <?php if ($googleProperties !== []) : ?>
                                        <select id="wrt-gsc-property" name="gsc_property_uri" class="regular-text">
                                            <option value=""><?php esc_html_e('Choisir une propriete', 'wp-rank-tracker'); ?></option>
                                            <?php foreach ($googleProperties as $property) : ?>
                                                <option value="<?php echo esc_attr($property['site_url']); ?>" <?php selected($selectedProperty, $property['site_url']); ?>>
                                                    <?php echo esc_html($property['site_url']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <p class="description"><?php esc_html_e('Liste recuperee automatiquement depuis le compte Google connecte.', 'wp-rank-tracker'); ?></p>
                                    <?php else : ?>
                                        <input id="wrt-gsc-property" type="text" class="regular-text" name="gsc_property_uri" value="<?php echo esc_attr($selectedProperty); ?>" placeholder="sc-domain:example.com" />
                                        <p class="description"><?php echo esc_html($isConnected ? __('Aucune propriete n a pu etre recuperee automatiquement. Tu peux la saisir manuellement.', 'wp-rank-tracker') : __('Connecte Google pour recuperer automatiquement les proprietes Search Console, ou saisis-la manuellement.', 'wp-rank-tracker')); ?></p>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="wrt-report-days"><?php esc_html_e('Periode d import', 'wp-rank-tracker'); ?></label></th>
                                <td>
                                    <input id="wrt-report-days" type="number" min="1" max="90" name="report_days" value="<?php echo esc_attr((string) $settings['report_days']); ?>" />
                                    <p class="description"><?php esc_html_e('Nombre de jours a importer depuis Search Console.', 'wp-rank-tracker'); ?></p>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <?php submit_button(__('Enregistrer la propriete Google', 'wp-rank-tracker')); ?>
                </form>
            </section>

            <section class="wrt-card wrt-table-card">
                <h2><?php esc_html_e('Podium Google actuel', 'wp-rank-tracker'); ?></h2>
                <?php if ($googleQueryPodium === []) : ?>
                    <p><?php esc_html_e('Aucune requete disponible pour le moment.', 'wp-rank-tracker'); ?></p>
                <?php else : ?>
                    <table class="widefat striped">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('Rang', 'wp-rank-tracker'); ?></th>
                                <th><?php esc_html_e('Requete', 'wp-rank-tracker'); ?></th>
                                <th><?php esc_html_e('Page', 'wp-rank-tracker'); ?></th>
                                <th><?php esc_html_e('Clics', 'wp-rank-tracker'); ?></th>
                                <th><?php esc_html_e('Position moy.', 'wp-rank-tracker'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($googleQueryPodium as $podiumRow) : ?>
                                <tr>
                                    <td><strong>#<?php echo esc_html((string) $podiumRow['rank']); ?></strong></td>
                                    <td><?php echo esc_html($podiumRow['query']); ?></td>
                                    <td><?php echo esc_html($podiumRow['page']); ?></td>
                                    <td><?php echo esc_html((string) $podiumRow['clicks']); ?></td>
                                    <td><?php echo esc_html($this->format_position((float) $podiumRow['position'])); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </section>

            <section class="wrt-card wrt-table-card">
                <h2><?php esc_html_e('Pages proches du top 10', 'wp-rank-tracker'); ?></h2>
                <?php if ($pagesNearTopTen === []) : ?>
                    <p><?php esc_html_e('Aucune page n est actuellement dans cette zone de progression rapide.', 'wp-rank-tracker'); ?></p>
                <?php else : ?>
                    <table class="widefat striped">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('Page', 'wp-rank-tracker'); ?></th>
                                <th><?php esc_html_e('Requete principale', 'wp-rank-tracker'); ?></th>
                                <th><?php esc_html_e('Impressions', 'wp-rank-tracker'); ?></th>
                                <th><?php esc_html_e('Position moy.', 'wp-rank-tracker'); ?></th>
                                <th><?php esc_html_e('Action conseillee', 'wp-rank-tracker'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pagesNearTopTen as $row) : ?>
                                <tr>
                                    <td><strong><?php echo esc_html($row['page']); ?></strong></td>
                                    <td><?php echo esc_html($row['top_query']); ?></td>
                                    <td><?php echo esc_html((string) $row['impressions']); ?></td>
                                    <td><?php echo esc_html($this->format_position((float) $row['position'])); ?></td>
                                    <td><?php echo esc_html($row['action']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </section>

            <section class="wrt-card wrt-table-card">
                <h2><?php esc_html_e('Pages avec CTR faible', 'wp-rank-tracker'); ?></h2>
                <?php if ($lowCtrPages === []) : ?>
                    <p><?php esc_html_e('Aucune page prioritaire avec CTR faible pour le moment.', 'wp-rank-tracker'); ?></p>
                <?php else : ?>
                    <table class="widefat striped">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('Page', 'wp-rank-tracker'); ?></th>
                                <th><?php esc_html_e('Requete principale', 'wp-rank-tracker'); ?></th>
                                <th><?php esc_html_e('Impressions', 'wp-rank-tracker'); ?></th>
                                <th>CTR</th>
                                <th><?php esc_html_e('Action conseillee', 'wp-rank-tracker'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($lowCtrPages as $row) : ?>
                                <tr>
                                    <td><strong><?php echo esc_html($row['page']); ?></strong></td>
                                    <td><?php echo esc_html($row['top_query']); ?></td>
                                    <td><?php echo esc_html((string) $row['impressions']); ?></td>
                                    <td><?php echo esc_html($this->format_ctr((float) $row['ctr'])); ?></td>
                                    <td><?php echo esc_html($row['action']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </section>

            <section class="wrt-card wrt-table-card">
                <h2><?php esc_html_e('Pages en baisse', 'wp-rank-tracker'); ?></h2>
                <?php if ($decliningPages === []) : ?>
                    <p><?php esc_html_e('Aucune baisse recente marquee n a ete detectee.', 'wp-rank-tracker'); ?></p>
                <?php else : ?>
                    <table class="widefat striped">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('Page', 'wp-rank-tracker'); ?></th>
                                <th><?php esc_html_e('Tendance', 'wp-rank-tracker'); ?></th>
                                <th><?php esc_html_e('Requete principale', 'wp-rank-tracker'); ?></th>
                                <th><?php esc_html_e('Action conseillee', 'wp-rank-tracker'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($decliningPages as $row) : ?>
                                <tr>
                                    <td><strong><?php echo esc_html($row['page']); ?></strong></td>
                                    <td><?php echo wp_kses_post($row['trend']); ?></td>
                                    <td><?php echo esc_html($row['top_query']); ?></td>
                                    <td><?php echo esc_html($row['action']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </section>

            <section class="wrt-card wrt-table-card">
                <h2><?php esc_html_e('Requetes emergentes', 'wp-rank-tracker'); ?></h2>
                <?php if ($emergingQueries === []) : ?>
                    <p><?php esc_html_e('Aucune requete emergente evidente pour le moment.', 'wp-rank-tracker'); ?></p>
                <?php else : ?>
                    <table class="widefat striped">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('Requete', 'wp-rank-tracker'); ?></th>
                                <th><?php esc_html_e('Page', 'wp-rank-tracker'); ?></th>
                                <th><?php esc_html_e('Impressions', 'wp-rank-tracker'); ?></th>
                                <th><?php esc_html_e('Position moy.', 'wp-rank-tracker'); ?></th>
                                <th><?php esc_html_e('Action conseillee', 'wp-rank-tracker'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($emergingQueries as $row) : ?>
                                <tr>
                                    <td><strong><?php echo esc_html($row['query']); ?></strong></td>
                                    <td><?php echo esc_html($row['page']); ?></td>
                                    <td><?php echo esc_html((string) $row['impressions']); ?></td>
                                    <td><?php echo esc_html($this->format_position((float) $row['position'])); ?></td>
                                    <td><?php echo esc_html($row['action']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </section>

            <section class="wrt-card wrt-table-card">
                <h2><?php esc_html_e('Opportunites SEO prioritaires', 'wp-rank-tracker'); ?></h2>
                <p><?php esc_html_e('Cette liste transforme les donnees locales, Google et concurrentielles en actions concretes a faire dans WordPress, dans l ordre le plus utile.', 'wp-rank-tracker'); ?></p>
                <?php if ($priorityOpportunities === []) : ?>
                    <p><?php esc_html_e('Aucune opportunite prioritaire n a encore ete detectee. Continue a importer Google et les SERP pour enrichir les actions.', 'wp-rank-tracker'); ?></p>
                <?php else : ?>
                    <div class="wrt-opportunities">
                        <?php foreach ($priorityOpportunities as $opportunity) : ?>
                            <article class="wrt-opportunity">
                                <div class="wrt-opportunity-head">
                                    <span class="wrt-recommendation-priority wrt-priority-<?php echo esc_attr($opportunity['priority']); ?>">
                                        <?php echo esc_html($opportunity['priority_label']); ?>
                                    </span>
                                    <strong><?php echo esc_html($opportunity['title']); ?></strong>
                                </div>
                                <p class="wrt-opportunity-page"><?php echo esc_html($opportunity['page']); ?></p>
                                <p class="wrt-opportunity-why"><?php echo esc_html($opportunity['why']); ?></p>
                                <p class="wrt-opportunity-action"><?php echo esc_html($opportunity['action']); ?></p>
                                <p class="wrt-opportunity-impact"><?php echo esc_html($opportunity['impact']); ?></p>
                                <?php if ($opportunity['actions'] !== []) : ?>
                                    <div class="wrt-row-actions">
                                        <?php foreach ($opportunity['actions'] as $action) : ?>
                                            <a class="button button-small" href="<?php echo esc_url($action['url']); ?>">
                                                <?php echo esc_html($action['label']); ?>
                                            </a>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>

            <section class="wrt-card wrt-table-card">
                <h2><?php esc_html_e('Bilan par page', 'wp-rank-tracker'); ?></h2>
                <?php if ($googleTrendRows === []) : ?>
                    <p><?php esc_html_e('Aucune donnee importee pour le moment.', 'wp-rank-tracker'); ?></p>
                <?php else : ?>
                    <table class="widefat striped">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('Page', 'wp-rank-tracker'); ?></th>
                                <th><?php esc_html_e('Clics', 'wp-rank-tracker'); ?></th>
                                <th><?php esc_html_e('Impressions', 'wp-rank-tracker'); ?></th>
                                <th>CTR</th>
                                <th><?php esc_html_e('Position moy.', 'wp-rank-tracker'); ?></th>
                                <th><?php esc_html_e('Tendance', 'wp-rank-tracker'); ?></th>
                                <th><?php esc_html_e('Requete principale', 'wp-rank-tracker'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($googleTrendRows as $pageRow) : ?>
                                <tr>
                                    <td><strong><?php echo esc_html($pageRow['page']); ?></strong></td>
                                    <td><?php echo esc_html((string) $pageRow['clicks']); ?></td>
                                    <td><?php echo esc_html((string) $pageRow['impressions']); ?></td>
                                    <td><?php echo esc_html($this->format_ctr($pageRow['ctr'])); ?></td>
                                    <td><?php echo esc_html($this->format_position($pageRow['position'])); ?></td>
                                    <td><?php echo wp_kses_post($pageRow['trend']); ?></td>
                                    <td><?php echo esc_html($pageRow['top_query']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </section>

            <section class="wrt-card wrt-table-card">
                <h2><?php esc_html_e('Comparaison local vs Google', 'wp-rank-tracker'); ?></h2>
                <?php if ($comparisonRows === []) : ?>
                    <p><?php esc_html_e('La comparaison sera disponible apres un import Search Console et un audit local avec pages publiees.', 'wp-rank-tracker'); ?></p>
                <?php else : ?>
                    <table class="widefat striped">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('Page', 'wp-rank-tracker'); ?></th>
                                <th><?php esc_html_e('Focus local', 'wp-rank-tracker'); ?></th>
                                <th><?php esc_html_e('Requete Google principale', 'wp-rank-tracker'); ?></th>
                                <th><?php esc_html_e('Lecture', 'wp-rank-tracker'); ?></th>
                                <th><?php esc_html_e('Position moy.', 'wp-rank-tracker'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($comparisonRows as $row) : ?>
                                <tr>
                                    <td><strong><?php echo esc_html($row['title']); ?></strong><br /><span class="description"><?php echo esc_html($row['url']); ?></span></td>
                                    <td><?php echo esc_html($row['local_keyword']); ?></td>
                                    <td><?php echo esc_html($row['google_query']); ?></td>
                                    <td><span class="wrt-match wrt-match-<?php echo esc_attr($row['match_status']); ?>"><?php echo esc_html($row['match_label']); ?></span></td>
                                    <td><?php echo esc_html($this->format_position((float) $row['position'])); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </section>

            <section class="wrt-card wrt-table-card">
                <h2><?php esc_html_e('Requetes detectees par Google', 'wp-rank-tracker'); ?></h2>
                <?php if ($report['rows'] === []) : ?>
                    <p><?php esc_html_e('Aucune requete disponible pour le moment.', 'wp-rank-tracker'); ?></p>
                <?php else : ?>
                    <table class="widefat striped">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('Requete', 'wp-rank-tracker'); ?></th>
                                <th><?php esc_html_e('Page', 'wp-rank-tracker'); ?></th>
                                <th><?php esc_html_e('Clics', 'wp-rank-tracker'); ?></th>
                                <th><?php esc_html_e('Impressions', 'wp-rank-tracker'); ?></th>
                                <th>CTR</th>
                                <th><?php esc_html_e('Position moy.', 'wp-rank-tracker'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($report['rows'] as $row) : ?>
                                <tr>
                                    <td><strong><?php echo esc_html($row['query']); ?></strong></td>
                                    <td><?php echo esc_html($row['page']); ?></td>
                                    <td><?php echo esc_html((string) $row['clicks']); ?></td>
                                    <td><?php echo esc_html((string) $row['impressions']); ?></td>
                                    <td><?php echo esc_html($this->format_ctr($row['ctr'])); ?></td>
                                    <td><?php echo esc_html($this->format_position($row['position'])); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </section>
            <?php endif; ?>

            <?php if ($currentSection === 'dataforseo') : ?>
            <section class="wrt-card wrt-table-card">
                <h2><?php esc_html_e('Configuration du suivi', 'wp-rank-tracker'); ?></h2>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="wp_rank_tracker_save_settings" />
                    <?php wp_nonce_field(self::NONCE_ACTION_SETTINGS); ?>
                    <input type="hidden" name="settings_section" value="dataforseo" />
                    <input type="hidden" name="gsc_property_uri" value="<?php echo esc_attr($selectedProperty); ?>" />
                    <input type="hidden" name="report_days" value="<?php echo esc_attr((string) $settings['report_days']); ?>" />
                    <table class="form-table" role="presentation">
                        <tbody>
                            <tr>
                                <th scope="row"><label for="wrt-target-domain"><?php esc_html_e('Domaine cible', 'wp-rank-tracker'); ?></label></th>
                                <td>
                                    <input id="wrt-target-domain" type="text" class="regular-text" name="target_domain" value="<?php echo esc_attr($settings['target_domain']); ?>" placeholder="example.com" />
                                    <p class="description"><?php esc_html_e('Le domaine principal du site audite.', 'wp-rank-tracker'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e('Connexion du service', 'wp-rank-tracker'); ?></th>
                                <td>
                                    <p><strong><?php echo esc_html__('Etat', 'wp-rank-tracker'); ?> :</strong> <?php echo esc_html($isCentralRegistered ? __('service pret', 'wp-rank-tracker') : __('initialisation en cours', 'wp-rank-tracker')); ?></p>
                                    <p class="description"><?php esc_html_e('Le plugin enregistre automatiquement ce site sur le service central. Aucun reglage technique n est demande ici.', 'wp-rank-tracker'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="wrt-tracked-keywords"><?php esc_html_e('Mots-cles surveilles', 'wp-rank-tracker'); ?></label></th>
                                <td>
                                    <textarea id="wrt-tracked-keywords" name="tracked_keywords" rows="6" class="large-text" placeholder="formation seo&#10;consultant seo local"><?php echo esc_textarea(implode("\n", $settings['tracked_keywords'])); ?></textarea>
                                    <p class="description"><?php esc_html_e('Un mot-cle par ligne. Sert au comparatif entre ton focus local, Google et les concurrents.', 'wp-rank-tracker'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="wrt-competitors"><?php esc_html_e('Concurrents', 'wp-rank-tracker'); ?></label></th>
                                <td>
                                    <textarea id="wrt-competitors" name="competitors" rows="5" class="large-text" placeholder="concurrent1.fr&#10;www.concurrent2.com"><?php echo esc_textarea(implode("\n", $settings['competitors'])); ?></textarea>
                                    <p class="description"><?php esc_html_e('Un domaine concurrent par ligne. Cette phase prepare le comparatif concurrentiel avant la vraie phase SERP externe.', 'wp-rank-tracker'); ?></p>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <?php submit_button(__('Enregistrer et analyser maintenant', 'wp-rank-tracker')); ?>
                </form>
            </section>

            <section class="wrt-card wrt-table-card">
                <h2><?php esc_html_e('Comparatif concurrentiel prepare', 'wp-rank-tracker'); ?></h2>
                <?php if ($settings['tracked_keywords'] === []) : ?>
                    <p><?php esc_html_e('Ajoute d abord des mots-cles surveilles pour construire le comparatif.', 'wp-rank-tracker'); ?></p>
                <?php else : ?>
                    <div class="wrt-market-intro">
                        <p><?php esc_html_e('Cette vue ne pretend pas encore connaitre les positions exactes des concurrents. Elle prepare la lecture strategique : mots-cles cibles, pages locales associees, signaux Google existants et liste des concurrents a comparer en phase 3.', 'wp-rank-tracker'); ?></p>
                        <div class="wrt-badges">
                            <span><?php printf(esc_html__('%d mot(s)-cle(s) surveille(s)', 'wp-rank-tracker'), count($settings['tracked_keywords'])); ?></span>
                            <span><?php printf(esc_html__('%d concurrent(s)', 'wp-rank-tracker'), count($settings['competitors'])); ?></span>
                        </div>
                    </div>
                    <table class="widefat striped">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('Mot-cle surveille', 'wp-rank-tracker'); ?></th>
                                <th><?php esc_html_e('Page locale probable', 'wp-rank-tracker'); ?></th>
                                <th><?php esc_html_e('Signal Google actuel', 'wp-rank-tracker'); ?></th>
                                <th><?php esc_html_e('Concurrents a comparer', 'wp-rank-tracker'); ?></th>
                                <th><?php esc_html_e('Lecture strategique', 'wp-rank-tracker'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($marketRows as $row) : ?>
                                <tr>
                                    <td><strong><?php echo esc_html($row['keyword']); ?></strong></td>
                                    <td><?php echo esc_html($row['local_page']); ?></td>
                                    <td><?php echo esc_html($row['google_signal']); ?></td>
                                    <td><?php echo esc_html($row['competitors']); ?></td>
                                    <td><?php echo esc_html($row['strategy']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </section>

            <section class="wrt-card wrt-table-card">
                <h2><?php esc_html_e('Phase 3 SERP externe', 'wp-rank-tracker'); ?></h2>
                <p><?php esc_html_e('Ce module interroge DataForSEO sur Google et Bing pour les mots-cles surveilles, puis cherche ton domaine et les domaines concurrents dans les SERP retournees.', 'wp-rank-tracker'); ?></p>
                <?php if ($serpReport['fetched_at'] !== '') : ?>
                    <p class="description">
                        <?php
                        echo esc_html(
                            sprintf(
                                __('Dernier import SERP : %1$s (%2$s, %3$s)', 'wp-rank-tracker'),
                                $serpReport['fetched_at'],
                                $serpReport['location_name'],
                                $serpReport['language_name']
                            )
                        );
                        ?>
                    </p>
                    <?php if (!empty($serpReport['keywords'])) : ?>
                        <p class="description">
                            <?php
                            echo esc_html(
                                sprintf(
                                    __('Mots-cles du dernier import : %s', 'wp-rank-tracker'),
                                    implode(', ', array_map('strval', (array) $serpReport['keywords']))
                                )
                            );
                            ?>
                        </p>
                    <?php endif; ?>
                    <?php
                    $currentTrackedKeywords = is_array($settings['tracked_keywords']) ? array_values($settings['tracked_keywords']) : [];
                    $lastImportedKeywords = is_array($serpReport['keywords'] ?? null) ? array_values($serpReport['keywords']) : [];
                    if ($currentTrackedKeywords !== [] && $lastImportedKeywords !== [] && $currentTrackedKeywords !== $lastImportedKeywords) :
                    ?>
                        <div class="notice notice-warning inline">
                            <p><?php esc_html_e('Les mots-cles affiches ci-dessous ne correspondent pas encore aux mots-cles actuellement saisis dans la configuration. Clique sur "Enregistrer et analyser maintenant" pour relancer l import avec les nouvelles valeurs.', 'wp-rank-tracker'); ?></p>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
                <?php if (!empty($centralSerpStatus['available'])) : ?>
                    <div class="notice notice-info inline">
                        <p>
                            <?php
                            echo esc_html(
                                sprintf(
                                    __('Serveur central : dernier snapshot recu le %1$s, %2$d ligne(s), mots-cles recus : %3$s', 'wp-rank-tracker'),
                                    (string) ($centralSerpStatus['fetched_at'] ?: __('jamais', 'wp-rank-tracker')),
                                    (int) ($centralSerpStatus['rows_count'] ?? 0),
                                    !empty($centralSerpStatus['keywords']) ? implode(', ', array_map('strval', (array) $centralSerpStatus['keywords'])) : __('aucun', 'wp-rank-tracker')
                                )
                            );
                            ?>
                        </p>
                    </div>
                <?php endif; ?>
                <?php if (!empty($serpRequestDebug['attempted_at'])) : ?>
                    <div class="notice notice-info inline">
                        <p>
                            <?php
                            echo esc_html(
                                sprintf(
                                    __('WordPress a tente d envoyer le %1$s : %2$s', 'wp-rank-tracker'),
                                    (string) $serpRequestDebug['attempted_at'],
                                    !empty($serpRequestDebug['keywords']) ? implode(', ', array_map('strval', (array) $serpRequestDebug['keywords'])) : __('aucun mot-cle', 'wp-rank-tracker')
                                )
                            );
                            ?>
                        </p>
                    </div>
                <?php endif; ?>
                <div class="wrt-local-stats">
                    <div><strong><?php echo esc_html((string) count($settings['tracked_keywords'])); ?></strong><span><?php esc_html_e('mots-cles suivis', 'wp-rank-tracker'); ?></span></div>
                    <div><strong><?php echo esc_html((string) count($settings['competitors'])); ?></strong><span><?php esc_html_e('concurrents compares', 'wp-rank-tracker'); ?></span></div>
                    <div><strong><?php echo esc_html($serpPreviousReport['fetched_at'] !== '' ? __('Oui', 'wp-rank-tracker') : __('Non', 'wp-rank-tracker')); ?></strong><span><?php esc_html_e('historique disponible', 'wp-rank-tracker'); ?></span></div>
                </div>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="wp_rank_tracker_import_serp" />
                    <?php wp_nonce_field(self::NONCE_ACTION_IMPORT_SERP); ?>
                    <?php submit_button(__('Rafraichir maintenant avec les mots-cles deja enregistres', 'wp-rank-tracker'), 'secondary'); ?>
                </form>
                <p class="description"><?php esc_html_e('Si tu viens de modifier la liste au-dessus, utilise d abord "Enregistrer et analyser maintenant". Le bouton de rafraichissement relance seulement le dernier jeu de mots-cles enregistre.', 'wp-rank-tracker'); ?></p>
                <?php if ($serpComparisonRows === []) : ?>
                    <p><?php esc_html_e('Aucune donnee SERP externe importee pour le moment.', 'wp-rank-tracker'); ?></p>
                <?php else : ?>
                    <table class="widefat striped">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('Mot-cle', 'wp-rank-tracker'); ?></th>
                                <th><?php esc_html_e('Moteur', 'wp-rank-tracker'); ?></th>
                                <th><?php esc_html_e('Ton site', 'wp-rank-tracker'); ?></th>
                                <th><?php esc_html_e('Concurrents suivis', 'wp-rank-tracker'); ?></th>
                                <th><?php esc_html_e('Podium actuel', 'wp-rank-tracker'); ?></th>
                                <th><?php esc_html_e('Tendance', 'wp-rank-tracker'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($serpComparisonRows as $row) : ?>
                                <tr>
                                    <td><strong><?php echo esc_html($row['keyword']); ?></strong></td>
                                    <td><?php echo esc_html($row['engine']); ?></td>
                                    <td><?php echo wp_kses_post($row['target_rank']); ?></td>
                                    <td><?php echo wp_kses_post($row['competitors']); ?></td>
                                    <td><?php echo wp_kses_post($row['podium']); ?></td>
                                    <td><?php echo wp_kses_post($row['note']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </section>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * @param string[] $links
     * @return string[]
     */
    public function plugin_action_links(array $links): array {
        array_unshift(
            $links,
            sprintf(
                '<a href="%s">%s</a>',
                esc_url(admin_url('admin.php?page=' . self::MENU_SLUG)),
                esc_html__('Ouvrir', 'wp-rank-tracker')
            )
        );

        return $links;
    }

    public function handle_connect_google(): void {
        $this->assert_permissions();
        check_admin_referer(self::NONCE_ACTION_CONNECT);

        $settings = $this->ensure_central_site_registration($this->get_settings());
        $centralService = new WP_Rank_Tracker_Central_Service($settings);
        if ($centralService->is_configured()) {
            $response = $centralService->start_google_connect($this->get_admin_page_url());
            if (is_wp_error($response)) {
                $this->redirect_with_notice('connect-error', $response->get_error_message());
            }

            $authUrl = isset($response['auth_url']) ? (string) $response['auth_url'] : '';
            if ($authUrl === '') {
                $this->redirect_with_notice('connect-error', __('Le service central n a pas retourne d URL OAuth.', 'wp-rank-tracker'));
            }

            wp_safe_redirect($authUrl);
            exit;
        }

        $this->redirect_with_notice('connect-error', __('Le service central n est pas pret pour ce site. Recharge la page puis recommence.', 'wp-rank-tracker'));

        $service = new WP_Rank_Tracker_GSC_Service($settings);
        $state = wp_generate_password(24, false, false);

        set_transient($this->oauth_state_key(), $state, 10 * MINUTE_IN_SECONDS);

        $authUrl = $service->get_authorization_url($this->get_oauth_redirect_uri(), $state);
        if (is_wp_error($authUrl)) {
            $this->redirect_with_notice('connect-error', $authUrl->get_error_message());
        }

        wp_safe_redirect($authUrl);
        exit;
    }

    public function handle_google_oauth_callback(): void {
        $this->assert_permissions();

        $error = sanitize_text_field(wp_unslash((string) ($_GET['error'] ?? '')));
        if ($error !== '') {
            $this->redirect_with_notice('connect-error', $error);
        }

        $state = sanitize_text_field(wp_unslash((string) ($_GET['state'] ?? '')));
        $expectedState = (string) get_transient($this->oauth_state_key());
        delete_transient($this->oauth_state_key());

        if ($state === '' || $expectedState === '' || !hash_equals($expectedState, $state)) {
            $this->redirect_with_notice('connect-error', __('Etat OAuth invalide.', 'wp-rank-tracker'));
        }

        $code = sanitize_text_field(wp_unslash((string) ($_GET['code'] ?? '')));
        if ($code === '') {
            $this->redirect_with_notice('connect-error', __('Code OAuth Google manquant.', 'wp-rank-tracker'));
        }

        $settings = $this->get_settings();
        $service = new WP_Rank_Tracker_GSC_Service($settings);
        $tokens = $service->exchange_code_for_tokens($code, $this->get_oauth_redirect_uri());

        if (is_wp_error($tokens)) {
            $this->redirect_with_notice('connect-error', $tokens->get_error_message());
        }

        $settings['google_refresh_token'] = (string) $tokens['refresh_token'];
        update_option(self::OPTION_KEY, $settings, false);

        $this->redirect_with_notice('connect-success');
    }

    public function handle_disconnect_google(): void {
        $this->assert_permissions();
        check_admin_referer(self::NONCE_ACTION_DISCONNECT);

        $settings = $this->get_settings();
        $centralService = new WP_Rank_Tracker_Central_Service($settings);
        if ($centralService->is_configured()) {
            $response = $centralService->disconnect_google();
            if (is_wp_error($response)) {
                $this->redirect_with_notice('connect-error', $response->get_error_message());
            }
        }
        $settings['google_refresh_token'] = '';
        update_option(self::OPTION_KEY, $settings, false);

        $this->redirect_with_notice('disconnect-success');
    }

    public function handle_refresh_local(): void {
        $this->assert_permissions();
        check_admin_referer(self::NONCE_ACTION_REFRESH_LOCAL);
        $this->redirect_with_notice('local-refresh-success');
    }

    private function assert_permissions(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Permissions insuffisantes.', 'wp-rank-tracker'));
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function get_settings(): array {
        $settings = get_option(self::OPTION_KEY, []);

        if (!is_array($settings)) {
            return self::default_settings();
        }

        return wp_parse_args($settings, self::default_settings());
    }

    /**
     * @return array<string, mixed>
     */
    private static function default_settings(): array {
        return [
            'target_domain' => wp_parse_url(home_url('/'), PHP_URL_HOST) ?: '',
            'central_api_base_url' => 'https://api.mapage-wp.online',
            'central_site_token' => '',
            'gsc_property_uri' => '',
            'google_client_id' => '',
            'google_client_secret' => '',
            'google_refresh_token' => '',
            'report_days' => 28,
            'competitors' => [],
            'tracked_keywords' => [],
            'dataforseo_login' => '',
            'dataforseo_password' => '',
            'dataforseo_location_name' => 'United States',
            'dataforseo_language_name' => 'English',
            'dataforseo_depth' => 20,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function get_report(): array {
        $report = get_option(self::REPORT_OPTION_KEY, []);

        if (!is_array($report)) {
            return $this->empty_report();
        }

        $rows = [];
        foreach (($report['rows'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }

            $rows[] = [
                'page' => esc_url_raw((string) ($row['page'] ?? '')),
                'query' => sanitize_text_field((string) ($row['query'] ?? '')),
                'clicks' => (int) ($row['clicks'] ?? 0),
                'impressions' => (int) ($row['impressions'] ?? 0),
                'ctr' => (float) ($row['ctr'] ?? 0),
                'position' => (float) ($row['position'] ?? 0),
            ];
        }

        return [
            'fetched_at' => sanitize_text_field((string) ($report['fetched_at'] ?? '')),
            'start_date' => sanitize_text_field((string) ($report['start_date'] ?? '')),
            'end_date' => sanitize_text_field((string) ($report['end_date'] ?? '')),
            'property_uri' => sanitize_text_field((string) ($report['property_uri'] ?? '')),
            'rows' => $rows,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function get_previous_google_report(): array {
        $history = get_option(self::REPORT_HISTORY_OPTION_KEY, []);
        if (!is_array($history) || $history === []) {
            return $this->empty_report();
        }

        $previous = $history[0] ?? [];
        if (!is_array($previous)) {
            return $this->empty_report();
        }

        $rows = [];
        foreach (($previous['rows'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }

            $rows[] = [
                'page' => esc_url_raw((string) ($row['page'] ?? '')),
                'query' => sanitize_text_field((string) ($row['query'] ?? '')),
                'clicks' => (int) ($row['clicks'] ?? 0),
                'impressions' => (int) ($row['impressions'] ?? 0),
                'ctr' => (float) ($row['ctr'] ?? 0),
                'position' => (float) ($row['position'] ?? 0),
            ];
        }

        return [
            'fetched_at' => sanitize_text_field((string) ($previous['fetched_at'] ?? '')),
            'start_date' => sanitize_text_field((string) ($previous['start_date'] ?? '')),
            'end_date' => sanitize_text_field((string) ($previous['end_date'] ?? '')),
            'property_uri' => sanitize_text_field((string) ($previous['property_uri'] ?? '')),
            'rows' => $rows,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function build_local_audit(): array {
        $posts = get_posts(
            [
                'post_type' => ['page', 'post'],
                'post_status' => 'publish',
                'posts_per_page' => 100,
                'orderby' => 'menu_order title',
                'order' => 'ASC',
            ]
        );

        $pages = [];
        $withFocusCount = 0;

        foreach ($posts as $post) {
            $analysis = $this->analyze_post_keywords($post);
            if ($analysis['primary_keyword'] !== __('A definir', 'wp-rank-tracker')) {
                $withFocusCount++;
            }
            $pages[] = $analysis;
        }

        return [
            'page_count' => count($pages),
            'with_focus_count' => $withFocusCount,
            'pages' => $pages,
        ];
    }

    private function sanitize_domain(string $domain): string {
        $domain = trim(strtolower($domain));
        $domain = preg_replace('#^https?://#', '', $domain);

        return rtrim((string) $domain, '/');
    }

    private function sanitize_report_days($value): int {
        $days = absint($value);
        if ($days < 1) {
            return 28;
        }

        return min($days, 90);
    }

    private function sanitize_serp_depth($value): int {
        $depth = absint($value);
        if ($depth < 20) {
            return 20;
        }

        return min($depth, 100);
    }

    /**
     * @return string[]
     */
    private function sanitize_line_list(string $value): array {
        $lines = preg_split('/\r\n|\r|\n/', wp_unslash($value));
        if (!is_array($lines)) {
            return [];
        }

        $clean = [];
        foreach ($lines as $line) {
            $line = trim(sanitize_text_field($line));
            if ($line === '') {
                continue;
            }

            $clean[] = $line;
        }

        return array_values(array_unique($clean));
    }

    private function is_google_connected(array $settings): bool {
        return !empty($settings['google_refresh_token']);
    }

    /**
     * @param array<string, mixed> $settings
     * @return array{connected:bool, property_uri:string}
     */
    private function get_central_google_status(array $settings): array {
        $service = new WP_Rank_Tracker_Central_Service($settings);
        if (empty($settings['central_site_token'])) {
            return [
                'connected' => $this->is_google_connected($settings),
                'property_uri' => sanitize_text_field((string) ($settings['gsc_property_uri'] ?? '')),
            ];
        }

        $response = $service->get_google_status();
        if (is_wp_error($response)) {
            return [
                'connected' => false,
                'property_uri' => '',
            ];
        }

        return [
            'connected' => !empty($response['connected']),
            'property_uri' => sanitize_text_field((string) ($response['property_uri'] ?? '')),
        ];
    }

    /**
     * @param array<string, mixed> $settings
     * @return array{available:bool,fetched_at:string,keywords:array<int,string>,rows_count:int}
     */
    private function get_central_serp_status(array $settings): array {
        $service = new WP_Rank_Tracker_Central_Service($settings);
        if (empty($settings['central_site_token'])) {
            return [
                'available' => false,
                'fetched_at' => '',
                'keywords' => [],
                'rows_count' => 0,
            ];
        }

        $response = $service->get_serp_status();
        if (is_wp_error($response)) {
            return [
                'available' => false,
                'fetched_at' => '',
                'keywords' => [],
                'rows_count' => 0,
            ];
        }

        $report = is_array($response['last_report'] ?? null) ? $response['last_report'] : [];

        return [
            'available' => true,
            'fetched_at' => sanitize_text_field((string) ($report['fetched_at'] ?? '')),
            'keywords' => array_values(array_filter(array_map(
                static fn($keyword): string => sanitize_text_field((string) $keyword),
                is_array($report['keywords'] ?? null) ? $report['keywords'] : []
            ))),
            'rows_count' => (int) ($report['rows_count'] ?? 0),
        ];
    }

    /**
     * @param array<string, mixed> $settings
     * @return array<int, array{site_url:string, permission_level:string}>
     */
    private function get_google_properties(array $settings, bool $isConnected): array {
        if (!$isConnected) {
            return [];
        }

        $centralService = new WP_Rank_Tracker_Central_Service($settings);
        if (!$centralService->is_configured()) {
            return [];
        }

        $response = $centralService->get_google_properties();
        if (is_wp_error($response) || !is_array($response['properties'] ?? null)) {
            return [];
        }

        $properties = [];
        foreach ($response['properties'] as $property) {
            if (!is_array($property)) {
                continue;
            }

            $siteUrl = sanitize_text_field((string) ($property['site_url'] ?? ''));
            if ($siteUrl === '') {
                continue;
            }

            $properties[] = [
                'site_url' => $siteUrl,
                'permission_level' => sanitize_text_field((string) ($property['permission_level'] ?? '')),
            ];
        }

        return $properties;
    }

    /**
     * @param array<string, mixed> $settings
     * @param array<string, mixed> $centralStatus
     * @param array<int, array{site_url:string, permission_level:string}> $googleProperties
     */
    private function resolve_selected_property(array $settings, array $centralStatus, array $googleProperties): string {
        $savedProperty = sanitize_text_field((string) ($settings['gsc_property_uri'] ?? ''));
        if ($savedProperty !== '') {
            return $savedProperty;
        }

        $centralProperty = sanitize_text_field((string) ($centralStatus['property_uri'] ?? ''));
        if ($centralProperty !== '') {
            return $centralProperty;
        }

        if (count($googleProperties) === 1) {
            return $googleProperties[0]['site_url'];
        }

        return '';
    }

    private function maybe_register_site_with_central_service(): void {
        $this->ensure_central_site_registration($this->get_settings());
    }

    /**
     * @param array<string, mixed> $settings
     * @return array<string, mixed>|\WP_Error
     */
    private function run_google_import(array $settings) {
        $centralService = new WP_Rank_Tracker_Central_Service($settings);

        if ($centralService->is_configured()) {
            $response = $centralService->import_google_report((string) $settings['gsc_property_uri'], (int) $settings['report_days']);
            if (is_wp_error($response)) {
                return $response;
            }

            return is_array($response['report'] ?? null) ? $response['report'] : [];
        }

        $service = new WP_Rank_Tracker_GSC_Service($settings);
        return $service->fetch_search_analytics();
    }

    /**
     * @param array<string, mixed> $settings
     * @return array<string, mixed>|\WP_Error
     */
    private function run_serp_import(array $settings) {
        $this->store_serp_request_debug($settings);
        $centralService = new WP_Rank_Tracker_Central_Service($settings);

        if ($centralService->is_configured()) {
            $response = $centralService->import_serp_report(
                (string) $settings['target_domain'],
                is_array($settings['tracked_keywords']) ? $settings['tracked_keywords'] : [],
                is_array($settings['competitors']) ? $settings['competitors'] : [],
                (string) $settings['dataforseo_location_name'],
                (string) $settings['dataforseo_language_name'],
                max(20, (int) $settings['dataforseo_depth'])
            );
            if (is_wp_error($response)) {
                return $response;
            }

            return is_array($response['report'] ?? null) ? $response['report'] : [];
        }

        $service = new WP_Rank_Tracker_DataForSEO_Service($settings);
        return $service->fetch_serp_snapshot(is_array($settings['tracked_keywords']) ? $settings['tracked_keywords'] : []);
    }

    /**
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    private function ensure_central_site_registration(array $settings): array {
        if (!empty($settings['central_site_token'])) {
            return $settings;
        }

        $service = new WP_Rank_Tracker_Central_Service($settings);
        $response = $service->register_site();
        if (is_wp_error($response)) {
            return $settings;
        }

        $siteToken = isset($response['site_token']) ? (string) $response['site_token'] : '';
        if ($siteToken === '') {
            return $settings;
        }

        $settings['central_site_token'] = $siteToken;
        update_option(self::OPTION_KEY, $settings, false);

        return $settings;
    }

    /**
     * @param array<string, mixed> $report
     * @return array<string, int|string|float>
     */
    private function build_summary(array $report): array {
        $clicks = 0;
        $impressions = 0;
        $positionTotal = 0.0;
        $rowCount = count($report['rows']);
        $pages = [];
        $queries = [];

        foreach ($report['rows'] as $row) {
            $clicks += (int) $row['clicks'];
            $impressions += (int) $row['impressions'];
            $positionTotal += (float) $row['position'];
            $pages[(string) $row['page']] = true;
            $queries[(string) $row['query']] = true;
        }

        $avgPosition = $rowCount > 0 ? round($positionTotal / $rowCount, 1) : 0.0;

        return [
            'avg_position' => $avgPosition > 0 ? $avgPosition : '-',
            'page_count' => count($pages),
            'query_count' => count($queries),
            'clicks' => $clicks,
            'last_fetch_label' => $report['fetched_at'] !== ''
                ? sprintf(__('Importe le %s', 'wp-rank-tracker'), $report['fetched_at'])
                : __('Aucun import', 'wp-rank-tracker'),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function group_rows_by_page(array $rows): array {
        $pages = [];

        foreach ($rows as $row) {
            $page = (string) $row['page'];
            if ($page === '') {
                continue;
            }

            if (!isset($pages[$page])) {
                $pages[$page] = [
                    'page' => $page,
                    'clicks' => 0,
                    'impressions' => 0,
                    'ctr' => 0.0,
                    'position' => 0.0,
                    'position_total' => 0.0,
                    'row_count' => 0,
                    'top_query' => '',
                    'top_query_clicks' => -1,
                ];
            }

            $pages[$page]['clicks'] += (int) $row['clicks'];
            $pages[$page]['impressions'] += (int) $row['impressions'];
            $pages[$page]['position_total'] += (float) $row['position'];
            $pages[$page]['row_count']++;

            if ((int) $row['clicks'] > (int) $pages[$page]['top_query_clicks']) {
                $pages[$page]['top_query'] = (string) $row['query'];
                $pages[$page]['top_query_clicks'] = (int) $row['clicks'];
            }
        }

        foreach ($pages as &$page) {
            $page['ctr'] = $page['impressions'] > 0 ? ((float) $page['clicks'] / (float) $page['impressions']) : 0.0;
            $page['position'] = $page['row_count'] > 0 ? round((float) $page['position_total'] / (int) $page['row_count'], 1) : 0.0;
            unset($page['position_total'], $page['row_count'], $page['top_query_clicks']);
        }
        unset($page);

        usort(
            $pages,
            static fn(array $left, array $right): int => $right['clicks'] <=> $left['clicks']
        );

        return array_values($pages);
    }

    /**
     * @param array<int, array<string, mixed>> $localPages
     * @param array<int, array<string, mixed>> $googlePages
     * @return array<int, array<string, mixed>>
     */
    private function build_comparison_rows(array $localPages, array $googlePages): array {
        if ($localPages === [] || $googlePages === []) {
            return [];
        }

        $googleByPath = [];
        foreach ($googlePages as $page) {
            $path = $this->normalize_url_path((string) ($page['page'] ?? ''));
            if ($path === '') {
                continue;
            }

            $googleByPath[$path] = $page;
        }

        $rows = [];
        foreach ($localPages as $page) {
            $path = $this->normalize_url_path((string) ($page['url'] ?? ''));
            if ($path === '' || !isset($googleByPath[$path])) {
                continue;
            }

            $googlePage = $googleByPath[$path];
            $localKeyword = (string) ($page['primary_keyword'] ?? '');
            $googleQuery = (string) ($googlePage['top_query'] ?? '');
            [$status, $label] = $this->compare_keywords($localKeyword, $googleQuery);

            $rows[] = [
                'title' => (string) ($page['title'] ?? ''),
                'url' => (string) ($page['url'] ?? ''),
                'local_keyword' => $localKeyword,
                'google_query' => $googleQuery !== '' ? $googleQuery : __('Aucune requete dominante', 'wp-rank-tracker'),
                'match_status' => $status,
                'match_label' => $label,
                'position' => (float) ($googlePage['position'] ?? 0),
            ];
        }

        usort(
            $rows,
            static fn(array $left, array $right): int => strcmp((string) $left['title'], (string) $right['title'])
        );

        return $rows;
    }

    /**
     * @param array<int, array<string, mixed>> $currentPages
     * @param array<int, array<string, mixed>> $previousPages
     * @return array<int, array<string, mixed>>
     */
    private function build_google_trend_rows(array $currentPages, array $previousPages): array {
        if ($currentPages === []) {
            return [];
        }

        $previousByPath = [];
        foreach ($previousPages as $row) {
            $path = $this->normalize_url_path((string) ($row['page'] ?? ''));
            if ($path === '') {
                continue;
            }

            $previousByPath[$path] = $row;
        }

        $rows = [];
        foreach ($currentPages as $row) {
            $path = $this->normalize_url_path((string) ($row['page'] ?? ''));
            $previous = $path !== '' && isset($previousByPath[$path]) ? $previousByPath[$path] : null;
            $previousPosition = $previous !== null ? (float) ($previous['position'] ?? 0) : 0.0;
            $previousClicks = $previous !== null ? (int) ($previous['clicks'] ?? 0) : 0;

            $row['trend'] = $this->build_google_trend_badge((float) ($row['position'] ?? 0), $previousPosition, (int) ($row['clicks'] ?? 0), $previousClicks);
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function build_google_query_podium(array $rows): array {
        if ($rows === []) {
            return [];
        }

        usort(
            $rows,
            static function (array $left, array $right): int {
                $clickCompare = ((int) ($right['clicks'] ?? 0)) <=> ((int) ($left['clicks'] ?? 0));
                if ($clickCompare !== 0) {
                    return $clickCompare;
                }

                return ((float) ($left['position'] ?? 0)) <=> ((float) ($right['position'] ?? 0));
            }
        );

        $podium = [];
        foreach (array_slice($rows, 0, 3) as $index => $row) {
            $row['rank'] = $index + 1;
            $podium[] = $row;
        }

        return $podium;
    }

    /**
     * @param array<int, array<string, mixed>> $pageRows
     * @return array<int, array<string, mixed>>
     */
    private function build_pages_near_top_ten_rows(array $pageRows): array {
        $rows = array_values(array_filter(
            $pageRows,
            static fn(array $row): bool => (float) ($row['position'] ?? 0) >= 8.0
                && (float) ($row['position'] ?? 0) <= 20.0
                && (int) ($row['impressions'] ?? 0) >= 10
        ));

        usort(
            $rows,
            static function (array $left, array $right): int {
                $leftPos = (float) ($left['position'] ?? 0);
                $rightPos = (float) ($right['position'] ?? 0);
                if ($leftPos !== $rightPos) {
                    return $leftPos <=> $rightPos;
                }

                return ((int) ($right['impressions'] ?? 0)) <=> ((int) ($left['impressions'] ?? 0));
            }
        );

        $rows = array_slice($rows, 0, 5);
        foreach ($rows as &$row) {
            $row['action'] = __('Renforcer le title, le H1 et le debut du contenu pour pousser cette page dans une zone de clic plus forte.', 'wp-rank-tracker');
        }
        unset($row);

        return $rows;
    }

    /**
     * @param array<int, array<string, mixed>> $pageRows
     * @return array<int, array<string, mixed>>
     */
    private function build_low_ctr_rows(array $pageRows): array {
        $rows = array_values(array_filter(
            $pageRows,
            static fn(array $row): bool => (int) ($row['impressions'] ?? 0) >= 20
                && (float) ($row['ctr'] ?? 0) > 0
                && (float) ($row['ctr'] ?? 0) < 0.03
        ));

        usort(
            $rows,
            static fn(array $left, array $right): int => ((int) ($right['impressions'] ?? 0)) <=> ((int) ($left['impressions'] ?? 0))
        );

        $rows = array_slice($rows, 0, 5);
        foreach ($rows as &$row) {
            $row['action'] = __('Retravailler le title SEO et la promesse de la page pour donner plus envie de cliquer dans Google.', 'wp-rank-tracker');
        }
        unset($row);

        return $rows;
    }

    /**
     * @param array<int, array<string, mixed>> $googleTrendRows
     * @return array<int, array<string, mixed>>
     */
    private function build_declining_pages_rows(array $googleTrendRows): array {
        $rows = array_values(array_filter(
            $googleTrendRows,
            static fn(array $row): bool => str_contains((string) ($row['trend'] ?? ''), 'wrt-delta-down')
        ));

        $rows = array_slice($rows, 0, 5);
        foreach ($rows as &$row) {
            $row['action'] = __('Verifier les changements recents sur la page, le title, le contenu et les concurrents qui ont pu passer devant.', 'wp-rank-tracker');
        }
        unset($row);

        return $rows;
    }

    /**
     * @param array<int, array<string, mixed>> $currentRows
     * @param array<int, array<string, mixed>> $previousRows
     * @return array<int, array<string, mixed>>
     */
    private function build_emerging_queries_rows(array $currentRows, array $previousRows): array {
        if ($currentRows === []) {
            return [];
        }

        $previousKeys = [];
        foreach ($previousRows as $row) {
            $key = (string) ($row['page'] ?? '') . '|' . (string) ($row['query'] ?? '');
            if ($key !== '|') {
                $previousKeys[$key] = true;
            }
        }

        $rows = [];
        foreach ($currentRows as $row) {
            $key = (string) ($row['page'] ?? '') . '|' . (string) ($row['query'] ?? '');
            if (isset($previousKeys[$key])) {
                continue;
            }

            if ((int) ($row['impressions'] ?? 0) < 5) {
                continue;
            }

            $row['action'] = __('Verifier si cette requete merite d etre assumee dans le contenu, les H2 ou une page dediee.', 'wp-rank-tracker');
            $rows[] = $row;
        }

        usort(
            $rows,
            static function (array $left, array $right): int {
                $impressionCompare = ((int) ($right['impressions'] ?? 0)) <=> ((int) ($left['impressions'] ?? 0));
                if ($impressionCompare !== 0) {
                    return $impressionCompare;
                }

                return ((float) ($left['position'] ?? 0)) <=> ((float) ($right['position'] ?? 0));
            }
        );

        return array_slice($rows, 0, 5);
    }

    /**
     * @param array<string, mixed> $settings
     * @param array<int, array<string, mixed>> $localPages
     * @param array<int, array<string, mixed>> $googlePages
     * @param array<int, array<string, mixed>> $comparisonRows
     * @param array<string, mixed> $serpReport
     * @return array<int, array<string, string>>
     */
    private function build_priority_opportunities(array $settings, array $localPages, array $googlePages, array $comparisonRows, array $serpReport): array {
        $opportunities = [];
        $targetDomain = $this->sanitize_domain((string) ($settings['target_domain'] ?? ''));
        $competitors = is_array($settings['competitors'] ?? null) ? array_map([$this, 'sanitize_domain'], $settings['competitors']) : [];
        $localByPath = [];

        foreach ($localPages as $page) {
            $path = $this->normalize_url_path((string) ($page['url'] ?? ''));
            if ($path !== '') {
                $localByPath[$path] = $page;
            }
        }

        foreach ($googlePages as $pageRow) {
            $pageUrl = (string) ($pageRow['page'] ?? '');
            $path = $this->normalize_url_path($pageUrl);
            $localPage = $path !== '' && isset($localByPath[$path]) ? $localByPath[$path] : null;
            $title = $localPage !== null ? (string) ($localPage['title'] ?? $pageUrl) : $pageUrl;
            $topQuery = (string) ($pageRow['top_query'] ?? '');
            $position = (float) ($pageRow['position'] ?? 0);
            $impressions = (int) ($pageRow['impressions'] ?? 0);
            $ctr = (float) ($pageRow['ctr'] ?? 0);

            if ($position >= 4.0 && $position <= 15.0 && $impressions >= 20) {
                $pageActions = $localPage !== null ? (array) ($localPage['actions'] ?? []) : [];
                $opportunities[] = $this->make_opportunity(
                    __('Page proche de la premiere page forte', 'wp-rank-tracker'),
                    $title !== '' ? $title : __('Page sans titre clair', 'wp-rank-tracker'),
                    sprintf(__('Google voit deja cette page sur "%s" autour de la position %s. Elle est assez proche pour gagner des places avec une optimisation ciblee.', 'wp-rank-tracker'), $topQuery !== '' ? $topQuery : __('cette requete', 'wp-rank-tracker'), $this->format_position($position)),
                    sprintf(__('Dans WordPress, retravaille d abord le titre visible, le H1 et les 2 premiers H2 pour reprendre clairement "%s", puis ajoute un bloc de contenu qui repond mieux a cette requete.', 'wp-rank-tracker'), $topQuery !== '' ? $topQuery : __('la requete cible', 'wp-rank-tracker')),
                    __('Impact attendu : faire progresser une page deja visible vers une meilleure zone de clics.', 'wp-rank-tracker'),
                    'high',
                    $pageActions
                );
            }

            if ($impressions >= 50 && $ctr > 0 && $ctr < 0.03) {
                $pageActions = $localPage !== null ? (array) ($localPage['actions'] ?? []) : [];
                $opportunities[] = $this->make_opportunity(
                    __('Page vue par Google mais peu cliquée', 'wp-rank-tracker'),
                    $title !== '' ? $title : __('Page sans titre clair', 'wp-rank-tracker'),
                    sprintf(__('La page genere des impressions sur "%s", mais le CTR reste faible. Cela suggere souvent un titre ou une promesse peu convaincante dans Google.', 'wp-rank-tracker'), $topQuery !== '' ? $topQuery : __('cette requete', 'wp-rank-tracker')),
                    __('Dans WordPress, reformule le titre de la page et si tu as un plugin SEO, retravaille aussi la balise title SEO pour donner une promesse plus concrete, plus precise et plus orientee benefice.', 'wp-rank-tracker'),
                    __('Impact attendu : gagner plus de clics sans attendre une hausse de position.', 'wp-rank-tracker'),
                    'high',
                    $pageActions
                );
            }
        }

        foreach ($comparisonRows as $row) {
            if ((string) ($row['match_status'] ?? '') !== 'weak') {
                continue;
            }

            $opportunities[] = $this->make_opportunity(
                __('La page n attire pas la requete que son contenu laisse penser', 'wp-rank-tracker'),
                (string) ($row['title'] ?? __('Page sans titre', 'wp-rank-tracker')),
                sprintf(__('En local, la page semble cibler "%s", mais Google la relie surtout a "%s".', 'wp-rank-tracker'), (string) ($row['local_keyword'] ?? ''), (string) ($row['google_query'] ?? '')),
                sprintf(__('Choisis une direction claire : soit tu assumes la requete vue par Google et tu renforces tout le contenu autour de "%s", soit tu crees une page dediee a cette requete pour eviter de melanger deux intentions.', 'wp-rank-tracker'), (string) ($row['google_query'] ?? __('la requete observee', 'wp-rank-tracker'))),
                __('Impact attendu : eviter les pages floues et aligner le contenu avec la bonne intention de recherche.', 'wp-rank-tracker'),
                'high',
                $this->find_page_actions_by_url((string) ($row['url'] ?? ''), $localPages)
            );
        }

        $trackedKeywords = is_array($settings['tracked_keywords'] ?? null) ? $settings['tracked_keywords'] : [];
        foreach ($trackedKeywords as $keyword) {
            $keyword = (string) $keyword;
            if ($keyword === '') {
                continue;
            }

            $localMatch = $this->find_best_local_page_for_keyword($keyword, $localPages);
            $googleMatch = $this->find_best_google_row_for_keyword($keyword, $this->get_report()['rows']);

            if ($localMatch !== null && $googleMatch === null) {
                $opportunities[] = $this->make_opportunity(
                    __('Mot-cle important encore invisible dans Google', 'wp-rank-tracker'),
                    (string) ($localMatch['title'] ?? __('Page locale', 'wp-rank-tracker')),
                    sprintf(__('Tu suis "%s", et une page locale semble deja la cibler, mais Search Console ne montre pas encore de signal dessus.', 'wp-rank-tracker'), $keyword),
                    sprintf(__('Dans WordPress, renforce cette page autour de "%s" avec un H1 plus net, des H2 plus explicites, un contenu plus concret et quelques liens internes depuis d autres pages du site.', 'wp-rank-tracker'), $keyword),
                    __('Impact attendu : aider Google a comprendre plus vite quelle page doit remonter sur ce sujet.', 'wp-rank-tracker'),
                    'medium',
                    (array) ($localMatch['actions'] ?? [])
                );
            }

            $googleRowsForKeyword = array_values(array_filter(
                $serpReport['rows'] ?? [],
                static fn(array $row): bool => (string) ($row['engine'] ?? '') === 'google' && (string) ($row['keyword'] ?? '') === $keyword
            ));
            $targetRank = $this->find_domain_rank($targetDomain, $googleRowsForKeyword);
            $bestCompetitorRank = $this->extract_best_competitor_rank($this->build_competitor_rank_rows($competitors, $googleRowsForKeyword, []));

            if ($bestCompetitorRank > 0 && ($targetRank === 0 || $bestCompetitorRank < $targetRank)) {
                $opportunities[] = $this->make_opportunity(
                    __('Un concurrent te passe devant sur un mot-cle suivi', 'wp-rank-tracker'),
                    $localMatch !== null ? (string) ($localMatch['title'] ?? __('Page a renforcer', 'wp-rank-tracker')) : __('Mot-cle sans page cible claire', 'wp-rank-tracker'),
                    sprintf(__('Sur Google, un concurrent suivi apparait mieux place que ton domaine sur "%s".', 'wp-rank-tracker'), $keyword),
                    sprintf(__('Ouvre la page de ton site qui doit travailler "%s", puis compare-la aux 3 premiers resultats : clarifie la promesse des titres, ajoute une preuve concrete, reponds aux questions pratiques et rends la page plus complete.', 'wp-rank-tracker'), $keyword),
                    __('Impact attendu : reduire l ecart avec les concurrents les mieux positionnes.', 'wp-rank-tracker'),
                    'high',
                    $localMatch !== null ? (array) ($localMatch['actions'] ?? []) : []
                );
            }
        }

        usort(
            $opportunities,
            function (array $left, array $right): int {
                $priorityOrder = ['high' => 0, 'medium' => 1, 'low' => 2];
                $leftPriority = $priorityOrder[$left['priority']] ?? 1;
                $rightPriority = $priorityOrder[$right['priority']] ?? 1;
                if ($leftPriority !== $rightPriority) {
                    return $leftPriority <=> $rightPriority;
                }

                return strcmp((string) $left['page'], (string) $right['page']);
            }
        );

        return array_slice($opportunities, 0, 6);
    }

    /**
     * @param array<string, mixed> $settings
     * @param array<string, mixed> $localAudit
     * @return array<int, array{status:string,badge:string,title:string,copy:string}>
     */
    private function build_setup_steps(array $settings, array $localAudit, bool $isConnected, string $selectedProperty, array $serpReport): array {
        $trackedKeywords = is_array($settings['tracked_keywords'] ?? null) ? $settings['tracked_keywords'] : [];
        $competitors = is_array($settings['competitors'] ?? null) ? $settings['competitors'] : [];

        return [
            $this->make_step($localAudit['page_count'] > 0, __('Audit local', 'wp-rank-tracker'), __('Le site a deja ete scanne localement. Tu peux relire les mots-cles detectes page par page.', 'wp-rank-tracker')),
            $this->make_step($isConnected, __('Connexion Google', 'wp-rank-tracker'), $isConnected ? __('Google est connecte. Les donnees Search Console peuvent etre importees automatiquement.', 'wp-rank-tracker') : __('Connecte Google pour confronter l audit local aux vraies requetes et positions observees.', 'wp-rank-tracker')),
            $this->make_step($selectedProperty !== '', __('Propriete choisie', 'wp-rank-tracker'), $selectedProperty !== '' ? __('Une propriete Search Console est selectionnee pour les imports quotidiens.', 'wp-rank-tracker') : __('Choisis la propriete Search Console du site pour activer le suivi Google.', 'wp-rank-tracker')),
            $this->make_step($trackedKeywords !== [], __('Mots-cles suivis', 'wp-rank-tracker'), $trackedKeywords !== [] ? __('Des mots-cles sont deja surveilles pour guider le suivi et les opportunites.', 'wp-rank-tracker') : __('Ajoute tes mots-cles prioritaires pour orienter les analyses et le comparatif.', 'wp-rank-tracker')),
            $this->make_step($competitors !== [], __('Concurrents', 'wp-rank-tracker'), $competitors !== [] ? __('Des concurrents sont renseignes pour comparer leur presence dans les SERP.', 'wp-rank-tracker') : __('Ajoute 2 ou 3 concurrents directs pour voir qui te passe devant.', 'wp-rank-tracker')),
            $this->make_step(($serpReport['fetched_at'] ?? '') !== '', __('Comparatif SERP', 'wp-rank-tracker'), ($serpReport['fetched_at'] ?? '') !== '' ? __('Le comparatif externe tourne deja et alimente les podiums concurrentiels.', 'wp-rank-tracker') : __('Lance DataForSEO pour visualiser ton site face aux concurrents sur Google et Bing.', 'wp-rank-tracker')),
        ];
    }

    /**
     * @param array<string, mixed> $localAudit
     * @param array<string, int|string|float> $summary
     * @param array<int, array<string, mixed>> $priorityOpportunities
     * @param array<int, array<string, mixed>> $googleTrendRows
     * @param array<int, array<string, string>> $serpComparisonRows
     * @return array<int, array{label:string,value:string,copy:string}>
     */
    private function build_dashboard_metrics(array $localAudit, array $summary, array $priorityOpportunities, array $googleTrendRows, array $serpComparisonRows): array {
        $highPriorityCount = count(array_filter($priorityOpportunities, static fn(array $item): bool => (string) ($item['priority'] ?? '') === 'high'));
        $downPagesCount = count(array_filter($googleTrendRows, static fn(array $item): bool => str_contains((string) ($item['trend'] ?? ''), 'wrt-delta-down')));
        $serpDetectedCount = count(array_filter($serpComparisonRows, static fn(array $item): bool => !str_contains((string) ($item['target_rank'] ?? ''), 'Non detecte')));

        return [
            [
                'label' => __('Priorites hautes', 'wp-rank-tracker'),
                'value' => (string) $highPriorityCount,
                'copy' => __('Actions a traiter d abord pour esperer un impact SEO utile.', 'wp-rank-tracker'),
            ],
            [
                'label' => __('Pages en baisse', 'wp-rank-tracker'),
                'value' => (string) $downPagesCount,
                'copy' => __('Pages Google qui ont perdu du terrain ou des clics depuis le dernier import.', 'wp-rank-tracker'),
            ],
            [
                'label' => __('Requetes Google', 'wp-rank-tracker'),
                'value' => (string) ($summary['query_count'] ?? 0),
                'copy' => __('Nombre de requetes actuellement visibles dans Search Console.', 'wp-rank-tracker'),
            ],
            [
                'label' => __('Mots-cles suivis detectes', 'wp-rank-tracker'),
                'value' => (string) $serpDetectedCount,
                'copy' => __('Mots-cles suivis pour lesquels ton domaine ressort deja dans les SERP externes.', 'wp-rank-tracker'),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $settings
     * @param array<int, array<string, mixed>> $priorityOpportunities
     * @param array<int, array<string, mixed>> $googleTrendRows
     * @param array<int, array<string, string>> $serpComparisonRows
     * @return array<int, array{priority:string,title:string,copy:string}>
     */
    private function build_dashboard_alerts(array $settings, bool $isConnected, string $selectedProperty, array $priorityOpportunities, array $googleTrendRows, array $serpComparisonRows): array {
        $alerts = [];

        if (!$isConnected) {
            $alerts[] = [
                'priority' => 'high',
                'title' => __('Google n est pas encore connecte', 'wp-rank-tracker'),
                'copy' => __('Le plugin peut deja analyser tes pages, mais il lui manque la vision reelle de Google pour prioriser les actions.', 'wp-rank-tracker'),
            ];
        } elseif ($selectedProperty === '') {
            $alerts[] = [
                'priority' => 'high',
                'title' => __('La propriete Search Console n est pas choisie', 'wp-rank-tracker'),
                'copy' => __('Choisis la propriete du site pour activer les imports automatiques et les tendances Google.', 'wp-rank-tracker'),
            ];
        }

        $firstHighOpportunity = $priorityOpportunities[0] ?? null;
        if (is_array($firstHighOpportunity) && (string) ($firstHighOpportunity['priority'] ?? '') === 'high') {
            $alerts[] = [
                'priority' => 'high',
                'title' => __('Une action forte est disponible', 'wp-rank-tracker'),
                'copy' => (string) ($firstHighOpportunity['title'] ?? ''),
            ];
        }

        foreach ($googleTrendRows as $row) {
            if (str_contains((string) ($row['trend'] ?? ''), 'wrt-delta-down')) {
                $alerts[] = [
                    'priority' => 'medium',
                    'title' => __('Une page perd du terrain dans Google', 'wp-rank-tracker'),
                    'copy' => sprintf(__('La page %s montre une baisse recente. Elle merite une verification rapide.', 'wp-rank-tracker'), (string) ($row['page'] ?? __('Sans titre', 'wp-rank-tracker'))),
                ];
                break;
            }
        }

        foreach ($serpComparisonRows as $row) {
            if (str_contains((string) ($row['note'] ?? ''), 'concurrent')) {
                $alerts[] = [
                    'priority' => 'medium',
                    'title' => __('Un concurrent ressort devant toi', 'wp-rank-tracker'),
                    'copy' => sprintf(__('Sur %s, un concurrent suivi reste mieux place que ton domaine.', 'wp-rank-tracker'), (string) ($row['keyword'] ?? __('ce mot-cle', 'wp-rank-tracker'))),
                ];
                break;
            }
        }

        if ((is_array($settings['tracked_keywords'] ?? null) ? $settings['tracked_keywords'] : []) === []) {
            $alerts[] = [
                'priority' => 'low',
                'title' => __('Aucun mot-cle suivi pour le moment', 'wp-rank-tracker'),
                'copy' => __('Ajoute tes mots-cles prioritaires pour transformer les analyses en suivi concret.', 'wp-rank-tracker'),
            ];
        }

        return array_slice($alerts, 0, 4);
    }

    /**
     * @param array<string, mixed> $settings
     * @param array<int, array<string, mixed>> $priorityOpportunities
     * @return array<int, array{label:string,url:string,class:string}>
     */
    private function build_quick_actions(array $settings, array $priorityOpportunities): array {
        $actions = [
            [
                'label' => __('Ouvrir Google Search Console', 'wp-rank-tracker'),
                'url' => admin_url('admin.php?page=' . self::MENU_SLUG_GOOGLE),
                'class' => 'button-primary',
            ],
            [
                'label' => __('Ouvrir DataForSEO', 'wp-rank-tracker'),
                'url' => admin_url('admin.php?page=' . self::MENU_SLUG_DATAFORSEO),
                'class' => '',
            ],
        ];

        $firstOpportunity = $priorityOpportunities[0] ?? null;
        if (is_array($firstOpportunity) && !empty($firstOpportunity['actions']) && is_array($firstOpportunity['actions'])) {
            $firstAction = $firstOpportunity['actions'][0] ?? null;
            if (is_array($firstAction) && !empty($firstAction['url']) && !empty($firstAction['label'])) {
                array_unshift($actions, [
                    'label' => __('Traiter la priorite #1', 'wp-rank-tracker'),
                    'url' => (string) $firstAction['url'],
                    'class' => 'button-primary',
                ]);
            }
        }

        return array_slice($actions, 0, 3);
    }

    /**
     * @param array<int, array<string, mixed>> $pageRows
     * @return array<int, array{label:string,value_label:string,width:float}>
     */
    private function build_google_click_chart_rows(array $pageRows): array {
        if ($pageRows === []) {
            return [];
        }

        $topRows = array_slice($pageRows, 0, 5);
        $maxClicks = max(array_map(static fn(array $row): int => (int) ($row['clicks'] ?? 0), $topRows));
        $maxClicks = max($maxClicks, 1);
        $chartRows = [];

        foreach ($topRows as $row) {
            $label = $this->shorten_label((string) ($row['page'] ?? ''));
            $clicks = (int) ($row['clicks'] ?? 0);
            $chartRows[] = [
                'label' => $label,
                'value_label' => sprintf(_n('%d clic', '%d clics', $clicks, 'wp-rank-tracker'), $clicks),
                'width' => round(($clicks / $maxClicks) * 100, 2),
            ];
        }

        return $chartRows;
    }

    /**
     * @param array<string, mixed> $settings
     * @param array<string, mixed> $serpReport
     * @return array<int, array{label:string,value_label:string,width:float}>
     */
    private function build_serp_visibility_chart_rows(array $settings, array $serpReport): array {
        $keywords = is_array($settings['tracked_keywords'] ?? null) ? $settings['tracked_keywords'] : [];
        $targetDomain = $this->sanitize_domain((string) ($settings['target_domain'] ?? ''));

        if ($keywords === [] || $targetDomain === '' || empty($serpReport['rows'])) {
            return [];
        }

        $rows = [];
        foreach (array_slice($keywords, 0, 5) as $keyword) {
            $googleRows = array_values(array_filter(
                $serpReport['rows'],
                static fn(array $row): bool => (string) ($row['engine'] ?? '') === 'google' && (string) ($row['keyword'] ?? '') === (string) $keyword
            ));
            $rank = $this->find_domain_rank($targetDomain, $googleRows);
            $rows[] = [
                'label' => $this->shorten_label((string) $keyword),
                'value_label' => $rank > 0 ? '#' . $rank : __('Non detecte', 'wp-rank-tracker'),
                'width' => $rank > 0 ? max(8, 100 - (($rank - 1) * 8)) : 4,
            ];
        }

        return $rows;
    }

    /**
     * @param array<int, array<string, mixed>> $priorityOpportunities
     * @param array<int, array<string, mixed>> $googleTrendRows
     * @param array<int, array<string, string>> $serpComparisonRows
     * @return array<int, array{label:string,value:string}>
     */
    private function build_daily_summary(array $priorityOpportunities, array $googleTrendRows, array $serpComparisonRows): array {
        $upCount = count(array_filter($googleTrendRows, static fn(array $item): bool => str_contains((string) ($item['trend'] ?? ''), 'wrt-delta-up')));
        $downCount = count(array_filter($googleTrendRows, static fn(array $item): bool => str_contains((string) ($item['trend'] ?? ''), 'wrt-delta-down')));
        $highPriorityCount = count(array_filter($priorityOpportunities, static fn(array $item): bool => (string) ($item['priority'] ?? '') === 'high'));
        $competitorLeadCount = count(array_filter($serpComparisonRows, static fn(array $item): bool => str_contains((string) ($item['note'] ?? ''), 'concurrent')));

        return [
            [
                'value' => (string) $upCount,
                'label' => __('pages en hausse', 'wp-rank-tracker'),
            ],
            [
                'value' => (string) $downCount,
                'label' => __('pages en baisse', 'wp-rank-tracker'),
            ],
            [
                'value' => (string) $highPriorityCount,
                'label' => __('opportunites fortes', 'wp-rank-tracker'),
            ],
            [
                'value' => (string) $competitorLeadCount,
                'label' => __('mots-cles avec concurrent devant', 'wp-rank-tracker'),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $settings
     * @param array<int, array<string, mixed>> $priorityOpportunities
     * @return array<int, array{title:string,copy:string,label:string,url:string}>
     */
    private function build_next_actions(array $settings, bool $isConnected, string $selectedProperty, array $priorityOpportunities): array {
        $actions = [];

        if (!$isConnected) {
            $actions[] = [
                'title' => __('Connecter Google', 'wp-rank-tracker'),
                'copy' => __('On commence par relier Search Console pour passer de l estimation locale a la vision reelle de Google.', 'wp-rank-tracker'),
                'label' => __('Ouvrir Google', 'wp-rank-tracker'),
                'url' => admin_url('admin.php?page=' . self::MENU_SLUG_GOOGLE),
            ];
        } elseif ($selectedProperty === '') {
            $actions[] = [
                'title' => __('Choisir la propriete Search Console', 'wp-rank-tracker'),
                'copy' => __('Le compte Google est connecte, mais il faut encore selectionner le bon site a suivre.', 'wp-rank-tracker'),
                'label' => __('Choisir la propriete', 'wp-rank-tracker'),
                'url' => admin_url('admin.php?page=' . self::MENU_SLUG_GOOGLE),
            ];
        }

        if ((is_array($settings['tracked_keywords'] ?? null) ? $settings['tracked_keywords'] : []) === []) {
            $actions[] = [
                'title' => __('Ajouter les mots-cles suivis', 'wp-rank-tracker'),
                'copy' => __('Ajoute les expressions que tu veux vraiment faire progresser pour obtenir un suivi utile.', 'wp-rank-tracker'),
                'label' => __('Configurer les mots-cles', 'wp-rank-tracker'),
                'url' => admin_url('admin.php?page=' . self::MENU_SLUG_DATAFORSEO),
            ];
        }

        foreach ($priorityOpportunities as $opportunity) {
            if (empty($opportunity['actions'][0]['url']) || empty($opportunity['actions'][0]['label'])) {
                continue;
            }

            $actions[] = [
                'title' => (string) ($opportunity['title'] ?? __('Traiter une priorite', 'wp-rank-tracker')),
                'copy' => (string) ($opportunity['action'] ?? ''),
                'label' => (string) ($opportunity['actions'][0]['label'] ?? __('Ouvrir', 'wp-rank-tracker')),
                'url' => (string) ($opportunity['actions'][0]['url'] ?? admin_url('admin.php?page=' . self::MENU_SLUG_LOCAL)),
            ];
        }

        return array_slice($actions, 0, 3);
    }

    /**
     * @param array<int, array<string, mixed>> $googleTrendRows
     * @return array<int, array{title:string,trend:string,actions:array<int, array{label:string,url:string}>}>
     */
    private function build_alert_pages(array $googleTrendRows): array {
        $rows = [];

        foreach ($googleTrendRows as $row) {
            $trend = (string) ($row['trend'] ?? '');
            if (!str_contains($trend, 'wrt-delta-down')) {
                continue;
            }

            $rows[] = [
                'title' => $this->shorten_label((string) ($row['page'] ?? __('Sans titre', 'wp-rank-tracker')), 72),
                'trend' => $trend,
                'actions' => $this->build_actions_from_page_url((string) ($row['page'] ?? '')),
            ];
        }

        return array_slice($rows, 0, 3);
    }

    /**
     * @param array<int, array<string, mixed>> $priorityOpportunities
     * @return array<int, array{title:string,copy:string,priority:string,impact_label:string,actions:array<int, array{label:string,url:string}>}>
     */
    private function build_quick_wins(array $priorityOpportunities): array {
        $items = [];

        foreach (array_slice($priorityOpportunities, 0, 3) as $opportunity) {
            $priority = (string) ($opportunity['priority'] ?? 'medium');
            $items[] = [
                'title' => (string) ($opportunity['title'] ?? ''),
                'copy' => (string) ($opportunity['impact'] ?? ''),
                'priority' => $priority,
                'impact_label' => $priority === 'high' ? __('Impact fort', 'wp-rank-tracker') : ($priority === 'medium' ? __('Impact moyen', 'wp-rank-tracker') : __('Impact utile', 'wp-rank-tracker')),
                'actions' => is_array($opportunity['actions'] ?? null) ? array_slice($opportunity['actions'], 0, 2) : [],
            ];
        }

        return $items;
    }

    private function make_step(bool $done, string $title, string $copy): array {
        return [
            'status' => $done ? 'done' : 'todo',
            'badge' => $done ? __('OK', 'wp-rank-tracker') : __('A faire', 'wp-rank-tracker'),
            'title' => $title,
            'copy' => $copy,
        ];
    }

    /**
     * @param array<string, mixed> $settings
     * @param array<int, array<string, mixed>> $localPages
     * @param array<int, array<string, mixed>> $googleRows
     * @return array<int, array<string, string>>
     */
    private function build_market_watch_rows(array $settings, array $localPages, array $googleRows): array {
        $keywords = is_array($settings['tracked_keywords'] ?? null) ? $settings['tracked_keywords'] : [];
        $competitors = is_array($settings['competitors'] ?? null) ? $settings['competitors'] : [];

        $rows = [];
        foreach ($keywords as $keyword) {
            $localMatch = $this->find_best_local_page_for_keyword((string) $keyword, $localPages);
            $googleMatch = $this->find_best_google_row_for_keyword((string) $keyword, $googleRows);

            $rows[] = [
                'keyword' => (string) $keyword,
                'local_page' => $localMatch !== null ? (string) $localMatch['title'] : __('Aucune page locale claire', 'wp-rank-tracker'),
                'google_signal' => $googleMatch !== null
                    ? sprintf(__('Query vue, pos. %s', 'wp-rank-tracker'), $this->format_position((float) $googleMatch['position']))
                    : __('Pas encore observe dans Search Console', 'wp-rank-tracker'),
                'competitors' => $competitors !== [] ? implode(', ', array_slice($competitors, 0, 4)) : __('Aucun concurrent renseigne', 'wp-rank-tracker'),
                'strategy' => $this->build_strategy_note($localMatch, $googleMatch),
            ];
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private function get_serp_report(): array {
        $report = get_option(self::SERP_REPORT_OPTION_KEY, []);

        return $this->sanitize_serp_report(is_array($report) ? $report : []);
    }

    /**
     * @return array<string, mixed>
     */
    private function get_previous_serp_report(): array {
        $history = get_option(self::SERP_HISTORY_OPTION_KEY, []);
        if (!is_array($history) || $history === []) {
            return $this->empty_serp_report();
        }

        $previous = $history[0] ?? [];
        return $this->sanitize_serp_report(is_array($previous) ? $previous : []);
    }

    /**
     * @return array{attempted_at:string,keywords:array<int,string>,location_name:string,language_name:string,target_domain:string}
     */
    private function get_serp_request_debug(): array {
        $payload = get_option(self::SERP_REQUEST_DEBUG_OPTION_KEY, []);
        if (!is_array($payload)) {
            return [
                'attempted_at' => '',
                'keywords' => [],
                'location_name' => '',
                'language_name' => '',
                'target_domain' => '',
            ];
        }

        return [
            'attempted_at' => sanitize_text_field((string) ($payload['attempted_at'] ?? '')),
            'keywords' => array_values(array_filter(array_map(
                static fn($keyword): string => sanitize_text_field((string) $keyword),
                is_array($payload['keywords'] ?? null) ? $payload['keywords'] : []
            ))),
            'location_name' => sanitize_text_field((string) ($payload['location_name'] ?? '')),
            'language_name' => sanitize_text_field((string) ($payload['language_name'] ?? '')),
            'target_domain' => sanitize_text_field((string) ($payload['target_domain'] ?? '')),
        ];
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function store_serp_request_debug(array $settings): void {
        update_option(
            self::SERP_REQUEST_DEBUG_OPTION_KEY,
            [
                'attempted_at' => current_time('mysql'),
                'keywords' => is_array($settings['tracked_keywords'] ?? null) ? array_values($settings['tracked_keywords']) : [],
                'location_name' => (string) ($settings['dataforseo_location_name'] ?? ''),
                'language_name' => (string) ($settings['dataforseo_language_name'] ?? ''),
                'target_domain' => (string) ($settings['target_domain'] ?? ''),
            ],
            false
        );
    }

    /**
     * @param array<string, mixed> $report
     * @return array<string, mixed>
     */
    private function sanitize_serp_report(array $report): array {
        if ($report === []) {
            return $this->empty_serp_report();
        }

        $rows = [];
        foreach (($report['rows'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }

            $rows[] = [
                'engine' => sanitize_text_field((string) ($row['engine'] ?? '')),
                'keyword' => sanitize_text_field((string) ($row['keyword'] ?? '')),
                'rank' => (int) ($row['rank'] ?? 0),
                'domain' => sanitize_text_field((string) ($row['domain'] ?? '')),
                'url' => esc_url_raw((string) ($row['url'] ?? '')),
                'title' => sanitize_text_field((string) ($row['title'] ?? '')),
            ];
        }

        return [
            'fetched_at' => sanitize_text_field((string) ($report['fetched_at'] ?? '')),
            'keywords' => array_values(array_filter(array_map(
                static fn($keyword): string => sanitize_text_field((string) $keyword),
                is_array($report['keywords'] ?? null) ? $report['keywords'] : []
            ))),
            'location_name' => sanitize_text_field((string) ($report['location_name'] ?? '')),
            'language_name' => sanitize_text_field((string) ($report['language_name'] ?? '')),
            'rows' => $rows,
        ];
    }

    /**
     * @param array<string, mixed> $settings
     * @param array<string, mixed> $serpReport
     * @param array<string, mixed> $previousSerpReport
     * @return array<int, array<string, string>>
     */
    private function build_serp_comparison_rows(array $settings, array $serpReport, array $previousSerpReport): array {
        $keywords = is_array($settings['tracked_keywords'] ?? null) ? $settings['tracked_keywords'] : [];
        $competitors = is_array($settings['competitors'] ?? null) ? array_map([$this, 'sanitize_domain'], $settings['competitors']) : [];
        $targetDomain = $this->sanitize_domain((string) ($settings['target_domain'] ?? ''));

        if ($keywords === [] || $serpReport['rows'] === []) {
            return [];
        }

        $rows = [];
        $importedKeywords = is_array($serpReport['keywords'] ?? null) ? array_map('strval', $serpReport['keywords']) : [];
        foreach ($keywords as $keyword) {
            foreach (['google', 'bing'] as $engine) {
                $snapshotRows = array_values(array_filter(
                    $serpReport['rows'],
                    static fn(array $row): bool => (string) $row['keyword'] === (string) $keyword && (string) $row['engine'] === $engine
                ));
                $previousRows = array_values(array_filter(
                    $previousSerpReport['rows'],
                    static fn(array $row): bool => (string) $row['keyword'] === (string) $keyword && (string) $row['engine'] === $engine
                ));

                if ($snapshotRows === [] && !in_array((string) $keyword, $importedKeywords, true)) {
                    $rows[] = [
                        'keyword' => (string) $keyword,
                        'engine' => ucfirst($engine),
                        'target_rank' => '<strong>' . esc_html($targetDomain !== '' ? $targetDomain : __('Ton domaine', 'wp-rank-tracker')) . '</strong><br /><span class="wrt-rank-line">' . esc_html__('Pas dans le dernier import', 'wp-rank-tracker') . '</span>',
                        'competitors' => esc_html__('Aucune donnee pour ce mot-cle dans le dernier import', 'wp-rank-tracker'),
                        'podium' => esc_html__('Import a relancer', 'wp-rank-tracker'),
                        'note' => esc_html__('Ce mot-cle est bien dans ta liste actuelle, mais il n apparait pas dans le dernier snapshot SERP enregistre. Relance "Enregistrer et analyser maintenant".', 'wp-rank-tracker'),
                    ];
                    continue;
                }

                $targetRank = $this->find_domain_rank($targetDomain, $snapshotRows);
                $previousTargetRank = $this->find_domain_rank($targetDomain, $previousRows);
                $competitorRows = $this->build_competitor_rank_rows($competitors, $snapshotRows, $previousRows);
                $bestCompetitorRank = $this->extract_best_competitor_rank($competitorRows);

                $rows[] = [
                    'keyword' => (string) $keyword,
                    'engine' => ucfirst($engine),
                    'target_rank' => $this->format_rank_with_delta($targetDomain !== '' ? $targetDomain : __('Ton domaine', 'wp-rank-tracker'), $targetRank, $previousTargetRank),
                    'competitors' => $this->format_competitor_rows($competitorRows),
                    'podium' => $this->build_serp_podium($snapshotRows),
                    'note' => $this->build_serp_note($targetRank, $previousTargetRank, $bestCompetitorRank),
                ];
            }
        }

        return $rows;
    }

    private function get_oauth_redirect_uri(): string {
        return admin_url('admin-post.php?action=wp_rank_tracker_google_oauth_callback');
    }

    private function oauth_state_key(): string {
        return self::OAUTH_TRANSIENT_PREFIX . get_current_user_id();
    }

    /**
     * @return array<string, mixed>
     */
    private function analyze_post_keywords(WP_Post $post): array {
        $title = trim(wp_strip_all_tags(get_the_title($post)));
        $url = get_permalink($post);
        $slug = (string) $post->post_name;
        $excerpt = trim(wp_strip_all_tags($post->post_excerpt));
        $content = trim(preg_replace('/\s+/', ' ', wp_strip_all_tags((string) $post->post_content)));
        $renderedContent = $this->render_post_content($post);
        $headings = $this->extract_headings($renderedContent);
        $intro = $this->extract_intro_text($renderedContent);

        $weightedTerms = [];
        $this->boost_term_weights($weightedTerms, $title, 5);
        $this->boost_term_weights($weightedTerms, str_replace(['-', '_'], ' ', $slug), 4);
        $this->boost_term_weights($weightedTerms, implode(' ', $headings['h1']), 4);
        $this->boost_term_weights($weightedTerms, implode(' ', $headings['h2']), 3);
        $this->boost_term_weights($weightedTerms, implode(' ', $headings['h3']), 2);
        $this->boost_term_weights($weightedTerms, $excerpt, 2);
        $this->boost_term_weights($weightedTerms, $intro, 2);
        $this->boost_term_weights($weightedTerms, $content, 1);

        $topTerms = $this->extract_top_terms_from_weights($weightedTerms);
        $titlePhrase = $this->normalize_phrase($title);
        $slugPhrase = $this->normalize_phrase(str_replace(['-', '_'], ' ', $slug));
        $h1Phrase = $this->normalize_phrase(implode(' ', $headings['h1']));
        $h2Phrase = $this->normalize_phrase(implode(' ', array_slice($headings['h2'], 0, 2)));

        $primaryKeyword = $this->pick_primary_keyword($titlePhrase, $h1Phrase, $h2Phrase, $slugPhrase, $topTerms);
        $secondaryKeywords = array_values(array_filter([$h1Phrase, $h2Phrase, $slugPhrase, ...$topTerms], fn(string $value): bool => $value !== '' && $value !== $primaryKeyword && !$this->is_generic_page_label($value)));
        $secondaryKeywords = array_slice(array_unique($secondaryKeywords), 0, 3);

        $recommendations = $this->build_local_recommendations($title, $slugPhrase, $content, $topTerms, $headings, $primaryKeyword);
        $actions = $this->build_post_actions($post, is_string($url) ? $url : '');

        return [
            'post_id' => (int) $post->ID,
            'title' => $title !== '' ? $title : __('Sans titre', 'wp-rank-tracker'),
            'url' => is_string($url) ? $url : '',
            'type_label' => $post->post_type === 'page' ? __('Page', 'wp-rank-tracker') : __('Article', 'wp-rank-tracker'),
            'primary_keyword' => $primaryKeyword,
            'secondary_keywords' => $secondaryKeywords !== [] ? $secondaryKeywords : [__('Aucun terme fort detecte', 'wp-rank-tracker')],
            'recommendations' => $recommendations,
            'actions' => $actions,
        ];
    }

    /**
     * @return string[]
     */
    private function extract_top_terms_from_weights(array $weights): array {
        if ($weights === []) {
            return [];
        }

        arsort($weights);

        return array_slice(array_keys($weights), 0, 4);
    }

    /**
     * @return string[]
     */
    private function tokenize_text(string $text): array {
        $text = remove_accents(strtolower($text));
        $text = preg_replace('/[^a-z0-9\s-]/', ' ', $text);
        $parts = preg_split('/\s+/', (string) $text);

        $stopWords = [
            'alors', 'apres', 'avec', 'avoir', 'bien', 'cette', 'dans', 'des', 'elle', 'elles', 'etre', 'faire',
            'font', 'home', 'html', 'http', 'https', 'pour', 'plus', 'page', 'pages', 'post', 'sans', 'site',
            'sur', 'tes', 'ton', 'une', 'vos', 'votre', 'vous', 'the', 'and', 'les', 'aux', 'est', 'par', 'qui',
            'que', 'quoi', 'mais', 'nos', 'notre', 'leur', 'leurs', 'ses', 'son', 'titre', 'article', 'blog',
            'from', 'this', 'that', 'your', 'wordpress',
        ];

        $tokens = [];
        foreach ($parts as $part) {
            if ($part === '' || strlen($part) < 3 || in_array($part, $stopWords, true) || ctype_digit($part)) {
                continue;
            }

            $tokens[] = $part;
        }

        return $tokens;
    }

    private function normalize_phrase(string $text): string {
        $tokens = $this->tokenize_text($text);
        if ($tokens === []) {
            return '';
        }

        return implode(' ', array_slice($tokens, 0, 4));
    }

    /**
     * @param string[] $topTerms
     */
    private function pick_primary_keyword(string $titlePhrase, string $h1Phrase, string $h2Phrase, string $slugPhrase, array $topTerms): string {
        $candidates = [$h1Phrase, $titlePhrase, $h2Phrase, $slugPhrase, ...$topTerms];

        foreach ($candidates as $candidate) {
            if ($candidate === '' || $this->is_generic_page_label($candidate)) {
                continue;
            }

            return $candidate;
        }

        return __('A definir', 'wp-rank-tracker');
    }

    private function is_generic_page_label(string $phrase): bool {
        $normalized = trim(remove_accents(strtolower($phrase)));
        if ($normalized === '') {
            return true;
        }

        $genericLabels = [
            'accueil',
            'home',
            'homepage',
            'index',
            'landing',
            'page accueil',
            'page home',
            'bienvenue',
        ];

        return in_array($normalized, $genericLabels, true);
    }

    /**
     * @param string[] $topTerms
     * @param array<string, string[]> $headings
     * @return array<int, array{issue:string,why:string,action:string,priority:string,priority_label:string,area:string,area_label:string}>
     */
    private function build_local_recommendations(string $title, string $slugPhrase, string $content, array $topTerms, array $headings, string $primaryKeyword): array {
        $recommendations = [];
        $titleTokens = $this->tokenize_text($title);
        $focusTokens = $this->tokenize_text($primaryKeyword);
        $sharedTitleFocus = array_intersect($titleTokens, $focusTokens);

        if ($this->is_generic_page_label($this->normalize_phrase($title)) || count($titleTokens) < 2 || ($focusTokens !== [] && count($sharedTitleFocus) === 0)) {
            $recommendedFocus = $primaryKeyword !== '' && !$this->is_generic_page_label($primaryKeyword) ? $primaryKeyword : __('ton sujet principal', 'wp-rank-tracker');
            $recommendations[] = $this->make_recommendation(
                __('Le titre n exprime pas clairement le sujet cible.', 'wp-rank-tracker'),
                sprintf(
                    __('Le titre actuel ("%s") ne reprend pas assez le mot-cle principal detecte.', 'wp-rank-tracker'),
                    $title !== '' ? $title : __('Sans titre', 'wp-rank-tracker')
                ),
                sprintf(
                    __('Dans WordPress, modifie le titre de la page pour faire apparaitre clairement "%s".', 'wp-rank-tracker'),
                    $recommendedFocus
                ),
                'high',
                'title'
            );
        }

        if ($slugPhrase === '') {
            $recommendations[] = $this->make_recommendation(
                __('Le slug n aide pas a comprendre le sujet de la page.', 'wp-rank-tracker'),
                __('L URL actuelle ne fournit pas de signal semantique exploitable.', 'wp-rank-tracker'),
                __('Dans WordPress, ouvre le permalien de la page et utilise un slug descriptif lie au sujet principal.', 'wp-rank-tracker'),
                'medium',
                'slug'
            );
        }

        if (str_word_count($content) < 120) {
            $recommendations[] = $this->make_recommendation(
                __('Le contenu est trop court pour bien poser l intention SEO.', 'wp-rank-tracker'),
                __('La page manque de matière pour expliquer clairement le sujet, ses usages et sa valeur.', 'wp-rank-tracker'),
                __('Dans l editeur WordPress ou ton builder, ajoute davantage de contenu utile : explication, benefices, cas d usage et reponses aux questions frequentes.', 'wp-rank-tracker'),
                'high',
                'content'
            );
        }

        if ($headings['h1'] === []) {
            $recommendations[] = $this->make_recommendation(
                __('Aucun H1 clair n a ete detecte.', 'wp-rank-tracker'),
                __('Sans H1, le sujet principal de la page est moins explicite pour l analyse SEO.', 'wp-rank-tracker'),
                __('Dans la page, ajoute un H1 unique et explicite, aligne sur le sujet principal detecte.', 'wp-rank-tracker'),
                'high',
                'h1'
            );
        }

        if ($headings['h2'] === []) {
            $recommendations[] = $this->make_recommendation(
                __('La page manque de sous-titres H2.', 'wp-rank-tracker'),
                __('Sans H2, le contenu reste peu structure et couvre moins bien les sous-themes du sujet.', 'wp-rank-tracker'),
                __('Ajoute 2 a 4 H2 pour decouper la page en sections claires : presentation, fonctionnement, benefices, questions frequentes, etc.', 'wp-rank-tracker'),
                'medium',
                'h2'
            );
        }

        if (count($topTerms) < 2) {
            $recommendations[] = $this->make_recommendation(
                __('Le champ lexical semble trop pauvre.', 'wp-rank-tracker'),
                __('Le contenu reprend peu de variantes ou de termes proches du sujet principal.', 'wp-rank-tracker'),
                __('Dans le texte, ajoute des formulations proches du sujet principal et des termes complementaires que ton audience pourrait rechercher.', 'wp-rank-tracker'),
                'medium',
                'semantic'
            );
        }

        if ($recommendations === []) {
            $recommendations[] = $this->make_recommendation(
                __('Base locale coherente.', 'wp-rank-tracker'),
                __('Les principaux signaux de structure sont presents et alignes.', 'wp-rank-tracker'),
                __('Passe maintenant a la comparaison avec Google Search Console pour confronter cette lecture locale aux requetes reelles.', 'wp-rank-tracker'),
                'low',
                'overview'
            );
        }

        return array_slice($recommendations, 0, 3);
    }

    private function get_current_section(): string {
        $page = sanitize_key((string) ($_GET['page'] ?? self::MENU_SLUG));

        if ($page === self::MENU_SLUG_LOCAL) {
            return 'local';
        }

        if ($page === self::MENU_SLUG_GOOGLE) {
            return 'google';
        }

        if ($page === self::MENU_SLUG_DATAFORSEO) {
            return 'dataforseo';
        }

        return 'dashboard';
    }

    /**
     * @return array{issue:string,why:string,action:string,priority:string,priority_label:string,area:string,area_label:string}
     */
    private function make_recommendation(string $issue, string $why, string $action, string $priority, string $area): array {
        $priorityLabels = [
            'high' => __('Priorite haute', 'wp-rank-tracker'),
            'medium' => __('Priorite moyenne', 'wp-rank-tracker'),
            'low' => __('Priorite basse', 'wp-rank-tracker'),
        ];

        $areaLabels = [
            'title' => __('Zone : titre WordPress', 'wp-rank-tracker'),
            'slug' => __('Zone : permalien', 'wp-rank-tracker'),
            'content' => __('Zone : contenu', 'wp-rank-tracker'),
            'h1' => __('Zone : H1', 'wp-rank-tracker'),
            'h2' => __('Zone : H2', 'wp-rank-tracker'),
            'semantic' => __('Zone : champ lexical', 'wp-rank-tracker'),
            'overview' => __('Zone : page globale', 'wp-rank-tracker'),
        ];

        return [
            'issue' => $issue,
            'why' => $why,
            'action' => $action,
            'priority' => $priority,
            'priority_label' => $priorityLabels[$priority] ?? $priorityLabels['medium'],
            'area' => $area,
            'area_label' => $areaLabels[$area] ?? $areaLabels['overview'],
        ];
    }

    /**
     * @return array{title:string,page:string,why:string,action:string,impact:string,priority:string,priority_label:string}
     */
    private function make_opportunity(string $title, string $page, string $why, string $action, string $impact, string $priority, array $actions = []): array {
        $priorityLabels = [
            'high' => __('A faire en premier', 'wp-rank-tracker'),
            'medium' => __('A faire ensuite', 'wp-rank-tracker'),
            'low' => __('A surveiller', 'wp-rank-tracker'),
        ];

        return [
            'title' => $title,
            'page' => $page,
            'why' => $why,
            'action' => $action,
            'impact' => $impact,
            'priority' => $priority,
            'priority_label' => $priorityLabels[$priority] ?? $priorityLabels['medium'],
            'actions' => $actions,
        ];
    }

    private function render_post_content(WP_Post $post): string {
        $content = (string) $post->post_content;

        if (has_blocks($content)) {
            $content = do_blocks($content);
        }

        if (function_exists('apply_shortcodes')) {
            $content = apply_shortcodes($content);
        } else {
            $content = do_shortcode($content);
        }

        $builderContent = $this->extract_builder_content($post);
        if ($builderContent !== '') {
            $content .= "\n" . $builderContent;
        }

        return $content;
    }

    /**
     * @return array<int, array{label:string,url:string}>
     */
    private function build_post_actions(WP_Post $post, string $viewUrl): array {
        $actions = [];
        $editUrl = get_edit_post_link($post->ID, 'raw');
        if (is_string($editUrl) && $editUrl !== '') {
            $actions[] = [
                'label' => __('Modifier', 'wp-rank-tracker'),
                'url' => $editUrl,
            ];
        }

        $builderAction = $this->get_builder_action($post, $viewUrl);
        if ($builderAction !== null) {
            $actions[] = $builderAction;
        }

        if ($viewUrl !== '') {
            $actions[] = [
                'label' => __('Voir la page', 'wp-rank-tracker'),
                'url' => $viewUrl,
            ];
        }

        return $actions;
    }

    /**
     * @return array{label:string,url:string}|null
     */
    private function get_builder_action(WP_Post $post, string $viewUrl): ?array {
        $elementorData = get_post_meta($post->ID, '_elementor_data', true);
        if (is_string($elementorData) && trim($elementorData) !== '') {
            return [
                'label' => __('Ouvrir Elementor', 'wp-rank-tracker'),
                'url' => admin_url('post.php?post=' . $post->ID . '&action=elementor'),
            ];
        }

        $diviEnabled = get_post_meta($post->ID, '_et_pb_use_builder', true);
        if ($diviEnabled === 'on' && $viewUrl !== '') {
            return [
                'label' => __('Ouvrir Divi', 'wp-rank-tracker'),
                'url' => add_query_arg([
                    'et_fb' => '1',
                    'PageSpeed' => 'off',
                ], $viewUrl),
            ];
        }

        return null;
    }

    /**
     * @param array<int, array<string, mixed>> $localPages
     * @return array<int, array{label:string,url:string}>
     */
    private function find_page_actions_by_url(string $url, array $localPages): array {
        $path = $this->normalize_url_path($url);
        if ($path === '') {
            return [];
        }

        foreach ($localPages as $page) {
            if ($this->normalize_url_path((string) ($page['url'] ?? '')) !== $path) {
                continue;
            }

            return is_array($page['actions'] ?? null) ? $page['actions'] : [];
        }

        return [];
    }

    /**
     * @return array<int, array{label:string,url:string}>
     */
    private function build_actions_from_page_url(string $url): array {
        $postId = url_to_postid($url);
        if ($postId <= 0) {
            return [];
        }

        $post = get_post($postId);
        if (!$post instanceof WP_Post) {
            return [];
        }

        $viewUrl = get_permalink($post);
        return $this->build_post_actions($post, is_string($viewUrl) ? $viewUrl : '');
    }

    private function shorten_label(string $label, int $limit = 48): string {
        $label = trim($label);
        if ($label === '') {
            return __('Sans libelle', 'wp-rank-tracker');
        }

        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            return mb_strlen($label) > $limit ? mb_substr($label, 0, $limit - 1) . '…' : $label;
        }

        return strlen($label) > $limit ? substr($label, 0, $limit - 3) . '...' : $label;
    }

    private function extract_builder_content(WP_Post $post): string {
        $parts = [];

        $elementorContent = $this->extract_elementor_content($post);
        if ($elementorContent !== '') {
            $parts[] = $elementorContent;
        }

        return implode("\n", $parts);
    }

    private function extract_elementor_content(WP_Post $post): string {
        $raw = get_post_meta($post->ID, '_elementor_data', true);
        if (!is_string($raw) || trim($raw) === '') {
            return '';
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return '';
        }

        $fragments = [];
        $this->walk_elementor_nodes($decoded, $fragments);

        return implode("\n", array_filter($fragments, static fn(string $value): bool => trim($value) !== ''));
    }

    /**
     * @param array<int|string, mixed> $nodes
     * @param string[] $fragments
     */
    private function walk_elementor_nodes(array $nodes, array &$fragments): void {
        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }

            $settings = isset($node['settings']) && is_array($node['settings']) ? $node['settings'] : [];
            $widgetType = isset($node['widgetType']) ? (string) $node['widgetType'] : '';

            $headingText = $this->extract_elementor_setting_text($settings, ['title', 'heading', 'text', 'editor', 'content']);
            $headingTag = $this->extract_elementor_heading_tag($settings);

            if ($widgetType === 'heading' && $headingText !== '') {
                $fragments[] = sprintf('<%1$s>%2$s</%1$s>', $headingTag, esc_html($headingText));
            } else {
                foreach ($this->extract_elementor_text_fragments($settings) as $fragment) {
                    $fragments[] = $fragment;
                }
            }

            if (isset($node['elements']) && is_array($node['elements'])) {
                $this->walk_elementor_nodes($node['elements'], $fragments);
            }
        }
    }

    /**
     * @param array<string, mixed> $settings
     * @return string[]
     */
    private function extract_elementor_text_fragments(array $settings): array {
        $fragments = [];
        foreach ($settings as $key => $value) {
            if (!is_string($key)) {
                continue;
            }

            if (is_string($value)) {
                $text = trim(wp_strip_all_tags($value));
                if ($text === '') {
                    continue;
                }

                if (str_contains($key, 'title') || str_contains($key, 'heading')) {
                    $fragments[] = '<h2>' . esc_html($text) . '</h2>';
                } elseif (str_contains($key, 'text') || str_contains($key, 'editor') || str_contains($key, 'content') || str_contains($key, 'description')) {
                    $fragments[] = '<p>' . esc_html($text) . '</p>';
                }
            } elseif (is_array($value)) {
                foreach ($this->extract_elementor_text_fragments($value) as $fragment) {
                    $fragments[] = $fragment;
                }
            }
        }

        return $fragments;
    }

    /**
     * @param array<string, mixed> $settings
     * @param string[] $keys
     */
    private function extract_elementor_setting_text(array $settings, array $keys): string {
        foreach ($keys as $key) {
            if (!isset($settings[$key]) || !is_string($settings[$key])) {
                continue;
            }

            $text = trim(wp_strip_all_tags($settings[$key]));
            if ($text !== '') {
                return $text;
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function extract_elementor_heading_tag(array $settings): string {
        $candidates = ['header_size', 'title_tag', 'html_tag'];
        foreach ($candidates as $key) {
            if (!isset($settings[$key]) || !is_string($settings[$key])) {
                continue;
            }

            $tag = strtolower(trim($settings[$key]));
            if (in_array($tag, ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'], true)) {
                return $tag;
            }
        }

        return 'h2';
    }

    /**
     * @return array<string, string[]>
     */
    private function extract_headings(string $html): array {
        $headings = [
            'h1' => [],
            'h2' => [],
            'h3' => [],
        ];

        if ($html === '') {
            return $headings;
        }

        foreach (['h1', 'h2', 'h3'] as $level) {
            if (preg_match_all('/<' . $level . '[^>]*>(.*?)<\/' . $level . '>/is', $html, $matches)) {
                $headings[$level] = array_values(array_filter(array_map(
                    static fn(string $value): string => trim(wp_strip_all_tags($value)),
                    $matches[1]
                )));
            }
        }

        return $headings;
    }

    private function extract_intro_text(string $html): string {
        if ($html === '') {
            return '';
        }

        if (preg_match('/<p[^>]*>(.*?)<\/p>/is', $html, $matches)) {
            return trim(preg_replace('/\s+/', ' ', wp_strip_all_tags($matches[1])));
        }

        return trim(preg_replace('/\s+/', ' ', wp_strip_all_tags($html)));
    }

    private function boost_term_weights(array &$weights, string $text, int $weight): void {
        $tokens = $this->tokenize_text($text);
        foreach ($tokens as $token) {
            $weights[$token] = ($weights[$token] ?? 0) + $weight;
        }
    }

    private function normalize_url_path(string $url): string {
        $path = (string) wp_parse_url($url, PHP_URL_PATH);
        $path = trim($path);

        if ($path === '') {
            return '/';
        }

        return untrailingslashit($path) === '' ? '/' : untrailingslashit($path);
    }

    /**
     * @return array{0:string,1:string}
     */
    private function compare_keywords(string $localKeyword, string $googleQuery): array {
        $localTokens = array_values(array_unique($this->tokenize_text($localKeyword)));
        $googleTokens = array_values(array_unique($this->tokenize_text($googleQuery)));

        if ($localTokens === [] || $googleTokens === []) {
            return ['empty', __('A completer', 'wp-rank-tracker')];
        }

        $shared = array_intersect($localTokens, $googleTokens);
        $sharedCount = count($shared);

        if ($sharedCount >= min(count($localTokens), count($googleTokens))) {
            return ['strong', __('Tres proche', 'wp-rank-tracker')];
        }

        if ($sharedCount >= 2) {
            return ['partial', __('Partiellement aligne', 'wp-rank-tracker')];
        }

        return ['weak', __('A retravailler', 'wp-rank-tracker')];
    }

    /**
     * @param array<int, array<string, mixed>> $localPages
     * @return array<string, mixed>|null
     */
    private function find_best_local_page_for_keyword(string $keyword, array $localPages): ?array {
        $keywordTokens = $this->tokenize_text($keyword);
        $best = null;
        $bestScore = 0;

        foreach ($localPages as $page) {
            $haystack = implode(' ', array_merge(
                [(string) ($page['primary_keyword'] ?? '')],
                is_array($page['secondary_keywords'] ?? null) ? $page['secondary_keywords'] : []
            ));
            $tokens = $this->tokenize_text($haystack);
            $score = count(array_intersect($keywordTokens, $tokens));
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $page;
            }
        }

        return $bestScore > 0 ? $best : null;
    }

    /**
     * @param array<int, array<string, mixed>> $googleRows
     * @return array<string, mixed>|null
     */
    private function find_best_google_row_for_keyword(string $keyword, array $googleRows): ?array {
        $keywordTokens = $this->tokenize_text($keyword);
        $best = null;
        $bestScore = 0;

        foreach ($googleRows as $row) {
            $tokens = $this->tokenize_text((string) ($row['query'] ?? ''));
            $score = count(array_intersect($keywordTokens, $tokens));
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $row;
            }
        }

        return $bestScore > 0 ? $best : null;
    }

    /**
     * @param array<string, mixed>|null $localMatch
     * @param array<string, mixed>|null $googleMatch
     */
    private function build_strategy_note(?array $localMatch, ?array $googleMatch): string {
        if ($localMatch !== null && $googleMatch !== null) {
            return __('Le sujet existe deja localement et commence a remonter dans Google. Base ideale pour comparer ensuite aux concurrents.', 'wp-rank-tracker');
        }

        if ($localMatch !== null) {
            return __('Le site semble cible ce sujet, mais Google ne le remonte pas encore clairement. Priorite a l optimisation puis au suivi.', 'wp-rank-tracker');
        }

        if ($googleMatch !== null) {
            return __('Google voit deja une requete proche, mais le focus local n est pas clair. Aligner la page cible avec cette intention.', 'wp-rank-tracker');
        }

        return __('Sujet encore peu structure. Identifier ou creer la meilleure page cible avant le vrai comparatif concurrentiel.', 'wp-rank-tracker');
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function find_domain_rank(string $domain, array $rows): int {
        if ($domain === '') {
            return 0;
        }

        foreach ($rows as $row) {
            if ($this->sanitize_domain((string) ($row['domain'] ?? '')) === $domain) {
                return (int) ($row['rank'] ?? 0);
            }
        }

        return 0;
    }

    /**
     * @param string[] $competitors
     * @param array<int, array<string, mixed>> $rows
     * @return array{rank:int,label:string}
     */
    private function build_competitor_rank_rows(array $competitors, array $rows, array $previousRows): array {
        $items = [];

        foreach ($competitors as $competitor) {
            if ($competitor === '') {
                continue;
            }

            $currentRank = $this->find_domain_rank($competitor, $rows);
            $previousRank = $this->find_domain_rank($competitor, $previousRows);

            $items[] = [
                'domain' => $competitor,
                'rank' => $currentRank,
                'label' => $this->format_rank_with_delta($competitor, $currentRank, $previousRank),
            ];
        }

        return $items;
    }

    private function extract_best_competitor_rank(array $competitorRows): int {
        $bestRank = 0;

        foreach ($competitorRows as $row) {
            $rank = (int) ($row['rank'] ?? 0);
            if ($rank > 0 && ($bestRank === 0 || $rank < $bestRank)) {
                $bestRank = $rank;
            }
        }

        return $bestRank;
    }

    private function format_competitor_rows(array $competitorRows): string {
        if ($competitorRows === []) {
            return esc_html__('Aucun concurrent renseigne', 'wp-rank-tracker');
        }

        $chunks = [];
        foreach ($competitorRows as $row) {
            $chunks[] = (string) ($row['label'] ?? '');
        }

        return implode('<br />', array_filter($chunks));
    }

    private function format_rank_with_delta(string $domainLabel, int $currentRank, int $previousRank): string {
        $rankLabel = $currentRank > 0 ? '#' . $currentRank : sprintf(__('Non detecte dans le top %d', 'wp-rank-tracker'), max(20, (int) ($this->get_settings()['dataforseo_depth'] ?? 20)));
        $delta = $this->build_rank_delta_badge($currentRank, $previousRank);

        return sprintf(
            '<strong>%1$s</strong><br /><span class="wrt-rank-line">%2$s</span>%3$s',
            esc_html($domainLabel),
            esc_html($rankLabel),
            $delta !== '' ? ' ' . $delta : ''
        );
    }

    private function build_rank_delta_badge(int $currentRank, int $previousRank): string {
        if ($currentRank === 0 && $previousRank === 0) {
            return '';
        }

        if ($previousRank === 0 && $currentRank > 0) {
            return sprintf('<span class="wrt-delta wrt-delta-up">%s</span>', esc_html__('↗ nouvelle entree', 'wp-rank-tracker'));
        }

        if ($currentRank === 0 && $previousRank > 0) {
            return sprintf('<span class="wrt-delta wrt-delta-down">%s</span>', esc_html__('↘ sortie du top', 'wp-rank-tracker'));
        }

        $delta = $previousRank - $currentRank;
        if ($delta > 0) {
            return sprintf(
                '<span class="wrt-delta wrt-delta-up">%s</span>',
                esc_html(sprintf(_n('↑ %d place gagnee', '↑ %d places gagnees', $delta, 'wp-rank-tracker'), $delta))
            );
        }

        if ($delta < 0) {
            $lost = abs($delta);
            return sprintf(
                '<span class="wrt-delta wrt-delta-down">%s</span>',
                esc_html(sprintf(_n('↓ %d place perdue', '↓ %d places perdues', $lost, 'wp-rank-tracker'), $lost))
            );
        }

        return sprintf('<span class="wrt-delta wrt-delta-stable">%s</span>', esc_html__('→ stable', 'wp-rank-tracker'));
    }

    private function build_google_trend_badge(float $currentPosition, float $previousPosition, int $currentClicks, int $previousClicks): string {
        if ($currentPosition <= 0 && $previousPosition <= 0) {
            return esc_html__('Aucun recul encore', 'wp-rank-tracker');
        }

        $parts = [];

        if ($previousPosition <= 0 && $currentPosition > 0) {
            $parts[] = sprintf('<span class="wrt-delta wrt-delta-up">%s</span>', esc_html__('↗ nouvelle page visible', 'wp-rank-tracker'));
        } else {
            $positionDelta = $previousPosition - $currentPosition;
            if ($positionDelta > 0.09) {
                $parts[] = sprintf('<span class="wrt-delta wrt-delta-up">%s</span>', esc_html(sprintf(__('↑ %s position', 'wp-rank-tracker'), number_format_i18n($positionDelta, 1))));
            } elseif ($positionDelta < -0.09) {
                $parts[] = sprintf('<span class="wrt-delta wrt-delta-down">%s</span>', esc_html(sprintf(__('↓ %s position', 'wp-rank-tracker'), number_format_i18n(abs($positionDelta), 1))));
            } else {
                $parts[] = sprintf('<span class="wrt-delta wrt-delta-stable">%s</span>', esc_html__('→ position stable', 'wp-rank-tracker'));
            }
        }

        $clickDelta = $currentClicks - $previousClicks;
        if ($clickDelta > 0) {
            $parts[] = sprintf('<span class="wrt-delta wrt-delta-up">%s</span>', esc_html(sprintf(_n('+%d clic', '+%d clics', $clickDelta, 'wp-rank-tracker'), $clickDelta)));
        } elseif ($clickDelta < 0) {
            $loss = abs($clickDelta);
            $parts[] = sprintf('<span class="wrt-delta wrt-delta-down">%s</span>', esc_html(sprintf(_n('-%d clic', '-%d clics', $loss, 'wp-rank-tracker'), $loss)));
        }

        return implode(' ', $parts);
    }

    private function build_serp_podium(array $rows): string {
        if ($rows === []) {
            return esc_html__('Aucun resultat', 'wp-rank-tracker');
        }

        usort(
            $rows,
            static fn(array $left, array $right): int => ((int) ($left['rank'] ?? 0)) <=> ((int) ($right['rank'] ?? 0))
        );

        $topRows = array_slice($rows, 0, 3);
        $chunks = [];
        foreach ($topRows as $row) {
            $rank = (int) ($row['rank'] ?? 0);
            $domain = $this->sanitize_domain((string) ($row['domain'] ?? ''));
            $chunks[] = sprintf(
                '<span class="wrt-podium-item"><strong>#%1$d</strong> %2$s</span>',
                $rank,
                esc_html($domain !== '' ? $domain : __('Resultat non identifie', 'wp-rank-tracker'))
            );
        }

        return implode('<br />', $chunks);
    }

    private function build_serp_note(int $targetRank, int $previousTargetRank, int $competitorRank): string {
        $trend = $this->build_rank_delta_badge($targetRank, $previousTargetRank);

        if ($targetRank > 0 && ($competitorRank === 0 || $targetRank < $competitorRank)) {
            $message = __('Ton site passe devant les concurrents suivis sur ce mot-cle.', 'wp-rank-tracker');
        } elseif ($targetRank > 0 && $competitorRank > 0) {
            $message = __('Au moins un concurrent suivi passe encore devant ton site sur cette SERP.', 'wp-rank-tracker');
        } elseif ($targetRank === 0 && $competitorRank > 0) {
            $message = __('Tes concurrents sont visibles, mais ton site n apparait pas encore dans la profondeur analysee.', 'wp-rank-tracker');
        } else {
            $message = __('Aucun domaine suivi n a ete detecte dans la profondeur analysee.', 'wp-rank-tracker');
        }

        if ($trend === '') {
            return esc_html($message);
        }

        return $trend . '<br /><span class="wrt-trend-copy">' . esc_html($message) . '</span>';
    }

    private function empty_report(): array {
        return [
            'fetched_at' => '',
            'start_date' => '',
            'end_date' => '',
            'property_uri' => '',
            'rows' => [],
        ];
    }

    private function empty_serp_report(): array {
        return [
            'fetched_at' => '',
            'keywords' => [],
            'location_name' => '',
            'language_name' => '',
            'rows' => [],
        ];
    }

    /**
     * @param array<string, mixed> $report
     */
    private function store_serp_report(array $report): void {
        $current = $this->get_serp_report();
        $history = get_option(self::SERP_HISTORY_OPTION_KEY, []);

        if (!is_array($history)) {
            $history = [];
        }

        if (($current['fetched_at'] ?? '') !== '' && !empty($current['rows'])) {
            $sameTimestamp = (string) ($current['fetched_at'] ?? '') === (string) ($report['fetched_at'] ?? '');
            if (!$sameTimestamp) {
                array_unshift($history, $current);
            }
        }

        $history = array_values(array_filter($history, static fn($item): bool => is_array($item)));
        $history = array_slice($history, 0, 14);

        update_option(self::SERP_REPORT_OPTION_KEY, $report, false);
        update_option(self::SERP_HISTORY_OPTION_KEY, $history, false);
    }

    /**
     * @param array<string, mixed> $report
     */
    private function store_google_report(array $report): void {
        $current = $this->get_report();
        $history = get_option(self::REPORT_HISTORY_OPTION_KEY, []);

        if (!is_array($history)) {
            $history = [];
        }

        if (($current['fetched_at'] ?? '') !== '' && !empty($current['rows'])) {
            $sameTimestamp = (string) ($current['fetched_at'] ?? '') === (string) ($report['fetched_at'] ?? '');
            if (!$sameTimestamp) {
                array_unshift($history, $current);
            }
        }

        $history = array_values(array_filter($history, static fn($item): bool => is_array($item)));
        $history = array_slice($history, 0, 14);

        update_option(self::REPORT_OPTION_KEY, $report, false);
        update_option(self::REPORT_HISTORY_OPTION_KEY, $history, false);
    }

    private function format_ctr(float $ctr): string {
        return number_format_i18n($ctr * 100, 2) . '%';
    }

    private function format_position(float $position): string {
        if ($position <= 0) {
            return '-';
        }

        return (string) number_format_i18n($position, 1);
    }

    private function render_notice(): void {
        $notice = sanitize_key((string) ($_GET['wrt_notice'] ?? ''));
        $message = isset($_GET['wrt_message']) ? sanitize_text_field(wp_unslash((string) $_GET['wrt_message'])) : '';

        if ($notice === '') {
            return;
        }

        $messages = [
            'settings-saved' => __('Configuration enregistree.', 'wp-rank-tracker'),
            'settings-and-import-success' => __('Configuration enregistree et import Search Console lance automatiquement.', 'wp-rank-tracker'),
            'settings-and-serp-success' => __('Configuration enregistree et import SERP externe lance automatiquement.', 'wp-rank-tracker'),
            'local-refresh-success' => __('Bilan local mis a jour.', 'wp-rank-tracker'),
            'import-success' => __('Import Search Console termine.', 'wp-rank-tracker'),
            'import-error' => $message !== '' ? $message : __('Erreur pendant l import Search Console.', 'wp-rank-tracker'),
            'serp-success' => __('Import SERP externe termine.', 'wp-rank-tracker'),
            'serp-error' => $message !== '' ? $message : __('Erreur pendant l import SERP externe.', 'wp-rank-tracker'),
            'connect-success' => __('Connexion Google reussie.', 'wp-rank-tracker'),
            'connect-error' => $message !== '' ? $message : __('Erreur pendant la connexion Google.', 'wp-rank-tracker'),
            'disconnect-success' => __('Connexion Google supprimee.', 'wp-rank-tracker'),
        ];

        if (!isset($messages[$notice])) {
            return;
        }

        printf(
            '<div class="notice %s is-dismissible"><p>%s</p></div>',
            esc_attr(in_array($notice, ['import-error', 'connect-error', 'serp-error'], true) ? 'notice-error' : 'notice-success'),
            esc_html($messages[$notice])
        );
    }

    private function redirect_with_notice(string $notice, string $message = ''): void {
        $args = [
            'wrt_notice' => $notice,
        ];

        if ($message !== '') {
            $args['wrt_message'] = $message;
        }

        wp_safe_redirect(add_query_arg($args, $this->get_admin_page_url()));
        exit;
    }

    private function get_admin_page_url(): string {
        $fallback = admin_url('admin.php?page=' . self::MENU_SLUG);
        $referer = wp_get_referer();

        if (!is_string($referer) || $referer === '') {
            return $fallback;
        }

        $parts = wp_parse_url($referer);
        if (!is_array($parts)) {
            return $fallback;
        }

        $path = isset($parts['path']) ? (string) $parts['path'] : '';
        if ($path === '' || substr($path, -9) !== '/admin.php') {
            return $fallback;
        }

        $query = [];
        if (!empty($parts['query'])) {
            parse_str((string) $parts['query'], $query);
        }

        $query['page'] = self::MENU_SLUG;
        unset($query['wrt_notice'], $query['wrt_message'], $query['_wpnonce'], $query['_wp_http_referer']);

        $url = (isset($parts['scheme']) ? $parts['scheme'] . '://' : '') . (isset($parts['host']) ? $parts['host'] : '');
        if (!empty($parts['port'])) {
            $url .= ':' . $parts['port'];
        }

        $url .= $path;

        return add_query_arg($query, $url);
    }
}
