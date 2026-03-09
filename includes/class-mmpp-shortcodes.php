<?php
if (!defined('ABSPATH')) exit;

class MMPP_Shortcodes {
  public static function init() {
    add_shortcode('mmpp_signup', [__CLASS__, 'signup']);
    add_shortcode('mmpp_claim', [__CLASS__, 'claim']);
  }

  public static function signup($atts) {
    wp_enqueue_style('mmpp-front');

    $atts = shortcode_atts([
      'campaign' => '',
    ], $atts, 'mmpp_signup');

    $slug = sanitize_title($atts['campaign']);
    if (!$slug) return '<div class="mmpp-box mmpp-error">Missing campaign.</div>';

    $campaign = MMPP_DB::get_campaign_by_slug($slug);
    if (!$campaign) return '<div class="mmpp-box mmpp-error">Campaign not found.</div>';

    if (!MMPP_DB::campaign_is_active($campaign)) {
      return '<div class="mmpp-box mmpp-error">This promotion is not active right now.</div>';
    }

    $out = '';

    if (!empty($_POST['mmpp_signup_submit']) && !empty($_POST['mmpp_campaign']) && hash_equals($slug, (string) $_POST['mmpp_campaign'])) {
      if (!wp_verify_nonce($_POST['mmpp_nonce'] ?? '', 'mmpp_signup_' . $slug)) {
        return '<div class="mmpp-box mmpp-error">Security check failed. Refresh and try again.</div>';
      }

      $email = sanitize_email($_POST['email'] ?? '');
      $entry = MMPP_DB::upsert_entry((int) $campaign->id, $email);

      if (is_wp_error($entry)) {
        $out .= '<div class="mmpp-box mmpp-error">' . esc_html($entry->get_error_message()) . '</div>';
      } else {
        // Use the same email template as webhook route.
        MMPP_Rest::init();
        // Call private method not possible. Rebuild a minimal send here.
        $base = MMPP_DB::get_claim_page_url($campaign);
        $claim_url = add_query_arg(['mmpp' => $campaign->slug, 't' => $entry->token], $base);

        $subject = $campaign->email_subject ?: 'Your free pint claim link';
        $body_tpl = $campaign->email_body ?: "Thanks for signing up.\n\nUse this link to claim your free pint:\n{claim_link}\n";
        $is_html = isset($campaign->email_is_html) ? ((int) $campaign->email_is_html === 1) : true;
        $btn_text = !empty($campaign->email_button_text) ? (string) $campaign->email_button_text : 'Open my free pint pass';

        if ($is_html) {
          $button = '<a href="' . esc_url($claim_url) . '" style="display:inline-block;padding:12px 18px;background:#0b3d2e;color:#ffffff;text-decoration:none;border-radius:10px;font-weight:700;">' . esc_html($btn_text) . '</a>';
          $fallback = '<p style="margin-top:12px;font-size:12px;color:#666;">If the button does not work, open this link: <a href="' . esc_url($claim_url) . '">' . esc_html($claim_url) . '</a></p>';
          $body = str_replace('{claim_link}', $button . $fallback, $body_tpl);
          $body = str_replace('{claim_url}', esc_url($claim_url), $body);
          $headers = ['Content-Type: text/html; charset=UTF-8'];
        } else {
          $body = str_replace('{claim_link}', esc_url_raw($claim_url), $body_tpl);
          $body = str_replace('{claim_url}', esc_url_raw($claim_url), $body);
          $headers = [];
        }

        if (!empty($campaign->email_from_email)) {
          $from_name = $campaign->email_from_name ? $campaign->email_from_name : wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
          $headers[] = 'From: ' . $from_name . ' <' . $campaign->email_from_email . '>';
        }
        wp_mail($entry->email, $subject, $body, $headers);

        $out .= '<div class="mmpp-box mmpp-ok">Check your email for your claim link.</div>';
      }
    }

    $out .= '<div class="mmpp-box">'
      . '<form method="post" class="mmpp-form">'
      . '<label>Email</label>'
      . '<input type="email" name="email" required autocomplete="email" placeholder="you@example.com">'
      . '<input type="hidden" name="mmpp_campaign" value="' . esc_attr($slug) . '">'
      . wp_nonce_field('mmpp_signup_' . $slug, 'mmpp_nonce', true, false)
      . '<button type="submit" name="mmpp_signup_submit" value="1">Sign up</button>'
      . '</form>'
      . '</div>';

    return $out;
  }

  public static function claim($atts) {
    wp_enqueue_style('mmpp-front');
    wp_enqueue_script('mmpp-front');

    $slug = sanitize_title($_GET['mmpp'] ?? '');
    $token = sanitize_text_field($_GET['t'] ?? '');

    if (!$slug || !$token) {
      return '<div class="mmpp-box mmpp-error">Invalid claim link.</div>';
    }

    $campaign = MMPP_DB::get_campaign_by_slug($slug);
    if (!$campaign) return '<div class="mmpp-box mmpp-error">Campaign not found.</div>';

    if (!MMPP_DB::campaign_is_active($campaign)) {
      return '<div class="mmpp-box mmpp-error">This promotion is not active right now.</div>';
    }

    $entry = MMPP_DB::get_entry_by_token($token);
    if (!$entry || (int) $entry->campaign_id !== (int) $campaign->id) {
      return '<div class="mmpp-box mmpp-error">Invalid claim token.</div>';
    }

    if (!MMPP_DB::campaign_is_active($campaign)) {
      return '<div class="mmpp-wrap"><div class="mmpp-card mmpp-fail"><h2>Promotion not active</h2><p>This promotion is not active right now.</p></div></div>';
    }

    if ((int) $entry->status === 0) {
      return self::screen_already_redeemed($campaign);
    }

    $pin_required = !empty($campaign->staff_pin);

    ob_start();
    ?>
    <div class="mmpp-wrap" data-campaign="<?php echo esc_attr($campaign->slug); ?>" data-token="<?php echo esc_attr($token); ?>" data-pin-required="<?php echo $pin_required ? '1' : '0'; ?>" data-qr-enabled="<?php echo (int) $campaign->qr_enabled; ?>">
      <div class="mmpp-card">
        <h2>Free pint claim</h2>
        <p class="mmpp-big-warn">Bar staff only below this point.</p>
        <p>Show this screen to staff at the bar.</p>

        <?php if ((int) $campaign->qr_enabled === 1) : ?>
          <div class="mmpp-qr-block">
            <div class="mmpp-qr" id="mmppQr"></div>
            <button type="button" class="mmpp-secondary" id="mmppScanBtn">Scan QR (staff device)</button>
            <div class="mmpp-scan" id="mmppScan" hidden>
              <video id="mmppVideo" playsinline></video>
              <canvas id="mmppCanvas" hidden></canvas>
              <div class="mmpp-scan-actions">
                <button type="button" class="mmpp-secondary" id="mmppStopScan">Stop scan</button>
              </div>
              <div class="mmpp-scan-msg" id="mmppScanMsg"></div>
            </div>
          </div>
        <?php endif; ?>

        <?php if ($pin_required) : ?>
          <div class="mmpp-pin">
            <label for="mmppPin">Staff PIN</label>
            <input id="mmppPin" type="password" inputmode="numeric" autocomplete="off" placeholder="PIN">
          </div>
        <?php endif; ?>

        <button type="button" class="mmpp-redeem" id="mmppRedeemBtn">Redeem free pint</button>

        <div class="mmpp-result" id="mmppResult" role="status" aria-live="polite"></div>
      </div>
    </div>
    <?php
    return ob_get_clean();
  }

  private static function screen_already_redeemed($campaign) {
    $html  = '<div class="mmpp-wrap">';
    $html .= '<div class="mmpp-card mmpp-fail">';
    $html .= '<h2>Already redeemed</h2>';
    $html .= '<p>This offer has already been redeemed.</p>';
    $html .= '</div></div>';
    return $html;
  }
}
