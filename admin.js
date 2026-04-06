jQuery(document).ready(function ($) {

    // --- Dark Mode Toggle Logic ---
    const darkModeToggle = {
        init: function() {
            this.switch = $('#zmm-dark-mode-switch');
            if (!this.switch.length) return;

            this.container = $('.zmm-settings-wrap');
            this.preference = localStorage.getItem('zmm-theme');

            this.applyInitialTheme();
            this.switch.on('change', this.toggleTheme.bind(this));
        },

        applyInitialTheme: function() {
            if (this.preference === 'dark') {
                this.container.addClass('zmm-dark-mode-active');
                this.switch.prop('checked', true);
            } else if (this.preference === 'light') {
                this.container.removeClass('zmm-dark-mode-active');
                this.switch.prop('checked', false);
            } else {
                // No preference saved, use system preference
                if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) {
                    this.container.addClass('zmm-dark-mode-active');
                    this.switch.prop('checked', true);
                }
            }
        },

        toggleTheme: function() {
            if (this.switch.is(':checked')) {
                this.container.addClass('zmm-dark-mode-active');
                localStorage.setItem('zmm-theme', 'dark');
            } else {
                this.container.removeClass('zmm-dark-mode-active');
                localStorage.setItem('zmm-theme', 'light');
            }
        }
    };
    darkModeToggle.init();


    // --- Custom Tooltip for A11y Score ---
    const a11yTooltip = {
        init: function() {
            $('body').append('<div id="zmm-tooltip"></div>');
            const $tooltip = $('#zmm-tooltip');

            $(document).on('mouseenter', '.zmm-a11y-score', function() {
                const $target = $(this);
                const text = $target.attr('title');
                if (text) {
                    $tooltip.html(text.replace(/\n/g, '<br>')).addClass('visible');
                    const targetRect = $target[0].getBoundingClientRect();
                    $tooltip.css({
                        top: (targetRect.top + window.scrollY - $tooltip.outerHeight() - 8) + 'px',
                        left: (targetRect.left + window.scrollX + (targetRect.width / 2) - ($tooltip.outerWidth() / 2)) + 'px'
                    });
                }
            });

            $(document).on('mouseleave', '.zmm-a11y-score', function() {
                $tooltip.removeClass('visible');
            });
        }
    };
    a11yTooltip.init();

    // --- Accessibility Helpers ---
    const accessibilityHelpers = {
        init: function() {
            this.attachToField($('#attachment_alt'));
            const observer = new MutationObserver((mutationsList) => {
                for (const mutation of mutationsList) {
                    if (mutation.addedNodes.length) {
                        const altInput = $(mutation.target).find('input[aria-describedby="alt-text-description"], input#attachment_alt');
                        if (altInput.length && !altInput.data('zmm-bound')) {
                            this.attachToField(altInput);
                            altInput.data('zmm-bound', true);
                        }
                    }
                }
            });
            observer.observe(document.body, { childList: true, subtree: true });
        },
        attachToField: function($field) {
            if (!$field.length || $field.next('.zmm-char-count').length > 0) return;
            const counterId = 'zmm-char-counter-' + Math.random().toString(36).substr(2, 9);
            $field.after(`<div id="${counterId}" class="zmm-char-count"></div>`);
            const $counter = $('#' + counterId);
            const updateCounter = () => {
                const length = $field.val().length;
                $counter.text(length + ' / 150');
                $counter.toggleClass('warning', length > 150);
            };
            $field.on('keyup input', updateCounter).trigger('input');
        }
    };
    accessibilityHelpers.init();

    // --- Bulk Optimization Logic ---
    const bulkProcessor = {
        init: function() {
            this.startButton = $('#zmm-bulk-start-btn');
            if (!this.startButton.length) return;
            this.progressBar = $('#zmm-bulk-progress-bar');
            this.feedbackContainer = $('#zmm-bulk-feedback');
            this.startButton.on('click', this.start.bind(this));
        },
        start: function(e) {
            e.preventDefault();
            this.startButton.prop('disabled', true).text(zmm_ajax.i18n.processing);
            this.feedbackContainer.text('').removeClass('error success');
            $.post(zmm_ajax.ajax_url, { action: 'zmm_bulk_process', nonce: zmm_ajax.nonce, bulk_action: 'get_unprocessed_images' })
             .done(response => {
                if (response.success && response.data.ids.length > 0) {
                    this.imageIds = response.data.ids;
                    this.totalImages = this.imageIds.length;
                    this.currentIndex = 0;
                    this.errors = [];
                    this.progressBar.parent().show();
                    this.feedbackContainer.text(`Found ${this.totalImages} images to process...`);
                    this.processNext();
                } else {
                    this.feedbackContainer.text('No unprocessed images found.').addClass('success');
                    this.resetButton();
                }
             })
             .fail(() => {
                this.feedbackContainer.text(zmm_ajax.i18n.error).addClass('error');
                this.resetButton();
             });
        },
        processNext: function() {
            if (this.currentIndex >= this.totalImages) {
                this.complete();
                return;
            }
            const imageId = this.imageIds[this.currentIndex];
            $.post(zmm_ajax.ajax_url, { action: 'zmm_bulk_process', nonce: zmm_ajax.nonce, bulk_action: 'process_batch', id: imageId })
             .fail((jqXHR) => this.errors.push(jqXHR.responseJSON?.data?.message || `Image ID ${imageId}: Failed.`))
             .always(() => {
                this.currentIndex++;
                this.progressBar.css('width', (this.currentIndex / this.totalImages) * 100 + '%');
                this.feedbackContainer.text(`Processing ${this.currentIndex} of ${this.totalImages}...`);
                setTimeout(() => this.processNext(), 100);
             });
        },
        complete: function() {
            let finalMessage = zmm_ajax.i18n.complete;
            if (this.errors.length > 0) {
                finalMessage += ` with ${this.errors.length} errors. See console for details.`;
                console.error('Zero Mass Media Bulk Processing Errors:', this.errors);
                this.feedbackContainer.addClass('error');
            } else {
                this.feedbackContainer.addClass('success');
            }
            this.feedbackContainer.text(finalMessage);
            setTimeout(() => location.reload(), 2000);
        },
        resetButton: function() {
            this.startButton.prop('disabled', false).text('Start Bulk Optimization');
        }
    };
    bulkProcessor.init();

    // --- Bulk Alt Text Generation Logic ---
    const bulkAltGenerator = {
        init: function() {
            this.startButton = $('#zmm-bulk-alt-start-btn');
            if (!this.startButton.length) return;
            this.progressBar = $('#zmm-bulk-alt-progress-bar');
            this.feedbackContainer = $('#zmm-bulk-alt-feedback');
            this.startButton.on('click', this.start.bind(this));
        },
        start: function(e) {
            e.preventDefault();
            this.startButton.prop('disabled', true).text(zmm_ajax.i18n.processing);
            this.feedbackContainer.text('').removeClass('error success');
             $.post(zmm_ajax.ajax_url, { action: 'zmm_bulk_generate_alt', nonce: zmm_ajax.nonce, bulk_action: 'get_images_without_alt' })
              .done(response => {
                if (response.success && response.data.ids.length > 0) {
                    this.imageIds = response.data.ids;
                    this.totalImages = this.imageIds.length;
                    this.currentIndex = 0;
                    this.errors = [];
                    this.progressBar.parent().show();
                    this.feedbackContainer.text(`Found ${this.totalImages} images needing alt text...`);
                    this.processNext();
                } else {
                    this.feedbackContainer.text('No images found needing alt text.').addClass('success');
                    this.resetButton();
                }
              })
              .fail(() => {
                this.feedbackContainer.text(zmm_ajax.i18n.error).addClass('error');
                this.resetButton();
              });
        },
        processNext: function() {
            if (this.currentIndex >= this.totalImages) {
                this.complete();
                return;
            }
            const imageId = this.imageIds[this.currentIndex];
            $.post(zmm_ajax.ajax_url, { action: 'zmm_bulk_generate_alt', nonce: zmm_ajax.nonce, bulk_action: 'process_alt_batch', id: imageId })
             .fail((jqXHR) => this.errors.push(jqXHR.responseJSON?.data?.message || `Image ID ${imageId}: Failed.`))
             .always(() => {
                this.currentIndex++;
                this.progressBar.css('width', (this.currentIndex / this.totalImages) * 100 + '%');
                this.feedbackContainer.text(`Generating alt text for ${this.currentIndex} of ${this.totalImages}...`);
                setTimeout(() => this.processNext(), 200);
             });
        },
        complete: function() {
            let finalMessage = zmm_ajax.i18n.complete;
            if (this.errors.length > 0) {
                finalMessage += ` with ${this.errors.length} errors. See console for details.`;
                console.error('Zero Mass Media Bulk Alt Gen Errors:', this.errors);
                this.feedbackContainer.addClass('error');
            } else {
                this.feedbackContainer.addClass('success');
            }
            this.feedbackContainer.text(finalMessage);
            setTimeout(() => location.reload(), 2000);
        },
        resetButton: function() {
            this.startButton.prop('disabled', false).text('Bulk Generate Alt Text');
        }
    };
    bulkAltGenerator.init();

    // --- Media Library & Post Edit Screen Logic ---
    function handleAction(button) {
        const action = button.data('action');
        const cell = button.closest('.zmm-actions-cell, .zmm-edit-media-actions');
        const feedback = cell.find('.zmm-feedback');
        const actionButtons = cell.find('.zmm-action-buttons');
        const progressContainer = cell.find('.zmm-progress-container');
        
        button.prop('disabled', true);
        feedback.text('').removeClass('error success');
        
        if (action === 'compress') {
            actionButtons.hide();
            progressContainer.show();
        }

        $.post(zmm_ajax.ajax_url, { action: 'zmm_process_single_image', nonce: zmm_ajax.nonce, task: action, id: button.data('id') })
         .done(response => {
            if (response.success) {
                feedback.text(response.data.message).addClass('success');
                if(action === 'compress' || action === 'restore') {
                    setTimeout(() => location.reload(), 1500);
                } else if (response.data.data.alt) {
                    $('#attachment_alt').val(response.data.data.alt).trigger('input');
                } else if (response.data.data.title) {
                    $('#title').val(response.data.data.title);
                }
            } else {
                feedback.text(response.data.message).addClass('error');
                if (action === 'compress') actionButtons.show();
            }
         })
         .fail((jqXHR) => {
            feedback.text(jqXHR.responseJSON?.data?.message || zmm_ajax.i18n.error).addClass('error');
            if (action === 'compress') actionButtons.show();
         })
         .always(() => {
            if (action !== 'compress') button.prop('disabled', false);
            if (action === 'compress') progressContainer.hide();
         });
    }

    $(document).on('click', '.zmm-action-btn', function(e) { e.preventDefault(); handleAction($(this)); });
    $(document).on('click', '.zmm-restore-btn', function(e) { e.preventDefault(); handleAction($(this)); });

    $('#zmm-manual-cleanup-btn').on('click', function(e) {
        e.preventDefault();
        const button = $(this);
        const feedback = $('#zmm-manual-cleanup-feedback');
        button.prop('disabled', true).text(zmm_ajax.i18n.processing);
        feedback.text('').removeClass('error success');
        $.post(zmm_ajax.ajax_url, { action: 'zmm_manual_backup_cleanup', nonce: zmm_ajax.nonce })
         .done(response => feedback.text(response.data.message).addClass(response.success ? 'success' : 'error'))
         .fail(jqXHR => feedback.text(jqXHR.responseJSON?.data?.message || zmm_ajax.i18n.error).addClass('error'))
         .always(() => button.prop('disabled', false).text('Clean Up Backups Now'));
    });
});