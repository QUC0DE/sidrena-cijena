<?php
/**
 * Plugin Name: Sidrena cijena i cjenik
 * Description: Unos usluga i proizvoda s dodatnom (sidrenom) cijenom, automatsko generiranje CSV/XML cjenika prema NN 101/2026 i REST + WPGraphQL API za headless frontend.
 * Version: 1.1.0
 * Requires PHP: 7.4
 * Requires at least: 6.0
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Update URI: https://github.com/QUC0DE/sidrena-cijena
 * Text Domain: sidrena-cjenik
 */

if (!defined('ABSPATH')) exit;

final class Sidrena_Cjenik {
    const CPT          = 'cjenik_stavka';
    const TAX          = 'cjenik_kategorija';
    const OPT          = 'sc_postavke';
    const REG          = 'sc_registar';
    const CRON         = 'sc_dnevni_cjenik';
    const DIR          = 'cjenik';
    const DEFAULT_DATUM = '2026-09-10';
    const CSV_SEP      = ',';
    const ARHIVA_DANA  = 45;
    const REPO         = 'QUC0DE/sidrena-cijena';
    const RELEASE_ZIP  = 'sidrena-cjenik.zip';

    const TIPOVI = ['usluga', 'proizvod'];

    private static $fields = [
        'vrsta'            => 'usluga',
        'cijena'           => '',
        'sidrena_cijena'   => '',
        'sidrena_datum'    => self::DEFAULT_DATUM,
        'akcija_cijena'    => '',
        'akcija_naziv'     => '',
        'akcija_najniza30' => '',
        'sifra'            => '',
        'marka'            => '',
        'jedinica_mjere'   => '',
        'cijena_jm'        => '',
        'barkod'           => '',
        'dostupnost'       => 'dostupno',
        'nova_cijena'      => '',
        'nova_cijena_od'   => '',
    ];
    private static $price_fields = ['cijena', 'sidrena_cijena', 'akcija_cijena', 'akcija_najniza30', 'cijena_jm', 'nova_cijena'];
    private static $akcija_fields = ['akcija_cijena', 'akcija_naziv', 'akcija_najniza30'];

    public static function init() {
        add_action('init', [__CLASS__, 'register']);
        add_filter('use_block_editor_for_post_type', fn($use, $pt) => $pt === self::CPT ? false : $use, 10, 2);
        add_action('add_meta_boxes', [__CLASS__, 'meta_box']);
        add_action('save_post_' . self::CPT, [__CLASS__, 'save']);
        add_action('save_post_' . self::CPT, [__CLASS__, 'on_change'], 99, 2);
        add_action('trashed_post', [__CLASS__, 'on_delete']);
        add_action('untrashed_post', [__CLASS__, 'on_delete']);
        add_action('deleted_post', [__CLASS__, 'on_delete']);
        add_action('admin_menu', [__CLASS__, 'admin_menu']);
        add_action('admin_init', fn() => register_setting('sc_group', self::OPT, ['sanitize_callback' => [__CLASS__, 'sanitize_settings']]));
        add_action('admin_post_sc_generiraj', [__CLASS__, 'manual_generate']);
        add_action('admin_post_sc_reset', [__CLASS__, 'manual_reset']);
        add_action('rest_api_init', [__CLASS__, 'rest']);
        add_action('graphql_register_types', [__CLASS__, 'graphql']);
        add_action(self::CRON, [__CLASS__, 'cron']);
        add_filter('manage_' . self::CPT . '_posts_columns', [__CLASS__, 'columns']);
        add_action('manage_' . self::CPT . '_posts_custom_column', [__CLASS__, 'column'], 10, 2);
        add_filter('views_edit-' . self::CPT, [__CLASS__, 'views']);
        add_action('pre_get_posts', [__CLASS__, 'filter_list']);
        add_action('admin_notices', [__CLASS__, 'admin_notices']);
        add_filter('update_plugins_github.com', [__CLASS__, 'check_update'], 10, 3);
        add_filter('upgrader_pre_download', [__CLASS__, 'download_private'], 10, 2);
        add_filter('upgrader_source_selection', [__CLASS__, 'keep_folder_name'], 10, 4);
        register_deactivation_hook(__FILE__, fn() => wp_clear_scheduled_hook(self::CRON));
    }

    public static function register() {
        register_post_type(self::CPT, [
            'labels' => [
                'name' => 'Cjenik', 'singular_name' => 'Stavka cjenika', 'add_new' => 'Dodaj stavku',
                'add_new_item' => 'Nova stavka cjenika', 'edit_item' => 'Uredi stavku', 'all_items' => 'Sve stavke',
            ],
            'public' => false, 'show_ui' => true, 'show_in_rest' => true,
            'menu_icon' => 'dashicons-money-alt', 'supports' => ['title', 'page-attributes'],
        ]);
        register_taxonomy(self::TAX, self::CPT, [
            'labels' => ['name' => 'Kategorije cjenika', 'singular_name' => 'Kategorija'],
            'hierarchical' => true, 'show_in_rest' => true, 'show_admin_column' => true, 'public' => false, 'show_ui' => true,
        ]);
        foreach (array_keys(self::$fields) as $k) {
            register_post_meta(self::CPT, 'sc_' . $k, [
                'type' => 'string', 'single' => true, 'show_in_rest' => true,
                'sanitize_callback' => 'sanitize_text_field',
                'auth_callback' => fn() => current_user_can('edit_posts'),
            ]);
        }
        if (!wp_next_scheduled(self::CRON)) {
            $next = new DateTime('today 06:30', wp_timezone());
            if ($next->getTimestamp() <= time()) $next->modify('+1 day');
            wp_schedule_event($next->getTimestamp(), 'daily', self::CRON);
        }
    }

    public static function meta_box() {
        add_meta_box('sc_cijene', 'Cijene', [__CLASS__, 'render_box'], self::CPT, 'normal', 'high');
    }

    public static function render_box($post) {
        wp_nonce_field('sc_save', 'sc_nonce');
        $v = [];
        foreach (self::$fields as $k => $def) {
            $val = get_post_meta($post->ID, 'sc_' . $k, true);
            $v[$k] = ($val === '' && $post->post_status === 'auto-draft') ? $def : $val;
        }
        foreach (self::$price_fields as $k) $v[$k] = self::price_input($v[$k]);
        $vrsta   = $v['vrsta'] === 'proizvod' ? 'proizvod' : 'usluga';
        $uvedeno = ($v['sidrena_datum'] !== '' && $v['sidrena_datum'] !== self::DEFAULT_DATUM) ? 'nakon' : 'prije';
        $akcija  = $v['akcija_cijena'] !== '';
        $default = self::fmt_date(self::DEFAULT_DATUM);

        $field = function ($k, $label, $help = '', $attrs = '', $type = 'text', $wrap = '') use ($v) {
            printf('<div class="sc-field %7$s"><label for="sc_%1$s">%2$s</label><input type="%3$s" id="sc_%1$s" name="sc_%1$s" value="%4$s" %5$s>%6$s</div>',
                esc_attr($k), wp_kses_post($label), esc_attr($type), esc_attr($v[$k]), $attrs,
                $help ? '<p class="description">' . wp_kses_post($help) . '</p>' : '', esc_attr($wrap));
        };
        $price = fn($k, $label, $help = '', $extra = '') => $field($k, $label, $help, 'inputmode="decimal" placeholder="0,00" class="sc-price" ' . $extra);

        echo self::admin_css();
        printf('<div class="sc-box" data-vrsta="%s" data-uvedeno="%s" data-akcija="%s" data-default-date="%s">',
            esc_attr($vrsta), esc_attr($uvedeno), $akcija ? '1' : '0', esc_attr(self::DEFAULT_DATUM));

        echo '<fieldset class="sc-section"><legend>Vrsta stavke</legend><div class="sc-choices">';
        foreach ([
            'usluga'   => ['Usluga', 'Ide u <strong>cjenik usluga</strong>. Novi cjenik se objavljuje kod svake promjene cijene.'],
            'proizvod' => ['Proizvod', 'Npr. digitalni materijal koji se prodaje online. Ide u <strong>cjenik proizvoda</strong> (webshop), koji se objavljuje svaki dan do 8:00.'],
        ] as $k => [$l, $d]) {
            printf('<label class="sc-choice"><input type="radio" name="sc_vrsta" value="%s"%s><span><strong>%s</strong><small>%s</small></span></label>',
                esc_attr($k), checked($vrsta, $k, false), esc_html($l), wp_kses_post($d));
        }
        echo '</div></fieldset>';

        echo '<fieldset class="sc-section"><legend>Cijena</legend><div class="sc-row">';
        $price('cijena', 'Redovna cijena (€) <span class="sc-req">*</span>', 'Trenutna cijena bez akcije, npr. 45,00');
        echo '</div><div class="sc-field"><span class="sc-label">Kada je stavka uvedena u ponudu?</span><div class="sc-inline">';
        foreach (['prije' => "Na dan $default. ili ranije", 'nakon' => "Nakon $default."] as $k => $l) {
            printf('<label><input type="radio" name="sc_uvedeno" value="%s"%s> %s</label>', esc_attr($k), checked($uvedeno, $k, false), esc_html($l));
        }
        echo '</div></div><div class="sc-row">';
        $price('sidrena_cijena', 'Dodatna (sidrena) cijena (€)');
        $field('sidrena_datum', 'Datum dodatne cijene', 'Datum kad je stavka prvi put uvedena u prodaju.', '', 'date', 'sc-only-nakon');
        echo '</div>';
        printf('<p class="description sc-only-prije">Upišite redovnu cijenu koja je vrijedila na dan %s. (bez akcija i popusta). Ako polje ostavite prazno, spremit će se trenutna redovna cijena. Kad je jednom spremljena, ova se cijena više ne mijenja.</p>', esc_html($default));
        echo '<p class="description sc-only-nakon">Za novu stavku dodatna cijena je cijena po kojoj je prvi put uvedena u prodaju. Ako polje ostavite prazno, spremit će se trenutna redovna cijena.</p>';
        echo '</fieldset>';

        echo '<fieldset class="sc-section"><legend>Akcija / popust</legend>';
        printf('<label class="sc-toggle"><input type="checkbox" name="sc_akcija_on" value="1"%s> Stavka je trenutno na akciji ili sniženju</label>', checked($akcija, true, false));
        echo '<div class="sc-row sc-only-akcija">';
        $price('akcija_cijena', 'Akcijska cijena (€) <span class="sc-req">*</span>');
        $field('akcija_naziv', 'Naziv akcije <span class="sc-req">*</span>', 'Npr. "Jesenski popust". Upisuje se u cjenik.');
        $price('akcija_najniza30', 'Najniža cijena u zadnjih 30 dana (€)', 'Mora biti istaknuta uz akcijsku cijenu.');
        echo '</div></fieldset>';

        echo '<fieldset class="sc-section"><legend>Zakazana promjena cijene</legend>';
        echo '<p class="description">Neobavezno. Na odabrani dan u 6:30 nova cijena postaje redovna i objavljuje se novi cjenik. Propis traži da cjenik s novom cijenom bude objavljen najkasnije do 8:00 na dan kad promjena stupa na snagu.</p><div class="sc-row">';
        $price('nova_cijena', 'Nova cijena (€)');
        $field('nova_cijena_od', 'Vrijedi od', '', 'min="' . esc_attr(wp_date('Y-m-d')) . '"', 'date');
        echo '</div></fieldset>';

        echo '<fieldset class="sc-section sc-only-proizvod"><legend>Podaci za cjenik proizvoda</legend><div class="sc-row">';
        $field('sifra', 'Šifra', 'Ako je prazno, koristi se SC-' . (int) $post->ID . '.');
        $field('marka', 'Marka <span class="sc-req">*</span>', 'Npr. naziv centra za vlastiti materijal.');
        $field('barkod', 'Barkod', 'Ako postoji.');
        echo '</div><div class="sc-row">';
        $field('jedinica_mjere', 'Jedinica mjere', 'Ako je primjenjivo, npr. kom.');
        $price('cijena_jm', 'Cijena za jedinicu mjere (€)', 'Ako je primjenjivo.');
        echo '<div class="sc-field"><label for="sc_dostupnost">Raspoloživost</label><select id="sc_dostupnost" name="sc_dostupnost">';
        foreach (['dostupno' => 'Dostupno', 'nedostupno' => 'Nedostupno'] as $k => $l) {
            printf('<option value="%s"%s>%s</option>', esc_attr($k), selected($v['dostupnost'], $k, false), esc_html($l));
        }
        echo '</select></div></div></fieldset>';

        echo '<div class="sc-preview"><span class="sc-label">Ovako će cijena izgledati na stranici</span><div class="sc-preview-text"></div></div>';
        echo '</div>';
        echo self::box_js();
    }

    private static function box_js() {
        return <<<'HTML'
<script>
(function () {
    var box = document.querySelector('.sc-box');
    if (!box) return;
    var byName = function (n) { return box.querySelector('[name="' + n + '"]'); };
    var checked = function (n) { var el = box.querySelector('[name="' + n + '"]:checked'); return el ? el.value : ''; };
    var parse = function (s) {
        s = (s || '').replace(/[\s€ ]/g, '');
        if (s.indexOf(',') !== -1) s = s.replace(/\./g, '').replace(',', '.');
        var n = parseFloat(s);
        return isNaN(n) ? null : n;
    };
    var eur = function (n) { return n === null ? '–' : n.toLocaleString('hr-HR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' €'; };
    var day = function (ymd) { var p = (ymd || '').split('-'); return p.length === 3 ? (+p[2]) + '. ' + (+p[1]) + '. ' + p[0] + '.' : ''; };
    var today = function () { var d = new Date(); return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); };

    function update() {
        box.dataset.vrsta = checked('sc_vrsta') || 'usluga';
        box.dataset.uvedeno = checked('sc_uvedeno') || 'prije';
        box.dataset.akcija = byName('sc_akcija_on').checked ? '1' : '0';

        var date = byName('sc_sidrena_datum');
        if (box.dataset.uvedeno === 'nakon' && (!date.value || date.value === box.dataset.defaultDate)) date.value = today();

        var regular = parse(byName('sc_cijena').value);
        var anchor = parse(byName('sc_sidrena_cijena').value);
        if (anchor === null) anchor = regular;
        var anchorDate = box.dataset.uvedeno === 'nakon' ? date.value : box.dataset.defaultDate;
        var parts = [];
        if (box.dataset.akcija === '1') {
            var name = byName('sc_akcija_naziv').value;
            parts.push('<strong>' + eur(parse(byName('sc_akcija_cijena').value)) + '</strong>' + (name ? ' (' + name.replace(/</g, '&lt;') + ')' : ''));
            parts.push('Najniža cijena u zadnjih 30 dana: ' + eur(parse(byName('sc_akcija_najniza30').value)));
        } else {
            parts.push('<strong>' + eur(regular) + '</strong>');
        }
        parts.push('Cijena na ' + day(anchorDate) + ': ' + eur(anchor));
        box.querySelector('.sc-preview-text').innerHTML = parts.join('<br>');
        byName('sc_sidrena_cijena').placeholder = regular === null ? '0,00' : eur(regular).replace(' €', '') + ' (ista kao redovna)';
    }
    box.addEventListener('input', update);
    box.addEventListener('change', update);
    update();
})();
</script>
HTML;
    }

    private static function admin_css() {
        return <<<'HTML'
<style>
.sc-box { --sc-muted: #646970; --sc-border: #dcdcde; }
.sc-box .sc-section { border: 0; border-top: 1px solid var(--sc-border); margin: 0; padding: 16px 0; }
.sc-box .sc-section:first-child { border-top: 0; padding-top: 4px; }
.sc-box legend { font-size: 14px; font-weight: 600; padding: 0 0 8px; }
.sc-box .sc-row { display: flex; flex-wrap: wrap; gap: 16px; margin-bottom: 8px; }
.sc-box .sc-field { display: flex; flex-direction: column; gap: 4px; min-width: 200px; flex: 1 1 200px; max-width: 320px; margin-bottom: 8px; }
.sc-box .sc-field label, .sc-box .sc-label { font-weight: 600; }
.sc-box .sc-field .description { margin: 0; }
.sc-box .sc-req { color: #d63638; }
.sc-box .sc-choices { display: flex; flex-wrap: wrap; gap: 12px; }
.sc-box .sc-choice { display: flex; gap: 8px; align-items: flex-start; flex: 1 1 260px; max-width: 420px; padding: 12px; border: 1px solid var(--sc-border); border-radius: 4px; cursor: pointer; }
.sc-box .sc-choice:has(input:checked) { border-color: #2271b1; box-shadow: 0 0 0 1px #2271b1; }
.sc-box .sc-choice span { display: flex; flex-direction: column; gap: 4px; }
.sc-box .sc-choice small { color: var(--sc-muted); font-size: 12px; }
.sc-box .sc-inline { display: flex; flex-wrap: wrap; gap: 16px; margin-bottom: 8px; }
.sc-box .sc-toggle { display: inline-block; margin-bottom: 12px; }
.sc-box .sc-preview { background: #f6f7f7; border-left: 4px solid #2271b1; padding: 12px 16px; margin-top: 8px; }
.sc-box .sc-preview-text { margin-top: 6px; line-height: 1.7; }
.sc-box:not([data-vrsta="proizvod"]) .sc-only-proizvod,
.sc-box:not([data-uvedeno="nakon"]) .sc-only-nakon,
.sc-box[data-uvedeno="nakon"] .sc-only-prije,
.sc-box:not([data-akcija="1"]) .sc-only-akcija { display: none; }
</style>
HTML;
    }

    public static function save($post_id) {
        if (!isset($_POST['sc_nonce']) || !wp_verify_nonce($_POST['sc_nonce'], 'sc_save')) return;
        if ((defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) || wp_is_post_revision($post_id)) return;
        if (!current_user_can('edit_post', $post_id)) return;

        $in = [];
        foreach (array_keys(self::$fields) as $k) {
            if (!isset($_POST['sc_' . $k])) continue;
            $val = sanitize_text_field(wp_unslash($_POST['sc_' . $k]));
            if (in_array($k, self::$price_fields, true)) $val = self::norm_price($val);
            $in[$k] = $val;
        }
        if (empty($_POST['sc_akcija_on'])) {
            foreach (self::$akcija_fields as $k) $in[$k] = '';
        }
        $in['vrsta'] = in_array($in['vrsta'] ?? '', self::TIPOVI, true) ? $in['vrsta'] : 'usluga';
        $is_new_item = ($_POST['sc_uvedeno'] ?? '') === 'nakon';
        $datum = $in['sidrena_datum'] ?? '';
        if (!$is_new_item) $in['sidrena_datum'] = self::DEFAULT_DATUM;
        elseif ($datum === '' || $datum === self::DEFAULT_DATUM) $in['sidrena_datum'] = wp_date('Y-m-d');
        if (($in['sidrena_cijena'] ?? '') === '') $in['sidrena_cijena'] = $in['cijena'] ?? '';

        $notices = [];
        // A scheduled change dated today or earlier takes effect right away.
        if (($in['nova_cijena'] ?? '') !== '' && ($in['nova_cijena_od'] ?? '') !== '' && $in['nova_cijena_od'] <= wp_date('Y-m-d')) {
            $in['cijena'] = $in['nova_cijena'];
            $in['nova_cijena'] = $in['nova_cijena_od'] = '';
            $notices[] = ['info', 'Datum zakazane promjene je danas ili prošao, pa je nova cijena odmah postala redovna cijena.'];
        }

        foreach ($in as $k => $val) update_post_meta($post_id, 'sc_' . $k, $val);
        self::add_notices(array_merge($notices, self::validate($post_id)));
    }

    private static function validate($post_id) {
        $m = fn($k) => (string) get_post_meta($post_id, 'sc_' . $k, true);
        $out = [];
        if (trim(get_the_title($post_id)) === '') $out[] = ['error', 'Upišite naziv stavke. Naziv ide u cjenik.'];
        if ($m('cijena') === '') $out[] = ['error', 'Redovna cijena nije upisana.'];
        if ($m('akcija_cijena') === '' && !empty($_POST['sc_akcija_on'])) $out[] = ['error', 'Označili ste akciju, ali akcijska cijena nije upisana.'];
        if ($m('akcija_cijena') !== '' && $m('akcija_naziv') === '') $out[] = ['error', 'Upišite naziv akcije. Propis traži naziv posebnog oblika prodaje.'];
        if ($m('akcija_cijena') !== '' && $m('akcija_najniza30') === '') $out[] = ['warning', 'Upišite najnižu cijenu u zadnjih 30 dana. Mora biti istaknuta uz akcijsku cijenu.'];
        if ($m('vrsta') === 'proizvod' && $m('marka') === '') $out[] = ['warning', 'Marka je obvezan podatak u cjeniku proizvoda.'];
        if (($m('nova_cijena') === '') !== ($m('nova_cijena_od') === '')) $out[] = ['error', 'Za zakazanu promjenu upišite i novu cijenu i datum od kojeg vrijedi.'];
        return $out;
    }

    private static function add_notices(array $notices) {
        if (!$notices) return;
        $key = 'sc_notices_' . get_current_user_id();
        set_transient($key, array_merge(get_transient($key) ?: [], $notices), MINUTE_IN_SECONDS);
    }

    public static function admin_notices() {
        $key = 'sc_notices_' . get_current_user_id();
        $notices = get_transient($key);
        if (!$notices) return;
        delete_transient($key);
        foreach ($notices as [$type, $msg]) {
            printf('<div class="notice notice-%s is-dismissible"><p><strong>Cjenik:</strong> %s</p></div>', esc_attr($type), esc_html($msg));
        }
    }

    public static function on_change($post_id, $post) {
        if ((defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) || wp_is_post_revision($post_id)) return;
        if ($post->post_status === 'auto-draft') return;
        self::regenerate(self::TIPOVI);
    }

    public static function on_delete($post_id) {
        if (get_post_type($post_id) === self::CPT) self::regenerate(self::TIPOVI);
    }

    // Runs every day: products must be republished daily, services whenever a scheduled change kicks in.
    public static function cron() {
        self::apply_scheduled();
        self::regenerate(self::TIPOVI, ['proizvod']);
        update_option('sc_zadnji_cron', time(), false);
    }

    private static function apply_scheduled() {
        $due = get_posts([
            'post_type' => self::CPT, 'post_status' => 'publish', 'numberposts' => -1, 'fields' => 'ids',
            'meta_query' => [['key' => 'sc_nova_cijena_od', 'value' => wp_date('Y-m-d'), 'compare' => '<=', 'type' => 'DATE']],
        ]);
        foreach ($due as $id) {
            $nova = get_post_meta($id, 'sc_nova_cijena', true);
            if ($nova !== '') update_post_meta($id, 'sc_cijena', $nova);
            update_post_meta($id, 'sc_nova_cijena', '');
            update_post_meta($id, 'sc_nova_cijena_od', '');
        }
    }

    /** @param bool|string[] $force true for all types, or the list of types to republish even when unchanged */
    public static function regenerate(array $types, $force = false) {
        $changed = [];
        foreach ($types as $type) {
            $rows = self::rows($type);
            if (!$rows) continue;
            $hash = md5(wp_json_encode($rows));
            $is_forced = $force === true || in_array($type, (array) $force, true);
            if (!$is_forced && get_option("sc_hash_$type") === $hash) continue;
            self::write($type, $rows);
            update_option("sc_hash_$type", $hash, false);
            $changed[] = $type;
        }
        if ($changed) {
            self::cleanup();
            do_action('sc_cjenik_azuriran', $changed);
            $url = self::settings()['revalidate_url'];
            if ($url) wp_remote_post($url, ['blocking' => false, 'timeout' => 3, 'body' => ['tipovi' => $changed]]);
        }
        return $changed;
    }

    private static function items($type = null) {
        $args = [
            'post_type' => self::CPT, 'post_status' => 'publish', 'numberposts' => -1,
            'orderby' => ['menu_order' => 'ASC', 'title' => 'ASC'],
        ];
        if ($type) $args['meta_query'] = [['key' => 'sc_vrsta', 'value' => $type]];
        return get_posts($args);
    }

    private static function rows($type) {
        $rows = [];
        foreach (self::items($type) as $p) {
            $m = fn($k) => (string) get_post_meta($p->ID, 'sc_' . $k, true);
            $akcija = $m('akcija_cijena') !== '';
            $row = ['naziv' => $p->post_title];
            if ($type === 'proizvod') {
                $row['sifra']                    = $m('sifra') ?: 'SC-' . $p->ID;
                $row['marka']                    = $m('marka');
                $row['jedinica_mjere']           = $m('jedinica_mjere');
                $row['cijena_za_jedinicu_mjere'] = $m('cijena_jm');
            }
            $row['maloprodajna_cijena']           = $akcija ? $m('akcija_cijena') : $m('cijena');
            $row['posebni_oblik_prodaje']         = $akcija ? 'DA' : 'NE';
            $row['naziv_posebnog_oblika_prodaje'] = $akcija ? $m('akcija_naziv') : '';
            $row['sidrena_cijena']                = $m('sidrena_cijena') ?: $m('cijena');
            $row['datum_sidrene_cijene']          = self::fmt_date($m('sidrena_datum') ?: self::DEFAULT_DATUM);
            if ($type === 'proizvod') {
                $row['barkod']        = $m('barkod');
                $row['raspolozivost'] = $m('dostupnost') ?: 'dostupno';
            }
            $rows[] = $row;
        }
        return $rows;
    }

    private static function write($type, array $rows) {
        $s = self::settings();
        $n = (int) get_option("sc_broj_$type", 0) + 1;
        update_option("sc_broj_$type", $n, false);

        $base = self::file_base($type, $n);
        $dir = self::dir();

        // UTF-8 BOM so Excel detects the encoding
        $fh = fopen("$dir/$base.csv", 'w');
        fwrite($fh, "\xEF\xBB\xBF");
        fputcsv($fh, array_keys($rows[0]), self::CSV_SEP);
        foreach ($rows as $r) fputcsv($fh, array_values($r), self::CSV_SEP);
        fclose($fh);

        $doc = new DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = true;
        $root = $doc->createElement('cjenik');
        foreach ([
            'subjekt' => $s['subjekt'], 'oblik_objekta' => $s["{$type}_oblik"], 'adresa' => $s['adresa'],
            'oznaka_objekta' => $s["{$type}_oznaka"], 'broj_pohrane' => $n, 'vrijeme' => wp_date('c'),
        ] as $a => $val) $root->setAttribute($a, (string) $val);
        foreach ($rows as $r) {
            $el = $doc->createElement('stavka');
            foreach ($r as $k => $val) {
                $c = $doc->createElement($k);
                $c->appendChild($doc->createTextNode((string) $val));
                $el->appendChild($c);
            }
            $root->appendChild($el);
        }
        $doc->appendChild($root);
        $doc->save("$dir/$base.xml");

        $reg = get_option(self::REG, []);
        $reg[] = ['vrsta' => $type, 'naziv' => $base, 'vrijeme' => time()];
        update_option(self::REG, $reg, false);
    }

    private static function file_base($type, $n) {
        $s = self::settings();
        return self::clean_name(implode('_', [
            $s["{$type}_oblik"], $s['adresa'], $s["{$type}_oznaka"], $n, wp_date('d.m.Y_H:i'),
        ]));
    }

    private static function cleanup() {
        $reg = get_option(self::REG, []);
        $limit = time() - self::ARHIVA_DANA * DAY_IN_SECONDS;
        $current = [];
        foreach ($reg as $e) $current[$e['vrsta']] = $e['naziv'];
        $keep = [];
        foreach ($reg as $e) {
            if ($e['vrijeme'] < $limit && !in_array($e['naziv'], $current, true)) {
                foreach (['csv', 'xml'] as $ext) @unlink(self::dir() . "/{$e['naziv']}.$ext");
            } else {
                $keep[] = $e;
            }
        }
        update_option(self::REG, $keep, false);
    }

    public static function files() {
        $reg = array_reverse(get_option(self::REG, []));
        $url = self::url();
        $out = ['trenutni' => [], 'arhiva' => []];
        foreach ($reg as $e) {
            if (!file_exists(self::dir() . "/{$e['naziv']}.csv")) continue;
            $item = [
                'vrsta' => $e['vrsta'], 'naziv' => $e['naziv'], 'objavljeno' => wp_date('c', $e['vrijeme']),
                'csv' => $url . '/' . rawurlencode($e['naziv'] . '.csv'),
                'xml' => $url . '/' . rawurlencode($e['naziv'] . '.xml'),
            ];
            if (!isset($out['trenutni'][$e['vrsta']])) $out['trenutni'][$e['vrsta']] = $item;
            else $out['arhiva'][] = $item;
        }
        return $out;
    }

    public static function rest() {
        register_rest_route('cjenik/v1', '/stavke', [
            'methods' => 'GET', 'permission_callback' => '__return_true',
            'callback' => fn() => rest_ensure_response(self::data()),
        ]);
        register_rest_route('cjenik/v1', '/datoteke', [
            'methods' => 'GET', 'permission_callback' => '__return_true',
            'callback' => fn() => rest_ensure_response(self::files()),
        ]);
    }

    public static function data() {
        $groups = [];
        foreach (self::items() as $p) {
            $m = fn($k) => (string) get_post_meta($p->ID, 'sc_' . $k, true);
            $terms = get_the_terms($p, self::TAX);
            $kat = ($terms && !is_wp_error($terms)) ? $terms[0] : null;
            $key = $kat ? $kat->slug : 'ostalo';
            if (!isset($groups[$key])) {
                $groups[$key] = ['kategorija' => $kat ? $kat->name : 'Ostalo', 'slug' => $key, 'stavke' => []];
            }
            $groups[$key]['stavke'][] = [
                'id'             => $p->ID,
                'naziv'          => $p->post_title,
                'vrsta'          => $m('vrsta') ?: 'usluga',
                'cijena'         => self::num($m('cijena')),
                'sidrena_cijena' => self::num($m('sidrena_cijena') ?: $m('cijena')),
                'sidrena_datum'  => $m('sidrena_datum') ?: self::DEFAULT_DATUM,
                'akcija'         => $m('akcija_cijena') !== '' ? [
                    'cijena' => self::num($m('akcija_cijena')),
                    'naziv' => $m('akcija_naziv'),
                    'najniza_30_dana' => self::num($m('akcija_najniza30')),
                ] : null,
                'jedinica_mjere' => $m('jedinica_mjere') ?: null,
                'dostupnost'     => $m('vrsta') === 'proizvod' ? ($m('dostupnost') ?: 'dostupno') : null,
            ];
        }
        $f = self::files();
        return ['kategorije' => array_values($groups), 'datoteke' => array_values($f['trenutni']), 'arhiva' => $f['arhiva']];
    }

    public static function graphql() {
        $map = function (array $fields) {
            $out = [];
            foreach ($fields as $name => [$type, $key, $desc]) {
                $out[$name] = ['type' => $type, 'description' => $desc,
                    'resolve' => fn($src) => $src[$key] ?? null];
            }
            return $out;
        };

        register_graphql_object_type('CjenikAkcija', ['description' => 'Akcija / posebni oblik prodaje', 'fields' => $map([
            'cijena'        => ['Float', 'cijena', 'Akcijska cijena'],
            'naziv'         => ['String', 'naziv', 'Naziv akcije'],
            'najniza30Dana' => ['Float', 'najniza_30_dana', 'Najniža cijena u zadnjih 30 dana'],
        ])]);

        register_graphql_object_type('CjenikStavka', ['description' => 'Stavka cjenika', 'fields' => $map([
            'databaseId'    => ['Int', 'id', 'ID stavke'],
            'naziv'         => ['String', 'naziv', 'Naziv usluge/proizvoda'],
            'vrsta'         => ['String', 'vrsta', 'usluga | proizvod'],
            'cijena'        => ['Float', 'cijena', 'Redovna cijena'],
            'sidrenaCijena' => ['Float', 'sidrena_cijena', 'Dodatna (sidrena) cijena'],
            'sidrenaDatum'  => ['String', 'sidrena_datum', 'Datum sidrene cijene (YYYY-MM-DD)'],
            'akcija'        => ['CjenikAkcija', 'akcija', 'Akcija ili null'],
            'jedinicaMjere' => ['String', 'jedinica_mjere', 'Jedinica mjere'],
            'dostupnost'    => ['String', 'dostupnost', 'dostupno | nedostupno (samo proizvodi)'],
        ])]);

        register_graphql_object_type('CjenikKategorija', ['description' => 'Kategorija cjenika', 'fields' => $map([
            'naziv'  => ['String', 'kategorija', 'Naziv kategorije'],
            'slug'   => ['String', 'slug', 'Slug'],
            'stavke' => [['list_of' => 'CjenikStavka'], 'stavke', 'Stavke u kategoriji'],
        ])]);

        register_graphql_object_type('CjenikDatoteka', ['description' => 'Generirana CSV/XML datoteka', 'fields' => $map([
            'vrsta'      => ['String', 'vrsta', 'usluga | proizvod'],
            'naziv'      => ['String', 'naziv', 'Naziv datoteke bez ekstenzije'],
            'objavljeno' => ['String', 'objavljeno', 'Vrijeme objave (ISO 8601)'],
            'csv'        => ['String', 'csv', 'URL CSV datoteke'],
            'xml'        => ['String', 'xml', 'URL XML datoteke'],
        ])]);

        register_graphql_object_type('Cjenik', ['description' => 'Cjenik sa sidrenim cijenama', 'fields' => $map([
            'kategorije' => [['list_of' => 'CjenikKategorija'], 'kategorije', 'Kategorije sa stavkama'],
            'datoteke'   => [['list_of' => 'CjenikDatoteka'], 'datoteke', 'Trenutno važeće datoteke'],
            'arhiva'     => [['list_of' => 'CjenikDatoteka'], 'arhiva', 'Arhiva prethodnih datoteka'],
        ])]);

        register_graphql_field('RootQuery', 'cjenik', [
            'type' => 'Cjenik',
            'description' => 'Javni cjenik usluga i proizvoda sa sidrenim cijenama',
            'resolve' => fn() => self::data(),
        ]);
    }

    public static function settings() {
        return wp_parse_args(get_option(self::OPT, []), [
            'subjekt' => get_bloginfo('name'), 'adresa' => '',
            'usluga_oblik' => 'centar', 'usluga_oznaka' => 'U-01',
            'proizvod_oblik' => 'webshop', 'proizvod_oznaka' => 'W-01',
            'revalidate_url' => '',
        ]);
    }

    public static function sanitize_settings($in) {
        $out = [];
        foreach (array_keys(self::settings()) as $k) {
            $out[$k] = $k === 'revalidate_url' ? esc_url_raw($in[$k] ?? '') : sanitize_text_field($in[$k] ?? '');
        }
        return $out;
    }

    public static function admin_menu() {
        add_submenu_page('edit.php?post_type=' . self::CPT, 'Objava cjenika', 'Objava i postavke', 'manage_options', 'sc-postavke', [__CLASS__, 'settings_page']);
    }

    private static function type_info($type) {
        return $type === 'proizvod'
            ? ['title' => 'Cjenik proizvoda', 'plural' => 'proizvoda',
               'rule' => 'Propis: objavljuje se jednom dnevno, najkasnije do 8:00. Plugin ga objavljuje svaki dan u 6:30 i kod svake promjene.']
            : ['title' => 'Cjenik usluga', 'plural' => 'usluga',
               'rule' => 'Propis: objavljuje se kod svake promjene cijene, najkasnije do 8:00 na dan kad promjena stupa na snagu. Plugin ga objavljuje odmah kod spremanja, a zakazane promjene u 6:30.'];
    }

    private static function health_checks() {
        $out = [];
        $s = self::settings();
        if (trim($s['adresa']) === '') $out[] = ['error', 'Adresa objekta nije upisana. Propis traži adresu u nazivu datoteke.'];
        if (wp_timezone_string() !== 'Europe/Zagreb') {
            $out[] = ['warning', sprintf('Vremenska zona je "%s". Postavite Zagreb u <a href="%s">Settings → General</a>, inače se cjenik objavljuje u krivo vrijeme.',
                esc_html(wp_timezone_string()), esc_url(admin_url('options-general.php')))];
        }
        $products = self::items('proizvod');
        $last = (int) get_option('sc_zadnji_cron', 0);
        if ($products && $last && $last < time() - 26 * HOUR_IN_SECONDS) {
            $out[] = ['error', sprintf('Dnevna objava cjenika proizvoda zadnji put je pokrenuta %s. Provjerite serverski cron.', esc_html(wp_date('d.m.Y. \u H:i', $last)))];
        }
        if ($products && !defined('DISABLE_WP_CRON')) {
            $out[] = ['info', 'Dnevna objava ovisi o WP-Cronu, koji se pokreće samo kad netko posjeti stranicu. Za pouzdanu objavu prije 8:00 postavite serverski cron (upute u README).'];
        }
        $missing = 0;
        foreach (self::items() as $p) {
            $m = fn($k) => (string) get_post_meta($p->ID, 'sc_' . $k, true);
            $is_incomplete = $m('cijena') === '' || ($m('akcija_cijena') !== '' && $m('akcija_naziv') === '') || ($m('vrsta') === 'proizvod' && $m('marka') === '');
            if ($is_incomplete) $missing++;
        }
        if ($missing) {
            $out[] = ['warning', sprintf('%d %s bez obveznih podataka (cijena, naziv akcije ili marka). <a href="%s">Pregledaj stavke</a>',
                $missing, self::plural($missing, 'stavka je', 'stavke su', 'stavki je'), esc_url(admin_url('edit.php?post_type=' . self::CPT)))];
        }
        return $out;
    }

    public static function settings_page() {
        $s = self::settings();
        $f = self::files();
        $field = fn($k, $l, $h = '') => printf('<tr><th><label for="sc-%2$s">%1$s</label></th><td><input class="regular-text" id="sc-%2$s" name="%3$s[%2$s]" value="%4$s"><p class="description">%5$s</p></td></tr>',
            esc_html($l), esc_attr($k), self::OPT, esc_attr($s[$k]), esc_html($h));
        $preview = fn($type) => printf('<tr><th>Primjer naziva datoteke</th><td><code>%s.csv</code></td></tr>',
            esc_html(self::file_base($type, (int) get_option("sc_broj_$type", 0) + 1)));

        echo self::settings_css();
        echo '<div class="wrap sc-settings"><h1>Objava cjenika</h1>';
        if (isset($_GET['sc_ok'])) {
            $msg = $_GET['sc_ok'] === 'reset' ? 'Svi stari cjenici su obrisani i objavljen je novi.' : 'Cjenik je objavljen.';
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($msg) . '</p></div>';
        }
        foreach (self::health_checks() as [$type, $msg]) {
            printf('<div class="notice notice-%s inline"><p>%s</p></div>', esc_attr($type), wp_kses_post($msg));
        }

        echo '<div class="sc-cards">';
        foreach (self::TIPOVI as $type) {
            $info = self::type_info($type);
            $count = count(self::items($type));
            $current = $f['trenutni'][$type] ?? null;
            printf('<div class="sc-card"><h2>%s</h2>', esc_html($info['title']));
            if (!$count) {
                printf('<p class="sc-muted">Nema %s u ponudi, pa ovaj cjenik nije potreban.</p>', esc_html($info['plural']));
            } elseif ($current) {
                printf('<p><span class="sc-badge">Objavljen</span> %s · %d %s</p><p><code>%s</code></p><p><a class="button" href="%s" target="_blank">CSV</a> <a class="button" href="%s" target="_blank">XML</a></p>',
                    esc_html(wp_date('d.m.Y. \u H:i', strtotime($current['objavljeno']))), $count, self::plural($count, 'stavka', 'stavke', 'stavki'),
                    esc_html($current['naziv']), esc_url($current['csv']), esc_url($current['xml']));
            } else {
                echo '<p><span class="sc-badge is-warning">Nije objavljen</span> Kliknite "Objavi cjenike sada".</p>';
            }
            printf('<p class="description">%s</p></div>', esc_html($info['rule']));
        }
        echo '</div>';

        $next = wp_next_scheduled(self::CRON);
        printf('<form method="post" action="%s" class="sc-actions"><input type="hidden" name="action" value="sc_generiraj">%s', esc_url(admin_url('admin-post.php')), wp_nonce_field('sc_gen', '_wpnonce', true, false));
        submit_button('Objavi cjenike sada', 'primary', 'submit', false);
        if ($next) printf('<span class="sc-muted">Sljedeća automatska objava: %s</span>', esc_html(wp_date('d.m.Y. \u H:i', $next)));
        echo '</form>';

        echo '<form method="post" action="options.php">';
        settings_fields('sc_group');
        echo '<h2>Podaci o subjektu</h2><table class="form-table">';
        $field('subjekt', 'Naziv subjekta', 'Upisuje se u XML datoteku.');
        $field('adresa', 'Adresa objekta', 'Npr. Ilica 150 Zagreb. Ulazi u naziv datoteke.');
        echo '</table><h2>Cjenik usluga</h2><table class="form-table">';
        $field('usluga_oblik', 'Oblik uslužnog objekta', 'Npr. centar, ordinacija, salon, servis.');
        $field('usluga_oznaka', 'Oznaka uslužnog objekta', 'Npr. U-01. Ako nemate internu oznaku, upišite U-01 ili 01.');
        $preview('usluga');
        echo '</table><h2>Cjenik proizvoda</h2><table class="form-table">';
        $field('proizvod_oblik', 'Oblik prodajnog objekta', 'Za online prodaju (npr. digitalnih materijala) upišite webshop.');
        $field('proizvod_oznaka', 'Oznaka prodajnog objekta', 'Npr. W-01.');
        $preview('proizvod');
        echo '</table><h2>Headless frontend</h2><table class="form-table">';
        $field('revalidate_url', 'Revalidate URL (neobavezno)', 'Poziva se nakon svake objave cjenika, za osvježavanje cachea na frontendu.');
        echo '</table>';
        submit_button('Spremi postavke');
        echo '</form>';

        printf('<h2>Arhiva</h2><p class="description">Prethodni cjenici ostaju javno dostupni %d dana (propis traži najmanje 30).</p>', (int) self::ARHIVA_DANA);
        if (!$f['arhiva']) {
            echo '<p class="sc-muted">Arhiva je prazna.</p>';
        } else {
            echo '<table class="widefat striped sc-archive"><thead><tr><th>Cjenik</th><th>Objavljen</th><th>Datoteka</th><th></th></tr></thead><tbody>';
            foreach ($f['arhiva'] as $e) {
                printf('<tr><td>%s</td><td>%s</td><td><code>%s</code></td><td><a href="%s" target="_blank">CSV</a> · <a href="%s" target="_blank">XML</a></td></tr>',
                    esc_html(self::type_info($e['vrsta'])['title']), esc_html(wp_date('d.m.Y. H:i', strtotime($e['objavljeno']))),
                    esc_html($e['naziv']), esc_url($e['csv']), esc_url($e['xml']));
            }
            echo '</tbody></table>';
        }

        echo '<div class="sc-danger"><h2>Kreni ispočetka</h2><p>Briše sve objavljene cjenike i arhivu, vraća broj pohrane na 1 i objavljuje novi cjenik. Koristite samo prije puštanja stranice u rad, npr. za brisanje testnih cjenika.</p>';
        printf('<form method="post" action="%s"><input type="hidden" name="action" value="sc_reset">%s', esc_url(admin_url('admin-post.php')), wp_nonce_field('sc_reset', '_wpnonce', true, false));
        submit_button('Obriši sve cjenike i kreni ispočetka', 'delete', 'submit', false, ['onclick' => "return confirm('Obrisati sve cjenike i arhivu? Ovo se ne može poništiti.')"]);
        echo '</form></div></div>';
    }

    private static function settings_css() {
        return <<<'HTML'
<style>
.sc-settings .sc-cards { display: flex; flex-wrap: wrap; gap: 16px; margin: 16px 0; }
.sc-settings .sc-card { flex: 1 1 320px; max-width: 560px; background: #fff; border: 1px solid #dcdcde; border-radius: 4px; padding: 16px 20px; }
.sc-settings .sc-card h2 { margin-top: 0; }
.sc-settings .sc-card code { word-break: break-all; }
.sc-settings .sc-muted { color: #646970; }
.sc-settings .sc-badge { display: inline-block; padding: 2px 8px; margin-right: 6px; border-radius: 10px; background: #edfaef; color: #00450c; font-size: 12px; font-weight: 600; }
.sc-settings .sc-badge.is-warning { background: #fcf9e8; color: #614200; }
.sc-settings .sc-actions { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; margin-bottom: 24px; }
.sc-settings .sc-archive { max-width: 1100px; }
.sc-settings .sc-danger { margin-top: 32px; padding-top: 8px; border-top: 1px solid #dcdcde; }
</style>
HTML;
    }

    public static function manual_generate() {
        if (!current_user_can('manage_options') || !check_admin_referer('sc_gen')) wp_die('Nedozvoljeno');
        self::regenerate(self::TIPOVI, true);
        wp_safe_redirect(admin_url('edit.php?post_type=' . self::CPT . '&page=sc-postavke&sc_ok=1'));
        exit;
    }

    public static function manual_reset() {
        if (!current_user_can('manage_options') || !check_admin_referer('sc_reset')) wp_die('Nedozvoljeno');
        foreach (['csv', 'xml'] as $ext) {
            foreach (glob(self::dir() . "/*.$ext") ?: [] as $file) unlink($file);
        }
        delete_option(self::REG);
        foreach (self::TIPOVI as $type) {
            delete_option("sc_broj_$type");
            delete_option("sc_hash_$type");
        }
        self::regenerate(self::TIPOVI, true);
        wp_safe_redirect(admin_url('edit.php?post_type=' . self::CPT . '&page=sc-postavke&sc_ok=reset'));
        exit;
    }

    public static function columns($cols) {
        $cols['sc_vrsta'] = 'Vrsta';
        $cols['sc_cijena'] = 'Cijena';
        $cols['sc_sidrena'] = 'Dodatna (sidrena) cijena';
        return $cols;
    }

    public static function column($col, $id) {
        $m = fn($k) => (string) get_post_meta($id, 'sc_' . $k, true);
        if ($col === 'sc_vrsta') echo esc_html($m('vrsta') === 'proizvod' ? 'Proizvod' : 'Usluga');
        if ($col === 'sc_cijena') {
            if ($m('cijena') === '') echo '<span style="color:#d63638">nedostaje</span>';
            elseif ($m('akcija_cijena') !== '') printf('<strong>%s</strong> <s>%s</s><br><small>%s</small>', esc_html(self::eur($m('akcija_cijena'))), esc_html(self::eur($m('cijena'))), esc_html($m('akcija_naziv') ?: 'akcija bez naziva'));
            else echo esc_html(self::eur($m('cijena')));
            if ($m('nova_cijena_od') !== '') printf('<br><small>Od %s: %s</small>', esc_html(self::fmt_date($m('nova_cijena_od'))), esc_html(self::eur($m('nova_cijena'))));
        }
        if ($col === 'sc_sidrena') {
            $sid = $m('sidrena_cijena');
            echo $sid === '' ? '<span style="color:#d63638">nedostaje</span>'
                : esc_html(self::eur($sid) . ' (' . self::fmt_date($m('sidrena_datum')) . ')');
        }
    }

    public static function views($views) {
        $current = sanitize_key($_GET['sc_vrsta'] ?? '');
        if ($current && isset($views['all'])) $views['all'] = str_replace(['class="current"', 'aria-current="page"'], '', $views['all']);
        foreach (['usluga' => 'Usluge', 'proizvod' => 'Proizvodi'] as $type => $label) {
            $count = count(get_posts([
                'post_type' => self::CPT, 'post_status' => ['publish', 'draft', 'pending', 'private', 'future'],
                'numberposts' => -1, 'fields' => 'ids', 'meta_key' => 'sc_vrsta', 'meta_value' => $type,
            ]));
            $url = add_query_arg(['post_type' => self::CPT, 'sc_vrsta' => $type], admin_url('edit.php'));
            $views["sc_$type"] = sprintf('<a href="%s"%s>%s <span class="count">(%d)</span></a>',
                esc_url($url), $current === $type ? ' class="current" aria-current="page"' : '', esc_html($label), $count);
        }
        return $views;
    }

    public static function filter_list($query) {
        if (!is_admin() || !$query->is_main_query() || $query->get('post_type') !== self::CPT) return;
        $type = sanitize_key($_GET['sc_vrsta'] ?? '');
        if (in_array($type, self::TIPOVI, true)) $query->set('meta_query', [['key' => 'sc_vrsta', 'value' => $type]]);
    }

    public static function check_update($update, $plugin_data, $plugin_file) {
        if ($plugin_file !== plugin_basename(__FILE__)) return $update;
        return self::latest_release() ?: $update;
    }

    private static function latest_release() {
        $cached = get_transient('sc_release');
        if ($cached !== false && empty($_GET['force-check'])) return $cached;
        $res = wp_remote_get('https://api.github.com/repos/' . self::REPO . '/releases/latest', [
            'headers' => self::github_headers('application/vnd.github+json'),
        ]);
        $body = json_decode(wp_remote_retrieve_body($res), true);
        $release = [];
        foreach ($body['assets'] ?? [] as $asset) {
            if ($asset['name'] !== self::RELEASE_ZIP) continue;
            $release = [
                'slug'    => dirname(plugin_basename(__FILE__)),
                'version' => ltrim($body['tag_name'], 'v'),
                'url'     => $body['html_url'],
                'package' => self::github_token() ? $asset['url'] : $asset['browser_download_url'],
            ];
        }
        set_transient('sc_release', $release, 6 * HOUR_IN_SECONDS);
        return $release;
    }

    // The asset API redirects to a signed URL on another host, which must not receive the token.
    public static function download_private($reply, $package) {
        $prefix = 'https://api.github.com/repos/' . self::REPO . '/releases/assets/';
        if (!self::github_token() || strpos($package, $prefix) !== 0) return $reply;
        $res = wp_remote_get($package, ['redirection' => 0, 'headers' => self::github_headers('application/octet-stream')]);
        $location = wp_remote_retrieve_header($res, 'location');
        return $location ? download_url($location) : new WP_Error('sc_download', 'Preuzimanje nove verzije nije uspjelo.');
    }

    public static function keep_folder_name($source, $remote_source, $upgrader, $hook_extra) {
        $dir = dirname(plugin_basename(__FILE__));
        if (($hook_extra['plugin'] ?? '') !== plugin_basename(__FILE__) || $dir === '.' || basename($source) === $dir) return $source;
        $target = trailingslashit($remote_source) . $dir;
        return $GLOBALS['wp_filesystem']->move($source, $target) ? trailingslashit($target) : $source;
    }

    private static function github_headers($accept) {
        $headers = ['Accept' => $accept];
        if (self::github_token()) $headers['Authorization'] = 'Bearer ' . self::github_token();
        return $headers;
    }

    private static function github_token() {
        return defined('SC_GITHUB_TOKEN') ? SC_GITHUB_TOKEN : '';
    }

    private static function dir() {
        $d = wp_upload_dir()['basedir'] . '/' . self::DIR;
        if (!is_dir($d)) wp_mkdir_p($d);
        return $d;
    }

    private static function url() {
        return wp_upload_dir()['baseurl'] . '/' . self::DIR;
    }

    private static function clean_name($s) {
        return trim(preg_replace('/[\/\\\\?*"<>|]+/', '', $s));
    }

    private static function norm_price($v) {
        $v = str_replace([' ', '€', "\xC2\xA0"], '', $v);
        if (strpos($v, ',') !== false) $v = str_replace(['.', ','], ['', '.'], $v);
        return is_numeric($v) ? number_format((float) $v, 2, '.', '') : '';
    }

    private static function plural($n, $one, $few, $many) {
        if ($n % 10 === 1 && $n % 100 !== 11) return $one;
        if ($n % 10 >= 2 && $n % 10 <= 4 && ($n % 100 < 12 || $n % 100 > 14)) return $few;
        return $many;
    }

    // Stored as 45.00, shown in the form the way Croatian users type it: 45,00
    private static function price_input($v) {
        return is_numeric($v) ? number_format((float) $v, 2, ',', '') : (string) $v;
    }

    private static function num($v) {
        return $v === '' ? null : (float) $v;
    }

    private static function eur($v) {
        return $v === '' ? '' : number_format((float) $v, 2, ',', '.') . ' €';
    }

    private static function fmt_date($ymd) {
        $d = DateTime::createFromFormat('Y-m-d', (string) $ymd);
        return $d ? $d->format('d.m.Y') : '';
    }
}

Sidrena_Cjenik::init();
