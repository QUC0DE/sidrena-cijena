<?php
/**
 * Plugin Name: Sidrena cijena i cjenik
 * Description: Unos usluga i proizvoda s dodatnom (sidrenom) cijenom, automatsko generiranje CSV/XML cjenika prema NN 101/2026 i REST + WPGraphQL API za headless frontend.
 * Version: 1.0.1
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
    ];
    private static $price_fields = ['cijena', 'sidrena_cijena', 'akcija_cijena', 'akcija_najniza30', 'cijena_jm'];

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
        add_action('rest_api_init', [__CLASS__, 'rest']);
        add_action('graphql_register_types', [__CLASS__, 'graphql']);
        add_action(self::CRON, [__CLASS__, 'cron']);
        add_filter('manage_' . self::CPT . '_posts_columns', [__CLASS__, 'columns']);
        add_action('manage_' . self::CPT . '_posts_custom_column', [__CLASS__, 'column'], 10, 2);
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
        $in = function ($k, $label, $help = '', $cls = '', $type = 'text') use ($v) {
            printf('<tr class="%s"><th><label for="sc_%s">%s</label></th><td><input type="%s" id="sc_%2$s" name="sc_%2$s" value="%s" class="regular-text">%s</td></tr>',
                esc_attr($cls), esc_attr($k), esc_html($label), esc_attr($type), esc_attr($v[$k]),
                $help ? '<p class="description">' . esc_html($help) . '</p>' : '');
        };
        echo '<table class="form-table"><tr><th>Vrsta</th><td><select name="sc_vrsta" id="sc_vrsta">';
        foreach (['usluga' => 'Usluga', 'proizvod' => 'Proizvod (npr. digitalni materijal)'] as $k => $l) {
            printf('<option value="%s"%s>%s</option>', $k, selected($v['vrsta'], $k, false), $l);
        }
        echo '</select></td></tr>';
        $in('cijena', 'Redovna cijena (€)', 'Trenutna redovna cijena, npr. 45,00');
        $in('sidrena_cijena', 'Sidrena cijena (€)', 'Redovna cijena na dan 10. 9. 2026. (bez akcija). Ako je prazno, uzima se redovna cijena.');
        $in('sidrena_datum', 'Datum sidrene cijene', 'Za postojeće stavke 2026-09-10. Za stavke uvedene kasnije: datum prvog uvođenja u prodaju.', '', 'date');
        echo '<tr><th>Nova stavka</th><td><label><input type="checkbox" name="sc_nova" value="1"> Uvedena u ponudu nakon 10. 9. 2026. (automatski postavi sidrenu cijenu = redovna, datum = danas)</label></td></tr>';
        echo '<tr><th colspan="2"><h3 style="margin:0">Akcija / popust (neobavezno)</h3></th></tr>';
        $in('akcija_cijena', 'Akcijska cijena (€)', 'Ostavite prazno ako nema akcije.');
        $in('akcija_naziv', 'Naziv akcije', 'Npr. "Jesenski popust". Obavezno ako postoji akcijska cijena.');
        $in('akcija_najniza30', 'Najniža cijena u zadnjih 30 dana (€)', 'Prikazuje se uz akciju.');
        echo '<tr class="sc-proizvod"><th colspan="2"><h3 style="margin:0">Podaci za proizvode</h3></th></tr>';
        $in('sifra', 'Šifra proizvoda', 'Ako je prazno, koristi se interna šifra SC-{ID}.', 'sc-proizvod');
        $in('marka', 'Marka', 'Npr. naziv centra ako je vlastiti materijal.', 'sc-proizvod');
        $in('jedinica_mjere', 'Jedinica mjere', 'Npr. kom (ako je primjenjivo)', 'sc-proizvod');
        $in('cijena_jm', 'Cijena za jedinicu mjere (€)', '', 'sc-proizvod');
        $in('barkod', 'Barkod', 'Ako je primjenjivo', 'sc-proizvod');
        echo '<tr class="sc-proizvod"><th>Raspoloživost</th><td><select name="sc_dostupnost">';
        foreach (['dostupno', 'nedostupno'] as $o) printf('<option%s>%s</option>', selected($v['dostupnost'], $o, false), $o);
        echo '</select></td></tr></table>';
        echo "<script>(function(){var s=document.getElementById('sc_vrsta');function t(){document.querySelectorAll('.sc-proizvod').forEach(function(r){r.style.display=s.value==='proizvod'?'':'none';});}s.addEventListener('change',t);t();})();</script>";
    }

    public static function save($post_id) {
        if (!isset($_POST['sc_nonce']) || !wp_verify_nonce($_POST['sc_nonce'], 'sc_save')) return;
        if ((defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) || wp_is_post_revision($post_id)) return;
        if (!current_user_can('edit_post', $post_id)) return;

        foreach (array_keys(self::$fields) as $k) {
            if (!isset($_POST['sc_' . $k])) continue;
            $val = sanitize_text_field(wp_unslash($_POST['sc_' . $k]));
            if (in_array($k, self::$price_fields, true)) $val = self::norm_price($val);
            update_post_meta($post_id, 'sc_' . $k, $val);
        }
        $cijena  = get_post_meta($post_id, 'sc_cijena', true);
        $sidrena = get_post_meta($post_id, 'sc_sidrena_cijena', true);
        $datum   = get_post_meta($post_id, 'sc_sidrena_datum', true);

        if (!empty($_POST['sc_nova'])) {
            if ($sidrena === '') update_post_meta($post_id, 'sc_sidrena_cijena', $cijena);
            if ($datum === '' || $datum === self::DEFAULT_DATUM) update_post_meta($post_id, 'sc_sidrena_datum', wp_date('Y-m-d'));
        } else {
            if ($sidrena === '' && $cijena !== '') update_post_meta($post_id, 'sc_sidrena_cijena', $cijena);
            if ($datum === '') update_post_meta($post_id, 'sc_sidrena_datum', self::DEFAULT_DATUM);
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

    public static function cron() {
        if ((int) wp_date('N') >= 6) return;
        self::regenerate(['proizvod'], true);
    }

    public static function regenerate(array $types, $force = false) {
        $changed = [];
        foreach ($types as $type) {
            $rows = self::rows($type);
            if (!$rows) continue;
            $hash = md5(wp_json_encode($rows));
            if (!$force && get_option("sc_hash_$type") === $hash) continue;
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

        $base = self::clean_name(implode('_', [
            $s["{$type}_oblik"], $s['adresa'], $s["{$type}_oznaka"], $n, wp_date('d.m.Y_H:i'),
        ]));
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
        add_submenu_page('edit.php?post_type=' . self::CPT, 'Postavke cjenika', 'Postavke i datoteke', 'manage_options', 'sc-postavke', [__CLASS__, 'settings_page']);
    }

    public static function settings_page() {
        $s = self::settings();
        $f = self::files();
        $field = fn($k, $l, $h = '') => printf('<tr><th>%s</th><td><input class="regular-text" name="%s[%s]" value="%s"><p class="description">%s</p></td></tr>',
            esc_html($l), self::OPT, $k, esc_attr($s[$k]), esc_html($h));
        echo '<div class="wrap"><h1>Cjenik: postavke i datoteke</h1>';
        if (isset($_GET['sc_ok'])) echo '<div class="notice notice-success"><p>Cjenik je generiran.</p></div>';
        echo '<form method="post" action="options.php">';
        settings_fields('sc_group');
        echo '<table class="form-table">';
        $field('subjekt', 'Naziv subjekta');
        $field('adresa', 'Adresa objekta', 'Npr. Ilica 150 Zagreb (ulazi u naziv datoteke)');
        $field('usluga_oblik', 'Oblik uslužnog objekta', 'Npr. centar, ordinacija, kabinet');
        $field('usluga_oznaka', 'Oznaka uslužnog objekta', 'Npr. U-01');
        $field('proizvod_oblik', 'Oblik objekta za proizvode', 'Npr. webshop (za digitalne materijale koji se prodaju online)');
        $field('proizvod_oznaka', 'Oznaka objekta za proizvode', 'Npr. W-01');
        $field('revalidate_url', 'Frontend revalidate URL (neobavezno)', 'Poziva se nakon svake promjene cjenika, za osvježavanje cachea na headless frontendu.');
        echo '</table>';
        submit_button('Spremi postavke');
        echo '</form><hr>';
        printf('<form method="post" action="%s"><input type="hidden" name="action" value="sc_generiraj">%s', esc_url(admin_url('admin-post.php')), wp_nonce_field('sc_gen', '_wpnonce', true, false));
        submit_button('Generiraj cjenik sada', 'secondary');
        echo '</form><h2>Trenutni cjenici</h2>';
        if (!$f['trenutni']) echo '<p>Još nema generiranih datoteka.</p>';
        foreach ($f['trenutni'] as $e) {
            printf('<p><strong>%s</strong>: %s &nbsp; <a href="%s" target="_blank">CSV</a> | <a href="%s" target="_blank">XML</a></p>',
                esc_html(ucfirst($e['vrsta'])), esc_html($e['naziv']), esc_url($e['csv']), esc_url($e['xml']));
        }
        echo '<h2>Arhiva (zadnjih ' . (int) self::ARHIVA_DANA . ' dana)</h2><ul>';
        foreach ($f['arhiva'] as $e) {
            printf('<li>%s &nbsp; <a href="%s">CSV</a> | <a href="%s">XML</a></li>', esc_html($e['naziv']), esc_url($e['csv']), esc_url($e['xml']));
        }
        echo '</ul></div>';
    }

    public static function manual_generate() {
        if (!current_user_can('manage_options') || !check_admin_referer('sc_gen')) wp_die('Nedozvoljeno');
        self::regenerate(self::TIPOVI, true);
        wp_safe_redirect(admin_url('edit.php?post_type=' . self::CPT . '&page=sc-postavke&sc_ok=1'));
        exit;
    }

    public static function columns($cols) {
        $cols['sc_vrsta'] = 'Vrsta';
        $cols['sc_cijena'] = 'Cijena';
        $cols['sc_sidrena'] = 'Sidrena cijena';
        return $cols;
    }

    public static function column($col, $id) {
        $m = fn($k) => get_post_meta($id, 'sc_' . $k, true);
        if ($col === 'sc_vrsta') echo esc_html($m('vrsta'));
        if ($col === 'sc_cijena') echo esc_html(self::eur($m('akcija_cijena') ?: $m('cijena'))) . ($m('akcija_cijena') ? ' (akcija)' : '');
        if ($col === 'sc_sidrena') {
            $sid = $m('sidrena_cijena');
            echo $sid === '' ? '<span style="color:#d63638">nedostaje</span>'
                : esc_html(self::eur($sid) . ' (' . self::fmt_date($m('sidrena_datum')) . ')');
        }
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
