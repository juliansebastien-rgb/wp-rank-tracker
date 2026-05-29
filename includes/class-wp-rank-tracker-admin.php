<?php

if (!defined('ABSPATH')) {
    exit;
}

final class WP_Rank_Tracker_Admin {
    private const OPTION_KEY = 'wp_rank_tracker_settings';
    private const REPORT_OPTION_KEY = 'wp_rank_tracker_gsc_report';
    private const SERP_REPORT_OPTION_KEY = 'wp_rank_tracker_serp_report';
    private const MENU_SLUG = 'wp-rank-tracker';
    private const NONCE_ACTION_SETTINGS = 'wp_rank_tracker_save_settings';
    private const NONCE_ACTION_IMPORT = 'wp_rank_tracker_import_gsc';
    private const NONCE_ACTION_IMPORT_SERP = 'wp_rank_tracker_import_serp';
    private const NONCE_ACTION_CONNECT = 'wp_rank_tracker_connect_google';
    private const NONCE_ACTION_DISCONNECT = 'wp_rank_tracker_disconnect_google';
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
    }

    public function enqueue_assets(string $hook): void {
        if ($hook !== 'toplevel_page_' . self::MENU_SLUG) {
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
            'dataforseo_depth' => $this->sanitize_serp_depth($_POST['dataforseo_depth'] ?? 10),
        ];

        update_option(self::OPTION_KEY, $settings, false);

        $propertyUri = (string) ($settings['gsc_property_uri'] ?? '');
        if ($propertyUri !== '') {
            $centralStatus = $this->get_central_google_status($settings);
            if (!empty($centralStatus['connected'])) {
                $importResult = $this->run_google_import($settings);
                if (is_wp_error($importResult)) {
                    $this->redirect_with_notice('import-error', $importResult->get_error_message());
                }

                update_option(self::REPORT_OPTION_KEY, $importResult, false);
                $this->redirect_with_notice('settings-and-import-success');
            }
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

        update_option(self::REPORT_OPTION_KEY, $report, false);
        $this->redirect_with_notice('import-success');
    }

    public function handle_import_serp(): void {
        $this->assert_permissions();
        check_admin_referer(self::NONCE_ACTION_IMPORT_SERP);

        $settings = $this->get_settings();
        $centralService = new WP_Rank_Tracker_Central_Service($settings);

        if ($centralService->is_configured()) {
            $response = $centralService->import_serp_report(
                (string) $settings['target_domain'],
                is_array($settings['tracked_keywords']) ? $settings['tracked_keywords'] : [],
                is_array($settings['competitors']) ? $settings['competitors'] : [],
                (string) $settings['dataforseo_location_name'],
                (string) $settings['dataforseo_language_name'],
                (int) $settings['dataforseo_depth']
            );
            if (is_wp_error($response)) {
                $this->redirect_with_notice('serp-error', $response->get_error_message());
            }
            $report = is_array($response['report'] ?? null) ? $response['report'] : [];
        } else {
            $service = new WP_Rank_Tracker_DataForSEO_Service($settings);
            $report = $service->fetch_serp_snapshot(is_array($settings['tracked_keywords']) ? $settings['tracked_keywords'] : []);
        }

        if (is_wp_error($report)) {
            $this->redirect_with_notice('serp-error', $report->get_error_message());
        }

        update_option(self::SERP_REPORT_OPTION_KEY, $report, false);
        $this->redirect_with_notice('serp-success');
    }

    public function render_admin_page(): void {
        if (!current_user_can('manage_options')) {
            return;
        }

        $settings = $this->get_settings();
        $report = $this->get_report();
        $serpReport = $this->get_serp_report();
        $localAudit = $this->build_local_audit();
        $summary = $this->build_summary($report);
        $pageRows = $this->group_rows_by_page($report['rows']);
        $comparisonRows = $this->build_comparison_rows($localAudit['pages'], $pageRows);
        $marketRows = $this->build_market_watch_rows($settings, $localAudit['pages'], $report['rows']);
        $serpComparisonRows = $this->build_serp_comparison_rows($settings, $serpReport);
        $centralStatus = $this->get_central_google_status($settings);
        $isConnected = $centralStatus['connected'];
        $isCentralRegistered = !empty($settings['central_site_token']);
        $googleProperties = $this->get_google_properties($settings, $isConnected);
        $selectedProperty = $this->resolve_selected_property($settings, $centralStatus, $googleProperties);
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

            <section class="wrt-card wrt-local-overview">
                <h2><?php esc_html_e('Lecture locale de tes pages', 'wp-rank-tracker'); ?></h2>
                <p><?php esc_html_e('Le plugin commence par analyser tes pages telles qu elles sont construites dans WordPress. Il repere pour chaque page le sujet principal qu elle semble cibler, avant de comparer ensuite cette lecture avec les donnees reelles de Google.', 'wp-rank-tracker'); ?></p>
                <div class="wrt-local-stats">
                    <div><strong><?php echo esc_html((string) $localAudit['page_count']); ?></strong><span><?php esc_html_e('pages analysees localement', 'wp-rank-tracker'); ?></span></div>
                    <div><strong><?php echo esc_html((string) $localAudit['with_focus_count']); ?></strong><span><?php esc_html_e('pages avec mot-cle principal detecte', 'wp-rank-tracker'); ?></span></div>
                </div>
            </section>

            <section class="wrt-card wrt-table-card">
                <h2><?php esc_html_e('Mots-cles potentiels par page', 'wp-rank-tracker'); ?></h2>
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
                                    </td>
                                    <td><?php echo esc_html($pageAudit['primary_keyword']); ?></td>
                                    <td><?php echo esc_html(implode(', ', $pageAudit['secondary_keywords'])); ?></td>
                                    <td><?php echo esc_html(implode(' | ', $pageAudit['recommendations'])); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </section>

            <section class="wrt-card wrt-table-card">
                <h2><?php esc_html_e('Google Search Console', 'wp-rank-tracker'); ?></h2>
                <p><?php esc_html_e('Connecte Google, choisis la propriete Search Console a analyser, puis enregistre. L import se lance automatiquement et te permettra de comparer ta lecture locale avec la vision reelle de Google.', 'wp-rank-tracker'); ?></p>
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
                <h2><?php esc_html_e('Bilan par page', 'wp-rank-tracker'); ?></h2>
                <?php if ($pageRows === []) : ?>
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
                                <th><?php esc_html_e('Requete principale', 'wp-rank-tracker'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pageRows as $pageRow) : ?>
                                <tr>
                                    <td><strong><?php echo esc_html($pageRow['page']); ?></strong></td>
                                    <td><?php echo esc_html((string) $pageRow['clicks']); ?></td>
                                    <td><?php echo esc_html((string) $pageRow['impressions']); ?></td>
                                    <td><?php echo esc_html($this->format_ctr($pageRow['ctr'])); ?></td>
                                    <td><?php echo esc_html($this->format_position($pageRow['position'])); ?></td>
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

            <section class="wrt-card wrt-table-card">
                <h2><?php esc_html_e('Configuration du suivi', 'wp-rank-tracker'); ?></h2>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="wp_rank_tracker_save_settings" />
                    <?php wp_nonce_field(self::NONCE_ACTION_SETTINGS); ?>
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
                    <?php submit_button(__('Enregistrer la configuration', 'wp-rank-tracker')); ?>
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
                <?php endif; ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="wp_rank_tracker_import_serp" />
                    <?php wp_nonce_field(self::NONCE_ACTION_IMPORT_SERP); ?>
                    <?php submit_button(__('Importer les SERP externes', 'wp-rank-tracker'), 'secondary'); ?>
                </form>
                <?php if ($serpComparisonRows === []) : ?>
                    <p><?php esc_html_e('Aucune donnee SERP externe importee pour le moment.', 'wp-rank-tracker'); ?></p>
                <?php else : ?>
                    <table class="widefat striped">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('Mot-cle', 'wp-rank-tracker'); ?></th>
                                <th><?php esc_html_e('Moteur', 'wp-rank-tracker'); ?></th>
                                <th><?php esc_html_e('Ton domaine', 'wp-rank-tracker'); ?></th>
                                <th><?php esc_html_e('Meilleur concurrent', 'wp-rank-tracker'); ?></th>
                                <th><?php esc_html_e('Lecture', 'wp-rank-tracker'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($serpComparisonRows as $row) : ?>
                                <tr>
                                    <td><strong><?php echo esc_html($row['keyword']); ?></strong></td>
                                    <td><?php echo esc_html($row['engine']); ?></td>
                                    <td><?php echo esc_html($row['target_rank']); ?></td>
                                    <td><?php echo esc_html($row['competitor_rank']); ?></td>
                                    <td><?php echo esc_html($row['note']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </section>
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
            'dataforseo_depth' => 10,
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
        if ($depth < 10) {
            return 10;
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

        if (!is_array($report)) {
            return [
                'fetched_at' => '',
                'location_name' => '',
                'language_name' => '',
                'rows' => [],
            ];
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
            'location_name' => sanitize_text_field((string) ($report['location_name'] ?? '')),
            'language_name' => sanitize_text_field((string) ($report['language_name'] ?? '')),
            'rows' => $rows,
        ];
    }

    /**
     * @param array<string, mixed> $settings
     * @param array<string, mixed> $serpReport
     * @return array<int, array<string, string>>
     */
    private function build_serp_comparison_rows(array $settings, array $serpReport): array {
        $keywords = is_array($settings['tracked_keywords'] ?? null) ? $settings['tracked_keywords'] : [];
        $competitors = is_array($settings['competitors'] ?? null) ? array_map([$this, 'sanitize_domain'], $settings['competitors']) : [];
        $targetDomain = $this->sanitize_domain((string) ($settings['target_domain'] ?? ''));

        if ($keywords === [] || $serpReport['rows'] === []) {
            return [];
        }

        $rows = [];
        foreach ($keywords as $keyword) {
            foreach (['google', 'bing'] as $engine) {
                $snapshotRows = array_values(array_filter(
                    $serpReport['rows'],
                    static fn(array $row): bool => (string) $row['keyword'] === (string) $keyword && (string) $row['engine'] === $engine
                ));

                $targetRank = $this->find_domain_rank($targetDomain, $snapshotRows);
                $bestCompetitor = $this->find_best_competitor_rank($competitors, $snapshotRows);

                $rows[] = [
                    'keyword' => (string) $keyword,
                    'engine' => ucfirst($engine),
                    'target_rank' => $targetRank > 0 ? '#' . $targetRank : __('Non detecte', 'wp-rank-tracker'),
                    'competitor_rank' => $bestCompetitor['label'],
                    'note' => $this->build_serp_note($targetRank, $bestCompetitor['rank']),
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

        $primaryKeyword = $titlePhrase !== '' ? $titlePhrase : ($h1Phrase !== '' ? $h1Phrase : ($slugPhrase !== '' ? $slugPhrase : __('A definir', 'wp-rank-tracker')));
        $secondaryKeywords = array_values(array_filter([$h1Phrase, $h2Phrase, $slugPhrase, ...$topTerms], static fn(string $value): bool => $value !== '' && $value !== $primaryKeyword));
        $secondaryKeywords = array_slice(array_unique($secondaryKeywords), 0, 3);

        $recommendations = $this->build_local_recommendations($title, $slugPhrase, $content, $topTerms, $headings);

        return [
            'title' => $title !== '' ? $title : __('Sans titre', 'wp-rank-tracker'),
            'url' => is_string($url) ? $url : '',
            'type_label' => $post->post_type === 'page' ? __('Page', 'wp-rank-tracker') : __('Article', 'wp-rank-tracker'),
            'primary_keyword' => $primaryKeyword,
            'secondary_keywords' => $secondaryKeywords !== [] ? $secondaryKeywords : [__('Aucun terme fort detecte', 'wp-rank-tracker')],
            'recommendations' => $recommendations,
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
     * @param array<string, string[]> $headings
     * @return string[]
     */
    private function build_local_recommendations(string $title, string $slugPhrase, string $content, array $topTerms, array $headings): array {
        $recommendations = [];

        if (count($this->tokenize_text($title)) < 2) {
            $recommendations[] = __('Renforcer le title avec une requete plus explicite.', 'wp-rank-tracker');
        }

        if ($slugPhrase === '') {
            $recommendations[] = __('Rendre le slug plus descriptif.', 'wp-rank-tracker');
        }

        if (str_word_count($content) < 120) {
            $recommendations[] = __('Ajouter plus de contenu pour mieux clarifier l intention de la page.', 'wp-rank-tracker');
        }

        if ($headings['h1'] === []) {
            $recommendations[] = __('Ajouter un H1 clair aligné avec le sujet principal de la page.', 'wp-rank-tracker');
        }

        if ($headings['h2'] === []) {
            $recommendations[] = __('Ajouter des H2 pour structurer le sujet et renforcer les sous-themes.', 'wp-rank-tracker');
        }

        if (count($topTerms) < 2) {
            $recommendations[] = __('Le champ lexical semble faible. Ajouter des termes proches du sujet principal.', 'wp-rank-tracker');
        }

        if ($recommendations === []) {
            $recommendations[] = __('Base locale coherente. Comparer maintenant avec Google Search Console.', 'wp-rank-tracker');
        }

        return array_slice($recommendations, 0, 3);
    }

    private function render_post_content(WP_Post $post): string {
        $content = (string) $post->post_content;

        if (has_blocks($content)) {
            $content = do_blocks($content);
        }

        return $content;
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
    private function find_best_competitor_rank(array $competitors, array $rows): array {
        $bestRank = 0;
        $bestDomain = '';

        foreach ($rows as $row) {
            $domain = $this->sanitize_domain((string) ($row['domain'] ?? ''));
            $rank = (int) ($row['rank'] ?? 0);

            if (!in_array($domain, $competitors, true)) {
                continue;
            }

            if ($bestRank === 0 || ($rank > 0 && $rank < $bestRank)) {
                $bestRank = $rank;
                $bestDomain = $domain;
            }
        }

        if ($bestRank === 0 || $bestDomain === '') {
            return [
                'rank' => 0,
                'label' => __('Aucun concurrent detecte', 'wp-rank-tracker'),
            ];
        }

        return [
            'rank' => $bestRank,
            'label' => $bestDomain . ' (#' . $bestRank . ')',
        ];
    }

    private function build_serp_note(int $targetRank, int $competitorRank): string {
        if ($targetRank > 0 && ($competitorRank === 0 || $targetRank < $competitorRank)) {
            return __('Ton domaine passe devant les concurrents suivis sur cet echantillon.', 'wp-rank-tracker');
        }

        if ($targetRank > 0 && $competitorRank > 0) {
            return __('Un concurrent suivi passe devant ton domaine sur cette SERP.', 'wp-rank-tracker');
        }

        if ($targetRank === 0 && $competitorRank > 0) {
            return __('Tes concurrents apparaissent, mais pas encore ton domaine.', 'wp-rank-tracker');
        }

        return __('Aucun domaine suivi detecte dans la profondeur analysee.', 'wp-rank-tracker');
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
