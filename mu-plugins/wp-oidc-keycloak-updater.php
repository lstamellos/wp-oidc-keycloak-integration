<?php
/**
 * Plugin Name: WP OIDC Keycloak Updater
 * Description: Automatic stable-release updater for the WP OIDC Keycloak must-use integration.
 * Version: 0.6.31
 * Author: OmniaTV
 * Author URI: https://omniatv.com/
 * Plugin URI: https://github.com/lstamellos/wp-oidc-keycloak-integration
 * Network: true
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class WP_OIDC_Keycloak_Updater
{
    private const VERSION = '0.6.31';
    private const REPOSITORY = 'lstamellos/wp-oidc-keycloak-integration';
    private const CRON_HOOK = 'wp_oidc_keycloak_check_for_updates';
    private const LOCK_OPTION = 'wp_oidc_keycloak_update_lock';
    private const LOCK_TTL = 900;

    /** @var array<string,string> */
    private const DEPLOYMENT_FILES = [
        'wp-oidc-keycloak-integration.php' =>
            'wp-oidc-keycloak-integration.php',
        'wp-oidc-keycloak-updater.php' =>
            'wp-oidc-keycloak-updater.php',
        'wp-oidc-keycloak-templates/myaccount/form-edit-account.php' =>
            'wp-oidc-keycloak-templates/myaccount/form-edit-account.php',
    ];

    public static function bootstrap(): void
    {
        add_action('init', [self::class, 'ensure_schedule'], 20);
        add_action(self::CRON_HOOK, [self::class, 'run_scheduled_update']);

        if (defined('WP_CLI') && WP_CLI && class_exists('WP_CLI')) {
            WP_CLI::add_command(
                'wp-oidc-keycloak update',
                [self::class, 'run_cli_update']
            );
        }
    }

    public static function ensure_schedule(): void
    {
        if (!self::enabled() || !self::is_primary_site()) {
            return;
        }

        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(
                time() + 300,
                'twicedaily',
                self::CRON_HOOK
            );
        }
    }

    public static function run_scheduled_update(): void
    {
        if (!self::enabled() || !self::is_primary_site()) {
            return;
        }

        try {
            self::check_and_update();
        } catch (Throwable $exception) {
            error_log(
                'WP OIDC Keycloak updater: ' .
                $exception->getMessage()
            );
        }
    }

    /**
     * @param list<string>         $args
     * @param array<string,string> $assocArgs
     */
    public static function run_cli_update(
        array $args,
        array $assocArgs
    ): void {
        unset($args, $assocArgs);

        try {
            $result = self::check_and_update(true);
            WP_CLI::success($result);
        } catch (Throwable $exception) {
            WP_CLI::error($exception->getMessage());
        }
    }

    private static function enabled(): bool
    {
        return !defined('WP_OIDC_KEYCLOAK_AUTO_UPDATE_ENABLED') ||
            WP_OIDC_KEYCLOAK_AUTO_UPDATE_ENABLED === true;
    }

    private static function is_primary_site(): bool
    {
        return !is_multisite() || is_main_site();
    }

    private static function repository(): string
    {
        if (
            defined('WP_OIDC_KEYCLOAK_GITHUB_REPOSITORY') &&
            is_string(WP_OIDC_KEYCLOAK_GITHUB_REPOSITORY) &&
            trim(WP_OIDC_KEYCLOAK_GITHUB_REPOSITORY) !== ''
        ) {
            return trim(WP_OIDC_KEYCLOAK_GITHUB_REPOSITORY);
        }

        return self::REPOSITORY;
    }

    private static function current_version(): string
    {
        $file = WPMU_PLUGIN_DIR .
            '/wp-oidc-keycloak-integration.php';

        if (!is_readable($file)) {
            throw new RuntimeException(
                'Installed integration file is not readable.'
            );
        }

        $head = file_get_contents($file, false, null, 0, 8192);

        if (
            !is_string($head) ||
            !preg_match(
                '/^[ \t\/*#@]*Version:\s*([^\r\n]+)/mi',
                $head,
                $matches
            )
        ) {
            throw new RuntimeException(
                'Installed integration version cannot be determined.'
            );
        }

        return trim($matches[1]);
    }

    private static function check_and_update(
        bool $force = false
    ): string {
        self::acquire_lock();

        try {
            $release = self::fetch_latest_release();
            $latestVersion = self::release_version($release);
            $currentVersion = self::current_version();

            if (
                !$force &&
                version_compare(
                    $latestVersion,
                    $currentVersion,
                    '<='
                )
            ) {
                return sprintf(
                    'Already current (%s).',
                    $currentVersion
                );
            }

            if (
                $force &&
                version_compare(
                    $latestVersion,
                    $currentVersion,
                    '<'
                )
            ) {
                throw new RuntimeException(
                    'Latest release is older than the installed version.'
                );
            }

            if (
                version_compare(
                    $latestVersion,
                    $currentVersion,
                    '=='
                )
            ) {
                return sprintf(
                    'Already current (%s).',
                    $currentVersion
                );
            }

            $asset = self::find_release_asset(
                $release,
                $latestVersion
            );
            $archive = self::download_and_verify_asset($asset);

            try {
                self::install_archive(
                    $archive,
                    $latestVersion
                );
            } finally {
                if (is_file($archive)) {
                    @unlink($archive);
                }
            }

            return sprintf(
                'Updated WP OIDC Keycloak integration from %s to %s.',
                $currentVersion,
                $latestVersion
            );
        } finally {
            self::release_lock();
        }
    }

    /** @return array<string,mixed> */
    private static function fetch_latest_release(): array
    {
        $url = sprintf(
            'https://api.github.com/repos/%s/releases/latest',
            self::repository()
        );

        $response = wp_remote_get(
            $url,
            [
                'timeout' => 20,
                'redirection' => 3,
                'headers' => [
                    'Accept' => 'application/vnd.github+json',
                    'X-GitHub-Api-Version' => '2026-03-10',
                    'User-Agent' => 'WP-OIDC-Keycloak-Updater/' .
                        self::VERSION,
                ],
            ]
        );

        if (is_wp_error($response)) {
            throw new RuntimeException(
                'GitHub release check failed: ' .
                $response->get_error_message()
            );
        }

        $status = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if ($status !== 200) {
            throw new RuntimeException(
                sprintf(
                    'GitHub release check returned HTTP %d.',
                    $status
                )
            );
        }

        $release = json_decode($body, true);

        if (!is_array($release)) {
            throw new RuntimeException(
                'GitHub release response is invalid JSON.'
            );
        }

        if (
            !empty($release['draft']) ||
            !empty($release['prerelease'])
        ) {
            throw new RuntimeException(
                'GitHub latest release is not a stable release.'
            );
        }

        return $release;
    }

    /** @param array<string,mixed> $release */
    private static function release_version(array $release): string
    {
        $tag = isset($release['tag_name'])
            ? trim((string) $release['tag_name'])
            : '';

        if (!preg_match('/^v(\d+\.\d+\.\d+)$/', $tag, $matches)) {
            throw new RuntimeException(
                'Release tag must use vX.Y.Z format.'
            );
        }

        return $matches[1];
    }

    /**
     * @param array<string,mixed> $release
     * @return array<string,mixed>
     */
    private static function find_release_asset(
        array $release,
        string $version
    ): array {
        $expectedName = sprintf(
            'wp-oidc-keycloak-integration-v%s.zip',
            $version
        );
        $assets = $release['assets'] ?? null;

        if (!is_array($assets)) {
            throw new RuntimeException(
                'Release does not contain an assets list.'
            );
        }

        foreach ($assets as $asset) {
            if (
                is_array($asset) &&
                ($asset['name'] ?? '') === $expectedName
            ) {
                return $asset;
            }
        }

        throw new RuntimeException(
            'Expected release asset was not found.'
        );
    }

    /** @param array<string,mixed> $asset */
    private static function download_and_verify_asset(
        array $asset
    ): string {
        $url = isset($asset['browser_download_url'])
            ? (string) $asset['browser_download_url']
            : '';
        $digest = isset($asset['digest'])
            ? (string) $asset['digest']
            : '';

        if (
            $url === '' ||
            !preg_match(
                '/^sha256:([a-f0-9]{64})$/',
                strtolower($digest),
                $matches
            )
        ) {
            throw new RuntimeException(
                'Release asset URL or SHA-256 digest is missing.'
            );
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';

        $archive = download_url($url, 60);

        if (is_wp_error($archive)) {
            throw new RuntimeException(
                'Release download failed: ' .
                $archive->get_error_message()
            );
        }

        $actual = hash_file('sha256', $archive);

        if (!hash_equals($matches[1], strtolower($actual))) {
            @unlink($archive);
            throw new RuntimeException(
                'Release asset SHA-256 verification failed.'
            );
        }

        return $archive;
    }

    private static function install_archive(
        string $archive,
        string $version
    ): void {
        require_once ABSPATH . 'wp-admin/includes/file.php';

        $workDir = trailingslashit(get_temp_dir()) .
            'wp-oidc-keycloak-update-' .
            wp_generate_uuid4();
        $extractDir = $workDir . '/extract';
        $backupDir = $workDir . '/backup';

        if (!wp_mkdir_p($extractDir) || !wp_mkdir_p($backupDir)) {
            throw new RuntimeException(
                'Cannot create updater working directory.'
            );
        }

        try {
            $result = unzip_file($archive, $extractDir);

            if (is_wp_error($result)) {
                throw new RuntimeException(
                    'Release extraction failed: ' .
                    $result->get_error_message()
                );
            }

            $packageDir = $extractDir . '/package';
            $manifestFile = $packageDir . '/release.json';

            if (!is_readable($manifestFile)) {
                throw new RuntimeException(
                    'Release manifest is missing.'
                );
            }

            $manifest = json_decode(
                (string) file_get_contents($manifestFile),
                true
            );

            self::validate_manifest(
                $manifest,
                $packageDir,
                $version
            );

            self::atomic_install_files(
                $packageDir,
                $backupDir
            );

            $installedVersion = self::current_version();

            if ($installedVersion !== $version) {
                throw new RuntimeException(
                    'Installed version does not match release version.'
                );
            }
        } finally {
            self::remove_tree($workDir);
        }
    }

    /**
     * @param mixed $manifest
     */
    private static function validate_manifest(
        $manifest,
        string $packageDir,
        string $version
    ): void {
        if (
            !is_array($manifest) ||
            ($manifest['version'] ?? '') !== $version ||
            !isset($manifest['files']) ||
            !is_array($manifest['files'])
        ) {
            throw new RuntimeException(
                'Release manifest is invalid.'
            );
        }

        foreach (self::DEPLOYMENT_FILES as $source => $destination) {
            unset($destination);
            $expected = $manifest['files'][$source] ?? '';
            $path = $packageDir . '/' . $source;

            if (
                !is_string($expected) ||
                !preg_match('/^[a-f0-9]{64}$/', $expected) ||
                !is_readable($path)
            ) {
                throw new RuntimeException(
                    'Release file manifest is incomplete.'
                );
            }

            $actual = hash_file('sha256', $path);

            if (!hash_equals($expected, strtolower($actual))) {
                throw new RuntimeException(
                    'Release file SHA-256 verification failed: ' .
                    $source
                );
            }
        }

        $pluginHead = (string) file_get_contents(
            $packageDir . '/wp-oidc-keycloak-integration.php',
            false,
            null,
            0,
            8192
        );

        if (
            !preg_match(
                '/^[ \t\/*#@]*Version:\s*([^\r\n]+)/mi',
                $pluginHead,
                $matches
            ) ||
            trim($matches[1]) !== $version
        ) {
            throw new RuntimeException(
                'Integration header version does not match release.'
            );
        }
    }

    private static function atomic_install_files(
        string $packageDir,
        string $backupDir
    ): void {
        $replaced = [];

        try {
            foreach (self::DEPLOYMENT_FILES as $source => $destination) {
                $sourcePath = $packageDir . '/' . $source;
                $destinationPath = WPMU_PLUGIN_DIR . '/' . $destination;
                $destinationDir = dirname($destinationPath);

                if (!wp_mkdir_p($destinationDir)) {
                    throw new RuntimeException(
                        'Cannot create destination directory: ' .
                        $destinationDir
                    );
                }

                if (!is_writable($destinationDir)) {
                    throw new RuntimeException(
                        'Destination directory is not writable: ' .
                        $destinationDir
                    );
                }

                $backupPath = $backupDir . '/' . $source;
                wp_mkdir_p(dirname($backupPath));

                if (is_file($destinationPath)) {
                    if (!copy($destinationPath, $backupPath)) {
                        throw new RuntimeException(
                            'Cannot back up destination file: ' .
                            $destination
                        );
                    }
                }

                $newPath = $destinationPath .
                    '.new-' .
                    wp_generate_uuid4();

                if (!copy($sourcePath, $newPath)) {
                    throw new RuntimeException(
                        'Cannot stage update file: ' .
                        $destination
                    );
                }

                @chmod($newPath, 0644);

                if (!rename($newPath, $destinationPath)) {
                    @unlink($newPath);
                    throw new RuntimeException(
                        'Cannot activate update file: ' .
                        $destination
                    );
                }

                $replaced[] = $source;
            }
        } catch (Throwable $exception) {
            self::rollback_files($replaced, $backupDir);
            throw $exception;
        }
    }

    /** @param list<string> $replaced */
    private static function rollback_files(
        array $replaced,
        string $backupDir
    ): void {
        foreach (array_reverse($replaced) as $source) {
            $destination = self::DEPLOYMENT_FILES[$source];
            $destinationPath = WPMU_PLUGIN_DIR . '/' . $destination;
            $backupPath = $backupDir . '/' . $source;

            if (is_file($backupPath)) {
                @copy($backupPath, $destinationPath);
                @chmod($destinationPath, 0644);
            }
        }
    }

    private static function acquire_lock(): void
    {
        $existing = get_site_option(self::LOCK_OPTION, 0);

        if (
            is_numeric($existing) &&
            (int) $existing > 0 &&
            (time() - (int) $existing) < self::LOCK_TTL
        ) {
            throw new RuntimeException(
                'Another WP OIDC Keycloak update is already running.'
            );
        }

        delete_site_option(self::LOCK_OPTION);

        if (!add_site_option(self::LOCK_OPTION, time())) {
            throw new RuntimeException(
                'Could not acquire the WP OIDC Keycloak update lock.'
            );
        }
    }

    private static function release_lock(): void
    {
        delete_site_option(self::LOCK_OPTION);
    }

    private static function remove_tree(string $path): void
    {
        if ($path === '' || !file_exists($path)) {
            return;
        }

        if (is_file($path) || is_link($path)) {
            @unlink($path);
            return;
        }

        $items = scandir($path);

        if (!is_array($items)) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            self::remove_tree($path . '/' . $item);
        }

        @rmdir($path);
    }
}

WP_OIDC_Keycloak_Updater::bootstrap();
