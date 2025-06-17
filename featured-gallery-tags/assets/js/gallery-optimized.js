/**
 * Gallery JavaScript Ultra-Ottimizzato con Correzioni di Sicurezza
 * Salva come: assets/js/gallery-optimized.js
 */

(function($) {
    'use strict';
    
    // Configurazione globale con validazione
    const config = {
        lazyLoad: window.fgtConfig?.lazyLoad !== false,
        autoplayDelay: Math.max(100, parseInt(window.fgtConfig?.autoplayDelay) || 500),
        transitionSpeed: Math.max(500, parseInt(window.fgtConfig?.transitionSpeed) || 1500)
    };
    
    // Cache degli elementi DOM con limite
    const galleries = new Map();
    const MAX_GALLERIES = 50;
    
    // Intersection Observer per lazy loading
    let imageObserver;
    if ('IntersectionObserver' in window) {
        imageObserver = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    const img = entry.target;
                    if (img.dataset.src && !img.src) {
                        // Gestione errori per immagini
                        img.onerror = function() {
                            console.warn('Failed to load image:', img.dataset.src);
                            // Placeholder o fallback
                            this.style.display = 'none';
                            // Rimuovi dal DOM se è una gallery image non essenziale
                            if (this.classList.contains('fgt-image') && !this.classList.contains('active')) {
                                $(this).remove();
                            }
                        };
                        
                        img.src = img.dataset.src;
                        img.removeAttribute('data-src');
                    }
                    imageObserver.unobserve(img);
                }
            });
        }, {
            rootMargin: '50px'
        });
    }
    
    // Gallery Class ottimizzata con gestione errori
    class Gallery {
        constructor(element) {
            this.$element = $(element);
            this.$images = this.$element.find('.fgt-image');
            this.$indicators = this.$element.find('.fgt-indicator');
            
            // Validazione
            if (this.$images.length === 0) {
                console.warn('No images found in gallery');
                return;
            }
            
            this.currentIndex = 0;
            this.autoplayTimer = null;
            this.hoverTimer = null;
            this.isHovering = false;
            this.touchStartX = 0;
            this.touchEndX = 0;
            
            this.init();
        }
        
        init() {
            try {
                // Parse sicuro dei gallery IDs
                const galleryIdsAttr = this.$element.find('.fgt-gallery').attr('data-gallery-ids');
                if (galleryIdsAttr) {
                    try {
                        this.galleryIds = JSON.parse(galleryIdsAttr);
                        // Validazione array
                        if (!Array.isArray(this.galleryIds)) {
                            this.galleryIds = [];
                        }
                    } catch (e) {
                        console.error('Invalid gallery IDs JSON:', e);
                        this.galleryIds = [];
                    }
                }
                
                // Lazy load delle immagini
                if (config.lazyLoad && imageObserver) {
                    this.$images.each((i, img) => {
                        if (img.dataset.src) {
                            imageObserver.observe(img);
                        }
                    });
                }
                
                // Event delegation per performance
                this.$element
                    .on('mouseenter.fgt', this.handleHoverStart.bind(this))
                    .on('mouseleave.fgt', this.handleHoverEnd.bind(this))
                    .on('click.fgt', '.fgt-indicator', this.handleIndicatorClick.bind(this));
                
                // Touch events ottimizzati
                this.initTouchEvents();
                
                // Gestione errori immagini attive
                this.$images.on('error', function() {
                    console.warn('Image load error:', this.src);
                });
                
            } catch (error) {
                console.error('Gallery initialization error:', error);
            }
        }
        
        handleHoverStart(e) {
            if (e.type === 'touchstart') {
                e.preventDefault();
            }
            
            this.isHovering = true;
            
            // Clear existing timer
            if (this.hoverTimer) {
                clearTimeout(this.hoverTimer);
            }
            
            // Usa requestAnimationFrame per performance
            this.hoverTimer = setTimeout(() => {
                if (this.isHovering) {
                    requestAnimationFrame(() => this.startAutoplay());
                }
            }, config.autoplayDelay);
        }
        
        handleHoverEnd() {
            this.isHovering = false;
            
            if (this.hoverTimer) {
                clearTimeout(this.hoverTimer);
                this.hoverTimer = null;
            }
            
            this.stopAutoplay();
            this.showImage(0);
        }
        
        handleIndicatorClick(e) {
            e.stopPropagation();
            e.preventDefault();
            
            const index = parseInt($(e.currentTarget).data('index'));
            if (!isNaN(index) && index >= 0 && index < this.$images.length) {
                this.showImage(index);
                
                if (this.autoplayTimer) {
                    this.stopAutoplay();
                    this.startAutoplay();
                }
            }
        }
        
        showImage(index) {
            // Validazione index
            if (index < 0 || index >= this.$images.length) {
                return;
            }
            
            // Batch DOM updates
            requestAnimationFrame(() => {
                this.$images.removeClass('active').eq(index).addClass('active');
                this.$indicators.removeClass('active').eq(index).addClass('active');
                this.currentIndex = index;
            });
        }
        
        nextImage() {
            const nextIndex = (this.currentIndex + 1) % this.$images.length;
            this.showImage(nextIndex);
        }
        
        startAutoplay() {
            this.stopAutoplay();
            this.autoplayTimer = setInterval(() => {
                if (this.$images.length > 1) {
                    requestAnimationFrame(() => this.nextImage());
                }
            }, config.transitionSpeed);
        }
        
        stopAutoplay() {
            if (this.autoplayTimer) {
                clearInterval(this.autoplayTimer);
                this.autoplayTimer = null;
            }
        }
        
        initTouchEvents() {
            // Usa namespace per eventi
            this.$element.on('touchstart.fgt', (e) => {
                this.touchStartX = e.changedTouches[0].screenX;
            });
            
            this.$element.on('touchend.fgt', (e) => {
                this.touchEndX = e.changedTouches[0].screenX;
                this.handleSwipe(this.touchStartX - this.touchEndX);
            });
        }
        
        handleSwipe(diff) {
            const threshold = 50;
            
            if (Math.abs(diff) > threshold && this.$images.length > 1) {
                if (diff > 0) {
                    // Swipe left
                    const nextIndex = (this.currentIndex + 1) % this.$images.length;
                    this.showImage(nextIndex);
                } else {
                    // Swipe right
                    const prevIndex = this.currentIndex === 0 ? this.$images.length - 1 : this.currentIndex - 1;
                    this.showImage(prevIndex);
                }
            }
        }
        
        destroy() {
            this.stopAutoplay();
            if (this.hoverTimer) {
                clearTimeout(this.hoverTimer);
            }
            
            // Rimuovi tutti gli event listener con namespace
            this.$element.off('.fgt');
            this.$images.off('error');
            
            if (imageObserver) {
                this.$images.each((i, img) => {
                    imageObserver.unobserve(img);
                });
            }
        }
    }
    
    // Inizializzazione ottimizzata
    $(document).ready(() => {
        // Usa Intersection Observer per inizializzare gallery solo quando visibili
        if ('IntersectionObserver' in window) {
            const galleryObserver = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        const $gallery = $(entry.target);
                        if (!galleries.has(entry.target)) {
                            // Limita numero di gallery in memoria
                            if (galleries.size >= MAX_GALLERIES) {
                                // Rimuovi la più vecchia
                                const firstKey = galleries.keys().next().value;
                                galleries.get(firstKey).destroy();
                                galleries.delete(firstKey);
                            }
                            
                            try {
                                galleries.set(entry.target, new Gallery($gallery));
                            } catch (error) {
                                console.error('Failed to initialize gallery:', error);
                            }
                        }
                        galleryObserver.unobserve(entry.target);
                    }
                });
            }, {
                rootMargin: '100px'
            });
            
            $('.fgt-gallery').each((i, el) => {
                galleryObserver.observe(el);
            });
        } else {
            // Fallback per browser vecchi
            $('.fgt-gallery').each((i, el) => {
                if (galleries.size < MAX_GALLERIES) {
                    try {
                        galleries.set(el, new Gallery($(el)));
                    } catch (error) {
                        console.error('Failed to initialize gallery:', error);
                    }
                }
            });
        }
        
        // Gestione click sulle targhette con validazione
        $(document).on('click', '.fgt-tag', (e) => {
            e.preventDefault();
            e.stopPropagation();
            // Nessuna azione pericolosa qui
        });
    });
    
    // Cleanup su page unload
    $(window).on('beforeunload', () => {
        galleries.forEach(gallery => {
            try {
                gallery.destroy();
            } catch (e) {
                // Ignora errori durante cleanup
            }
        });
        galleries.clear();
    });
    
})(jQuery);
