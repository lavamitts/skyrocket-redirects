<?php
/**
 * Plugin Name: Skyrocket Redirects
 * Description: Manage exact-path 301 and 410 redirects from an editable CSV file.
 * Version: 1.0.1
 * Author: Skyrocket Digital Development
 * License: GPL-2.0-or-later
 */

defined('ABSPATH') || exit;

final class Site_Redirects_CSV
{
    private const PAGE_SLUG = 'site-redirects-csv';
    private const LOG_OPTION = 'site_redirects_csv_logging';
    private const MAX_BYTES = 2 * 1024 * 1024;
    private static ?array $rules = null;

    public function __construct()
    {
        add_action('admin_menu', array($this, 'add_admin_page'));
        add_action('admin_post_site_redirects_upload', array($this, 'handle_upload'));
        add_action('admin_post_site_redirects_test', array($this, 'handle_test'));
        add_action('admin_post_site_redirects_settings', array($this, 'handle_settings'));
        add_action('template_redirect', array($this, 'maybe_redirect'), 1);
    }

    public function add_admin_page(): void
    {
        add_management_page(
            'Site Redirects',
            'Site Redirects',
            'manage_options',
            self::PAGE_SLUG,
            array($this, 'render_admin_page')
        );
    }

    private function csv_path(): string
    {
        $uploads = wp_upload_dir();
        return trailingslashit($uploads['basedir']) . 'site-redirects/redirects.csv';
    }

    private function log_path(): string
    {
        $uploads = wp_upload_dir();
        return trailingslashit($uploads['basedir']) . 'site-redirects/redirects.log';
    }

    private function normalise_path(string $path): string
    {
        $path = '/' . ltrim(trim($path), '/');
        return '/' === $path ? '/' : untrailingslashit($path);
    }

    /** Return a local path, rejecting absolute URLs on other hosts. */
    private function local_path(string $value): ?string
    {
        $value = trim($value);
        if ('' === $value || str_starts_with($value, '//')) {
            return null;
        }

        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $value)) {
            $parts = wp_parse_url($value);
            $home_host = strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST));
            if (empty($parts['host']) || strtolower($parts['host']) !== $home_host) {
                return null;
            }
            $path = $parts['path'] ?? '/';
        } else {
            $parts = wp_parse_url($value);
            $path = $parts['path'] ?? '/';
        }

        return $this->normalise_path($path);
    }

    /** Parse and validate CSV. Expected header: old_url,new_url,status. */
    private function parse_csv(string $file): array
    {
        $rules = array();
        $errors = array();

        if (!is_readable($file)) {
            return array(array(), array('The CSV file could not be read.'));
        }

        $handle = fopen($file, 'rb');
        if (false === $handle) {
            return array(array(), array('The CSV file could not be opened.'));
        }

        $header = fgetcsv($handle);
        if (false === $header) {
            fclose($handle);
            return array(array(), array('The CSV file is empty.'));
        }

        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
        $header = array_map(static function ($value) {
            return strtolower(trim((string) $value));
        }, $header);

        if (array_slice($header, 0, 3) !== array('old_url', 'new_url', 'status')) {
            fclose($handle);
            return array(array(), array('The first three columns must be: old_url,new_url,status'));
        }

        $line = 1;
        while (false !== ($data = fgetcsv($handle))) {
            ++$line;
            if (empty($data) || (1 === count($data) && '' === trim((string) $data[0]))) {
                continue;
            }

            $old = trim((string) ($data[0] ?? ''));
            $new = trim((string) ($data[1] ?? ''));
            $status = trim((string) ($data[2] ?? ''));

            if ('' === $old) {
                $errors[] = "Row {$line}: old_url is required.";
                continue;
            }
            if (!in_array($status, array('301', '410'), true)) {
                $errors[] = "Row {$line}: status must be 301 or 410.";
                continue;
            }
            if (false !== strpos($old, '?') || false !== strpos($old, '#')) {
                $errors[] = "Row {$line}: old_url must be a path only (no query string or fragment).";
                continue;
            }

            $old_path = $this->local_path($old);
            if (null === $old_path) {
                $errors[] = "Row {$line}: old_url must be a relative path or an absolute URL on this domain.";
                continue;
            }
            if (isset($rules[$old_path])) {
                $errors[] = "Row {$line}: duplicate old_url '{$old_path}'.";
                continue;
            }

            $new_path = null;
            if ('301' === $status) {
                $new_path = $this->local_path($new);
                if (null === $new_path) {
                    $errors[] = "Row {$line}: a 301 requires a valid same-domain new_url.";
                    continue;
                }
                if ($new_path === $old_path) {
                    $errors[] = "Row {$line}: old_url and new_url are the same path.";
                    continue;
                }
            } elseif ('' !== $new) {
                $errors[] = "Row {$line}: leave new_url blank for a 410 response.";
                continue;
            }

            $rules[$old_path] = array(
                'new_path' => $new_path,
                'status' => (int) $status,
            );
        }

        fclose($handle);
        return array($rules, $errors);
    }

    private function get_rules(): array
    {
        if (null === self::$rules) {
            list($rules, $errors) = $this->parse_csv($this->csv_path());
            self::$rules = empty($errors) ? $rules : array();
        }
        return self::$rules;
    }

    private function set_notice(string $message, string $type = 'error'): void
    {
        set_transient('site_redirects_notice_' . get_current_user_id(), array(
            'message' => $message,
            'type' => $type,
        ), 60);
    }

    private function return_to_admin(): void
    {
        wp_safe_redirect(admin_url('tools.php?page=' . self::PAGE_SLUG));
        exit;
    }

    public function handle_upload(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to do this.', 'site-redirects-csv'), 403);
        }
        check_admin_referer('site_redirects_upload');

        if (empty($_FILES['redirect_csv']['tmp_name']) || !is_uploaded_file($_FILES['redirect_csv']['tmp_name'])) {
            $this->set_notice('Please select a CSV file.');
            $this->return_to_admin();
        }

        $upload = $_FILES['redirect_csv'];
        if (!empty($upload['error']) || (int) $upload['size'] > self::MAX_BYTES) {
            $this->set_notice('Upload failed or the file exceeds the 2 MB limit.');
            $this->return_to_admin();
        }
        if ('csv' !== strtolower(pathinfo((string) $upload['name'], PATHINFO_EXTENSION))) {
            $this->set_notice('Please upload a file with a .csv extension.');
            $this->return_to_admin();
        }

        list($rules, $errors) = $this->parse_csv($upload['tmp_name']);
        if (!empty($errors)) {
            $this->set_notice('CSV not installed: ' . implode(' ', $errors));
            $this->return_to_admin();
        }

        $destination = $this->csv_path();
        $directory = dirname($destination);
        if (!wp_mkdir_p($directory)) {
            $this->set_notice('Could not create the site-redirects directory in uploads.');
            $this->return_to_admin();
        }

        // Best-effort protection for the directory on Apache; CSV contains URL mappings only.
        if (!file_exists($directory . '/index.php')) {
            file_put_contents($directory . '/index.php', "<?php // Silence is golden.\n");
        }
        if (!file_exists($directory . '/.htaccess')) {
            file_put_contents($directory . '/.htaccess', "Options -Indexes\n<FilesMatch \"\\.(csv|log)$\">\nRequire all denied\n</FilesMatch>\n");
        }

        if (!move_uploaded_file($upload['tmp_name'], $destination)) {
            $this->set_notice('Could not save the CSV file.');
            $this->return_to_admin();
        }

        self::$rules = $rules;
        $this->set_notice(sprintf('CSV installed successfully: %d redirect rules loaded.', count($rules)), 'success');
        $this->return_to_admin();
    }

    public function handle_test(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to do this.', 'site-redirects-csv'), 403);
        }
        check_admin_referer('site_redirects_test');

        $path = isset($_POST['test_path']) ? sanitize_text_field(wp_unslash($_POST['test_path'])) : '';
        $key = $this->local_path($path);
        list($rules, $errors) = $this->parse_csv($this->csv_path());

        if (!empty($errors)) {
            $message = 'Cannot test: ' . implode(' ', $errors);
            $type = 'error';
        } elseif (null !== $key && isset($rules[$key])) {
            $rule = $rules[$key];
            $message = 301 === $rule['status'] ? 'Match: 301 → ' . $rule['new_path'] : 'Match: 410 (Gone).';
            $type = 'success';
        } else {
            $message = 'No matching redirect found for that path.';
            $type = 'info';
        }

        $this->set_notice($message, $type);
        $this->return_to_admin();
    }

    public function handle_settings(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to do this.', 'site-redirects-csv'), 403);
        }
        check_admin_referer('site_redirects_settings');
        update_option(self::LOG_OPTION, isset($_POST['enable_logging']) ? '1' : '0', false);
        $this->set_notice('Settings saved.', 'success');
        $this->return_to_admin();
    }

    private function log_redirect(string $path, int $status, ?string $destination): void
    {
        if ('1' !== get_option(self::LOG_OPTION, '0')) {
            return;
        }
        $file = $this->log_path();
        // Keep the log bounded: rotate it once it reaches approximately 1 MB.
        if (file_exists($file) && filesize($file) > 1024 * 1024) {
            @rename($file, $file . '.old');
        }
        $line = sprintf(
            "%s\t%d\t%s\t%s\n",
            gmdate('c'),
            $status,
            str_replace(array("\t", "\n"), ' ', $path),
            str_replace(array("\t", "\n"), ' ', (string) $destination)
        );
        error_log($line, 3, $file);
    }

    public function render_admin_page(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $notice = get_transient('site_redirects_notice_' . get_current_user_id());
        delete_transient('site_redirects_notice_' . get_current_user_id());
        $file = $this->csv_path();
        list($rules, $errors) = file_exists($file) ? $this->parse_csv($file) : array(array(), array());
        ?>
        <div class="wrap">
            <h1>Site Redirects</h1>
            <p>Exact-path redirects loaded from a CSV file. Matching requests are handled before the normal WordPress template is displayed.</p>
            <?php if (is_array($notice)): ?>
                <div class="notice notice-<?php echo esc_attr($notice['type']); ?> is-dismissible">
                    <p><?php echo esc_html($notice['message']); ?></p>
                </div>
            <?php endif; ?>

            <?php if (file_exists($file) && empty($errors)): ?>
                <p><strong>Current rules:</strong> <?php echo esc_html((string) count($rules)); ?></p>
            <?php elseif (!file_exists($file)): ?>
                <p><strong>No CSV has been installed yet.</strong></p>
            <?php else: ?>
                <div class="notice notice-error">
                    <p><?php echo esc_html(implode(' ', $errors)); ?></p>
                </div>
            <?php endif; ?>

            <h2>Upload or replace CSV</h2>
            <p>Required header: <code>old_url,new_url,status</code>. Use <code>301</code> for a permanent redirect or <code>410</code> when content has deliberately been removed. Leave <code>new_url</code> blank for 410.</p>
            <p>Use paths such as <code>/old-page/</code> and <code>/new-page/</code>. Same-domain absolute URLs are also accepted. Matching ignores trailing slashes; incoming query strings are preserved.</p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
                <input type="hidden" name="action" value="site_redirects_upload">
                <?php wp_nonce_field('site_redirects_upload'); ?>
                <input type="file" name="redirect_csv" accept=".csv,text/csv" required>
                <?php submit_button('Validate and install CSV', 'primary', 'submit', false); ?>
            </form>

            <hr>
            <h2>Test a path</h2>
            <p>Checks the current CSV without issuing a redirect.</p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="site_redirects_test">
                <?php wp_nonce_field('site_redirects_test'); ?>
                <input type="text" name="test_path" class="regular-text" placeholder="/old-page/" required>
                <?php submit_button('Test path', 'secondary', 'submit', false); ?>
            </form>

            <hr>
            <h2>Logging</h2>
            <p>Optional: records matched redirects (UTC date/time, status, requested path and destination) in a rotating log file under uploads/site-redirects.</p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="site_redirects_settings">
                <?php wp_nonce_field('site_redirects_settings'); ?>
                <label><input type="checkbox" name="enable_logging" value="1" <?php checked('1', get_option(self::LOG_OPTION, '0')); ?>> Enable redirect logging</label>
                <?php submit_button('Save settings', 'secondary', 'submit', false); ?>
            </form>
        </div>
        <?php
    }

    public function maybe_redirect(): void
    {
        if (is_admin() || wp_doing_ajax() || wp_doing_cron() || (defined('REST_REQUEST') && REST_REQUEST)) {
            return;
        }
        if (empty($_SERVER['REQUEST_URI'])) {
            return;
        }

        $request = wp_parse_url(wp_unslash($_SERVER['REQUEST_URI']));
        $path = $this->normalise_path($request['path'] ?? '/');
        $rules = $this->get_rules();
        if (!isset($rules[$path])) {
            return;
        }

        $rule = $rules[$path];
        if (410 === $rule['status']) {
            $this->log_redirect($path, 410, null);
            status_header(410);
            nocache_headers();
            $template = get_404_template();
            if ($template) {
                include $template;
            } else {
                wp_die(esc_html__('This content is no longer available.', 'site-redirects-csv'), '', array('response' => 410));
            }
            exit;
        }

        $destination = home_url($rule['new_path']);
        if (!empty($request['query']) && false === strpos($destination, '?')) {
            $destination .= '?' . $request['query'];
        }
        $destination_path = $this->normalise_path((string) wp_parse_url($destination, PHP_URL_PATH));
        if ($destination_path === $path) {
            return;
        }

        $this->log_redirect($path, 301, $destination);
        wp_safe_redirect($destination, 301, 'Site Redirects CSV');
        exit;
    }
}

new Site_Redirects_CSV();
