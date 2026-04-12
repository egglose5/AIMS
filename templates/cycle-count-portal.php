<?php
/**
 * Cycle Count / Inventory Deployment Portal
 *
 * Template variables supplied by AIMS_Cycle_Count_Controller::render_shortcode():
 *   $cc_title        - Portal title string
 *   $cc_description  - Portal description string
 *   $cc_rest_base    - Base REST URL (aims/v1/)
 *   $cc_rest_nonce   - wp_rest nonce for authenticated fetch calls
 *   $cc_bucket_route - 'cycle-count/bucket' route segment
 *   $cc_submit_route - 'cycle-count/submit' route segment
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<section class="aims-cycle-count-portal" id="aims-cc-portal">
<style>
.aims-cycle-count-portal{max-width:640px;margin:0 auto;padding:12px;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}
.aims-cc-shell,.aims-cc-card{border:1px solid #dcdcde;border-radius:16px;background:#fff}
.aims-cc-shell{padding:16px;box-shadow:0 4px 24px rgba(0,0,0,.05)}
.aims-cc-card{padding:14px;margin:0 0 12px}
.aims-cc-muted{color:#646970;font-size:.9em;margin:4px 0 10px}
.aims-cc-step-badge{display:inline-block;background:#2271b1;color:#fff;border-radius:999px;padding:2px 10px;font-size:.78em;font-weight:700;margin-bottom:8px}
label.cc-label{display:block;font-weight:600;margin-bottom:6px}
.aims-cc-portal input[type="text"],.aims-cc-portal input[type="number"],.aims-cc-portal textarea,.aims-cc-portal select,.aims-cc-portal button{width:100%;box-sizing:border-box;padding:10px 12px;border:1px solid #8c8f94;border-radius:6px;font-size:1em}
.aims-cc-portal button{cursor:pointer;border:none;border-radius:8px;padding:12px;font-weight:700;margin-top:8px}
.aims-cc-portal button.primary{background:#2271b1;color:#fff}
.aims-cc-portal button.primary:disabled{background:#b5d0ee;cursor:not-allowed}
.aims-cc-portal button.secondary{background:#f6f7f7;color:#1d2327;border:1px solid #c3c4c7}
.aims-cc-portal button.danger{background:#d63638;color:#fff}
#aims-cc-viewfinder-wrap{position:relative;background:#000;border-radius:10px;overflow:hidden;max-height:280px;margin:0 0 10px}
#aims-cc-viewfinder{width:100%;display:block;border-radius:10px}
#aims-cc-scan-overlay{position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);border:2px solid rgba(34,113,177,.8);border-radius:6px;width:60%;height:38%;box-shadow:0 0 0 9999px rgba(0,0,0,.35)}
#aims-cc-scan-flash{position:absolute;inset:0;pointer-events:none;opacity:0;background:rgba(0,255,120,.35);border-radius:10px;transition:opacity .08s ease}
.aims-cc-input-row{display:flex;gap:8px;margin:8px 0}
.aims-cc-input-row input{flex:1}
.aims-cc-input-row button{width:auto;flex-shrink:0;padding:10px 14px}
.aims-cc-count-table{width:100%;border-collapse:collapse;margin:10px 0;font-size:.88em}
.aims-cc-count-table th{text-align:left;padding:6px 8px;border-bottom:2px solid #dcdcde;color:#646970;font-weight:600}
.aims-cc-count-table td{padding:6px 8px;border-bottom:1px solid #f0f0f1;vertical-align:middle}
.aims-cc-count-table td input[type="number"]{width:72px;padding:4px 6px;font-size:.9em}
.aims-cc-count-table td button{width:auto;padding:4px 9px;font-size:.8em;border-radius:5px;margin:0}
.aims-cc-bucket-badge{background:#e8f0fb;border:1px solid #b2cff8;border-radius:8px;padding:8px 12px;margin:0 0 12px;font-size:.9em}
.aims-cc-bucket-badge strong{display:block;font-size:1.05em}
.aims-cc-notice{padding:10px 12px;border-radius:8px;margin:8px 0;font-size:.9em}
.aims-cc-notice.is-error{background:#fcf0f1;border:1px solid #f5c2c4;color:#8d2020}
.aims-cc-notice.is-success{background:#edfaef;border:1px solid #b2e4bc;color:#1a5c24}
.aims-cc-notice.is-info{background:#f0f6fc;border:1px solid #b2cff8;color:#1d4b7a}
#aims-cc-phase-items{display:none}
#aims-cc-phase-confirm{display:none}
#aims-cc-phase-done{display:none}
.aims-cc-scan-hint{text-align:center;font-size:.82em;color:#646970;margin:4px 0 0}
</style>

<div class="aims-cc-shell">
	<h1><?php echo esc_html( $cc_title ); ?></h1>
	<p class="aims-cc-muted"><?php echo esc_html( $cc_description ); ?></p>
	<div class="aims-cc-card" style="padding:10px 12px;">
		<label class="cc-label" for="aims-cc-scan-mode" style="margin-bottom:4px;">Scanner Mode</label>
		<select id="aims-cc-scan-mode" aria-label="Scanner Mode">
			<option value="auto">Auto (mobile camera first)</option>
			<option value="camera">Camera preferred</option>
			<option value="scanner">Hardware scanner preferred</option>
		</select>
		<p class="aims-cc-muted" style="margin:6px 0 0;">Tip: USB/Bluetooth barcode scanners work as keyboard input in this page.</p>
	</div>

	<!-- ===================== PHASE 1: Scan Bucket ===================== -->
	<div id="aims-cc-phase-bucket">
		<span class="aims-cc-step-badge">Step 1 of 3</span>
		<div class="aims-cc-card">
			<label class="cc-label" for="aims-cc-bucket-text">Scan or enter Bucket ID / Barcode</label>

			<div id="aims-cc-bucket-viewfinder-wrap" class="aims-cc-viewfinder-wrap" style="display:none">
				<div id="aims-cc-viewfinder-wrap">
					<video id="aims-cc-viewfinder" autoplay playsinline muted></video>
					<div id="aims-cc-scan-overlay"></div>
					<div id="aims-cc-scan-flash"></div>
				</div>
				<p class="aims-cc-scan-hint">Point at the bucket barcode</p>
			</div>

			<div class="aims-cc-input-row">
				<input id="aims-cc-bucket-text" type="text" placeholder="Bucket code or barcode&hellip;" autocomplete="off" autocapitalize="characters" spellcheck="false" />
				<button type="button" class="secondary" id="aims-cc-bucket-cam-btn" title="Use camera">&#128247;</button>
				<button type="button" class="primary" id="aims-cc-bucket-lookup-btn">Find</button>
			</div>

			<div id="aims-cc-bucket-status"></div>
		</div>
	</div>

	<!-- ===================== PHASE 2: Scan Items ===================== -->
	<div id="aims-cc-phase-items">
		<span class="aims-cc-step-badge">Step 2 of 3</span>
		<div id="aims-cc-bucket-info-badge" class="aims-cc-bucket-badge"></div>

		<div class="aims-cc-card">
			<label class="cc-label">Scan Each Item</label>

			<div id="aims-cc-item-viewfinder-wrap" style="display:none">
				<div id="aims-cc-viewfinder-wrap-items">
					<video id="aims-cc-viewfinder-items" autoplay playsinline muted></video>
					<div id="aims-cc-scan-overlay-items" class="aims-cc-scan-overlay" style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);border:2px solid rgba(34,113,177,.8);border-radius:6px;width:60%;height:38%;box-shadow:0 0 0 9999px rgba(0,0,0,.35)"></div>
					<div id="aims-cc-scan-flash-items" style="position:absolute;inset:0;pointer-events:none;opacity:0;background:rgba(0,255,120,.35);border-radius:10px;transition:opacity .08s ease"></div>
				</div>
				<p class="aims-cc-scan-hint">Point at item barcode / SKU label</p>
			</div>

			<div class="aims-cc-input-row">
				<input id="aims-cc-item-sku-text" type="text" placeholder="SKU or item barcode&hellip;" autocomplete="off" autocapitalize="characters" spellcheck="false" />
				<button type="button" class="secondary" id="aims-cc-item-cam-btn" title="Use camera">&#128247;</button>
				<button type="button" class="primary" id="aims-cc-item-add-btn">Add</button>
			</div>

			<div id="aims-cc-item-status"></div>

			<table class="aims-cc-count-table" id="aims-cc-count-table" style="display:none">
				<thead><tr><th>SKU</th><th style="width:80px">Qty</th><th style="width:32px"></th></tr></thead>
				<tbody id="aims-cc-count-body"></tbody>
			</table>

			<p id="aims-cc-empty-hint" class="aims-cc-muted" style="display:none">No items scanned yet. Scan or type a SKU above.</p>
		</div>

		<button type="button" class="primary" id="aims-cc-review-btn">Review &amp; Submit Count</button>
		<button type="button" class="secondary" id="aims-cc-back-to-bucket-btn" style="margin-top:6px">&#8592; Back to Bucket Scan</button>
	</div>

	<!-- ===================== PHASE 3: Confirm ===================== -->
	<div id="aims-cc-phase-confirm">
		<span class="aims-cc-step-badge">Step 3 of 3</span>
		<div class="aims-cc-card">
			<strong>Confirm Count</strong>
			<div id="aims-cc-confirm-bucket-badge" class="aims-cc-bucket-badge" style="margin-top:8px"></div>
			<table class="aims-cc-count-table" id="aims-cc-confirm-table">
				<thead><tr><th>SKU</th><th>Quantity</th></tr></thead>
				<tbody id="aims-cc-confirm-body"></tbody>
			</table>
			<label class="cc-label" for="aims-cc-notes" style="margin-top:12px">Notes (optional)</label>
			<textarea id="aims-cc-notes" rows="3" placeholder="Operator notes, discrepancies, context&hellip;"></textarea>
		</div>

		<button type="button" class="primary" id="aims-cc-submit-btn">&#10003; Confirm &amp; Save Count</button>
		<button type="button" class="secondary" id="aims-cc-back-to-items-btn" style="margin-top:6px">&#8592; Back to Scanning</button>
		<div id="aims-cc-submit-status" style="margin-top:8px"></div>
	</div>

	<!-- ===================== DONE ===================== -->
	<div id="aims-cc-phase-done">
		<div class="aims-cc-card">
			<div id="aims-cc-done-message"></div>
			<button type="button" class="primary" id="aims-cc-restart-btn" style="margin-top:12px">Start New Count</button>
		</div>
	</div>
</div>

<script>
(function () {
	'use strict';

	/* ---- Config ---- */
	var REST_BASE   = '<?php echo esc_js( $cc_rest_base ); ?>';
	var REST_NONCE  = '<?php echo esc_js( $cc_rest_nonce ); ?>';
	var BUCKET_ROUTE = '<?php echo esc_js( $cc_bucket_route ); ?>';
	var SUBMIT_ROUTE = '<?php echo esc_js( $cc_submit_route ); ?>';

	/* ---- State ---- */
	var currentBucket   = null;   // bucket object from API
	var countLines      = [];     // [{sku, quantity}]
	var bucketStream    = null;   // MediaStream for bucket camera
	var itemStream      = null;   // MediaStream for item camera
	var bucketScanning  = false;
	var itemScanning    = false;
	var lastBucketScan  = '';
	var lastItemScan    = '';
	var lastScanTs      = 0;
	var SCAN_DEBOUNCE   = 1800;   // ms between duplicate scans
	var SCAN_PREF_KEY   = 'aims_cycle_count_scan_mode';
	var cameraAutoDisabled = false;
	var scanMode = loadScanMode();

	/* ---- DOM refs ---- */
	var phaseBucket  = document.getElementById('aims-cc-phase-bucket');
	var phaseItems   = document.getElementById('aims-cc-phase-items');
	var phaseConfirm = document.getElementById('aims-cc-phase-confirm');
	var phaseDone    = document.getElementById('aims-cc-phase-done');
	var scanModeSelect = document.getElementById('aims-cc-scan-mode');

	if (scanModeSelect) {
		scanModeSelect.value = scanMode;
		scanModeSelect.addEventListener('change', function () {
			scanMode = normalizeScanMode(scanModeSelect.value);
			saveScanMode(scanMode);

			if (scanMode === 'scanner') {
				stopBucketCamera();
				stopItemCamera();
			}
		});
	}

	/* ---- Phase helpers ---- */
	function showPhase(phase) {
		[phaseBucket, phaseItems, phaseConfirm, phaseDone].forEach(function (p) {
			if (p) p.style.display = 'none';
		});
		if (phase) phase.style.display = '';
		stopBucketCamera();
		stopItemCamera();

		if (phase === phaseBucket && bucketTextField) {
			bucketTextField.focus();
			if (shouldAutoUseCamera()) {
				startBucketCamera(true);
			}
		}

		if (phase === phaseItems && itemTextField) {
			itemTextField.focus();
			if (shouldAutoUseCamera()) {
				startItemCamera(true);
			}
		}
	}

	/* ---- Bucket phase UI ---- */
	var bucketTextField  = document.getElementById('aims-cc-bucket-text');
	var bucketCamBtn     = document.getElementById('aims-cc-bucket-cam-btn');
	var bucketLookupBtn  = document.getElementById('aims-cc-bucket-lookup-btn');
	var bucketStatus     = document.getElementById('aims-cc-bucket-status');
	var bucketCamWrap    = document.getElementById('aims-cc-bucket-viewfinder-wrap');
	var bucketVideo      = document.getElementById('aims-cc-viewfinder');
	var bucketFlash      = document.getElementById('aims-cc-scan-flash');

	bucketLookupBtn.addEventListener('click', function () {
		var val = (bucketTextField.value || '').trim();
		if (!val) { showNotice(bucketStatus, 'error', 'Enter a bucket code or barcode.'); return; }
		lookupBucket(val);
	});
	bucketTextField.addEventListener('keydown', function (e) {
		if (e.key === 'Enter' || e.key === 'Tab') bucketLookupBtn.click();
	});
	bucketCamBtn.addEventListener('click', function () {
		if (bucketScanning) {
			stopBucketCamera();
		} else {
			startBucketCamera();
		}
	});

	/* ---- Item phase UI ---- */
	var itemBucketBadge = document.getElementById('aims-cc-bucket-info-badge');
	var itemTextField   = document.getElementById('aims-cc-item-sku-text');
	var itemCamBtn      = document.getElementById('aims-cc-item-cam-btn');
	var itemAddBtn      = document.getElementById('aims-cc-item-add-btn');
	var itemStatus      = document.getElementById('aims-cc-item-status');
	var itemCamWrap     = document.getElementById('aims-cc-item-viewfinder-wrap');
	var itemVideo       = document.getElementById('aims-cc-viewfinder-items');
	var itemFlash       = document.getElementById('aims-cc-scan-flash-items');
	var countBody       = document.getElementById('aims-cc-count-body');
	var countTable      = document.getElementById('aims-cc-count-table');
	var emptyHint       = document.getElementById('aims-cc-empty-hint');
	var reviewBtn       = document.getElementById('aims-cc-review-btn');
	var backToBucketBtn = document.getElementById('aims-cc-back-to-bucket-btn');

	itemAddBtn.addEventListener('click', function () {
		var sku = (itemTextField.value || '').trim();
		if (!sku) { showNotice(itemStatus, 'error', 'Enter a SKU.'); return; }
		addOrIncrementLine(sku);
		itemTextField.value = '';
		itemTextField.focus();
	});
	itemTextField.addEventListener('keydown', function (e) {
		if (e.key === 'Enter' || e.key === 'Tab') itemAddBtn.click();
	});
	itemCamBtn.addEventListener('click', function () {
		if (itemScanning) {
			stopItemCamera();
		} else {
			startItemCamera();
		}
	});
	reviewBtn.addEventListener('click', function () {
		if (countLines.length === 0) {
			showNotice(itemStatus, 'error', 'Add at least one item before reviewing.');
			return;
		}
		buildConfirmView();
		showPhase(phaseConfirm);
	});
	backToBucketBtn.addEventListener('click', function () {
		showPhase(phaseBucket);
		clearNotice(bucketStatus);
	});

	/* ---- Confirm phase UI ---- */
	var confirmBucketBadge = document.getElementById('aims-cc-confirm-bucket-badge');
	var confirmBody        = document.getElementById('aims-cc-confirm-body');
	var notesField         = document.getElementById('aims-cc-notes');
	var submitBtn          = document.getElementById('aims-cc-submit-btn');
	var backToItemsBtn     = document.getElementById('aims-cc-back-to-items-btn');
	var submitStatus       = document.getElementById('aims-cc-submit-status');

	submitBtn.addEventListener('click', function () {
		submitCount();
	});
	backToItemsBtn.addEventListener('click', function () {
		showPhase(phaseItems);
	});

	/* ---- Done phase UI ---- */
	var doneMessage  = document.getElementById('aims-cc-done-message');
	var restartBtn   = document.getElementById('aims-cc-restart-btn');
	restartBtn.addEventListener('click', function () {
		resetAll();
		showPhase(phaseBucket);
	});

	/* ======================== Bucket lookup ======================== */
	function lookupBucket(scanValue) {
		setBusy(bucketLookupBtn, true);
		clearNotice(bucketStatus);

		var url = REST_BASE + BUCKET_ROUTE + '?barcode=' + encodeURIComponent(scanValue);
		apiFetch('GET', url, null)
			.then(function (data) {
				if (data && data.found) {
					currentBucket = data.bucket;
					countLines    = [];
					prepopulateFromPositions(data.positions || []);
					transitionToItems();
				} else {
					var msg = (data && data.message) ? data.message : 'Bucket not found.';
					showNotice(bucketStatus, 'error', msg);
				}
			})
			.catch(function (err) {
				showNotice(bucketStatus, 'error', 'Lookup failed: ' + err.message);
			})
			.finally(function () {
				setBusy(bucketLookupBtn, false);
			});
	}

	function prepopulateFromPositions(positions) {
		positions.forEach(function (pos) {
			if (pos.sku && pos.quantity > 0) {
				addOrIncrementLine(pos.sku, pos.quantity, true);
			}
		});
	}

	function transitionToItems() {
		stopBucketCamera();
		if (currentBucket) {
			var label = currentBucket.bucket_label || currentBucket.bucket_code || 'Bucket';
			var type  = currentBucket.bucket_type  ? ' (' + currentBucket.bucket_type + ')' : '';
			itemBucketBadge.innerHTML = '<strong>' + esc(label) + '</strong>' + esc(type);
		}
		renderCountTable();
		showPhase(phaseItems);
		itemTextField.focus();
	}

	/* ======================== Count lines ======================== */
	function addOrIncrementLine(sku, qty, replace) {
		sku = sku.toUpperCase().replace(/[^A-Z0-9\-_\.\/]/g, '');
		if (!sku) return;

		var existing = countLines.find(function (l) { return l.sku === sku; });
		if (existing) {
			if (replace) {
				existing.quantity = (qty !== undefined) ? qty : existing.quantity;
			} else {
				existing.quantity += (qty !== undefined ? qty : 1);
			}
		} else {
			countLines.push({ sku: sku, quantity: (qty !== undefined ? qty : 1) });
		}

		renderCountTable();
		flashElement(itemFlash);
		showNotice(itemStatus, 'info', 'Added: ' + sku);
		setTimeout(function () { clearNotice(itemStatus); }, 1200);
	}

	function removeLine(sku) {
		countLines = countLines.filter(function (l) { return l.sku !== sku; });
		renderCountTable();
	}

	function renderCountTable() {
		countBody.innerHTML = '';
		if (countLines.length === 0) {
			if (countTable)   countTable.style.display   = 'none';
			if (emptyHint)    emptyHint.style.display    = '';
			return;
		}
		if (countTable) countTable.style.display = '';
		if (emptyHint)  emptyHint.style.display  = 'none';

		countLines.forEach(function (line, idx) {
			var tr = document.createElement('tr');
			tr.innerHTML =
				'<td>' + esc(line.sku) + '</td>' +
				'<td><input type="number" min="0" step="0.0001" value="' + line.quantity + '" data-idx="' + idx + '" class="cc-qty-input" /></td>' +
				'<td><button type="button" class="danger cc-remove-btn" data-sku="' + esc(line.sku) + '" title="Remove">&#10005;</button></td>';
			countBody.appendChild(tr);
		});

		// Bind qty change
		countBody.querySelectorAll('.cc-qty-input').forEach(function (input) {
			input.addEventListener('change', function () {
				var idx = parseInt(this.getAttribute('data-idx'), 10);
				if (countLines[idx]) {
					countLines[idx].quantity = Math.max(0, parseFloat(this.value) || 0);
				}
			});
		});

		// Bind remove
		countBody.querySelectorAll('.cc-remove-btn').forEach(function (btn) {
			btn.addEventListener('click', function () {
				removeLine(this.getAttribute('data-sku'));
			});
		});
	}

	/* ======================== Confirm view ======================== */
	function buildConfirmView() {
		if (currentBucket) {
			var label = currentBucket.bucket_label || currentBucket.bucket_code || 'Bucket';
			confirmBucketBadge.innerHTML = '<strong>' + esc(label) + '</strong> &mdash; ' + countLines.length + ' SKU(s) to record';
		}
		confirmBody.innerHTML = '';
		countLines.forEach(function (line) {
			var tr = document.createElement('tr');
			tr.innerHTML = '<td>' + esc(line.sku) + '</td><td>' + line.quantity + '</td>';
			confirmBody.appendChild(tr);
		});
	}

	/* ======================== Submit ======================== */
	function submitCount() {
		if (!currentBucket || !currentBucket.id) {
			showNotice(submitStatus, 'error', 'No bucket selected.');
			return;
		}
		setBusy(submitBtn, true);
		clearNotice(submitStatus);

		var payload = {
			bucket_id: currentBucket.id,
			lines:     countLines.map(function (l) { return { sku: l.sku, quantity: l.quantity }; }),
			notes:     (notesField.value || '').trim(),
		};

		apiFetch('POST', REST_BASE + SUBMIT_ROUTE, payload)
			.then(function (data) {
				if (data && data.success) {
					var msg = 'Count saved. ' + (data.applied_lines || 0) + ' line(s) recorded.';
					if (data.skipped_lines > 0) msg += ' ' + data.skipped_lines + ' line(s) skipped.';
					doneMessage.innerHTML = '<div class="aims-cc-notice is-success"><strong>Count Complete</strong><br>' + esc(msg) + '</div>';
					showPhase(phaseDone);
				} else {
					var errMsg = (data && data.errors && data.errors.length) ? data.errors.join(' ') : 'Save failed.';
					showNotice(submitStatus, 'error', errMsg);
				}
			})
			.catch(function (err) {
				showNotice(submitStatus, 'error', 'Submit failed: ' + err.message);
			})
			.finally(function () {
				setBusy(submitBtn, false);
			});
	}

	/* ======================== Camera: Bucket ======================== */
	function startBucketCamera(autoStart) {
		autoStart = !!autoStart;
		if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
			showNotice(bucketStatus, 'info', 'Camera scanning is unavailable here. Use a handheld barcode scanner or type the bucket code.');
			return;
		}
		navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: 'environment' } } })
			.then(function (stream) {
				bucketStream   = stream;
				bucketScanning = true;
				bucketVideo.srcObject = stream;
				bucketCamWrap.style.display = '';
				bucketCamBtn.textContent    = '✕ Stop Camera';
				bucketVideo.play();
				scheduleDetect('bucket');
			})
			.catch(function () {
				if (autoStart) {
					cameraAutoDisabled = true;
				}
				showNotice(bucketStatus, 'info', 'Camera access denied. Use a handheld barcode scanner or enter the barcode manually.');
			});
	}

	function stopBucketCamera() {
		bucketScanning = false;
		if (bucketStream) {
			bucketStream.getTracks().forEach(function (t) { t.stop(); });
			bucketStream = null;
		}
		if (bucketCamWrap) bucketCamWrap.style.display = 'none';
		if (bucketCamBtn) bucketCamBtn.textContent = '📷';
	}

	/* ======================== Camera: Items ======================== */
	function startItemCamera(autoStart) {
		autoStart = !!autoStart;
		if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
			showNotice(itemStatus, 'info', 'Camera scanning is unavailable here. Use a handheld barcode scanner or type SKUs.');
			return;
		}
		navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: 'environment' } } })
			.then(function (stream) {
				itemStream   = stream;
				itemScanning = true;
				itemVideo.srcObject = stream;
				itemCamWrap.style.display = '';
				itemCamBtn.textContent    = '✕ Stop Camera';
				itemVideo.play();
				scheduleDetect('items');
			})
			.catch(function () {
				if (autoStart) {
					cameraAutoDisabled = true;
				}
				showNotice(itemStatus, 'info', 'Camera access denied. Use a handheld barcode scanner or enter SKUs manually.');
			});
	}

	function stopItemCamera() {
		itemScanning = false;
		if (itemStream) {
			itemStream.getTracks().forEach(function (t) { t.stop(); });
			itemStream = null;
		}
		if (itemCamWrap)  itemCamWrap.style.display  = 'none';
		if (itemCamBtn) itemCamBtn.textContent = '📷';
	}

	/* ======================== BarcodeDetector ======================== */
	function scheduleDetect(context) {
		if (!window.BarcodeDetector) return;

		var detector    = new BarcodeDetector({ formats: ['code_128','ean_13','ean_8','code_39','upc_a','upc_e','qr_code','itf'] });
		var videoTarget = context === 'bucket' ? bucketVideo : itemVideo;
		var flashTarget = context === 'bucket' ? bucketFlash : itemFlash;

		function tick() {
			var isActive = context === 'bucket' ? bucketScanning : itemScanning;
			if (!isActive) return;

			detector.detect(videoTarget)
				.then(function (barcodes) {
					if (barcodes.length > 0) {
						var val  = barcodes[0].rawValue;
						var now  = Date.now();
						var last = context === 'bucket' ? lastBucketScan : lastItemScan;
						if (val !== last || (now - lastScanTs) > SCAN_DEBOUNCE) {
							lastScanTs = now;
							if (context === 'bucket') {
								lastBucketScan = val;
								flashElement(flashTarget);
								stopBucketCamera();
								bucketTextField.value = val;
								lookupBucket(val);
							} else {
								lastItemScan = val;
								flashElement(flashTarget);
								addOrIncrementLine(val, 1, false);
							}
						}
					}
				})
				.catch(function () { /* ignore */ })
				.finally(function () {
					var isStillActive = context === 'bucket' ? bucketScanning : itemScanning;
					if (isStillActive) setTimeout(tick, 200);
				});
		}

		setTimeout(tick, 300);
	}

	/* ======================== Utilities ======================== */
	function flashElement(el) {
		if (!el) return;
		el.style.opacity = '1';
		setTimeout(function () { el.style.opacity = '0'; }, 200);
	}

	function apiFetch(method, url, body) {
		var opts = {
			method:  method,
			headers: {
				'X-WP-Nonce':   REST_NONCE,
				'Content-Type': 'application/json',
			},
		};
		if (body !== null && method !== 'GET') {
			opts.body = JSON.stringify(body);
		}
		return fetch(url, opts).then(function (resp) {
			if (!resp.ok && resp.status !== 404 && resp.status !== 422) {
				throw new Error('HTTP ' + resp.status);
			}
			return resp.json();
		});
	}

	function showNotice(container, type, msg) {
		if (!container) return;
		var cls = type === 'error' ? 'is-error' : (type === 'success' ? 'is-success' : 'is-info');
		container.innerHTML = '<div class="aims-cc-notice ' + cls + '">' + esc(msg) + '</div>';
	}

	function clearNotice(container) {
		if (container) container.innerHTML = '';
	}

	function esc(str) {
		return String(str)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;')
			.replace(/'/g, '&#39;');
	}

	function setBusy(btn, busy) {
		if (!btn) return;
		btn.disabled = busy;
		if (busy) {
			btn.dataset.origText = btn.textContent;
			btn.textContent = btn.getAttribute('data-busy-label') || 'Please wait\u2026';
		} else {
			btn.textContent = btn.dataset.origText || btn.textContent;
		}
	}

	function resetAll() {
		currentBucket  = null;
		countLines     = [];
		lastBucketScan = '';
		lastItemScan   = '';
		cameraAutoDisabled = false;
		if (bucketTextField) bucketTextField.value = '';
		if (notesField)      notesField.value      = '';
		clearNotice(bucketStatus);
		clearNotice(itemStatus);
		clearNotice(submitStatus);
		renderCountTable();
	}

	function isLikelyMobileBrowser() {
		var ua = navigator.userAgent || '';
		var touchPoints = navigator.maxTouchPoints || 0;
		return /Android|iPhone|iPad|iPod|Mobile/i.test(ua) || touchPoints > 1;
	}

	function normalizeScanMode(mode) {
		if (mode === 'camera' || mode === 'scanner') {
			return mode;
		}

		return 'auto';
	}

	function loadScanMode() {
		try {
			return normalizeScanMode(localStorage.getItem(SCAN_PREF_KEY) || 'auto');
		} catch (e) {
			return 'auto';
		}
	}

	function saveScanMode(mode) {
		try {
			localStorage.setItem(SCAN_PREF_KEY, normalizeScanMode(mode));
		} catch (e) {
			/* ignore localStorage failures */
		}
	}

	function shouldAutoUseCamera() {
		if (scanMode === 'scanner') {
			return false;
		}

		if (cameraAutoDisabled) {
			return false;
		}

		if (!window.isSecureContext) {
			return false;
		}

		if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
			return false;
		}

		if (!window.BarcodeDetector) {
			return false;
		}

		if (scanMode === 'camera') {
			return true;
		}

		return isLikelyMobileBrowser();
	}

	/* ---- BarcodeDetector availability notice ---- */
	if (!window.BarcodeDetector) {
		var hint = document.createElement('p');
		hint.className = 'aims-cc-muted';
		hint.style.fontSize = '.8em';
		hint.textContent = 'Live camera scanning requires Chrome on Android or Safari 15.4+ on iOS. A USB/Bluetooth barcode scanner works as keyboard input, and manual entry is always available.';
		document.getElementById('aims-cc-portal').querySelector('.aims-cc-shell').appendChild(hint);
	}

	/* ---- Init ---- */
	showPhase(phaseBucket);
})();
</script>
</section>
