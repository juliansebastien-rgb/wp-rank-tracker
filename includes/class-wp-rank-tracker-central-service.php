<?php

if (!defined('ABSPATH')) {
    exit;
}

final class WP_Rank_Tracker_Central_Service {
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

    public function is_configured(): bool {
        return $this->get_api_base_url() !== '' && $this->get_api_token() !== '';
    }

    /**
     * @return array<string, mixed>|\WP_Error
     */
    public function start_google_connect(string $returnUrl) {
        return $this->request(
            'POST',
            '/wrt/v1/google/connect/start',
            [
                'site_url' => home_url('/'),
                'site_name' => get_bloginfo('name'),
                'return_url' => $returnUrl,
            ]
        );
    }

    /**
     * @return array<string, mixed>|\WP_Error
     */
    public function get_google_status() {
        return $this->request('GET', '/wrt/v1/google/status', [], [
            'site_url' => home_url('/'),
        ]);
    }

    /**
     * @return array<string, mixed>|\WP_Error
     */
    public function disconnect_google() {
        return $this->request('POST', '/wrt/v1/google/disconnect', [], [
            'site_url' => home_url('/'),
        ]);
    }

    /**
     * @return array<string, mixed>|\WP_Error
     */
    public function import_google_report(string $propertyUri, int $days) {
        return $this->request(
            'POST',
            '/wrt/v1/google/import',
            [
                'site_url' => home_url('/'),
                'property_uri' => $propertyUri,
                'days' => $days,
            ]
        );
    }

    /**
     * @param string[] $keywords
     * @param string[] $competitors
     * @return array<string, mixed>|\WP_Error
     */
    public function import_serp_report(string $targetDomain, array $keywords, array $competitors, string $locationName, string $languageName, int $depth) {
        return $this->request(
            'POST',
            '/wrt/v1/serp/import',
            [
                'site_url' => home_url('/'),
                'target_domain' => $targetDomain,
                'keywords' => array_values($keywords),
                'competitors' => array_values($competitors),
                'location_name' => $locationName,
                'language_name' => $languageName,
                'depth' => $depth,
            ]
        );
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $query
     * @return array<string, mixed>|\WP_Error
     */
    private function request(string $method, string $path, array $body = [], array $query = []) {
        if (!$this->is_configured()) {
            return new WP_Error('wrt_central_not_configured', __('Le service central SEO n est pas configure.', 'wp-rank-tracker'));
        }

        $url = trailingslashit($this->get_api_base_url()) . ltrim($path, '/');
        if ($query !== []) {
            $url = add_query_arg($query, $url);
        }

        $args = [
            'method' => $method,
            'timeout' => 30,
            'headers' => [
                'Authorization' => 'Bearer ' . $this->get_api_token(),
                'Content-Type' => 'application/json',
            ],
        ];

        if ($method !== 'GET') {
            $args['body'] = wp_json_encode($body);
        }

        $response = wp_remote_request($url, $args);
        if (is_wp_error($response)) {
            return $response;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $payload = json_decode((string) wp_remote_retrieve_body($response), true);

        if ($code >= 400) {
            $message = is_array($payload) && !empty($payload['detail'])
                ? (string) $payload['detail']
                : __('Erreur du service central SEO.', 'wp-rank-tracker');
            return new WP_Error('wrt_central_http_error', $message);
        }

        return is_array($payload) ? $payload : [];
    }

    private function get_api_base_url(): string {
        return untrailingslashit((string) ($this->settings['central_api_base_url'] ?? ''));
    }

    private function get_api_token(): string {
        return (string) ($this->settings['central_api_token'] ?? '');
    }
}
