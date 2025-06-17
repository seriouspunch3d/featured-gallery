<?php
/**
 * Classe principale del plugin Featured Gallery Tags
 * File: includes/class-featured-gallery.php
 * 
 * Versione 2.0.0 - Ottimizzata e sicura per siti con migliaia di post
 * Include tutte le patch di sicurezza
 */

if (!defined('ABSPATH')) {
    exit;
}

class FeaturedGalleryTags {
    
    // Cache in memoria per la richiesta corrente con limite
    private static $gallery_cache = array();
    private static $posts_with_galleries = null;
    private static $cache_limit = 100;
    
    public function __construct() {
        // Hooks con priorità ottimizzate
        add_action('init', array($this, 'init_hooks'), 5);
        
        // Cache warming per LiteSpeed
        add_action('litespeed_purged_all', array($this, 'clear_gallery_cache'));
        
        // Cleanup periodico della cache
        add_action('shutdown', array($this, 'cleanup_memory_cache'));
    }
    
    public function init_hooks() {
        // Carica hooks solo dove necessario
        if (is_admin()) {
            add_action('add_meta_boxes', array($this, 'add_meta_boxes'));
            add_action('save_post', array($this, 'save_meta_data'), 10, 2);
            add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        } else {
            // Frontend: carica solo se necessario
            add_action('wp', array($this, 'maybe_load_frontend_hooks'));
        }
    }
    
    public function maybe_load_frontend_hooks() {
        // Controlla se la pagina corrente potrebbe avere gallery
        if ($this->should_load_gallery_assets()) {
            add_filter('post_thumbnail_html', array($this, 'enhance_featured_media'), 10, 5);
            add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_assets'));
            
            // Pre-carica i dati delle gallery per questa pagina
            $this->preload_galleries_for_current_page();
        }
    }
    
    private function should_load_gallery_assets() {
        // Non caricare su pagine che sicuramente non hanno gallery
        if (is_page() || is_404() || is_search()) {
            return false;
        }
        
        // Carica su home, archivi e singoli post
        return is_home() || is_archive() || is_single() || is_front_page();
    }
    
    private function preload_galleries_for_current_page() {
        global $wp_query;
        
        if (!$wp_query->posts || empty($wp_query->posts)) {
            return;
        }
        
        // Estrai tutti gli ID dei post nella pagina corrente
        $post_ids = wp_list_pluck($wp_query->posts, 'ID');
        
        // Carica tutti i meta in una sola query
        $this->bulk_load_gallery_data($post_ids);
    }
    
    /**
     * Carica dati gallery in bulk con query preparata sicura
     */
    private function bulk_load_gallery_data($post_ids) {
        global $wpdb;
        
        if (empty($post_ids)) {
            return;
        }
        
        // Sanitizza gli ID
        $post_ids = array_map('intval', $post_ids);
        $post_ids = array_filter($post_ids, function($id) {
            return $id > 0;
        });
        
        if (empty($post_ids)) {
            return;
        }
        
        // Controlla cache persistente
        $cache_key = 'fgt_bulk_' . md5(implode('_', $post_ids));
        $cached_data = wp_cache_get($cache_key, 'fgt_gallery');
        
        if ($cached_data !== false && is_array($cached_data)) {
            $this->merge_cache($cached_data);
            return;
        }
        
        // Query preparata in modo sicuro
        $meta_keys = array(
            '_use_featured_gallery',
            '_featured_gallery_ids',
            '_show_trailer_tag',
            '_show_completo_tag'
        );
        
        // Costruisci placeholder per post IDs
        $placeholders = implode(',', array_fill(0, count($post_ids), '%d'));
        
        // Costruisci placeholder per meta keys
        $meta_placeholders = implode(',', array_fill(0, count($meta_keys), '%s'));
        
        // Query preparata
        $query = $wpdb->prepare(
            "SELECT post_id, meta_key, meta_value
             FROM {$wpdb->postmeta}
             WHERE post_id IN ($placeholders)
             AND meta_key IN ($meta_placeholders)
             ORDER BY post_id",
            array_merge($post_ids, $meta_keys)
        );
        
        $results = $wpdb->get_results($query);
        
        // Organizza i risultati per post_id
        $gallery_data = array();
        foreach ($results as $row) {
            $post_id = intval($row->post_id);
            
            if (!isset($gallery_data[$post_id])) {
                $gallery_data[$post_id] = array();
            }
            
            // Deserializza array se necessario con validazione
            if ($row->meta_key === '_featured_gallery_ids') {
                $value = maybe_unserialize($row->meta_value);
                if (is_array($value)) {
                    // Sanitizza array di ID
                    $value = array_map('intval', $value);
                    $value = array_filter($value, function($id) {
                        return $id > 0;
                    });
                }
                $gallery_data[$post_id][$row->meta_key] = $value;
            } else {
                $gallery_data[$post_id][$row->meta_key] = sanitize_text_field($row->meta_value);
            }
        }
        
        // Salva in cache con limite
        $this->merge_cache($gallery_data);
        
        // Cache persistente
        wp_cache_set($cache_key, $gallery_data, 'fgt_gallery', 3600); // Cache per 1 ora
    }
    
    /**
     * Merge dati in cache con limite di memoria
     */
    private function merge_cache($new_data) {
        // Limita dimensione cache in memoria
        if (count(self::$gallery_cache) + count($new_data) > self::$cache_limit) {
            // Mantieni solo gli ultimi N elementi
            $keep_count = max(0, self::$cache_limit - count($new_data));
            self::$gallery_cache = array_slice(self::$gallery_cache, -$keep_count, null, true);
        }
        
        self::$gallery_cache = array_merge(self::$gallery_cache, $new_data);
    }
    
    /**
     * Cleanup memoria cache alla fine della richiesta
     */
    public function cleanup_memory_cache() {
        if (count(self::$gallery_cache) > self::$cache_limit) {
            self::$gallery_cache = array_slice(self::$gallery_cache, -(self::$cache_limit / 2), null, true);
        }
    }
    
    public function add_meta_boxes() {
        add_meta_box(
            'featured-gallery',
            __('Featured Gallery (5 Immagini)', 'featured-gallery-tags'),
            array($this, 'gallery_meta_box_callback'),
            'post',
            'side',
            'high'
        );
        
        add_meta_box(
            'post-tags-box',
            __('Opzioni Targhette', 'featured-gallery-tags'),
            array($this, 'tags_meta_box_callback'),
            'post',
            'side',
            'high'
        );
    }
    
    public function gallery_meta_box_callback($post) {
        wp_nonce_field('featured_gallery_nonce', 'featured_gallery_nonce_field');
        
        $use_gallery = get_post_meta($post->ID, '_use_featured_gallery', true);
        $gallery_ids = get_post_meta($post->ID, '_featured_gallery_ids', true);
        
        // Validazione e sanitizzazione
        if (!is_array($gallery_ids)) {
            $gallery_ids = array();
        } else {
            $gallery_ids = array_map('intval', $gallery_ids);
            $gallery_ids = array_filter($gallery_ids, function($id) {
                return $id > 0;
            });
        }
        ?>
        <div class="fgt-gallery-wrapper">
            <p>
                <label>
                    <input type="checkbox" name="use_featured_gallery" value="1" <?php checked($use_gallery, '1'); ?> />
                    <?php esc_html_e('Usa Gallery invece della Featured Image', 'featured-gallery-tags'); ?>
                </label>
            </p>
            
            <div id="fgt-gallery-container" style="<?php echo $use_gallery ? '' : 'display:none;'; ?>">
                <p><strong><?php esc_html_e('Seleziona 5 immagini:', 'featured-gallery-tags'); ?></strong></p>
                
                <div id="fgt-gallery-preview">
                    <?php 
                    for ($i = 0; $i < 5; $i++) {
                        $image_id = isset($gallery_ids[$i]) ? $gallery_ids[$i] : '';
                        $image_url = '';
                        
                        if ($image_id && get_post_type($image_id) === 'attachment') {
                            $image_url = wp_get_attachment_image_url($image_id, 'thumbnail');
                        }
                        ?>
                        <div class="fgt-image-slot" data-index="<?php echo esc_attr($i); ?>">
                            <?php if ($image_url): ?>
                                <img src="<?php echo esc_url($image_url); ?>" alt="" />
                                <button type="button" class="fgt-remove-image" data-index="<?php echo esc_attr($i); ?>">×</button>
                            <?php else: ?>
                                <div class="fgt-placeholder"><?php echo esc_html($i + 1); ?></div>
                            <?php endif; ?>
                            <input type="hidden" name="gallery_image_ids[]" value="<?php echo esc_attr($image_id); ?>" />
                        </div>
                        <?php
                    }
                    ?>
                </div>
                
                <button type="button" id="fgt-select-images" class="button">
                    <?php esc_html_e('Seleziona Immagini', 'featured-gallery-tags'); ?>
                </button>
            </div>
        </div>
        <?php
    }
    
    public function tags_meta_box_callback($post) {
        $show_trailer = get_post_meta($post->ID, '_show_trailer_tag', true);
        $show_completo = get_post_meta($post->ID, '_show_completo_tag', true);
        ?>
        <p>
            <label>
                <input type="checkbox" name="show_trailer_tag" value="1" <?php checked($show_trailer, '1'); ?> />
                <?php esc_html_e('Mostra targhetta "TRAILER"', 'featured-gallery-tags'); ?>
            </label>
        </p>
        <p>
            <label>
                <input type="checkbox" name="show_completo_tag" value="1" <?php checked($show_completo, '1'); ?> />
                <?php esc_html_e('Mostra targhetta "COMPLETO"', 'featured-gallery-tags'); ?>
            </label>
        </p>
        <?php
    }
    
    /**
     * Salva meta data con validazione e sanitizzazione complete
     */
    public function save_meta_data($post_id, $post) {
        // Verifica nonce
        if (!isset($_POST['featured_gallery_nonce_field']) || 
            !wp_verify_nonce($_POST['featured_gallery_nonce_field'], 'featured_gallery_nonce')) {
            return;
        }
        
        // Skip autosave
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        
        // Verifica permessi
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }
        
        // Verifica post type
        if (get_post_type($post_id) !== 'post') {
            return;
        }
        
        // Sanitizza checkboxes
        $use_gallery = isset($_POST['use_featured_gallery']) ? '1' : '';
        $show_trailer = isset($_POST['show_trailer_tag']) ? '1' : '';
        $show_completo = isset($_POST['show_completo_tag']) ? '1' : '';
        
        // Salva settings
        update_post_meta($post_id, '_use_featured_gallery', $use_gallery);
        update_post_meta($post_id, '_show_trailer_tag', $show_trailer);
        update_post_meta($post_id, '_show_completo_tag', $show_completo);
        
        // Sanitizza e valida gallery IDs
        $gallery_ids = array();
        
        if (isset($_POST['gallery_image_ids']) && is_array($_POST['gallery_image_ids'])) {
            foreach ($_POST['gallery_image_ids'] as $id) {
                // Sanitizza come intero
                $id = intval($id);
                
                // Valida che sia un ID positivo
                if ($id > 0) {
                    // Verifica che l'attachment esista e appartenga a questo sito
                    $attachment = get_post($id);
                    if ($attachment && $attachment->post_type === 'attachment') {
                        // Verifica che sia un'immagine
                        if (wp_attachment_is_image($id)) {
                            $gallery_ids[] = $id;
                        }
                    }
                }
            }
            
            // Limita a massimo 5 immagini
            $gallery_ids = array_slice($gallery_ids, 0, 5);
        }
        
        update_post_meta($post_id, '_featured_gallery_ids', $gallery_ids);
        
        // Invalida cache per questo post
        unset(self::$gallery_cache[$post_id]);
        
        // Invalida cache transient correlate
        delete_transient('fgt_post_' . $post_id);
        
        // Invalida cache LiteSpeed per questo post
        if (defined('LSCWP_V')) {
            do_action('litespeed_purge_post', $post_id);
        }
    }
    
    public function enhance_featured_media($html, $post_id, $post_thumbnail_id, $size, $attr) {
        // Solo frontend e solo per post
        if (is_admin() || get_post_type($post_id) !== 'post') {
            return $html;
        }
        
        // Controlla prima la cache in memoria
        $use_gallery = $this->get_cached_meta($post_id, '_use_featured_gallery');
        
        if (!$use_gallery) {
            return $html;
        }
        
        // Genera HTML con dati cached
        return $this->generate_gallery_html($html, $post_id, $size);
    }
    
    /**
     * Recupera meta con cache sicura
     */
    private function get_cached_meta($post_id, $meta_key) {
        $post_id = intval($post_id);
        
        // Validazione
        if ($post_id <= 0) {
            return false;
        }
        
        // Prima controlla cache in memoria
        if (isset(self::$gallery_cache[$post_id][$meta_key])) {
            return self::$gallery_cache[$post_id][$meta_key];
        }
        
        // Controlla cache transient
        $transient_key = 'fgt_meta_' . $post_id . '_' . md5($meta_key);
        $cached_value = get_transient($transient_key);
        
        if ($cached_value !== false) {
            return $cached_value;
        }
        
        // Fallback a get_post_meta
        $value = get_post_meta($post_id, $meta_key, true);
        
        // Salva in cache memoria
        if (!isset(self::$gallery_cache[$post_id])) {
            self::$gallery_cache[$post_id] = array();
        }
        self::$gallery_cache[$post_id][$meta_key] = $value;
        
        // Salva in transient per 1 ora
        set_transient($transient_key, $value, HOUR_IN_SECONDS);
        
        return $value;
    }
    
    /**
     * Genera HTML gallery con escape e validazione completi
     */
    private function generate_gallery_html($original_html, $post_id, $size) {
        $post_id = intval($post_id);
        
        $show_trailer = $this->get_cached_meta($post_id, '_show_trailer_tag');
        $show_completo = $this->get_cached_meta($post_id, '_show_completo_tag');
        $gallery_ids = $this->get_cached_meta($post_id, '_featured_gallery_ids');
        
        // Validazione gallery IDs
        if (!is_array($gallery_ids)) {
            $gallery_ids = array();
        } else {
            $gallery_ids = array_map('intval', $gallery_ids);
            $gallery_ids = array_filter($gallery_ids, function($id) {
                return $id > 0;
            });
            $gallery_ids = array_slice($gallery_ids, 0, 5); // Max 5 immagini
        }
        
        // Inizio output
        $output = '<div class="fgt-media-wrapper" data-post-id="' . esc_attr($post_id) . '">';
        
        if (!empty($gallery_ids)) {
            // JSON encode sicuro con flag di sicurezza
            $gallery_ids_json = esc_attr(wp_json_encode(
                $gallery_ids, 
                JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
            ));
            
            $output .= '<div class="fgt-gallery" data-gallery-ids="' . $gallery_ids_json . '">';
            $output .= '<div class="fgt-images">';
            
            // Prima immagine sempre caricata
            $first_id = $gallery_ids[0];
            $first_image_url = wp_get_attachment_image_url($first_id, $size);
            
            if ($first_image_url) {
                // Ottieni alt text
                $alt_text = get_post_meta($first_id, '_wp_attachment_image_alt', true);
                
                $output .= sprintf(
                    '<img src="%s" class="fgt-image active" data-index="0" alt="%s" loading="eager" />',
                    esc_url($first_image_url),
                    esc_attr($alt_text)
                );
                
                // Altre immagini con lazy loading
                for ($i = 1; $i < count($gallery_ids); $i++) {
                    $image_id = $gallery_ids[$i];
                    $image_url = wp_get_attachment_image_url($image_id, $size);
                    
                    if ($image_url) {
                        $alt_text = get_post_meta($image_id, '_wp_attachment_image_alt', true);
                        
                        $output .= sprintf(
                            '<img data-src="%s" class="fgt-image" data-index="%d" alt="%s" loading="lazy" />',
                            esc_url($image_url),
                            esc_attr($i),
                            esc_attr($alt_text)
                        );
                    }
                }
            } else {
                // Fallback se prima immagine non disponibile
                $output .= $original_html;
            }
            
            $output .= '</div>'; // .fgt-images
            
            // Indicatori
            if (count($gallery_ids) > 1) {
                $output .= '<div class="fgt-indicators">';
                
                for ($i = 0; $i < count($gallery_ids); $i++) {
                    $active_class = $i === 0 ? ' active' : '';
                    $aria_label = sprintf(
                        /* translators: %d: image number */
                        __('Immagine %d di %d', 'featured-gallery-tags'),
                        $i + 1,
                        count($gallery_ids)
                    );
                    
                    $output .= sprintf(
                        '<button class="fgt-indicator%s" data-index="%d" aria-label="%s" tabindex="-1"></button>',
                        esc_attr($active_class),
                        esc_attr($i),
                        esc_attr($aria_label)
                    );
                }
                
                $output .= '</div>'; // .fgt-indicators
            }
            
            $output .= '</div>'; // .fgt-gallery
        } else {
            // Nessuna gallery, usa HTML originale
            $output .= $original_html;
        }
        
        // Targhette
        if ($show_trailer || $show_completo) {
            $output .= '<div class="fgt-tags">';
            
            if ($show_trailer) {
                $output .= sprintf(
                    '<span class="fgt-tag fgt-tag-trailer" role="status">%s</span>',
                    esc_html__('TRAILER', 'featured-gallery-tags')
                );
            }
            
            if ($show_completo) {
                $output .= sprintf(
                    '<span class="fgt-tag fgt-tag-completo" role="status">%s</span>',
                    esc_html__('COMPLETO', 'featured-gallery-tags')
                );
            }
            
            $output .= '</div>'; // .fgt-tags
        }
        
        $output .= '</div>'; // .fgt-media-wrapper
        
        return $output;
    }
    
    public function enqueue_frontend_assets() {
        // CSS con versioning basato su file modification time
        $css_path = FGT_PLUGIN_DIR . 'assets/css/gallery.css';
        $css_version = file_exists($css_path) ? filemtime($css_path) : FGT_VERSION;
        
        wp_enqueue_style(
            'fgt-gallery-style',
            FGT_PLUGIN_URL . 'assets/css/gallery.css',
            array(),
            $css_version
        );
        
        // JS ottimizzato con versioning
        $js_path = FGT_PLUGIN_DIR . 'assets/js/gallery-optimized.js';
        $js_version = file_exists($js_path) ? filemtime($js_path) : FGT_VERSION;
        
        wp_enqueue_script(
            'fgt-gallery-script',
            FGT_PLUGIN_URL . 'assets/js/gallery-optimized.js',
            array('jquery'),
            $js_version,
            true
        );
        
        // Passa configurazione al JS in modo sicuro
        wp_localize_script('fgt-gallery-script', 'fgtConfig', array(
            'lazyLoad' => true,
            'autoplayDelay' => 500,
            'transitionSpeed' => 1500,
            'debug' => defined('WP_DEBUG') && WP_DEBUG
        ));
    }
    
    public function enqueue_admin_assets($hook) {
        // Solo su edit/new post
        if ($hook !== 'post.php' && $hook !== 'post-new.php') {
            return;
        }
        
        // Solo per post type 'post'
        global $post;
        if (!$post || get_post_type($post) !== 'post') {
            return;
        }
        
        // Media uploader
        wp_enqueue_media();
        
        // Admin JS con versioning
        $js_path = FGT_PLUGIN_DIR . 'assets/js/admin.js';
        $js_version = file_exists($js_path) ? filemtime($js_path) : FGT_VERSION;
        
        wp_enqueue_script(
            'fgt-admin-script',
            FGT_PLUGIN_URL . 'assets/js/admin.js',
            array('jquery'),
            $js_version,
            true
        );
        
        // Localizzazione stringhe
        wp_localize_script('fgt-admin-script', 'fgtAdmin', array(
            'strings' => array(
                'selectImages' => __('Seleziona immagini per la gallery', 'featured-gallery-tags'),
                'useImages' => __('Usa queste immagini', 'featured-gallery-tags'),
                'confirmRemove' => __('Rimuovere questa immagine?', 'featured-gallery-tags'),
                'noImages' => __('La gallery è attiva ma non ci sono immagini. Continuare?', 'featured-gallery-tags')
            ),
            'debug' => defined('WP_DEBUG') && WP_DEBUG
        ));
        
        // Admin CSS
        $css_path = FGT_PLUGIN_DIR . 'assets/css/gallery.css';
        $css_version = file_exists($css_path) ? filemtime($css_path) : FGT_VERSION;
        
        wp_enqueue_style(
            'fgt-admin-style',
            FGT_PLUGIN_URL . 'assets/css/gallery.css',
            array(),
            $css_version
        );
    }
    
    /**
     * Gestione cache - pulizia completa
     */
    public function clear_gallery_cache() {
        // Pulisci cache in memoria
        self::$gallery_cache = array();
        self::$posts_with_galleries = null;
        
        // Pulisci tutti i transients del plugin
        global $wpdb;
        $wpdb->query(
            "DELETE FROM {$wpdb->options} 
             WHERE option_name LIKE '_transient_fgt_%' 
             OR option_name LIKE '_transient_timeout_fgt_%'"
        );
        
        // Pulisci object cache se disponibile
        if (function_exists('wp_cache_flush_group')) {
            wp_cache_flush_group('fgt_gallery');
        }
    }
}

// Hook per supporto ESI LiteSpeed (Edge Side Includes)
add_action('init', function() {
    if (defined('LSCWP_V')) {
        // Registra ESI per gallery
        add_action('litespeed_esi_load-fgt_gallery', function($params) {
            if (!isset($params['post_id']) || !isset($params['size'])) {
                return;
            }
            
            $post_id = intval($params['post_id']);
            $size = sanitize_text_field($params['size']);
            
            if ($post_id > 0) {
                $gallery = new FeaturedGalleryTags();
                echo $gallery->generate_gallery_html('', $post_id, $size);
            }
        });
    }
});
