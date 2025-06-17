jQuery(document).ready(function($) {
    // Toggle gallery visibility
    $('input[name="use_featured_gallery"]').on('change', function() {
        if ($(this).is(':checked')) {
            $('#fgt-gallery-container').slideDown();
        } else {
            $('#fgt-gallery-container').slideUp();
        }
    });
    
    // Select multiple images
    $('#fgt-select-images').on('click', function(e) {
        e.preventDefault();
        
        var frame = wp.media({
            title: 'Seleziona immagini per la gallery',
            multiple: true,
            library: { type: 'image' },
            button: { text: 'Usa queste immagini' }
        });
        
        frame.on('select', function() {
            var selection = frame.state().get('selection');
            var images = [];
            
            selection.each(function(attachment) {
                images.push(attachment.toJSON());
            });
            
            var currentIndex = 0;
            $('.fgt-image-slot').each(function(index) {
                if (!$(this).find('input[type="hidden"]').val() && currentIndex < images.length) {
                    var image = images[currentIndex];
                    updateSlot($(this), image);
                    currentIndex++;
                }
            });
        });
        
        frame.open();
    });
    
    // Click on single slot
    $(document).on('click', '.fgt-image-slot', function(e) {
        if ($(e.target).hasClass('fgt-remove-image')) return;
        
        var $slot = $(this);
        
        var frame = wp.media({
            title: 'Seleziona immagine ' + ($slot.data('index') + 1),
            multiple: false,
            library: { type: 'image' },
            button: { text: 'Usa questa immagine' }
        });
        
        frame.on('select', function() {
            var attachment = frame.state().get('selection').first().toJSON();
            updateSlot($slot, attachment);
        });
        
        frame.open();
    });
    
    // Remove image
    $(document).on('click', '.fgt-remove-image', function(e) {
        e.stopPropagation();
        var $slot = $(this).closest('.fgt-image-slot');
        clearSlot($slot);
    });
    
    // Helper functions
    function updateSlot($slot, image) {
        var index = $slot.data('index');
        $slot.html(
            '<img src="' + image.sizes.thumbnail.url + '" />' +
            '<button type="button" class="fgt-remove-image" data-index="' + index + '">×</button>' +
            '<input type="hidden" name="gallery_image_ids[]" value="' + image.id + '" />'
        );
    }
    
    function clearSlot($slot) {
        var index = $slot.data('index');
        $slot.html(
            '<div class="fgt-placeholder">' + (index + 1) + '</div>' +
            '<input type="hidden" name="gallery_image_ids[]" value="" />'
        );
    }
});