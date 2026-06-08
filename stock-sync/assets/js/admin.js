/**
 * Stock Sync — Admin JavaScript (Unified Sync Tab)
 */
(function($) {
    'use strict';

    function getDistributorSlug() {
        var params = new URLSearchParams(window.location.search);
        return params.get('distributor') || 'vininova';
    }

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

    function sortMatchesByTier(matches) {
        return matches.slice().sort(function(a, b) {
            var tierA = getTier(a.confidence, a.status);
            var tierB = getTier(b.confidence, b.status);
            if (tierA !== tierB) return tierA - tierB;
            // Within tier, sort alphabetically by XLSX name
            return (a.xlsx_name || '').localeCompare(b.xlsx_name || '');
        });
    }

    function getTier(confidence, status) {
        if (status === 'manual' || confidence < 70) return 1;
        if (status === 'suggest' || confidence < 90) return 2;
        if (status === 'auto' && confidence >= 90) {
            return 4; // Clean auto at bottom
        }
        return 3;
    }

    // ===== TOAST NOTIFICATIONS =====

    var $toastContainer = $('<div>').addClass('stock-toast-container').appendTo('body');

    function showToast(message, type) {
        type = type || 'info';
        var $toast = $('<div>').addClass('stock-toast ' + type).text(message);
        $toastContainer.append($toast);
        setTimeout(function() {
            $toast.addClass('out');
            setTimeout(function() {
                $toast.remove();
            }, 300);
        }, 4000);
    }

    function hideUploadError() {
        $('#sync-upload-error').hide().empty();
    }

    function showUploadError(rawMessage) {
        var friendlyMessage;
        if (rawMessage && rawMessage.indexOf('Header row not found') !== -1) {
            friendlyMessage = 'We could not locate the required column headers. If you set custom names under Advanced options, please double-check them. Otherwise, verify the file contains the reference and availability columns, then try uploading again.';
        } else {
            friendlyMessage = 'Expected column headers have not been found. Check if the correct distributor file has been uploaded or modify column names under Advanced options.';
            if (rawMessage) {
                friendlyMessage += ' <em>(' + escapeHtml(rawMessage) + ')</em>';
            }
        }
        $('#sync-upload-error').html('<p>' + friendlyMessage + '</p>').show();
    }

    // ===== STEPPER =====

    function updateStepper(stepIndex) {
        var $steps = $('.stock-step');
        $steps.each(function(i) {
            var $step = $(this);
            $step.removeClass('active completed pending');
            if (i + 1 < stepIndex) {
                $step.addClass('completed').find('.stock-step-icon').html('&#10003;');
            } else if (i + 1 === stepIndex) {
                $step.addClass('active').find('.stock-step-icon').text(i + 1);
            } else {
                $step.addClass('pending').find('.stock-step-icon').text(i + 1);
            }
        });
    }

    // ===== SKELETON =====

    function showSkeleton($tableBody, rows, cols) {
        var html = '';
        for (var r = 0; r < rows; r++) {
            html += '<tr>';
            for (var c = 0; c < cols; c++) {
                html += '<td><div class="stock-skeleton" style="height:16px;width:100%;"></div></td>';
            }
            html += '</tr>';
        }
        $tableBody.html(html);
    }

    function hideSkeleton($tableBody) {
        $tableBody.empty();
    }

    // ===== UTILS =====

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
    var requestGenerationToken = 0;

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
            hideUploadError();
            $toastContainer.empty();
        }
    }

    $('#stock-xlsx-file').on('change', function() {
        droppedFile = null;
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

            // Assign dropped file to the input so HTML5 validation passes on submit
            try {
                var dataTransfer = new DataTransfer();
                dataTransfer.items.add(droppedFile);
                document.getElementById('stock-xlsx-file').files = dataTransfer.files;
            } catch (e) {
                // Older browsers may not support DataTransfer; upload still works via droppedFile
            }
        }
    });

    $('#stock-sync-form').on('submit', function(e) {
        e.preventDefault();

        var $form = $(this);
        var $input = $form.find('input[name="xlsx_file"]')[0];
        var $btn = $form.find('button[type="submit"]');

        var file = droppedFile || ($input.files.length ? $input.files[0] : null);

        if (!file) {
            showToast('Please select a file.', 'warning');
            return;
        }

        $btn.prop('disabled', true).addClass('stock-btn-spinner').text('Uploading...');
        $('#stock-xlsx-file').prop('disabled', true);
        hideUploadError();
        resetAllSteps();
        globalTotalBatches = 0;
        globalCurrentBatch = 0;

        var thisGen = requestGenerationToken;
        uploadFile(file, function(uploadData) {
            if (thisGen !== requestGenerationToken) return;
            currentSyncFilePath = uploadData.file_path;
            $btn.prop('disabled', false).removeClass('stock-btn-spinner').text('Upload');
            $('#stock-xlsx-file').prop('disabled', false);
            analyzeAndMap(uploadData.file_path, $btn);
        }, function(error) {
            if (thisGen !== requestGenerationToken) return;
            showToast('Upload error: ' + error, 'error');
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
        hideUploadError();
        $('#sync-mapping-body').empty();
        $('#sync-unmatched-body').empty();
        $('#sync-preview-body').empty();
        currentRunId = null;
        currentDryRunStats = null;
        globalTotalBatches = 0;
        globalCurrentBatch = 0;
        droppedFile = null;
        $('#stock-upload-filename').text('');
        $('#stock-xlsx-file').val('');
        $('#stock-sync-upload').prop('disabled', true);
        updateStepper(1);
    }

    $(document).on('click', '.stock-reset-btn', function() {
        requestGenerationToken++;
        resetAllSteps();
    });

    // ===== STEP 2: ANALYZE & MAP =====

    function analyzeAndMap(filePath, $btn) {
        var thisGen = requestGenerationToken;
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
                if (thisGen !== requestGenerationToken) return;
                if (!response.success) {
                    $toastContainer.empty();
                    showUploadError(response.data);
                    hideProgress();
                    $('#sync-step-upload form').show();
                    $btn.prop('disabled', false).removeClass('stock-btn-spinner').text('Upload');
                    $('#stock-xlsx-file').prop('disabled', false);
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
                if (thisGen !== requestGenerationToken) return;
                $toastContainer.empty();
                showUploadError('Network error while reading the file. Please try again.');
                hideProgress();
                $('#sync-step-upload form').show();
                if ($btn) {
                    $btn.prop('disabled', false).removeClass('stock-btn-spinner').text('Upload');
                }
                $('#stock-xlsx-file').prop('disabled', false);
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
                    .attr('data-wc-id', m.wc_id || '')
                    .attr('data-wc-sku', m.wc_sku || '');
                $tr.append($('<td>').append($('<input>', {type: 'checkbox', class: 'match-check', checked: true, title: 'Already mapped'})));
                $tr.append($('<td>').text(m.distributor_ref || '-'));
                $tr.append($('<td>').text(m.xlsx_name || '-'));
                $tr.append($('<td>').addClass('wc-product-cell').text(m.wc_name || '—'));
                $tr.append($('<td>').append($('<span>').addClass('confidence-badge confidence-high').text('100%')));
                $tr.append($('<td>').append($('<span>').addClass('stock-mapped-label').text('Already mapped')));
                $tbody.append($tr);
            });
        }

        matches = sortMatchesByTier(matches);

        matches.forEach(function(m) {
            var isSuggest = m.confidence >= 70;
            var checked = isSuggest; // Suggest checked by default, manual unchecked
            if (isSuggest) {
                suggestCount++;
            } else {
                manualCount++;
            }

            var confClass = m.confidence >= 90 ? 'confidence-high' : (m.confidence >= 70 ? 'confidence-medium' : 'confidence-low');

            var $tr = $('<tr>')
                .attr('data-ref', m.distributor_ref || '')
                .attr('data-wc-id', m.wc_id || '')
                .attr('data-wc-sku', m.wc_sku || '')
                .attr('data-original-confidence', m.confidence)
                .attr('data-original-status', m.status)
                .attr('data-changed', 'false');
            $tr.append($('<td>').append($('<input>', {type: 'checkbox', class: 'match-check'}).prop('checked', checked)));
            $tr.append($('<td>').text(m.distributor_ref || '-'));
            $tr.append($('<td>').text(m.xlsx_name || '-'));

            var $wcCell = $('<td>').addClass('wc-product-cell')
                .attr('data-original-wc-name', m.wc_name || '— no match —')
                .attr('data-original-wc-id', m.wc_id || '')
                .attr('data-original-wc-sku', m.wc_sku || '')
                .text(m.wc_name || '— no match —');
            $tr.append($wcCell);

            $tr.append($('<td>').append($('<span>').addClass('confidence-badge ' + confClass).text(m.confidence + '%')));

            // Action column: single Change button
            var $actionCell = $('<td>').addClass('stock-action-cell');
            var $changeBtn = $('<button>', {type: 'button', class: 'button button-small stock-change-match'})
                .text('Change');
            $actionCell.append($changeBtn);
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

        // Validate immediately after render
        validateMappingDuplicates(false);
        // Highlight duplicate rows
        highlightDuplicateRows();

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

        updateStepper(2);
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

        // Always proceed with the update first
        $row.attr('data-wc-id', newId);
        $row.attr('data-wc-sku', $a.data('sku') || '');
        $row.attr('data-changed', 'true');
        $cell.text(newName);

        // Update confidence badge to 100%
        var $confCell = $row.find('td').eq(4);
        $confCell.empty().append($('<span>').addClass('confidence-badge confidence-high').text('100%'));

        // Pre-check the row since user explicitly selected it
        $row.find('.match-check').prop('checked', true);

        // Restore action cell with single Revert button
        $actionCell.empty();
        var $revertBtn = $('<button>', {type: 'button', class: 'button button-small stock-revert-match'}).text('Revert');
        $actionCell.append($revertBtn);

        activeInlineSearch = null;

        // Then show duplicate warning if applicable
        if (duplicateRef) {
            showDuplicateBanner([{ wcId: newId, wcSku: $a.data('sku') || '—', name: newName, refs: [duplicateRef, $row.attr('data-ref') || 'this row'] }]);
        }

        validateMappingDuplicates(true);
    });

    $(document).on('click', '.stock-revert-match', function(e) {
        e.preventDefault();
        var $btn = $(this);
        var $row = $btn.closest('tr');
        var $cell = $row.find('.wc-product-cell');
        var $actionCell = $row.find('.stock-action-cell');

        var originalName = $cell.attr('data-original-wc-name') || '— no match —';
        var originalId = $cell.attr('data-original-wc-id') || '';
        var originalSku = $cell.attr('data-original-wc-sku') || '';

        // Restore original suggestion
        $row.attr('data-wc-id', originalId);
        $row.attr('data-wc-sku', originalSku);
        $row.attr('data-changed', 'false');
        $cell.text(originalName);

        // Restore original confidence
        var originalConf = parseInt($row.attr('data-original-confidence') || '0', 10);
        var confClass = originalConf >= 90 ? 'confidence-high' : (originalConf >= 70 ? 'confidence-medium' : 'confidence-low');
        var $confCell = $row.find('td').eq(4);
        $confCell.empty().append($('<span>').addClass('confidence-badge ' + confClass).text(originalConf + '%'));

        // Replace action cell with single Change button
        $actionCell.empty();
        var $changeBtn = $('<button>', {type: 'button', class: 'button button-small stock-change-match'}).text('Change');
        $actionCell.append($changeBtn);

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

        // Restore action cell with single Change or Revert button
        var $actionCell = $row.find('.stock-action-cell');
        var isChanged = $row.attr('data-changed') === 'true';
        $actionCell.empty();
        if (isChanged) {
            var $revertBtn = $('<button>', {type: 'button', class: 'button button-small stock-revert-match'}).text('Revert');
            $actionCell.append($revertBtn);
        } else {
            var $changeBtn = $('<button>', {type: 'button', class: 'button button-small stock-change-match'}).text('Change');
            $actionCell.append($changeBtn);
        }
    }

    function highlightDuplicateRows() {
        var duplicates = getMappingDuplicates();
        var duplicateWcIds = duplicates.map(function(d) { return String(d.wcId); });
        
        $('#sync-mapping-body tr, #sync-unmatched-body tr').each(function() {
            var $row = $(this);
            var wcId = $row.attr('data-wc-id');
            if (wcId && duplicateWcIds.indexOf(String(wcId)) !== -1) {
                $row.addClass('stock-row-duplicate');
            } else {
                $row.removeClass('stock-row-duplicate');
            }
        });
    }

    function getMappingDuplicates() {
        var wcIdToData = {};
        var duplicates = [];
        $('#sync-mapping-body tr, #sync-unmatched-body tr').each(function() {
            var $row = $(this);
            if ($row.find('.match-check').prop('checked')) {
                var wcId = $row.attr('data-wc-id');
                var wcSku = $row.attr('data-wc-sku') || '—';
                var ref = $row.attr('data-ref');
                var wcName = $row.find('.wc-product-cell').text() || 'Unknown';
                if (wcId) {
                    if (wcIdToData[wcId]) {
                        duplicates.push({ wcId: wcId, wcSku: wcSku, name: wcName, refs: [wcIdToData[wcId].ref, ref] });
                    } else {
                        wcIdToData[wcId] = { ref: ref, name: wcName, wcSku: wcSku };
                    }
                }
            }
        });
        return duplicates;
    }

    function buildDuplicateBannerHtml(duplicates) {
        var html = '<p><strong>' + escapeHtml('Duplicate mapping' + (duplicates.length > 1 ? 's' : '') + ' detected. Please adjust your selections.') + '</strong></p>';
        html += '<ul class="stock-duplicate-list">';
        duplicates.forEach(function(d) {
            html += '<li>' + escapeHtml(d.name + ' (SKU: ' + d.wcSku + ') is mapped to refs: ' + d.refs.join(', ')) + '</li>';
        });
        html += '</ul>';
        return html;
    }

    function showDuplicateBanner(duplicates) {
        var $notice = $('#sync-duplicate-notice');
        $notice.html(buildDuplicateBannerHtml(duplicates)).show();
        $('#stock-sync-confirm-mappings').prop('disabled', true);
    }

    function hideDuplicateBanner() {
        $('#sync-duplicate-notice').hide().empty();
    }

    function validateMappingDuplicates(suppressBanner) {
        var duplicates = getMappingDuplicates();
        var isValid = duplicates.length === 0;
        $('#stock-sync-confirm-mappings').prop('disabled', !isValid);
        if (!isValid && !suppressBanner) {
            showDuplicateBanner(duplicates);
        } else if (isValid) {
            hideDuplicateBanner();
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
        highlightDuplicateRows();
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
            showToast('Please select at least one match to save.', 'warning');
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
                    showToast('Save failed: ' + response.data, 'error');
                    hideProgress();
                }
            },
            error: function() {
                $btn.prop('disabled', false).text('Confirm Mappings & Continue');
                showToast('Save network error', 'error');
                hideProgress();
            }
        });
    });

    // ===== STEP 3: DRY-RUN PREVIEW =====

    function startDryRun(filePath) {
        $('#sync-step-upload').hide();
        $('#sync-step-mapping').hide();
        $('#sync-step-preview').show();
        updateStepper(3);
        $('#sync-preview-body').empty();

        var thisGen = requestGenerationToken;
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
                if (thisGen !== requestGenerationToken) return;
                if (!response.success) {
                    showToast('Scan failed: ' + response.data, 'error');
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
                    if (thisGen !== requestGenerationToken) return;
                    currentDryRunStats = stats;
                    showPreviewResults(stats);
                });
            },
            error: function() {
                if (thisGen !== requestGenerationToken) return;
                showToast('Scan network error', 'error');
                hideProgress();
            }
        });
    }

    function showPreviewResults(stats) {
        var $tbody = $('#sync-preview-body').empty();
        var updateCount = 0;

        stats.details.forEach(function(d) {
            if (d.status === 'would_update' || d.status === 'updated') {
                updateCount++;
                var $tr = $('<tr>').attr('data-ref', d.distributor_ref || '');
                $tr.append($('<td>').append($('<input>', {type: 'checkbox', class: 'preview-check'}).prop('checked', true)));
                $tr.append($('<td>').text(d.distributor_ref || '-'));
                $tr.append($('<td>').text(d.name || '-'));
                $tr.append($('<td>').addClass('status-auto').text('will update'));
                $tbody.append($tr);
            }
        });

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
            showToast('No sync data available. Please start over.', 'warning');
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
            showToast('Please select at least one product to update.', 'warning');
            return;
        }

        $btn.prop('disabled', true).text('Filtering...');
        showProgress('Preparing sync...');
        $('#stock-sync-results').hide();

        // Filter queue to only selected refs
        filterAndApply(includeRefs, $btn);
    });

    function filterAndApply(includeRefs, $btn) {
        var thisGen = requestGenerationToken;
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
                if (thisGen !== requestGenerationToken) return;
                if (!response.success) {
                    showToast('Filter failed: ' + response.data, 'error');
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
                    if (thisGen !== requestGenerationToken) return;
                    $btn.prop('disabled', false).text('Apply Sync');
                    hideProgress();
                    showFinalResults(stats);
                });
            },
            error: function() {
                if (thisGen !== requestGenerationToken) return;
                showToast('Filter network error', 'error');
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

        var thisGen = requestGenerationToken;
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
                if (thisGen !== requestGenerationToken) return;
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
                    showToast('Batch failed: ' + (response.data || 'Unknown server error'), 'error');
                }
            },
            error: function() {
                if (thisGen !== requestGenerationToken) return;
                stats.errors += 50;
                onComplete(stats);
                if ($btn) $btn.prop('disabled', false);
                showToast('Sync stopped: network/transport error', 'error');
            }
        });
    }

    function showFinalResults(stats) {
        updateStepper(4);
        $('#sync-step-preview').hide();
        $('#stock-sync-results').show();

        $('#res-total').text(stats.processed);
        $('#res-updated').text(stats.updated);
        $('#res-notfound').text(stats.not_found);
        $('#res-errors').text(stats.errors);

        $('#stock-sync-results-title').text('Sync Results');
        $('#res-updated-label').text('Updated');

        var html = '<table class="stock-card-table"><thead><tr><th>Ref</th><th>Name</th><th>Status</th></tr></thead><tbody>';
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
            showToast('Please enter at least 2 characters.', 'warning');
            return;
        }

        var distributor = getDistributorSlug();
        var $results = $('#stock-test-search-results');
        $results.html('<p>Searching...</p>').removeClass('hidden');

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
        $('#stock-test-search-results').addClass('hidden');
        $('#stock-test-search').val('');
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
                    showToast('Failed to load product: ' + response.data, 'error');
                    return;
                }

                var d = response.data;
                $('#test-current-sku').text(d.sku || '—');
                $('#test-current-name').text(d.name);
                $('#test-new-name').text(d.new_name === d.name ? '(no change)' : d.new_name);
                $('#test-current-visibility').text(d.visibility);
                $('#test-new-visibility').text(d.visibility === 'search' ? '(no change)' : 'Search results only');
                $('#test-current-price').text(d.price || '—');
                $('#test-new-price').text(d.price ? '(cleared)' : '(no change)');
                $('#test-current-sale').text(d.sale_price || '—');
                $('#test-new-sale').text(d.sale_price ? '(cleared)' : '(no change)');
                $('#test-current-excerpt').text(d.excerpt || '—');
                $('#test-new-excerpt').text(d.new_excerpt);

                $('#stock-test-selected').removeClass('hidden');
                $('#stock-test-apply').prop('disabled', false);
                $('#stock-test-success').addClass('hidden').empty();
                $('#stock-test-status').text('');
            },
            error: function() {
                showToast('Network error loading product details.', 'error');
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
                $btn.text('Apply Update to This Product');
                if (response.success) {
                    $('#stock-test-success').html('<p>' + escapeHtml(response.data.message) + '</p>').removeClass('hidden');
                } else {
                    $btn.prop('disabled', false);
                    $('#stock-test-status').empty().append($('<span>').css('color', 'red').text('Error: ' + response.data));
                }
            },
            error: function() {
                $btn.prop('disabled', false).text('Apply Update to This Product');
                $('#stock-test-status').empty().append($('<span>').css('color', 'red').text('Network error.'));
            }
        });
    });

    $(function() {
        updateStepper(1);
    });

})(jQuery);
