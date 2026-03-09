<?php
if (!defined('ABSPATH')) exit;

class MMPP_DB {
  public static function init() {
    // Lightweight schema migrations.
    $ver = (int) get_option('mmpp_schema_ver', 0);
    if ($ver < 101) {
      self::activate();
      update_option('mmpp_schema_ver', 101);
    }
  }

  public static function activate() {
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $charset = $wpdb->get_charset_collate();
    $t_campaigns = $wpdb->prefix . MMPP_TABLE_CAMPAIGNS;
    $t_entries   = $wpdb->prefix . MMPP_TABLE_ENTRIES;

    $sql1 = "CREATE TABLE {$t_campaigns} (
      id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
      name VARCHAR(190) NOT NULL,
      slug VARCHAR(190) NOT NULL,
      webhook_key VARCHAR(64) NOT NULL,
      form_id VARCHAR(190) NULL,
      claim_page_id BIGINT(20) UNSIGNED NULL,
      email_field_name VARCHAR(190) NOT NULL DEFAULT 'email',
      email_subject VARCHAR(190) NOT NULL DEFAULT 'Your free pint claim link',
      email_from_name VARCHAR(190) NULL,
      email_from_email VARCHAR(190) NULL,
      email_body LONGTEXT NULL,
      staff_pin VARCHAR(32) NULL,
      qr_enabled TINYINT(1) NOT NULL DEFAULT 0,
      created_at DATETIME NOT NULL,
      PRIMARY KEY  (id),
      UNIQUE KEY slug (slug),
      UNIQUE KEY webhook_key (webhook_key)
    ) {$charset};";

    $sql2 = "CREATE TABLE {$t_entries} (
      id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
      campaign_id BIGINT(20) UNSIGNED NOT NULL,
      email VARCHAR(190) NOT NULL,
      status TINYINT(1) NOT NULL DEFAULT 1,
      token VARCHAR(64) NOT NULL,
      signup_at DATETIME NOT NULL,
      redeemed_at DATETIME NULL,
      redeemed_ip VARCHAR(64) NULL,
      redeemed_ua VARCHAR(255) NULL,
      PRIMARY KEY (id),
      UNIQUE KEY campaign_email (campaign_id, email),
      UNIQUE KEY token (token),
      KEY campaign_status (campaign_id, status)
    ) {$charset};";

    dbDelta($sql1);
    dbDelta($sql2);

    // Seed: no default campaigns.
  }

  public static function table_campaigns() {
    global $wpdb;
    return $wpdb->prefix . MMPP_TABLE_CAMPAIGNS;
  }

  public static function table_entries() {
    global $wpdb;
    return $wpdb->prefix . MMPP_TABLE_ENTRIES;
  }

  public static function now_gmt() {
    return gmdate('Y-m-d H:i:s');
  }

  public static function random_key($len = 32) {
    $bytes = random_bytes((int) ceil($len / 2));
    return substr(bin2hex($bytes), 0, $len);
  }

  public static function normalize_email($email) {
    $email = trim((string) $email);
    $email = strtolower($email);
    return sanitize_email($email);
  }

  public static function get_campaign_by_key($key) {
    global $wpdb;
    $t = self::table_campaigns();
    return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE webhook_key=%s", $key));
  }

  public static function get_campaign($id) {
    global $wpdb;
    $t = self::table_campaigns();
    return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE id=%d", $id));
  }

  public static function get_campaign_by_slug($slug) {
    global $wpdb;
    $t = self::table_campaigns();
    return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE slug=%s", $slug));
  }

  public static function list_campaigns() {
    global $wpdb;
    $t = self::table_campaigns();
    return $wpdb->get_results("SELECT * FROM {$t} ORDER BY created_at DESC");
  }

  public static function get_claim_page_url($campaign) {
    $campaign_id = is_object($campaign) ? (int) ($campaign->id ?? 0) : 0;
    $page_id = is_object($campaign) ? (int) ($campaign->claim_page_id ?? 0) : 0;

    if ($page_id > 0) {
      $url = get_permalink($page_id);
      if ($url) return $url;
    }

    // Fallback: try to auto-detect a published page containing the [mmpp_claim] shortcode.
    $cache_key = 'mmpp_claim_page_url_' . $campaign_id;
    $cached = get_transient($cache_key);
    if (is_string($cached) && $cached) return $cached;

    $pages = get_posts([
      'post_type' => 'page',
      'post_status' => 'publish',
      'posts_per_page' => 50,
      'orderby' => 'modified',
      'order' => 'DESC',
      'no_found_rows' => true,
      'suppress_filters' => true,
    ]);

    $found = '';
    foreach ($pages as $p) {
      if (!empty($p->post_content) && has_shortcode($p->post_content, 'mmpp_claim')) {
        $u = get_permalink($p->ID);
        if ($u) {
          $found = $u;
          break;
        }
      }
    }

    if ($found) {
      set_transient($cache_key, $found, 5 * MINUTE_IN_SECONDS);
      return $found;
    }

    return home_url('/');
  }

  public static function upsert_entry($campaign_id, $email, $token = null) {
    global $wpdb;
    $t = self::table_entries();

    $email = self::normalize_email($email);
    if (!$email || !is_email($email)) {
      return new WP_Error('mmpp_bad_email', 'Invalid email');
    }

    $existing = $wpdb->get_row($wpdb->prepare(
      "SELECT * FROM {$t} WHERE campaign_id=%d AND email=%s",
      $campaign_id,
      $email
    ));

    if ($existing) {
      return $existing;
    }

    if (!$token) $token = self::random_key(40);

    $ok = $wpdb->insert($t, [
      'campaign_id' => (int) $campaign_id,
      'email'       => $email,
      'status'      => 1,
      'token'       => $token,
      'signup_at'   => self::now_gmt(),
    ], ['%d', '%s', '%d', '%s', '%s']);

    if (!$ok) {
      return new WP_Error('mmpp_db_insert_failed', 'Could not create entry');
    }

    $id = (int) $wpdb->insert_id;
    return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE id=%d", $id));
  }

  public static function get_entry_by_token($token) {
    global $wpdb;
    $t = self::table_entries();
    return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE token=%s", $token));
  }

  public static function get_entry($campaign_id, $email) {
    global $wpdb;
    $t = self::table_entries();
    $email = self::normalize_email($email);
    return $wpdb->get_row($wpdb->prepare(
      "SELECT * FROM {$t} WHERE campaign_id=%d AND email=%s",
      (int) $campaign_id,
      $email
    ));
  }

  public static function redeem_by_token($token) {
    global $wpdb;
    $t = self::table_entries();

    $ip = isset($_SERVER['REMOTE_ADDR']) ? substr((string) $_SERVER['REMOTE_ADDR'], 0, 64) : null;
    $ua = isset($_SERVER['HTTP_USER_AGENT']) ? substr((string) $_SERVER['HTTP_USER_AGENT'], 0, 255) : null;

    // Atomic update prevents double redemption.
    $updated = $wpdb->query($wpdb->prepare(
      "UPDATE {$t}
       SET status=0, redeemed_at=%s, redeemed_ip=%s, redeemed_ua=%s
       WHERE token=%s AND status=1",
      self::now_gmt(),
      $ip,
      $ua,
      $token
    ));

    if ($updated === 1) {
      return ['ok' => true, 'state' => 'redeemed_now'];
    }

    // Either already redeemed or token not found.
    $row = self::get_entry_by_token($token);
    if (!$row) return ['ok' => false, 'state' => 'not_found'];

    return ['ok' => false, 'state' => 'already_redeemed'];
  }

  public static function stats_for_campaign($campaign_id) {
    global $wpdb;
    $t = self::table_entries();

    $total = (int) $wpdb->get_var($wpdb->prepare(
      "SELECT COUNT(*) FROM {$t} WHERE campaign_id=%d",
      $campaign_id
    ));

    $redeemed = (int) $wpdb->get_var($wpdb->prepare(
      "SELECT COUNT(*) FROM {$t} WHERE campaign_id=%d AND status=0",
      $campaign_id
    ));

    $pending = $total - $redeemed;

    // Last 14 days, simple daily counts.
    $since = gmdate('Y-m-d 00:00:00', time() - 13 * DAY_IN_SECONDS);
    $rows = $wpdb->get_results($wpdb->prepare(
      "SELECT DATE(signup_at) d, COUNT(*) c
       FROM {$t}
       WHERE campaign_id=%d AND signup_at >= %s
       GROUP BY DATE(signup_at)
       ORDER BY d ASC",
      $campaign_id,
      $since
    ));

    $by_day = [];
    foreach ($rows as $r) {
      $by_day[$r->d] = (int) $r->c;
    }

    return [
      'total' => $total,
      'redeemed' => $redeemed,
      'pending' => $pending,
      'by_day' => $by_day,
    ];
  }

  public static function export_entries_csv($campaign_id) {
    global $wpdb;
    $t = self::table_entries();

    $rows = $wpdb->get_results($wpdb->prepare(
      "SELECT email, status, signup_at, redeemed_at, redeemed_ip
       FROM {$t}
       WHERE campaign_id=%d
       ORDER BY signup_at DESC",
      $campaign_id
    ), ARRAY_A);

    $out = fopen('php://output', 'w');
    fputcsv($out, ['email', 'status', 'signup_at_gmt', 'redeemed_at_gmt', 'redeemed_ip']);
    foreach ($rows as $r) {
      fputcsv($out, [
        $r['email'],
        (string) $r['status'],
        $r['signup_at'],
        $r['redeemed_at'],
        $r['redeemed_ip'],
      ]);
    }
    fclose($out);
  }
}
