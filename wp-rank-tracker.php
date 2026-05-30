<?php
/**
 * Plugin Name: WP Rank Tracker
 * Plugin URI: https://github.com/juliansebastien-rgb/wp-rank-tracker
 * Description: Suit les mots-cles SEO de votre site WordPress et prepare le suivi de position sur Google et d'autres moteurs.
 * Version: 0.1.14
 * Author: Le Labo d'Azertaf
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wp-rank-tracker
 * Update URI: https://github.com/juliansebastien-rgb/wp-rank-tracker
 */

if (!defined('ABSPATH')) {
    exit;
}

define('WP_RANK_TRACKER_VERSION', '0.1.14');
define('WP_RANK_TRACKER_FILE', __FILE__);
define('WP_RANK_TRACKER_DIR', plugin_dir_path(__FILE__));
define('WP_RANK_TRACKER_URL', plugin_dir_url(__FILE__));

require_once WP_RANK_TRACKER_DIR . 'includes/class-wp-rank-tracker-gsc-service.php';
require_once WP_RANK_TRACKER_DIR . 'includes/class-wp-rank-tracker-dataforseo-service.php';
require_once WP_RANK_TRACKER_DIR . 'includes/class-wp-rank-tracker-central-service.php';
require_once WP_RANK_TRACKER_DIR . 'includes/class-wp-rank-tracker-admin.php';

final class WP_Rank_Tracker {
    private const VERSION = '0.1.14';
    private const DAILY_GOOGLE_EVENT = 'wp_rank_tracker_refresh_google_daily';
    private const DAILY_SERP_EVENT = 'wp_rank_tracker_refresh_serp_daily';
    private const TRANSIENT_PREFIX = 'wp_rank_tracker_';
    private const GITHUB_REPOSITORY = 'juliansebastien-rgb/wp-rank-tracker';
    private const GITHUB_API_BASE = 'https://api.github.com/repos/juliansebastien-rgb/wp-rank-tracker';
    private const GITHUB_REPOSITORY_URL = 'https://github.com/juliansebastien-rgb/wp-rank-tracker';
    private const UPDATE_CACHE_TTL = HOUR_IN_SECONDS;
    private static ?WP_Rank_Tracker $instance = null;

    public static function instance(): WP_Rank_Tracker {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct() {
        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);
        add_action('plugins_loaded', [$this, 'boot']);
        add_filter('pre_set_site_transient_update_plugins', [$this, 'inject_github_update']);
        add_filter('plugins_api', [$this, 'filter_plugin_information'], 20, 3);
        add_filter('upgrader_source_selection', [$this, 'normalize_github_update_source'], 10, 4);
        add_action('upgrader_process_complete', [$this, 'clear_update_cache'], 10, 2);
    }

    public function activate(): void {
        WP_Rank_Tracker_Admin::maybe_seed_defaults();
        if (!wp_next_scheduled(self::DAILY_GOOGLE_EVENT)) {
            wp_schedule_event(time() + (30 * MINUTE_IN_SECONDS), 'daily', self::DAILY_GOOGLE_EVENT);
        }
        if (!wp_next_scheduled(self::DAILY_SERP_EVENT)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::DAILY_SERP_EVENT);
        }
    }

    public function deactivate(): void {
        wp_clear_scheduled_hook(self::DAILY_GOOGLE_EVENT);
        wp_clear_scheduled_hook(self::DAILY_SERP_EVENT);
    }

    public function boot(): void {
        if (!wp_next_scheduled(self::DAILY_GOOGLE_EVENT)) {
            wp_schedule_event(time() + (30 * MINUTE_IN_SECONDS), 'daily', self::DAILY_GOOGLE_EVENT);
        }
        if (!wp_next_scheduled(self::DAILY_SERP_EVENT)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::DAILY_SERP_EVENT);
        }

        $admin = new WP_Rank_Tracker_Admin();
        $admin->boot();
        add_action(self::DAILY_GOOGLE_EVENT, [$admin, 'handle_scheduled_google_refresh']);
        add_action(self::DAILY_SERP_EVENT, [$admin, 'handle_scheduled_serp_refresh']);
    }

    public function inject_github_update($transient) {
        if (!is_object($transient) || empty($transient->checked)) {
            return $transient;
        }

        $release = $this->get_github_release_data();
        if (!$release || empty($release['version'])) {
            return $transient;
        }

        if (version_compare(self::VERSION, $release['version'], '>=')) {
            return $transient;
        }

        $plugin_file = plugin_basename(__FILE__);
        $transient->response[$plugin_file] = (object) [
            'slug' => 'wp-rank-tracker',
            'plugin' => $plugin_file,
            'new_version' => $release['version'],
            'url' => $release['url'],
            'package' => $release['package'],
            'icons' => [],
            'banners' => [],
            'banners_rtl' => [],
            'tested' => '6.8',
            'requires_php' => '7.4',
            'compatibility' => new stdClass(),
        ];

        return $transient;
    }

    public function filter_plugin_information($result, string $action, $args) {
        if ($action !== 'plugin_information' || !is_object($args) || empty($args->slug) || $args->slug !== 'wp-rank-tracker') {
            return $result;
        }

        $release = $this->get_github_release_data();
        if (!$release) {
            return $result;
        }

        return (object) [
            'name' => 'WP Rank Tracker',
            'slug' => 'wp-rank-tracker',
            'version' => $release['version'],
            'author' => '<a href="https://github.com/juliansebastien-rgb">Le Labo d&#039;Azertaf</a>',
            'author_profile' => 'https://github.com/juliansebastien-rgb',
            'homepage' => self::GITHUB_REPOSITORY_URL,
            'requires' => '6.0',
            'requires_php' => '7.4',
            'tested' => '6.8',
            'last_updated' => $release['published_at'],
            'download_link' => $release['package'],
            'sections' => [
                'description' => 'Audit local SEO, connexion Google Search Console et comparatif SERP concurrentiel dans WordPress.',
                'installation' => 'Upload the plugin, activate it, configure WP Rank Tracker, then create GitHub releases to deliver updates directly from the WordPress Extensions screen.',
                'changelog' => $this->build_release_changelog($release),
            ],
            'banners' => [],
            'icons' => [],
        ];
    }

    public function clear_update_cache($upgrader, array $hook_extra): void {
        if (($hook_extra['type'] ?? '') !== 'plugin') {
            return;
        }

        $plugins = $hook_extra['plugins'] ?? [];
        if (in_array(plugin_basename(__FILE__), $plugins, true)) {
            delete_transient(self::TRANSIENT_PREFIX . 'github_release');
        }
    }

    public function normalize_github_update_source(string $source, string $remote_source, $upgrader, array $hook_extra): string {
        if (($hook_extra['type'] ?? '') !== 'plugin') {
            return $source;
        }

        $plugins = $hook_extra['plugins'] ?? [];
        if (!in_array(plugin_basename(__FILE__), $plugins, true)) {
            return $source;
        }

        $normalized = trailingslashit($remote_source) . 'wp-rank-tracker';
        if ($source === $normalized || !is_dir($source)) {
            return $source;
        }

        if (@rename($source, $normalized)) {
            return $normalized;
        }

        return $source;
    }

    private function get_github_release_data(): ?array {
        $cache_key = self::TRANSIENT_PREFIX . 'github_release';
        $cached = get_transient($cache_key);

        if (is_array($cached)) {
            return $cached;
        }

        $release = $this->request_github_release('/releases/latest');

        if (!$release) {
            $tag = $this->request_github_release('/tags');
            if (!$tag || empty($tag[0]['name'])) {
                return null;
            }

            $first_tag = $tag[0];
            $release = [
                'tag_name' => $first_tag['name'],
                'zipball_url' => self::GITHUB_API_BASE . '/zipball/' . rawurlencode($first_tag['name']),
                'html_url' => self::GITHUB_REPOSITORY_URL . '/releases/tag/' . rawurlencode($first_tag['name']),
                'published_at' => gmdate('Y-m-d H:i:s'),
                'body' => '',
            ];
        }

        if (empty($release['tag_name'])) {
            return null;
        }

        $package = '';
        if (!empty($release['assets']) && is_array($release['assets'])) {
            foreach ($release['assets'] as $asset) {
                if (!is_array($asset)) {
                    continue;
                }

                $name = isset($asset['name']) ? (string) $asset['name'] : '';
                $download = isset($asset['browser_download_url']) ? (string) $asset['browser_download_url'] : '';

                if ($name !== '' && substr($name, -4) === '.zip' && $download !== '') {
                    $package = $download;
                    break;
                }
            }
        }

        if ($package === '' && !empty($release['zipball_url'])) {
            $package = (string) $release['zipball_url'];
        }

        if ($package === '') {
            return null;
        }

        $data = [
            'version' => ltrim((string) $release['tag_name'], 'v'),
            'package' => $package,
            'url' => !empty($release['html_url']) ? (string) $release['html_url'] : self::GITHUB_REPOSITORY_URL,
            'published_at' => !empty($release['published_at']) ? gmdate('Y-m-d H:i:s', strtotime((string) $release['published_at'])) : gmdate('Y-m-d H:i:s'),
            'body' => !empty($release['body']) ? (string) $release['body'] : '',
        ];

        set_transient($cache_key, $data, self::UPDATE_CACHE_TTL);

        return $data;
    }

    private function build_release_changelog(array $release): string {
        $body = isset($release['body']) && is_string($release['body']) ? trim($release['body']) : '';

        if ($body !== '') {
            return sprintf("= %s =\n%s\n", $release['version'], wp_strip_all_tags($body));
        }

        return sprintf("= %s =\nGitHub release package.\n", $release['version']);
    }

    private function request_github_release(string $path) {
        $response = wp_remote_get(
            self::GITHUB_API_BASE . $path,
            [
                'timeout' => 15,
                'headers' => [
                    'Accept' => 'application/vnd.github+json',
                    'User-Agent' => 'WP Rank Tracker/' . self::VERSION . '; ' . home_url('/'),
                ],
            ]
        );

        if (is_wp_error($response)) {
            return null;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            return null;
        }

        $data = json_decode((string) wp_remote_retrieve_body($response), true);

        return is_array($data) ? $data : null;
    }
}

WP_Rank_Tracker::instance();
