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

  // Logo: campaign setting could be added later, but for now use site logo or site icon.
  $logo_url = '';
  $custom_logo_id = (int) get_theme_mod('custom_logo');
  if ($custom_logo_id) {
    $logo_src = wp_get_attachment_image_src($custom_logo_id, 'full');
    if (!empty($logo_src[0])) $logo_url = $logo_src[0];
  }
  if (!$logo_url) {
    $logo_url = get_site_icon_url(256);
  }

  // Brand colours - tweak these to match the site.
  $bg = '#0b2b18';         // page background
  $card = '#1c1f22';       // card background
  $text = '#ffffff';
  $muted = '#cfd5d3';
  $subtle = '#9aa7a1';
  $accent = '#caa34a';     // gold accent
  $button_bg = '#146b3a';  // button green
  $button_text = '#ffffff';
  $border = 'rgba(255,255,255,0.10)';

  if ($is_html) {
    // Build button
    $button_html =
      '<a href="' . esc_url($claim_url) . '" ' .
      'style="display:inline-block;background:' . esc_attr($button_bg) . ';color:' . esc_attr($button_text) . ';' .
      'text-decoration:none;border-radius:12px;padding:14px 18px;font-weight:800;font-size:16px;letter-spacing:0.2px;">' .
      esc_html($btn_text) .
      '</a>';

    // Fallback link made quiet and compact
    $fallback_html =
      '<div style="margin-top:14px;padding-top:12px;border-top:1px solid ' . esc_attr($border) . ';font-size:12px;line-height:18px;color:' . esc_attr($subtle) . ';">' .
      'Manual link: <a href="' . esc_url($claim_url) . '" style="color:#9fe6b8;text-decoration:underline;word-break:break-word;">' .
      esc_html($claim_url) .
      '</a>' .
      '</div>';

    // Body copy (keep simple formatting)
    $safe_body = wp_kses_post($body_tpl);
    $safe_body = str_replace('{claim_url}', esc_url($claim_url), $safe_body);
    $safe_body = str_replace('{claim_link}', $button_html . $fallback_html, $safe_body);

    // Convert double newlines to paragraph spacing
    $parts = preg_split("/\r\n\r\n|\n\n|\r\r/", $safe_body);
    $body_html = '';
    foreach ($parts as $p) {
      $p = trim($p);
      if ($p === '') continue;
      $body_html .= '<p style="margin:0 0 14px 0;font-size:14px;line-height:22px;color:' . esc_attr($muted) . ';">' . nl2br($p) . '</p>';
    }

    // Header block (logo + title)
    $logo_block = '';
    if ($logo_url) {
      $logo_block =
        '<img src="' . esc_url($logo_url) . '" alt="' . esc_attr($site_name) . '" width="160" ' .
        'style="display:block;margin:0 auto 10px auto;max-width:160px;height:auto;border:0;outline:none;text-decoration:none;">';
    }

    $title = !empty($campaign->email_title) ? (string) $campaign->email_title : $site_name;
    $kicker = !empty($campaign->email_kicker) ? (string) $campaign->email_kicker : 'Free pint pass';

    // Full email HTML
    $html =
      '<!doctype html><html><head><meta http-equiv="Content-Type" content="text/html; charset=UTF-8">' .
      '<meta name="viewport" content="width=device-width, initial-scale=1.0"></head>' .
      '<body style="margin:0;padding:0;background:' . esc_attr($bg) . ';font-family:Arial,Helvetica,sans-serif;">' .

      '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background:' . esc_attr($bg) . ';padding:24px 12px;">' .
        '<tr><td align="center">' .

          '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="620" style="width:620px;max-width:620px;">' .

            // Header
            '<tr><td style="padding:0 0 14px 0;text-align:center;">' .
              '<div style="background:rgba(255,255,255,0.06);border:1px solid ' . esc_attr($border) . ';border-radius:16px;padding:18px 16px;">' .
                $logo_block .
                '<div style="font-size:18px;font-weight:900;color:' . esc_attr($text) . ';margin:0;">' . esc_html($title) . '</div>' .
                '<div style="margin-top:6px;font-size:12px;letter-spacing:0.14em;text-transform:uppercase;color:' . esc_attr($accent) . ';font-weight:900;">' . esc_html($kicker) . '</div>' .
              '</div>' .
            '</td></tr>' .

            // Main card
            '<tr><td style="background:' . esc_attr($card) . ';border:1px solid ' . esc_attr($border) . ';border-radius:16px;overflow:hidden;">' .
              '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">' .
                '<tr><td style="padding:22px 18px;">' .
                  $body_html .
                  '<div style="margin-top:6px;"></div>' .
                '</td></tr>' .
              '</table>' .
            '</td></tr>' .

            // Footer
            '<tr><td style="padding:14px 6px 0 6px;text-align:center;color:' . esc_attr($subtle) . ';font-size:12px;line-height:18px;">' .
              esc_html($site_name) . ' automated email' .
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