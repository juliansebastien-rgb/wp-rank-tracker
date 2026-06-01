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
        $rows = [];

        foreach ($engines as $engine) {
            $endpoint = sprintf('%s/v3/serp/%s/organic/live/advanced', self::BASE_URL, $engine);
            $tasks = [];

            foreach ($keywords as $keyword) {
                $tasks[] = [
                    'keyword' => $keyword,
                    'location_name' => (string) ($this->settings['dataforseo_location_name'] ?? 'United States'),
                    'language_name' => (string) ($this->settings['dataforseo_language_name'] ?? 'English'),
                    'depth' => max(20, (int) ($this->settings['dataforseo_depth'] ?? 20)),
                ];
            }

            $response = wp_remote_post(
                $endpoint,
                [
                    'timeout' => 45,
                    'headers' => [
                        'Authorization' => 'Basic ' . base64_encode($login . ':' . $password),
                        'Content-Type' => 'application/json',
                    ],
                    'body' => wp_json_encode($tasks),
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

                $keyword = sanitize_text_field((string) ($task['data']['keyword'] ?? ''));
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
                            'keyword' => $keyword,
                            'rank' => isset($item['rank_absolute']) ? (int) $item['rank_absolute'] : 0,
                            'domain' => $this->normalize_domain((string) ($item['domain'] ?? '')),
                            'url' => esc_url_raw((string) ($item['url'] ?? '')),
                            'title' => sanitize_text_field((string) ($item['title'] ?? '')),
                        ];
                    }
                }
            }
        }

        return [
            'fetched_at' => current_time('mysql'),
            'keywords' => $keywords,
            'location_name' => (string) ($this->settings['dataforseo_location_name'] ?? 'United States'),
            'language_name' => (string) ($this->settings['dataforseo_language_name'] ?? 'English'),
            'rows' => $rows,
        ];
    }

    private function normalize_domain(string $domain): string {
        $domain = trim(strtolower($domain));
        $domain = preg_replace('#^https?://#', '', $domain);

        return rtrim((string) $domain, '/');
    }
}
