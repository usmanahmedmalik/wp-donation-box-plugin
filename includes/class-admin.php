<?php
if (!defined('ABSPATH')) { exit; }
final class Donation_Box_Admin {
    public static function boot(): void {
        add_action('admin_menu', static function () {
            add_menu_page(__('Donation Box', 'donation-box'), __('Donation Box', 'donation-box'), 'manage_options', 'donation-box', [self::class, 'dashboard'], 'dashicons-heart', 56);
            foreach (['payments' => __('Payments', 'donation-box'), 'subscriptions' => __('Subscriptions', 'donation-box'), 'donors' => __('Donors', 'donation-box'), 'settings' => __('Settings', 'donation-box')] as $slug => $title) {
                add_submenu_page('donation-box', $title, $title, 'manage_options', 'donation-box-' . $slug, [self::class, $slug]);
            }
        });
        add_action('admin_init', static function () {
            register_setting('donation_box', 'donation_box_settings', ['type' => 'array', 'sanitize_callback' => ['Donation_Box_Settings', 'sanitize'], 'show_in_rest' => false]);
        });
        add_action('admin_post_donation_box_export', [self::class, 'export']);
    }
    private static function guard(): void { if (!current_user_can('manage_options')) { wp_die(esc_html__('Access denied.', 'donation-box')); } }
    private static function start(string $title): void { self::guard(); echo '<div class="wrap"><h1>' . esc_html($title) . '</h1>'; }
    public static function dashboard(): void {
        global $wpdb; self::start(__('Donation Box', 'donation-box'));
        $mode = Donation_Box_Settings::get()['mode'];
        echo '<p>' . esc_html(sprintf(__('Current mode: %s', 'donation-box'), $mode)) . '</p>';
        $table = Donation_Box_Store::table('payments');
        $totals = $wpdb->get_results($wpdb->prepare("SELECT currency, SUM(amount - refunded_amount) AS total, COUNT(*) AS count FROM $table WHERE status IN ('paid','refunded','partially_refunded') AND mode = %s GROUP BY currency", $mode));
        foreach ($totals as $total) { echo '<p><strong>' . esc_html(Donation_Box_Frontend::money($total->total, $total->currency)) . '</strong> · ' . esc_html($total->count) . ' ' . esc_html__('confirmed donations', 'donation-box') . '</p>'; }
        if (!$totals) { echo '<p>' . esc_html__('No confirmed donations yet.', 'donation-box') . '</p>'; }
        echo '<p>' . esc_html__('Totals show confirmed donations minus full and partial refunds, grouped by currency.', 'donation-box') . '</p>';
        echo '<h2>' . esc_html__('Add to your website', 'donation-box') . '</h2><p>' . esc_html__('Paste these shortcodes into a page or an Elementor Shortcode widget:', 'donation-box') . '</p><p><code>[donation_box]</code> · <code>[donation_box_account]</code></p>';
        if (defined('WP_HTTP_BLOCK_EXTERNAL') && WP_HTTP_BLOCK_EXTERNAL) { echo '<div class="notice notice-warning inline"><p>' . esc_html__('External HTTP requests are blocked on this installation. Allow api.stripe.com in WP_ACCESSIBLE_HOSTS before testing Stripe.', 'donation-box') . '</p></div>'; }
        echo '</div>';
    }
    public static function settings(): void {
        self::start(__('Donation Box settings', 'donation-box')); $s = Donation_Box_Settings::get(); settings_errors('donation_box_settings'); ?>
        <form method="post" action="options.php">
        <?php settings_fields('donation_box'); ?>
        <h2><?php esc_html_e('Donation form', 'donation-box'); ?></h2><table class="form-table" role="presentation">
        <?php foreach (['title' => __('Title', 'donation-box'), 'description' => __('Description', 'donation-box'), 'amounts' => __('Suggested amounts (comma-separated)', 'donation-box')] as $key => $label): ?>
        <tr><th><label for="dbox-<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></label></th><td><input class="regular-text" id="dbox-<?php echo esc_attr($key); ?>" name="donation_box_settings[<?php echo esc_attr($key); ?>]" value="<?php echo esc_attr($s[$key]); ?>"></td></tr>
        <?php endforeach; ?>
        <tr><th><label for="dbox-currency"><?php esc_html_e('Currency', 'donation-box'); ?></label></th><td><select id="dbox-currency" name="donation_box_settings[currency]">
        <?php foreach (['eur', 'usd', 'gbp'] as $currency): ?><option value="<?php echo esc_attr($currency); ?>" <?php selected($s['currency'], $currency); ?>><?php echo esc_html(strtoupper($currency)); ?></option><?php endforeach; ?></select></td></tr></table>
        <h2><?php esc_html_e('Colors', 'donation-box'); ?></h2><p><?php esc_html_e('Defaults match the ABAM website. Change these to match any theme.', 'donation-box'); ?></p>
        <table class="form-table" role="presentation">
        <?php foreach (Donation_Box_Settings::colors() as $key => $color): ?>
        <tr><th><label for="dbox-<?php echo esc_attr($key); ?>"><?php echo esc_html($color[0]); ?></label></th><td><input type="color" id="dbox-<?php echo esc_attr($key); ?>" name="donation_box_settings[<?php echo esc_attr($key); ?>]" value="<?php echo esc_attr($s[$key]); ?>"> <code><?php echo esc_html($s[$key]); ?></code> <span class="description"><?php echo esc_html(sprintf(__('Default: %s', 'donation-box'), $color[1])); ?></span></td></tr>
        <?php endforeach; ?></table>
        <h2><?php esc_html_e('Stripe', 'donation-box'); ?></h2>
        <p><?php esc_html_e('Stripe Checkout hosts the payment form, so no publishable key is needed. Secret fields are never displayed again. Leave blank to keep the saved value.', 'donation-box'); ?></p>
        <table class="form-table" role="presentation"><tr><th><label for="dbox-mode"><?php esc_html_e('Payment mode', 'donation-box'); ?></label></th><td><select id="dbox-mode" name="donation_box_settings[mode]"><option value="test" <?php selected($s['mode'], 'test'); ?>><?php esc_html_e('Test', 'donation-box'); ?></option><option value="live" <?php selected($s['mode'], 'live'); ?>><?php esc_html_e('Live', 'donation-box'); ?></option></select></td></tr></table>
        <?php foreach (['test' => __('Test credentials', 'donation-box'), 'live' => __('Live credentials', 'donation-box')] as $mode => $heading): ?>
        <h3><?php echo esc_html($heading); ?></h3><table class="form-table" role="presentation">
        <?php foreach (['key' => __('Secret API key', 'donation-box'), 'webhook' => __('Webhook signing secret', 'donation-box')] as $type => $label): $key = $mode . '_' . $type; $constant = 'DONATION_BOX_' . strtoupper($key); ?>
        <tr><th><label for="dbox-<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></label></th><td>
        <?php if (defined($constant)): ?><code><?php echo esc_html($constant); ?></code><p><?php esc_html_e('Managed in wp-config.php.', 'donation-box'); ?></p>
        <?php else: ?><input class="regular-text" type="password" autocomplete="new-password" id="dbox-<?php echo esc_attr($key); ?>" name="donation_box_settings[<?php echo esc_attr($key); ?>]" value="" placeholder="<?php echo esc_attr($type === 'key' ? 'sk_' . $mode . '_…' : 'whsec_…'); ?>">
        <p><?php echo esc_html(Donation_Box_Settings::secret($mode, $type) ? __('Configured', 'donation-box') : __('Not configured', 'donation-box')); ?> · <label><input type="checkbox" name="donation_box_settings[clear_<?php echo esc_attr($key); ?>]" value="1"> <?php esc_html_e('Clear saved credential', 'donation-box'); ?></label></p><?php endif; ?></td></tr>
        <?php endforeach; ?>
        <tr><th><?php esc_html_e('Webhook URL', 'donation-box'); ?></th><td><input aria-label="<?php esc_attr_e('Webhook URL', 'donation-box'); ?>" readonly class="large-text code" onclick="this.select()" value="<?php echo esc_url(rest_url('donation-box/v1/webhook/' . $mode)); ?>"><p class="description"><?php esc_html_e('Copy this URL into a Stripe webhook destination. This URL is generated by WordPress and does not need manual editing.', 'donation-box'); ?></p></td></tr>
        </table><?php endforeach; ?>
        <p><?php esc_html_e('Configure the webhook destination to use API version 2024-06-20 and enable these events:', 'donation-box'); ?></p>
        <code>checkout.session.completed, checkout.session.async_payment_succeeded, checkout.session.async_payment_failed, checkout.session.expired, invoice.paid, invoice.payment_failed, customer.subscription.created, customer.subscription.updated, customer.subscription.deleted, charge.refunded</code>
        <p><?php esc_html_e('Enable the Stripe Customer Portal to let donors manage payment methods and cancel subscriptions. Configure payment methods and receipt emails in Stripe.', 'donation-box'); ?></p>
        <?php submit_button(); ?></form></div><?php
    }
    private static function filters(string $page): array {
        $mode = ($_GET['mode'] ?? Donation_Box_Settings::get()['mode']) === 'live' ? 'live' : 'test';
        $status = sanitize_key($_GET['status'] ?? ''); $search = sanitize_text_field(wp_unslash($_GET['search'] ?? ''));
        echo '<form method="get"><input type="hidden" name="page" value="' . esc_attr($page) . '"><p><label>' . esc_html__('Mode', 'donation-box') . ' <select name="mode">';
        foreach (['test', 'live'] as $value) { echo '<option value="' . esc_attr($value) . '" ' . selected($mode, $value, false) . '>' . esc_html($value) . '</option>'; }
        echo '</select></label> <label>' . esc_html__('Status', 'donation-box') . ' <input name="status" value="' . esc_attr($status) . '"></label> <label>' . esc_html__('Email', 'donation-box') . ' <input name="search" value="' . esc_attr($search) . '"></label> <button class="button">' . esc_html__('Filter', 'donation-box') . '</button></p></form>';
        return [$mode, $status, $search];
    }
    public static function payments(): void { self::listing('payments'); }
    public static function subscriptions(): void { self::listing('subscriptions'); }
    private static function listing(string $kind): void {
        global $wpdb; self::start($kind === 'payments' ? __('Payments', 'donation-box') : __('Subscriptions', 'donation-box'));
        [$mode, $status, $search] = self::filters('donation-box-' . $kind);
        $where = $wpdb->prepare('mode = %s', $mode);
        if ($status) { $where .= $wpdb->prepare(' AND status = %s', $status); }
        if ($search) { $where .= $wpdb->prepare(' AND email LIKE %s', '%' . $wpdb->esc_like($search) . '%'); }
        $page = max(1, absint($_GET['paged'] ?? 1)); $table = Donation_Box_Store::table($kind);
        $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE $where");
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE $where ORDER BY id DESC LIMIT 25 OFFSET %d", ($page - 1) * 25));
        echo '<p><a class="button" href="' . esc_url(wp_nonce_url(add_query_arg(['action' => 'donation_box_export', 'kind' => $kind, 'mode' => $mode, 'status' => $status, 'search' => $search], admin_url('admin-post.php')), 'donation_box_export')) . '">' . esc_html__('Export filtered CSV', 'donation-box') . '</a></p>';
        echo '<table class="widefat striped"><thead><tr>';
        foreach ([__('Email', 'donation-box'), __('Amount', 'donation-box'), __('Status', 'donation-box'), __('Date (UTC)', 'donation-box'), __('Stripe', 'donation-box')] as $heading) { echo '<th>' . esc_html($heading) . '</th>'; }
        echo '</tr></thead><tbody>';
        foreach ($rows as $row) {
            $stripe = $row->stripe_id;
            $path = str_starts_with($stripe, 'in_') ? 'invoices/' : ($kind === 'subscriptions' ? 'subscriptions/' : 'payments/');
            $url = 'https://dashboard.stripe.com/' . ($mode === 'test' ? 'test/' : '') . $path . rawurlencode($stripe);
            echo '<tr><td>' . esc_html($row->email) . '</td><td>' . esc_html(Donation_Box_Frontend::money($row->amount, $row->currency)) . '</td><td>' . esc_html($row->status);
            if ($kind === 'subscriptions' && $row->cancel_at_period_end) { echo ' · ' . esc_html__('Cancels at period end', 'donation-box'); }
            echo '</td><td>' . esc_html($kind === 'payments' ? $row->created_at : ($row->period_end ? gmdate('Y-m-d H:i:s', $row->period_end) : '—')) . '</td><td>';
            if ($stripe) { echo '<a target="_blank" rel="noopener noreferrer" href="' . esc_url($url) . '">' . esc_html($stripe) . '</a>'; }
            echo '</td></tr>';
        }
        if (!$rows) { echo '<tr><td colspan="5">' . esc_html__('No records found.', 'donation-box') . '</td></tr>'; }
        echo '</tbody></table>';
        echo wp_kses_post(paginate_links(['base' => add_query_arg('paged', '%#%'), 'format' => '', 'current' => $page, 'total' => max(1, (int) ceil($count / 25))]));
        echo '</div>';
    }
    public static function donors(): void {
        global $wpdb; self::start(__('Donors', 'donation-box'));
        [$mode, $status, $search] = self::filters('donation-box-donors');
        $table = Donation_Box_Store::table('payments');
        $where = $wpdb->prepare("mode = %s AND status IN ('paid','refunded','partially_refunded') AND email LIKE %s", $mode, '%' . $wpdb->esc_like($search) . '%');
        if ($status) { $where .= $wpdb->prepare(' AND status = %s', $status); }
        $page = max(1, absint($_GET['paged'] ?? 1));
        $count = (int) $wpdb->get_var("SELECT COUNT(DISTINCT email) FROM $table WHERE $where");
        $rows = $wpdb->get_results($wpdb->prepare("SELECT email, MAX(user_id) AS user_id, COUNT(*) AS donations FROM $table WHERE $where GROUP BY email ORDER BY email LIMIT 25 OFFSET %d", ($page - 1) * 25));
        echo '<table class="widefat striped"><thead><tr><th>' . esc_html__('Email', 'donation-box') . '</th><th>' . esc_html__('WordPress user', 'donation-box') . '</th><th>' . esc_html__('Donations', 'donation-box') . '</th></tr></thead><tbody>';
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
        $kind = ($_GET['kind'] ?? '') === 'subscriptions' ? 'subscriptions' : 'payments';
        $mode = ($_GET['mode'] ?? '') === 'live' ? 'live' : 'test';
        $status = sanitize_key($_GET['status'] ?? ''); $search = sanitize_text_field(wp_unslash($_GET['search'] ?? ''));
        $where = $wpdb->prepare('mode = %s', $mode);
        if ($status) { $where .= $wpdb->prepare(' AND status = %s', $status); }
        if ($search) { $where .= $wpdb->prepare(' AND email LIKE %s', '%' . $wpdb->esc_like($search) . '%'); }
        nocache_headers(); header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="donation-box-' . $kind . '-' . $mode . '.csv"');
        $stream = fopen('php://output', 'w'); $last = 0; $header = false;
        do {
            $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . Donation_Box_Store::table($kind) . " WHERE $where AND id > %d ORDER BY id LIMIT 500", $last), ARRAY_A);
            foreach ($rows as $row) {
                if (!$header) { fputcsv($stream, array_keys($row), ',', '"', ''); $header = true; }
                fputcsv($stream, array_map([self::class, 'csv_cell'], $row), ',', '"', ''); $last = (int) $row['id'];
            }
        } while (count($rows) === 500);
        fclose($stream); exit;
    }
}
