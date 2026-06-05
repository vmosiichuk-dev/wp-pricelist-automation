/**
 * Stock Sync — Admin JavaScript (Unified Sync Tab)
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

    function escapeHtml(text) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(text));
        return div.innerHTML;
    }
  
    /**
     * Uploads the first selected XLSX file via AJAX and forwards the server response to the provided callbacks.
     * @param {HTMLInputElement} fileInput - File input element; its first selected file is sent as `xlsx_file`.
     * @param {function(Object):void} onSuccess - Called with `response.data` when the server responds with success.
     * @param {function(string|Object):void} onError - Called with an error message or response data when upload or network errors occur.
     */
    function uploadFile(file, onSuccess, onError) {
        var formData = new FormData();
        formData.append('xlsx_file', file);
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

    // ===== STATE =====

    var currentSyncFilePath = null;
    var currentRunId = null;
    var currentDryRunStats = null;
    var globalTotalBatches = 0;
    var globalCurrentBatch = 0;

    // ===== PROGRESS BAR =====

    function showProgress(text) {
        $('#stock-sync-progress').show();
        if (text) {
            $('.stock-progress-fill').css('width', '0%');
            $('.stock-progress-text').text(text);
        }
    }

    function updateProgressPercent(percent, text) {
        $('#stock-sync-progress').show();
        $('.stock-progress-fill').css('width', Math.round(percent) + '%');
        if (text) {
            $('.stock-progress-text').text(Math.round(percent) + '% — ' + text);
        } else {
            $('.stock-progress-text').text(Math.round(percent) + '%');
        }
    }

    function hideProgress() {
        $('#stock-sync-progress').hide();
    }

    // ===== STEP 1: UPLOAD =====

    var droppedFile = null;

    // Disable upload button by default; enable when file is chosen
    $('#stock-sync-upload').prop('disabled', true);

    // Show selected filename in dropzone and enable upload button
    function onFileChosen(file) {
        if (file) {
            $('#stock-upload-filename').text(file.name);
            $('#stock-sync-upload').prop('disabled', false);
        }
    }

    $('#stock-xlsx-file').on('change', function() {
        onFileChosen(this.files[0]);
    });

    // Drag-and-drop handlers
    var $dropzone = $('#stock-upload-dropzone');

    $dropzone.on('dragover', function(e) {
        e.preventDefault();
        e.stopPropagation();
        $(this).addClass('drag-over');
    });

    $dropzone.on('dragleave', function(e) {
        e.preventDefault();
        e.stopPropagation();
        $(this).removeClass('drag-over');
    });

    $dropzone.on('drop', function(e) {
        e.preventDefault();
        e.stopPropagation();
        $(this).removeClass('drag-over');

        var files = e.originalEvent.dataTransfer.files;
        if (files.length > 0) {
            droppedFile = files[0];
            onFileChosen(droppedFile);
        }
    });

    $('#stock-sync-form').on('submit', function(e) {
        e.preventDefault();

        var $form = $(this);
        var $input = $form.find('input[name="xlsx_file"]')[0];
        var $btn = $form.find('button[type="submit"]');

        var file = droppedFile || ($input.files.length ? $input.files[0] : null);

        if (!file) {
            alert('Please select a file.');
            return;
        }

        $btn.prop('disabled', true).addClass('stock-btn-spinner').text('Uploading...');
        $('#stock-xlsx-file').prop('disabled', true);
        resetAllSteps();
        globalTotalBatches = 0;
        globalCurrentBatch = 0;

        uploadFile(file, function(uploadData) {
            currentSyncFilePath = uploadData.file_path;
            $btn.prop('disabled', false).removeClass('stock-btn-spinner').text('Upload');
            $('#stock-xlsx-file').prop('disabled', false);
            analyzeAndMap(uploadData.file_path);
        }, function(error) {
            alert('Upload error: ' + error);
            $btn.prop('disabled', false).removeClass('stock-btn-spinner').text('Upload');
            $('#stock-xlsx-file').prop('disabled', false);
        });
    });

    function resetAllSteps() {
        $('#sync-step-upload').show();
        $('#sync-step-mapping').hide();
        $('#sync-step-preview').hide();
        $('#stock-sync-results').hide();
        $('#sync-duplicate-notice').hide();
        $('#sync-mapping-body').empty();
        $('#sync-unmatched-body').empty();
        $('#sync-preview-body').empty();
        $('#sync-preview-unmatched-body').empty();
        currentRunId = null;
        currentDryRunStats = null;
        globalTotalBatches = 0;
        globalCurrentBatch = 0;
        droppedFile = null;
        $('#stock-upload-filename').text('');
        $('#stock-xlsx-file').val('');
        $('#stock-sync-upload').prop('disabled', true);
    }

    // ===== STEP 2: ANALYZE & MAP =====

    function analyzeAndMap(filePath) {
        var distributor = getDistributorSlug();
        var headerRef   = $('#header_label_ref').val().trim();
        var headerAvail = $('#header_label_avail').val().trim();

        var ajaxData = {
            action: 'stock_sync_bootstrap_analyze',
            nonce: stockSync.nonce,
            distributor_slug: distributor,
            file_path: filePath
        };

        if (headerRef) {
            ajaxData.header_label_ref = headerRef;
        }
        if (headerAvail) {
            ajaxData.header_label_avail = headerAvail;
        }

        $.ajax({
            url: stockSync.ajaxUrl,
            type: 'POST',
            data: ajaxData,
            success: function(response) {
                if (!response.success) {
                    alert('Analysis failed: ' + response.data);
                    hideProgress();
                    $('#sync-step-upload form').show();
                    return;
                }

                showWarnings('#stock-sync-form', response.data.warnings);

                var matches = response.data.matches || [];
                var alreadyMappedItems = response.data.already_mapped_items || [];
                var alreadyMappedCount = response.data.already_mapped_count || 0;

                // Show auto-mapped notice for previously mapped products
                if (alreadyMappedCount > 0) {
                    $('#sync-auto-mapped-notice')
                        .text(alreadyMappedCount + ' products were already mapped.')
                        .show();
                }

                if (matches.length > 0 || alreadyMappedItems.length > 0) {
                    updateProgressPercent(10, 'Review mappings...');
                    showMappingReview(matches, alreadyMappedItems);
                } else {
                    updateProgressPercent(10, 'Starting scan...');
                    startDryRun(filePath);
                }
            },
            error: function() {
                alert('Analysis network error');
                hideProgress();
                $('#sync-step-upload form').show();
            }
        });
    }

    function showMappingReview(matches, alreadyMappedItems) {
        $('#sync-step-upload').hide();
        $('#sync-step-mapping').show();
        hideProgress();

        var $tbody = $('#sync-mapping-body').empty();
        var $unmatchedBody = $('#sync-unmatched-body').empty();
        var suggestCount = 0;
        var manualCount = 0;

        // Render already-mapped items (non-interactive)
        if (alreadyMappedItems && alreadyMappedItems.length > 0) {
            alreadyMappedItems.forEach(function(m) {
                var $tr = $('<tr>').addClass('stock-already-mapped')
                    .attr('data-ref', m.distributor_ref || '')
                    .attr('data-wc-id', m.wc_id || '');
                $tr.append($('<td>').append($('<input>', {type: 'checkbox', class: 'match-check', checked: true, title: 'Already mapped'})));
                $tr.append($('<td>').text(m.distributor_ref || '-'));
                $tr.append($('<td>').text(m.xlsx_name || '-'));
                $tr.append($('<td>').addClass('wc-product-cell').text(m.wc_name || '—'));
                $tr.append($('<td>').append($('<span>').addClass('confidence-badge confidence-high').text('100%')));
                $tr.append($('<td>').addClass('status-auto').text('mapped'));
                $tr.append($('<td>').append($('<span>').addClass('stock-mapped-label').text('Already mapped')));
                $tbody.append($tr);
            });
        }

        matches.forEach(function(m) {
            var isSuggest = m.confidence >= 70;
            var checked = isSuggest; // Suggest checked by default, manual unchecked
            if (isSuggest) {
                suggestCount++;
            } else {
                manualCount++;
            }

            var validStatus = ['auto', 'suggest', 'manual'].indexOf(m.status) !== -1 ? m.status : 'manual';
            var statusClass = 'status-' + validStatus;
            var confClass = m.confidence >= 90 ? 'confidence-high' : (m.confidence >= 70 ? 'confidence-medium' : 'confidence-low');

            var $tr = $('<tr>')
                .attr('data-ref', m.distributor_ref || '')
                .attr('data-wc-id', m.wc_id || '')
                .attr('data-original-confidence', m.confidence)
                .attr('data-original-status', m.status)
                .attr('data-changed', 'false');
            $tr.append($('<td>').append($('<input>', {type: 'checkbox', class: 'match-check'}).prop('checked', checked)));
            $tr.append($('<td>').text(m.distributor_ref || '-'));
            $tr.append($('<td>').text(m.xlsx_name || '-'));

            var $wcCell = $('<td>').addClass('wc-product-cell')
                .attr('data-original-wc-name', m.wc_name || '— no match —')
                .attr('data-original-wc-id', m.wc_id || '')
                .text(m.wc_name || '— no match —');
            $tr.append($wcCell);

            $tr.append($('<td>').append($('<span>').addClass('confidence-badge ' + confClass).text(m.confidence + '%')));
            $tr.append($('<td>').addClass(statusClass).text(m.status));

            // Action column: Change + Revert (Revert disabled until changed)
            var $actionCell = $('<td>').addClass('stock-action-cell');
            var $changeBtn = $('<button>', {type: 'button', class: 'button button-small stock-change-match'})
                .text('Change');
            var $revertBtn = $('<button>', {type: 'button', class: 'button button-small stock-revert-match'})
                .text('Revert')
                .prop('disabled', true);
            $actionCell.append($changeBtn).append($revertBtn);
            $tr.append($actionCell);

            if (isSuggest) {
                $tbody.append($tr);
            } else {
                $unmatchedBody.append($tr);
            }
        });

        // Show/hide unmatched section
        var $details = $('#sync-unmatched-details');
        if (manualCount === 0) {
            $details.hide();
        } else {
            $details.show();
            $details.find('.stock-unmatched-count').text('(' + manualCount + ')');
        }

        // Enable confirm button
        $('#stock-sync-confirm-mappings').prop('disabled', false);

        // Check/uncheck all mapping rows
        $('#check-all-mapping').prop('checked', true).off('change').on('change', function() {
            var isChecked = $(this).prop('checked');
            $('#sync-mapping-body .match-check').prop('checked', isChecked);
            handleBulkDuplicateCheck();
        });

        // Check/uncheck all unmatched rows
        $('#check-all-unmatched').prop('checked', false).off('change').on('change', function() {
            var isChecked = $(this).prop('checked');
            $('#sync-unmatched-body .match-check').prop('checked', isChecked);
            handleBulkDuplicateCheck();
        });

        // Validate initial state for duplicates
        validateMappingDuplicates(true);
    }

    // ===== INLINE CHANGE MATCH =====

    var activeInlineSearch = null;

    $(document).on('click', '.stock-change-match', function(e) {
        e.preventDefault();
        var $btn = $(this);
        var $row = $btn.closest('tr');
        var $cell = $row.find('.wc-product-cell');
        var $actionCell = $row.find('.stock-action-cell');

        // Remove any existing inline search
        if (activeInlineSearch) {
            activeInlineSearch.closest('tr').find('.stock-action-cell .stock-cancel-match').trigger('click');
        }

        // Store original suggestion if not already stored
        if (!$cell.attr('data-original-wc-name')) {
            $cell.attr('data-original-wc-name', $cell.text());
        }
        if (!$cell.attr('data-original-wc-id')) {
            $cell.attr('data-original-wc-id', $row.attr('data-wc-id') || '');
        }

        // Replace action buttons with Cancel
        $actionCell.empty();
        var $cancelBtn = $('<button>', {type: 'button', class: 'button button-small stock-cancel-match'})
            .text('Cancel');
        $actionCell.append($cancelBtn);

        // Wrap existing text in a hidden span to preserve column width
        var currentText = $cell.text();
        $cell.empty().append($('<span>').addClass('stock-cell-text-hidden').text(currentText));

        // Show search field in wc-product-cell
        var $searchWrap = $('<div>').addClass('stock-inline-search');
        var $input = $('<input>', {type: 'text', placeholder: 'Search product name or SKU...'});
        var $results = $('<div>').addClass('stock-inline-search-results');
        $searchWrap.append($input).append($results);
        $cell.append($searchWrap);
        $input.focus();
        activeInlineSearch = $searchWrap;

        $cancelBtn.on('click', function(ev) {
            ev.stopPropagation();
            cancelInlineChange($row, $cell);
        });

        var searchTimeout = null;
        $input.on('input', function() {
            var query = $(this).val().trim();
            if (query.length < 2) {
                $results.empty();
                return;
            }

            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(function() {
                performInlineSearch(query, $results, $row, $cell);
            }, 300);
        });

        // Cancel on Escape
        $input.on('keydown', function(e) {
            if (e.which === 27) {
                cancelInlineChange($row, $cell);
            }
        });
    });

    function performInlineSearch(query, $resultsContainer, $row, $cell) {
        var distributor = getDistributorSlug();

        $.ajax({
            url: stockSync.ajaxUrl,
            type: 'POST',
            data: {
                action: 'stock_sync_test_search',
                nonce: stockSync.nonce,
                distributor_slug: distributor,
                q: query,
                limit: 10
            },
            success: function(response) {
                if (!response.success) {
                    $resultsContainer.html('<p class="stock-inline-error">' + escapeHtml(response.data) + '</p>');
                    return;
                }

                var products = response.data.products;
                if (products.length === 0) {
                    $resultsContainer.html('<p class="stock-inline-info">No products found.</p>');
                    return;
                }

                var $ul = $('<ul>').addClass('stock-inline-search-list');
                products.forEach(function(p) {
                    var $a = $('<a>', {href: '#'})
                        .attr('data-id', p.id)
                        .attr('data-name', p.name)
                        .attr('data-sku', p.sku)
                        .append($('<strong>').text(p.name));
                    if (p.sku) {
                        $a.append(' ').append($('<span>').addClass('stock-sku').text('(' + p.sku + ')'));
                    }
                    $ul.append($('<li>').append($a));
                });
                $resultsContainer.empty().append($ul);
            },
            error: function() {
                $resultsContainer.html('<p class="stock-inline-error">Network error.</p>');
            }
        });
    }

    $(document).on('click', '.stock-inline-search-list a', function(e) {
        e.preventDefault();
        var $a = $(this);
        var $row = $a.closest('tr');
        var $cell = $row.find('.wc-product-cell');
        var $actionCell = $row.find('.stock-action-cell');
        var newId = $a.data('id');
        var newName = $a.data('name');

        // Prevent selecting a product already mapped to another checked row
        var duplicateRef = '';
        $('#sync-mapping-body tr, #sync-unmatched-body tr').each(function() {
            var $r = $(this);
            if ($r.is($row)) return;
            if ($r.find('.match-check').prop('checked') && String($r.attr('data-wc-id')) === String(newId)) {
                duplicateRef = $r.attr('data-ref') || 'another reference';
                return false;
            }
        });

        if (duplicateRef) {
            showDuplicateBanner([{ wcId: newId, refs: [duplicateRef, $row.attr('data-ref') || 'this row'] }]);
            return;
        }

        // Update row data and display
        $row.attr('data-wc-id', newId);
        $row.attr('data-changed', 'true');
        $cell.text(newName);

        // Update confidence badge to 100%
        var $confCell = $row.find('td').eq(4);
        $confCell.empty().append($('<span>').addClass('confidence-badge confidence-high').text('100%'));

        // Update status
        var $statusCell = $row.find('td').eq(5);
        $statusCell.removeClass('status-suggest status-manual').addClass('status-auto').text('changed');

        // Pre-check the row since user explicitly selected it
        $row.find('.match-check').prop('checked', true);

        // Restore action cell with Change + Revert (Revert enabled)
        $actionCell.empty();
        var $changeBtn = $('<button>', {type: 'button', class: 'button button-small stock-change-match'}).text('Change');
        var $revertBtn = $('<button>', {type: 'button', class: 'button button-small stock-revert-match'}).text('Revert');
        $actionCell.append($changeBtn).append($revertBtn);

        activeInlineSearch = null;
        validateMappingDuplicates(true);
    });

    $(document).on('click', '.stock-revert-match', function(e) {
        e.preventDefault();
        var $btn = $(this);
        var $row = $btn.closest('tr');
        var $cell = $row.find('.wc-product-cell');

        var originalName = $cell.attr('data-original-wc-name') || '— no match —';
        var originalId = $cell.attr('data-original-wc-id') || '';

        // Restore original suggestion
        $row.attr('data-wc-id', originalId);
        $row.attr('data-changed', 'false');
        $cell.text(originalName);

        // Restore original confidence and status
        var originalConf = parseInt($row.attr('data-original-confidence') || '0', 10);
        var originalStatus = $row.attr('data-original-status') || 'manual';

        var confClass = originalConf >= 90 ? 'confidence-high' : (originalConf >= 70 ? 'confidence-medium' : 'confidence-low');
        var $confCell = $row.find('td').eq(4);
        $confCell.empty().append($('<span>').addClass('confidence-badge ' + confClass).text(originalConf + '%'));

        var statusClass = 'status-' + originalStatus;
        var $statusCell = $row.find('td').eq(5);
        $statusCell.removeClass('status-auto status-suggest status-manual').addClass(statusClass).text(originalStatus);

        // Uncheck the row since we're reverting to the original suggestion
        $row.find('.match-check').prop('checked', false);

        // Disable Revert button
        $btn.prop('disabled', true);
        validateMappingDuplicates(true);
    });

    $(document).on('click', '.stock-cancel-match', function(e) {
        e.preventDefault();
        var $btn = $(this);
        var $row = $btn.closest('tr');
        var $cell = $row.find('.wc-product-cell');
        cancelInlineChange($row, $cell);
    });

    function cancelInlineChange($row, $cell) {
        var originalName = $cell.attr('data-original-wc-name') || '— no match —';
        $cell.text(originalName);
        activeInlineSearch = null;

        // Restore action cell with Change + Revert
        var $actionCell = $row.find('.stock-action-cell');
        var isChanged = $row.attr('data-changed') === 'true';
        var $changeBtn = $('<button>', {type: 'button', class: 'button button-small stock-change-match'}).text('Change');
        var $revertBtn = $('<button>', {type: 'button', class: 'button button-small stock-revert-match'}).text('Revert');
        $revertBtn.prop('disabled', !isChanged);
        $actionCell.empty().append($changeBtn).append($revertBtn);
    }

    function getMappingDuplicates() {
        var wcIdToRef = {};
        var duplicates = [];
        $('#sync-mapping-body tr, #sync-unmatched-body tr').each(function() {
            var $row = $(this);
            if ($row.find('.match-check').prop('checked')) {
                var wcId = $row.attr('data-wc-id');
                var ref = $row.attr('data-ref');
                if (wcId) {
                    if (wcIdToRef[wcId]) {
                        duplicates.push({ wcId: wcId, refs: [wcIdToRef[wcId], ref] });
                    } else {
                        wcIdToRef[wcId] = ref;
                    }
                }
            }
        });
        return duplicates;
    }

    function buildDuplicateBannerHtml(duplicates) {
        var html = '<p><strong>' + escapeHtml('Duplicate mapping' + (duplicates.length > 1 ? 's' : '') + ' detected.') + '</strong></p>';
        html += '<ul class="stock-duplicate-list">';
        duplicates.forEach(function(d) {
            html += '<li>' + escapeHtml('Product ID ' + d.wcId + ' is mapped to refs: ' + d.refs.join(', ')) + '</li>';
        });
        html += '</ul>';
        html += '<p>' + escapeHtml('Each WooCommerce product can only be mapped to one distributor reference. Please adjust your selections.') + '</p>';
        return html;
    }

    function showDuplicateBanner(duplicates) {
        var $notice = $('#sync-duplicate-notice');
        $notice.html(buildDuplicateBannerHtml(duplicates)).show();
    }

    function hideDuplicateBanner() {
        $('#sync-duplicate-notice').hide().empty();
    }

    function validateMappingDuplicates(suppressBanner) {
        var duplicates = getMappingDuplicates();
        var isValid = duplicates.length === 0;
        $('#stock-sync-confirm-mappings').prop('disabled', !isValid);
        if (isValid) {
            hideDuplicateBanner();
        } else if (!suppressBanner) {
            showDuplicateBanner(duplicates);
        }
        return isValid;
    }

    function handleBulkDuplicateCheck() {
        var duplicates = getMappingDuplicates();
        var isValid = duplicates.length === 0;
        $('#stock-sync-confirm-mappings').prop('disabled', !isValid);
        if (!isValid) {
            showDuplicateBanner(duplicates);
        } else {
            hideDuplicateBanner();
        }
        return isValid;
    }

    // Validate duplicates on individual checkbox changes
    $(document).off('change', '.match-check').on('change', '.match-check', function() {
        validateMappingDuplicates();
    });

    // ===== CONFIRM MAPPINGS =====

    $('#stock-sync-confirm-mappings').on('click', function() {
        var $btn = $(this);
        var distributor = getDistributorSlug();
        var matches = [];

        $('#sync-mapping-body tr, #sync-unmatched-body tr').each(function() {
            var $row = $(this);
            if ($row.find('.match-check').prop('checked')) {
                matches.push({
                    distributor_ref: $row.data('ref'),
                    wc_id: $row.data('wc-id')
                });
            }
        });

        if (matches.length === 0) {
            alert('Please select at least one match to save.');
            return;
        }

        $btn.prop('disabled', true).text('Saving...');
        showProgress('Saving mappings...');

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
                $btn.prop('disabled', false).text('Confirm Mappings & Continue');
                if (response.success) {
                    $('#sync-step-mapping').hide();
                    updateProgressPercent(15, 'Starting scan...');
                    startDryRun(currentSyncFilePath);
                } else {
                    alert('Save failed: ' + response.data);
                    hideProgress();
                }
            },
            error: function() {
                $btn.prop('disabled', false).text('Confirm Mappings & Continue');
                alert('Save network error');
                hideProgress();
            }
        });
    });

    // ===== STEP 3: DRY-RUN PREVIEW =====

    function startDryRun(filePath) {
        $('#sync-step-preview').show();
        $('#sync-preview-body').empty();
        $('#sync-preview-unmatched-body').empty();

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
                    hideProgress();
                    return;
                }

                showWarnings('#stock-sync-form', response.data.warnings);

                var total = response.data.total_batches;
                var runId = response.data.run_id;
                currentRunId = runId;
                globalTotalBatches += total;

                runBatches(filePath, distributor, true, total, 0, {
                    processed: 0,
                    updated: 0,
                    not_found: 0,
                    errors: 0,
                    details: []
                }, null, runId, function(stats) {
                    currentDryRunStats = stats;
                    showPreviewResults(stats);
                });
            },
            error: function() {
                alert('Scan network error');
                hideProgress();
            }
        });
    }

    function showPreviewResults(stats) {
        hideProgress();

        var $tbody = $('#sync-preview-body').empty();
        var $unmatchedBody = $('#sync-preview-unmatched-body').empty();
        var updateCount = 0;
        var unmatchedCount = 0;

        stats.details.forEach(function(d) {
            if (d.status === 'would_update' || d.status === 'updated') {
                updateCount++;
                var $tr = $('<tr>').attr('data-ref', d.distributor_ref || '');
                $tr.append($('<td>').append($('<input>', {type: 'checkbox', class: 'preview-check'}).prop('checked', true)));
                $tr.append($('<td>').text(d.distributor_ref || '-'));
                $tr.append($('<td>').text(d.name || '-'));
                $tr.append($('<td>').addClass('status-auto').text('will update'));
                $tbody.append($tr);
            } else if (d.status === 'not_found') {
                unmatchedCount++;
                var $tr = $('<tr>');
                $tr.append($('<td>').text(d.distributor_ref || '-'));
                $tr.append($('<td>').text(d.name || '-'));
                $tr.append($('<td>').addClass('status-manual').text('not found'));
                $unmatchedBody.append($tr);
            }
        });

        // Show summary
        var summaryText = 'Products to update: ' + updateCount;
        if (unmatchedCount > 0) {
            summaryText += ' | Unmatched: ' + unmatchedCount;
        }
        $('#sync-preview-summary').text(summaryText);

        // Show/hide unmatched section
        var $details = $('#sync-preview-unmatched-details');
        if (unmatchedCount === 0) {
            $details.hide();
        } else {
            $details.show();
            $details.find('.stock-unmatched-count').text('(' + unmatchedCount + ')');
        }

        // Enable/disable apply button based on checked count
        updateApplyButtonState();

        // Check/uncheck all preview rows
        $('#check-all-preview').prop('checked', true).off('change').on('change', function() {
            var isChecked = $(this).prop('checked');
            $('#sync-preview-body tr .preview-check').prop('checked', isChecked);
            updateApplyButtonState();
        });

        // Individual checkbox changes update apply button
        $(document).off('change', '.preview-check').on('change', '.preview-check', function() {
            updateApplyButtonState();
        });
    }

    function updateApplyButtonState() {
        var checkedCount = $('#sync-preview-body tr .preview-check:checked').length;
        $('#stock-sync-apply').prop('disabled', checkedCount === 0);
    }

    // ===== APPLY SYNC =====

    $('#stock-sync-apply').on('click', function() {
        var $btn = $(this);

        if (!currentSyncFilePath || !currentRunId) {
            alert('No sync data available. Please start over.');
            return;
        }

        // Collect checked refs
        var includeRefs = [];
        $('#sync-preview-body tr').each(function() {
            var $row = $(this);
            if ($row.find('.preview-check').prop('checked')) {
                var ref = $row.data('ref');
                if (ref) {
                    includeRefs.push(ref);
                }
            }
        });

        if (includeRefs.length === 0) {
            alert('Please select at least one product to update.');
            return;
        }

        $btn.prop('disabled', true).text('Filtering...');
        showProgress('Preparing sync...');
        $('#stock-sync-results').hide();

        // Filter queue to only selected refs
        filterAndApply(includeRefs, $btn);
    });

    function filterAndApply(includeRefs, $btn) {
        var distributor = getDistributorSlug();

        $.ajax({
            url: stockSync.ajaxUrl,
            type: 'POST',
            data: {
                action: 'stock_sync_filter_queue',
                nonce: stockSync.nonce,
                distributor_slug: distributor,
                run_id: currentRunId,
                include_refs: includeRefs
            },
            success: function(response) {
                if (!response.success) {
                    alert('Filter failed: ' + response.data);
                    $btn.prop('disabled', false).text('Apply Sync');
                    hideProgress();
                    return;
                }

                var newRunId = response.data.run_id;
                var total = response.data.total_batches;
                globalTotalBatches += total;

                $btn.text('Syncing...');
                runBatches(currentSyncFilePath, distributor, false, total, 0, {
                    processed: 0,
                    updated: 0,
                    not_found: 0,
                    errors: 0,
                    details: []
                }, $btn, newRunId, function(stats) {
                    $btn.prop('disabled', false).text('Apply Sync');
                    hideProgress();
                    showFinalResults(stats);
                });
            },
            error: function() {
                alert('Filter network error');
                $btn.prop('disabled', false).text('Apply Sync');
                hideProgress();
            }
        });
    }

    // ===== BATCH RUNNER (shared, unified progress) =====

    function runBatches(filePath, distributor, dryRun, total, current, stats, $btn, runId, onComplete) {
        if (current >= total) {
            onComplete(stats);
            return;
        }

        globalCurrentBatch++;
        var progress = globalTotalBatches > 0 ? (globalCurrentBatch / globalTotalBatches) * 100 : 0;
        var phaseText = dryRun ? 'Scanning batch ' + (current + 1) + ' of ' + total : 'Syncing batch ' + (current + 1) + ' of ' + total;
        updateProgressPercent(progress, phaseText);

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
                    stats.errors += 50;
                    onComplete(stats);
                    if ($btn) $btn.prop('disabled', false);
                    alert('Batch failed: ' + (response.data || 'Unknown server error'));
                }
            },
            error: function() {
                stats.errors += 50;
                onComplete(stats);
                if ($btn) $btn.prop('disabled', false);
                alert('Sync stopped: network/transport error');
            }
        });
    }

    function showFinalResults(stats) {
        $('#sync-step-preview').hide();
        $('#stock-sync-results').show();

        $('#res-total').text(stats.processed);
        $('#res-updated').text(stats.updated);
        $('#res-notfound').text(stats.not_found);
        $('#res-errors').text(stats.errors);

        $('#stock-sync-results-title').text('Sync Results');
        $('#res-updated-label').text('Updated');

        var html = '<table class="widefat striped"><thead><tr><th>Ref</th><th>Name</th><th>Status</th></tr></thead><tbody>';
        stats.details.slice(0, 100).forEach(function(d) {
            var statusClass = '';
            if (d.status === 'updated') statusClass = 'status-auto';
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

    // ===== TEST PRODUCT TAB (unchanged from original) =====

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

})(jQuery);
