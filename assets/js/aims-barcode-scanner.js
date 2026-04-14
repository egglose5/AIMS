(function($) {
    'use strict';

    const AIMSBarcodeScanner = {
        init: function() {
            this.bindEvents();
        },

        bindEvents: function() {
            $(document).on('click', '.aims-scan-trigger', this.openScanner.bind(this));
        },

        openScanner: function(e) {
            e.preventDefault();
            const $trigger = $(e.currentTarget);
            const targetId = $trigger.data('target');
            const $targetField = $('#' + targetId);

            if (!$targetField.length) return;

            // Create scanner modal if not exists
            this.ensureModal();
            this.activeTarget = $targetField;
            $('#aims-barcode-modal').show();

            this.startScanning();
        },

        ensureModal: function() {
            if ($('#aims-barcode-modal').length) return;

            const modalHtml = `
                <div id="aims-barcode-modal" class="aims-modal">
                    <div class="aims-modal-content">
                        <span class="aims-modal-close">&times;</span>
                        <h3>Scan Barcode</h3>
                        <div id="aims-reader"></div>
                        <p class="aims-modal-status">Ready to scan...</p>
                    </div>
                </div>
            `;
            $('body').append(modalHtml);

            $('.aims-modal-close').on('click', () => {
                this.stopScanning();
                $('#aims-barcode-modal').hide();
            });
        },

        startScanning: function() {
            // Check if Html5Qrcode is available (assumed to be enqueued)
            if (typeof Html5Qrcode === 'undefined') {
                $('.aims-modal-status').text('Scanner library not loaded.');
                return;
            }

            const html5QrCode = new Html5Qrcode("aims-reader");
            this.scanner = html5QrCode;

            const config = { fps: 10, qrbox: { width: 250, height: 250 } };

            html5QrCode.start(
                { facingMode: "environment" },
                config,
                (decodedText) => {
                    this.activeTarget.val(decodedText).trigger('change');
                    this.stopScanning();
                    $('#aims-barcode-modal').hide();
                },
                (errorMessage) => {
                    // console.log(errorMessage);
                }
            ).catch((err) => {
                $('.aims-modal-status').text('Error accessing camera: ' + err);
            });
        },

        stopScanning: function() {
            if (this.scanner && this.scanner.isScanning) {
                this.scanner.stop().then(() => {
                    this.scanner.clear();
                });
            }
        }
    };

    $(document).ready(() => {
        AIMSBarcodeScanner.init();
    });

})(jQuery);
