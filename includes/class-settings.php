<?php
if (!defined('ABSPATH')) { exit; }
final class Donation_Box_Settings {
    public static function get(): array {
        $defaults = array_map(static fn ($color) => $color[1], self::colors());
        return wp_parse_args(get_option('donation_box_settings', []), $defaults + [
            'mode' => 'test', 'currency' => 'eur', 'amounts' => '15,25,50,100,250,500',
            'title' => __('Make a difference', 'donation-box'),
            'description' => __('Your generosity helps our community grow.', 'donation-box'),
            'test_key' => '', 'live_key' => '', 'test_webhook' => '', 'live_webhook' => '',
        ]);
    }
    public static function colors(): array {
        return [
            'color_primary' => [__('Primary / buttons', 'donation-box'), '#0071DC'],
            'color_secondary' => [__('Secondary / accents', 'donation-box'), '#FFC221'],
            'color_heading' => [__('Headings', 'donation-box'), '#031F42'],
            'color_text' => [__('Body text', 'donation-box'), '#252525'],
            'color_muted' => [__('Muted text', 'donation-box'), '#696969'],
            'color_background' => [__('Box background', 'donation-box'), '#FFFFFF'],
            'color_light' => [__('Selected amount background', 'donation-box'), '#F2F2F2'],
            'color_border' => [__('Borders', 'donation-box'), '#EEEEEE'],
            'color_button_text' => [__('Button text', 'donation-box'), '#FFFFFF'],
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
                else { add_settings_error('donation_box_settings', $field, __('Invalid Stripe credential. Previous value retained.', 'donation-box')); }
            }
        }
        return $out;
    }
}
