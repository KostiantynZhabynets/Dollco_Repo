<?php
/**
 * Plugin Name: Custom Catalog (ohne WooCommerce)
 * Description: Eigener Katalog mit Tabellengenerator (Modelle × Attribute), Kachel-Grid und Merkliste.
 * Version: 1.0.0
 * Author: you
 */

if (!defined('ABSPATH')) exit;
/* Dompdf autoload (если библиотека лежит в /lib/dompdf/) */
if ( ! class_exists('\Dompdf\Dompdf') ) {
    $cc_dompdf_autoload = __DIR__ . '/lib/dompdf/autoload.inc.php';
    
    if ( file_exists($cc_dompdf_autoload) ) {
        require_once $cc_dompdf_autoload;
    }
}

/* Neue Kommentare*/
/* ========== CPT: catalog_item ========== */
add_action('init', function () {
    register_post_type('catalog_item', [
        'label' => 'Katalog',
        'labels' => [
            'name'          => 'Katalog-Artikel',
            'singular_name' => 'Artikel',
            'add_new'       => 'Artikel hinzufügen',
            'add_new_item'  => 'Neuen Artikel hinzufügen',
            'edit_item'     => 'Artikel bearbeiten',
            'new_item'      => 'Neuer Artikel',
            'view_item'     => 'Artikel ansehen',
            'search_items'  => 'Artikel suchen',
        ],
        'public'       => true,
        'has_archive'  => true,
        'rewrite'      => ['slug' => 'katalog'],
        'menu_position'=> 5,
        'menu_icon'    => 'dashicons-products',
        'supports'     => ['title','editor','thumbnail'],
        'show_in_rest' => true,
    ]);

    // Meta für die Tabelle (JSON), damit sie im REST verfügbar ist
    register_post_meta('catalog_item', 'spec_matrix', [
        'type'              => 'string',
        'single'            => true,
        'show_in_rest'      => true,
        'sanitize_callback' => function($val){ return is_string($val) ? wp_kses_post($val) : ''; },
        'auth_callback'     => function(){ return current_user_can('edit_posts'); }
    ]);
});
/* -----------------------------------------------------------
   Taxonomie: Kategorien für Katalog-Artikel
   ----------------------------------------------------------- */
add_action('init', function () {
    $post_type = 'catalog_item';
    register_taxonomy('catalog_category', $post_type, [
        'labels' => [
            'name'          => 'Kategorien',
            'singular_name' => 'Kategorie',
            'search_items'  => 'Kategorien durchsuchen',
            'all_items'     => 'Alle Kategorien',
            'edit_item'     => 'Kategorie bearbeiten',
            'update_item'   => 'Kategorie aktualisieren',
            'add_new_item'  => 'Neue Kategorie hinzufügen',
            'new_item_name' => 'Name der neuen Kategorie',
            'menu_name'     => 'Kategorien',
        ],
        'public'            => true,
        'hierarchical'      => false, // <-- неиерархическая
        'show_ui'           => true,
        'show_admin_column' => true,
        'show_in_rest'      => true,
        'rewrite'           => ['slug' => 'kategorie', 'with_front' => false],
    ]);
});

/* ========== Metabox: Tabellengenerator ========== */
add_action('add_meta_boxes', function(){
    add_meta_box(
        'cc_spec_matrix_box',
        'Eigenschaftstabelle (Modelle × Attribute)',
        'cc_render_spec_matrix_box',
        'catalog_item',
        'normal',
        'default'
    );
});

add_action('wp_ajax_cc_fetch_items', 'cc_fetch_items');
add_action('wp_ajax_nopriv_cc_fetch_items', 'cc_fetch_items');
function cc_fetch_items() {
    $ids = isset($_POST['ids']) ? (array) $_POST['ids'] : [];
    $ids = array_map('intval', $ids);
    $items = [];
    foreach ($ids as $id) {
        $post = get_post($id);
        if (!$post || $post->post_type !== 'catalog_item' || $post->post_status !== 'publish') continue;
        $items[] = [
            'id'    => $id,
            'title' => get_the_title($id),
            'img'   => get_the_post_thumbnail_url($id, 'thumbnail'),
            'link'  => get_permalink($id),
            'categories' => array_map(function($t){
                return ['term_id'=>$t->term_id, 'name'=>$t->name];
            }, get_the_terms($id, 'catalog_category') ?: []),
            'spec'  => get_post_meta($id, 'spec_matrix', true),
        ];
    }
    wp_send_json_success($items);
}

add_action('wp_ajax_cc_wishlist_pdf', 'cc_wishlist_pdf');
add_action('wp_ajax_nopriv_cc_wishlist_pdf', 'cc_wishlist_pdf');
function cc_wishlist_pdf() {
    if ( ! class_exists('\Dompdf\Dompdf') ) {
        wp_send_json_error('PDF-Generator nicht verfügbar.');
    }

    $wlm = isset($_POST['wlm']) ? json_decode(stripslashes($_POST['wlm']), true) : [];
    if (!is_array($wlm) || !count($wlm)) {
        wp_send_json_error('Merkliste ist leer.');
    }

    // IDs sammeln
    $ids = array_map('intval', array_keys($wlm));
    $items = [];
    foreach ($ids as $id) {
        $post = get_post($id);
        if (!$post || $post->post_type !== 'catalog_item' || $post->post_status !== 'publish') continue;
        $items[] = [
            'id'    => $id,
            'title' => get_the_title($id),
            'img'   => get_the_post_thumbnail_url($id, 'medium'),
            'spec'  => get_post_meta($id, 'spec_matrix', true),
        ];
    }
    if (!count($items)) {
        wp_send_json_error('Keine Artikel gefunden.');
    }

    // HTML für PDF bauen
    $css = file_get_contents(__DIR__ . '/assets/pdf.css');
    ob_start();
    echo '<style>' . $css . '</style>';
    ?>
    <img src="/wp-content/uploads/2025/02/Dollco-Logo-1.png" alt="">
    <h1>Merkliste</h1>
    <h1></h1>
    <?php foreach ($items as $it):
        $pid = $it['id'];
        $spec = json_decode($it['spec'] ?: '', true);
        $cols = is_array($spec['columns'] ?? null) ? $spec['columns'] : [];
        $rows = is_array($spec['rows'] ?? null) ? $spec['rows'] : [];
        $modelMap = $wlm[$pid] ?? [];
        $activeMids = array_keys($modelMap);
        if (!count($activeMids)) continue;
        $isSingle = (count($activeMids) === 1 && $activeMids[0] === 'single');
    ?>
      <div class="cc-pdf-item">
        <?php if ($it['img']): ?>
          <img class="cc-pdf-img" src="<?php echo esc_url($it['img']); ?>" alt="">
        <?php endif; ?>
        <p class="bump"></p>
        <strong><?php echo esc_html($it['title']); ?></strong>
        <table>
          <thead>
            <tr>
              <?php if (!$isSingle): ?><th>Modell</th><?php endif; ?>
              <?php foreach($cols as $c): ?><th><?php echo esc_html($c); ?></th><?php endforeach; ?>
              <th>Menge</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach($activeMids as $mid):
                if ($mid === 'single') {
                    $qty = intval($modelMap['single']);
                    echo '<tr>';
                    foreach($cols as $k=>$c) echo '<td></td>';
                    echo '<td>'.$qty.'</td></tr>';
                    continue;
                }
                $idx = intval($mid);
                $row = $rows[$idx] ?? [];
                $name = $row['model'] ?? '';
                $vals = is_array($row['values'] ?? null) ? $row['values'] : [];
                $qty = intval($modelMap[$mid] ?? 1);
            ?>
              <tr>
                <?php if (!$isSingle): ?><td><?php echo esc_html($name); ?></td><?php endif; ?>
                <?php foreach($cols as $k=>$c): ?>
                  <td><?php echo esc_html($vals[$k] ?? ''); ?></td>
                <?php endforeach; ?>
                <td><?php echo $qty; ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
       
      </div>
       <h1></h1>
    <?php endforeach;
    $html = ob_get_clean();

    // PDF generieren
    $dompdf = new \Dompdf\Dompdf([
        'isHtml5ParserEnabled' => true,
        'isRemoteEnabled' => true
    ]);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    // PDF ausgeben
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="Merkliste.pdf"');
    echo $dompdf->output();
    exit;
}
// Basis-Styles des Plugins (deaktivierbar per Filter)
add_action('wp_enqueue_scripts', function () {
    if (apply_filters('cc_enable_default_styles', true)) {
        wp_enqueue_style(
            'custom-catalog',
            plugins_url('assets/custom-catalog.css', __FILE__),
            [],
            '1.0.0'
        );
    }
});
/* -----------------------------------------------------------
   Taxonomie: Kategorien für Katalog-Artikel
   ----------------------------------------------------------- */
add_action('init', function () {
    $post_type = apply_filters('cc_post_type','catalog_item');

    register_taxonomy('catalog_category', $post_type, [
        'labels' => [
            'name'                       => 'Kategorien',
            'singular_name'              => 'Kategorie',
            'search_items'               => 'Kategorien durchsuchen',
            'all_items'                  => 'Alle Kategorien',
            'edit_item'                  => 'Kategorie bearbeiten',
            'update_item'                => 'Kategorie aktualisieren',
            'add_new_item'               => 'Neue Kategorie hinzufügen',
            'new_item_name'              => 'Name der neuen Kategorie',
            'menu_name'                  => 'Kategorien',
        ],
        'public'            => true,
        'hierarchical'      => false, // <-- неиерархическая
        'show_ui'           => true,
        'show_in_rest'      => true,
        'show_admin_column' => true,
        'rewrite'           => ['slug' => 'kategorie', 'with_front' => false],
        'capabilities'      => [
            'manage_terms' => 'manage_categories',
            'edit_terms'   => 'manage_categories',
            'delete_terms' => 'manage_categories',
            'assign_terms' => 'edit_posts',
        ],
    ]);
});
/* -----------------------------------------------------------
   Kategorie-Bild (Term Meta) – ID speichern / laden
   ----------------------------------------------------------- */
function cc_get_category_image_id($term_id){
    return (int) get_term_meta($term_id, 'cc_cat_image_id', true);
}
function cc_set_category_image_id($term_id, $att_id){
    if ($att_id) update_term_meta($term_id, 'cc_cat_image_id', (int)$att_id);
    else delete_term_meta($term_id, 'cc_cat_image_id');
}

function cc_render_spec_matrix_box($post){
    wp_nonce_field('cc_save_spec_matrix','cc_spec_nonce');
    $json = get_post_meta($post->ID, 'spec_matrix', true);
    $data = json_decode($json ?: '', true);
    if (!is_array($data)) $data = ['columns'=>[], 'rows'=>[]];
    $columns = array_values($data['columns'] ?? []);
    $rows    = array_values($data['rows'] ?? []);

    ?>

    <div class="cc-builder" id="cc-builder" data-post="<?php echo esc_attr($post->ID); ?>">
      <input type="hidden" id="cc-spec-json" name="cc_spec_matrix" value="<?php echo esc_attr($json); ?>"/>

      <h4>Spalten (Attribute)</h4>
      <div class="cc-tags" id="cc-columns-tags"></div>
      <div class="cc-row">
        <input type="text" id="cc-new-col" placeholder="Zum Beispiel: Größe, Gewicht, Material …">
        <button type="button" class="button" id="cc-add-col">+ Spalte hinzufügen</button>
        <button type="button" class="button" id="cc-clear-cols">Leeren</button>
      </div>

      <h4>Zeilen (Modelle)</h4>
      <div id="cc-rows"></div>
      <div class="cc-row">
        <input type="text" id="cc-new-row-name" placeholder="Modellname (z. B. Modell A)">
        <button type="button" class="button" id="cc-add-row">+ Modell hinzufügen</button>
        <button type="button" class="button" id="cc-clear-rows">Alle Modelle leeren</button>
      </div>

      <h4>Vorschau</h4>
      <table class="cc-table-preview" id="cc-preview"></table>
      <p><em>Die Tabelle wird als strukturierter JSON gespeichert und über einen Shortcode auf der Website ausgegeben.</em></p>
    </div>

    <script>
    (function(){
      const state = {
        columns: <?php echo json_encode(array_values($columns)); ?>,
        rows: <?php echo json_encode(array_values($rows)); ?> // [{model:'', values:['','',...]}]
      };

      const $json = document.getElementById('cc-spec-json');
      const $colTags = document.getElementById('cc-columns-tags');
      const $newCol = document.getElementById('cc-new-col');
      const $rows = document.getElementById('cc-rows');
      const $newRowName = document.getElementById('cc-new-row-name');
      const $preview = document.getElementById('cc-preview');

      function saveToHidden(){
        $json.value = JSON.stringify({columns: state.columns, rows: state.rows});
      }
      function renderColumns(){
        $colTags.innerHTML = '';
        state.columns.forEach((c, i)=>{
          const span = document.createElement('span');
          span.className = 'cc-tag';
          span.textContent = c;
          span.title = 'Klicken zum Löschen';
          span.style.cursor = 'pointer';
          span.addEventListener('click', ()=>{
            // Spalte und korrespondierende Zellen entfernen
            state.columns.splice(i,1);
            state.rows.forEach(r=>{ if (Array.isArray(r.values)) r.values.splice(i,1); });
            renderAll();
          });
          $colTags.appendChild(span);
        });
        saveToHidden();
      }
      function ensureRowCellsLen(r){
        r.values = Array.isArray(r.values) ? r.values : [];
        while (r.values.length < state.columns.length) r.values.push('');
        if (r.values.length > state.columns.length) r.values = r.values.slice(0, state.columns.length);
      }
      function renderRows(){
        $rows.innerHTML = '';
        state.rows.forEach((r, idx)=>{
          ensureRowCellsLen(r);
          const wrap = document.createElement('div');
          wrap.className = 'cc-row';
          const name = document.createElement('input');
          name.type = 'text';
          name.value = r.model || '';
          name.placeholder = 'Modellname';
          name.addEventListener('input', e=>{ r.model = e.target.value; saveToHidden(); });
          wrap.appendChild(name);

          const del = document.createElement('button');
          del.type = 'button';
          del.className = 'button';
          del.textContent = 'Löschen';
          del.addEventListener('click', ()=>{ state.rows.splice(idx,1); renderAll(); });
          wrap.appendChild(del);

          const cellsBox = document.createElement('div');
          cellsBox.style.display = 'grid';
          cellsBox.style.gridTemplateColumns = `repeat(${Math.max(1,state.columns.length)}, minmax(120px,1fr))`;
          cellsBox.style.gap = '6px';
          state.columns.forEach((c, ci)=>{
            const cell = document.createElement('input');
            cell.type = 'text';
            cell.placeholder = c;
            cell.value = r.values[ci] || '';
            cell.addEventListener('input', e=>{ r.values[ci] = e.target.value; saveToHidden(); });
            cellsBox.appendChild(cell);
          });
          wrap.appendChild(cellsBox);
          $rows.appendChild(wrap);
        });
        saveToHidden();
      }
      function renderPreview(){
        const cols = state.columns;
        let html = '';
        html += '<thead><tr><th>Modell</th>';
        cols.forEach(c=> html += '<th>'+escapeHtml(c)+'</th>');
        html += '</tr></thead><tbody>';
        state.rows.forEach(r=>{
          html += '<tr><td>'+escapeHtml(r.model||'')+'</td>';
          cols.forEach((c,ci)=> html += '<td>'+escapeHtml(r.values?.[ci]||'')+'</td>');
          html += '</tr>';
        });
        html += '</tbody>';
        $preview.innerHTML = html;
      }
      function escapeHtml(s){ return (s||'').toString().replace(/[&<>"']/g, m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m])); }

      function renderAll(){ renderColumns(); renderRows(); renderPreview(); }

      document.getElementById('cc-add-col').addEventListener('click', ()=>{
        const val = ($newCol.value||'').trim();
        if (!val) return;
        state.columns.push(val);
        state.rows.forEach(r=>{ ensureRowCellsLen(r); });
        $newCol.value = '';
        renderAll();
      });
      document.getElementById('cc-clear-cols').addEventListener('click', ()=>{
        if (confirm('Alle Spalten löschen?')) {
          state.columns = [];
          state.rows.forEach(r=>{ r.values = []; });
          renderAll();
        }
      });
      document.getElementById('cc-add-row').addEventListener('click', ()=>{
        const name = ($newRowName.value||'').trim();
        const r = { model: name, values: [] };
        ensureRowCellsLen(r);
        state.rows.push(r);
        $newRowName.value = '';
        renderAll();
      });
      document.getElementById('cc-clear-rows').addEventListener('click', ()=>{
        if (confirm('Alle Modelle löschen?')) {
          state.rows = [];
          renderAll();
        }
      });

      renderAll();
    })();
    </script>
    <?php
}

/* Speichern */
add_action('save_post_catalog_item', function($post_id){
    if (!isset($_POST['cc_spec_nonce']) || !wp_verify_nonce($_POST['cc_spec_nonce'], 'cc_save_spec_matrix')) return;
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (!current_user_can('edit_post', $post_id)) return;

    $json = isset($_POST['cc_spec_matrix']) ? wp_unslash($_POST['cc_spec_matrix']) : '';
    $arr = json_decode($json, true);
    if (!is_array($arr) || !isset($arr['columns']) || !isset($arr['rows'])) $json = '';
    update_post_meta($post_id, 'spec_matrix', $json);
});
/* -----------------------------------------------------------
   Galerie-Helfer (Upload, Lesen, Rendern)
   ----------------------------------------------------------- */

/* Datei-Feld (multiple) in ein Array umwandeln */
function cc_files_to_array($files_field){
    $out = [];
    if (!is_array($files_field) || empty($files_field['name'])) return $out;
    $count = is_array($files_field['name']) ? count($files_field['name']) : 0;
    for ($i=0; $i<$count; $i++){
        if (empty($files_field['name'][$i])) continue;
        $out[] = [
            'name'     => $files_field['name'][$i],
            'type'     => $files_field['type'][$i],
            'tmp_name' => $files_field['tmp_name'][$i],
            'error'    => $files_field['error'][$i],
            'size'     => $files_field['size'][$i],
        ];
    }
    return $out;
}

/* Galerie-IDs aus der Post-Meta holen (Array von Attachment-IDs) */
function cc_get_gallery_ids($post_id){
    $meta = get_post_meta($post_id, 'gallery_ids', true);
    if (is_string($meta)) {
        $ids = array_filter(array_map('intval', preg_split('/[,	\s]+/', $meta)));
    } elseif (is_array($meta)) {
        $ids = array_filter(array_map('intval', $meta));
    } else {
        $ids = [];
    }
    $ids = array_values(array_unique($ids));
    return $ids;
}

/* HTML für die Galerie unter der Tabelle erzeugen */
function cc_gallery_html($post_id){
    $ids = cc_get_gallery_ids($post_id);
    if (empty($ids)) return '';
    $out  = '<div class="cc-gallery">';
    $out .= '<h3 class="cc-gallery-title">Beispiele:</h3>';
    $out .= '<div class="cc-gallery-strip">';
    foreach ($ids as $att_id){
        $thumb = wp_get_attachment_image_url($att_id, 'thumbnail');
        $full  = wp_get_attachment_image_url($att_id, 'large');
        if (!$thumb) continue;
        $out .= '<a class="cc-gallery-item" href="'.esc_url($full ?: $thumb).'">';
        $out .= '<img src="'.esc_url($thumb).'" alt="" loading="lazy">';
        $out .= '</a>';
    }
    $out .= '</div></div>';
    return $out;
}

/* ========== Shortcodes ========== */
/* -----------------------------------------------------------
   [catalog_new_category] – Kategorie im Frontend anlegen
   ----------------------------------------------------------- */
add_shortcode('catalog_new_category', function(){
    if (!is_user_logged_in() || !current_user_can('manage_categories')) {
        return '<p>Keine Berechtigung.</p>';
    }

    $msg = '';
    if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['cc_cat_action']) && $_POST['cc_cat_action']==='create' && check_admin_referer('cc_new_category')) {
        $name = sanitize_text_field($_POST['cc_cat_name'] ?? '');
        $desc = wp_kses_post($_POST['cc_cat_desc'] ?? '');
        // $parent= (int) ($_POST['cc_cat_parent'] ?? 0); // убрано

        if ($name) {
            $r = wp_insert_term($name, 'catalog_category', [
                'description' => $desc,
                // 'parent'      => $parent, // убрано
                'slug'        => sanitize_title($name),
            ]);
            if (!is_wp_error($r)) {
                $term_id = (int)$r['term_id'];

                // Bild uploaden (optional)
                if (!empty($_FILES['cc_cat_image']['name'])) {
                    require_once ABSPATH.'wp-admin/includes/file.php';
                    require_once ABSPATH.'wp-admin/includes/media.php';
                    require_once ABSPATH.'wp-admin/includes/image.php';
                    $key = 'cc_cat_image';
                    $att_id = media_handle_upload($key, 0);
                    if (!is_wp_error($att_id)) cc_set_category_image_id($term_id, $att_id);
                }
                $msg = '<div class="notice notice-success">Kategorie erstellt.</div>';
            } else {
                $msg = '<div class="notice notice-error">'.esc_html($r->get_error_message()).'</div>';
            }
        } else {
            $msg = '<div class="notice notice-error">Name ist erforderlich.</div>';
        }
    }

    // $parents = get_terms(['taxonomy'=>'catalog_category','hide_empty'=>false]); // убрано
    ob_start(); ?>
    <form class="ccf-form" method="post" enctype="multipart/form-data">
      <h2>Neue Kategorie</h2>
      <?php echo $msg; ?>
      <div class="ccf-field">
        <label for="cc_cat_name">Name</label>
        <input type="text" id="cc_cat_name" name="cc_cat_name" required>
      </div>
      <!-- Убрано поле выбора родительской категории -->
      <div class="ccf-field">
        <label for="cc_cat_desc">Beschreibung</label>
        <textarea id="cc_cat_desc" name="cc_cat_desc"></textarea>
      </div>
      <div class="ccf-field">
        <label for="cc_cat_image">Kategorie-Bild</label>
        <input type="file" id="cc_cat_image" name="cc_cat_image" accept="image/*">
      </div>
      <div class="cc-actions-row">
        <?php wp_nonce_field('cc_new_category'); ?>
        <button class="button button-primary" type="submit" name="cc_cat_action" value="create">Speichern</button>
      </div>
    </form>
    <?php return ob_get_clean();
});

/* -----------------------------------------------------------
   [catalog_edit_category] – Kategorie im Frontend bearbeiten/löschen
   Übergabe: ?id=TERM_ID
   ----------------------------------------------------------- */
add_shortcode('catalog_edit_category', function(){
    if (!is_user_logged_in() || !current_user_can('manage_categories')) {
        return '<p>Keine Berechtigung.</p>';
    }
    $id = (int) ($_GET['id'] ?? 0);
    if (!$id) return '<p>Keine Kategorie-ID.</p>';

    $term = get_term($id, 'catalog_category');
    if (!$term || is_wp_error($term)) return '<p>Kategorie nicht gefunden.</p>';

    $msg = '';
    if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['cc_cat_action']) && check_admin_referer('cc_edit_category_'.$id)) {
        $action = $_POST['cc_cat_action'];
        if ($action === 'delete') {
            $r = wp_delete_term($id, 'catalog_category');
            if (is_wp_error($r)) {
                $msg = '<div class="notice notice-error">'.esc_html($r->get_error_message()).'</div>';
            } else {
                return '<p>Kategorie gelöscht.</p>';
            }
        } else {
            $name  = sanitize_text_field($_POST['cc_cat_name'] ?? $term->name);
            $desc  = wp_kses_post($_POST['cc_cat_desc'] ?? $term->description);
            // $parent= (int) ($_POST['cc_cat_parent'] ?? $term->parent); // убрано

            $r = wp_update_term($id, 'catalog_category', [
                'name'        => $name,
                'description' => $desc,
                // 'parent'      => $parent, // убрано
                'slug'        => sanitize_title($name),
            ]);
            if (is_wp_error($r)) {
                $msg = '<div class="notice notice-error">'.esc_html($r->get_error_message()).'</div>';
            } else {
                // Bild aktualisieren (falls hochgeladen)
                if (!empty($_FILES['cc_cat_image']['name'])) {
                    require_once ABSPATH.'wp-admin/includes/file.php';
                    require_once ABSPATH.'wp-admin/includes/media.php';
                    require_once ABSPATH.'wp-admin/includes/image.php';
                    $att_id = media_handle_upload('cc_cat_image', 0);
                    if (!is_wp_error($att_id)) cc_set_category_image_id($id, $att_id);
                }
                $msg = '<div class="notice notice-success">Kategorie gespeichert.</div>';
                $term = get_term($id, 'catalog_category'); // refresh
            }
        }
    }

    // $parents = get_terms(['taxonomy'=>'catalog_category','hide_empty'=>false, 'exclude'=>[$id]]); // убрано
    $img_id  = cc_get_category_image_id($id);
    $thumb   = $img_id ? wp_get_attachment_image_url($img_id, 'thumbnail') : '';

    ob_start(); ?>
    <form class="ccf-form" method="post" enctype="multipart/form-data">
      <h2>Kategorie bearbeiten</h2>
      <?php echo $msg; ?>
      <div class="ccf-field">
        <label for="cc_cat_name">Name</label>
        <input type="text" id="cc_cat_name" name="cc_cat_name" value="<?php echo esc_attr($term->name); ?>" required>
      </div>
      <!-- Убрано поле выбора родительской категории -->
      <div class="ccf-field">
        <label for="cc_cat_desc">Beschreibung</label>
        <textarea id="cc_cat_desc" name="cc_cat_desc"><?php echo esc_textarea($term->description); ?></textarea>
      </div>
      <div class="ccf-field ccf-thumb">
        <label for="cc_cat_image">Kategorie-Bild</label>
        <?php if ($thumb): ?><div><img src="<?php echo esc_url($thumb); ?>" alt=""></div><?php endif; ?>
        <input type="file" id="cc_cat_image" name="cc_cat_image" accept="image/*">
      </div>
      <div class="cc-actions-row">
        <?php wp_nonce_field('cc_edit_category_'.$id); ?>
        <button class="button button-primary" type="submit" name="cc_cat_action" value="update">Änderungen speichern</button>
        <button class="button" type="submit" name="cc_cat_action" value="delete" onclick="return confirm('Kategorie wirklich löschen?')">Löschen</button>
      </div>
    </form>
    <?php return ob_get_clean();
});

/* -----------------------------------------------------------
   [catalog_categories] – Kacheln mit Kategorien
   ----------------------------------------------------------- */
add_shortcode('catalog_categories', function($atts){
    $a = shortcode_atts([
        'cols'      => 3,     // 2|3|4
        'hide_empty'=> '0',   // 0/1
    ], $atts, 'catalog_categories');

    $terms = get_terms([
        'taxonomy'   => 'catalog_category',
        'hide_empty' => (bool) intval($a['hide_empty']),
        'orderby'    => 'name',
        'order'      => 'ASC',
    ]);
    if (is_wp_error($terms) || empty($terms)) {
        return '<p>Keine Kategorien vorhanden.</p>';
    }

    $cols = max(1, min(4, (int)$a['cols']));
    ob_start(); ?>
    <div class="cc-cat-grid cc-grid cols-<?php echo esc_attr($cols); ?>">
      <?php foreach ($terms as $t):
        $img_id = cc_get_category_image_id($t->term_id);
        $img    = $img_id ? wp_get_attachment_image_url($img_id, 'medium') : '';
        $link   = get_term_link($t);
      ?>
        <article class="cc-cat-card cc-card">
          <a href="<?php echo esc_url($link); ?>" class="cc-cat-link">
            <?php if ($img): ?>
              <img src="<?php echo esc_url($img); ?>" alt="<?php echo esc_attr($t->name); ?>">
            <?php endif; ?>
            <h3 class="cc-cat-title"><?php echo esc_html($t->name); ?></h3>
            <div class="cc-cat-count"><?php echo intval($t->count); ?> Artikel</div>
          </a>
        </article>
      <?php endforeach; ?>
    </div>
    <?php
    return ob_get_clean();
});

/* [catalog_grid per_page="18" columns="3"] — Kachel-Grid mit Pagination (ohne Merkliste-Button) */
add_shortcode('catalog_grid', function($atts){
    $a = shortcode_atts(['per_page'=>18,'columns'=>3], $atts, 'catalog_grid');
    $per_page = max(1, intval($a['per_page']));
    $cols = max(1, intval($a['columns']));

    $paged = max(1, get_query_var('paged') ?: get_query_var('page') ?: 1);
    $q = new WP_Query([
        'post_type'      => 'catalog_item',
        'posts_per_page' => $per_page,
        'paged'          => $paged,
    ]);

    ob_start();
    ?>
    <div class="cc-grid cols-<?php echo intval($cols); ?>" id="cc-grid" style="--cc-cols: <?php echo intval($cols); ?>;">
      <?php if ($q->have_posts()): while($q->have_posts()): $q->the_post(); ?>
        <article class="cc-card">
          <a href="<?php the_permalink(); ?>">
            <?php if (has_post_thumbnail()) the_post_thumbnail('medium'); ?>
            <h3><?php the_title(); ?></h3>
          </a>
        </article>
      <?php endwhile; endif; wp_reset_postdata(); ?>
    </div>
    <div class="cc-pagination">
        <?php
          echo paginate_links([
              'total'   => $q->max_num_pages,
              'current' => $paged
          ]);
        ?>
    </div>
    <?php
    return ob_get_clean();
});


/* [catalog_specs_table] — Tabelle mit per-Modell „+1 zur Merkliste“ (mit Auto-Reset nach 2s) */
add_shortcode('catalog_specs_table', function(){
    if (!is_singular(apply_filters('cc_post_type','catalog_item'))) return '';
    $pid  = get_the_ID();
    $json = get_post_meta($pid, 'spec_matrix', true);
    $data = json_decode($json ?: '', true);
    $has_models = !empty($data['rows'] ?? []);
    ob_start(); ?>
    <style>
      .wc-specs-table{ width:100%; border-collapse:collapse; margin:1rem 0; }
      .wc-specs-table th,.wc-specs-table td{ border:1px solid var(--cc-border, #e5e7eb); padding:.6rem .8rem; vertical-align:top; }
      .wc-specs-table th{ text-align:left; }
      .cc-ml-btn{ padding:4px 8px; font-size:12px; }
    </style>
    <table class="wc-specs-table" data-product="<?php echo esc_attr($pid); ?>">
      <thead>
        <tr>
          <th>Modell</th>
          <?php foreach($data['columns'] as $c): ?><th><?php echo esc_html($c); ?></th><?php endforeach; ?>
          <th>Merkliste</th>
        </tr>
      </thead>
      <tbody>
        <?php if ($has_models): foreach($data['rows'] as $i=>$r):
          $model = trim($r['model'] ?? '');
          $vals  = $r['values'] ?? [];
        ?>
          <tr data-model-index="<?php echo esc_attr($i); ?>">
            <td><?php echo esc_html($model); ?></td>
            <?php for ($k=0; $k<count($data['columns']); $k++): ?>
              <td><?php echo esc_html($vals[$k] ?? ''); ?></td>
            <?php endfor; ?>
            <td><button type="button" class="button button-small cc-ml-btn">+1</button></td>
          </tr>
        <?php endforeach; else: ?>
          <tr data-model-index="single">
            <td>Standard</td>
            <?php for ($k=0; $k<count($data['columns']); $k++): ?><td></td><?php endfor; ?>
            <td><button type="button" class="button button-small cc-ml-btn">+1</button></td>
          </tr>
        <?php endif; ?>
      </tbody>
    </table>
    <script>
      (function(){
        function getWLM(){ try{ const raw=localStorage.getItem('ccWishlistM'); if(!raw) return {}; const o=JSON.parse(raw); return (o && typeof o==='object' && !Array.isArray(o))?o:{}; }catch(e){ return {}; } }
        function setWLM(o){ localStorage.setItem('ccWishlistM', JSON.stringify(o)); }
        function addOne(pid, mid){
          const o=getWLM();
          if(!o[pid]) o[pid]={};
          o[pid][mid]=(o[pid][mid]||0)+1;
          setWLM(o);
        }

        // ► Klick-Handler mit Auto-Reset der Button-Beschriftung
        document.addEventListener('click', function(e){
          const btn = e.target.closest('.cc-ml-btn');
          if(!btn) return;
          const tr  = btn.closest('tr');
          const tbl = btn.closest('table');
          if(!tr || !tbl) return;
          const pid = String(tbl.getAttribute('data-product'));
          let mid = tr.getAttribute('data-model-index');
          if (mid === null) return;
          addOne(pid, mid);
          const original = btn.dataset.origText || btn.textContent;
          btn.dataset.origText = original;
          btn.textContent = '✓ Gespeichert';

          // Vorherigen Timer ggf. abbrechen
          if (btn.dataset.resetTimer){
            try{ clearTimeout(parseInt(btn.dataset.resetTimer,10)); }catch(_){}
          }
          // Nach 2s zurück auf Original („+1“)
          const t = setTimeout(()=>{ btn.textContent = btn.dataset.origText || '+1'; }, 1300);
          btn.dataset.resetTimer = String(t);
        }, {passive:true});
      })();
    </script>
    <?php
    return ob_get_clean();
});



/* [catalog_add_to_list] — Button „Zur Merkliste“ nur auf Einzelansicht */
add_shortcode('catalog_add_to_list', function(){
    if ( !is_singular('catalog_item') || !in_the_loop() || !is_main_query() ) return '';
    $id = get_the_ID();
    $json = get_post_meta($id, 'spec_matrix', true);
    $data = json_decode($json ?: '', true);
    $has_models = is_array($data) && !empty($data['rows']);
    ob_start();
    if ($has_models) {
        // Обычная кнопка не выводится, используется таблица моделей
        return '';
    } else {
    ?>
    <button type="button" class="button button-small" data-cc-add-single="<?php echo esc_attr($id); ?>">Zur Merkliste</button>
    <script>
      (function(){
        function getWLM(){ try{ return JSON.parse(localStorage.getItem('ccWishlistM')||'{}'); }catch(e){ return {}; } }
        function setWLM(o){ localStorage.setItem('ccWishlistM', JSON.stringify(o)); }
        document.addEventListener('click', function(e){
          const b = e.target.closest('[data-cc-add-single]');
          if (!b) return;
          const id = String(b.getAttribute('data-cc-add-single'));
          const o = getWLM();
          if (!o[id]) o[id] = {};
          o[id]['single'] = (o[id]['single']||0)+1;
          setWLM(o);
          b.textContent = 'In Merkliste ✓';
        }, {passive:true});
      })();
    </script>
    <?php }
    return ob_get_clean();
});


/* -----------------------------------------------------------
   Button "Kategorie bearbeiten" nur auf Kategorieseiten (FSE-freundlich)
   - injiziert den Button direkt VOR den Query-Loop der Seite
   - keine Ausgabe im Header/Footer, nur im Inhaltsbereich
   ----------------------------------------------------------- */


/* -----------------------------------------------------------
   Eigenes Template für Kategorie-Archiv (klassische Themes)
   ----------------------------------------------------------- */
add_filter('taxonomy_template', function($template){
    if ( is_tax('catalog_category')
         && function_exists('wp_is_block_theme')
         && ! wp_is_block_theme() ) {
        $tpl = __DIR__ . '/templates/taxonomy-catalog_category.php';
        if ( file_exists($tpl) ) return $tpl;
    }
    return $template;
});

/* Einzelansicht: Beschreibung → Tabelle → Galerie → Admin-Aktionen */
add_filter('the_content', function($content){
    if ( is_singular( apply_filters('cc_post_type','catalog_item') ) && in_the_loop() && is_main_query() ) {
        $pid    = get_the_ID();
        $table  = do_shortcode('[catalog_specs_table]');
        $gallery= cc_gallery_html($pid);

        $admin_btn = '';
        if ( current_user_can('edit_post', $pid) ) {
            $edit_url = add_query_arg('id', $pid, home_url('/produkt-bearbeiten/'));
            $admin_btn = '<a class="button button-small" href="'.esc_url($edit_url).'">Bearbeiten</a>';
        }

        $image_html = '';
        $full_img_url = get_the_post_thumbnail_url($pid, 'full');
        if ($full_img_url) {
            $image_html = '<div class="cc-single-fullimg" style="text-align:center;margin-bottom:1.5rem;">'
                        . '<img src="'.esc_url($full_img_url).'" alt="" style="max-width:100%;height:auto;">'
                        . '</div>';
        }

        $html  = '<div class="cc-single-wrap">';
        $html .= $image_html;
        $html .= '<div class="cc-single-desc">'.$content.'</div>';
        $html .= '<div class="cc-single-table">'.$table.'</div>';
        $html .= $gallery;
        // ► Lightbox-Script für die Galerie (zentriert, 50vw, Pfeile, ESC, Klick auf Hintergrund)
        if ($gallery) {
        $html .= '<script>
        (function(){
            const strip = document.querySelector(".cc-gallery-strip");
            if(!strip) return;
            const items = Array.from(strip.querySelectorAll(".cc-gallery-item"));
            if(!items.length) return;

            const hrefs = items.map(a => a.getAttribute("href"));
            let idx = 0;

            // Overlay einmalig anlegen
            let lb = document.getElementById("cc-lightbox");
            if(!lb){
            lb = document.createElement("div");
            lb.id = "cc-lightbox";
            lb.className = "cc-lightbox";
            lb.innerHTML = `
                <div class="cc-lb-backdrop" data-close="1"></div>
                <button class="cc-lb-prev" aria-label="Vorheriges Bild">‹</button>
                <img class="cc-lb-image" alt="">
                <button class="cc-lb-next" aria-label="Nächstes Bild">›</button>
            `;
            document.body.appendChild(lb);
            }
            const img = lb.querySelector(".cc-lb-image");
            const prevBtn = lb.querySelector(".cc-lb-prev");
            const nextBtn = lb.querySelector(".cc-lb-next");

            function open(i){
            idx = (i + hrefs.length) % hrefs.length;
            img.src = hrefs[idx];
            lb.classList.add("is-open");
            document.body.style.overflow = "hidden";
            }
            function close(){
            lb.classList.remove("is-open");
            document.body.style.overflow = "";
            // optional: Bild entladen
            // img.removeAttribute("src");
            }
            function next(){ open(idx+1); }
            function prev(){ open(idx-1); }

            items.forEach((a,i)=>{
            a.addEventListener("click", function(ev){
                ev.preventDefault();
                open(i);
            });
            });

            lb.addEventListener("click", function(ev){
            if (ev.target.matches(".cc-lb-next")) { next(); return; }
            if (ev.target.matches(".cc-lb-prev")) { prev(); return; }
            // Klick auf den verdunkelten Hintergrund schließt
            if (ev.target.dataset && ev.target.dataset.close) { close(); }
            });

            document.addEventListener("keydown", function(ev){
            if (!lb.classList.contains("is-open")) return;
            if (ev.key === "Escape") close();
            if (ev.key === "ArrowRight") next();
            if (ev.key === "ArrowLeft") prev();
            });
        })();
        </script>';
        }

        $html .= '<div class="cc-single-actions">'.$admin_btn.'</div>';
        $html .= '</div>';

        return $html;
    }
    return $content;
});


/* -----------------------------------------------------------
   [catalog_category_loop] – Inhalt der Kategorieseite (FSE)
   ----------------------------------------------------------- */
add_shortcode('catalog_category_loop', function(){
    if ( ! is_tax('catalog_category') ) return '';

    $term = get_queried_object();
    $term_id = (int)($term->term_id ?? 0);

    ob_start(); ?>
    <header class="cc-archive-header">
      <h1 class="cc-archive-title"><?php echo esc_html($term->name ?? 'Kategorie'); ?></h1>
      <?php if (!empty($term->description)): ?>
        <div class="cc-archive-desc"><?php echo wp_kses_post(wpautop($term->description)); ?></div>
      <?php endif; ?>

      <?php if ( current_user_can('manage_categories') && $term_id ): ?>
        <div class="cc-archive-actions" style="margin-top:8px;">
          <a class="button button-small cc-cat-edit-btn"
             href="<?php echo esc_url( add_query_arg('id', $term_id, home_url('/kategorie-bearbeiten/')) ); ?>">
            Bearbeiten
          </a>
        </div>
      <?php endif; ?>
    </header>
    <?php
    // Produkte dieser Kategorie (kompakte Karten)
    $q = new WP_Query([
      'post_type'      => 'catalog_item',
      'tax_query'      => [[ 'taxonomy'=>'catalog_category','field'=>'term_id','terms'=>$term_id ]],
      'orderby'        => 'title',
      'order'          => 'ASC',
      'posts_per_page' => 18,
      'paged'          => max(1,(int)get_query_var('paged')),
    ]);

    echo '<div class="cc-grid cols-3">';
    if ($q->have_posts()){
      while($q->have_posts()){ $q->the_post(); ?>
        <article class="cc-card cc-card--compact">
          <a href="<?php the_permalink(); ?>">
            <?php if (has_post_thumbnail()) the_post_thumbnail('medium'); ?>
            <h3><?php the_title(); ?></h3>
          </a>
        </article>
      <?php }
    } else {
      echo '<p>Keine Artikel in dieser Kategorie.</p>';
    }
    echo '</div>';

    wp_reset_postdata();
    return ob_get_clean();
});




/* [catalog_wishlist] — Merkliste mit Mengen pro Modell + PDF-Export */
add_shortcode('catalog_wishlist', function(){
    $ajax = admin_url('admin-ajax.php');
    ob_start(); ?>
    <div class="cc-toolbar">
      <button type="button" class="button button-small" id="cc-reset">Merkliste zurücksetzen</button>
      <button type="button" class="button button-small button-primary" id="cc-pdf">PDF herunterladen</button>
    </div>
    <div id="cc-wishlist" class="cc-wl"></div>
    <script>
      (async function(){
        // === локальное хранилище per-Modell ===
        function getWLM(){ try{ const raw=localStorage.getItem('ccWishlistM'); if(!raw) return {}; const o=JSON.parse(raw); return (o && typeof o==='object' && !Array.isArray(o))?o:{}; }catch(e){ return {}; } }
        function setWLM(o){ localStorage.setItem('ccWishlistM', JSON.stringify(o)); }
        function setQty(pid, mid, qty){
          const o=getWLM();
          if(!o[pid]) o[pid]={};
          if (qty<=0){ delete o[pid][mid]; if(Object.keys(o[pid]).length===0) delete o[pid]; }
          else { o[pid][mid]=qty; }
          setWLM(o);
        }
        function removeModel(pid, mid){ setQty(pid, mid, 0); }

        document.getElementById('cc-reset').addEventListener('click', function(){
          if (confirm('Merkliste wirklich löschen?')){
            localStorage.removeItem('ccWishlistM'); location.reload();
          }
        });

        const wrap = document.getElementById('cc-wishlist');
        const wlm = getWLM();
        const pids = Object.keys(wlm);
        if (!pids.length){ wrap.innerHTML = '<p>Merkliste ist leer.</p>'; return; }

        async function fetchItems(ids){
          const form = new FormData(); form.append('action','cc_fetch_items');
          ids.forEach(id=>form.append('ids[]', id));
          const res = await fetch('<?php echo esc_js($ajax); ?>', { method:'POST', body: form });
          const payload = await res.json();
          return (payload && payload.success && Array.isArray(payload.data)) ? payload.data : [];
        }

        const items = await fetchItems(pids);
        if (!items.length){ wrap.innerHTML='<p>Merkliste ist leer.</p>'; return; }

        function esc(s){ return (s||'').toString().replace(/[&<>"']/g, m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m])); }

        // ► Nach Kategorie gruppieren (erste Kategorie; sonst "Ohne Kategorie")
        const groups = {};
        for (const it of items){
          const catName = (it.categories && it.categories.length) ? it.categories[0].name : 'Ohne Kategorie';
          if (!groups[catName]) groups[catName] = [];
          groups[catName].push(it);
        }

        // ► Kategorienamen alphabetisch (de)
        const groupNames = Object.keys(groups).sort((a,b)=> a.localeCompare(b,'de',{sensitivity:'base'}));

        wrap.innerHTML = '';

        // ► Für jede Kategorie: Überschrift + Grid mit Artikeln
        for (const gName of groupNames){
          const section = document.createElement('section');
          section.className = 'cc-wl-group';
          section.innerHTML = `<h3 class="cc-wl-group-title">${esc(gName)}</h3><div class="cc-wl-group-inner"></div>`;
          wrap.appendChild(section);
          const inner = section.querySelector('.cc-wl-group-inner');

          // ► Artikel alphabetisch nach Titel sortieren
          const arr = groups[gName].slice().sort((A,B)=> (A.title||'').localeCompare(B.title||'', 'de', {sensitivity:'base'}));

          for (const it of arr){
            const pid = String(it.id);
            const spec = it.spec || '{}';
            let cols=[], rows=[];
            try{ const data = JSON.parse(spec); cols = data.columns||[]; rows = data.rows||[]; }catch(e){}

            const modelMap = wlm[pid] || {};
            const activeMids = Object.keys(modelMap);
            if (!activeMids.length) continue;
            const isSingle = (activeMids.length === 1 && activeMids[0] === 'single');
            const box = document.createElement('article');
            box.className='cc-wl-item';
            box.setAttribute('data-pid', pid);
            let tableHtml = '<table class="cc-models"><thead><tr>';
            if (!isSingle) tableHtml += '<th>Modell</th>';
            cols.forEach(c=> tableHtml += '<th>'+esc(c)+'</th>');
            tableHtml += '<th>Menge</th><th>Aktion</th></tr></thead><tbody>';

            activeMids.forEach(mid=>{
              const idx = parseInt(mid,10);
              const row = rows[idx] || {};
              const name = esc(row.model||'');
              const vals = Array.isArray(row.values)? row.values : [];
              tableHtml += '<tr data-mid="'+esc(mid)+'">';
              if (!isSingle) tableHtml += '<td>'+name+'</td>';
              cols.forEach((c,i)=>{ tableHtml += '<td>'+esc(vals[i]||'')+'</td>'; });
              const qty = parseInt(modelMap[mid]||1,10);
              tableHtml += '<td><div class="cc-qty"><button type="button" class="button button-small cc-minus">−</button><input type="number" class="cc-input" min="1" step="1" value="'+qty+'"><button type="button" class="button button-small cc-plus">+</button></div></td>';
              tableHtml += '<td><button type="button" class="button button-small cc-remove">Entfernen</button></td></tr>';
            });

            tableHtml += '</tbody></table>';

            box.innerHTML =
              '<div class="cc-wl-top">'+
                (it.img ? '<img src="'+esc(it.img)+'" alt="">' : '')+
                '<div><a href="'+esc(it.link)+'"><strong>'+esc(it.title)+'</strong></a></div>'+ 
              '</div>'+ tableHtml;

            // ► ВАЖНО: теперь добавляем в inner (внутрь группы), а не прямо в wrap
            inner.appendChild(box);
          }
        }

        // +/- / Entfernen / прямой ввод количества
        wrap.addEventListener('click', function(e){
          const row = e.target.closest('tr[data-mid]'); if (!row) return;
          const pid = row.closest('.cc-wl-item').getAttribute('data-pid');
          const mid = row.getAttribute('data-mid');
          const input = row.querySelector('.cc-input');

          if (e.target.closest('.cc-minus')){
            let v = Math.max(1, (parseInt(input.value,10)||1)-1); input.value=v; setQty(pid, mid, v);
          }
          if (e.target.closest('.cc-plus')){
            let v = Math.max(1, (parseInt(input.value,10)||1)+1); input.value=v; setQty(pid, mid, v);
          }
          // ENTFERNEN: Modell ODER gesamten Artikel (je nach Anzahl der Modelle)
          // ENTFERNEN: Modell ODER gesamten Artikel (je nach Anzahl der моделей)
          if (e.target.closest('.cc-remove')) {
            const row  = e.target.closest('tr');               // строка модели
            const item = row.closest('.cc-wl-item');           // карточка товара
            const pid  = item && item.getAttribute('data-pid');
            const mid  = row && row.getAttribute('data-mid');
            if (!pid || !mid) return;

            const wl   = getWLM();                             // { pid: { mid: qty, ... }, ... }
            const map  = wl[pid] || {};
            const keys = Object.keys(map);

            if (keys.length > 1) {
              // >1 модель → удаляем только выбранную строку
              if (!confirm('Dieses Modell aus der Merkliste entfernen?')) return;

              // твоя утилита, если есть:
              if (typeof removeModel === 'function') removeModel(pid, mid);
              else { delete map[mid]; wl[pid] = map; setWLM(wl); }

              row.remove();

              // если в карточке не осталось строк моделей → удаляем карточку
              const tbl = item.querySelector('.cc-models');
              if (!tbl.querySelector('tr[data-mid]')) item.remove();
            } else {
              // только 1 модель → удаляем весь товар
              if (!confirm('Diesen Artikel vollständig aus der Merkliste entfernen?')) return;

              delete wl[pid];
              setWLM(wl);
              item.remove();
            }

            // ► если категория-группа пустая — удаляем её (и заголовок)
            const group = (item.parentElement && item.parentElement.closest)
              ? item.closest('.cc-wl-group') : null;
            if (group) {
              const inner = group.querySelector('.cc-wl-group-inner') || group; // на всякий случай
              if (!inner.querySelector('.cc-wl-item')) group.remove();
            }

            // ► если вообще ничего не осталось — показать сообщение
            if (!document.querySelector('.cc-wl-item')) {
              wrap.innerHTML = '<p>Merkliste ist leer.</p>';
            }
            return;
          }


        });
        wrap.addEventListener('change', function(e){
          if (!e.target.classList.contains('cc-input')) return;
          const row = e.target.closest('tr[data-mid]'); const pid=row.closest('.cc-wl-item').getAttribute('data-pid');
          const mid = row.getAttribute('data-mid');
          let v = parseInt(e.target.value,10); if (isNaN(v) || v<1) v=1;
          e.target.value = v; setQty(pid, mid, v);
        });

        // === PDF: собрать и скачать ===
        document.getElementById('cc-pdf').addEventListener('click', async function(){
          const payload = getWLM();
          if (!Object.keys(payload).length){ alert('Merkliste ist leer.'); return; }

          const form = new URLSearchParams();
          form.append('action','cc_wishlist_pdf');
          form.append('wlm', JSON.stringify(payload));

          const res = await fetch('<?php echo esc_js($ajax); ?>', {
            method:'POST',
            headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},
            body: form.toString()
          });

          const ct = (res.headers.get('content-type')||'').toLowerCase();
          // Если пришёл PDF — сохраняем файлом
          if (ct.includes('application/pdf')){
            const blob = await res.blob();
            const url  = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url; a.download = 'Merkliste.pdf';
            document.body.appendChild(a); a.click(); a.remove();
            URL.revokeObjectURL(url);
            return;
          }

          // Иначе пришёл HTML (fallback): откроем окно печати → "Als PDF sichern"
          const html = await res.text();
          const w = window.open('', '_blank');
          if (w) {
            w.document.open(); w.document.write(html); w.document.close();
            w.focus(); w.print();
          } else {
            // браузер заблокировал — покажем в текущей вкладке
            const d = document.createElement('div');
            d.innerHTML = html;
            document.body.appendChild(d);
            alert('PDF-Generator nicht verfügbar. Nutzen Sie bitte den Druckdialog „Als PDF sichern“.');
          }
        });
      })();
    </script>
    <?php
    return ob_get_clean();
});



/* [catalog_new_item] – Frontend-Formular: Titel, Beschreibung, Foto, Tabellengenerator */
add_shortcode('catalog_new_item', function () {
    if ( ! is_user_logged_in() ) {
        return '<p>Bitte einloggen, um neue Produkte anzulegen.</p>';
    }
    // Rechte: bei Bedarf härter machen (z. B. current_user_can('edit_others_posts'))
    if ( ! current_user_can('edit_posts') ) {
        return '<p>Sie haben keine Berechtigung, Produkte anzulegen.</p>';
    }

    $out = '';
    $errors = [];

    // Verarbeitung
    if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ccf_action']) && $_POST['ccf_action'] === 'create' ) {
        // Nonce
        if ( ! isset($_POST['ccf_nonce']) || ! wp_verify_nonce($_POST['ccf_nonce'], 'ccf_new_item') ) {
            $errors[] = 'Sicherheitsprüfung fehlgeschlagen. Bitte neu laden.';
        }

        // Eingaben
        $title   = isset($_POST['ccf_title']) ? sanitize_text_field($_POST['ccf_title']) : '';
        $content = isset($_POST['ccf_content']) ? wp_kses_post($_POST['ccf_content']) : '';
        $json    = isset($_POST['ccf_spec_json']) ? wp_unslash($_POST['ccf_spec_json']) : '';

        if ($title === '') {
            $errors[] = 'Titel ist erforderlich.';
        }

        // JSON prüfen
        $arr = json_decode($json, true);
        if ($json !== '' && (!is_array($arr) || !isset($arr['columns']) || !isset($arr['rows']))) {
            $errors[] = 'Die Eigenschaftstabelle ist ungültig (JSON).';
        }

        if (!$errors) {
            // Beitrag anlegen
            $post_id = wp_insert_post([
                'post_type'   => 'catalog_item',
                'post_status' => 'publish', // ggf. 'draft'
                'post_title'  => $title,
                'post_content'=> $content,
                'post_author' => get_current_user_id(),
            ], true);

            if (is_wp_error($post_id)) {
                $errors[] = 'Fehler beim Erstellen des Produkts: ' . esc_html($post_id->get_error_message());
            } else {
                // Bild hochladen → als Beitragsbild setzen
                if (!empty($_FILES['ccf_image']['name'])) {
                    require_once ABSPATH . 'wp-admin/includes/file.php';
                    require_once ABSPATH . 'wp-admin/includes/image.php';
                    require_once ABSPATH . 'wp-admin/includes/media.php';
                    $att_id = media_handle_upload('ccf_image', $post_id);
                    if (!is_wp_error($att_id)) {
                        set_post_thumbnail($post_id, $att_id);
                    } else {
                        $errors[] = 'Bild-Upload fehlgeschlagen: ' . esc_html($att_id->get_error_message());
                    }
                }

                // Tabelle speichern
                if ($json !== '') {
                    update_post_meta($post_id, 'spec_matrix', $json);
                }
                // ► Galerie-Upload (mehrere Dateien)
                if (!empty($_FILES['ccf_gallery']['name']) && is_array($_FILES['ccf_gallery']['name'])) {
                    require_once ABSPATH . 'wp-admin/includes/file.php';
                    require_once ABSPATH . 'wp-admin/includes/image.php';
                    require_once ABSPATH . 'wp-admin/includes/media.php';

                    $files = cc_files_to_array($_FILES['ccf_gallery']);
                    $gids  = [];
                    foreach ($files as $i => $file) {
                        // Dom-Feldname für media_handle_upload simulieren
                        $key = 'ccf_gallery_single_' . $i;
                        $_FILES[$key] = $file;
                        $att_id = media_handle_upload($key, $post_id);
                        if (!is_wp_error($att_id)) $gids[] = $att_id;
                        unset($_FILES[$key]);
                    }
                    if (!empty($gids)) {
                        update_post_meta($post_id, 'gallery_ids', $gids);
                    }
                }
                // Deutsch: gewählte Kategorien dem Artikel zuweisen
                if (isset($_POST['cc_item_categories'])) {
                    $term_ids = array_map('intval', (array) $_POST['cc_item_categories']);
                    wp_set_post_terms($post_id, $term_ids, 'catalog_category', false);
                } else {
                    // nichts gewählt → Kategorien leeren
                    wp_set_post_terms($post_id, [], 'catalog_category', false);
                }

                // Erfolg → Weiterleitung auf das Produkt
                wp_safe_redirect( get_permalink($post_id) );
                exit;
            }
        }
    }

    // Fehler ausgeben (falls vorhanden)
    if ($errors) {
        $out .= '<div class="notice notice-error" style="border-left:4px solid #d63638;padding:.6rem 1rem;margin:0 0 1rem;">';
        $out .= '<strong>Fehler:</strong><ul style="margin:.4rem 0 0 1.2rem;">';
        foreach ($errors as $e) { $out .= '<li>'.esc_html($e).'</li>'; }
        $out .= '</ul></div>';
    }

    // Formular (Deutsch), inkl. Tabellengenerator (wie im Metabox, но для фронта)
    ob_start(); ?>


    <form class="ccf-form" method="post" enctype="multipart/form-data">
      <?php wp_nonce_field('ccf_new_item','ccf_nonce'); ?>
      <input type="hidden" name="ccf_action" value="create"/>

      <div class="ccf-field">
        <label for="ccf_title">Titel</label>
        <input type="text" id="ccf_title" name="ccf_title" placeholder="Produktname" required>
      </div>

      <div class="ccf-field">
        <label for="ccf_content">Beschreibung</label>
        <textarea id="ccf_content" name="ccf_content" rows="6" placeholder="Produktbeschreibung …"></textarea>
      </div>
     <?php
        // Deutsch: alle Kategorien für Mehrfachauswahl laden (alphabetisch)
        $cc_all_terms = get_terms([
          'taxonomy'   => 'catalog_category',
          'hide_empty' => false,
          'orderby'    => 'name',
          'order'      => 'ASC',
        ]);
      ?>
      <div class="ccf-field">
        <label for="cc_item_categories">Kategorie(n)</label>
        <select id="cc_item_categories" name="cc_item_categories[]" multiple>
          <?php foreach($cc_all_terms as $t): ?>
            <option value="<?php echo esc_attr($t->term_id); ?>"><?php echo esc_html($t->name); ?></option>
          <?php endforeach; ?>
        </select>
        <small class="cc-muted">Mehrfachauswahl möglich (Strg/Cmd gedrückt halten).</small>
      </div>

      <div class="ccf-field">
        <label for="ccf_image">Produktfoto</label>
        <input type="file" id="ccf_image" name="ccf_image" accept="image/*">
      </div>

      <div class="ccf-field">
        <label>Eigenschaftstabelle (Modelle × Attribute)</label>
        <input type="hidden" id="ccf-spec-json" name="ccf_spec_json" value=""/>
        <div class="cc-builder" id="ccf-builder">
          <h4>Spalten (Attribute)</h4>
          <div class="cc-tags" id="ccf-columns-tags"></div>
          <div class="cc-row">
            <input type="text" id="ccf-new-col" placeholder="z. B. Größe, Gewicht, Material …">
            <button type="button" class="button" id="ccf-add-col">+ Spalte hinzufügen</button>
            <button type="button" class="button" id="ccf-clear-cols">Leeren</button>
          </div>

          <h4>Zeilen (Modelle)</h4>
          <div id="ccf-rows"></div>
          <div class="cc-row">
            <input type="text" id="ccf-new-row-name" placeholder="Modellname (z. B. Modell A)">
            <button type="button" class="button" id="ccf-add-row">+ Modell hinzufügen</button>
            <button type="button" class="button" id="ccf-clear-rows">Alle Modelle leeren</button>
          </div>

          <h4>Vorschau</h4>
          <table class="cc-table-preview" id="ccf-preview"></table>
          <p><em>Die Tabelle wird strukturiert gespeichert und automatisch in der Artikelseite angezeigt.</em></p>
        </div>
      </div>
                <div class="ccf-field">
                    <label for="ccf_gallery">Galerie (zusätzliche Bilder)</label>
                        <input type="file" id="ccf_gallery" name="ccf_gallery[]" accept="image/*" multiple>
                             <small class="cc-muted">Mehrere Bilder können ausgewählt werden.</small>
                 </div>
      
      <p><button type="submit" class="button button-primary">Produkt erstellen</button></p>
    </form>

    <script>
    (function(){
      const state = { columns: [], rows: [] }; // rows: [{model:'', values:[]}]
      const $json = document.getElementById('ccf-spec-json');
      const $colTags = document.getElementById('ccf-columns-tags');
      const $newCol = document.getElementById('ccf-new-col');
      const $rows = document.getElementById('ccf-rows');
      const $newRowName = document.getElementById('ccf-new-row-name');
      const $preview = document.getElementById('ccf-preview');

      function saveToHidden(){ $json.value = JSON.stringify({columns: state.columns, rows: state.rows}); }
      function renderColumns(){
        $colTags.innerHTML = '';
        state.columns.forEach((c, i)=>{
          const span = document.createElement('span');
          span.className = 'cc-tag';
          span.textContent = c;
          span.title = 'Klicken zum Löschen';
          span.style.cursor = 'pointer';
          span.addEventListener('click', ()=>{
            state.columns.splice(i,1);
            state.rows.forEach(r=>{ if (Array.isArray(r.values)) r.values.splice(i,1); });
            renderAll();
          });
          $colTags.appendChild(span);
        });
        saveToHidden();
      }
      function ensureRowCellsLen(r){
        r.values = Array.isArray(r.values) ? r.values : [];
        while (r.values.length < state.columns.length) r.values.push('');
        if (r.values.length > state.columns.length) r.values = r.values.slice(0, state.columns.length);
      }
      function renderRows(){
        $rows.innerHTML = '';
        state.rows.forEach((r, idx)=>{
          ensureRowCellsLen(r);
          const wrap = document.createElement('div'); wrap.className='cc-row';
          const name = document.createElement('input'); name.type='text'; name.value=r.model||''; name.placeholder='Modellname';
          name.addEventListener('input', e=>{ r.model=e.target.value; saveToHidden(); });
          wrap.appendChild(name);

          const del = document.createElement('button'); del.type='button'; del.className='button'; del.textContent='Löschen';
          del.addEventListener('click', ()=>{ state.rows.splice(idx,1); renderAll(); });
          wrap.appendChild(del);

          const cellsBox = document.createElement('div');
          cellsBox.style.display='grid'; cellsBox.style.gridTemplateColumns=`repeat(${Math.max(1,state.columns.length)}, minmax(120px,1fr))`; cellsBox.style.gap='6px';
          state.columns.forEach((c, ci)=>{
            const cell=document.createElement('input'); cell.type='text'; cell.placeholder=c; cell.value=r.values[ci]||'';
            cell.addEventListener('input', e=>{ r.values[ci]=e.target.value; saveToHidden(); });
            cellsBox.appendChild(cell);
          });
          wrap.appendChild(cellsBox);
          $rows.appendChild(wrap);
        });
        saveToHidden();
      }
      function renderPreview(){
        const cols=state.columns; let html='';
        html+='<thead><tr><th>Modell</th>'+cols.map(c=>'<th>'+esc(c)+'</th>').join('')+'</tr></thead><tbody>';
        state.rows.forEach(r=>{
          html+='<tr><td>'+esc(r.model||'')+'</td>';
          cols.forEach((c,ci)=> html+='<td>'+esc(r.values?.[ci]||'')+'</td>');
          html+='</tr>';
        });
        html+='</tbody>'; $preview.innerHTML = html;
      }
      function esc(s){ return (s||'').toString().replace(/[&<>"']/g, m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m])); }
      function renderAll(){ renderColumns(); renderRows(); renderPreview(); }

      document.getElementById('ccf-add-col').addEventListener('click', ()=>{
        const val = ($newCol.value||'').trim();
        if (!val) return;
        state.columns.push(val);
        state.rows.forEach(r=>{ ensureRowCellsLen(r); });
        $newCol.value = '';
        renderAll();
      });
      document.getElementById('ccf-clear-cols').addEventListener('click', ()=>{
        if (confirm('Alle Spalten löschen?')) {
          state.columns = [];
          state.rows.forEach(r=>{ r.values = []; });
          renderAll();
        }
      });
      document.getElementById('ccf-add-row').addEventListener('click', ()=>{
        const name = ($newRowName.value||'').trim();
        const r = { model: name, values: [] };
        ensureRowCellsLen(r);
        state.rows.push(r);
        $newRowName.value = '';
        renderAll();
      });
      document.getElementById('ccf-clear-rows').addEventListener('click', ()=>{
        if (confirm('Alle Modelle löschen?')) {
          state.rows = [];
          renderAll();
        }
      });

      renderAll();
    })();
    </script>
    <?php
    $out .= ob_get_clean();
    return $out;
});
/* Link "Neue Kategorie" im Admin-Bar (nur für Berechtigte) */
add_action('admin_bar_menu', function($bar){
    if (!is_user_logged_in() || !current_user_can('manage_categories')) return;
    $bar->add_node([
        'id'    => 'cc_new_category',
        'title' => 'Neue Kategorie',
        'href'  => home_url('/neue-kategorie/'),
    ]);
}, 81);

/* Link "Neues Produkt" в админ-баре (только для редакторов) */
add_action('admin_bar_menu', function($bar){
    if ( ! is_user_logged_in() || ! current_user_can('edit_posts') ) return;
    $new_url = home_url('/neues-produkt/'); // страница со шорткодом [catalog_new_item]
    $bar->add_node([
        'id'    => 'cc_new_product',
        'title' => 'Neues Produkt',
        'href'  => $new_url,
        'meta'  => ['class'=>'cc-adminbar-link']
    ]);
}, 80);

/* [catalog_edit_item id="123"] – Frontend-Bearbeitung inkl. Löschen */
add_shortcode('catalog_edit_item', function($atts){
    if ( ! is_user_logged_in() ) {
        return '<p>Bitte einloggen, um Produkte zu bearbeiten.</p>';
    }

    $id = isset($_GET['id']) ? absint($_GET['id']) : 0;
    if (!$id && !empty($atts['id'])) $id = absint($atts['id']);

    if (!$id || get_post_type($id) !== 'catalog_item') {
        return '<p>Kein gültiger Artikel gewählt.</p>';
    }
    if ( ! current_user_can('edit_post', $id) ) {
        return '<p>Keine Berechtigung.</p>';
    }

    $post = get_post($id);
    $title   = $post->post_title;
    $content = $post->post_content;
    $json    = get_post_meta($id, 'spec_matrix', true);
    $data    = json_decode($json ?: '', true);
    $columns = is_array($data['columns'] ?? null) ? array_values($data['columns']) : [];
    $rows    = is_array($data['rows'] ?? null)    ? array_values($data['rows'])    : [];

    $errors = [];
    $archive_url = get_post_type_archive_link('catalog_item') ?: home_url('/');

    /* Обработка сохранения */
    if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['cc_edit_action'])) {
        if (!isset($_POST['cc_edit_nonce']) || !wp_verify_nonce($_POST['cc_edit_nonce'], 'cc_edit_'.$id)) {
            $errors[] = 'Sicherheitsprüfung fehlgeschlagen.';
        } else {
            $action = sanitize_text_field($_POST['cc_edit_action']);
            if ($action === 'duplicate') {
                // Duplizieren-Logik
                $new_post_id = wp_insert_post([
                    'post_type'   => 'catalog_item',
                    'post_status' => 'publish',
                    'post_title'  => $title . ' (Kopie)',
                    'post_content'=> $content,
                    'post_author' => get_current_user_id(),
                ], true);
                if (is_wp_error($new_post_id)) {
                    $errors[] = 'Fehler beim Duplizieren: ' . esc_html($new_post_id->get_error_message());
                } else {
                    // Kategorien übernehmen
                    $curr_terms = wp_get_post_terms($id, 'catalog_category', ['fields'=>'ids']);
                    if (!is_wp_error($curr_terms) && $curr_terms) {
                        wp_set_post_terms($new_post_id, $curr_terms, 'catalog_category', false);
                    }
                    // spec_matrix übernehmen
                    if ($json) update_post_meta($new_post_id, 'spec_matrix', $json);
                    // Galerie übernehmen
                    $gallery_ids = cc_get_gallery_ids($id);
                    $new_gallery = [];
                    foreach ($gallery_ids as $gid) {
                        $new_gid = cc_duplicate_attachment($gid, $new_post_id);
                        if ($new_gid) $new_gallery[] = $new_gid;
                    }
                    if (!empty($new_gallery)) update_post_meta($new_post_id, 'gallery_ids', $new_gallery);
                    // Копируем миниатюру
                    if (has_post_thumbnail($id)) {
                        $thumb_id = get_post_thumbnail_id($id);
                        $new_thumb = cc_duplicate_attachment($thumb_id, $new_post_id);
                        if ($new_thumb) set_post_thumbnail($new_post_id, $new_thumb);
                    }
                    // Weiterleitung auf Bearbeiten нового Artikла
                    wp_safe_redirect( add_query_arg('id', $new_post_id, home_url('/produkt-bearbeiten/')) );
                    exit;
                }
            } else if ($action === 'delete') {
                if ( current_user_can('delete_post', $id) ) {
                    wp_trash_post($id); // или wp_delete_post($id, true)
                    wp_safe_redirect($archive_url);
                    exit;
                } else {
                    $errors[] = 'Keine Berechtigung zum Löschen.';
                }
            } else { // update
                $new_title   = isset($_POST['ccf_title']) ? sanitize_text_field($_POST['ccf_title']) : '';
                $new_content = isset($_POST['ccf_content']) ? wp_kses_post($_POST['ccf_content']) : '';
                $new_json    = isset($_POST['ccf_spec_json']) ? wp_unslash($_POST['ccf_spec_json']) : '';

                $arr = json_decode($new_json, true);
                if ($new_json !== '' && (!is_array($arr) || !isset($arr['columns']) || !isset($arr['rows']))) {
                    $errors[] = 'Die Eigenschaftstabelle ist ungültig (JSON).';
                }

                if (!$errors) {
                    wp_update_post([
                        'ID'           => $id,
                        'post_title'   => $new_title,
                        'post_content' => $new_content,
                    ]);
                    // Deutsch: Kategorien speichern/aktualisieren
                    if (isset($_POST['cc_item_categories'])) {
                        $term_ids = array_map('intval', (array) $_POST['cc_item_categories']);
                        wp_set_post_terms($id, $term_ids, 'catalog_category', false);
                    } else {
                        // nichts gewählt → Kategorien leeren
                        wp_set_post_terms($id, [], 'catalog_category', false);
                    }

                    // Bild: ersetzen или удалить
                    if (!empty($_FILES['ccf_image']['name'])) {
                        require_once ABSPATH.'wp-admin/includes/file.php';
                        require_once ABSPATH.'wp-admin/includes/image.php';
                        require_once ABSPATH.'wp-admin/includes/media.php';
                        $att_id = media_handle_upload('ccf_image', $id);
                        if (!is_wp_error($att_id)) {
                            set_post_thumbnail($id, $att_id);

                        } else {
                            $errors[] = 'Bild-Upload fehlgeschlagen: '.esc_html($att_id->get_error_message());
                        }
                    } elseif (!empty($_POST['ccf_remove_image'])) {
                        delete_post_thumbnail($id);
                    }
                    // ► Galerie: Entfernen markierter & Upload neuer Dateien
                    $curr = cc_get_gallery_ids($id);

                    // Entfernen
                    if (!empty($_POST['ccf_gallery_remove']) && is_array($_POST['ccf_gallery_remove'])) {
                        $remove = array_map('intval', (array) $_POST['ccf_gallery_remove']);
                        $curr   = array_values(array_diff($curr, $remove));
                    }

                    // Neue Uploads
                    if (!empty($_FILES['ccf_gallery']['name']) && is_array($_FILES['ccf_gallery']['name'])) {
                        require_once ABSPATH . 'wp-admin/includes/file.php';
                        require_once ABSPATH . 'wp-admin/includes/image.php';
                        require_once ABSPATH . 'wp-admin/includes/media.php';

                        $files = cc_files_to_array($_FILES['ccf_gallery']);
                        foreach ($files as $i => $file) {
                            $key = 'ccf_gallery_single_' . $i;
                            $_FILES[$key] = $file;
                            $att_id = media_handle_upload($key, $id);
                            if (!is_wp_error($att_id)) $curr[] = (int) $att_id;
                            unset($_FILES[$key]);
                        }
                    }

                    // Speichern/Leeren
                    $curr = array_values(array_unique(array_filter(array_map('intval', $curr))));
                    if (!empty($curr)) update_post_meta($id, 'gallery_ids', $curr);
                    else              delete_post_meta($id, 'gallery_ids');

                    if ($new_json !== '') {
                        update_post_meta($id, 'spec_matrix', $new_json);
                    } else {
                        delete_post_meta($id, 'spec_matrix');
                    }

                    wp_safe_redirect( get_permalink($id) );
                    exit;
                }
            }
        }
    }

    ob_start(); ?>


    <?php if ($errors): ?>
      <div class="notice notice-error" style="border-left:4px solid #d63638;padding:.6rem 1rem;margin:0 0 1rem;">
        <strong>Fehler:</strong>
        <ul style="margin:.4rem 0 0 1.2rem;">
          <?php foreach ($errors as $e) echo '<li>'.esc_html($e).'</li>'; ?>
        </ul>
      </div>
    <?php endif; ?>

    <form class="ccf-form" method="post" enctype="multipart/form-data">
      <?php wp_nonce_field('cc_edit_'.$id, 'cc_edit_nonce'); ?>

      <div class="ccf-field">
        <label for="ccf_title">Titel</label>
        <input type="text" id="ccf_title" name="ccf_title" value="<?php echo esc_attr($title); ?>" required>
      </div>

      <div class="ccf-field">
        <label for="ccf_content">Beschreibung</label>
        <textarea id="ccf_content" name="ccf_content" rows="6"><?php echo esc_textarea($content); ?></textarea>
      </div>
      
      <?php
        // Deutsch: alle Kategorien + aktuell zugewiesene ermitteln
        $cc_all_terms = get_terms([
          'taxonomy'   => 'catalog_category',
          'hide_empty' => false,
          'orderby'    => 'name',
          'order'      => 'ASC',
        ]);
        $cc_curr_terms = wp_get_post_terms($id, 'catalog_category', ['fields'=>'ids']);
        if (is_wp_error($cc_curr_terms)) $cc_curr_terms = [];
      ?>
      <div class="ccf-field">
        <label for="cc_item_categories">Kategorie(n)</label>
        <select id="cc_item_categories" name="cc_item_categories[]" multiple>
          <?php foreach($cc_all_terms as $t): ?>
            <option value="<?php echo esc_attr($t->term_id); ?>"
                    <?php selected(in_array($t->term_id, $cc_curr_terms, true)); ?>>
              <?php echo esc_html($t->name); ?>
            </option>
          <?php endforeach; ?>
        </select>
        <small class="cc-muted">Mehrfachauswahl möglich (Strg/Cmd gedrückt halten).</small>
      </div>

      <div class="ccf-field">
        <label for="ccf_image">Produktfoto</label>
        <?php if (has_post_thumbnail($id)): ?>
          <div class="ccf-thumb"><?php echo get_the_post_thumbnail($id, 'medium'); ?></div>
          <label><input type="checkbox" name="ccf_remove_image" value="1"> Aktuelles Bild entfernen</label><br>
        <?php endif; ?>
        <input type="file" id="ccf_image" name="ccf_image" accept="image/*">
      </div>

      <div class="ccf-field">
        <label>Eigenschaftstabelle (Modelle × Attribute)</label>
        <input type="hidden" id="ccf-spec-json" name="ccf_spec_json" value="<?php echo esc_attr($json); ?>"/>
        <div class="cc-builder" id="ccf-builder">
          <h4>Spalten (Attribute)</h4>
          <div class="cc-tags" id="ccf-columns-tags"></div>
          <div class="cc-row">
            <input type="text" id="ccf-new-col" placeholder="z. B. Größe, Gewicht, Material …">
            <button type="button" class="button" id="ccf-add-col">+ Spalte hinzufügen</button>
            <button type="button" class="button" id="ccf-clear-cols">Leeren</button>
          </div>

          <h4>Zeilen (Modelle)</h4>
          <div id="ccf-rows"></div>
          <div class="cc-row">
            <input type="text" id="ccf-new-row-name" placeholder="Modellname (z. B. Modell A)">
            <button type="button" class="button" id="ccf-add-row">+ Modell hinzufügen</button>
            <button type="button" class="button" id="ccf-clear-rows">Alle Modelle leeren</button>
          </div>

          <h4>Vorschau</h4>
          <table class="cc-table-preview" id="ccf-preview"></table>
        </div>
      </div>
                    <?php
        $gallery_ids = cc_get_gallery_ids($id);
        ?>
        <div class="ccf-field">
        <label>Galerie (zusätzliche Bilder)</label>

        <?php if (!empty($gallery_ids)): ?>
            <div class="ccf-gallery-manage">
            <?php foreach ($gallery_ids as $gid): 
                $thumb = wp_get_attachment_image_url($gid, 'thumbnail'); ?>
                <label class="ccf-g-item">
                <?php if ($thumb): ?><img src="<?php echo esc_url($thumb); ?>" alt=""><?php endif; ?>
                <div class="ccf-g-actions">
                    <input type="checkbox" name="ccf_gallery_remove[]" value="<?php echo esc_attr($gid); ?>" id="grem_<?php echo esc_attr($gid); ?>">
                    <label for="grem_<?php echo esc_attr($gid); ?>">Entfernen</label>
                </div>
                </label>
            <?php endforeach; ?>
            </div>
        <?php else: ?>
            <p class="cc-muted">Keine Galerie-Bilder vorhanden.</p>
        <?php endif; ?>

        <div class="cc-row" style="margin-top:8px;">
            <input type="file" name="ccf_gallery[]" accept="image/*" multiple>
            <small class="cc-muted">Weitere Bilder hinzufügen (mehrfach möglich).</small>
        </div>
        </div>

      <div class="cc-actions-row">
        <button type="submit" name="cc_edit_action" value="update" class="button button-primary">Änderungen speichern</button>
        <button type="submit" name="cc_edit_action" value="duplicate" class="button">Duplizieren</button>
        <?php if ( current_user_can('delete_post', $id) ): ?>
          <button type="submit" name="cc_edit_action" value="delete" class="button" onclick="return confirm('Diesen Artikel wirklich löschen?');">Artikel löschen</button>
        <?php endif; ?>
        <a class="button" href="<?php echo esc_url( get_permalink($id) ); ?>">Abbrechen</a>
      </div>
    </form>

    <script>
    (function(){
      const initial = {
        columns: <?php echo json_encode($columns); ?>,
        rows: <?php echo json_encode($rows); ?>   // [{model:'', values:[...]}]
      };
      const state = { columns: initial.columns.slice(), rows: initial.rows.slice() };
      const $json = document.getElementById('ccf-spec-json');
      const $colTags = document.getElementById('ccf-columns-tags');
      const $newCol = document.getElementById('ccf-new-col');
      const $rows = document.getElementById('ccf-rows');
      const $newRowName = document.getElementById('ccf-new-row-name');
      const $preview = document.getElementById('ccf-preview');

      function saveToHidden(){ $json.value = JSON.stringify({columns: state.columns, rows: state.rows}); }
      function renderColumns(){
        $colTags.innerHTML = '';
        state.columns.forEach((c, i)=>{
          const span = document.createElement('span');
          span.className = 'cc-tag';
          span.textContent = c;
          span.title = 'Klicken zum Löschen';
          span.style.cursor = 'pointer';
          span.addEventListener('click', ()=>{
            state.columns.splice(i,1);
            state.rows.forEach(r=>{ if (Array.isArray(r.values)) r.values.splice(i,1); });
            renderAll();
          });
          $colTags.appendChild(span);
        });
        saveToHidden();
      }
      function ensureRowCellsLen(r){
        r.values = Array.isArray(r.values) ? r.values : [];
        while (r.values.length < state.columns.length) r.values.push('');
        if (r.values.length > state.columns.length) r.values = r.values.slice(0, state.columns.length);
      }
      function renderRows(){
        $rows.innerHTML = '';
        state.rows.forEach((r, idx)=>{
          ensureRowCellsLen(r);
          const wrap = document.createElement('div'); wrap.className='cc-row';
          const name = document.createElement('input'); name.type='text'; name.value=r.model||''; name.placeholder='Modellname';
          name.addEventListener('input', e=>{ r.model=e.target.value; saveToHidden(); });
          wrap.appendChild(name);

          const del = document.createElement('button'); del.type='button'; del.className='button'; del.textContent='Löschen';
          del.addEventListener('click', ()=>{ state.rows.splice(idx,1); renderAll(); });
          wrap.appendChild(del);

          const cellsBox = document.createElement('div');
          cellsBox.style.display='grid'; cellsBox.style.gridTemplateColumns=`repeat(${Math.max(1,state.columns.length)}, minmax(120px,1fr))`; cellsBox.style.gap='6px';
          state.columns.forEach((c, ci)=>{
            const cell=document.createElement('input'); cell.type='text'; cell.placeholder=c; cell.value=r.values[ci]||'';
            cell.addEventListener('input', e=>{ r.values[ci]=e.target.value; saveToHidden(); });
            cellsBox.appendChild(cell);
          });
          wrap.appendChild(cellsBox);
          $rows.appendChild(wrap);
        });
        saveToHidden();
      }
      function renderPreview(){
        const cols=state.columns; let html='';
        html+='<thead><tr><th>Modell</th>'+cols.map(c=>'<th>'+esc(c)+'</th>').join('')+'</tr></thead><tbody>';
        state.rows.forEach(r=>{
          html+='<tr><td>'+esc(r.model||'')+'</td>';
          cols.forEach((c,ci)=> html+='<td>'+esc(r.values?.[ci]||'')+'</td>');
          html+='</tr>';
        });
        html+='</tbody>'; $preview.innerHTML = html;
      }
      function esc(s){ return (s||'').toString().replace(/[&<>"']/g, m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m])); }
      function renderAll(){ renderColumns(); renderRows(); renderPreview(); }

      document.getElementById('ccf-add-col').addEventListener('click', ()=>{
        const val = ($newCol.value||'').trim();
        if (!val) return;
        state.columns.push(val);
        state.rows.forEach(r=>{ ensureRowCellsLen(r); });
        $newCol.value = '';
        renderAll();
      });
      document.getElementById('ccf-clear-cols').addEventListener('click', ()=>{
        if (confirm('Alle Spalten löschen?')) {
          state.columns = [];
          state.rows.forEach(r=>{ r.values = []; });
          renderAll();
        }
      });
      document.getElementById('ccf-add-row').addEventListener('click', ()=>{
        const name = ($newRowName.value||'').trim();
        const r = { model: name, values: [] };
        ensureRowCellsLen(r);
        state.rows.push(r);
        $newRowName.value = '';
        renderAll();
      });
      document.getElementById('ccf-clear-rows').addEventListener('click', ()=>{
        if (confirm('Alle Modelle löschen?')) {
          state.rows = [];
          renderAll();
        }
      });

      renderAll();
    })();
    </script>
    <?php
    return ob_get_clean();
});
/* -----------------------------------------------------------
   Eigenes Template für Kategorie-Archiv
   ----------------------------------------------------------- */


add_filter('the_content', function($content) {
    // Nur auf Kategorieseiten der Taxonomie catalog_category und nur für Admins
    if (is_tax('catalog_category') && current_user_can('manage_categories')) {
        $term = get_queried_object();
        if ($term && !is_wp_error($term)) {
            $edit_url = add_query_arg('id', $term->term_id, home_url('/kategorie-bearbeiten/'));
            $btn = '<div class="cc-archive-actions" style="margin:8px 0 12px;">
                        <a class="button button-small cc-cat-edit-btn" href="'.esc_url($edit_url).'">Bearbeiten</a>
                    </div>';
            // Button vor dem Inhalt einfügen
            return $btn . $content;
        }
    }
    return $content;
});



// Клонирование вложения (медиафайла) для нового поста
function cc_duplicate_attachment($att_id, $new_post_id) {
    $att = get_post($att_id);
    if (!$att || $att->post_type !== 'attachment') return 0;
    $file = get_attached_file($att_id);
    if (!file_exists($file)) return 0;
    $info = pathinfo($file);
    $new_file = $info['dirname'] . '/' . wp_unique_filename($info['dirname'], $info['basename']);
    if (!copy($file, $new_file)) return 0;
    $filetype = wp_check_filetype($new_file);
    $attachment = [
        'post_mime_type' => $filetype['type'],
        'post_title'     => $att->post_title,
        'post_content'   => '',
        'post_status'    => 'inherit',
        'post_author'    => get_current_user_id(),
    ];
    $new_att_id = wp_insert_attachment($attachment, $new_file, $new_post_id);
    require_once ABSPATH . 'wp-admin/includes/image.php';
    $attach_data = wp_generate_attachment_metadata($new_att_id, $new_file);
    wp_update_attachment_metadata($new_att_id, $attach_data);
    return $new_att_id;
}




