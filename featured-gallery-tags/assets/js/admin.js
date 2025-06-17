/**
 * Featured Gallery Tags - Admin JavaScript
 * Versione: 2.0.0 (Con patch di sicurezza)
 * 
 * Gestisce l'interfaccia admin per la selezione delle immagini della gallery
 * con validazione e sanitizzazione sicura degli input
 */

jQuery(document).ready(function($) {
    'use strict';
    
    // Configurazione
    const MAX_IMAGES = 5;
    const ALLOWED_TYPES = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
    
    // Cache elementi DOM
    const $galleryCheckbox = $('input[name="use_featured_gallery"]');
    const $galleryContainer = $('#fgt-gallery-container');
    const $selectImagesBtn = $('#fgt-select-images');
    
    // Stato dell'applicazione
    let isProcessing = false;
    
    /**
     * Toggle visibilità gallery container
     */
    $galleryCheckbox.on('change', function() {
        if ($(this).is(':checked')) {
            $galleryContainer.slideDown(300);
        } else {
            $galleryContainer.slideUp(300);
        }
    });
    
    /**
     * Selezione multipla immagini
     */
    $selectImagesBtn.on('click', function(e) {
        e.preventDefault();
        
        if (isProcessing) {
            return;
        }
        
        const frame = wp.media({
            title: fgtAdmin.strings.selectImages || 'Seleziona immagini per la gallery',
            multiple: true,
            library: { 
                type: 'image',
                uploadedTo: wp.media.view.settings.post.id // Solo immagini di questo post
            },
            button: { 
                text: fgtAdmin.strings.useImages || 'Usa queste immagini' 
            }
        });
        
        frame.on('select', function() {
            isProcessing = true;
            
            try {
                const selection = frame.state().get('selection');
                const images = [];
                
                // Raccogli e valida le immagini selezionate
                selection.each(function(attachment) {
                    const data = attachment.toJSON();
                    
                    // Validazione tipo mime
                    if (data.mime && ALLOWED_TYPES.includes(data.mime)) {
                        images.push(data);
                    } else {
                        console.warn('Tipo file non supportato:', data.mime);
                    }
                });
                
                // Riempi gli slot disponibili
                let imageIndex = 0;
                $('.fgt-image-slot').each(function() {
                    const $slot = $(this);
                    
                    // Se lo slot è vuoto e abbiamo ancora immagini
                    if (!$slot.find('input[type="hidden"]').val() && imageIndex < images.length) {
                        updateSlot($slot, images[imageIndex]);
                        imageIndex++;
                    }
                });
                
                // Avvisa se ci sono più immagini del massimo consentito
                if (images.length > MAX_IMAGES) {
                    showNotice('info', `Selezionate ${images.length} immagini ma solo ${MAX_IMAGES} possono essere usate.`);
                }
                
            } catch (error) {
                console.error('Errore durante la selezione immagini:', error);
                showNotice('error', 'Si è verificato un errore durante la selezione delle immagini.');
            } finally {
                isProcessing = false;
            }
        });
        
        frame.open();
    });
    
    /**
     * Click su singolo slot
     */
    $(document).on('click', '.fgt-image-slot', function(e) {
        // Ignora se click sul pulsante rimuovi
        if ($(e.target).hasClass('fgt-remove-image')) {
            return;
        }
        
        const $slot = $(this);
        const slotIndex = parseInt($slot.data('index')) || 0;
        
        const frame = wp.media({
            title: `Seleziona immagine ${slotIndex + 1}`,
            multiple: false,
            library: { 
                type: 'image' 
            },
            button: { 
                text: 'Usa questa immagine' 
            }
        });
        
        frame.on('select', function() {
            try {
                const attachment = frame.state().get('selection').first().toJSON();
                
                // Validazione
                if (!attachment.mime || !ALLOWED_TYPES.includes(attachment.mime)) {
                    showNotice('error', 'Tipo di file non supportato. Usa JPG, PNG, GIF o WebP.');
                    return;
                }
                
                updateSlot($slot, attachment);
                
            } catch (error) {
                console.error('Errore selezione immagine:', error);
                showNotice('error', 'Errore durante la selezione dell\'immagine.');
            }
        });
        
        frame.open();
    });
    
    /**
     * Rimuovi immagine
     */
    $(document).on('click', '.fgt-remove-image', function(e) {
        e.stopPropagation();
        e.preventDefault();
        
        const $slot = $(this).closest('.fgt-image-slot');
        
        // Conferma rimozione
        if (confirm(fgtAdmin.strings.confirmRemove || 'Rimuovere questa immagine?')) {
            clearSlot($slot);
        }
    });
    
    /**
     * Aggiorna slot con nuova immagine (versione sicura)
     */
    function updateSlot($slot, image) {
        // Validazione dati immagine
        if (!image || !image.id || !image.sizes) {
            console.error('Dati immagine non validi:', image);
            return;
        }
        
        // Determina URL thumbnail
        let thumbnailUrl = '';
        if (image.sizes.thumbnail && image.sizes.thumbnail.url) {
            thumbnailUrl = image.sizes.thumbnail.url;
        } else if (image.sizes.medium && image.sizes.medium.url) {
            thumbnailUrl = image.sizes.medium.url;
        } else if (image.url) {
            thumbnailUrl = image.url;
        } else {
            console.error('Nessun URL valido trovato per l\'immagine');
            return;
        }
        
        const index = parseInt($slot.data('index')) || 0;
        const imageId = parseInt(image.id) || 0;
        
        // Validazione ID
        if (imageId <= 0) {
            console.error('ID immagine non valido:', imageId);
            return;
        }
        
        // Crea elementi DOM in modo sicuro
        const $img = $('<img>', {
            'src': thumbnailUrl,
            'alt': image.alt || '',
            'title': image.title || '',
            'loading': 'lazy'
        });
        
        const $button = $('<button>', {
            'type': 'button',
            'class': 'fgt-remove-image',
            'data-index': index,
            'text': '×',
            'aria-label': 'Rimuovi immagine'
        });
        
        const $input = $('<input>', {
            'type': 'hidden',
            'name': 'gallery_image_ids[]',
            'value': imageId
        });
        
        // Aggiorna lo slot
        $slot.empty().append($img, $button, $input);
        
        // Aggiungi classe per animazione
        $slot.addClass('has-image');
        
        // Log per debugging (rimuovere in produzione)
        if (window.fgtDebug) {
            console.log('Slot aggiornato:', {
                index: index,
                imageId: imageId,
                url: thumbnailUrl
            });
        }
    }
    
    /**
     * Pulisci slot (versione sicura)
     */
    function clearSlot($slot) {
        const index = parseInt($slot.data('index')) || 0;
        
        // Crea elementi DOM in modo sicuro
        const $placeholder = $('<div>', {
            'class': 'fgt-placeholder',
            'text': String(index + 1),
            'aria-label': `Slot ${index + 1} vuoto`
        });
        
        const $input = $('<input>', {
            'type': 'hidden',
            'name': 'gallery_image_ids[]',
            'value': ''
        });
        
        // Pulisci lo slot
        $slot.empty()
            .append($placeholder, $input)
            .removeClass('has-image');
    }
    
    /**
     * Mostra notifica temporanea
     */
    function showNotice(type, message) {
        // Rimuovi notifiche esistenti
        $('.fgt-notice').remove();
        
        const $notice = $('<div>', {
            'class': `notice notice-${type} fgt-notice is-dismissible`,
            'html': $('<p>', { text: message })
        });
        
        // Inserisci dopo il titolo del metabox
        $('.fgt-gallery-wrapper').prepend($notice);
        
        // Auto-rimuovi dopo 5 secondi
        setTimeout(() => {
            $notice.fadeOut(300, function() {
                $(this).remove();
            });
        }, 5000);
    }
    
    /**
     * Drag & Drop support (opzionale)
     */
    if (window.FileReader && window.FormData) {
        $('.fgt-image-slot').on('dragover', function(e) {
            e.preventDefault();
            e.stopPropagation();
            $(this).addClass('drag-over');
        });
        
        $('.fgt-image-slot').on('dragleave drop', function(e) {
            e.preventDefault();
            e.stopPropagation();
            $(this).removeClass('drag-over');
        });
        
        // Nota: L'implementazione completa del drag & drop richiederebbe
        // l'upload AJAX che va oltre lo scope di questo esempio
    }
    
    /**
     * Validazione form prima del salvataggio
     */
    $('#post').on('submit', function() {
        if ($galleryCheckbox.is(':checked')) {
            let hasImages = false;
            
            $('.fgt-image-slot input[type="hidden"]').each(function() {
                if ($(this).val()) {
                    hasImages = true;
                    return false; // break
                }
            });
            
            if (!hasImages) {
                return confirm('La gallery è attiva ma non ci sono immagini selezionate. Continuare comunque?');
            }
        }
        
        return true;
    });
    
    /**
     * Inizializzazione
     */
    function init() {
        // Aggiungi stili dinamici per feedback visivo
        const style = `
            <style>
                .fgt-image-slot.drag-over { 
                    border-color: #0073aa; 
                    background-color: #f0f8ff; 
                }
                .fgt-image-slot.has-image .fgt-placeholder { 
                    display: none; 
                }
                .fgt-notice { 
                    margin: 10px 0; 
                }
            </style>
        `;
        $('head').append(style);
        
        // Verifica che wp.media sia disponibile
        if (typeof wp === 'undefined' || typeof wp.media === 'undefined') {
            console.error('wp.media non disponibile. Assicurati che il media uploader sia caricato.');
            $selectImagesBtn.prop('disabled', true);
        }
    }
    
    // Avvia inizializzazione
    init();
    
    // Esponi API pubblica per debugging (solo in dev)
    if (window.fgtDebug) {
        window.fgtAdminAPI = {
            updateSlot: updateSlot,
            clearSlot: clearSlot,
            showNotice: showNotice
        };
    }
});
