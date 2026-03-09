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
      $body_tpl = "Thanks for signing up.

Use this link to claim your free pint:
{claim_link}
";
    }

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
  }

  public static function redeem(WP_REST_Request $req) {
    if (!MMPP_DB::campaign_is_active($campaign)) {
      return new WP_REST_Response(['ok' => false, 'state' => 'inactive', 'message' => 'Campaign not active'], 403);
    }

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

    if ($res['ok']) {
      return new WP_REST_Response(['ok' => true, 'state' => $res['state']]);
    }

    return new WP_REST_Response(['ok' => false, 'state' => $res['state']]);
  }
}
