jQuery(document).ready(function ($) {
    var isTroubleshooting = debugTroubleshoot.is_troubleshooting;
    var troubleshootState = debugTroubleshoot.current_state;
    var isDebugMode = debugTroubleshoot.is_debug_mode;

    // --- MODALS ---

    // Show custom alert modal
    function showAlert(title, message, type = 'success') {
        var modal = $('#debug-troubleshoot-alert-modal');
        $('#debug-troubleshoot-alert-title').text(title);
        $('#debug-troubleshoot-alert-message').text(message);

        if (type === 'error') {
            $('#debug-troubleshoot-alert-title').css('color', '#dc3232');
        } else {
            $('#debug-troubleshoot-alert-title').css('color', '');
        }

        modal.removeClass('hidden');
    }

    // Close alert modal
    $('#debug-troubleshoot-alert-close').on('click', function () {
        $('#debug-troubleshoot-alert-modal').addClass('hidden');
    });

    // Close confirmation modal
    $('#debug-troubleshoot-confirm-cancel').on('click', function () {
        $('#debug-troubleshoot-confirm-modal').addClass('hidden');
    });


    // --- EVENT HANDLERS ---

    // Handle toggle button for troubleshooting mode
    $('#troubleshoot-mode-toggle').on('click', function () {
        var $button = $(this);
        var enableMode = !isTroubleshooting; // Determine if we are enabling or disabling

        $button.prop('disabled', true).text(enableMode ? 'Activating...' : 'Deactivating...');

        $.ajax({
            url: debugTroubleshoot.ajax_url,
            type: 'POST',
            data: {
                action: 'debug_troubleshoot_toggle_mode',
                nonce: debugTroubleshoot.nonce,
                enable: enableMode ? 1 : 0
            },
            success: function (response) {
                if (response.success) {
                    showAlert(debugTroubleshoot.alert_title_success, response.data.message);
                    isTroubleshooting = enableMode; // Update state
                    // Refresh the page to apply cookie changes immediately
                    setTimeout(function () { location.reload(); }, 500);
                } else {
                    showAlert(debugTroubleshoot.alert_title_error, response.data.message, 'error');
                    $button.prop('disabled', false);
                }
            },
            error: function () {
                showAlert(debugTroubleshoot.alert_title_error, 'An AJAX error occurred.', 'error');
                $button.prop('disabled', false);
            }
        });
    });

    // Handle toggle button for Live Debug mode
    $('#debug-mode-toggle').on('click', function () {
        var $button = $(this);
        var enableMode = !isDebugMode;

        $button.prop('disabled', true).text(enableMode ? 'Enabling...' : 'Disabling...');

        $.ajax({
            url: debugTroubleshoot.ajax_url,
            type: 'POST',
            data: {
                action: 'debug_troubleshoot_toggle_debug_mode',
                nonce: debugTroubleshoot.nonce,
            },
            success: function (response) {
                if (response.success) {
                    showAlert(debugTroubleshoot.alert_title_success, response.data.message);
                    isDebugMode = enableMode; // Update state
                    $button.text(isDebugMode ? 'Disable Live Debug' : 'Enable Live Debug');
                    if (isDebugMode) {
                        $button.removeClass('button-primary').addClass('button-danger');
                    } else {
                        $button.removeClass('button-danger').addClass('button-primary');
                    }
                } else {
                    showAlert(debugTroubleshoot.alert_title_error, response.data.message, 'error');
                }
            },
            error: function () {
                showAlert(debugTroubleshoot.alert_title_error, 'An AJAX error occurred.', 'error');
            },
            complete: function () {
                $button.prop('disabled', false);
            }
        });
    });

    // Handle Clear Log button - Show confirmation modal
    $('#clear-debug-log').on('click', function () {
        var modal = $('#debug-troubleshoot-confirm-modal');
        $('#debug-troubleshoot-confirm-title').text('Confirm Action');
        $('#debug-troubleshoot-confirm-message').text('Are you sure you want to clear the debug.log file? This action cannot be undone.');
        modal.removeClass('hidden');
    });

    // Handle the actual log clearing after confirmation
    $('#debug-troubleshoot-confirm-ok').on('click', function () {
        var $button = $('#clear-debug-log');
        $button.prop('disabled', true);

        // IMMEDIATELY hide the confirm modal before showing the alert
        $('#debug-troubleshoot-confirm-modal').addClass('hidden');

        $.ajax({
            url: debugTroubleshoot.ajax_url,
            type: 'POST',
            data: {
                action: 'debug_troubleshoot_clear_debug_log',
                nonce: debugTroubleshoot.nonce
            },
            success: function (response) {
                if (response.success) {
                    $('#debug-log-viewer').val('Debug log cleared successfully.');
                    showAlert(debugTroubleshoot.alert_title_success, response.data.message);
                } else {
                    showAlert(debugTroubleshoot.alert_title_error, response.data.message, 'error');
                }
            },
            error: function () {
                showAlert(debugTroubleshoot.alert_title_error, 'An AJAX error occurred.', 'error');
            },
            complete: function () {
                $button.prop('disabled', false);
            }
        });
    });


    // Populate troubleshooting controls initially if mode is active
    if (isTroubleshooting) {
        $('#troubleshoot-mode-controls').removeClass('hidden');

        // Set selected theme
        if (troubleshootState && troubleshootState.theme) {
            $('#troubleshoot-theme-select').val(troubleshootState.theme);
        }

        // Check plugins based on troubleshooting state
        $('.plugin-list input[type="checkbox"]').each(function () {
            var $checkbox = $(this);
            var pluginFile = $checkbox.val();

            var troubleshootActive = false;

            if (troubleshootState && troubleshootState.plugins && troubleshootState.plugins.includes(pluginFile)) {
                troubleshootActive = true;
            }
            if (troubleshootState && troubleshootState.sitewide_plugins && troubleshootState.sitewide_plugins.includes(pluginFile)) {
                troubleshootActive = true;
            }

            $checkbox.prop('checked', troubleshootActive);
        });
    }

    // Handle applying troubleshooting changes
    $('#apply-troubleshoot-changes').on('click', function () {
        if (!isTroubleshooting) {
            showAlert(debugTroubleshoot.alert_title_error, 'Please enter troubleshooting mode first.', 'error');
            return;
        }

        var $button = $(this);
        $button.prop('disabled', true).text('Applying...');

        var selectedTheme = $('#troubleshoot-theme-select').val();
        var selectedPlugins = [];
        $('.plugin-list input[type="checkbox"]:checked').each(function () {
            selectedPlugins.push($(this).val());
        });

        $.ajax({
            url: debugTroubleshoot.ajax_url,
            type: 'POST',
            data: {
                action: 'debug_troubleshoot_update_state',
                nonce: debugTroubleshoot.nonce,
                theme: selectedTheme,
                plugins: selectedPlugins
            },
            success: function (response) {
                if (response.success) {
                    showAlert(debugTroubleshoot.alert_title_success, response.data.message);
                    // Refresh the page to apply cookie changes immediately
                    setTimeout(function () { location.reload(); }, 500);
                } else {
                    showAlert(debugTroubleshoot.alert_title_error, response.data.message, 'error');
                }
            },
            error: function () {
                showAlert(debugTroubleshoot.alert_title_error, 'An AJAX error occurred.', 'error');
            },
            complete: function () {
                $button.prop('disabled', false).text('Apply Troubleshooting Changes');
            }
        });
    });

    // --- UI Toggles ---

    // Collapsible Site Info Cards
    $('.card-collapsible-header').on('click', function () {
        var $header = $(this);
        var $content = $header.siblings('.card-collapsible-content');

        // Remove hidden class if it exists to allow slideToggle to work (it might have !important)
        if ($content.hasClass('hidden')) {
            $content.hide().removeClass('hidden');
        }

        $content.slideToggle(200);
        $header.toggleClass('collapsed');
    });

    // Toggle for theme/plugin sub-lists
    $('.info-sub-list-toggle').on('click', function (e) {
        e.preventDefault();
        var $link = $(this);
        var targetId = $link.data('target');
        var $list = $('#' + targetId);

        // Remove hidden class if it exists to allow slideToggle to work
        if ($list.hasClass('hidden')) {
            $list.hide().removeClass('hidden');
        }

        $list.slideToggle(200);

        if ($link.text() === debugTroubleshoot.show_all_text) {
            $link.text(debugTroubleshoot.hide_text);
        } else {
            $link.text(debugTroubleshoot.show_all_text);
        }
    });

    // Copy Site Info to Clipboard
    $('#copy-site-info').on('click', function (e) {
        e.stopPropagation(); // Prevent any other click events
        var $button = $(this);
        var siteInfoText = '';
        var siteInfoContent = document.getElementById('site-info-content');

        // Function to format and append a card's content
        function appendCardInfo(card) {
            var title = card.querySelector('h3').innerText;
            var infoList = card.querySelectorAll('p, li, h4');
            siteInfoText += '### ' + title + ' ###\n';
            infoList.forEach(function (item) {
                if (item.tagName.toLowerCase() === 'h4') {
                    siteInfoText += '\n--- ' + item.textContent.trim() + ' ---\n';
                } else {
                    var key = item.querySelector('strong') ? item.querySelector('strong').textContent.trim() : '';
                    var itemClone = item.cloneNode(true);
                    if (itemClone.querySelector('strong')) {
                        itemClone.querySelector('strong').remove();
                    }
                    var value = itemClone.textContent.trim().replace(/\s+/g, ' ');
                    if (key) {
                        siteInfoText += key + ' ' + value + '\n';
                    } else {
                        siteInfoText += value + '\n';
                    }
                }
            });
            siteInfoText += '\n';
        }

        // Iterate over each card and extract its information
        siteInfoContent.querySelectorAll('.debug-troubleshooter-card').forEach(appendCardInfo);

        // Use modern Clipboard API
        navigator.clipboard.writeText(siteInfoText.trim()).then(function () {
            var originalText = debugTroubleshoot.copy_button_text;
            $button.text(debugTroubleshoot.copied_button_text);
            setTimeout(function () {
                $button.text(originalText);
            }, 2000);
        }).catch(function (err) {
            // Fallback for older browsers
            var textArea = document.createElement("textarea");
            textArea.value = siteInfoText.trim();
            textArea.style.position = "fixed";
            textArea.style.top = 0;
            textArea.style.left = 0;
            textArea.style.width = "2em";
            textArea.style.height = "2em";
            textArea.style.padding = 0;
            textArea.style.border = "none";
            textArea.style.outline = "none";
            textArea.style.boxShadow = "none";
            textArea.style.background = "transparent";
            document.body.appendChild(textArea);
            textArea.focus();
            textArea.select();
            try {
                var successful = document.execCommand('copy');
                if (successful) {
                    var originalText = debugTroubleshoot.copy_button_text;
                    $button.text(debugTroubleshoot.copied_button_text);
                    setTimeout(function () {
                        $button.text(originalText);
                    }, 2000);
                } else {
                    showAlert(debugTroubleshoot.alert_title_error, 'Could not copy text.', 'error');
                }
            } catch (err) {
                showAlert(debugTroubleshoot.alert_title_error, 'Could not copy text: ' + err, 'error');
            }
            document.body.removeChild(textArea);
        });
    });
    // Handle User Simulation
    $('#simulate-user-btn').on('click', function () {
        var $button = $(this);
        var userId = $('#simulate-user-select').val();

        if (!userId) {
            showAlert(debugTroubleshoot.alert_title_error, 'Please select a user to simulate.', 'error');
            return;
        }

        $button.prop('disabled', true).text('Switching...');

        $.ajax({
            url: debugTroubleshoot.ajax_url,
            type: 'POST',
            data: {
                action: 'debug_troubleshoot_toggle_simulate_user',
                nonce: debugTroubleshoot.nonce,
                enable: 1,
                user_id: userId
            },
            success: function (response) {
                if (response.success) {
                    showAlert(debugTroubleshoot.alert_title_success, response.data.message);
                    setTimeout(function () { 
                        if (response.data && response.data.redirect) {
                            window.location.href = response.data.redirect;
                        } else {
                            location.reload(); 
                        }
                    }, 500);
                } else {
                    showAlert(debugTroubleshoot.alert_title_error, response.data.message, 'error');
                    $button.prop('disabled', false).text('Simulate User');
                }
            },
            error: function () {
                showAlert(debugTroubleshoot.alert_title_error, 'An AJAX error occurred.', 'error');
                $button.prop('disabled', false).text('Simulate User');
            }
        });
    });

    // Handle Send Test Email
    $('#send-test-email').on('click', function () {
        var $button = $(this);
        var recipient = $('#test-email-recipient').val();
        var $resultBox = $('#mail-debug-result');
        var $resultTitle = $('#mail-debug-result-title');
        var $resultMessage = $('#mail-debug-result-message');

        if (!recipient) {
            showAlert(debugTroubleshoot.alert_title_error, 'Please enter a recipient email address.', 'error');
            return;
        }

        $button.prop('disabled', true).text('Sending...');
        $resultBox.addClass('hidden').removeClass('bg-green-50 bg-red-50 border-green-200 border-red-200');

        $.ajax({
            url: debugTroubleshoot.ajax_url,
            type: 'POST',
            data: {
                action: 'debug_troubleshoot_send_test_email',
                nonce: debugTroubleshoot.nonce,
                to: recipient
            },
            success: function (response) {
                $resultBox.removeClass('hidden');
                if (response.success) {
                    $resultTitle.text('Success').css('color', '#0f5132');
                    $resultMessage.text(response.data.message);
                    $resultBox.addClass('bg-green-50 border-green-200').css({
                        'background-color': '#f0fdf4',
                        'border-color': '#bbf7d0',
                        'color': '#166534'
                    });
                } else {
                    $resultTitle.text('Failed').css('color', '#842029');
                    var msg = response.data.message;
                    if (response.data.debug) {
                        msg += '\n\nDebug Info:\n' + response.data.debug;
                    }
                    $resultMessage.text(msg);
                    $resultBox.addClass('bg-red-50 border-red-200').css({
                        'background-color': '#fef2f2',
                        'border-color': '#fecaca',
                        'color': '#991b1b'
                    });
                }
            },
            error: function () {
                showAlert(debugTroubleshoot.alert_title_error, 'An AJAX error occurred while sending the email.', 'error');
            },
            complete: function () {
                $button.prop('disabled', false).text('Send Test Email');
            }
        });
    });

    // --- TABS NAVIGATION ---
    var activeTab = localStorage.getItem('dbgtbl_active_tab') || 'general';
    
    // If a conflict check is active, force active tab to detective
    if (debugTroubleshoot.current_state && debugTroubleshoot.current_state.detective && debugTroubleshoot.current_state.detective.status !== 'inactive') {
        activeTab = 'detective';
    }
    
    switchTab(activeTab);

    $('.dbgtbl-nav-tabs .nav-tab').on('click', function (e) {
        e.preventDefault();
        var tabName = $(this).data('tab');
        switchTab(tabName);
    });

    function switchTab(tabName) {
        $('.dbgtbl-nav-tabs .nav-tab').removeClass('nav-tab-active');
        $('.dbgtbl-nav-tabs .nav-tab[data-tab="' + tabName + '"]').addClass('nav-tab-active');
        
        $('.dbgtbl-tab-content').addClass('hidden');
        $('#tab-content-' + tabName).removeClass('hidden');
        
        localStorage.setItem('dbgtbl_active_tab', tabName);
    }

    // --- CONFLICT CHECKER ---

    // Start Conflict Check
    $('#detective-start-btn').on('click', function () {
        var $btn = $(this);
        var mustKeep = [];
        
        $('.detective-keep-plugin:checked').each(function () {
            mustKeep.push($(this).val());
        });

        $btn.prop('disabled', true).text('Starting Check...');

        $.ajax({
            url: debugTroubleshoot.ajax_url,
            type: 'POST',
            data: {
                action: 'debug_troubleshoot_detective_start',
                nonce: debugTroubleshoot.nonce,
                must_keep: mustKeep
            },
            success: function (response) {
                if (response.success) {
                    showAlert(debugTroubleshoot.alert_title_success, response.data.message);
                    setTimeout(function () {
                        location.reload();
                    }, 1000);
                } else {
                    showAlert(debugTroubleshoot.alert_title_error, response.data.message, 'error');
                    $btn.prop('disabled', false).html('<span class="dashicons dashicons-search" style="margin-top: 4px; margin-right: 4px;"></span>Start Conflict Check');
                }
            },
            error: function () {
                showAlert(debugTroubleshoot.alert_title_error, 'An AJAX error occurred while starting the conflict check.', 'error');
                $btn.prop('disabled', false).html('<span class="dashicons dashicons-search" style="margin-top: 4px; margin-right: 4px;"></span>Start Conflict Check');
            }
        });
    });

    // Handle Yes/No answer buttons
    $('.detective-answer-btn').on('click', function () {
        var $btn = $(this);
        var answer = $btn.data('answer');
        
        $('.detective-answer-btn').prop('disabled', true);
        $btn.css('opacity', '0.7');

        $.ajax({
            url: debugTroubleshoot.ajax_url,
            type: 'POST',
            data: {
                action: 'debug_troubleshoot_detective_step',
                nonce: debugTroubleshoot.nonce,
                answer: answer
            },
            success: function (response) {
                if (response.success) {
                    showAlert(debugTroubleshoot.alert_title_success, response.data.message);
                    setTimeout(function () {
                        location.reload();
                    }, 1000);
                } else {
                    showAlert(debugTroubleshoot.alert_title_error, response.data.message, 'error');
                    $('.detective-answer-btn').prop('disabled', false).css('opacity', '1');
                }
            },
            error: function () {
                showAlert(debugTroubleshoot.alert_title_error, 'An AJAX error occurred while saving step.', 'error');
                $('.detective-answer-btn').prop('disabled', false).css('opacity', '1');
            }
        });
    });

    // Handle Abort / Reset
    $('#detective-abort-btn, #detective-reset-only-btn, #detective-reset-fail-btn').on('click', function (e) {
        e.preventDefault();
        var $btn = $(this);
        $btn.prop('disabled', true);

        $.ajax({
            url: debugTroubleshoot.ajax_url,
            type: 'POST',
            data: {
                action: 'debug_troubleshoot_detective_reset',
                nonce: debugTroubleshoot.nonce,
                deactivate_culprit: 0
            },
            success: function (response) {
                if (response.success) {
                    showAlert(debugTroubleshoot.alert_title_success, response.data.message);
                    setTimeout(function () {
                        location.reload();
                    }, 1000);
                } else {
                    showAlert(debugTroubleshoot.alert_title_error, response.data.message, 'error');
                    $btn.prop('disabled', false);
                }
            },
            error: function () {
                showAlert(debugTroubleshoot.alert_title_error, 'An AJAX error occurred while resetting the check.', 'error');
                $btn.prop('disabled', false);
            }
        });
    });

    // Handle Deactivate Culprit Globally
    $('#detective-deactivate-btn').on('click', function () {
        var $btn = $(this);
        $btn.prop('disabled', true).text('Deactivating...');

        $.ajax({
            url: debugTroubleshoot.ajax_url,
            type: 'POST',
            data: {
                action: 'debug_troubleshoot_detective_reset',
                nonce: debugTroubleshoot.nonce,
                deactivate_culprit: 1
            },
            success: function (response) {
                if (response.success) {
                    showAlert(debugTroubleshoot.alert_title_success, response.data.message);
                    setTimeout(function () {
                        localStorage.setItem('dbgtbl_active_tab', 'general');
                        location.reload();
                    }, 1000);
                } else {
                    showAlert(debugTroubleshoot.alert_title_error, response.data.message, 'error');
                    $btn.prop('disabled', false).html('<span class="dashicons dashicons-dismiss" style="margin-top: 2px;"></span>Deactivate Plugin Globally');
                }
            },
            error: function () {
                showAlert(debugTroubleshoot.alert_title_error, 'An AJAX error occurred while deactivating the plugin.', 'error');
                $btn.prop('disabled', false).html('<span class="dashicons dashicons-dismiss" style="margin-top: 2px;"></span>Deactivate Plugin Globally');
            }
        });
    });

    // --- PHP COMPATIBILITY CHECKER ---
    $('#compat-run-btn').on('click', function () {
        var $btn = $(this);
        var targetPhp = $('#compat-target-php').val();
        var $progressWrapper = $('#compat-progress-wrapper');
        var $progressBar = $('#compat-progress-bar');
        var $progressStatus = $('#compat-progress-status');
        var $progressPercent = $('#compat-progress-percent');
        var $resultsWrapper = $('#compat-results-wrapper');
        var $summary = $('#compat-summary');
        var $cards = $('#compat-cards');

        // Reset UI
        $btn.prop('disabled', true);
        $progressWrapper.removeClass('hidden');
        $progressBar.css('width', '0%');
        $progressStatus.text('Initializing scan...');
        $progressPercent.text('0%');
        $resultsWrapper.addClass('hidden');
        $summary.addClass('hidden');
        $summary.find('.compat-summary-count').text('0');
        $cards.empty();

        $.ajax({
            url: debugTroubleshoot.ajax_url,
            type: 'POST',
            data: {
                action: 'debug_troubleshoot_compat_start',
                nonce: debugTroubleshoot.nonce
            },
            success: function (response) {
                if (response.success && response.data.items) {
                    var items = response.data.items;
                    if (items.length === 0) {
                        showAlert(debugTroubleshoot.alert_title_error, 'No active components found to scan.', 'error');
                        $btn.prop('disabled', false);
                        $progressWrapper.addClass('hidden');
                        return;
                    }
                    
                    // Show results section
                    $resultsWrapper.removeClass('hidden');
                    
                    // Start scanning queue
                    processCompatQueue(items, 0, targetPhp);
                } else {
                    showAlert(debugTroubleshoot.alert_title_error, response.data.message || 'Could not start compatibility checker.', 'error');
                    $btn.prop('disabled', false);
                    $progressWrapper.addClass('hidden');
                }
            },
            error: function () {
                showAlert(debugTroubleshoot.alert_title_error, 'An AJAX error occurred while starting compatibility scan.', 'error');
                $btn.prop('disabled', false);
                $progressWrapper.addClass('hidden');
            }
        });

        function processCompatQueue(queue, index, targetPhp) {
            var total = queue.length;
            var percent = Math.round((index / total) * 100);
            
            $progressBar.css('width', percent + '%');
            $progressPercent.text(percent + '%');

            if (index >= total) {
                $progressStatus.text('Scan complete!');
                $progressBar.css('width', '100%');
                $progressPercent.text('100%');
                $btn.prop('disabled', false);
                showAlert(debugTroubleshoot.alert_title_success, 'PHP Compatibility Scan completed successfully!');
                return;
            }

            var item = queue[index];
            $progressStatus.text('Scanning ' + item.name + '...');

            var postData = {
                action: 'debug_troubleshoot_compat_scan_item',
                nonce: debugTroubleshoot.nonce,
                id: item.id,
                type: item.type,
                target_php: targetPhp
            };
            if (item.file) {
                postData.file = item.file;
            }

            $.ajax({
                url: debugTroubleshoot.ajax_url,
                type: 'POST',
                data: postData,
                success: function (response) {
                    if (response.success) {
                        renderCompatCard(item, response.data);
                    } else {
                        renderErrorCard(item, response.data.message || 'Error occurred during scan.');
                    }
                },
                error: function () {
                    renderErrorCard(item, 'AJAX request failed.');
                },
                complete: function () {
                    processCompatQueue(queue, index + 1, targetPhp);
                }
            });
        }

        function renderCompatCard(item, data) {
            var statusClass = data.status === 'compatible' ? 'compat-card-ok' : 
                              data.status === 'warning' ? 'compat-card-warning' : 'compat-card-error';
            var statusBadge = data.status === 'compatible' ? 
                '<span class="badge-compatible"><span class="dashicons dashicons-yes-alt"></span> Compatible</span>' :
                data.status === 'warning' ?
                '<span class="badge-warning"><span class="dashicons dashicons-warning"></span> Warning</span>' :
                '<span class="badge-incompatible"><span class="dashicons dashicons-dismiss"></span> Incompatible</span>';

            var typeLabel = item.type.charAt(0).toUpperCase() + item.type.slice(1);
            var minRequired = data.requires_php ? 'PHP ' + data.requires_php : 'Not specified';

            var issuesHtml = '';
            if (data.details && data.details.length > 0) {
                issuesHtml += '<div class="compat-card-issues">';
                data.details.forEach(function (detail) {
                    var levelIcon = detail.level === 'incompatible' ? 'dismiss' : 'warning';
                    issuesHtml += '<div class="compat-issue compat-issue-' + detail.level + '">';
                    if (detail.file) {
                        issuesHtml += '<div class="compat-issue-location"><span class="dashicons dashicons-media-code"></span> ' + escapeHtml(detail.file) + ':' + detail.line + '</div>';
                    }
                    issuesHtml += '<div class="compat-issue-message"><span class="dashicons dashicons-' + levelIcon + '"></span> ' + escapeHtml(detail.message) + '</div>';
                    if (detail.snippet) {
                        issuesHtml += '<pre class="compat-snippet">' + escapeHtml(detail.snippet) + '</pre>';
                    }
                    issuesHtml += '</div>';
                });
                issuesHtml += '</div>';
            }

            var cardHtml = 
                '<div class="compat-card ' + statusClass + '">' +
                    '<div class="compat-card-top">' +
                        '<div class="compat-card-heading">' +
                            '<strong class="compat-card-name">' + escapeHtml(item.name) + '</strong>' +
                            '<span class="compat-card-version">v' + escapeHtml(item.version) + '</span>' +
                        '</div>' +
                        '<div class="compat-card-badges">' +
                            '<span class="compat-card-type">' + typeLabel + '</span>' +
                            statusBadge +
                        '</div>' +
                    '</div>' +
                    '<div class="compat-card-body">' +
                        '<div class="compat-card-meta">' +
                            '<span><strong>Requires:</strong> ' + minRequired + '</span>' +
                            (data.details && data.details.length > 0 ? '<span class="compat-issue-count">' + data.details.length + ' issue' + (data.details.length !== 1 ? 's' : '') + ' found</span>' : '') +
                        '</div>' +
                        issuesHtml +
                    '</div>' +
                '</div>';

            $cards.append(cardHtml);
            updateCompatSummary(data.status);
        }

        function renderErrorCard(item, errorMsg) {
            var cardHtml = 
                '<div class="compat-card compat-card-error">' +
                    '<div class="compat-card-top">' +
                        '<div class="compat-card-heading">' +
                            '<strong class="compat-card-name">' + escapeHtml(item.name) + '</strong>' +
                            '<span class="compat-card-version">v' + escapeHtml(item.version) + '</span>' +
                        '</div>' +
                        '<div class="compat-card-badges">' +
                            '<span class="compat-card-type">' + (item.type.charAt(0).toUpperCase() + item.type.slice(1)) + '</span>' +
                            '<span class="badge-incompatible"><span class="dashicons dashicons-dismiss"></span> Error</span>' +
                        '</div>' +
                    '</div>' +
                    '<div class="compat-card-body">' +
                        '<div class="compat-card-meta compat-card-error-msg">' +
                            '<span class="dashicons dashicons-warning"></span> ' + escapeHtml(errorMsg) +
                        '</div>' +
                    '</div>' +
                '</div>';

            $cards.append(cardHtml);
            updateCompatSummary('warning');
        }

        function updateCompatSummary(status) {
            var $item = $summary.find('.compat-summary-item.' + status);
            var count = parseInt($item.attr('data-count')) + 1;
            $item.attr('data-count', count);
            $item.find('.compat-summary-count').text(count);
            $summary.removeClass('hidden');
        }

        function escapeHtml(text) {
            var map = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            };
            return text.replace(/[&<>"']/g, function(m) { return map[m]; });
        }
    });
});
