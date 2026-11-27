<?php
if (!defined('ABSPATH')) { exit; }
final class Donation_Box_Admin {
    public static function boot(): void {
        add_action('admin_menu', static function () {
            add_menu_page(__('Pledgly Donations', 'pledgly-donations'), __('Pledgly Donations', 'pledgly-donations'), 'manage_options', 'donation-box', [self::class, 'dashboard'], 'dashicons-heart', 56);
            foreach (['payments' => __('Payments', 'pledgly-donations'), 'subscriptions' => __('Subscriptions', 'pledgly-donations'), 'donors' => __('Donors', 'pledgly-donations'), 'settings' => __('Settings', 'pledgly-donations')] as $slug => $title) {
                add_submenu_page('donation-box', $title, $title, 'manage_options', 'donation-box-' . $slug, [self::class, $slug]);
            }
        });
        add_action('admin_init', static function () {
            register_setting('donation_box', 'donation_box_settings', ['type' => 'array', 'sanitize_callback' => ['Donation_Box_Settings', 'sanitize'], 'show_in_rest' => false]);
        });
        add_action('admin_post_donation_box_export', [self::class, 'export']);
    }
    private static function guard(): void { if (!current_user_can('manage_options')) { wp_die(esc_html__('Access denied.', 'pledgly-donations')); } }
    private static function start(string $title): void { self::guard(); echo '<div class="wrap"><h1>' . esc_html($title) . '</h1>'; }
    public static function dashboard(): void {
        global $wpdb; self::start(__('Pledgly Donations', 'pledgly-donations'));
        $mode = Donation_Box_Settings::get()['mode'];
        /* translators: %s: current payment environment, test or live. */
        echo '<p>' . esc_html(sprintf(__('Current mode: %s', 'pledgly-donations'), $mode)) . '</p>';
        $table = Donation_Box_Store::table('payments');
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned financial tables require current state for reporting, ownership and webhook processing.
        $totals = $wpdb->get_results($wpdb->prepare("SELECT currency, SUM(amount - refunded_amount) AS total, COUNT(*) AS count FROM %i WHERE status IN ('paid','refunded','partially_refunded') AND mode = %s GROUP BY currency", $table, $mode));
        foreach ($totals as $total) { echo '<p><strong>' . esc_html(Donation_Box_Frontend::money($total->total, $total->currency)) . '</strong> · ' . esc_html($total->count) . ' ' . esc_html__('confirmed donations', 'pledgly-donations') . '</p>'; }
        if (!$totals) { echo '<p>' . esc_html__('No confirmed donations yet.', 'pledgly-donations') . '</p>'; }
        echo '<p>' . esc_html__('Totals show confirmed donations minus full and partial refunds, grouped by currency.', 'pledgly-donations') . '</p>';
        echo '<h2>' . esc_html__('Add to your website', 'pledgly-donations') . '</h2><p>' . esc_html__('Paste these shortcodes into a page or an Elementor Shortcode widget:', 'pledgly-donations') . '</p><p><code>[donation_box]</code> · <code>[donation_box_account]</code></p>';
        if (defined('WP_HTTP_BLOCK_EXTERNAL') && WP_HTTP_BLOCK_EXTERNAL) { echo '<div class="notice notice-warning inline"><p>' . esc_html__('External HTTP requests are blocked on this installation. Allow api.stripe.com in WP_ACCESSIBLE_HOSTS before testing Stripe.', 'pledgly-donations') . '</p></div>'; }
        echo '</div>';
    }
    public static function settings(): void {
        self::start(__('Pledgly Donations settings', 'pledgly-donations')); $s = Donation_Box_Settings::get(); settings_errors('donation_box_settings'); ?>
        <form method="post" action="options.php">
        <?php settings_fields('donation_box'); ?>
        <h2><?php esc_html_e('Donation form', 'pledgly-donations'); ?></h2><table class="form-table" role="presentation">
        <?php foreach (['title' => __('Title', 'pledgly-donations'), 'description' => __('Description', 'pledgly-donations'), 'amounts' => __('Suggested amounts (comma-separated)', 'pledgly-donations')] as $key => $label): ?>
        <tr><th><label for="dbox-<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></label></th><td><input class="regular-text" id="dbox-<?php echo esc_attr($key); ?>" name="donation_box_settings[<?php echo esc_attr($key); ?>]" value="<?php echo esc_attr($s[$key]); ?>"></td></tr>
        <?php endforeach; ?>
        <tr><th><label for="dbox-currency"><?php esc_html_e('Currency', 'pledgly-donations'); ?></label></th><td><select id="dbox-currency" name="donation_box_settings[currency]">
        <?php foreach (['eur', 'usd', 'gbp'] as $currency): ?><option value="<?php echo esc_attr($currency); ?>" <?php selected($s['currency'], $currency); ?>><?php echo esc_html(strtoupper($currency)); ?></option><?php endforeach; ?></select></td></tr></table>
        <h2><?php esc_html_e('Colors', 'pledgly-donations'); ?></h2><p><?php esc_html_e('Choose colors to match your website.', 'pledgly-donations'); ?></p>
        <table class="form-table" role="presentation">
        <?php foreach (Donation_Box_Settings::colors() as $key => $color): ?>
        <?php /* translators: %s: default hexadecimal colour value. */ ?>
        <tr><th><label for="dbox-<?php echo esc_attr($key); ?>"><?php echo esc_html($color[0]); ?></label></th><td><input type="color" id="dbox-<?php echo esc_attr($key); ?>" name="donation_box_settings[<?php echo esc_attr($key); ?>]" value="<?php echo esc_attr($s[$key]); ?>"> <code><?php echo esc_html($s[$key]); ?></code> <span class="description"><?php echo esc_html(sprintf(__('Default: %s', 'pledgly-donations'), $color[1])); ?></span></td></tr>
        <?php endforeach; ?></table>
        <h2><?php esc_html_e('Stripe', 'pledgly-donations'); ?></h2>
        <p><?php esc_html_e('Stripe Checkout hosts the payment form, so no publishable key is needed. Secret fields are never displayed again. Leave blank to keep the saved value.', 'pledgly-donations'); ?></p>
        <table class="form-table" role="presentation"><tr><th><label for="dbox-mode"><?php esc_html_e('Payment mode', 'pledgly-donations'); ?></label></th><td><select id="dbox-mode" name="donation_box_settings[mode]"><option value="test" <?php selected($s['mode'], 'test'); ?>><?php esc_html_e('Test', 'pledgly-donations'); ?></option><option value="live" <?php selected($s['mode'], 'live'); ?>><?php esc_html_e('Live', 'pledgly-donations'); ?></option></select></td></tr></table>
        <?php foreach (['test' => __('Test credentials', 'pledgly-donations'), 'live' => __('Live credentials', 'pledgly-donations')] as $mode => $heading): ?>
        <h3><?php echo esc_html($heading); ?></h3><table class="form-table" role="presentation">
        <?php foreach (['key' => __('Secret API key', 'pledgly-donations'), 'webhook' => __('Webhook signing secret', 'pledgly-donations')] as $type => $label): $key = $mode . '_' . $type; $constant = 'DONATION_BOX_' . strtoupper($key); ?>
        <tr><th><label for="dbox-<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></label></th><td>
        <?php if (defined($constant)): ?><code><?php echo esc_html($constant); ?></code><p><?php esc_html_e('Managed in wp-config.php.', 'pledgly-donations'); ?></p>
        <?php else: ?><input class="regular-text" type="password" autocomplete="new-password" id="dbox-<?php echo esc_attr($key); ?>" name="donation_box_settings[<?php echo esc_attr($key); ?>]" value="" placeholder="<?php echo esc_attr($type === 'key' ? 'sk_' . $mode . '_…' : 'whsec_…'); ?>">
        <p><?php echo esc_html(Donation_Box_Settings::secret($mode, $type) ? __('Configured', 'pledgly-donations') : __('Not configured', 'pledgly-donations')); ?> · <label><input type="checkbox" name="donation_box_settings[clear_<?php echo esc_attr($key); ?>]" value="1"> <?php esc_html_e('Clear saved credential', 'pledgly-donations'); ?></label></p><?php endif; ?></td></tr>
        <?php endforeach; ?>
        <tr><th><?php esc_html_e('Webhook URL', 'pledgly-donations'); ?></th><td><input aria-label="<?php esc_attr_e('Webhook URL', 'pledgly-donations'); ?>" readonly class="large-text code" onclick="this.select()" value="<?php echo esc_url(rest_url('donation-box/v1/webhook/' . $mode)); ?>"><p class="description"><?php esc_html_e('Copy this URL into a Stripe webhook destination. This URL is generated by WordPress and does not need manual editing.', 'pledgly-donations'); ?></p></td></tr>
        </table><?php endforeach; ?>
        <p><?php esc_html_e('Configure the webhook destination to use API version 2024-06-20 and enable these events:', 'pledgly-donations'); ?></p>
        <code>checkout.session.completed, checkout.session.async_payment_succeeded, checkout.session.async_payment_failed, checkout.session.expired, invoice.paid, invoice.payment_failed, customer.subscription.created, customer.subscription.updated, customer.subscription.deleted, charge.refunded</code>
        <p><?php esc_html_e('Enable the Stripe Customer Portal to let donors manage payment methods and cancel subscriptions. Configure payment methods and receipt emails in Stripe.', 'pledgly-donations'); ?></p>
        <?php submit_button(); ?></form></div><?php
    }
    private static function filters(string $page): array {
        $mode = sanitize_key(Donation_Box_Settings::query_value('mode', Donation_Box_Settings::get()['mode'])) === 'live' ? 'live' : 'test';
        $status = sanitize_key(Donation_Box_Settings::query_value('status', '')); $search = sanitize_text_field(Donation_Box_Settings::query_value('search', ''));
        echo '<form method="get"><input type="hidden" name="page" value="' . esc_attr($page) . '"><p><label>' . esc_html__('Mode', 'pledgly-donations') . ' <select name="mode">';
        foreach (['test', 'live'] as $value) { echo '<option value="' . esc_attr($value) . '" ' . selected($mode, $value, false) . '>' . esc_html($value) . '</option>'; }
        echo '</select></label> <label>' . esc_html__('Status', 'pledgly-donations') . ' <input name="status" value="' . esc_attr($status) . '"></label> <label>' . esc_html__('Email', 'pledgly-donations') . ' <input name="search" value="' . esc_attr($search) . '"></label> <button class="button">' . esc_html__('Filter', 'pledgly-donations') . '</button></p></form>';
        return [$mode, $status, $search];
    }
    public static function payments(): void { self::listing('payments'); }
    public static function subscriptions(): void { self::listing('subscriptions'); }
    private static function listing(string $kind): void {
        global $wpdb; self::start($kind === 'payments' ? __('Payments', 'pledgly-donations') : __('Subscriptions', 'pledgly-donations'));
        [$mode, $status, $search] = self::filters('donation-box-' . $kind);
        $email_pattern = '%' . $wpdb->esc_like($search) . '%';
        $page = max(1, absint(Donation_Box_Settings::query_value('paged', '1'))); $table = Donation_Box_Store::table($kind);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned financial tables require current state for reporting, ownership and webhook processing.
        $count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i WHERE mode = %s AND (%s = '' OR status = %s) AND email LIKE %s", $table, $mode, $status, $status, $email_pattern));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned financial tables require current state for reporting, ownership and webhook processing.
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM %i WHERE mode = %s AND (%s = '' OR status = %s) AND email LIKE %s ORDER BY id DESC LIMIT 25 OFFSET %d", $table, $mode, $status, $status, $email_pattern, ($page - 1) * 25));
        echo '<p><a class="button" href="' . esc_url(wp_nonce_url(add_query_arg(['action' => 'donation_box_export', 'kind' => $kind, 'mode' => $mode, 'status' => $status, 'search' => $search], admin_url('admin-post.php')), 'donation_box_export')) . '">' . esc_html__('Export filtered CSV', 'pledgly-donations') . '</a></p>';
        echo '<table class="widefat striped"><thead><tr>';
        foreach ([__('Email', 'pledgly-donations'), __('Amount', 'pledgly-donations'), __('Status', 'pledgly-donations'), __('Date (UTC)', 'pledgly-donations'), __('Stripe', 'pledgly-donations')] as $heading) { echo '<th>' . esc_html($heading) . '</th>'; }
        echo '</tr></thead><tbody>';
        foreach ($rows as $row) {
            $stripe = $row->stripe_id;
            $path = str_starts_with($stripe, 'in_') ? 'invoices/' : ($kind === 'subscriptions' ? 'subscriptions/' : 'payments/');
            $url = 'https://dashboard.stripe.com/' . ($mode === 'test' ? 'test/' : '') . $path . rawurlencode($stripe);
            echo '<tr><td>' . esc_html($row->email) . '</td><td>' . esc_html(Donation_Box_Frontend::money($row->amount, $row->currency)) . '</td><td>' . esc_html($row->status);
            if ($kind === 'subscriptions' && $row->cancel_at_period_end) { echo ' · ' . esc_html__('Cancels at period end', 'pledgly-donations'); }
            echo '</td><td>' . esc_html($kind === 'payments' ? $row->created_at : ($row->period_end ? gmdate('Y-m-d H:i:s', $row->period_end) : '—')) . '</td><td>';
            if ($stripe) { echo '<a target="_blank" rel="noopener noreferrer" href="' . esc_url($url) . '">' . esc_html($stripe) . '</a>'; }
            echo '</td></tr>';
        }
        if (!$rows) { echo '<tr><td colspan="5">' . esc_html__('No records found.', 'pledgly-donations') . '</td></tr>'; }
        echo '</tbody></table>';
        echo wp_kses_post(paginate_links(['base' => add_query_arg('paged', '%#%'), 'format' => '', 'current' => $page, 'total' => max(1, (int) ceil($count / 25))]));
        echo '</div>';
    }
    public static function donors(): void {
        global $wpdb; self::start(__('Donors', 'pledgly-donations'));
        [$mode, $status, $search] = self::filters('donation-box-donors');
        $table = Donation_Box_Store::table('payments');
        $email_pattern = '%' . $wpdb->esc_like($search) . '%';
        $page = max(1, absint(Donation_Box_Settings::query_value('paged', '1')));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned financial tables require current state for reporting, ownership and webhook processing.
        $count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT email) FROM %i WHERE mode = %s AND status IN ('paid','refunded','partially_refunded') AND (%s = '' OR status = %s) AND email LIKE %s", $table, $mode, $status, $status, $email_pattern));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned financial tables require current state for reporting, ownership and webhook processing.
        $rows = $wpdb->get_results($wpdb->prepare("SELECT email, MAX(user_id) AS user_id, COUNT(*) AS donations FROM %i WHERE mode = %s AND status IN ('paid','refunded','partially_refunded') AND (%s = '' OR status = %s) AND email LIKE %s GROUP BY email ORDER BY email LIMIT 25 OFFSET %d", $table, $mode, $status, $status, $email_pattern, ($page - 1) * 25));
        echo '<table class="widefat striped"><thead><tr><th>' . esc_html__('Email', 'pledgly-donations') . '</th><th>' . esc_html__('WordPress user', 'pledgly-donations') . '</th><th>' . esc_html__('Donations', 'pledgly-donations') . '</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            echo '<tr><td>' . esc_html($row->email) . '</td><td>';
            if ($row->user_id) { echo '<a href="' . esc_url(get_edit_user_link($row->user_id)) . '">' . esc_html($row->user_id) . '</a>'; }
            echo '</td><td>' . esc_html($row->donations) . '</td></tr>';
        }
        echo '</tbody></table>';
        echo wp_kses_post(paginate_links(['base' => add_query_arg('paged', '%#%'), 'format' => '', 'current' => $page, 'total' => max(1, (int) ceil($count / 25))])); echo '</div>';
    }
    public static function csv_cell($value): string {
        $value = (string) $value;
        return preg_match('/^[\s]*[=+@\-]/u', $value) ? "'" . $value : $value;
    }
    public static function export(): void {
        self::guard(); check_admin_referer('donation_box_export'); global $wpdb;
        $kind = sanitize_key(Donation_Box_Settings::query_value('kind', '')) === 'subscriptions' ? 'subscriptions' : 'payments';
        $mode = sanitize_key(Donation_Box_Settings::query_value('mode', '')) === 'live' ? 'live' : 'test';
        $status = sanitize_key(Donation_Box_Settings::query_value('status', '')); $search = sanitize_text_field(Donation_Box_Settings::query_value('search', ''));
        $email_pattern = '%' . $wpdb->esc_like($search) . '%';
        nocache_headers(); header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="donation-box-' . $kind . '-' . $mode . '.csv"');
        $stream = fopen('php://output', 'w'); $last = 0; $header = false;
        do {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned financial tables require current state for reporting, ownership and webhook processing.
            $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM %i WHERE mode = %s AND (%s = '' OR status = %s) AND email LIKE %s AND id > %d ORDER BY id LIMIT 500", Donation_Box_Store::table($kind), $mode, $status, $status, $email_pattern, $last), ARRAY_A);
            foreach ($rows as $row) {
                if (!$header) { fputcsv($stream, array_keys($row), ',', '"', ''); $header = true; }
                fputcsv($stream, array_map([self::class, 'csv_cell'], $row), ',', '"', ''); $last = (int) $row['id'];
            }
        } while (count($rows) === 500);
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes the HTTP output stream, not a filesystem file.
        fclose($stream); exit;
    }
}
