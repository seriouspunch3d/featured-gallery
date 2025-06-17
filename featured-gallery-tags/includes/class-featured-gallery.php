<?php
/**
 * Classe principale del plugin Featured Gallery Tags
 * File: includes/class-featured-gallery.php
 * 
 * Versione ottimizzata per siti con migliaia di post
 */

if (!defined('ABSPATH')) {
    exit;
}

class FeaturedGalleryTags {
    
    // Cache in memoria per la richiesta corrente
    private static $gallery_cache = array();
    private static $posts_with_galleries = null;
    
    public function __construct() {
        // Hooks con priorità ottimizzate
        add_action('init', array($this, 'init_hooks'), 5);
        
        // Cache warming per LiteSpeed
        add_action('litespeed_purged_all', array($this, 'clear_gallery_cache'));
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
        return is_home() || is_archive() || is_single();
    }
    
    private function preload_galleries_for_current_page() {
        global $wp_query;
        
        if (!$wp_query->posts) {
            return;
        }
        
        // Estrai tutti gli ID dei post nella pagina corrente
        $post_ids = wp_list_pluck($wp_query->posts, 'ID');
        
        // Carica tutti i meta in una sola query
        $this->bulk_load_gallery_data($post_ids);
    }
    
    private function bulk_load_gallery_data($post_ids) {
        global $wpdb;
        
        if (empty($post_ids)) {
            return;
        }
        
        // Controlla cache persistente
        $cache_key = 'fgt_bulk_' . md5(implode('_', $post_ids));
        $cached_data = wp_cache_get($cache_key, 'fgt_gallery');
        
        if ($cached_data !== false) {
            self::$gallery_cache = array_merge(self::$gallery_cache, $cached_data);
            return;
        }
        
        // Query ottimizzata per caricare tutti i meta in una volta
        $post_ids_placeholder = implode(',', array_map('intval', $post_ids));
        
        $results = $wpdb->get_results("
            SELECT post_id, meta_key, meta_value
            FROM $wpdb->postmeta
            WHERE post_id IN ($post_ids_placeholder)
            AND meta_key IN ('_use_featured_gallery', '_featured_gallery_ids', '_show_trailer_tag', '_show_completo_tag')
            ORDER BY post_id
        ");
        
        // Organizza i risultati per post_id
        $gallery_data = array();
        foreach ($results as $row) {
            if (!isset($gallery_data[$row->post_id])) {
                $gallery_data[$row->post_id] = array();
            }
            
            // Deserializza array se necessario
            if ($row->meta_key === '_featured_gallery_ids') {
                $gallery_data[$row->post_id][$row->meta_key] = maybe_unserialize($row->meta_value);
            } else {
                $gallery_data[$row->post_id][$row->meta_key] = $row->meta_value;
            }
        }
        
        // Salva in cache
        self::$gallery_cache = array_merge(self::$gallery_cache, $gallery_data);
        wp_cache_set($cache_key, $gallery_data, 'fgt_gallery', 3600); // Cache per 1 ora
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
        if (!is_array($gallery_ids)) {
            $gallery_ids = array();
        }
        ?>
        <div class="fgt-gallery-wrapper">
            <p>
                <label>
                    <input type="checkbox" name="use_featured_gallery" value="1" <?php checked($use_gallery, '1'); ?> />
                    <?php _e('Usa Gallery invece della Featured Image', 'featured-gallery-tags'); ?>
                </label>
            </p>
            
            <div id="fgt-gallery-container" style="<?php echo $use_gallery ? '' : 'display:none;'; ?>">
                <p><strong><?php _e('Seleziona 5 immagini:', 'featured-gallery-tags'); ?></strong></p>
                
                <div id="fgt-gallery-preview">
                    <?php 
                    for ($i = 0; $i < 5; $i++) {
                        $image_id = isset($gallery_ids[$i]) ? $gallery_ids[$i] : '';
                        $image_url = $image_id ? wp_get_attachment_image_url($image_id, 'thumbnail') : '';
                        ?>
                        <div class="fgt-image-slot" data-index="<?php echo $i; ?>">
                            <?php if ($image_url): ?>
                                <img src="<?php echo esc_url($image_url); ?>" />
                                <button type="button" class="fgt-remove-image" data-index="<?php echo $i; ?>">×</button>
                            <?php else: ?>
                                <div class="fgt-placeholder"><?php echo $i + 1; ?></div>
                            <?php endif; ?>
                            <input type="hidden" name="gallery_image_ids[]" value="<?php echo esc_attr($image_id); ?>" />
                        </div>
                        <?php
                    }
                    ?>
                </div>
                
                <button type="button" id="fgt-select-images" class="button">
                    <?php _e('Seleziona Immagini', 'featured-gallery-tags'); ?>
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
                <?php _e('Mostra targhetta "TRAILER"', 'featured-gallery-tags'); ?>
            </label>
        </p>
        <p>
            <label>
                <input type="checkbox" name="show_completo_tag" value="1" <?php checked($show_completo, '1'); ?> />
                <?php _e('Mostra targhetta "COMPLETO"', 'featured-gallery-tags'); ?>
            </label>
        </p>
        <?php
    }
    
    public function save_meta_data($post_id, $post) {
        if (!isset($_POST['featured_gallery_nonce_field']) || 
            !wp_verify_nonce($_POST['featured_gallery_nonce_field'], 'featured_gallery_nonce')) {
            return;
        }
        
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }
        
        // Salva settings
        update_post_meta($post_id, '_use_featured_gallery', isset($_POST['use_featured_gallery']) ? '1' : '');
        update_post_meta($post_id, '_show_trailer_tag', isset($_POST['show_trailer_tag']) ? '1' : '');
        update_post_meta($post_id, '_show_completo_tag', isset($_POST['show_completo_tag']) ? '1' : '');
        
        // Salva gallery IDs
        if (isset($_POST['gallery_image_ids'])) {
            $gallery_ids = array_filter($_POST['gallery_image_ids']);
            update_post_meta($post_id, '_featured_gallery_ids', $gallery_ids);
        }
        
        // Invalida cache per questo post
        unset(self::$gallery_cache[$post_id]);
        
        // Invalida cache LiteSpeed per questo post
        if (defined('LSCWP_V')) {
            do_action('litespeed_purge_post', $post_id);
        }
    }
    
    public function enhance_featured_media($html, $post_id, $post_thumbnail_id, $size, $attr) {
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
    
    private function get_cached_meta($post_id, $meta_key) {
        // Prima controlla cache in memoria
        if (isset(self::$gallery_cache[$post_id][$meta_key])) {
            return self::$gallery_cache[$post_id][$meta_key];
        }
        
        // Fallback a get_post_meta (con cache interna di WP)
        $value = get_post_meta($post_id, $meta_key, true);
        
        // Salva in cache memoria
        if (!isset(self::$gallery_cache[$post_id])) {
            self::$gallery_cache[$post_id] = array();
        }
        self::$gallery_cache[$post_id][$meta_key] = $value;
        
        return $value;
    }
    
    private function generate_gallery_html($original_html, $post_id, $size) {
        $show_trailer = $this->get_cached_meta($post_id, '_show_trailer_tag');
        $show_completo = $this->get_cached_meta($post_id, '_show_completo_tag');
        $gallery_ids = $this->get_cached_meta($post_id, '_featured_gallery_ids');
        
        // Genera HTML ottimizzato
        $output = '<div class="fgt-media-wrapper" data-post-id="' . esc_attr($post_id) . '">';
        
        if (!empty($gallery_ids) && is_array($gallery_ids)) {
            // Lazy load delle immagini dopo la prima
            $output .= '<div class="fgt-gallery" data-gallery-ids="' . esc_attr(json_encode($gallery_ids)) . '">';
            $output .= '<div class="fgt-images">';
            
            // Carica solo la prima immagine, le altre via JS
            $first_image_url = wp_get_attachment_image_url($gallery_ids[0], $size);
            $output .= '<img src="' . esc_url($first_image_url) . '" class="fgt-image active" data-index="0" alt="" />';
            
            // Placeholder per le altre immagini
            for ($i = 1; $i < count($gallery_ids); $i++) {
                $output .= '<img data-src="' . esc_url(wp_get_attachment_image_url($gallery_ids[$i], $size)) . '" class="fgt-image" data-index="' . $i . '" alt="" loading="lazy" />';
            }
            
            $output .= '</div>';
            
            // Indicatori
            $output .= '<div class="fgt-indicators">';
            for ($i = 0; $i < count($gallery_ids); $i++) {
                $active = $i === 0 ? ' active' : '';
                $output .= '<button class="fgt-indicator' . $active . '" data-index="' . $i . '" aria-label="Immagine ' . ($i + 1) . '"></button>';
            }
            $output .= '</div>';
            
            $output .= '</div>';
        } else {
            $output .= $original_html;
        }
        
        // Targhette
        if ($show_trailer || $show_completo) {
            $output .= '<div class="fgt-tags">';
            if ($show_trailer) {
                $output .= '<span class="fgt-tag fgt-tag-trailer">TRAILER</span>';
            }
            if ($show_completo) {
                $output .= '<span class="fgt-tag fgt-tag-completo">COMPLETO</span>';
            }
            $output .= '</div>';
        }
        
        $output .= '</div>';
        
        return $output;
    }
    
    public function enqueue_frontend_assets() {
        // CSS
        wp_enqueue_style(
            'fgt-gallery-style',
            FGT_PLUGIN_URL . 'assets/css/gallery.css',
            array(),
            FGT_VERSION
        );
        
        // JS ottimizzato
        wp_enqueue_script(
            'fgt-gallery-script',
            FGT_PLUGIN_URL . 'assets/js/gallery-optimized.js',
            array('jquery'),
            FGT_VERSION,
            true
        );
        
        // Passa configurazione al JS
        wp_localize_script('fgt-gallery-script', 'fgtConfig', array(
            'lazyLoad' => true,
            'autoplayDelay' => 500,
            'transitionSpeed' => 1500
        ));
    }
    
    public function enqueue_admin_assets($hook) {
        if ($hook == 'post.php' || $hook == 'post-new.php') {
            wp_enqueue_media();
            
            wp_enqueue_script(
                'fgt-admin-script',
                FGT_PLUGIN_URL . 'assets/js/admin.js',
                array('jquery'),
                FGT_VERSION,
                true
            );
            
            wp_enqueue_style(
                'fgt-admin-style',
                FGT_PLUGIN_URL . 'assets/css/gallery.css',
                array(),
                FGT_VERSION
            );
        }
    }
    
    // Gestione cache
    public function clear_gallery_cache() {
        wp_cache_delete('fgt_posts_with_galleries', 'fgt_gallery');
        self::$posts_with_galleries = null;
        self::$gallery_cache = array();
    }
}

// Aggiungi supporto per LiteSpeed ESI (Edge Side Includes)
add_action('init', function() {
    if (defined('LSCWP_V')) {
        // Registra ESI per gallery
        add_action('litespeed_esi_load', function($params) {
            if ($params['name'] === 'fgt_gallery') {
                $gallery = new FeaturedGalleryTags();
                echo $gallery->generate_gallery_html('', $params['post_id'], $params['size']);
            }
        });
    }
});