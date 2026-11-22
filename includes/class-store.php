<?php
if (!defined('ABSPATH')) { exit; }
final class Donation_Box_Store {
    public static function table(string $name): string {
        if (!in_array($name, ['payments', 'subscriptions', 'events'], true)) {
            throw new InvalidArgumentException('Unknown Donation Box table');
        }
        global $wpdb;
        return $wpdb->prefix . 'donation_box_' . $name;
    }
    public static function install(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $payments = self::table('payments');
        $subscriptions = self::table('subscriptions');
        $events = self::table('events');
        dbDelta("CREATE TABLE $payments (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            reference varchar(191) NOT NULL,
            mode varchar(8) NOT NULL,
            user_id bigint unsigned NOT NULL DEFAULT 0,
            email varchar(191) NOT NULL,
            first_name varchar(100) NOT NULL DEFAULT '',
            last_name varchar(100) NOT NULL DEFAULT '',
            phone varchar(50) NOT NULL DEFAULT '',
            amount bigint unsigned NOT NULL,
            refunded_amount bigint unsigned NOT NULL DEFAULT 0,
            currency varchar(3) NOT NULL,
            frequency varchar(16) NOT NULL,
            status varchar(24) NOT NULL DEFAULT 'pending',
            customer_id varchar(191) NOT NULL DEFAULT '',
            session_id varchar(191) NOT NULL DEFAULT '',
            subscription_id varchar(191) NOT NULL DEFAULT '',
            stripe_id varchar(191) NOT NULL DEFAULT '',
            created_at datetime NOT NULL,
            paid_at datetime DEFAULT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY reference (reference),
            KEY donor (user_id,mode),
            KEY subscription_id (subscription_id),
            KEY status (status)
        ) $charset;");
        dbDelta("CREATE TABLE $subscriptions (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            stripe_id varchar(191) NOT NULL,
            mode varchar(8) NOT NULL,
            reference varchar(191) NOT NULL,
            customer_id varchar(191) NOT NULL,
            email varchar(191) NOT NULL,
            amount bigint unsigned NOT NULL DEFAULT 0,
            currency varchar(3) NOT NULL,
            status varchar(24) NOT NULL,
            cancel_at_period_end tinyint NOT NULL DEFAULT 0,
            period_end bigint unsigned NOT NULL DEFAULT 0,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY stripe_id (stripe_id)
        ) $charset;");
        dbDelta("CREATE TABLE $events (
            event_id varchar(191) NOT NULL,
            processed_at datetime NOT NULL,
            PRIMARY KEY  (event_id)
        ) $charset;");
        update_option('donation_box_db_version', DONATION_BOX_VERSION, false);
        add_option('donation_box_settings', [], '', false);
    }
    public static function find(string $reference): ?object {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned financial tables require current state for reporting, ownership and webhook processing.
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE reference = %s', self::table('payments'), $reference));
    }
    public static function update(int $id, array $data): void {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned financial tables require current state for reporting, ownership and webhook processing.
        if ($wpdb->update(self::table('payments'), $data, ['id' => $id]) === false) { throw new RuntimeException('Database update failed'); }
    }
    public static function insert(array $data): int {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned financial tables require current state for reporting, ownership and webhook processing.
        if (!$wpdb->insert(self::table('payments'), $data)) { throw new RuntimeException('Database insert failed'); }
        return (int) $wpdb->insert_id;
    }
    public static function link_user(object $row): void {
        if (!in_array($row->status, ['paid', 'refunded', 'partially_refunded'], true)) { return; }
        $user = get_user_by('email', $row->email);
        if (!$user) {
            // Never use a site's configurable default role (it might be privileged).
            $id = wp_insert_user([
                'user_login' => 'donor_' . str_replace('-', '', wp_generate_uuid4()),
                'user_email' => $row->email, 'user_pass' => wp_generate_password(40, true, true),
                'first_name' => $row->first_name, 'last_name' => $row->last_name,
                'display_name' => trim($row->first_name . ' ' . $row->last_name), 'role' => 'subscriber',
            ]);
            if (is_wp_error($id)) {
                $user = get_user_by('email', $row->email); // Concurrent successful donation.
                if (!$user) { throw new RuntimeException('Donor account could not be created'); }
            } else {
                $user = get_user_by('id', $id);
                wp_new_user_notification($id, null, 'user');
            }
        }
        self::update((int) $row->id, ['user_id' => $user->ID]);
    }
}
