<?php

if (!defined('ABSPATH')) {
    exit;
}

final class WP_Rank_Tracker_DataForSEO_Service {
    private const BASE_URL = 'https://api.dataforseo.com';

    /**
     * @var array<string, mixed>
     */
    private array $settings;

    /**
     * @param array<string, mixed> $settings
     */
    public function __construct(array $settings) {
        $this->settings = $settings;
    }

    /**
     * @param string[] $keywords
     * @return array<string, mixed>|\WP_Error
     */
    public function fetch_serp_snapshot(array $keywords) {
        $login = (string) ($this->settings['dataforseo_login'] ?? '');
        $password = (string) ($this->settings['dataforseo_password'] ?? '');

        if ($login === '' || $password === '') {
            return new WP_Error('wrt_dataforseo_missing_credentials', __('Les identifiants DataForSEO sont manquants.', 'wp-rank-tracker'));
        }

        $keywords = array_values(array_filter(array_map('trim', $keywords), static fn(string $keyword): bool => $keyword !== ''));
        if ($keywords === []) {
            return new WP_Error('wrt_dataforseo_missing_keywords', __('Aucun mot-cle surveille pour l import concurrentiel.', 'wp-rank-tracker'));
        }

        $engines = ['google', 'bing'];
        $market = $this->resolve_market();
        $depth = 100;
        $rows = [];

        foreach ($engines as $engine) {
            $endpoint = sprintf('%s/v3/serp/%s/organic/live/advanced', self::BASE_URL, $engine);
            foreach ($keywords as $keyword) {
                $response = wp_remote_post(
                    $endpoint,
                    [
                        'timeout' => 45,
                        'headers' => [
                            'Authorization' => 'Basic ' . base64_encode($login . ':' . $password),
                            'Content-Type' => 'application/json',
                        ],
                        'body' => wp_json_encode([
                            [
                                'keyword' => $keyword,
                                'location_name' => $market['location_name'],
                                'language_name' => $market['language_name'],
                                'depth' => $depth,
                            ],
                        ]),
                    ]
                );

                if (is_wp_error($response)) {
                    return new WP_Error('wrt_dataforseo_request_failed', $response->get_error_message());
                }

                $status = (int) wp_remote_retrieve_response_code($response);
                $body = (string) wp_remote_retrieve_body($response);
                $decoded = json_decode($body, true);

                if ($status >= 400 || !is_array($decoded)) {
                    return new WP_Error('wrt_dataforseo_http_error', __('Reponse DataForSEO invalide.', 'wp-rank-tracker'));
                }

                foreach (($decoded['tasks'] ?? []) as $task) {
                    if (!is_array($task)) {
                        continue;
                    }

                    $taskKeyword = sanitize_text_field((string) ($task['data']['keyword'] ?? $keyword));
                    foreach (($task['result'] ?? []) as $result) {
                        if (!is_array($result)) {
                            continue;
                        }

                        foreach (($result['items'] ?? []) as $item) {
                            if (!is_array($item)) {
                                continue;
                            }

                            $rows[] = [
                                'engine' => $engine,
                                'keyword' => $taskKeyword,
                                'rank' => isset($item['rank_absolute']) ? (int) $item['rank_absolute'] : 0,
                                'domain' => $this->normalize_domain((string) ($item['domain'] ?? '')),
                                'url' => esc_url_raw((string) ($item['url'] ?? '')),
                                'title' => sanitize_text_field((string) ($item['title'] ?? '')),
                            ];
                        }
                    }
                }
            }
        }

        return [
            'fetched_at' => current_time('mysql'),
            'keywords' => $keywords,
            'location_name' => $market['location_name'],
            'language_name' => $market['language_name'],
            'rows' => $rows,
        ];
    }

    /**
     * @return array{location_name:string,language_name:string}
     */
    private function resolve_market(): array {
        $location = trim((string) ($this->settings['dataforseo_location_name'] ?? ''));
        $language = trim((string) ($this->settings['dataforseo_language_name'] ?? ''));

        $siteLocale = function_exists('determine_locale') ? (string) determine_locale() : (string) get_locale();
        $host = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
        $lowerLocale = strtolower($siteLocale);
        $looksFrench = strpos($lowerLocale, 'fr') === 0 || substr($host, -3) === '.fr';

        if ($looksFrench && ($location === '' || ($location === 'United States' && ($language === '' || $language === 'English')))) {
            $location = 'France';
            $language = 'French';
        }

        if ($location === '') {
            $location = 'United States';
        }

        if ($language === '') {
            $language = 'English';
        }

        return [
            'location_name' => $location,
            'language_name' => $language,
        ];
    }

    private function normalize_domain(string $domain): string {
        $domain = trim(strtolower($domain));
        $domain = preg_replace('#^https?://#', '', $domain);

        return rtrim((string) $domain, '/');
    }
}
