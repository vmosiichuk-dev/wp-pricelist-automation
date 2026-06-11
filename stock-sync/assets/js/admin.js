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

    // Simple sprintf-like helper for localized strings
    function __(text) {
        var args = Array.prototype.slice.call(arguments, 1);
        return text.replace(/%(\d+)\$s/g, function(match, num) {
            return args[num - 1] !== undefined ? args[num - 1] : match;
        });
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
        if (rawMessage && rawMessage.indexOf(stockSync.strings.headerRowNotFound) !== -1) {
            friendlyMessage = stockSync.strings.uploadErrorHeader;
        } else {
            friendlyMessage = stockSync.strings.uploadErrorGeneric;
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
                    onError(response.data || stockSync.strings.uploadError);
                }
            },
            error: function() {
                onError(stockSync.strings.networkErrorUpload);
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
            showToast(stockSync.strings.pleaseSelectFile, 'warning');
            return;
        }

        $btn.prop('disabled', true).addClass('stock-btn-spinner').text(stockSync.strings.uploading);
        $('#stock-xlsx-file').prop('disabled', true);
        hideUploadError();
        resetAllSteps();
        globalTotalBatches = 0;
        globalCurrentBatch = 0;

        var thisGen = requestGenerationToken;
        uploadFile(file, function(uploadData) {
            if (thisGen !== requestGenerationToken) return;
            currentSyncFilePath = uploadData.file_path;
            // Keep button in analyzing state until mapping table is ready
            $btn.prop('disabled', true).removeClass('stock-btn-spinner').addClass('stock-btn-spinner').text(stockSync.strings.analyzing);
            analyzeAndMap(uploadData.file_path, $btn);
        }, function(error) {
            if (thisGen !== requestGenerationToken) return;
            showToast(stockSync.strings.uploadError + ': ' + error, 'error');
            $btn.prop('disabled', false).removeClass('stock-btn-spinner').text(stockSync.strings.upload);
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
        $('#sync-already-mapped-body').empty();
        $('#sync-unmatched-body').empty();
        $('#sync-preview-body').empty();
        $('#sync-mapping-details').addClass('hidden');
        $('#sync-already-mapped-details').addClass('hidden');
        $('#sync-unmatched-details').addClass('hidden');
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
                    $btn.prop('disabled', false).removeClass('stock-btn-spinner').text(stockSync.strings.upload);
                    $('#stock-xlsx-file').prop('disabled', false);
                    return;
                }

                showWarnings('#stock-sync-form', response.data.warnings);

                var matches = response.data.matches || [];
                var alreadyMappedItems = response.data.already_mapped_items || [];
                var alreadyMappedCount = response.data.already_mapped_count || 0;

                if (matches.length > 0 || alreadyMappedItems.length > 0) {
                    updateProgressPercent(10, stockSync.strings.reviewMappings);
                    showMappingReview(matches, alreadyMappedItems);
                } else {
                    updateProgressPercent(10, stockSync.strings.startingScan);
                    startDryRun(filePath);
                }
            },
            error: function() {
                if (thisGen !== requestGenerationToken) return;
                $toastContainer.empty();
                showUploadError(stockSync.strings.networkErrorReadFile);
                hideProgress();
                $('#sync-step-upload form').show();
                if ($btn) {
                    $btn.prop('disabled', false).removeClass('stock-btn-spinner').text(stockSync.strings.upload);
                }
                $('#stock-xlsx-file').prop('disabled', false);
            }
        });
    }

    function showMappingReview(matches, alreadyMappedItems) {
        $('#sync-step-upload').hide();
        $('#sync-step-mapping').show();
        hideProgress();

        // Re-enable upload button now that analysis is complete
        $('#stock-sync-upload').prop('disabled', false).removeClass('stock-btn-spinner').text(stockSync.strings.upload);
        $('#stock-xlsx-file').prop('disabled', false);

        var $tbody = $('#sync-mapping-body').empty();
        var $alreadyMappedBody = $('#sync-already-mapped-body').empty();
        var $unmatchedBody = $('#sync-unmatched-body').empty();
        var suggestCount = 0;
        var manualCount = 0;

        // Render already-mapped items (inactive checkboxes)
        if (alreadyMappedItems && alreadyMappedItems.length > 0) {
            alreadyMappedItems.forEach(function(m) {
                var $tr = $('<tr>').addClass('stock-already-mapped')
                    .attr('data-ref', m.distributor_ref || '')
                    .attr('data-wc-id', m.wc_id || '')
                    .attr('data-wc-sku', m.wc_sku || '');
                $tr.append($('<td>').append($('<input>', {type: 'checkbox', class: 'match-check', checked: true, disabled: true, title: stockSync.strings.alreadyMapped})));
                $tr.append($('<td>').text(m.distributor_ref || '-'));
                $tr.append($('<td>').text(m.xlsx_name || '-'));
                $tr.append($('<td>').addClass('wc-product-cell').text(m.wc_name || '—'));
                $tr.append($('<td>').append($('<span>').addClass('confidence-badge confidence-high').text('100%')));
                $tr.append($('<td>').append($('<span>').addClass('stock-mapped-label').text(stockSync.strings.alreadyMapped)));
                $alreadyMappedBody.append($tr);
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
                .text(stockSync.strings.change);
            $actionCell.append($changeBtn);
            $tr.append($actionCell);

            if (isSuggest) {
                $tbody.append($tr);
            } else {
                $unmatchedBody.append($tr);
            }
        });

        // Show/hide mapping section
        var $mappingDetails = $('#sync-mapping-details');
        if (suggestCount === 0) {
            $mappingDetails.addClass('hidden');
        } else {
            $mappingDetails.removeClass('hidden');
            $mappingDetails.find('.stock-mapping-count').text('(' + suggestCount + ')');
        }

        // Show/hide already mapped section
        var $alreadyDetails = $('#sync-already-mapped-details');
        if (alreadyMappedItems.length === 0) {
            $alreadyDetails.addClass('hidden');
        } else {
            $alreadyDetails.removeClass('hidden');
            $alreadyDetails.find('.stock-already-mapped-count').text('(' + alreadyMappedItems.length + ')');
        }

        // Show/hide unmatched section
        var $details = $('#sync-unmatched-details');
        if (manualCount === 0) {
            $details.addClass('hidden');
        } else {
            $details.removeClass('hidden');
            $details.find('.stock-unmatched-count').text('(' + manualCount + ')');
        }

        // Mark the last visible table as the one before the sticky bar
        function updateLastTableBorder() {
            $('.stock-card-table').removeClass('stock-table-last');
            var $visibleTables = $('.stock-card-table:visible');
            if ($visibleTables.length > 0) {
                $visibleTables.last().addClass('stock-table-last');
            }
        }
        updateLastTableBorder();

        // Recompute last-table class when details open/close
        $('#sync-step-mapping details').off('toggle.stock').on('toggle.stock', function() {
            setTimeout(updateLastTableBorder, 0);
        });

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
            .text(stockSync.strings.cancel);
        $actionCell.append($cancelBtn);

        // Wrap existing text in a hidden span to preserve column width
        var currentText = $cell.text();
        $cell.empty().append($('<span>').addClass('stock-cell-text-hidden').text(currentText));

        // Show search field in wc-product-cell
        var $searchWrap = $('<div>').addClass('stock-inline-search');
        var $input = $('<input>', {type: 'text', placeholder: stockSync.strings.searchPlaceholder});
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
                    $resultsContainer.html('<p class="stock-inline-info">' + escapeHtml(stockSync.strings.noProductsFound) + '</p>');
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
                $resultsContainer.html('<p class="stock-inline-error">' + escapeHtml(stockSync.strings.networkError) + '</p>');
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
        var $revertBtn = $('<button>', {type: 'button', class: 'button button-small stock-revert-match'}).text(stockSync.strings.revert);
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
        var $changeBtn = $('<button>', {type: 'button', class: 'button button-small stock-change-match'}).text(stockSync.strings.change);
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
            var $revertBtn = $('<button>', {type: 'button', class: 'button button-small stock-revert-match'}).text(stockSync.strings.revert);
            $actionCell.append($revertBtn);
        } else {
            var $changeBtn = $('<button>', {type: 'button', class: 'button button-small stock-change-match'}).text(stockSync.strings.change);
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
                var wcName = $row.find('.wc-product-cell').text() || stockSync.strings.unknown;
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
        var html = '<p><strong>' + escapeHtml(duplicates.length > 1 ? stockSync.strings.duplicateMappingsDetected : stockSync.strings.duplicateMappingDetected) + '</strong></p>';
        html += '<ul class="stock-duplicate-list">';
        duplicates.forEach(function(d) {
            html += '<li>' + escapeHtml(__(stockSync.strings.mappedToRefs, d.name, d.wcSku, d.refs.join(', '))) + '</li>';
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

        // Only include rows from mapping and unmatched tables.
        // Already-mapped rows are skipped because their checkboxes are disabled.
        $('#sync-mapping-body tr, #sync-unmatched-body tr').each(function() {
            var $row = $(this);
            if ($row.find('.match-check').prop('checked')) {
                matches.push({
                    distributor_ref: $row.data('ref'),
                    wc_id: $row.data('wc-id')
                });
            }
        });

        var hasAlreadyMapped = $('#sync-already-mapped-body .match-check:disabled').length > 0;
        if (matches.length === 0 && !hasAlreadyMapped) {
            showToast(stockSync.strings.pleaseSelectOneMatch, 'warning');
            return;
        }

        $btn.prop('disabled', true).text(stockSync.strings.saving);
        showProgress(stockSync.strings.savingMappings);

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
                $btn.prop('disabled', false).text(stockSync.strings.confirmMappingsContinue);
                if (response.success) {
                    $('#sync-step-mapping').hide();
                    updateProgressPercent(15, stockSync.strings.startingScan);
                    startDryRun(currentSyncFilePath);
                } else {
                    showToast(stockSync.strings.saveFailed + ': ' + response.data, 'error');
                    hideProgress();
                }
            },
            error: function() {
                $btn.prop('disabled', false).text(stockSync.strings.confirmMappingsContinue);
                showToast(stockSync.strings.networkError, 'error');
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
                    showToast(stockSync.strings.scanFailed + ': ' + response.data, 'error');
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
                showToast(stockSync.strings.networkError, 'error');
                hideProgress();
            }
        });
    }

    function showPreviewResults(stats) {
        var $tbody = $('#sync-preview-body').empty();
        var updateCount = 0;

        stats.details.forEach(function(d) {
            if (d.status === 'would_update' || d.status === 'updated' || d.status === 'would_delist' || d.status === 'delisted' || d.status === 'would_list' || d.status === 'listed') {
                updateCount++;
                var $tr = $('<tr>').attr('data-ref', d.distributor_ref || '');
                $tr.append($('<td>').append($('<input>', {type: 'checkbox', class: 'preview-check'}).prop('checked', true)));
                $tr.append($('<td>').text(d.sku || '—'));
                $tr.append($('<td>').text(d.distributor_ref || '-'));
                $tr.append($('<td>').text(d.name || '-'));
                var actionText = stockSync.strings.delist;
                if (d.status === 'would_list' || d.status === 'listed') {
                    actionText = stockSync.strings.list;
                }
                $tr.append($('<td>').addClass('status-delisted').text(actionText));
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
            showToast(stockSync.strings.noSyncData, 'warning');
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
            showToast(stockSync.strings.pleaseSelectOneProduct, 'warning');
            return;
        }

        $btn.prop('disabled', true).text(stockSync.strings.filtering);
        showProgress(stockSync.strings.preparingSync);
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
                    showToast(stockSync.strings.filterFailed + ': ' + response.data, 'error');
                    $btn.prop('disabled', false).text(stockSync.strings.applySync);
                    hideProgress();
                    return;
                }

                var newRunId = response.data.run_id;
                var total = response.data.total_batches;
                globalTotalBatches += total;

                $btn.text(stockSync.strings.syncing);
                runBatches(currentSyncFilePath, distributor, false, total, 0, {
                    processed: 0,
                    updated: 0,
                    not_found: 0,
                    errors: 0,
                    details: []
                }, $btn, newRunId, function(stats) {
                    if (thisGen !== requestGenerationToken) return;
                    $btn.prop('disabled', false).text(stockSync.strings.applySync);
                    hideProgress();
                    showFinalResults(stats);
                });
            },
            error: function() {
                if (thisGen !== requestGenerationToken) return;
                showToast(stockSync.strings.networkErrorFilter, 'error');
                $btn.prop('disabled', false).text(stockSync.strings.applySync);
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
        var phaseText = dryRun ? __(stockSync.strings.scanningBatch, current + 1, total) : __(stockSync.strings.syncingBatch, current + 1, total);
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
                    showToast(stockSync.strings.batchFailed + ': ' + (response.data || stockSync.strings.unknownServerError), 'error');
                }
            },
            error: function() {
                if (thisGen !== requestGenerationToken) return;
                stats.errors += 50;
                onComplete(stats);
                if ($btn) $btn.prop('disabled', false);
                showToast(stockSync.strings.syncStopped + ': ' + stockSync.strings.networkError, 'error');
            }
        });
    }

    function showFinalResults(stats) {
        updateStepper(5);
        $('#sync-step-preview').hide();
        $('#stock-sync-results').show();

        $('#res-total').text(stats.processed);
        $('#res-delisted').text(stats.updated);
        var listedCount = stats.details.reduce(function(n, d) {
            return n + (d.status === 'listed' || d.status === 'would_list' ? 1 : 0);
        }, 0);
        $('#res-listed').text(listedCount);
        $('#res-errors').text(stats.errors);

        $('#stock-sync-results-title').text(stockSync.strings.syncResults);

        var html = '<table class="stock-card-table"><thead><tr><th>' + escapeHtml(stockSync.strings.sku) + '</th><th>' + escapeHtml(stockSync.strings.ref) + '</th><th>' + escapeHtml(stockSync.strings.name) + '</th><th>' + escapeHtml(stockSync.strings.status) + '</th></tr></thead><tbody>';
        stats.details.slice(0, 100).forEach(function(d) {
            var statusClass = '';
            var displayStatus = d.status;
            if (d.status === 'updated' || d.status === 'delisted') {
                statusClass = 'status-delisted';
                displayStatus = stockSync.strings.delisted;
            } else if (d.status === 'listed' || d.status === 'would_list') {
                statusClass = 'status-listed';
                displayStatus = stockSync.strings.listed;
            } else if (d.status === 'not_found') {
                statusClass = 'status-error';
                displayStatus = stockSync.strings.notFound;
            } else if (d.status === 'error') {
                statusClass = 'status-error';
                displayStatus = stockSync.strings.error;
            } else {
                statusClass = 'status-neutral';
            }

            html += '<tr><td>' + escapeHtml(d.sku || '—') + '</td><td>' + escapeHtml(d.distributor_ref || '-') + '</td><td>' + escapeHtml(d.name || '-') + '</td><td class="' + statusClass + '">' + escapeHtml(displayStatus) + '</td></tr>';
        });
        if (stats.details.length > 100) {
            html += '<tr><td colspan="4">' + escapeHtml(__(stockSync.strings.more, stats.details.length - 100)) + '</td></tr>';
        }
        html += '</tbody></table>';
        $('#res-details').html(html);
    }

    // ===== TEST PRODUCT TAB =====

    var selectedTestProductId = null;

    $('#stock-test-search-btn').on('click', function() {
        var query = $('#stock-test-search').val().trim();
        if (query.length < 2) {
            showToast(stockSync.strings.pleaseEnterTwoChars, 'warning');
            return;
        }

        // Bug fix: disable apply button and hide previous results when starting a new search
        $('#stock-test-apply').prop('disabled', true);
        $('#stock-test-selected').addClass('hidden');

        var distributor = getDistributorSlug();
        var $results = $('#stock-test-search-results');
        $results.html('<p>' + escapeHtml(stockSync.strings.searching) + '</p>').removeClass('hidden');

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
                    $results.empty().append($('<p>').css('color', 'red').text(stockSync.strings.error + ': ' + response.data));
                    return;
                }

                if (response.data.products.length === 0) {
                    $results.html('<p>' + escapeHtml(stockSync.strings.noProductsFound) + '</p>');
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
                $results.empty().append($('<p>').css('color', 'red').text(stockSync.strings.networkError));
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
                    showToast(__(stockSync.strings.failedToLoadProduct, response.data), 'error');
                    return;
                }

                var d = response.data;
                $('#test-current-sku').text(d.sku || '—');
                $('#test-current-name').text(d.name);
                $('#test-new-name').text(d.new_name === d.name ? stockSync.strings.noChange : d.new_name);
                $('#test-current-visibility').text(d.visibility);
                $('#test-new-visibility').text(d.visibility === 'search' ? stockSync.strings.noChange : stockSync.strings.searchResultsOnly);
                $('#test-current-price').text(d.price || '—');
                $('#test-new-price').text(d.price ? stockSync.strings.cleared : stockSync.strings.noChange);
                $('#test-current-sale').text(d.sale_price || '—');
                $('#test-new-sale').text(d.sale_price ? stockSync.strings.cleared : stockSync.strings.noChange);
                $('#test-current-excerpt').text(d.excerpt || '—');
                $('#test-new-excerpt').text(d.new_excerpt);

                $('#stock-test-selected').removeClass('hidden');
                $('#stock-test-apply').prop('disabled', false);
                $('#stock-test-success').addClass('hidden').empty();
                $('#stock-test-status').text('');
            },
            error: function() {
                showToast(stockSync.strings.networkErrorLoadProduct, 'error');
            }
        });
    }

    $('#stock-test-apply').on('click', function() {
        if (!selectedTestProductId) return;

        var $btn = $(this);
        var distributor = getDistributorSlug();

        if (!confirm(stockSync.strings.confirmUpdateSingle)) {
            return;
        }

        $btn.prop('disabled', true).text(stockSync.strings.applying);
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
                $btn.text(stockSync.strings.applyUpdateProduct);
                if (response.success) {
                    $('#stock-test-success').html('<p>' + escapeHtml(response.data.message) + '</p>').removeClass('hidden');
                } else {
                    $btn.prop('disabled', false);
                    $('#stock-test-status').empty().append($('<span>').css('color', 'red').text(stockSync.strings.error + ': ' + response.data));
                }
            },
            error: function() {
                $btn.prop('disabled', false).text(stockSync.strings.applyUpdateProduct);
                $('#stock-test-status').empty().append($('<span>').css('color', 'red').text(stockSync.strings.networkError));
            }
        });
    });

    // ===== ERASE ALL SUPPLIER REFERENCES =====

    $('#stock-erase-refs-btn').on('click', function() {
        var distributor = getDistributorSlug();
        var distributorName = $('#stock-distributor option:selected').text().trim();

        if (!confirm(__(stockSync.strings.confirmEraseRefs, distributorName))) {
            return;
        }

        var $btn = $(this);
        $btn.prop('disabled', true).text(stockSync.strings.erasing);

        $.ajax({
            url: stockSync.ajaxUrl,
            type: 'POST',
            data: {
                action: 'stock_sync_erase_refs',
                nonce: stockSync.nonce,
                distributor_slug: distributor
            },
            success: function(response) {
                $btn.prop('disabled', false).text(stockSync.strings.eraseAllRefs);
                if (response.success) {
                    showToast(__(stockSync.strings.erasedRefs, response.data.erased, distributorName), 'success');
                } else {
                    showToast(stockSync.strings.eraseFailed + ': ' + response.data, 'error');
                }
            },
            error: function() {
                $btn.prop('disabled', false).text(stockSync.strings.eraseAllRefs);
                showToast(stockSync.strings.eraseNetworkError, 'error');
            }
        });
    });

    // ===== CLOSE DROPDOWNS ON CLICK OUTSIDE =====
    $(document).on('click', function(e) {
        if (activeInlineSearch && !$(e.target).closest('.stock-inline-search').length) {
            activeInlineSearch.closest('tr').find('.stock-action-cell .stock-cancel-match').trigger('click');
        }
        if (!$(e.target).closest('#stock-test-search-results').length && !$(e.target).closest('.stock-test-search-wrap').length) {
            $('#stock-test-search-results').addClass('hidden');
        }
    });

    $(function() {
        updateStepper(1);
    });

})(jQuery);
