<?php
/**
 * Plugin Name: Sidrena cijena i cjenik
 * Description: Unos usluga i proizvoda s dodatnom (sidrenom) cijenom, automatsko generiranje CSV/XML cjenika po lokacijama prema NN 101/2026 i REST + WPGraphQL API za headless frontend.
 * Version: 1.2.1
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
    const DB_VERZIJA   = 2;

    const TIPOVI = ['usluga', 'proizvod'];

    // Per-item meta holding the location ids the item is NOT offered at, and per-location overrides.
    const META_ISKLJUCENO = 'sc_iskljuceni_objekti';
    const META_LOKACIJE   = 'sc_lokacije';

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
    private static $objekt_fields = ['naziv', 'oblik', 'adresa', 'oznaka'];
    private static $is_migrating = false;

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
        add_action('add_option_' . self::OPT, [__CLASS__, 'on_settings_change']);
        add_action('update_option_' . self::OPT, [__CLASS__, 'on_settings_change']);
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
        add_action('admin_enqueue_scripts', [__CLASS__, 'list_assets']);
        add_action('wp_ajax_sc_reorder', [__CLASS__, 'ajax_reorder']);
        // Price lists are short; one page keeps drag-and-drop ordering simple.
        add_filter('edit_' . self::CPT . '_per_page', fn() => 500);
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
        self::schedule_cron();
        self::maybe_migrate();
    }

    // WP-Cron reschedules from the moment a late run happened, so pin the daily run back to 06:30.
    private static function schedule_cron() {
        $next = new DateTime('today 06:30', wp_timezone());
        if ($next->getTimestamp() <= time()) $next->modify('+1 day');
        $scheduled = wp_next_scheduled(self::CRON);
        if ($scheduled && wp_date('H:i', $scheduled) === '06:30') return;
        wp_clear_scheduled_hook(self::CRON);
        wp_schedule_event($next->getTimestamp(), 'daily', self::CRON);
    }

    // v1 kept one hardcoded location per type in flat settings keys; v2 keeps a list of locations.
    private static function maybe_migrate() {
        if ((int) get_option('sc_db_verzija', 1) >= self::DB_VERZIJA) return;
        $opt = get_option(self::OPT, false);
        $reg = get_option(self::REG, []);
        if (is_array($opt) && !isset($opt['objekti'])) {
            $opt['objekti'] = [];
            foreach (self::TIPOVI as $type) {
                $has_history = (bool) array_filter($reg, fn($e) => ($e['vrsta'] ?? '') === $type);
                if (!$has_history && !self::items($type)) continue;
                $id = "$type-1";
                $opt['objekti'][] = [
                    'id' => $id, 'vrsta' => $type, 'naziv' => '',
                    'oblik' => $opt["{$type}_oblik"] ?? ($type === 'proizvod' ? 'webshop' : 'centar'),
                    'adresa' => $opt['adresa'] ?? '',
                    'oznaka' => $opt["{$type}_oznaka"] ?? ($type === 'proizvod' ? 'W-01' : 'U-01'),
                ];
                $broj = get_option("sc_broj_$type", false);
                if ($broj !== false) update_option("sc_broj_$id", $broj, false);
                delete_option("sc_broj_$type");
                delete_option("sc_hash_$type");
                foreach ($reg as &$e) {
                    if (($e['vrsta'] ?? '') === $type && empty($e['objekt'])) $e['objekt'] = $id;
                }
                unset($e);
            }
            foreach (['adresa', 'usluga_oblik', 'usluga_oznaka', 'proizvod_oblik', 'proizvod_oznaka'] as $k) unset($opt[$k]);
            self::$is_migrating = true;
            update_option(self::OPT, $opt);
            update_option(self::REG, $reg, false);
            self::$is_migrating = false;
        }
        update_option('sc_db_verzija', self::DB_VERZIJA);
    }

    /* ---------- Locations ---------- */

    public static function objekti($type = null) {
        $list = apply_filters('sc_objekti', self::settings()['objekti']);
        return array_values(array_filter((array) $list, fn($o) => is_array($o) && !empty($o['id']) && (!$type || ($o['vrsta'] ?? '') === $type)));
    }

    private static function objekt($id) {
        foreach (self::objekti() as $o) if ($o['id'] === $id) return $o;
        return null;
    }

    private static function objekt_label(array $o) {
        return ($o['naziv'] ?? '') ?: (($o['adresa'] ?? '') ?: ($o['oznaka'] ?? $o['id']));
    }

    private static function excluded($post_id) {
        $v = get_post_meta($post_id, self::META_ISKLJUCENO, true);
        return is_array($v) ? $v : [];
    }

    private static function overrides($post_id) {
        $v = get_post_meta($post_id, self::META_LOKACIJE, true);
        return is_array($v) ? $v : [];
    }

    private static function is_offered_at($post_id, $objekt_id) {
        return !in_array($objekt_id, self::excluded($post_id), true);
    }

    // Base item values with the location's own price/sale/availability applied on top.
    private static function effective($post, $objekt_id) {
        $v = [];
        foreach (array_keys(self::$fields) as $k) $v[$k] = (string) get_post_meta($post->ID, 'sc_' . $k, true);
        $o = self::overrides($post->ID)[$objekt_id] ?? [];
        foreach (['cijena', 'sidrena_cijena', 'dostupnost'] as $k) {
            if (($o[$k] ?? '') !== '') $v[$k] = $o[$k];
        }
        if (($o['akcija'] ?? '') === 'ne') {
            foreach (self::$akcija_fields as $k) $v[$k] = '';
        } elseif (($o['akcija'] ?? '') === 'da') {
            foreach (self::$akcija_fields as $k) $v[$k] = (string) ($o[$k] ?? '');
        }
        if ($v['sidrena_cijena'] === '') $v['sidrena_cijena'] = $v['cijena'];
        return $v;
    }

    /* ---------- Item edit screen ---------- */

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
            'usluga'   => ['Usluga', 'Ide u <strong>cjenik usluga</strong> svake uslužne lokacije. Novi cjenik se objavljuje kod svake promjene cijene.'],
            'proizvod' => ['Proizvod', 'Fizička ili digitalna roba. Ide u <strong>cjenik proizvoda</strong> svake prodajne lokacije (npr. trgovine ili webshopa), koji se objavljuje svaki dan do 8:00.'],
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
        echo '<p class="description">Neobavezno. Na odabrani dan u 6:30 nova cijena postaje redovna i objavljuje se novi cjenik. Propis traži da cjenik s novom cijenom bude objavljen najkasnije do 8:00 na dan kad promjena stupa na snagu. Lokacije s vlastitom cijenom zadržavaju svoju cijenu.</p><div class="sc-row">';
        $price('nova_cijena', 'Nova cijena (€)');
        $field('nova_cijena_od', 'Vrijedi od', '', 'min="' . esc_attr(wp_date('Y-m-d')) . '"', 'date');
        echo '</div></fieldset>';

        self::render_locations($post);

        echo '<fieldset class="sc-section sc-only-proizvod"><legend>Podaci za cjenik proizvoda</legend><div class="sc-row">';
        $field('sifra', 'Šifra', 'Ako je prazno, koristi se SC-' . (int) $post->ID . '.');
        $field('marka', 'Marka <span class="sc-req">*</span>', 'Npr. proizvođač ili vlastita marka.');
        $field('barkod', 'Barkod', 'Ako postoji.');
        echo '</div><div class="sc-row">';
        $field('jedinica_mjere', 'Jedinica mjere', 'Ako je primjenjivo, npr. kom, kg, l.');
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

    private static function render_locations($post) {
        $overrides = self::overrides($post->ID);
        $excluded  = self::excluded($post->ID);
        $settings_url = admin_url('edit.php?post_type=' . self::CPT . '&page=sc-postavke');
        echo '<fieldset class="sc-section"><legend>Lokacije</legend>';
        foreach (self::TIPOVI as $type) {
            $list = self::objekti($type);
            $has_custom = (bool) array_filter($list, fn($o) => !empty($overrides[$o['id']]) || in_array($o['id'], $excluded, true));
            printf('<div data-for="%s">', esc_attr($type));
            if (!$list) {
                printf('<div class="notice notice-warning inline"><p>Nema nijedne lokacije za %s, pa se ova stavka zasad nigdje ne objavljuje. <a href="%s">Dodajte lokaciju</a></p></div>',
                    esc_html(mb_strtolower(self::type_info($type)['title'])), esc_url($settings_url));
            } elseif (count($list) === 1 && !$has_custom) {
                printf('<p class="description">Objavljuje se u cjeniku lokacije <strong>%s</strong>. Kad dodate više lokacija, ovdje možete odabrati gdje se stavka nudi.</p>', esc_html(self::objekt_label($list[0])));
            } else {
                echo '<p class="description">Označite gdje se stavka nudi. Cijena, akcija i raspoloživost iste su kao gore, osim ako za lokaciju upišete drukčije. Nove lokacije automatski su uključene.</p>';
                foreach ($list as $o) {
                    self::render_location($o, $overrides[$o['id']] ?? [], !in_array($o['id'], $excluded, true));
                }
            }
            echo '</div>';
        }
        echo '</fieldset>';
    }

    private static function render_location(array $o, array $ov, $is_on) {
        $id   = $o['id'];
        $name = 'sc_obj[' . $id . ']';
        $input = function ($k, $label, $is_price = true, $placeholder = '') use ($id, $name, $ov) {
            printf('<div class="sc-field"><label for="sc_obj_%1$s_%2$s">%3$s</label><input type="text" id="sc_obj_%1$s_%2$s" name="%4$s[%2$s]" value="%5$s"%6$s></div>',
                esc_attr($id), esc_attr($k), esc_html($label), esc_attr($name),
                esc_attr($is_price ? self::price_input($ov[$k] ?? '') : ($ov[$k] ?? '')),
                $is_price ? ' inputmode="decimal" data-inherit="' . esc_attr($placeholder) . '"' : '');
        };
        $select = function ($k, $label, array $options) use ($id, $name, $ov) {
            printf('<div class="sc-field"><label for="sc_obj_%1$s_%2$s">%3$s</label><select id="sc_obj_%1$s_%2$s" name="%4$s[%2$s]" class="sc-location-%2$s">', esc_attr($id), esc_attr($k), esc_html($label), esc_attr($name));
            foreach ($options as $val => $l) printf('<option value="%s"%s>%s</option>', esc_attr($val), selected($ov[$k] ?? '', $val, false), esc_html($l));
            echo '</select></div>';
        };
        $details = array_filter([$o['oblik'] ?? '', $o['adresa'] ?? '', $o['oznaka'] ?? '']);

        printf('<div class="sc-location%s" data-akcija="%s">', $is_on ? '' : ' is-off', esc_attr($ov['akcija'] ?? ''));
        printf('<input type="hidden" name="%s[shown]" value="1">', esc_attr($name));
        printf('<label class="sc-location-on"><input type="checkbox" name="%s[on]" value="1"%s> <strong>%s</strong> <span class="sc-muted">%s</span></label>',
            esc_attr($name), checked($is_on, true, false), esc_html(self::objekt_label($o)), esc_html(implode(' · ', $details)));
        printf('<details%s><summary>Drukčija cijena, akcija ili raspoloživost na ovoj lokaciji</summary><div class="sc-row">', $ov ? ' open' : '');
        $input('cijena', 'Cijena (€)', true, 'cijena');
        $input('sidrena_cijena', 'Dodatna cijena (€)', true, 'sidrena_cijena');
        $select('akcija', 'Akcija', ['' => 'Kao osnovno', 'ne' => 'Bez akcije', 'da' => 'Vlastita akcija']);
        if ($o['vrsta'] === 'proizvod') $select('dostupnost', 'Raspoloživost', ['' => 'Kao osnovno', 'dostupno' => 'Dostupno', 'nedostupno' => 'Nedostupno']);
        echo '</div><div class="sc-row sc-location-own-akcija">';
        $input('akcija_cijena', 'Akcijska cijena (€)');
        $input('akcija_naziv', 'Naziv akcije', false);
        $input('akcija_najniza30', 'Najniža cijena u 30 dana (€)');
        echo '</div></details></div>';
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
    var num = function (n) { return n.toLocaleString('hr-HR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); };
    var eur = function (n) { return n === null ? '–' : num(n) + ' €'; };
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
        byName('sc_sidrena_cijena').placeholder = regular === null ? '0,00' : num(regular) + ' (ista kao redovna)';

        var inherited = { cijena: regular, sidrena_cijena: anchor };
        box.querySelectorAll('[data-inherit]').forEach(function (input) {
            var base = inherited[input.dataset.inherit];
            input.placeholder = input.dataset.inherit && base !== null && base !== undefined ? 'kao osnovno: ' + num(base) : '0,00';
        });
        box.querySelectorAll('.sc-location').forEach(function (loc) {
            loc.classList.toggle('is-off', !loc.querySelector('.sc-location-on input').checked);
            loc.dataset.akcija = loc.querySelector('.sc-location-akcija').value;
        });
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
.sc-box .sc-muted { color: var(--sc-muted); }
.sc-box .sc-choices { display: flex; flex-wrap: wrap; gap: 12px; }
.sc-box .sc-choice { display: flex; gap: 8px; align-items: flex-start; flex: 1 1 260px; max-width: 420px; padding: 12px; border: 1px solid var(--sc-border); border-radius: 4px; cursor: pointer; }
.sc-box .sc-choice:has(input:checked) { border-color: #2271b1; box-shadow: 0 0 0 1px #2271b1; }
.sc-box .sc-choice span { display: flex; flex-direction: column; gap: 4px; }
.sc-box .sc-choice small { color: var(--sc-muted); font-size: 12px; }
.sc-box .sc-inline { display: flex; flex-wrap: wrap; gap: 16px; margin-bottom: 8px; }
.sc-box .sc-toggle { display: inline-block; margin-bottom: 12px; }
.sc-box .sc-location { border: 1px solid var(--sc-border); border-radius: 4px; padding: 10px 12px; margin-bottom: 8px; max-width: 1000px; }
.sc-box .sc-location summary { cursor: pointer; color: #2271b1; margin-top: 6px; }
.sc-box .sc-location details[open] summary { margin-bottom: 12px; }
.sc-box .sc-location.is-off details { display: none; }
.sc-box .sc-location.is-off .sc-location-on strong { color: var(--sc-muted); text-decoration: line-through; }
.sc-box .sc-location:not([data-akcija="da"]) .sc-location-own-akcija { display: none; }
.sc-box .sc-preview { background: #f6f7f7; border-left: 4px solid #2271b1; padding: 12px 16px; margin-top: 8px; }
.sc-box .sc-preview-text { margin-top: 6px; line-height: 1.7; }
.sc-box:not([data-vrsta="proizvod"]) .sc-only-proizvod,
.sc-box:not([data-uvedeno="nakon"]) .sc-only-nakon,
.sc-box[data-uvedeno="nakon"] .sc-only-prije,
.sc-box:not([data-akcija="1"]) .sc-only-akcija,
.sc-box[data-vrsta="usluga"] [data-for="proizvod"],
.sc-box[data-vrsta="proizvod"] [data-for="usluga"] { display: none; }
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
        self::save_locations($post_id, (array) ($_POST['sc_obj'] ?? []));
        self::add_notices(array_merge($notices, self::validate($post_id)));
    }

    private static function save_locations($post_id, array $posted) {
        $known     = array_column(self::objekti(), 'id');
        $excluded  = array_values(array_intersect(self::excluded($post_id), $known));
        $overrides = array_intersect_key(self::overrides($post_id), array_flip($known));

        foreach ($known as $id) {
            $row = $posted[$id] ?? null;
            // Locations not rendered in the form (e.g. only one exists) keep their stored values.
            if (!is_array($row) || empty($row['shown'])) continue;
            $row = wp_unslash($row);

            $excluded = array_values(array_diff($excluded, [$id]));
            if (empty($row['on'])) $excluded[] = $id;

            $ov = [];
            foreach (['cijena', 'sidrena_cijena', 'akcija_cijena', 'akcija_najniza30'] as $k) $ov[$k] = self::norm_price(sanitize_text_field($row[$k] ?? ''));
            $ov['akcija_naziv'] = sanitize_text_field($row['akcija_naziv'] ?? '');
            $ov['akcija']       = in_array($row['akcija'] ?? '', ['ne', 'da'], true) ? $row['akcija'] : '';
            $ov['dostupnost']   = in_array($row['dostupnost'] ?? '', ['dostupno', 'nedostupno'], true) ? $row['dostupnost'] : '';
            if ($ov['akcija'] !== 'da') {
                foreach (self::$akcija_fields as $k) $ov[$k] = '';
            }
            if ($ov['cijena'] !== '' && $ov['sidrena_cijena'] === '') $ov['sidrena_cijena'] = $ov['cijena'];
            $ov = array_filter($ov, fn($val) => $val !== '');
            if ($ov) $overrides[$id] = $ov;
            else unset($overrides[$id]);
        }
        update_post_meta($post_id, self::META_ISKLJUCENO, $excluded);
        update_post_meta($post_id, self::META_LOKACIJE, wp_slash($overrides));
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

        $locations = self::objekti($m('vrsta'));
        $overrides = self::overrides($post_id);
        foreach ($locations as $o) {
            $ov = $overrides[$o['id']] ?? [];
            $label = self::objekt_label($o);
            if (($ov['akcija'] ?? '') === 'da' && (($ov['akcija_cijena'] ?? '') === '' || ($ov['akcija_naziv'] ?? '') === '')) {
                $out[] = ['error', "Lokacija \"$label\": za vlastitu akciju upišite akcijsku cijenu i naziv akcije."];
            }
        }
        if ($locations && !array_filter($locations, fn($o) => self::is_offered_at($post_id, $o['id']))) {
            $out[] = ['warning', 'Stavka nije označena ni na jednoj lokaciji, pa se neće pojaviti ni u jednom cjeniku.'];
        }
        $custom = array_filter($locations, fn($o) => ($overrides[$o['id']]['cijena'] ?? '') !== '');
        if ($m('nova_cijena') !== '' && $custom) {
            $out[] = ['info', 'Zakazana promjena mijenja osnovnu cijenu. Lokacije s vlastitom cijenom (' . implode(', ', array_map([__CLASS__, 'objekt_label'], $custom)) . ') zadržavaju svoju.'];
        }
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

    /* ---------- Publishing ---------- */

    public static function on_change($post_id, $post) {
        if ((defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) || wp_is_post_revision($post_id)) return;
        if ($post->post_status === 'auto-draft') return;
        self::regenerate(self::TIPOVI);
    }

    public static function on_delete($post_id) {
        if (get_post_type($post_id) === self::CPT) self::regenerate(self::TIPOVI);
    }

    // Location name/address changes the file name, so publish right away.
    public static function on_settings_change() {
        if (!self::$is_migrating) self::regenerate(self::TIPOVI);
    }

    // Runs every day: products must be republished daily, services whenever a scheduled change kicks in.
    public static function cron() {
        self::apply_scheduled();
        self::regenerate(self::TIPOVI, ['proizvod']);
        update_option('sc_zadnji_cron', time(), false);
        self::schedule_cron();
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

    /**
     * @param string[]      $types types whose locations are checked
     * @param bool|string[] $force true for all, or types / location ids to republish even when unchanged
     */
    public static function regenerate(array $types, $force = false) {
        $changed = [];
        $subjekt = self::settings()['subjekt'];
        foreach (self::objekti() as $o) {
            if (!in_array($o['vrsta'], $types, true)) continue;
            $rows = self::rows($o);
            $has_history = get_option("sc_hash_{$o['id']}", false) !== false;
            // Nothing to publish and nothing published before: this location has no price list yet.
            if (!$rows && !$has_history) continue;
            $hash = md5(wp_json_encode([$subjekt, $o, $rows]));
            $is_forced = $force === true || array_intersect([$o['vrsta'], $o['id']], (array) $force);
            if (!$is_forced && get_option("sc_hash_{$o['id']}") === $hash) continue;
            self::write($o, $rows);
            update_option("sc_hash_{$o['id']}", $hash, false);
            $changed[] = $o;
        }
        if ($changed) {
            self::cleanup();
            $changed_types = array_values(array_unique(array_column($changed, 'vrsta')));
            $changed_ids   = array_column($changed, 'id');
            do_action('sc_cjenik_azuriran', $changed_types, $changed_ids);
            self::revalidate_frontend($changed_types, $changed_ids);
        }
        return array_column($changed, 'id');
    }

    private static function revalidate_frontend(array $types, array $objekt_ids) {
        $url = self::settings()['revalidate_url'];
        if ($url) wp_remote_post($url, ['blocking' => false, 'timeout' => 3, 'body' => ['tipovi' => $types, 'objekti' => $objekt_ids]]);
    }

    private static function items($type = null) {
        $args = [
            'post_type' => self::CPT, 'post_status' => 'publish', 'numberposts' => -1,
            'orderby' => ['menu_order' => 'ASC', 'title' => 'ASC'],
        ];
        if ($type) $args['meta_query'] = [['key' => 'sc_vrsta', 'value' => $type]];
        return get_posts($args);
    }

    private static function csv_columns($type) {
        return $type === 'proizvod'
            ? ['naziv', 'sifra', 'marka', 'jedinica_mjere', 'cijena_za_jedinicu_mjere', 'maloprodajna_cijena', 'posebni_oblik_prodaje',
               'naziv_posebnog_oblika_prodaje', 'sidrena_cijena', 'datum_sidrene_cijene', 'barkod', 'raspolozivost']
            : ['naziv', 'maloprodajna_cijena', 'posebni_oblik_prodaje', 'naziv_posebnog_oblika_prodaje', 'sidrena_cijena', 'datum_sidrene_cijene'];
    }

    private static function rows(array $objekt) {
        $type = $objekt['vrsta'];
        $rows = [];
        foreach (self::items($type) as $p) {
            if (!self::is_offered_at($p->ID, $objekt['id'])) continue;
            $v = self::effective($p, $objekt['id']);
            $akcija = $v['akcija_cijena'] !== '';
            $row = [
                'naziv'                         => $p->post_title,
                'sifra'                         => $v['sifra'] ?: 'SC-' . $p->ID,
                'marka'                         => $v['marka'],
                'jedinica_mjere'                => $v['jedinica_mjere'],
                'cijena_za_jedinicu_mjere'      => $v['cijena_jm'],
                'maloprodajna_cijena'           => $akcija ? $v['akcija_cijena'] : $v['cijena'],
                'posebni_oblik_prodaje'         => $akcija ? 'DA' : 'NE',
                'naziv_posebnog_oblika_prodaje' => $akcija ? $v['akcija_naziv'] : '',
                'sidrena_cijena'                => $v['sidrena_cijena'],
                'datum_sidrene_cijene'          => self::fmt_date($v['sidrena_datum'] ?: self::DEFAULT_DATUM),
                'barkod'                        => $v['barkod'],
                'raspolozivost'                 => $v['dostupnost'] ?: 'dostupno',
            ];
            $row = array_merge(array_flip(self::csv_columns($type)), array_intersect_key($row, array_flip(self::csv_columns($type))));
            $rows[] = apply_filters('sc_cjenik_redak', $row, $p, $objekt);
        }
        return $rows;
    }

    private static function write(array $objekt, array $rows) {
        $id = $objekt['id'];
        $n = (int) get_option("sc_broj_$id", 0) + 1;
        update_option("sc_broj_$id", $n, false);

        $base = self::file_base($objekt, $n);
        $dir = self::dir();
        $header = $rows ? array_keys($rows[0]) : self::csv_columns($objekt['vrsta']);

        // UTF-8 BOM so Excel detects the encoding
        $fh = fopen("$dir/$base.csv", 'w');
        fwrite($fh, "\xEF\xBB\xBF");
        fputcsv($fh, $header, self::CSV_SEP);
        foreach ($rows as $r) fputcsv($fh, array_values($r), self::CSV_SEP);
        fclose($fh);

        $doc = new DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = true;
        $root = $doc->createElement('cjenik');
        foreach ([
            'subjekt' => self::settings()['subjekt'], 'vrsta' => $objekt['vrsta'], 'oblik_objekta' => $objekt['oblik'],
            'adresa' => $objekt['adresa'], 'oznaka_objekta' => $objekt['oznaka'], 'broj_pohrane' => $n, 'vrijeme' => wp_date('c'),
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
        $reg[] = [
            'objekt' => $id, 'vrsta' => $objekt['vrsta'], 'naziv' => $base, 'vrijeme' => time(),
            'lokacija' => self::objekt_label($objekt), 'adresa' => $objekt['adresa'], 'oznaka' => $objekt['oznaka'],
        ];
        update_option(self::REG, $reg, false);
    }

    private static function file_base(array $objekt, $n) {
        return self::clean_name(implode('_', [
            $objekt['oblik'], $objekt['adresa'], $objekt['oznaka'], $n, wp_date('d.m.Y_H:i'),
        ]));
    }

    // Latest file per existing location; files of removed locations become archive.
    private static function current_names(array $reg) {
        $known = array_column(self::objekti(), 'id');
        $current = [];
        foreach ($reg as $e) {
            $id = $e['objekt'] ?? '';
            if (in_array($id, $known, true)) $current[$id] = $e['naziv'];
        }
        return $current;
    }

    private static function cleanup() {
        $reg = get_option(self::REG, []);
        $limit = time() - self::ARHIVA_DANA * DAY_IN_SECONDS;
        $current = self::current_names($reg);
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
        $reg = get_option(self::REG, []);
        $current = self::current_names($reg);
        $url = self::url();
        $out = ['trenutni' => [], 'arhiva' => []];
        foreach (array_reverse($reg) as $e) {
            if (!file_exists(self::dir() . "/{$e['naziv']}.csv")) continue;
            $id = $e['objekt'] ?? '';
            $o = self::objekt($id);
            $item = [
                'objekt' => $id, 'vrsta' => $e['vrsta'],
                'lokacija' => $o ? self::objekt_label($o) : ($e['lokacija'] ?? ''),
                'adresa' => $e['adresa'] ?? ($o['adresa'] ?? ''), 'oznaka' => $e['oznaka'] ?? ($o['oznaka'] ?? ''),
                'naziv' => $e['naziv'], 'objavljeno' => wp_date('c', $e['vrijeme']),
                'csv' => $url . '/' . rawurlencode($e['naziv'] . '.csv'),
                'xml' => $url . '/' . rawurlencode($e['naziv'] . '.xml'),
            ];
            if (($current[$id] ?? null) === $e['naziv']) $out['trenutni'][$id] = $item;
            else $out['arhiva'][] = $item;
        }
        return $out;
    }

    /* ---------- API ---------- */

    public static function rest() {
        register_rest_route('cjenik/v1', '/stavke', [
            'methods' => 'GET', 'permission_callback' => '__return_true',
            'callback' => fn() => rest_ensure_response(self::data()),
        ]);
        register_rest_route('cjenik/v1', '/datoteke', [
            'methods' => 'GET', 'permission_callback' => '__return_true',
            'callback' => fn() => rest_ensure_response(self::files()),
        ]);
        register_rest_route('cjenik/v1', '/objekti', [
            'methods' => 'GET', 'permission_callback' => '__return_true',
            'callback' => fn() => rest_ensure_response(self::objekti()),
        ]);
    }

    private static function price_data(array $v) {
        return [
            'cijena'         => self::num($v['cijena']),
            'sidrena_cijena' => self::num($v['sidrena_cijena'] ?: $v['cijena']),
            'akcija'         => $v['akcija_cijena'] !== '' ? [
                'cijena' => self::num($v['akcija_cijena']),
                'naziv' => $v['akcija_naziv'],
                'najniza_30_dana' => self::num($v['akcija_najniza30']),
            ] : null,
            'dostupnost'     => $v['vrsta'] === 'proizvod' ? ($v['dostupnost'] ?: 'dostupno') : null,
        ];
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
            $base = [];
            foreach (array_keys(self::$fields) as $k) $base[$k] = $m($k);
            $base['vrsta'] = $base['vrsta'] ?: 'usluga';
            $lokacije = [];
            foreach (self::objekti($base['vrsta']) as $o) {
                if (!self::is_offered_at($p->ID, $o['id'])) continue;
                $lokacije[] = ['objekt' => $o['id']] + self::price_data(self::effective($p, $o['id']));
            }
            $groups[$key]['stavke'][] = [
                'id'             => $p->ID,
                'naziv'          => $p->post_title,
                'vrsta'          => $base['vrsta'],
                'sidrena_datum'  => $base['sidrena_datum'] ?: self::DEFAULT_DATUM,
                'jedinica_mjere' => $base['jedinica_mjere'] ?: null,
                'lokacije'       => $lokacije,
            ] + self::price_data($base);
        }
        $f = self::files();
        return [
            'kategorije' => array_values($groups), 'objekti' => self::objekti(),
            'datoteke' => array_values($f['trenutni']), 'arhiva' => $f['arhiva'],
        ];
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

        register_graphql_object_type('CjenikObjekt', ['description' => 'Lokacija (prodajni ili uslužni objekt) s vlastitim cjenikom', 'fields' => $map([
            'id'     => ['String', 'id', 'Stalni ID lokacije'],
            'vrsta'  => ['String', 'vrsta', 'usluga | proizvod'],
            'naziv'  => ['String', 'naziv', 'Naziv lokacije'],
            'oblik'  => ['String', 'oblik', 'Oblik objekta, npr. kabinet ili webshop'],
            'adresa' => ['String', 'adresa', 'Adresa objekta'],
            'oznaka' => ['String', 'oznaka', 'Oznaka objekta, npr. U-01'],
        ])]);

        register_graphql_object_type('CjenikStavkaLokacija', ['description' => 'Cijena stavke na jednoj lokaciji', 'fields' => $map([
            'objekt'        => ['String', 'objekt', 'ID lokacije'],
            'cijena'        => ['Float', 'cijena', 'Redovna cijena na lokaciji'],
            'sidrenaCijena' => ['Float', 'sidrena_cijena', 'Dodatna (sidrena) cijena na lokaciji'],
            'akcija'        => ['CjenikAkcija', 'akcija', 'Akcija na lokaciji ili null'],
            'dostupnost'    => ['String', 'dostupnost', 'dostupno | nedostupno (samo proizvodi)'],
        ])]);

        register_graphql_object_type('CjenikStavka', ['description' => 'Stavka cjenika', 'fields' => $map([
            'databaseId'    => ['Int', 'id', 'ID stavke'],
            'naziv'         => ['String', 'naziv', 'Naziv usluge/proizvoda'],
            'vrsta'         => ['String', 'vrsta', 'usluga | proizvod'],
            'cijena'        => ['Float', 'cijena', 'Osnovna redovna cijena'],
            'sidrenaCijena' => ['Float', 'sidrena_cijena', 'Osnovna dodatna (sidrena) cijena'],
            'sidrenaDatum'  => ['String', 'sidrena_datum', 'Datum sidrene cijene (YYYY-MM-DD)'],
            'akcija'        => ['CjenikAkcija', 'akcija', 'Osnovna akcija ili null'],
            'jedinicaMjere' => ['String', 'jedinica_mjere', 'Jedinica mjere'],
            'dostupnost'    => ['String', 'dostupnost', 'dostupno | nedostupno (samo proizvodi)'],
            'lokacije'      => [['list_of' => 'CjenikStavkaLokacija'], 'lokacije', 'Lokacije na kojima se stavka nudi, s cijenama'],
        ])]);

        register_graphql_object_type('CjenikKategorija', ['description' => 'Kategorija cjenika', 'fields' => $map([
            'naziv'  => ['String', 'kategorija', 'Naziv kategorije'],
            'slug'   => ['String', 'slug', 'Slug'],
            'stavke' => [['list_of' => 'CjenikStavka'], 'stavke', 'Stavke u kategoriji'],
        ])]);

        register_graphql_object_type('CjenikDatoteka', ['description' => 'Generirana CSV/XML datoteka', 'fields' => $map([
            'objekt'     => ['String', 'objekt', 'ID lokacije'],
            'vrsta'      => ['String', 'vrsta', 'usluga | proizvod'],
            'lokacija'   => ['String', 'lokacija', 'Naziv lokacije'],
            'adresa'     => ['String', 'adresa', 'Adresa lokacije'],
            'oznaka'     => ['String', 'oznaka', 'Oznaka lokacije'],
            'naziv'      => ['String', 'naziv', 'Naziv datoteke bez ekstenzije'],
            'objavljeno' => ['String', 'objavljeno', 'Vrijeme objave (ISO 8601)'],
            'csv'        => ['String', 'csv', 'URL CSV datoteke'],
            'xml'        => ['String', 'xml', 'URL XML datoteke'],
        ])]);

        register_graphql_object_type('Cjenik', ['description' => 'Cjenik sa sidrenim cijenama', 'fields' => $map([
            'kategorije' => [['list_of' => 'CjenikKategorija'], 'kategorije', 'Kategorije sa stavkama'],
            'objekti'    => [['list_of' => 'CjenikObjekt'], 'objekti', 'Lokacije'],
            'datoteke'   => [['list_of' => 'CjenikDatoteka'], 'datoteke', 'Trenutno važeće datoteke, jedna po lokaciji'],
            'arhiva'     => [['list_of' => 'CjenikDatoteka'], 'arhiva', 'Arhiva prethodnih datoteka'],
        ])]);

        register_graphql_field('RootQuery', 'cjenik', [
            'type' => 'Cjenik',
            'description' => 'Javni cjenik usluga i proizvoda sa sidrenim cijenama',
            'resolve' => fn() => self::data(),
        ]);
    }

    /* ---------- Settings ---------- */

    public static function settings() {
        $s = wp_parse_args(get_option(self::OPT, []), [
            'subjekt' => get_bloginfo('name'), 'objekti' => [], 'revalidate_url' => '',
        ]);
        $s['objekti'] = is_array($s['objekti']) ? $s['objekti'] : [];
        return $s;
    }

    public static function sanitize_settings($in) {
        $in = (array) $in;
        $out = [
            'subjekt' => sanitize_text_field($in['subjekt'] ?? ''),
            'objekti' => [],
            'revalidate_url' => esc_url_raw($in['revalidate_url'] ?? ''),
        ];
        $ids = $names = [];
        foreach ((array) ($in['objekti'] ?? []) as $row) {
            if (!is_array($row)) continue;
            $o = [];
            foreach (self::$objekt_fields as $k) $o[$k] = sanitize_text_field($row[$k] ?? '');
            if (!array_filter($o)) continue;
            $id = sanitize_key($row['id'] ?? '');
            if ($id === '' || isset($ids[$id])) $id = 'o' . substr(md5(uniqid('', true)), 0, 8);
            $ids[$id] = true;
            $o = ['id' => $id, 'vrsta' => in_array($row['vrsta'] ?? '', self::TIPOVI, true) ? $row['vrsta'] : 'usluga'] + $o;
            $name = mb_strtolower(implode('_', [$o['oblik'], $o['adresa'], $o['oznaka']]));
            if (isset($names[$name]) && function_exists('add_settings_error')) {
                add_settings_error(self::OPT, 'sc_dup', sprintf('Lokacije "%s" i "%s" imaju isti oblik, adresu i oznaku, pa bi im datoteke imale isti naziv. Promijenite oznaku jedne od njih.', $names[$name], self::objekt_label($o)));
            }
            $names[$name] = self::objekt_label($o);
            $out['objekti'][] = $o;
        }
        return $out;
    }

    public static function admin_menu() {
        add_submenu_page('edit.php?post_type=' . self::CPT, 'Objava cjenika', 'Objava i postavke', 'manage_options', 'sc-postavke', [__CLASS__, 'settings_page']);
    }

    private static function type_info($type) {
        return $type === 'proizvod'
            ? ['title' => 'Cjenik proizvoda', 'plural' => 'proizvoda', 'option' => 'Proizvodi (prodajni objekt)',
               'rule' => 'Propis: objavljuje se jednom dnevno, najkasnije do 8:00, zasebno za svaku lokaciju i webshop. Plugin ga objavljuje svaki dan u 6:30 i kod svake promjene.']
            : ['title' => 'Cjenik usluga', 'plural' => 'usluga', 'option' => 'Usluge (uslužni objekt)',
               'rule' => 'Propis: objavljuje se kod svake promjene cijene, najkasnije do 8:00 na dan kad promjena stupa na snagu, zasebno za svaku lokaciju. Plugin ga objavljuje odmah kod spremanja, a zakazane promjene u 6:30.'];
    }

    private static function health_checks() {
        $out = [];
        $objekti = self::objekti();
        if (!$objekti) $out[] = ['error', 'Nema nijedne lokacije. Dodajte barem jednu u odjeljku "Lokacije" niže, inače se cjenik ne objavljuje.'];
        foreach (self::TIPOVI as $type) {
            $count = count(self::items($type));
            if ($count && !self::objekti($type)) {
                $out[] = ['error', sprintf('Imate %d %s, ali nijednu lokaciju za %s. Dodajte lokaciju i u stupcu "Cjenik" odaberite "%s".',
                    $count, $type === 'proizvod' ? self::plural($count, 'proizvod', 'proizvoda', 'proizvoda') : self::plural($count, 'uslugu', 'usluge', 'usluga'),
                    mb_strtolower(self::type_info($type)['title']), self::type_info($type)['option'])];
            }
        }
        $names = [];
        foreach ($objekti as $o) {
            $label = self::objekt_label($o);
            if (trim($o['adresa']) === '') $out[] = ['error', sprintf('Lokacija "%s" nema adresu. Propis traži adresu u nazivu datoteke.', esc_html($label))];
            if (trim($o['oblik']) === '' || trim($o['oznaka']) === '') $out[] = ['warning', sprintf('Lokaciji "%s" nedostaje oblik ili oznaka objekta.', esc_html($label))];
            $name = mb_strtolower(implode('_', [$o['oblik'], $o['adresa'], $o['oznaka']]));
            if (isset($names[$name])) $out[] = ['error', sprintf('Lokacije "%s" i "%s" imaju isti naziv datoteke. Promijenite oznaku jedne od njih.', esc_html($names[$name]), esc_html($label))];
            $names[$name] = $label;
        }
        if (wp_timezone_string() !== 'Europe/Zagreb') {
            $out[] = ['warning', sprintf('Vremenska zona je "%s". Postavite Zagreb u <a href="%s">Settings → General</a>, inače se cjenik objavljuje u krivo vrijeme.',
                esc_html(wp_timezone_string()), esc_url(admin_url('options-general.php')))];
        }
        $has_products = self::items('proizvod') && self::objekti('proizvod');
        $last = (int) get_option('sc_zadnji_cron', 0);
        if ($has_products && $last && $last < time() - 26 * HOUR_IN_SECONDS) {
            $out[] = ['error', sprintf('Dnevna objava cjenika proizvoda zadnji put je pokrenuta %s. Provjerite serverski cron.', esc_html(wp_date('d.m.Y. \u H:i', $last)))];
        }
        if ($has_products && !defined('DISABLE_WP_CRON')) {
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

    private static function objekt_row($i, array $o) {
        $name = self::OPT . "[objekti][$i]";
        $input = fn($k, $placeholder) => printf('<td><input type="text" name="%s[%s]" value="%s" placeholder="%s" aria-label="%s"></td>',
            esc_attr($name), esc_attr($k), esc_attr($o[$k] ?? ''), esc_attr($placeholder), esc_attr(ucfirst($k)));
        printf('<tr%s><td><input type="hidden" name="%s[id]" value="%s"><select name="%s[vrsta]" aria-label="Vrsta cjenika">',
            !empty($o['id']) ? ' data-saved="1"' : '', esc_attr($name), esc_attr($o['id'] ?? ''), esc_attr($name));
        foreach (self::TIPOVI as $k) {
            printf('<option value="%s"%s>%s</option>', esc_attr($k), selected($o['vrsta'] ?? 'usluga', $k, false), esc_html(self::type_info($k)['option']));
        }
        echo '</select></td>';
        $input('naziv', 'npr. Kabinet Zagreb');
        $input('oblik', 'npr. kabinet, prodavaonica, webshop');
        $input('adresa', 'npr. Ilica 150 Zagreb');
        $input('oznaka', 'npr. U-01');
        echo '<td><button type="button" class="button-link sc-remove-objekt" aria-label="Ukloni lokaciju"><span class="dashicons dashicons-trash"></span></button></td></tr>';
    }

    public static function settings_page() {
        $s = self::settings();
        $f = self::files();
        $field = fn($k, $l, $h = '') => printf('<tr><th><label for="sc-%2$s">%1$s</label></th><td><input class="regular-text" id="sc-%2$s" name="%3$s[%2$s]" value="%4$s"><p class="description">%5$s</p></td></tr>',
            esc_html($l), esc_attr($k), self::OPT, esc_attr($s[$k]), esc_html($h));

        echo self::settings_css();
        echo '<div class="wrap sc-settings"><h1>Objava cjenika</h1>';
        settings_errors(self::OPT);
        if (isset($_GET['sc_ok'])) {
            $msg = $_GET['sc_ok'] === 'reset' ? 'Svi stari cjenici su obrisani i objavljeni su novi.' : 'Cjenici su objavljeni.';
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($msg) . '</p></div>';
        }
        foreach (self::health_checks() as [$type, $msg]) {
            printf('<div class="notice notice-%s inline"><p>%s</p></div>', esc_attr($type), wp_kses_post($msg));
        }

        foreach (self::TIPOVI as $type) {
            $list = self::objekti($type);
            if (!$list) continue;
            $info = self::type_info($type);
            printf('<h2>%s</h2><p class="description">%s</p><div class="sc-cards">', esc_html($info['title']), esc_html($info['rule']));
            foreach ($list as $o) {
                $count = count(self::rows($o));
                $current = $f['trenutni'][$o['id']] ?? null;
                printf('<div class="sc-card"><h3>%s</h3><p class="sc-muted">%s</p>', esc_html(self::objekt_label($o)),
                    esc_html(implode(' · ', array_filter([$o['oblik'], $o['adresa'], $o['oznaka']]))));
                if (!$count && !$current) {
                    printf('<p class="sc-muted">Nema %s na ovoj lokaciji, pa cjenik nije potreban.</p>', esc_html($info['plural']));
                } elseif ($current) {
                    printf('<p><span class="sc-badge">Objavljen</span> %s · %d %s</p><p><code>%s</code></p><p><a class="button" href="%s" target="_blank">CSV</a> <a class="button" href="%s" target="_blank">XML</a></p>',
                        esc_html(wp_date('d.m.Y. \u H:i', strtotime($current['objavljeno']))), $count, self::plural($count, 'stavka', 'stavke', 'stavki'),
                        esc_html($current['naziv']), esc_url($current['csv']), esc_url($current['xml']));
                } else {
                    echo '<p><span class="sc-badge is-warning">Nije objavljen</span> Kliknite "Objavi cjenike sada".</p>';
                }
                echo '</div>';
            }
            echo '</div>';
        }

        $next = wp_next_scheduled(self::CRON);
        printf('<form method="post" action="%s" class="sc-actions"><input type="hidden" name="action" value="sc_generiraj">%s', esc_url(admin_url('admin-post.php')), wp_nonce_field('sc_gen', '_wpnonce', true, false));
        submit_button('Objavi cjenike sada', 'primary', 'submit', false);
        if ($next) printf('<span class="sc-muted">Sljedeća automatska objava: %s</span>', esc_html(wp_date('d.m.Y. \u H:i', $next)));
        echo '</form>';

        echo '<form method="post" action="options.php">';
        settings_fields('sc_group');
        echo '<h2>Podaci o subjektu</h2><table class="form-table">';
        $field('subjekt', 'Naziv subjekta', 'Upisuje se u XML datoteke.');
        echo '</table>';

        echo '<h2>Lokacije</h2><p class="description">Svaka lokacija dobiva svoj cjenik, jer propis traži zasebnu datoteku za svaku poslovnicu, kabinet ili webshop, čak i kad su cijene iste. Ako na istoj adresi imate i usluge i proizvode, dodajte dvije lokacije (npr. U-01 i P-01).</p>';
        echo '<table class="widefat sc-objekti"><thead><tr><th>Cjenik</th><th>Naziv lokacije</th><th>Oblik objekta</th><th>Adresa</th><th>Oznaka</th><th></th></tr></thead><tbody>';
        $objekti = $s['objekti'] ?: [[]];
        foreach (array_values($objekti) as $i => $o) self::objekt_row($i, $o);
        echo '</tbody></table><template id="sc-objekt-row">';
        self::objekt_row('__i__', []);
        echo '</template><p><button type="button" class="button sc-add-objekt">+ Dodaj lokaciju</button></p>';
        echo '<p class="description">Naziv datoteke: <code>oblik_adresa_oznaka_brojpohrane_datum_vrijeme</code>. Uklanjanjem lokacije prestaje objava njezinog cjenika, a stari cjenici ostaju u arhivi još ' . (int) self::ARHIVA_DANA . ' dana.</p>';

        echo '<h2>Headless frontend</h2><table class="form-table">';
        $field('revalidate_url', 'Revalidate URL (neobavezno)', 'Poziva se nakon svake objave cjenika, za osvježavanje cachea na frontendu.');
        echo '</table>';
        submit_button('Spremi postavke');
        echo '</form>';

        printf('<h2>Arhiva</h2><p class="description">Prethodni cjenici ostaju javno dostupni %d dana (propis traži najmanje 30).</p>', (int) self::ARHIVA_DANA);
        if (!$f['arhiva']) {
            echo '<p class="sc-muted">Arhiva je prazna.</p>';
        } else {
            echo '<table class="widefat striped sc-archive"><thead><tr><th>Lokacija</th><th>Cjenik</th><th>Objavljen</th><th>Datoteka</th><th></th></tr></thead><tbody>';
            foreach ($f['arhiva'] as $e) {
                printf('<tr><td>%s</td><td>%s</td><td>%s</td><td><code>%s</code></td><td><a href="%s" target="_blank">CSV</a> · <a href="%s" target="_blank">XML</a></td></tr>',
                    esc_html($e['lokacija']), esc_html(self::type_info($e['vrsta'])['title']), esc_html(wp_date('d.m.Y. H:i', strtotime($e['objavljeno']))),
                    esc_html($e['naziv']), esc_url($e['csv']), esc_url($e['xml']));
            }
            echo '</tbody></table>';
        }

        echo '<div class="sc-danger"><h2>Kreni ispočetka</h2><p>Briše sve objavljene cjenike i arhivu, vraća brojeve pohrane na 1 i objavljuje nove cjenike. Koristite samo prije puštanja stranice u rad, npr. za brisanje testnih cjenika.</p>';
        printf('<form method="post" action="%s"><input type="hidden" name="action" value="sc_reset">%s', esc_url(admin_url('admin-post.php')), wp_nonce_field('sc_reset', '_wpnonce', true, false));
        submit_button('Obriši sve cjenike i kreni ispočetka', 'delete', 'submit', false, ['onclick' => "return confirm('Obrisati sve cjenike i arhivu? Ovo se ne može poništiti.')"]);
        echo '</form></div></div>';
        echo self::settings_js();
    }

    private static function settings_js() {
        return <<<'HTML'
<script>
(function () {
    var table = document.querySelector('.sc-objekti tbody');
    var template = document.getElementById('sc-objekt-row');
    if (!table || !template) return;
    document.querySelector('.sc-add-objekt').addEventListener('click', function () {
        table.insertAdjacentHTML('beforeend', template.innerHTML.replace(/__i__/g, 'n' + Date.now()));
        table.lastElementChild.querySelector('input[type="text"]').focus();
    });
    table.addEventListener('click', function (e) {
        var button = e.target.closest('.sc-remove-objekt');
        if (!button) return;
        var row = button.closest('tr');
        if (row.dataset.saved && !confirm('Ukloniti lokaciju? Njezin cjenik se više neće objavljivati. Promjena vrijedi nakon "Spremi postavke".')) return;
        row.remove();
    });
})();
</script>
HTML;
    }

    private static function settings_css() {
        return <<<'HTML'
<style>
.sc-settings .sc-cards { display: flex; flex-wrap: wrap; gap: 16px; margin: 16px 0 24px; }
.sc-settings .sc-card { flex: 1 1 320px; max-width: 560px; background: #fff; border: 1px solid #dcdcde; border-radius: 4px; padding: 16px 20px; }
.sc-settings .sc-card h3 { margin: 0 0 4px; }
.sc-settings .sc-card code { word-break: break-all; }
.sc-settings .sc-muted { color: #646970; }
.sc-settings .sc-badge { display: inline-block; padding: 2px 8px; margin-right: 6px; border-radius: 10px; background: #edfaef; color: #00450c; font-size: 12px; font-weight: 600; }
.sc-settings .sc-badge.is-warning { background: #fcf9e8; color: #614200; }
.sc-settings .sc-actions { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; margin-bottom: 24px; }
.sc-settings .sc-objekti { max-width: 1200px; }
.sc-settings .sc-objekti input[type="text"], .sc-settings .sc-objekti select { width: 100%; }
.sc-settings .sc-objekti td:last-child { width: 32px; vertical-align: middle; }
.sc-settings .sc-remove-objekt { color: #b32d2e; }
.sc-settings .sc-archive { max-width: 1200px; }
.sc-settings .sc-danger { margin-top: 32px; padding-top: 8px; border-top: 1px solid #dcdcde; }
@media (max-width: 782px) {
    .sc-settings .sc-objekti thead { display: none; }
    .sc-settings .sc-objekti tr { display: flex; flex-wrap: wrap; gap: 8px; padding: 8px 0; border-bottom: 1px solid #dcdcde; }
    .sc-settings .sc-objekti td { display: block; flex: 1 1 45%; padding: 0; }
}
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
        $ids = array_merge(array_column(self::objekti(), 'id'), array_column(get_option(self::REG, []), 'objekt'), self::TIPOVI);
        foreach (array_unique(array_filter($ids)) as $id) {
            delete_option("sc_broj_$id");
            delete_option("sc_hash_$id");
        }
        delete_option(self::REG);
        self::regenerate(self::TIPOVI, true);
        wp_safe_redirect(admin_url('edit.php?post_type=' . self::CPT . '&page=sc-postavke&sc_ok=reset'));
        exit;
    }

    /* ---------- Item list ---------- */

    public static function columns($cols) {
        $cb = isset($cols['cb']) ? ['cb' => $cols['cb']] : [];
        $cols = $cb + ['sc_order' => '<span class="screen-reader-text">Redoslijed</span>'] + $cols;
        $cols['sc_vrsta'] = 'Vrsta';
        $cols['sc_cijena'] = 'Cijena';
        $cols['sc_sidrena'] = 'Dodatna (sidrena) cijena';
        if (count(self::objekti()) > 1) $cols['sc_lokacije'] = 'Lokacije';
        return $cols;
    }

    public static function column($col, $id) {
        $m = fn($k) => (string) get_post_meta($id, 'sc_' . $k, true);
        if ($col === 'sc_order' && self::is_reorderable()) {
            echo '<span class="dashicons dashicons-menu sc-drag" title="Povucite za promjenu redoslijeda" aria-hidden="true"></span>';
        }
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
        if ($col === 'sc_lokacije') {
            $list = self::objekti($m('vrsta') ?: 'usluga');
            $offered = array_filter($list, fn($o) => self::is_offered_at($id, $o['id']));
            $overrides = self::overrides($id);
            if (!$list) { echo '<span style="color:#d63638">nema lokacije</span>'; return; }
            if (!$offered) { echo '<span style="color:#d63638">nigdje</span>'; return; }
            echo esc_html(implode(', ', array_map([__CLASS__, 'objekt_label'], $offered)));
            if (count($offered) < count($list)) {
                $missing = array_udiff($list, $offered, fn($a, $b) => strcmp($a['id'], $b['id']));
                printf('<br><small>Ne nudi se: %s</small>', esc_html(implode(', ', array_map([__CLASS__, 'objekt_label'], $missing))));
            }
            $custom = array_filter($offered, fn($o) => !empty($overrides[$o['id']]));
            if ($custom) printf('<br><small>Vlastite postavke: %s</small>', esc_html(implode(', ', array_map([__CLASS__, 'objekt_label'], $custom))));
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
        // Same order as the published price list, unless the user sorts by a column.
        if (empty($_GET['orderby'])) $query->set('orderby', ['menu_order' => 'ASC', 'title' => 'ASC']);
    }

    private static function is_reorderable() {
        return empty($_GET['orderby']) || $_GET['orderby'] === 'menu_order';
    }

    public static function list_assets($hook) {
        $screen = get_current_screen();
        if ($hook !== 'edit.php' || !$screen || $screen->post_type !== self::CPT) return;
        wp_register_style('sc-list', false);
        wp_enqueue_style('sc-list');
        wp_add_inline_style('sc-list', '.column-sc_order { width: 24px; } .sc-drag { cursor: move; color: #8c8f94; } .sc-drag:hover { color: #2271b1; }'
            . ' .sc-sort-placeholder { height: 56px; background: #f0f6fc; outline: 1px dashed #2271b1; } #the-list tr.ui-sortable-helper { background: #fff; box-shadow: 0 2px 8px rgba(0,0,0,.15); }');
        if (!self::is_reorderable()) return;
        wp_enqueue_script('jquery-ui-sortable');
        wp_add_inline_script('jquery-ui-sortable', 'window.scReorder = ' . wp_json_encode(['nonce' => wp_create_nonce('sc_reorder')]) . ';' . self::reorder_js());
    }

    private static function reorder_js() {
        return <<<'JS'
jQuery(function ($) {
    var $list = $('#the-list');
    if (!$list.length || !$list.find('.sc-drag').length) return;
    $list.sortable({
        handle: '.sc-drag',
        items: '> tr',
        axis: 'y',
        placeholder: 'sc-sort-placeholder',
        helper: function (e, tr) {
            tr.children().each(function () { $(this).width($(this).width()); });
            return tr;
        },
        update: function () {
            var ids = $list.children('tr').map(function () { return this.id.replace('post-', ''); }).get();
            $list.sortable('disable').css('opacity', 0.6);
            $.post(window.ajaxurl, { action: 'sc_reorder', nonce: window.scReorder.nonce, ids: ids })
                .fail(function () { alert('Redoslijed nije spremljen. Osvježite stranicu i pokušajte ponovno.'); })
                .always(function () { $list.sortable('enable').css('opacity', 1); });
        }
    });
});
JS;
    }

    // Visible rows (possibly a filtered subset) are written back into the slots they already occupy in the full order.
    public static function ajax_reorder() {
        check_ajax_referer('sc_reorder', 'nonce');
        if (!current_user_can('edit_posts')) wp_send_json_error(null, 403);
        $all = array_map('intval', get_posts([
            'post_type' => self::CPT, 'post_status' => ['publish', 'draft', 'pending', 'private', 'future'],
            'numberposts' => -1, 'fields' => 'ids', 'orderby' => ['menu_order' => 'ASC', 'title' => 'ASC'],
        ]));
        $moved = array_values(array_intersect(array_unique(array_map('intval', (array) ($_POST['ids'] ?? []))), $all));
        foreach (array_keys(array_intersect($all, $moved)) as $i => $slot) $all[$slot] = $moved[$i];

        global $wpdb;
        foreach (array_values($all) as $position => $post_id) {
            if ((int) get_post_field('menu_order', $post_id) === $position || !current_user_can('edit_post', $post_id)) continue;
            // Direct update: reordering alone should not publish a new price list on every drag.
            $wpdb->update($wpdb->posts, ['menu_order' => $position], ['ID' => $post_id]);
            clean_post_cache($post_id);
        }
        // The published files pick up the new order with the next publication; the site shows it right away.
        self::revalidate_frontend(self::TIPOVI, array_column(self::objekti(), 'id'));
        wp_send_json_success();
    }

    /* ---------- Updates from GitHub Releases ---------- */

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

    /* ---------- Helpers ---------- */

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
