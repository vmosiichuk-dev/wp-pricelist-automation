/**
 * Stock Sync — Admin JavaScript
 */
(function($) {
    'use strict';

    function getDistributorSlug() {
        var params = new URLSearchParams(window.location.search);
        return params.get('distributor') || 'vininova';
    }

    // ===== UTILS =====

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

    $('#stock-sync-form').on('submit', function(e) {
        e.preventDefault();

        var $form = $(this);
        var $file = $form.find('input[name="xlsx_file"]')[0];
        var mode = $form.find('input[name="sync_mode"]:checked').val();
        var $btn = $form.find('button[type="submit"]');

        if (!$file.files.length) {
            alert('Please select a file.');
            return;
        }

        $btn.prop('disabled', true).text('Uploading...');
        $('#stock-sync-progress').show();
        $('#stock-sync-results').hide();

        uploadFile($file, function(uploadData) {
            $btn.text('Processing...');
            startSync(uploadData.file_path, mode, $btn);
        }, function(error) {
            alert('Upload error: ' + error);
            $btn.prop('disabled', false).text('Start Sync');
            $('#stock-sync-progress').hide();
        });
    });

    function startSync(filePath, mode, $btn) {
        var distributor = getDistributorSlug();
        var dryRun = (mode === 'preview');

        $.ajax({
            url: stockSync.ajaxUrl,
            type: 'POST',
            data: {
                action: 'stock_sync_init',
                nonce: stockSync.nonce,
                distributor_slug: distributor,
                file_path: filePath,
                dry_run: dryRun
            },
            success: function(response) {
                if (!response.success) {
                    alert('Sync init failed: ' + response.data);
                    $btn.prop('disabled', false).text('Start Sync');
                    return;
                }

                var total = response.data.total_batches;
                var runId = response.data.run_id;
                runBatches(filePath, distributor, dryRun, total, 0, {
                    processed: 0,
                    updated: 0,
                    not_found: 0,
                    errors: 0,
                    details: []
                }, $btn, runId);
            },
            error: function() {
                alert('Sync init network error');
                $btn.prop('disabled', false).text('Start Sync');
            }
        });
    }

    function runBatches(filePath, distributor, dryRun, total, current, stats, $btn, runId) {
        if (current >= total) {
            showResults(stats, dryRun);
            $btn.prop('disabled', false).text('Start Sync');
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
                    runBatches(filePath, distributor, dryRun, total, current + 1, stats, $btn, runId);
                } else {
                    stats.errors += 50; // Assume full batch error
                    showResults(stats, dryRun);
                    $btn.prop('disabled', false).text('Start Sync');
                    alert('Batch failed: ' + (response.data || 'Unknown server error'));
                }
            },
            error: function() {
                stats.errors += 50; // Assume full batch error
                showResults(stats, dryRun);
                $btn.prop('disabled', false).text('Start Sync');
                alert('Sync stopped: network/transport error');
            }
        });
    }

    function showResults(stats, dryRun) {
        $('#stock-sync-progress').hide();
        $('#stock-sync-results').show();

        $('#res-total').text(stats.processed);
        $('#res-updated').text(stats.updated);
        $('#res-notfound').text(stats.not_found);
        $('#res-errors').text(stats.errors);

        var modeLabel = dryRun ? 'Would Update' : 'Updated';
        $('#res-updated').prev().text(modeLabel);

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
