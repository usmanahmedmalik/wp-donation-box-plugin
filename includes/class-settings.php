<?php
if (!defined('ABSPATH')) { exit; }
final class Donation_Box_Settings {
    public static function get(): array {
        $defaults = array_map(static fn ($color) => $color[1], self::colors());
        return wp_parse_args(get_option('donation_box_settings', []), $defaults + [
            'mode' => 'test', 'currency' => 'eur', 'amounts' => '15,25,50,100,250,500',
            'title' => __('Make a difference', 'pledgly-donations'),
            'description' => __('Your generosity helps our community grow.', 'pledgly-donations'),
            'test_key' => '', 'live_key' => '', 'test_webhook' => '', 'live_webhook' => '',
        ]);
    }
    /** Read a display-only URL filter; no data is changed by these values. */
    public static function query_value(string $key, string $default = ''): string {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only display/pagination filters; privileged actions have separate nonce checks.
        if (!isset($_GET[$key]) || !is_scalar($_GET[$key])) { return $default; }
        $value = sanitize_text_field(wp_unslash($_GET[$key]));
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
        return $value;
    }
    public static function colors(): array {
        return [
            'color_primary' => [__('Primary / buttons', 'pledgly-donations'), '#0071DC'],
            'color_secondary' => [__('Secondary / accents', 'pledgly-donations'), '#FFC221'],
            'color_heading' => [__('Headings', 'pledgly-donations'), '#031F42'],
            'color_text' => [__('Body text', 'pledgly-donations'), '#252525'],
            'color_muted' => [__('Muted text', 'pledgly-donations'), '#696969'],
            'color_background' => [__('Box background', 'pledgly-donations'), '#FFFFFF'],
            'color_light' => [__('Selected amount background', 'pledgly-donations'), '#F2F2F2'],
            'color_border' => [__('Borders', 'pledgly-donations'), '#EEEEEE'],
            'color_button_text' => [__('Button text', 'pledgly-donations'), '#FFFFFF'],
        ];
    }
    public static function color_style(): string {
        $settings = self::get(); $css = [];
        foreach (self::colors() as $key => $color) {
            $css[] = '--dbox-' . str_replace('_', '-', substr($key, 6)) . ':' . (sanitize_hex_color($settings[$key]) ?: $color[1]);
        }
        return implode(';', $css);
    }
    public static function secret(string $mode, string $type = 'key'): string {
        $constant = 'DONATION_BOX_' . strtoupper($mode . '_' . $type);
        return defined($constant) ? (string) constant($constant) : self::get()[$mode . '_' . $type];
    }
    public static function sanitize($input): array {
        $old = self::get();
        $out = $old;
        foreach (self::colors() as $key => $color) {
            $out[$key] = sanitize_hex_color($input[$key] ?? $old[$key]) ?: $old[$key];
        }
        foreach (['title', 'description'] as $key) { $out[$key] = sanitize_text_field($input[$key] ?? $old[$key]); }
        $out['mode'] = ($input['mode'] ?? '') === 'live' ? 'live' : 'test';
        $out['currency'] = in_array($input['currency'] ?? '', ['eur', 'usd', 'gbp'], true) ? $input['currency'] : 'eur';
        $amounts = array_filter(array_map('trim', explode(',', (string) ($input['amounts'] ?? $old['amounts']))), static function ($a) {
            return !is_wp_error(Donation_Box_Stripe::amount($a));
        });
        $out['amounts'] = implode(',', array_slice($amounts ?: ['10', '25', '50', '100'], 0, 8));
        foreach (['test', 'live'] as $mode) {
            foreach (['key', 'webhook'] as $type) {
                $field = $mode . '_' . $type;
                $value = trim((string) ($input[$field] ?? ''));
                if (!empty($input['clear_' . $field])) { $out[$field] = ''; continue; }
                if ($value === '') { continue; }
                $pattern = $type === 'key' ? '/^sk_' . $mode . '_[A-Za-z0-9]+$/' : '/^whsec_[A-Za-z0-9]+$/';
                if (preg_match($pattern, $value)) { $out[$field] = $value; }
                else { add_settings_error('donation_box_settings', $field, __('Invalid Stripe credential. Previous value retained.', 'pledgly-donations')); }
            }
        }
        return $out;
    }
}
