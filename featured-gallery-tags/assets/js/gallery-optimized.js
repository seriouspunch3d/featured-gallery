/**
 * Gallery JavaScript Ultra-Ottimizzato
 * Salva come: assets/js/gallery-optimized.js
 */

(function($) {
    'use strict';
    
    // Configurazione globale
    const config = window.fgtConfig || {
        lazyLoad: true,
        autoplayDelay: 500,
        transitionSpeed: 1500
    };
    
    // Cache degli elementi DOM
    const galleries = new Map();
    
    // Intersection Observer per lazy loading
    let imageObserver;
    if ('IntersectionObserver' in window) {
        imageObserver = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    const img = entry.target;
                    if (img.dataset.src && !img.src) {
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
    
    // Gallery Class ottimizzata
    class Gallery {
        constructor(element) {
            this.$element = $(element);
            this.$images = this.$element.find('.fgt-image');
            this.$indicators = this.$element.find('.fgt-indicator');
            
            this.currentIndex = 0;
            this.autoplayTimer = null;
            this.hoverTimer = null;
            this.isHovering = false;
            
            this.init();
        }
        
        init() {
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
                .on('mouseenter touchstart', this.handleHoverStart.bind(this))
                .on('mouseleave touchend', this.handleHoverEnd.bind(this))
                .on('click', '.fgt-indicator', this.handleIndicatorClick.bind(this));
            
            // Touch events ottimizzati
            this.initTouchEvents();
        }
        
        handleHoverStart(e) {
            if (e.type === 'touchstart') {
                e.preventDefault();
            }
            
            this.isHovering = true;
            
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
            this.showImage(index);
            
            if (this.autoplayTimer) {
                this.stopAutoplay();
                this.startAutoplay();
            }
        }
        
        showImage(index) {
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
                requestAnimationFrame(() => this.nextImage());
            }, config.transitionSpeed);
        }
        
        stopAutoplay() {
            if (this.autoplayTimer) {
                clearInterval(this.autoplayTimer);
                this.autoplayTimer = null;
            }
        }
        
        initTouchEvents() {
            let touchStartX = 0;
            let touchEndX = 0;
            
            this.$element.on('touchstart', (e) => {
                touchStartX = e.changedTouches[0].screenX;
            });
            
            this.$element.on('touchend', (e) => {
                touchEndX = e.changedTouches[0].screenX;
                this.handleSwipe(touchStartX - touchEndX);
            });
        }
        
        handleSwipe(diff) {
            const threshold = 50;
            
            if (Math.abs(diff) > threshold) {
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
            this.$element.off();
            
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
                            galleries.set(entry.target, new Gallery($gallery));
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
                galleries.set(el, new Gallery($(el)));
            });
        }
        
        // Gestione click sulle targhette
        $(document).on('click', '.fgt-tag', (e) => {
            e.preventDefault();
            e.stopPropagation();
        });
    });
    
    // Cleanup su page unload
    $(window).on('beforeunload', () => {
        galleries.forEach(gallery => gallery.destroy());
        galleries.clear();
    });
    
})(jQuery);