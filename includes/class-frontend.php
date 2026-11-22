<?php
if (!defined('ABSPATH')) { exit; }
final class Donation_Box_Frontend {
    public static function boot(): void {
        add_shortcode('donation_box', [self::class, 'form']);
        add_shortcode('donation_box_account', [self::class, 'account']);
        add_action('wp_enqueue_scripts', static function () {
            wp_register_style('donation-box', plugins_url('assets/donation-box.css', DONATION_BOX_FILE), [], DONATION_BOX_VERSION);
            wp_register_script('donation-box', plugins_url('assets/donation-box.js', DONATION_BOX_FILE), [], DONATION_BOX_VERSION, true);
        });
    }
    private static function assets(): void {
        wp_enqueue_style('donation-box'); wp_enqueue_script('donation-box');
        wp_localize_script('donation-box', 'DonationBoxI18n', [
            'connection' => __('Unable to connect. Please try again.', 'donation-box'),
            'redirect' => __('Invalid payment redirect.', 'donation-box'),
        ]);
    }
    public static function money($cents, string $currency): string { return strtoupper($currency) . ' ' . number_format_i18n((int) $cents / 100, 2); }
    public static function form(): string {
        self::assets(); $s = Donation_Box_Settings::get(); $id = wp_unique_id('dbox-');
        $user = wp_get_current_user();
        ob_start(); ?>
        <section class="donation-box" style="<?php echo esc_attr(Donation_Box_Settings::color_style()); ?>" aria-labelledby="<?php echo esc_attr($id); ?>title">
            <?php if ($s['mode'] === 'test'): ?><p class="dbox-badge"><?php esc_html_e('Test mode — no real payments', 'donation-box'); ?></p><?php endif; ?>
            <h2 id="<?php echo esc_attr($id); ?>title"><?php echo esc_html($s['title']); ?></h2>
            <p class="dbox-description"><?php echo esc_html($s['description']); ?></p>
            <?php $result = sanitize_key(Donation_Box_Settings::query_value('donation_box', ''));
            if ($result === 'received'): ?><p role="status" class="dbox-notice"><?php esc_html_e('Thank you. Your payment is being confirmed. It will appear in your account once confirmation arrives.', 'donation-box'); ?></p>
            <?php elseif ($result === 'cancelled'): ?><p role="status" class="dbox-notice"><?php esc_html_e('Checkout was cancelled. You can try again whenever you are ready.', 'donation-box'); ?></p><?php endif; ?>
            <form class="dbox-form" data-endpoint="<?php echo esc_url(rest_url('donation-box/v1/checkout')); ?>" data-token-endpoint="<?php echo esc_url(rest_url('donation-box/v1/checkout-token')); ?>">
                <p class="dbox-steps"><span data-step-label="1" aria-current="step"><?php esc_html_e('1. Your donation', 'donation-box'); ?></span><span data-step-label="2"><?php esc_html_e('2. Your details', 'donation-box'); ?></span></p>
                <div data-step="1">
                    <fieldset><legend><?php esc_html_e('How often?', 'donation-box'); ?></legend>
                        <div class="dbox-frequency"><label><input type="radio" name="frequency" value="once" checked><span><?php esc_html_e('One time', 'donation-box'); ?></span></label><label><input type="radio" name="frequency" value="monthly"><span><?php esc_html_e('Monthly', 'donation-box'); ?></span></label></div>
                    </fieldset>
                    <fieldset><legend><?php esc_html_e('Choose your amount', 'donation-box'); ?></legend><div class="dbox-amounts">
                    <?php foreach (explode(',', $s['amounts']) as $amount): ?>
                        <button type="button" data-amount="<?php echo esc_attr($amount); ?>" aria-pressed="false"><?php echo esc_html(self::money(Donation_Box_Stripe::amount($amount), $s['currency'])); ?></button>
                    <?php endforeach; ?></div></fieldset>
                    <?php /* translators: %s: three-letter currency code, for example EUR. */ ?>
                    <label for="<?php echo esc_attr($id); ?>amount"><?php echo esc_html(sprintf(__('Amount (%s)', 'donation-box'), strtoupper($s['currency']))); ?></label>
                    <input id="<?php echo esc_attr($id); ?>amount" name="amount" type="number" min="1" max="99999.99" step="0.01" inputmode="decimal" placeholder="25.00" required>
                    <button type="button" class="dbox-primary" data-next><?php esc_html_e('Continue', 'donation-box'); ?> <span aria-hidden="true">→</span></button>
                </div>
                <div data-step="2" hidden>
                    <div class="dbox-names">
                    <?php foreach (['first_name' => __('First name', 'donation-box'), 'last_name' => __('Last name', 'donation-box')] as $name => $label): ?>
                        <div><label for="<?php echo esc_attr($id . $name); ?>"><?php echo esc_html($label); ?></label><input id="<?php echo esc_attr($id . $name); ?>" name="<?php echo esc_attr($name); ?>" autocomplete="<?php echo $name === 'first_name' ? 'given-name' : 'family-name'; ?>" maxlength="100" value="<?php echo esc_attr($user->$name); ?>" required disabled></div>
                    <?php endforeach; ?></div>
                    <label for="<?php echo esc_attr($id); ?>email"><?php esc_html_e('Email address', 'donation-box'); ?></label>
                    <input id="<?php echo esc_attr($id); ?>email" name="email" type="email" autocomplete="email" maxlength="191" value="<?php echo esc_attr($user->user_email); ?>" required disabled>
                    <label for="<?php echo esc_attr($id); ?>phone"><?php esc_html_e('Phone (optional)', 'donation-box'); ?></label>
                    <input id="<?php echo esc_attr($id); ?>phone" name="phone" type="tel" autocomplete="tel" maxlength="50" disabled>
                    <label class="dbox-consent"><input name="consent" type="checkbox" value="1" required disabled><span><?php esc_html_e('I agree to the processing of my details for this donation and a donor account.', 'donation-box'); ?> <?php if (get_privacy_policy_url()): ?><a href="<?php echo esc_url(get_privacy_policy_url()); ?>"><?php esc_html_e('Privacy policy', 'donation-box'); ?></a><?php endif; ?></span></label>
                    <p class="dbox-monthly" hidden><?php esc_html_e('Your chosen amount will be charged every month until you cancel in the donor portal.', 'donation-box'); ?></p>
                    <div class="dbox-actions"><button type="button" data-back><?php esc_html_e('Back', 'donation-box'); ?></button><button class="dbox-primary" type="submit"><?php esc_html_e('Donate securely', 'donation-box'); ?></button></div>
                </div>
                <div class="dbox-honey" aria-hidden="true"><label><?php esc_html_e('Leave empty', 'donation-box'); ?><input name="website" tabindex="-1" autocomplete="off"></label></div>
                <p class="dbox-error" role="alert" tabindex="-1"></p>
                <p class="dbox-footer"><?php esc_html_e('Secure payment with Stripe. Your card details stay with Stripe.', 'donation-box'); ?></p>
                <noscript><?php esc_html_e('Please enable JavaScript to donate.', 'donation-box'); ?></noscript>
            </form>
        </section>
        <?php return ob_get_clean();
    }
    public static function account(): string {
        self::assets();
        if (!is_user_logged_in()) { return '<p><a href="' . esc_url(wp_login_url(get_permalink())) . '">' . esc_html__('Log in to view your donations', 'donation-box') . '</a></p>'; }
        global $wpdb;
        $mode = Donation_Box_Settings::get()['mode'];
        $page = max(1, absint(Donation_Box_Settings::query_value('donation_page', '1'))); $offset = ($page - 1) * 20;
        $table = Donation_Box_Store::table('payments');
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned financial tables require current state for reporting, ownership and webhook processing.
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM %i WHERE user_id = %d AND mode = %s ORDER BY id DESC LIMIT 21 OFFSET %d", $table, get_current_user_id(), $mode, $offset));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned financial tables require current state for reporting, ownership and webhook processing.
        $customers = $wpdb->get_col($wpdb->prepare("SELECT DISTINCT customer_id FROM %i WHERE user_id = %d AND mode = %s AND status IN ('paid','refunded','partially_refunded') AND customer_id <> ''", $table, get_current_user_id(), $mode));
        ob_start(); ?>
        <section class="donation-box dbox-account" style="<?php echo esc_attr(Donation_Box_Settings::color_style()); ?>"><h2><?php esc_html_e('My donations', 'donation-box'); ?></h2>
        <?php if (!$rows): ?><p><?php esc_html_e('No donations yet.', 'donation-box'); ?></p><?php else: ?>
        <div class="dbox-table"><table><thead><tr><th><?php esc_html_e('Date', 'donation-box'); ?></th><th><?php esc_html_e('Amount', 'donation-box'); ?></th><th><?php esc_html_e('Status', 'donation-box'); ?></th></tr></thead><tbody>
        <?php foreach (array_slice($rows, 0, 20) as $row): ?><tr><td><?php echo esc_html($row->created_at . ' UTC'); ?></td><td><?php echo esc_html(self::money($row->amount, $row->currency)); ?></td><td><?php echo esc_html($row->status); ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
        <?php if ($page > 1): ?><a href="<?php echo esc_url(add_query_arg('donation_page', $page - 1)); ?>"><?php esc_html_e('Previous', 'donation-box'); ?></a><?php endif; ?>
        <?php if (count($rows) > 20): ?><a href="<?php echo esc_url(add_query_arg('donation_page', $page + 1)); ?>"><?php esc_html_e('Next', 'donation-box'); ?></a><?php endif; ?>
        <?php endif; ?>
        <?php foreach ($customers as $i => $customer): ?>
        <?php /* translators: %d: number identifying a donor's Stripe customer account. */ ?>
        <button type="button" class="dbox-portal dbox-primary" data-endpoint="<?php echo esc_url(rest_url('donation-box/v1/portal')); ?>" data-nonce="<?php echo esc_attr(wp_create_nonce('wp_rest')); ?>" data-customer="<?php echo esc_attr($customer); ?>"><?php echo esc_html(sprintf(__('Manage payments and subscriptions (%d)', 'donation-box'), $i + 1)); ?></button>
        <?php endforeach; ?><p class="dbox-error" role="alert"></p></section>
        <?php return ob_get_clean();
    }
}
