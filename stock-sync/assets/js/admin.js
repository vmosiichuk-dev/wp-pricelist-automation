/**
 * Stock Sync — Admin JavaScript
 */
(function($) {
    'use strict';

    /**
     * Get the distributor slug from the current page URL.
     *
     * Looks for the `distributor` query parameter and falls back to `'vininova'` when absent.
     * @returns {string} The distributor slug from the `distributor` URL parameter, or `'vininova'` if not present.
     */
    function getDistributorSlug() {
        var params = new URLSearchParams(window.location.search);
        return params.get('distributor') || 'vininova';
    }

    /**
     * Display warning notice(s) adjacent to a given container, replacing any existing warning.
     * @param {string|HTMLElement|jQuery} containerSelector - Selector, DOM element, or jQuery object identifying the container before which the warning should be inserted.
     * @param {string[]|null|undefined} warnings - Array of warning messages; when non-empty the messages are joined with a space, HTML-escaped, and rendered in a warning notice. If falsy or empty, no notice is inserted.
     */
    function showWarnings(containerSelector, warnings) {
        $(containerSelector).siblings('.stock-sync-warning').remove();
        if (warnings && warnings.length) {
            var html = '<div class="stock-sync-warning notice notice-warning"><p>' + escapeHtml(warnings.join(' ')) + '</p></div>';
            $(containerSelector).before(html);
        }
    }

    /**
     * Uploads the first selected XLSX file via AJAX and forwards the server response to the provided callbacks.
     * @param {HTMLInputElement} fileInput - File input element; its first selected file is sent as `xlsx_file`.
     * @param {function(Object):void} onSuccess - Called with `response.data` when the server responds with success.
     * @param {function(string|Object):void} onError - Called with an error message or response data when upload or network errors occur.
     */

    function uploadFile(fileInput, onSuccess, onError) {
        var formData = new FormData();
        formData.append('xlsx_file', fileInput.files[0]);
        formData.append('action', 'stock_sync_upload_file');
        formData.append('nonce', stockSync.nonce);

        $.ajax({
            url: stockSync.ajaxUrl,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function(response) {
                if (response.success) {
                    onSuccess(response.data);
                } else {
                    onError(response.data || 'Upload failed');
                }
            },
            error: function() {
                onError('Network error during upload');
            }
        });
    }

    // ===== SYNC TAB =====

    var currentSyncFilePath = null;

    $('#stock-sync-form').on('submit', function(e) {
        e.preventDefault();

        var $form = $(this);
        var $file = $form.find('input[name="xlsx_file"]')[0];
        var $btn = $form.find('button[type="submit"]');

        if (!$file.files.length) {
            alert('Please select a file.');
            return;
        }

        $btn.prop('disabled', true).text('Uploading...');
        $('#stock-sync-progress').show();
        $('#stock-sync-results').hide();
        $('#stock-sync-apply').hide();

        uploadFile($file, function(uploadData) {
            currentSyncFilePath = uploadData.file_path;
            $btn.text('Scanning...');
            startScan(uploadData.file_path, $btn);
        }, function(error) {
            alert('Upload error: ' + error);
            $btn.prop('disabled', false).text('Upload & Scan');
            $('#stock-sync-progress').hide();
        });
    });

    /**
     * Initiates a dry-run stock scan for an uploaded XLSX file, runs server-side batches to collect scan statistics, and renders the scan results.
     *
     * On completion the function displays warnings (if any), updates the UI with scan statistics and details, and shows the "Apply" control when the scan indicates there are updates to perform; if no updates are found it hides the apply control and appends an informational message. Network or server failures surface an alert and re-enable the provided button.
     *
     * @param {string} filePath - Server path to the uploaded XLSX file to scan.
     * @param {jQuery} $btn - jQuery button element that will be disabled while the scan runs and re-enabled afterwards; its text is also updated to reflect the current action.
     */
    function startScan(filePath, $btn) {
        var distributor = getDistributorSlug();

        $.ajax({
            url: stockSync.ajaxUrl,
            type: 'POST',
            data: {
                action: 'stock_sync_init',
                nonce: stockSync.nonce,
                distributor_slug: distributor,
                file_path: filePath,
                dry_run: true
            },
            success: function(response) {
                if (!response.success) {
                    alert('Scan failed: ' + response.data);
                    $btn.prop('disabled', false).text('Upload & Scan');
                    return;
                }

                showWarnings('#stock-sync-form', response.data.warnings);

                var total = response.data.total_batches;
                var runId = response.data.run_id;
                runBatches(filePath, distributor, true, total, 0, {
                    processed: 0,
                    updated: 0,
                    not_found: 0,
                    errors: 0,
                    details: []
                }, $btn, runId, function(stats) {
                    $btn.prop('disabled', false).text('Upload & Scan');
                    showResults(stats, true);

                    if (stats.updated > 0) {
                        $('#stock-sync-apply').show();
                    } else {
                        $('#stock-sync-apply').hide();
                        $('#res-details').append('<p class="stock-sync-info">' + escapeHtml('No products would be updated. Start Sync is not needed.') + '</p>');
                    }
                });
            },
            error: function() {
                alert('Scan network error');
                $btn.prop('disabled', false).text('Upload & Scan');
            }
        });
    }

    $('#stock-sync-apply').on('click', function() {
        var $btn = $(this);

        if (!currentSyncFilePath) {
            alert('No file uploaded. Please upload and scan first.');
            return;
        }

        if (!confirm('Are you sure you want to apply these changes to your products?')) {
            return;
        }

        $btn.prop('disabled', true).text('Syncing...');
        $('#stock-sync-progress').show();
        $('#stock-sync-results').hide();

        runActualSync(currentSyncFilePath, $btn);
    });

    /**
     * Start a real (non-dry-run) stock synchronization for the given uploaded file.
     *
     * @param {string} filePath - Server path to the uploaded XLSX file to sync.
     * @param {jQuery} $btn - jQuery-wrapped button element that will be re-enabled and have its label updated when the sync completes or fails.
     */
    function runActualSync(filePath, $btn) {
        var distributor = getDistributorSlug();

        $.ajax({
            url: stockSync.ajaxUrl,
            type: 'POST',
            data: {
                action: 'stock_sync_init',
                nonce: stockSync.nonce,
                distributor_slug: distributor,
                file_path: filePath,
                dry_run: false
            },
            success: function(response) {
                if (!response.success) {
                    alert('Sync init failed: ' + response.data);
                    $btn.prop('disabled', false).text('Start Sync');
                    return;
                }

                showWarnings('#stock-sync-form', response.data.warnings);

                var total = response.data.total_batches;
                var runId = response.data.run_id;
                runBatches(filePath, distributor, false, total, 0, {
                    processed: 0,
                    updated: 0,
                    not_found: 0,
                    errors: 0,
                    details: []
                }, $btn, runId, function(stats) {
                    $btn.prop('disabled', false).text('Start Sync');
                    showResults(stats, false);
                    $('#stock-sync-apply').hide();
                });
            },
            error: function() {
                alert('Sync init network error');
                $btn.prop('disabled', false).text('Start Sync');
            }
        });
    }

    /**
     * Process sync batches for a file by sending repeated AJAX batch requests and aggregating the results.
     *
     * Updates the progress UI while iterating through batches; on completion (all batches processed or on error)
     * invokes `onComplete` with the aggregated `stats`.
     *
     * @param {string} filePath - Path to the uploaded XLSX file on the server.
     * @param {string} distributor - Distributor slug used for the server request.
     * @param {boolean} dryRun - If true, runs a preview scan; if false, performs the actual sync.
     * @param {number} total - Total number of batches to process.
     * @param {number} current - Zero-based index of the batch to process next.
     * @param {Object} stats - Aggregated statistics object; expected properties: `processed`, `updated`, `not_found`, `errors`, `details` (array). This object is mutated in place.
     * @param {jQuery} $btn - jQuery-wrapped button element that will be re-enabled if a fatal error occurs.
     * @param {string|number} runId - Identifier for the server-side run/session.
     * @param {function(Object):void} onComplete - Callback invoked with the final `stats` when processing finishes or stops due to an error.
     */
    function runBatches(filePath, distributor, dryRun, total, current, stats, $btn, runId, onComplete) {
        if (current >= total) {
            onComplete(stats);
            return;
        }

        var progress = Math.round(((current + 1) / total) * 100);
        $('.stock-progress-fill').css('width', progress + '%');
        $('.stock-progress-text').text(progress + '% — Batch ' + (current + 1) + ' of ' + total);

        $.ajax({
            url: stockSync.ajaxUrl,
            type: 'POST',
            data: {
                action: 'stock_sync_batch',
                nonce: stockSync.nonce,
                distributor_slug: distributor,
                file_path: filePath,
                dry_run: dryRun,
                offset: current * 50,
                run_id: runId
            },
            success: function(response) {
                if (response.success) {
                    var r = response.data;
                    stats.processed += r.processed;
                    stats.updated += r.updated;
                    stats.not_found += r.not_found;
                    stats.errors += r.errors;
                    stats.details = stats.details.concat(r.details);
                    runBatches(filePath, distributor, dryRun, total, current + 1, stats, $btn, runId, onComplete);
                } else {
                    stats.errors += 50; // Assume full batch error
                    onComplete(stats);
                    $btn.prop('disabled', false);
                    alert('Batch failed: ' + (response.data || 'Unknown server error'));
                }
            },
            error: function() {
                stats.errors += 50; // Assume full batch error
                onComplete(stats);
                $btn.prop('disabled', false);
                alert('Sync stopped: network/transport error');
            }
        });
    }

    /**
     * Render stock sync summary and detail rows into the admin UI.
     *
     * Updates result counters, result title, updated-label text, and injects a table of up to the first 100 detail rows (with an overflow row if more exist).
     *
     * @param {Object} stats - Aggregated statistics and detail items from a sync run.
     * @param {number} stats.processed - Total items processed.
     * @param {number} stats.updated - Total items that would be or were updated.
     * @param {number} stats.not_found - Total items not found.
     * @param {number} stats.errors - Total errors encountered.
     * @param {Array<Object>} stats.details - Detail entries for individual items.
     * @param {string} stats.details[].distributor_ref - Distributor reference for the item.
     * @param {string} stats.details[].name - Item name from the XLSX.
     * @param {string} stats.details[].status - Item status (e.g., 'updated', 'would_update', 'not_found', etc.).
     * @param {boolean} dryRun - When true, renders a scan preview and uses the "Would Update" label; when false, renders actual sync results and "Updated" label.
     */
    function showResults(stats, dryRun) {
        $('#stock-sync-progress').hide();
        $('#stock-sync-results').show();

        $('#res-total').text(stats.processed);
        $('#res-updated').text(stats.updated);
        $('#res-notfound').text(stats.not_found);
        $('#res-errors').text(stats.errors);

        var title = dryRun ? 'Scan Preview' : 'Sync Results';
        $('#stock-sync-results-title').text(title);

        var modeLabel = dryRun ? 'Would Update' : 'Updated';
        $('#res-updated-label').text(modeLabel);

        var html = '<table class="widefat striped"><thead><tr><th>Ref</th><th>Name</th><th>Status</th></tr></thead><tbody>';
        stats.details.slice(0, 100).forEach(function(d) {
            var statusClass = '';
            if (d.status === 'updated' || d.status === 'would_update') statusClass = 'status-auto';
            else if (d.status === 'not_found') statusClass = 'status-manual';
            else statusClass = 'status-suggest';

            html += '<tr><td>' + escapeHtml(d.distributor_ref || '-') + '</td><td>' + escapeHtml(d.name || '-') + '</td><td class="' + statusClass + '">' + escapeHtml(d.status) + '</td></tr>';
        });
        if (stats.details.length > 100) {
            html += '<tr><td colspan="3">... and ' + escapeHtml(stats.details.length - 100) + ' more</td></tr>';
        }
        html += '</tbody></table>';
        $('#res-details').html(html);
    }

    // ===== BOOTSTRAP TAB =====

    $('#stock-bootstrap-form').on('submit', function(e) {
        e.preventDefault();

        var $form = $(this);
        var $file = $form.find('input[name="xlsx_file"]')[0];
        var $btn = $form.find('button[type="submit"]');

        if (!$file.files.length) {
            alert('Please select a file.');
            return;
        }

        $btn.prop('disabled', true).text('Uploading...');
        $('#stock-bootstrap-progress').show();
        $('#stock-bootstrap-results').hide();

        uploadFile($file, function(uploadData) {
            $btn.text('Analyzing...');
            analyzeBootstrap(uploadData.file_path, $btn);
        }, function(error) {
            alert('Upload error: ' + error);
            $btn.prop('disabled', false).text('Analyze & Match');
            $('#stock-bootstrap-progress').hide();
        });
    });

    /**
     * Request server-side analysis of the uploaded XLSX file and render bootstrap match results and warnings.
     *
     * Sends an AJAX request to analyze the provided file for bootstrap matching; on success it displays any
     * server-provided warnings and renders the matches table. On failure it resets the UI and shows an alert.
     *
     * @param {string} filePath - Path to the uploaded XLSX file to be analyzed.
     * @param {jQuery} $btn - jQuery button element that will be re-enabled and have its label reset when the request completes or fails.
     */
    function analyzeBootstrap(filePath, $btn) {
        var distributor = getDistributorSlug();

        $.ajax({
            url: stockSync.ajaxUrl,
            type: 'POST',
            data: {
                action: 'stock_sync_bootstrap_analyze',
                nonce: stockSync.nonce,
                distributor_slug: distributor,
                file_path: filePath
            },
            success: function(response) {
                $btn.prop('disabled', false).text('Analyze & Match');
                $('#stock-bootstrap-progress').hide();

                if (!response.success) {
                    alert('Analysis failed: ' + response.data);
                    return;
                }

                showWarnings('#stock-bootstrap-form', response.data.warnings);

                renderBootstrapTable(response.data.matches, response.data.total_xlsx, response.data.total_wc, response.data.category_filter);
            },
            error: function() {
                $btn.prop('disabled', false).text('Analyze & Match');
                $('#stock-bootstrap-progress').hide();
                alert('Analysis network error');
            }
        });
    }

    function renderBootstrapTable(matches, totalXlsx, totalWc, categoryFilter) {
        $('#stock-bootstrap-results').show();
        $('#bootstrap-results-title').text('Review Matches');
        var $summary = $('#bootstrap-summary').empty();
        $summary.append(document.createTextNode('XLSX products: '));
        $summary.append($('<strong>').text(totalXlsx));
        $summary.append(document.createTextNode(' | WC products: '));
        $summary.append($('<strong>').text(totalWc));
        if (categoryFilter) {
            $summary.append(document.createTextNode(' | Category filter: '));
            $summary.append($('<strong>').text(categoryFilter));
        }

        var $tbody = $('#bootstrap-matches-body').empty();
        var $unmatchedBody = $('#bootstrap-unmatched-body').empty();
        var highCount = 0;
        var lowCount = 0;

        matches.forEach(function(m) {
            var checked = m.confidence >= 70;
            if (checked) {
                highCount++;
            } else {
                lowCount++;
            }

            var validStatus = ['auto', 'suggest', 'manual'].indexOf(m.status) !== -1 ? m.status : 'manual';
            var statusClass = 'status-' + validStatus;
            var confClass = m.confidence >= 95 ? 'confidence-high' : (m.confidence >= 70 ? 'confidence-medium' : 'confidence-low');

            var $tr = $('<tr>').attr('data-ref', m.distributor_ref || '').attr('data-wc-id', m.wc_id || '');
            $tr.append($('<td>').append($('<input>', {type: 'checkbox', class: 'match-check'}).prop('checked', checked)));
            $tr.append($('<td>').text(m.distributor_ref || '-'));
            $tr.append($('<td>').text(m.xlsx_name || '-'));
            $tr.append($('<td>').text(m.wc_name || '— no match —'));
            $tr.append($('<td>').append($('<span>').addClass('confidence-badge ' + confClass).text(m.confidence + '%')));
            $tr.append($('<td>').addClass(statusClass).text(m.status));

            if (checked) {
                $tbody.append($tr);
            } else {
                $unmatchedBody.append($tr);
            }
        });

        var $details = $('#bootstrap-unmatched-details');
        if (lowCount === 0) {
            $details.hide();
        } else {
            $details.show();
            $details.find('.stock-unmatched-count').text('(' + lowCount + ')');
        }

        $('#stock-bootstrap-save').prop('disabled', false);

        // Check/uncheck all high-confidence matches
        $('#check-all-auto').prop('checked', true).off('change').on('change', function() {
            var isChecked = $(this).prop('checked');
            $('#bootstrap-matches-body tr .match-check').prop('checked', isChecked);
        });

        // Check/uncheck all unmatched
        $('#check-all-unmatched').prop('checked', false).off('change').on('change', function() {
            var isChecked = $(this).prop('checked');
            $('#bootstrap-unmatched-body tr .match-check').prop('checked', isChecked);
        });

        // Auto-save when every match is 70%+
        if (lowCount === 0 && highCount > 0) {
            var $notice = $('<div class="stock-auto-save-notice">').text('All matches are high confidence — saving automatically...');
            $('#stock-bootstrap-results').prepend($notice);
            saveBootstrapMappings(true);
        }
    }

    function saveBootstrapMappings(autoSave) {
        var $btn = $('#stock-bootstrap-save');
        var distributor = getDistributorSlug();
        var matches = [];

        $('#bootstrap-matches-body tr, #bootstrap-unmatched-body tr').each(function() {
            var $row = $(this);
            if ($row.find('.match-check').prop('checked')) {
                matches.push({
                    distributor_ref: $row.data('ref'),
                    wc_id: $row.data('wc-id')
                });
            }
        });

        if (matches.length === 0) {
            if (!autoSave) {
                alert('Please select at least one match to save.');
            }
            return;
        }

        $btn.prop('disabled', true).text('Saving...');

        $.ajax({
            url: stockSync.ajaxUrl,
            type: 'POST',
            data: {
                action: 'stock_sync_bootstrap_save',
                nonce: stockSync.nonce,
                distributor_slug: distributor,
                matches: matches
            },
            success: function(response) {
                $btn.prop('disabled', false).text('Confirm & Save Mappings');
                if (response.success) {
                    if (!autoSave) {
                        alert('Saved ' + response.data.saved + ' mappings successfully!');
                    }
                    showMappedOnly();
                } else {
                    alert('Save failed: ' + response.data);
                }
            },
            error: function() {
                $btn.prop('disabled', false).text('Confirm & Save Mappings');
                alert('Save network error');
            }
        });
    }

    function showMappedOnly() {
        $('#bootstrap-results-title').text('Mapped Positions');

        // Move checked unmatched rows into the main table so they remain visible
        $('#bootstrap-unmatched-body tr').each(function() {
            var $row = $(this);
            if ($row.find('.match-check').prop('checked')) {
                $row.detach().appendTo('#bootstrap-matches-body');
            } else {
                $row.remove();
            }
        });
        $('#bootstrap-unmatched-details').hide();

        // Keep only checked rows in the main table
        var mappedCount = 0;
        $('#bootstrap-matches-body tr').each(function() {
            var $row = $(this);
            if ($row.find('.match-check').prop('checked')) {
                $row.addClass('mapped-row');
                mappedCount++;
            } else {
                $row.remove();
            }
        });

        $('#bootstrap-summary').empty().text('Mapped: ' + mappedCount + ' positions');
        $('#stock-bootstrap-save').prop('disabled', true).text('Saved');
        $('.stock-auto-save-notice').remove();
    }

    $('#stock-bootstrap-save').on('click', function() {
        saveBootstrapMappings(false);
    });

    // ===== TEST PRODUCT TAB =====

    var selectedTestProductId = null;

    $('#stock-test-search-btn').on('click', function() {
        var query = $('#stock-test-search').val().trim();
        if (query.length < 2) {
            alert('Please enter at least 2 characters.');
            return;
        }

        var distributor = getDistributorSlug();
        var $results = $('#stock-test-search-results');
        $results.html('<p>Searching...</p>').show();

        $.ajax({
            url: stockSync.ajaxUrl,
            type: 'POST',
            data: {
                action: 'stock_sync_test_search',
                nonce: stockSync.nonce,
                distributor_slug: distributor,
                q: query
            },
            success: function(response) {
                if (!response.success) {
                    $results.empty().append($('<p>').css('color', 'red').text('Error: ' + response.data));
                    return;
                }

                if (response.data.products.length === 0) {
                    $results.html('<p>No products found.</p>');
                    return;
                }

                var $ul = $('<ul>').addClass('stock-search-list');
                response.data.products.forEach(function(p) {
                    var $a = $('<a>', { href: '#', class: 'stock-select-product' })
                        .attr('data-id', p.id)
                        .attr('data-name', p.name)
                        .attr('data-sku', p.sku);
                    $a.append($('<strong>').text(p.name));
                    if (p.sku) {
                        $a.append(' ').append($('<span>').addClass('stock-sku').text('(' + p.sku + ')'));
                    }
                    $('<li>').append($a).appendTo($ul);
                });
                $results.empty().append($ul);
            },
            error: function() {
                $results.empty().append($('<p>').css('color', 'red').text('Network error.'));
            }
        });
    });

    // Allow Enter key to trigger search
    $('#stock-test-search').on('keypress', function(e) {
        if (e.which === 13) {
            e.preventDefault();
            $('#stock-test-search-btn').click();
        }
    });

    $(document).on('click', '.stock-select-product', function(e) {
        e.preventDefault();
        selectedTestProductId = $(this).data('id');
        $('#stock-test-search-results').hide();
        $('#stock-test-search').val($(this).data('name'));
        loadTestProductDetails(selectedTestProductId);
    });

    function loadTestProductDetails(productId) {
        var distributor = getDistributorSlug();

        $.ajax({
            url: stockSync.ajaxUrl,
            type: 'POST',
            data: {
                action: 'stock_sync_test_get_product',
                nonce: stockSync.nonce,
                distributor_slug: distributor,
                product_id: productId
            },
            success: function(response) {
                if (!response.success) {
                    alert('Failed to load product: ' + response.data);
                    return;
                }

                var d = response.data;
                $('#test-current-id-sku').text('#' + d.id + ' / ' + (d.sku || '—'));
                $('#test-current-name').text(d.name);
                $('#test-new-name').text(d.new_name);
                $('#test-current-slug').text(d.slug || '—');
                $('#test-new-slug').text(d.new_slug);
                $('#test-current-visibility').text(d.visibility);
                $('#test-current-price').text(d.price || '—');
                $('#test-current-sale').text(d.sale_price || '—');
                $('#test-current-excerpt').text(d.excerpt || '—');
                $('#test-new-excerpt').text(d.new_excerpt);

                $('#stock-test-selected').show();
                $('#stock-test-apply').prop('disabled', false);
                $('#stock-test-status').text('');
            },
            error: function() {
                alert('Network error loading product details.');
            }
        });
    }

    $('#stock-test-apply').on('click', function() {
        if (!selectedTestProductId) return;

        var $btn = $(this);
        var distributor = getDistributorSlug();

        if (!confirm('Are you sure you want to update this single product?')) {
            return;
        }

        $btn.prop('disabled', true).text('Applying...');
        $('#stock-test-status').text('');

        $.ajax({
            url: stockSync.ajaxUrl,
            type: 'POST',
            data: {
                action: 'stock_sync_test_apply',
                nonce: stockSync.nonce,
                distributor_slug: distributor,
                product_id: selectedTestProductId
            },
            success: function(response) {
                $btn.prop('disabled', false).text('Apply Test Update to This Product');
                if (response.success) {
                    $('#stock-test-status').empty().append($('<span>').css('color', 'green').text(response.data.message));
                    // Refresh details
                    loadTestProductDetails(selectedTestProductId);
                } else {
                    $('#stock-test-status').empty().append($('<span>').css('color', 'red').text('Error: ' + response.data));
                }
            },
            error: function() {
                $btn.prop('disabled', false).text('Apply Test Update to This Product');
                $('#stock-test-status').empty().append($('<span>').css('color', 'red').text('Network error.'));
            }
        });
    });

    function escapeHtml(text) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(text));
        return div.innerHTML;
    }

})(jQuery);
