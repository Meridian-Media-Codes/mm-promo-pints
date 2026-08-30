<?php
if (!defined('ABSPATH')) exit;

class MMPP_Rest {
  public static function init() {
    add_action('rest_api_init', [__CLASS__, 'routes']);
  }

  public static function routes() {
    register_rest_route('mmpp/v1', '/webhook/(?P<key>[a-zA-Z0-9]+)', [
      'methods' => 'POST',
      'callback' => [__CLASS__, 'webhook'],
      'permission_callback' => '__return_true',
      'args' => [
        'key' => ['required' => true],
      ],
    ]);

    register_rest_route('mmpp/v1', '/redeem', [
      'methods' => 'POST',
      'callback' => [__CLASS__, 'redeem'],
      'permission_callback' => '__return_true',
    ]);
  }

  private static function get_body_params(WP_REST_Request $req) {
    $params = $req->get_json_params();
    if (is_array($params)) return $params;
    return $req->get_body_params();
  }

  public static function webhook(WP_REST_Request $req) {
    $key = (string) $req['key'];
    $campaign = MMPP_DB::get_campaign_by_key($key);

    if (!$campaign) {
      return new WP_REST_Response(['ok' => false, 'message' => 'Campaign not found'], 404);
    }

    if (!MMPP_DB::campaign_is_active($campaign)) {
      return new WP_REST_Response(['ok' => false, 'state' => 'inactive', 'message' => 'Campaign not active'], 403);
    }

    $data = self::get_body_params($req);
    $field = $campaign->email_field_name ?: 'email';

    $email = '';
    if (isset($data[$field])) $email = (string) $data[$field];
    if (!$email && isset($data['email'])) $email = (string) $data['email'];

    $email = MMPP_DB::normalize_email($email);
    if (!$email || !is_email($email)) {
      return new WP_REST_Response(['ok' => false, 'message' => 'Missing or invalid email'], 400);
    }

    $existing = MMPP_DB::get_entry((int) $campaign->id, $email);

    if ($existing) {
      return new WP_REST_Response(['ok' => true, 'state' => 'already_registered']);
    }

    $entry = MMPP_DB::upsert_entry((int) $campaign->id, $email);
    if (is_wp_error($entry)) {
      return new WP_REST_Response(['ok' => false, 'message' => $entry->get_error_message()], 500);
    }

    self::send_claim_email($campaign, $entry);

    return new WP_REST_Response(['ok' => true, 'state' => 'registered']);
  }

  /**
   * Logo used in the claim email.
   *
   * Set MMPP_EMAIL_LOGO_URL in wp-config.php, or filter 'mmpp_email_logo_url'.
   * Use an image that has the dark green background BAKED IN (not transparent),
   * otherwise dark-mode email clients will show it as a black or white box.
   */
  private static function email_logo_url($campaign) {
    $logo_url = '';

    if (defined('MMPP_EMAIL_LOGO_URL') && MMPP_EMAIL_LOGO_URL) {
      $logo_url = (string) MMPP_EMAIL_LOGO_URL;
    }

    $logo_url = (string) apply_filters('mmpp_email_logo_url', $logo_url, $campaign);

    // Fallback only if nothing has been set above.
    if (!$logo_url) {
      $custom_logo_id = (int) get_theme_mod('custom_logo');
      if ($custom_logo_id) {
        $logo_src = wp_get_attachment_image_src($custom_logo_id, 'full');
        if (!empty($logo_src[0])) $logo_url = $logo_src[0];
      }
    }
    if (!$logo_url) {
      $logo_url = get_site_icon_url(256);
    }

    return $logo_url;
  }

  private static function send_claim_email($campaign, $entry) {
  $base = MMPP_DB::get_claim_page_url($campaign);
  $claim_url = add_query_arg([
    'mmpp' => $campaign->slug,
    't' => $entry->token,
  ], $base);

  $subject = $campaign->email_subject ?: 'Your free pint claim link';

  $body_tpl = $campaign->email_body;
  if (!$body_tpl) {
    $body_tpl = "Thanks for signing up.\n\nShow this screen at the bar on opening day to claim your free pint.\n\n{claim_link}";
  }

  $is_html = isset($campaign->email_is_html) ? ((int) $campaign->email_is_html === 1) : true;
  $btn_text = !empty($campaign->email_button_text) ? (string) $campaign->email_button_text : 'Open my free pint pass';

  $site_name = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);

  $logo_url   = self::email_logo_url($campaign);
  $logo_width = (int) apply_filters('mmpp_email_logo_width', 260, $campaign);

  // Brand colours - matched to the Nelly Mulligans site.
  $bg      = '#0b2b18';  // deep green page background
  $card    = '#10331e';  // slightly lifted green card
  $text    = '#f7f3e8';  // cream
  $muted   = '#dfe4d8';
  $subtle  = '#93a698';
  $accent  = '#caa34a';  // gold
  $border  = '#2c5238';
  $button_bg   = '#caa34a';
  $button_text = '#0b2b18';

  if ($is_html) {
    // Build button
    $button_html =
      '<a href="' . esc_url($claim_url) . '" class="mmpp-btn" ' .
      'style="display:inline-block;background:' . esc_attr($button_bg) . ';color:' . esc_attr($button_text) . ';' .
      'text-decoration:none;border-radius:10px;padding:14px 22px;font-weight:800;font-size:16px;letter-spacing:0.4px;">' .
      esc_html($btn_text) .
      '</a>';

    // Fallback link, quiet and compact
    $fallback_html =
      '<div class="mmpp-subtle" style="margin-top:16px;padding-top:14px;border-top:1px solid ' . esc_attr($border) . ';font-size:12px;line-height:18px;color:' . esc_attr($subtle) . ';">' .
      'Manual link: <a href="' . esc_url($claim_url) . '" class="mmpp-link" style="color:' . esc_attr($accent) . ';text-decoration:underline;word-break:break-word;">' .
      esc_html($claim_url) .
      '</a>' .
      '</div>';

    // Body copy
    $safe_body = wp_kses_post($body_tpl);
    $safe_body = str_replace('{claim_url}', esc_url($claim_url), $safe_body);
    $safe_body = str_replace('{claim_link}', $button_html . $fallback_html, $safe_body);

    $parts = preg_split("/\r\n\r\n|\n\n|\r\r/", $safe_body);
    $body_html = '';
    foreach ($parts as $p) {
      $p = trim($p);
      if ($p === '') continue;
      $body_html .= '<p class="mmpp-body" style="margin:0 0 16px 0;font-size:15px;line-height:23px;color:' . esc_attr($muted) . ';">' . nl2br($p) . '</p>';
    }

    // Header block (logo + kicker)
    $logo_block = '';
    if ($logo_url) {
      $logo_block =
        '<img src="' . esc_url($logo_url) . '" alt="' . esc_attr($site_name) . '" width="' . $logo_width . '" ' .
        'style="display:block;margin:0 auto 12px auto;width:' . $logo_width . 'px;max-width:100%;height:auto;border:0;outline:none;text-decoration:none;">';
    }

    $kicker = !empty($campaign->email_kicker) ? (string) $campaign->email_kicker : 'Free Pint Pass';
    $kicker = (string) apply_filters('mmpp_email_kicker', $kicker, $campaign);

    // Show the site name as text only when there is no branded logo image.
    $title_block = '';
    if (!$logo_url) {
      $title_block = '<div class="mmpp-title" style="font-size:20px;font-weight:900;color:' . esc_attr($text) . ';margin:0 0 6px 0;">' . esc_html($site_name) . '</div>';
    }

    // Dark-mode guards. Gmail and Outlook re-colour dark emails; these keep the palette.
    $head_style =
      '<style>' .
      ':root{color-scheme:light dark;supported-color-schemes:light dark;}' .
      '@media (prefers-color-scheme:dark){' .
        '.mmpp-bg{background:' . $bg . ' !important;}' .
        '.mmpp-card{background:' . $card . ' !important;}' .
        '.mmpp-title,.mmpp-kicker{color:' . $text . ' !important;}' .
        '.mmpp-body{color:' . $muted . ' !important;}' .
        '.mmpp-subtle,.mmpp-footer{color:' . $subtle . ' !important;}' .
        '.mmpp-btn{background:' . $button_bg . ' !important;color:' . $button_text . ' !important;}' .
        '.mmpp-link{color:' . $accent . ' !important;}' .
      '}' .
      '[data-ogsb] .mmpp-bg,u+.body .mmpp-bg{background:' . $bg . ' !important;}' .
      '[data-ogsb] .mmpp-card,u+.body .mmpp-card{background:' . $card . ' !important;}' .
      '[data-ogsb] .mmpp-btn,u+.body .mmpp-btn{background:' . $button_bg . ' !important;color:' . $button_text . ' !important;}' .
      '[data-ogsc] .mmpp-title,[data-ogsc] .mmpp-kicker{color:' . $text . ' !important;}' .
      '[data-ogsc] .mmpp-body{color:' . $muted . ' !important;}' .
      '[data-ogsc] .mmpp-subtle,[data-ogsc] .mmpp-footer{color:' . $subtle . ' !important;}' .
      '[data-ogsc] .mmpp-link{color:' . $accent . ' !important;}' .
      '[data-ogsc] .mmpp-btn{color:' . $button_text . ' !important;}' .
      '</style>';

    // Full email HTML
    $html =
      '<!doctype html><html><head><meta http-equiv="Content-Type" content="text/html; charset=UTF-8">' .
      '<meta name="viewport" content="width=device-width, initial-scale=1.0">' .
      '<meta name="color-scheme" content="light dark">' .
      '<meta name="supported-color-schemes" content="light dark">' .
      $head_style .
      '</head>' .
      '<body class="body mmpp-bg" bgcolor="' . esc_attr($bg) . '" style="margin:0;padding:0;background:' . esc_attr($bg) . ';font-family:Georgia,\'Times New Roman\',Times,serif;">' .

      '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" bgcolor="' . esc_attr($bg) . '" class="mmpp-bg" style="background:' . esc_attr($bg) . ';padding:28px 12px;">' .
        '<tr><td align="center">' .

          '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="620" style="width:620px;max-width:620px;">' .

            // Header
            '<tr><td style="padding:0 0 18px 0;text-align:center;">' .
              $logo_block .
              $title_block .
              '<div class="mmpp-kicker" style="font-size:12px;letter-spacing:0.22em;text-transform:uppercase;color:' . esc_attr($accent) . ';font-weight:700;font-family:Arial,Helvetica,sans-serif;">' . esc_html($kicker) . '</div>' .
            '</td></tr>' .

            // Main card
            '<tr><td class="mmpp-card" bgcolor="' . esc_attr($card) . '" style="background:' . esc_attr($card) . ';border:1px solid ' . esc_attr($border) . ';border-radius:14px;">' .
              '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">' .
                '<tr><td align="center" style="padding:26px 22px;font-family:Arial,Helvetica,sans-serif;text-align:center;">' .
                  $body_html .
                '</td></tr>' .
              '</table>' .
            '</td></tr>' .

            // Footer
            '<tr><td class="mmpp-footer" style="padding:18px 6px 0 6px;text-align:center;color:' . esc_attr($subtle) . ';font-size:12px;line-height:18px;font-family:Arial,Helvetica,sans-serif;">' .
              esc_html($site_name) . ' &middot; please drink responsibly' .
            '</td></tr>' .

          '</table>' .

        '</td></tr>' .
      '</table>' .

      '</body></html>';

    $headers = ['Content-Type: text/html; charset=UTF-8'];

    if (!empty($campaign->email_from_email)) {
      $from_name = $campaign->email_from_name ? $campaign->email_from_name : $site_name;
      $headers[] = 'From: ' . $from_name . ' <' . $campaign->email_from_email . '>';
    }

    wp_mail($entry->email, $subject, $html, $headers);
    return;
  }

  // Plain text fallback
  $body = str_replace('{claim_link}', esc_url_raw($claim_url), $body_tpl);
  $body = str_replace('{claim_url}', esc_url_raw($claim_url), $body);

  $headers = [];
  if (!empty($campaign->email_from_email)) {
    $from_name = $campaign->email_from_name ? $campaign->email_from_name : $site_name;
    $headers[] = 'From: ' . $from_name . ' <' . $campaign->email_from_email . '>';
  }

  wp_mail($entry->email, $subject, $body, $headers);
}

  public static function redeem(WP_REST_Request $req) {
    // IMPORTANT: we must load the campaign first (via the entry token) before checking active dates.
    $data = self::get_body_params($req);

    $token = sanitize_text_field($data['token'] ?? '');
    $pin = sanitize_text_field($data['pin'] ?? '');

    if (!$token) {
      return new WP_REST_Response(['ok' => false, 'message' => 'Missing token'], 400);
    }

    $entry = MMPP_DB::get_entry_by_token($token);
    if (!$entry) {
      return new WP_REST_Response(['ok' => false, 'state' => 'not_found', 'message' => 'Not found'], 404);
    }

    $campaign = MMPP_DB::get_campaign((int) $entry->campaign_id);
    if (!$campaign) {
      return new WP_REST_Response(['ok' => false, 'message' => 'Campaign not found'], 404);
    }

    if (!MMPP_DB::campaign_is_active($campaign)) {
      return new WP_REST_Response(['ok' => false, 'state' => 'inactive', 'message' => 'Campaign not active'], 403);
    }

    if (!empty($campaign->staff_pin)) {
      if (!$pin || $pin !== (string) $campaign->staff_pin) {
        return new WP_REST_Response(['ok' => false, 'state' => 'bad_pin', 'message' => 'PIN required'], 403);
      }
    }

    $res = MMPP_DB::redeem_by_token($token);

    if (!empty($res['ok'])) {
      return new WP_REST_Response(['ok' => true, 'state' => $res['state']]);
    }

    return new WP_REST_Response(['ok' => false, 'state' => $res['state'] ?? 'error']);
  }
}