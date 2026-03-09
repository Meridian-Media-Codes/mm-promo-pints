<?php
if (!defined('ABSPATH')) exit;

class MMPP_Admin {
  public static function init() {
    add_action('admin_menu', [__CLASS__, 'menu']);
    add_action('admin_post_mmpp_save_campaign', [__CLASS__, 'handle_save_campaign']);
    add_action('admin_post_mmpp_export_csv', [__CLASS__, 'handle_export_csv']);
  }

  public static function menu() {
    add_menu_page(
      'Promo Pints',
      'Promo Pints',
      'manage_options',
      'mmpp',
      [__CLASS__, 'page_campaigns'],
      'dashicons-tickets-alt',
      56
    );

    add_submenu_page('mmpp', 'Campaigns', 'Campaigns', 'manage_options', 'mmpp', [__CLASS__, 'page_campaigns']);
    add_submenu_page('mmpp', 'Add campaign', 'Add campaign', 'manage_options', 'mmpp-add', [__CLASS__, 'page_campaign_edit']);
    add_submenu_page('mmpp', 'Analytics', 'Analytics', 'manage_options', 'mmpp-analytics', [__CLASS__, 'page_analytics']);
    add_submenu_page('mmpp', 'Entries', 'Entries', 'manage_options', 'mmpp-entries', [__CLASS__, 'page_entries']);
  }

  private static function admin_url($slug, $args = []) {
    $url = admin_url('admin.php?page=' . $slug);
    if (!empty($args)) $url = add_query_arg($args, $url);
    return $url;
  }

  private static function to_local_dt($gmt_sql) {
    $gmt_sql = trim((string) $gmt_sql);
    if (!$gmt_sql) return '';
    try {
      $dt = new DateTimeImmutable($gmt_sql, new DateTimeZone('UTC'));
      $local = $dt->setTimezone(wp_timezone());
      return $local->format('Y-m-d\TH:i');
    } catch (Exception $e) {
      return '';
    }
  }

  private static function from_local_dt($local_dt) {
    $local_dt = trim((string) $local_dt);
    if (!$local_dt) return '';
    try {
      $tz = wp_timezone();
      // datetime-local uses "YYYY-MM-DDTHH:MM"
      $dt = new DateTimeImmutable($local_dt, $tz);
      $gmt = $dt->setTimezone(new DateTimeZone('UTC'));
      return $gmt->format('Y-m-d H:i:s');
    } catch (Exception $e) {
      return '';
    }
  }

  public static function page_campaigns() {
    if (!current_user_can('manage_options')) return;

    $campaigns = MMPP_DB::list_campaigns();

    echo '<div class="wrap mmpp">';
    echo '<h1>Promo Pints campaigns</h1>';

    echo '<p><a class="button button-primary" href="' . esc_url(self::admin_url('mmpp-add')) . '">Add campaign</a></p>';

    if (!$campaigns) {
      echo '<div class="notice notice-info"><p>No campaigns yet.</p></div>';
      echo '</div>';
      return;
    }

    echo '<table class="widefat striped">';
    echo '<thead><tr>';
    echo '<th>Name</th><th>Slug</th><th>Status</th><th>Form ID</th><th>Webhook URL</th><th>QR</th><th>Created</th><th></th>';
    echo '</tr></thead><tbody>';

    foreach ($campaigns as $c) {
      $webhook = rest_url('mmpp/v1/webhook/' . $c->webhook_key);
      $edit = self::admin_url('mmpp-add', ['id' => (int) $c->id]);
      $analytics = self::admin_url('mmpp-analytics', ['id' => (int) $c->id]);

      echo '<tr>';
      echo '<td>' . esc_html($c->name) . '</td>';
      echo '<td><code>' . esc_html($c->slug) . '</code></td>';
      $state = MMPP_DB::campaign_state($c);
      $badge = $state;
      if ($state === 'active') $badge = 'Active';
      elseif ($state === 'scheduled') $badge = 'Scheduled';
      elseif ($state === 'ended') $badge = 'Ended';
      elseif ($state === 'always') $badge = 'Always on';
      echo '<td><span class="mmpp-badge mmpp-badge-' . esc_attr($state) . '">' . esc_html($badge) . '</span></td>';
      echo '<td>' . esc_html($c->form_id ?: '-') . '</td>';
      echo '<td><code>' . esc_html($webhook) . '</code></td>';
      echo '<td>' . ($c->qr_enabled ? 'On' : 'Off') . '</td>';
      echo '<td>' . esc_html($c->created_at) . ' (GMT)</td>';
      echo '<td style="white-space:nowrap">'
        . '<a class="button" href="' . esc_url($edit) . '">Edit</a> '
        . '<a class="button" href="' . esc_url($analytics) . '">Analytics</a> '
        . '<a class="button" href="' . esc_url(self::admin_url('mmpp-entries', ['id' => (int) $c->id])) . '">Entries</a>'
        . '</td>';
      echo '</tr>';
    }

    echo '</tbody></table>';

    echo '<div class="mmpp-help">';
    echo '<h2>How to connect a Kadence Form (Adv)</h2>';
    echo '<ol>';
    echo '<li>Open your Form (Adv) block settings in the editor.</li>';
    echo '<li>Submit Actions: add a WebHook action.</li>';
    echo '<li>Webhook URL: paste the campaign webhook URL shown above.</li>';
    echo '<li>Map Fields: map your email field to the webhook field name <code>email</code> (or whatever you set in campaign settings).</li>';
    echo '</ol>';
    echo '<p>Kadence webhook integration is a Pro feature. ';
    echo 'If you do not have it, use the shortcode form below instead.</p>';
    echo '<h3>Shortcodes</h3>';
    echo '<p><code>[mmpp_signup campaign="your-campaign-slug"]</code> shows a basic signup form.</p>';
    echo '<p><code>[mmpp_claim]</code> shows the claim page (reads token from the URL).</p>';
    echo '</div>';

    echo '</div>';
  }

  public static function page_campaign_edit() {
    if (!current_user_can('manage_options')) return;

    $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
    $c = $id ? MMPP_DB::get_campaign($id) : null;

    $defaults = [
      'name' => '',
      'slug' => '',
      'form_id' => '',
      'claim_page_id' => 0,
      'start_at' => '',
      'end_at' => '',
      'email_field_name' => 'email',
      'email_subject' => 'Your free pint claim link',
      'email_from_name' => '',
      'email_from_email' => '',
      'email_is_html' => '1',
      'email_button_text' => 'Open my free pint pass',
      'email_body' => "Thanks for signing up.\n\nUse this link to claim your free pint:\n{claim_link}\n\nIf you are at the bar, show this page to staff.",
      'staff_pin' => '',
      'qr_enabled' => 0,
    ];

    $data = $defaults;
    if ($c) {
      foreach ($defaults as $k => $v) {
        if (isset($c->{$k})) $data[$k] = (string) $c->{$k};
      }
      $data['qr_enabled'] = (int) $c->qr_enabled;
    }

    echo '<div class="wrap mmpp">';
    echo '<h1>' . ($c ? 'Edit campaign' : 'Add campaign') . '</h1>';

    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    wp_nonce_field('mmpp_save_campaign');
    echo '<input type="hidden" name="action" value="mmpp_save_campaign">';
    if ($c) echo '<input type="hidden" name="id" value="' . (int) $c->id . '">';

    echo '<table class="form-table">';

    self::row_text('Name', 'name', $data['name'], 'Human friendly name, for example Opening day free pint');
    self::row_text('Slug', 'slug', $data['slug'], 'Lowercase, no spaces. Used in shortcodes, for example opening-day');
    self::row_text('Kadence Form ID (optional)', 'form_id', $data['form_id'], 'For your tracking only. Webhook URL is what matters.');

    echo '<tr><th scope="row"><label for="claim_page_id">Claim page</label></th><td>';
    wp_dropdown_pages([
      'name' => 'claim_page_id',
      'id' => 'claim_page_id',
      'selected' => (int) $data['claim_page_id'],
      'show_option_none' => 'Auto-detect (first page with [mmpp_claim])',
      'option_none_value' => '0',
    ]);
    echo '<p class="description">Recommended. Pick the page that contains <code>[mmpp_claim]</code>. This fixes email links pointing at the homepage.</p>';
    echo '</td></tr>';

echo '<tr><th scope="row"><label for="start_at">Start date (optional)</label></th><td>';
    echo '<input type="datetime-local" name="start_at" id="start_at" value="' . esc_attr(self::to_local_dt($data['start_at'])) . '">';
    echo '<p class="description">If set, signups and redemptions only work from this time.</p>';
    echo '</td></tr>';

    echo '<tr><th scope="row"><label for="end_at">End date (optional)</label></th><td>';
    echo '<input type="datetime-local" name="end_at" id="end_at" value="' . esc_attr(self::to_local_dt($data['end_at'])) . '">';
    echo '<p class="description">If set, signups and redemptions stop after this time.</p>';
    echo '</td></tr>';

    self::row_text('Email field name', 'email_field_name', $data['email_field_name'], 'Kadence webhook field name that contains the email, default is email.');
    self::row_text('Email subject', 'email_subject', $data['email_subject'], 'Subject for the claim email.');
    self::row_text('Email from name (optional)', 'email_from_name', $data['email_from_name'], 'Leave blank to use site defaults.');
    self::row_text('Email from email (optional)', 'email_from_email', $data['email_from_email'], 'Leave blank to use site defaults.');

    echo '<tr><th scope="row">Email format</th><td>';
    echo '<label><input type="checkbox" name="email_is_html" value="1" ' . checked(1, (int) $data['email_is_html'], false) . '> Send as HTML (recommended)</label>';
    echo '<p class="description">If enabled, the claim link is rendered as a button in most email clients.</p>';
    echo '</td></tr>';

    self::row_text('Button text', 'email_button_text', $data['email_button_text'], 'Text shown on the claim button in the email.');

    echo '<tr><th scope="row"><label for="email_body">Email body</label></th><td>';
    echo '<textarea class="large-text" rows="8" name="email_body" id="email_body">' . esc_textarea($data['email_body']) . '</textarea>';
    echo '<p class="description">Use <code>{claim_link}</code> to insert the unique claim link.</p>';
    echo '</td></tr>';

    self::row_text('Staff PIN (optional)', 'staff_pin', $data['staff_pin'], 'If set, redeem requires this PIN. This helps prevent customers redeeming early.');

    echo '<tr><th scope="row">QR scanner for staff</th><td>';
    echo '<label><input type="checkbox" name="qr_enabled" value="1" ' . checked(1, (int) $data['qr_enabled'], false) . '> Enable QR scanning UI on the claim page</label>';
    echo '<p class="description">When enabled, the claim page shows a QR code and a scan button for staff devices.</p>';
    echo '</td></tr>';

    echo '</table>';

    submit_button($c ? 'Save campaign' : 'Create campaign');

    echo '</form>';

    if ($c) {
      $webhook = rest_url('mmpp/v1/webhook/' . $c->webhook_key);
      $claim_base = MMPP_DB::get_claim_page_url($c);
      echo '<hr>';
      echo '<h2>Connection info</h2>';
      echo '<p>Webhook URL:</p>';
      echo '<p><code>' . esc_html($webhook) . '</code></p>';
      echo '<p>Claim page shortcode: <code>[mmpp_claim]</code></p>';
      echo '<p>Claim page URL used in emails:</p>';
      echo '<p><code>' . esc_html($claim_base) . '</code></p>';
      if (trailingslashit($claim_base) === trailingslashit(home_url('/'))) {
        echo '<div class="notice notice-warning"><p>Email links are currently based on the homepage. Set the Claim page dropdown above to fix this.</p></div>';
      }
    }

    echo '</div>';
  }

  private static function row_text($label, $name, $value, $help = '') {
    echo '<tr><th scope="row"><label for="' . esc_attr($name) . '">' . esc_html($label) . '</label></th><td>';
    echo '<input class="regular-text" type="text" name="' . esc_attr($name) . '" id="' . esc_attr($name) . '" value="' . esc_attr($value) . '">';
    if ($help) echo '<p class="description">' . wp_kses_post($help) . '</p>';
    echo '</td></tr>';
  }

  public static function handle_save_campaign() {
    if (!current_user_can('manage_options')) wp_die('No permission');
    check_admin_referer('mmpp_save_campaign');

    global $wpdb;
    $t = MMPP_DB::table_campaigns();

    $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;

    $name = sanitize_text_field($_POST['name'] ?? '');
    $slug = sanitize_title($_POST['slug'] ?? '');
    $form_id = sanitize_text_field($_POST['form_id'] ?? '');
    $claim_page_id = isset($_POST['claim_page_id']) ? (int) $_POST['claim_page_id'] : 0;
    $start_at = self::from_local_dt($_POST['start_at'] ?? '');
    $end_at = self::from_local_dt($_POST['end_at'] ?? '');
    $email_field_name = sanitize_text_field($_POST['email_field_name'] ?? 'email');

    $email_subject = sanitize_text_field($_POST['email_subject'] ?? 'Your free pint claim link');
    $email_from_name = sanitize_text_field($_POST['email_from_name'] ?? '');
    $email_from_email = sanitize_email($_POST['email_from_email'] ?? '');
    $email_is_html = !empty($_POST['email_is_html']) ? 1 : 0;
    $email_button_text = sanitize_text_field($_POST['email_button_text'] ?? 'Open my free pint pass');
    $email_body = wp_kses_post($_POST['email_body'] ?? '');

    $staff_pin = sanitize_text_field($_POST['staff_pin'] ?? '');
    $qr_enabled = !empty($_POST['qr_enabled']) ? 1 : 0;

    if (!$name || !$slug) {
      wp_redirect(self::admin_url('mmpp-add', ['error' => 'missing']));
      exit;
    }

    $data = [
      'name' => $name,
      'slug' => $slug,
      'form_id' => $form_id ?: null,
      'claim_page_id' => $claim_page_id > 0 ? $claim_page_id : null,
      'start_at' => $start_at ?: null,
      'end_at' => $end_at ?: null,
      'email_field_name' => $email_field_name ?: 'email',
      'email_subject' => $email_subject,
      'email_from_name' => $email_from_name ?: null,
      'email_from_email' => $email_from_email ?: null,
      'email_is_html' => (int) $email_is_html,
      'email_button_text' => $email_button_text ?: 'Open my free pint pass',
      'email_body' => $email_body ?: null,
      'staff_pin' => $staff_pin ?: null,
      'qr_enabled' => $qr_enabled,
    ];

    if ($id) {
      $wpdb->update($t, $data, ['id' => $id]);
      wp_redirect(self::admin_url('mmpp-add', ['id' => $id, 'saved' => 1]));
      exit;
    }

    $data['webhook_key'] = MMPP_DB::random_key(32);
    $data['created_at'] = MMPP_DB::now_gmt();

    $wpdb->insert($t, $data);
    $new_id = (int) $wpdb->insert_id;

    wp_redirect(self::admin_url('mmpp-add', ['id' => $new_id, 'created' => 1]));
    exit;
  }


  public static function page_entries() {
    if (!current_user_can('manage_options')) return;

    $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
    if (!$id) {
      $campaigns = MMPP_DB::list_campaigns();
      echo '<div class="wrap mmpp"><h1>Entries</h1>';
      if (!$campaigns) {
        echo '<p>No campaigns yet.</p></div>';
        return;
      }
      echo '<p>Select a campaign:</p><ul>';
      foreach ($campaigns as $c) {
        echo '<li><a href="' . esc_url(self::admin_url('mmpp-entries', ['id' => (int) $c->id])) . '">' . esc_html($c->name) . '</a></li>';
      }
      echo '</ul></div>';
      return;
    }

    $c = MMPP_DB::get_campaign($id);
    if (!$c) {
      echo '<div class="wrap mmpp"><h1>Entries</h1><p>Campaign not found.</p></div>';
      return;
    }

    $search = sanitize_text_field($_GET['s'] ?? '');
    $paged = isset($_GET['paged']) ? max(1, (int) $_GET['paged']) : 1;
    $per_page = 50;

    $total = MMPP_DB::count_entries($id, $search);
    $rows = MMPP_DB::list_entries($id, $search, $paged, $per_page);

    $pages = max(1, (int) ceil($total / $per_page));

    $export = admin_url('admin-post.php?action=mmpp_export_csv&id=' . (int) $id . '&_wpnonce=' . wp_create_nonce('mmpp_export_csv'));

    echo '<div class="wrap mmpp">';
    echo '<h1>Entries: ' . esc_html($c->name) . '</h1>';

    echo '<form method="get" style="margin:12px 0;">';
    echo '<input type="hidden" name="page" value="mmpp-entries">';
    echo '<input type="hidden" name="id" value="' . (int) $id . '">';
    echo '<input type="search" name="s" value="' . esc_attr($search) . '" placeholder="Search email" style="min-width:280px;"> ';
    echo '<button class="button">Search</button> ';
    echo '<a class="button" href="' . esc_url($export) . '">Export CSV</a>';
    echo '</form>';

    echo '<table class="widefat striped">';
    echo '<thead><tr>';
    echo '<th>Email</th><th>Redeemed</th><th>Signup (GMT)</th><th>Redeemed at (GMT)</th><th>Redeemed IP</th>';
    echo '</tr></thead><tbody>';

    if (!$rows) {
      echo '<tr><td colspan="5">No entries found.</td></tr>';
    } else {
      foreach ($rows as $r) {
        $redeemed = ((int) $r->status === 0);
        echo '<tr>';
        echo '<td><code>' . esc_html($r->email) . '</code></td>';
        echo '<td>' . ($redeemed ? '<span class="mmpp-check">✓</span>' : '—') . '</td>';
        echo '<td>' . esc_html($r->signup_at) . '</td>';
        echo '<td>' . esc_html($r->redeemed_at ?: '-') . '</td>';
        echo '<td>' . esc_html($r->redeemed_ip ?: '-') . '</td>';
        echo '</tr>';
      }
    }

    echo '</tbody></table>';

    if ($pages > 1) {
      echo '<div class="tablenav" style="margin-top:12px;">';
      echo '<div class="tablenav-pages">';
      for ($p = 1; $p <= $pages; $p++) {
        $u = self::admin_url('mmpp-entries', ['id' => $id, 'paged' => $p, 's' => $search]);
        $cls = $p === $paged ? 'button button-primary' : 'button';
        echo '<a class="' . esc_attr($cls) . '" style="margin-right:6px;" href="' . esc_url($u) . '">' . (int) $p . '</a>';
      }
      echo '</div></div>';
    }

    echo '</div>';
  }

  public static function page_analytics() {
    if (!current_user_can('manage_options')) return;

    $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
    if (!$id) {
      echo '<div class="wrap mmpp"><h1>Analytics</h1><p>Select a campaign from the Campaigns page.</p></div>';
      return;
    }

    $c = MMPP_DB::get_campaign($id);
    if (!$c) {
      echo '<div class="wrap mmpp"><h1>Analytics</h1><p>Campaign not found.</p></div>';
      return;
    }

    $stats = MMPP_DB::stats_for_campaign($id);
    $export = admin_url('admin-post.php?action=mmpp_export_csv&id=' . (int) $id . '&_wpnonce=' . wp_create_nonce('mmpp_export_csv'));

    echo '<div class="wrap mmpp">';
    echo '<h1>Analytics: ' . esc_html($c->name) . '</h1>';

    echo '<div class="mmpp-cards">';
    echo '<div class="mmpp-card"><div class="mmpp-card-k">Signups</div><div class="mmpp-card-v">' . (int) $stats['total'] . '</div></div>';
    echo '<div class="mmpp-card"><div class="mmpp-card-k">Redeemed</div><div class="mmpp-card-v">' . (int) $stats['redeemed'] . '</div></div>';
    echo '<div class="mmpp-card"><div class="mmpp-card-k">Pending</div><div class="mmpp-card-v">' . (int) $stats['pending'] . '</div></div>';

    $rate = $stats['total'] ? round(($stats['redeemed'] / $stats['total']) * 100, 1) : 0;
    echo '<div class="mmpp-card"><div class="mmpp-card-k">Redeem rate</div><div class="mmpp-card-v">' . esc_html($rate) . '%</div></div>';
    echo '</div>';

    echo '<p><a class="button" href="' . esc_url($export) . '">Export CSV</a></p>';

    echo '<h2>Signups last 14 days</h2>';

    $days = [];
    for ($i = 13; $i >= 0; $i--) {
      $d = gmdate('Y-m-d', time() - $i * DAY_IN_SECONDS);
      $days[] = $d;
    }

    $max = 0;
    foreach ($days as $d) {
      $v = (int) ($stats['by_day'][$d] ?? 0);
      if ($v > $max) $max = $v;
    }
    if ($max < 1) $max = 1;

    echo '<div class="mmpp-bars">';
    foreach ($days as $d) {
      $v = (int) ($stats['by_day'][$d] ?? 0);
      $h = (int) round(($v / $max) * 100);
      echo '<div class="mmpp-bar">';
      echo '<div class="mmpp-bar-col" style="height:' . $h . '%"></div>';
      echo '<div class="mmpp-bar-label">' . esc_html(substr($d, 5)) . '<br><span>' . (int) $v . '</span></div>';
      echo '</div>';
    }
    echo '</div>';

    echo '</div>';
  }

  public static function handle_export_csv() {
    if (!current_user_can('manage_options')) wp_die('No permission');
    if (!wp_verify_nonce($_GET['_wpnonce'] ?? '', 'mmpp_export_csv')) wp_die('Bad nonce');

    $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
    $c = $id ? MMPP_DB::get_campaign($id) : null;
    if (!$c) wp_die('Campaign not found');

    $filename = 'mmpp-' . $c->slug . '-' . gmdate('Ymd-His') . '.csv';

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=' . $filename);

    MMPP_DB::export_entries_csv($id);
    exit;
  }
}
