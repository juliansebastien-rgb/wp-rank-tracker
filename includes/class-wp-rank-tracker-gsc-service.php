<?php

if (!defined('ABSPATH')) {
    exit;
}

final class WP_Rank_Tracker_GSC_Service {
    private const TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';
    private const SEARCH_ANALYTICS_ENDPOINT = 'https://searchconsole.googleapis.com/webmasters/v3/sites/%s/searchAnalytics/query';
    private const AUTH_ENDPOINT = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const SCOPE = 'https://www.googleapis.com/auth/webmasters.readonly';

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
     * @return array<string, mixed>|\WP_Error
     */
    public function fetch_search_analytics() {
        if (empty($this->settings['gsc_property_uri'])) {
            return new WP_Error('wrt_missing_property', __('La propriete Search Console est obligatoire.', 'wp-rank-tracker'));
        }

        $accessToken = $this->fetch_access_token();
        if (is_wp_error($accessToken)) {
            return $accessToken;
        }

        $days = max(1, min(90, (int) ($this->settings['report_days'] ?? 28)));
        $endDate = gmdate('Y-m-d');
        $startDate = gmdate('Y-m-d', strtotime('-' . ($days - 1) . ' days'));
        $propertyUri = (string) $this->settings['gsc_property_uri'];
        $endpoint = sprintf(self::SEARCH_ANALYTICS_ENDPOINT, rawurlencode($propertyUri));

        $response = wp_remote_post(
            $endpoint,
            [
                'timeout' => 30,
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                    'Content-Type' => 'application/json',
                ],
                'body' => wp_json_encode(
                    [
                        'startDate' => $startDate,
                        'endDate' => $endDate,
                        'dimensions' => ['page', 'query'],
                        'rowLimit' => 250,
                        'startRow' => 0,
                    ]
                ),
            ]
        );

        if (is_wp_error($response)) {
            return new WP_Error('wrt_gsc_request_failed', $response->get_error_message());
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);
        $decoded = json_decode($body, true);

        if ($status >= 400) {
            $message = is_array($decoded) && isset($decoded['error']['message'])
                ? (string) $decoded['error']['message']
                : __('Reponse Google Search Console invalide.', 'wp-rank-tracker');

            return new WP_Error('wrt_gsc_http_error', $message);
        }

        $rows = [];
        foreach (($decoded['rows'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }

            $keys = $row['keys'] ?? [];
            $rows[] = [
                'page' => isset($keys[0]) ? esc_url_raw((string) $keys[0]) : '',
                'query' => isset($keys[1]) ? sanitize_text_field((string) $keys[1]) : '',
                'clicks' => isset($row['clicks']) ? (int) round((float) $row['clicks']) : 0,
                'impressions' => isset($row['impressions']) ? (int) round((float) $row['impressions']) : 0,
                'ctr' => isset($row['ctr']) ? (float) $row['ctr'] : 0.0,
                'position' => isset($row['position']) ? (float) $row['position'] : 0.0,
            ];
        }

        usort(
            $rows,
            static fn(array $left, array $right): int => $right['clicks'] <=> $left['clicks']
        );

        return [
            'property_uri' => $propertyUri,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'fetched_at' => current_time('mysql'),
            'rows' => $rows,
        ];
    }

    /**
     * @return string|\WP_Error
     */
    public function get_authorization_url(string $redirectUri, string $state) {
        $clientId = (string) ($this->settings['google_client_id'] ?? '');

        if ($clientId === '') {
            return new WP_Error('wrt_missing_client_id', __('Le Google Client ID est obligatoire.', 'wp-rank-tracker'));
        }

        return add_query_arg(
            [
                'client_id' => $clientId,
                'redirect_uri' => $redirectUri,
                'response_type' => 'code',
                'scope' => self::SCOPE,
                'access_type' => 'offline',
                'prompt' => 'consent',
                'include_granted_scopes' => 'true',
                'state' => $state,
            ],
            self::AUTH_ENDPOINT
        );
    }

    /**
     * @return array<string, mixed>|\WP_Error
     */
    public function exchange_code_for_tokens(string $code, string $redirectUri) {
        $clientId = (string) ($this->settings['google_client_id'] ?? '');
        $clientSecret = (string) ($this->settings['google_client_secret'] ?? '');

        if ($clientId === '' || $clientSecret === '') {
            return new WP_Error('wrt_missing_credentials', __('Le Google Client ID et le Client Secret sont obligatoires.', 'wp-rank-tracker'));
        }

        $response = wp_remote_post(
            self::TOKEN_ENDPOINT,
            [
                'timeout' => 20,
                'body' => [
                    'code' => $code,
                    'client_id' => $clientId,
                    'client_secret' => $clientSecret,
                    'redirect_uri' => $redirectUri,
                    'grant_type' => 'authorization_code',
                ],
            ]
        );

        if (is_wp_error($response)) {
            return new WP_Error('wrt_token_exchange_failed', $response->get_error_message());
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);
        $decoded = json_decode($body, true);

        if ($status >= 400 || !is_array($decoded)) {
            $message = is_array($decoded) && isset($decoded['error_description'])
                ? (string) $decoded['error_description']
                : __('Impossible d echanger le code OAuth Google.', 'wp-rank-tracker');

            return new WP_Error('wrt_oauth_exchange_error', $message);
        }

        if (empty($decoded['refresh_token'])) {
            return new WP_Error('wrt_missing_refresh_token', __('Google n a pas retourne de refresh token. Reessaie avec le consentement complet.', 'wp-rank-tracker'));
        }

        return [
            'refresh_token' => sanitize_text_field((string) $decoded['refresh_token']),
            'access_token' => sanitize_text_field((string) ($decoded['access_token'] ?? '')),
            'expires_in' => (int) ($decoded['expires_in'] ?? 0),
        ];
    }

    /**
     * @return string|\WP_Error
     */
    private function fetch_access_token() {
        $clientId = (string) ($this->settings['google_client_id'] ?? '');
        $clientSecret = (string) ($this->settings['google_client_secret'] ?? '');
        $refreshToken = (string) ($this->settings['google_refresh_token'] ?? '');

        if ($clientId === '' || $clientSecret === '' || $refreshToken === '') {
            return new WP_Error('wrt_missing_credentials', __('Les identifiants Google OAuth sont incomplets.', 'wp-rank-tracker'));
        }

        $response = wp_remote_post(
            self::TOKEN_ENDPOINT,
            [
                'timeout' => 20,
                'body' => [
                    'client_id' => $clientId,
                    'client_secret' => $clientSecret,
                    'refresh_token' => $refreshToken,
                    'grant_type' => 'refresh_token',
                ],
            ]
        );

        if (is_wp_error($response)) {
            return new WP_Error('wrt_token_request_failed', $response->get_error_message());
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);
        $decoded = json_decode($body, true);

        if ($status >= 400 || !is_array($decoded) || empty($decoded['access_token'])) {
            $message = is_array($decoded) && isset($decoded['error_description'])
                ? (string) $decoded['error_description']
                : __('Impossible de recuperer un access token Google.', 'wp-rank-tracker');

            return new WP_Error('wrt_access_token_error', $message);
        }

        return (string) $decoded['access_token'];
    }
}
