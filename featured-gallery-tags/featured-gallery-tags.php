<?php
/**
 * Plugin Name: Featured Gallery Tags
 * Description: Sostituisce la featured image con una gallery di 5 immagini con autoplay al hover + sistema targhette TRAILER/COMPLETO. Ottimizzato per siti con migliaia di post.
 * Version: 2.0.0
 * Author: Porno Zero
 * License: GPL v2 or later
 * Text Domain: featured-gallery-tags
 * 
 * Ottimizzato per siti con 10k+ post
 * Compatibile con LiteSpeed Cache
 */

// Previeni accesso diretto
if (!defined('ABSPATH')) {
    exit;
}

// Definisci costanti
define('FGT_VERSION', '2.0.0');
define('FGT_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('FGT_PLUGIN_URL', plugin_dir_url(__FILE__));
define('FGT_PLUGIN_BASENAME', plugin_basename(__FILE__));

// Includi la classe principale
require_once FGT_PLUGIN_DIR . 'includes/class-featured-gallery.php';

// Inizializza il plugin
function fgt_init() {
    new FeaturedGalleryTags();
}
add_action('plugins_loaded', 'fgt_init');

// Registra l'hook di attivazione
register_activation_hook(__FILE__, 'fgt_activate');

function fgt_activate() {
    // Crea tabella per statistiche (opzionale)
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'fgt_stats';
    $charset_collate = $wpdb->get_charset_collate();
    
    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        post_id bigint(20) NOT NULL,
        views bigint(20) DEFAULT 0,
        last_viewed datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY post_id (post_id)
    ) $charset_collate;";
    
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
    
    // Imposta versione
    update_option('fgt_version', FGT_VERSION);
    
    // Flush rewrite rules
    flush_rewrite_rules();
}

// Registra l'hook di disattivazione
register_deactivation_hook(__FILE__, 'fgt_deactivate');

function fgt_deactivate() {
    // Pulisci cache
    wp_cache_flush();
    
    // Pulisci transients
    global $wpdb;
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_fgt_%'");
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_fgt_%'");
    
    // Flush rewrite rules
    flush_rewrite_rules();
}

// Registra l'hook di disinstallazione
register_uninstall_hook(__FILE__, 'fgt_uninstall');

function fgt_uninstall() {
    global $wpdb;
    
    // Rimuovi tutti i meta data del plugin
    $meta_keys = array(
        '_use_featured_gallery',
        '_featured_gallery_ids',
        '_show_trailer_tag',
        '_show_completo_tag'
    );
    
    foreach ($meta_keys as $meta_key) {
        $wpdb->delete($wpdb->postmeta, array('meta_key' => $meta_key));
    }
    
    // Rimuovi tabella stats se esiste
    $table_name = $wpdb->prefix . 'fgt_stats';
    $wpdb->query("DROP TABLE IF EXISTS $table_name");
    
    // Rimuovi opzioni
    delete_option('fgt_version');
    delete_option('fgt_settings');
    
    // Pulisci tutti i transients
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_fgt_%'");
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_fgt_%'");
}

// Aggiungi link alle impostazioni nella pagina dei plugin
add_filter('plugin_action_links_' . FGT_PLUGIN_BASENAME, 'fgt_add_action_links');

function fgt_add_action_links($links) {
    $settings_link = '<a href="' . admin_url('tools.php?page=fgt-stats') . '">Statistiche</a>';
    array_unshift($links, $settings_link);
    return $links;
}

// Aggiungi pagina delle statistiche
add_action('admin_menu', 'fgt_add_admin_menu');

function fgt_add_admin_menu() {
    add_submenu_page(
        'tools.php',
        'Featured Gallery Stats',
        'Gallery Stats',
        'manage_options',
        'fgt-stats',
        'fgt_stats_page'
    );
}

function fgt_stats_page() {
    global $wpdb;
    
    // Conta post totali e con gallery
    $total_posts = wp_count_posts('post')->publish;
    
    $gallery_posts = $wpdb->get_var("
        SELECT COUNT(DISTINCT post_id) 
        FROM {$wpdb->postmeta} 
        WHERE meta_key = '_use_featured_gallery' 
        AND meta_value = '1'
    ");
    
    $percentage = $total_posts > 0 ? round(($gallery_posts / $total_posts) * 100, 2) : 0;
    
    // Conta immagini totali nelle gallery
    $total_images = $wpdb->get_var("
        SELECT COUNT(meta_value) 
        FROM {$wpdb->postmeta} 
        WHERE meta_key = '_featured_gallery_ids' 
        AND meta_value != ''
    ") * 5; // Approssimativo
    
    ?>
    <div class="wrap">
        <h1>Featured Gallery Statistics</h1>
        
        <div class="card" style="max-width: 600px; margin-top: 20px;">
            <h2>📊 Utilizzo Gallery</h2>
            <table class="widefat">
                <tbody>
                    <tr>
                        <td><strong>Post totali pubblicati:</strong></td>
                        <td><?php echo number_format($total_posts); ?></td>
                    </tr>
                    <tr>
                        <td><strong>Post con gallery attiva:</strong></td>
                        <td><?php echo number_format($gallery_posts); ?> (<?php echo $percentage; ?>%)</td>
                    </tr>
                    <tr>
                        <td><strong>Immagini totali nelle gallery:</strong></td>
                        <td>~<?php echo number_format($total_images); ?></td>
                    </tr>
                </tbody>
            </table>
        </div>
        
        <div class="card" style="max-width: 600px; margin-top: 20px;">
            <h2>🚀 Performance Status</h2>
            <?php
            $performance_color = 'green';
            $performance_text = 'Ottimo';
            $performance_desc = 'Il plugin funziona in modo ottimale.';
            
            if ($gallery_posts > 1000 && $gallery_posts <= 5000) {
                $performance_color = 'orange';
                $performance_text = 'Buono';
                $performance_desc = 'Performance buone. Assicurati di avere la cache attiva.';
            } elseif ($gallery_posts > 5000) {
                $performance_color = 'red';
                $performance_text = 'Attenzione';
                $performance_desc = 'Con molte gallery, ottimizza ulteriormente il sito.';
            }
            ?>
            <p style="font-size: 18px;">
                Status: <span style="color: <?php echo $performance_color; ?>; font-weight: bold;">
                    <?php echo $performance_text; ?>
                </span>
            </p>
            <p><?php echo $performance_desc; ?></p>
            
            <?php if ($gallery_posts > 1000): ?>
            <h3>💡 Suggerimenti per migliorare le performance:</h3>
            <ul>
                <li>✅ Usa LiteSpeed Cache (già attivo sul tuo sito)</li>
                <li>✅ Mantieni le immagini in formato WebP sotto i 100KB</li>
                <li>✅ Attiva il lazy loading delle immagini</li>
                <li>✅ Considera l'uso di un CDN per le immagini</li>
                <?php if ($gallery_posts > 5000): ?>
                <li>⚡ Aumenta la memoria PHP a 256MB o più</li>
                <li>⚡ Ottimizza le query del database regolarmente</li>
                <?php endif; ?>
            </ul>
            <?php endif; ?>
        </div>
        
        <div class="card" style="max-width: 600px; margin-top: 20px;">
            <h2>🔧 Azioni di Manutenzione</h2>
            <p>
                <a href="<?php echo wp_nonce_url(admin_url('tools.php?page=fgt-stats&action=clear-cache'), 'fgt_clear_cache'); ?>" 
                   class="button button-secondary">
                    Pulisci Cache Gallery
                </a>
                <span class="description" style="margin-left: 10px;">
                    Svuota la cache delle gallery (utile dopo aggiornamenti massivi)
                </span>
            </p>
        </div>
        
        <div class="card" style="max-width: 600px; margin-top: 20px;">
            <h2>ℹ️ Informazioni Sistema</h2>
            <table class="widefat">
                <tbody>
                    <tr>
                        <td><strong>Versione Plugin:</strong></td>
                        <td><?php echo FGT_VERSION; ?></td>
                    </tr>
                    <tr>
                        <td><strong>Cache Attiva:</strong></td>
                        <td><?php echo defined('LSCWP_V') ? '✅ LiteSpeed Cache' : '❌ Nessuna cache rilevata'; ?></td>
                    </tr>
                    <tr>
                        <td><strong>Memoria PHP:</strong></td>
                        <td><?php echo ini_get('memory_limit'); ?></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
    <?php
    
    // Gestisci azione clear cache
    if (isset($_GET['action']) && $_GET['action'] == 'clear-cache' && wp_verify_nonce($_GET['_wpnonce'], 'fgt_clear_cache')) {
        // Pulisci cache
        wp_cache_flush();
        delete_transient('fgt_posts_with_galleries');
        
        echo '<div class="notice notice-success"><p>Cache delle gallery pulita con successo!</p></div>';
    }
}

// Aggiungi supporto per WP-CLI (opzionale)
if (defined('WP_CLI') && WP_CLI) {
    WP_CLI::add_command('fgt stats', function() {
        global $wpdb;
        
        $total = wp_count_posts('post')->publish;
        $gallery = $wpdb->get_var("SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key = '_use_featured_gallery' AND meta_value = '1'");
        
        WP_CLI::line("Post totali: $total");
        WP_CLI::line("Post con gallery: $gallery");
        WP_CLI::success("Completato!");
    });
}