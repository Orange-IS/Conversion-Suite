<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait OISCL_Admin_Component_Trait {

    // ========================================================================
    // OIS COMPONENT RENDERER (UNIFIED UI) v 1.3
    // ========================================================================
    public function render_ois_component($type, $args = array()) {
        switch ($type) {
            
            // --- 1. LAYOUT PRINCIPAL ---
            case 'layout_start':
                $id = isset($args['id']) ? $args['id'] : 'oiscl-generic-wrap';
                echo '<div class="wrap oiscl-layout-root" id="' . esc_attr($id) . '" style="background:#f1f5f9; padding:20px; margin:0; min-height:100vh; max-width:100%; box-sizing:border-box;">';
                echo '<style> 
                    body.oiscl-presentation-mode #adminmenumain, body.oiscl-presentation-mode #wpadminbar, body.oiscl-presentation-mode #wpfooter { display: none !important; } 
                    body.oiscl-presentation-mode #wpcontent { margin-left: 0 !important; padding-top: 0 !important; background: #f0f2f5 !important; } 
                    
                    /* --- ESTILOS GLOBALES PREMIUM PARA ADVANCED_TABLE --- */
                    th.j-sortable { cursor: pointer !important; user-select:none; transition:0.2s; position:relative; } 
                    th.j-sortable:hover { background: #f0f7ff !important; color:#1a73e8; } 
                    .sort-icon { font-size:10px; margin-left:4px; color:#1a73e8; } 
                    .ois-table-hover tbody tr.ois-row:hover, .ois-table-hover tbody tr.ois-row-accordion:hover { background-color: #f0f7ff !important; cursor: pointer; }
                    .filter-dropdown-container { position: relative; } 
                    .filter-menu { position: absolute; top: 110%; right: 0; background: #fff; border: 1px solid #ccd0d4; border-radius: 4px; box-shadow: 0 4px 12px rgba(0,0,0,0.15); z-index: 999; width: 220px; padding: 10px; display: none; } 
                    .filter-menu.active { display: block; } 
                    .filter-item { display:flex; align-items:center; gap:8px; padding:8px 5px; font-size:13px; cursor:pointer; }
                    .badge-cat { font-size: 9px; padding: 2px 5px; border-radius: 3px; text-transform: uppercase; font-weight: bold; margin-right: 8px; display: inline-block; min-width: 55px; text-align: center; } 
                    .cat-contact { background: #e6fffa; color: #047481; border: 1px solid #b2f5ea; } 
                    .cat-forms { background: #ebf4ff; color: #2b6cb0; border: 1px solid #bee3f8; } 
                    .cat-pages { background: #e9d8fd; color: #553c9a; border: 1px solid #d6bcfa; } 
                    .cat-media { background: #fff5f5; color: #c53030; border: 1px solid #feb2b2; } 
                    .cat-external { background: #fffaf0; color: #9c4221; border: 1px solid #feebc8; } 
                    .cat-interface { background: #f7fafc; color: #4a5568; border: 1px solid #edf2f7; }
                    .ois-box { background:#fff; border:1px solid #ccd0d4; padding:20px; border-radius:4px; margin-bottom:25px; box-sizing:border-box; width:100%; display:block; }
                </style>';
                break;

            case 'layout_end':
                echo '</div>'; // Cierra layout_start
                ?>
                <script>
                jQuery(document).ready(function($) {
                    
                    // --- 0. SELECTOR DE FECHAS (dropdown: sin colas de animación; cierre global solo si hay panel abierto) ---
                    $(document).on('click', '.oiscl-date-toggle', function(e) {
                        e.preventDefault();
                        e.stopPropagation();
                        var $dd = $(this).next('.oiscl-date-dropdown');
                        var opening = !$dd.is(':visible');
                        $('.oiscl-date-dropdown').not($dd).stop(true, true).hide();
                        if (opening) {
                            $dd.stop(true, true).show();
                        } else {
                            $dd.stop(true, true).hide();
                        }
                    });

                    $(document).on('click', function(e) {
                        if (!$(e.target).closest('.oiscl-date-wrap').length) {
                            var $vis = $('.oiscl-date-dropdown:visible');
                            if ($vis.length) {
                                $vis.stop(true, true).hide();
                            }
                        }
                        if (!$(e.target).closest('details.ois-export-menu').length) {
                            $('details.ois-export-menu[open]').prop('open', false);
                        }
                    });
                    
                    // --- MOTOR GLOBAL ADVANCED TABLE (Paginación, Sort, Accordion, PDF) ---
                    // 1. Accordion Toggle
                    $(document).on('click', 'tr.ois-row-accordion', function() {
                        var $det = $(this).next('tr.ois-row-details');
                        if($det.length) {
                            $det.toggle();
                            var $arrow = $(this).find('.j-arrow');
                            if($det.is(':visible')) {
                                $arrow.css('transform', 'rotate(90deg)');
                                if (typeof window.oisclJourneyDetailPageUpdate === 'function') {
                                    $det.find('.oiscl-utm-journey-events-table').each(function() {
                                        window.oisclJourneyDetailPageUpdate($(this));
                                    });
                                }
                            } else {
                                $arrow.css('transform', 'rotate(0deg)');
                            }
                        }
                    });

                    $(document).on('click', '.oiscl-copy-text', function(e) {
                        e.preventDefault();
                        e.stopPropagation();
                        var text = $(this).attr('data-copy') || '';
                        if (!text || !navigator.clipboard) return;
                        var $btn = $(this);
                        var orig = $btn.html();
                        navigator.clipboard.writeText(text).then(function() {
                            $btn.html('✅');
                            setTimeout(function() { $btn.html(orig); }, 1500);
                        });
                    });

                    // 2. Paginación Inteligente
                    function setupAdvancedTable(tableId) {
                        let $table = $('#' + tableId);
                        let $allRows = $table.find('tbody tr').filter('.ois-row, .ois-row-accordion');
                        let $paginator = $('#pag-wrap-' + tableId);
                        let $selector = $('.oiscl-row-selector[data-target="'+tableId+'"]');
                        let currentPage = 1;
                        
                        function draw() {
                            let rowsPerPage = parseInt($selector.val()) || 20;
                            let $visibleRows = $allRows.not('.ois-filtered-out');
                            let totalPages = Math.ceil($visibleRows.length / rowsPerPage) || 1;
                            if (currentPage > totalPages) currentPage = totalPages;
                            if (currentPage < 1) currentPage = 1;
                            
                            $allRows.hide();
                            $table.find('tbody tr.ois-row-details').hide();
                            $table.find('.j-arrow').css('transform', 'rotate(0deg)');

                            $visibleRows.slice((currentPage - 1) * rowsPerPage, currentPage * rowsPerPage).show();
                            
                            if (totalPages <= 1) { $paginator.hide(); return; }
                            $paginator.show();
                            
                            let html = `<button class="button tp-prev" data-target="${tableId}" ${currentPage === 1 ? 'disabled' : ''}>&lsaquo;</button>`;
                            let startPage = Math.max(1, currentPage - 2); 
                            let endPage = Math.min(totalPages, currentPage + 2);
                            for (let i = startPage; i <= endPage; i++) { 
                                html += `<span class="tp-page-num ${i === currentPage ? 'active' : ''}" data-page="${i}" data-target="${tableId}" style="padding:5px 10px; cursor:pointer; ${i === currentPage ? 'background:#1a73e8; color:#fff; border-radius:3px;' : 'background:#fff; border:1px solid #ccd0d4; border-radius:3px; font-weight:bold;'}">${i}</span>`; 
                            }
                            html += `<button class="button tp-next" data-target="${tableId}" ${currentPage === totalPages ? 'disabled' : ''}>&rsaquo;</button>`;
                            $paginator.html(html);
                        }
                        
                        $selector.off('change').on('change', function() { currentPage = 1; draw(); });
                        draw();
                        $table.data('drawFn', draw); 
                        $table.data('setPage', function(p) { currentPage = p; });
                    }

                    window.oisclSetupAdvancedTable = setupAdvancedTable;

                    window.oisclJourneyDetailPageUpdate = function($table) {
                        if (!$table || !$table.length) return;
                        var tableId = $table.attr('id');
                        if (!tableId) return;
                        var pageSize = parseInt($table.attr('data-page-size'), 10) || 6;
                        var currentPage = parseInt($table.attr('data-current-page'), 10) || 1;
                        var $rows = $table.find('tbody tr.oiscl-journey-pag-row');
                        var totalPages = Math.ceil($rows.length / pageSize) || 1;
                        if (currentPage > totalPages) currentPage = totalPages;
                        if (currentPage < 1) currentPage = 1;
                        $table.attr('data-current-page', currentPage);
                        $rows.hide();
                        $rows.slice((currentPage - 1) * pageSize, currentPage * pageSize).show();
                        $('#pag-cur-' + tableId).text(currentPage);
                        var $wrap = $('#pag-wrap-' + tableId);
                        $wrap.find('.pag-prev').prop('disabled', currentPage === 1);
                        $wrap.find('.pag-next').prop('disabled', currentPage === totalPages || totalPages === 0);
                        $wrap.css('display', $rows.length > pageSize ? 'flex' : 'none');
                    };

                    window.oisclInitDataTablePagination = function(tableId) {
                        window.oisclJourneyDetailPageUpdate($('#' + tableId));
                    };

                    if (!window.__oisclUtmJourneyPagBound) {
                        window.__oisclUtmJourneyPagBound = 1;

                    $(document).on('change', '.oiscl-utm-journey-panel .ois-row-selector', function() {
                        var tid = $(this).data('target');
                        var $t = $('#' + tid);
                        if (!$t.length) return;
                        $t.attr('data-page-size', $(this).val()).attr('data-current-page', 1);
                        window.oisclJourneyDetailPageUpdate($t);
                    });

                    $(document).on('click', '.oiscl-utm-journey-panel .pag-prev', function(e) {
                        e.preventDefault();
                        e.stopPropagation();
                        var tid = $(this).data('target');
                        var $t = $('#' + tid);
                        if (!$t.length) return;
                        var cur = parseInt($t.attr('data-current-page'), 10) || 1;
                        if (cur > 1) {
                            $t.attr('data-current-page', cur - 1);
                            window.oisclJourneyDetailPageUpdate($t);
                        }
                    });

                    $(document).on('click', '.oiscl-utm-journey-panel .pag-next', function(e) {
                        e.preventDefault();
                        e.stopPropagation();
                        var tid = $(this).data('target');
                        var $t = $('#' + tid);
                        if (!$t.length) return;
                        var cur = parseInt($t.attr('data-current-page'), 10) || 1;
                        var pageSize = parseInt($t.attr('data-page-size'), 10) || 6;
                        var max = Math.ceil($t.find('tbody tr.oiscl-journey-pag-row').length / pageSize) || 1;
                        if (cur < max) {
                            $t.attr('data-current-page', cur + 1);
                            window.oisclJourneyDetailPageUpdate($t);
                        }
                    });

                    }

                    $('table.ois-table-hover').each(function() { setupAdvancedTable(this.id); });

                    window.oisclAttachThResizers = function(rootEl) {
                        var scope = rootEl || document;
                        scope.querySelectorAll('th.j-sortable').forEach(function(col) {
                            if (col.querySelector('.oiscl-th-resizer')) return;
                            const resizer = document.createElement('div');
                            resizer.className = 'oiscl-th-resizer';
                            resizer.style.width = '6px';
                            resizer.style.height = '100%';
                            resizer.style.position = 'absolute';
                            resizer.style.right = '0';
                            resizer.style.top = '0';
                            resizer.style.cursor = 'col-resize';
                            resizer.style.userSelect = 'none';
                            resizer.style.zIndex = '10';
                            resizer.addEventListener('mouseenter', function() { if (!resizer.isResizing) resizer.style.borderRight = '2px solid #1a73e8'; });
                            resizer.addEventListener('mouseleave', function() { if (!resizer.isResizing) resizer.style.borderRight = ''; });
                            col.appendChild(resizer);
                            let x = 0, w = 0;
                            const mouseDownHandler = function(e) {
                                e.stopPropagation();
                                x = e.clientX;
                                w = parseInt(window.getComputedStyle(col).width, 10) || 0;
                                resizer.isResizing = true;
                                resizer.style.borderRight = '2px solid #1a73e8';
                                document.addEventListener('mousemove', mouseMoveHandler);
                                document.addEventListener('mouseup', mouseUpHandler);
                            };
                            const mouseMoveHandler = function(e) {
                                const next = Math.max(40, w + (e.clientX - x));
                                col.style.width = next + 'px';
                            };
                            const mouseUpHandler = function() {
                                resizer.isResizing = false;
                                resizer.style.borderRight = '';
                                document.removeEventListener('mousemove', mouseMoveHandler);
                                document.removeEventListener('mouseup', mouseUpHandler);
                            };
                            resizer.addEventListener('mousedown', mouseDownHandler);
                            resizer.addEventListener('click', function(e) { e.stopPropagation(); });
                        });
                    };
                    window.oisclAttachThResizers(document);
                    $(document).on('click', '.tp-prev', function(e) {
                        e.preventDefault();
                        const tid = $(this).data('target');
                        const $t = $('#' + tid);
                        const cur = parseInt($('#pag-wrap-' + tid + ' .tp-page-num.active').data('page'), 10) || 1;
                        if (cur > 1) { $t.data('setPage')(cur - 1); $t.data('drawFn')(); }
                    });
                    $(document).on('click', '.tp-next', function(e) {
                        e.preventDefault();
                        const tid = $(this).data('target');
                        const $t = $('#' + tid);
                        const cur = parseInt($('#pag-wrap-' + tid + ' .tp-page-num.active').data('page'), 10) || 1;
                        const rpp = parseInt($('.oiscl-row-selector[data-target="' + tid + '"]').val(), 10) || 20;
                        const max = Math.ceil($t.find('tbody tr').filter('.ois-row, .ois-row-accordion').not('.ois-filtered-out').length / rpp) || 1;
                        if (cur < max) { $t.data('setPage')(cur + 1); $t.data('drawFn')(); }
                    });
                    $(document).on('click', '.tp-page-num', function(e) {
                        e.preventDefault();
                        const tid = $(this).data('target');
                        $('#' + tid).data('setPage')($(this).data('page'));
                        $('#' + tid).data('drawFn')();
                    });

                    // 3. Sorting (Compatible con Acordeones y Tiempos)
                    $(document).on('click', '.j-sortable', function() {
                        let $table = $(this).closest('table'); let $tbody = $table.find('tbody');
                        let colIndex = $(this).data('col'); let type = $(this).data('type'); let isAsc = $(this).hasClass('asc');
                        
                        $table.find('th').removeClass('asc desc'); $table.find('.sort-icon').text('');
                        $(this).addClass(isAsc ? 'desc' : 'asc'); $(this).find('.sort-icon').text(isAsc ? ' ▼' : ' ▲');

                        let rowPairs = [];
                        $tbody.find('tr').filter('.ois-row, .ois-row-accordion').each(function() {
                            rowPairs.push({ main: $(this), det: $(this).next('tr.ois-row-details'), val: $(this).find('td').eq(colIndex).text().trim() });
                        });

                        rowPairs.sort(function(a, b) {
                            var valA = a.val; var valB = b.val;
                            if (type === 'numeric') { 
                                function parseNum(v) {
                                    if(v.includes('m')) return parseFloat(v.replace(/[^0-9.-]+/g,"")) * 60;
                                    if(v.includes('s')) return parseFloat(v.replace(/[^0-9.-]+/g,""));
                                    return parseFloat(v.replace(/[^0-9.-]+/g,"")) || 0;
                                }
                                var numA = parseNum(valA); var numB = parseNum(valB);
                                return isAsc ? numA - numB : numB - numA; 
                            }
                            return isAsc ? valA.localeCompare(valB) : valB.localeCompare(valA);
                        });

                        $tbody.empty();
                        $.each(rowPairs, function(i, pair) { $tbody.append(pair.main); if (pair.det.length) { $tbody.append(pair.det); } });

                        if($table.data('setPage')) { $table.data('setPage')(1); $table.data('drawFn')(); }
                    });

                    $(document).on('click', '.ois-export-menu-png', function(e) {
                        e.preventDefault();
                        var canvasId = $(this).data('canvas');
                        var fn = $(this).data('filename') || 'chart.png';
                        var canvas = document.getElementById(canvasId);
                        if (!canvas) return;
                        var link = document.createElement('a');
                        link.download = fn;
                        link.href = canvas.toDataURL('image/png');
                        link.click();
                        $(this).closest('details.ois-export-menu').prop('open', false);
                    });
                    $(document).on('click', '.ois-export-menu-pdf-chart', function(e) {
                        e.preventDefault();
                        var canvasId = $(this).data('canvas');
                        var title = $(this).data('title') || 'Chart';
                        var canvas = document.getElementById(canvasId);
                        if (!canvas) return;
                        var printWin = window.open('', '', 'width=900,height=700');
                        if (!printWin) return;
                        printWin.document.write('<html><head><title>' + String(title).replace(/</g,'') + '</title><style>body{font-family:system-ui,sans-serif;padding:20px;}h1{font-size:18px;}img{max-width:100%;height:auto;}</style></head><body>');
                        printWin.document.write('<h1>' + String(title).replace(/</g,'') + '</h1><img src="' + canvas.toDataURL('image/png') + '" alt="">');
                        printWin.document.write('</body></html>');
                        printWin.document.close();
                        printWin.focus();
                        setTimeout(function() { printWin.print(); printWin.close(); }, 400);
                        $(this).closest('details.ois-export-menu').prop('open', false);
                    });

                    // Tablas simples (data_table): CSV / PDF desde el DOM (misma lógica que el dashboard).
                    $(document).on('click', '.ois-export-csv, .ois-export-pdf', function(e) {
                        e.preventDefault();
                        var type = $(this).hasClass('ois-export-csv') ? 'csv' : 'pdf';
                        var tableId = $(this).data('target');
                        var $table = $('#' + tableId);
                        var title = $(this).closest('div[style*="background:#fff"]').find('h3').first().text().replace(/[^a-zA-Z0-9]/g, '_');
                        if (type === 'csv') {
                            var csv = [];
                            $table.find('tr').each(function() {
                                var row = [];
                                $(this).find('th, td').each(function() {
                                    var text = $(this).text().replace(/▲|▼|—/g, '').replace(/Past: \d+/g, '').replace(/\s+/g, ' ').trim();
                                    row.push('"' + text + '"');
                                });
                                csv.push(row.join(','));
                            });
                            var blob = new Blob([csv.join('\n')], { type: 'text/csv' });
                            var link = document.createElement('a');
                            link.href = window.URL.createObjectURL(blob);
                            link.download = 'OIS_' + title + '.csv';
                            link.click();
                        } else if (type === 'pdf') {
                            var printWin = window.open('', '', 'width=800,height=600');
                            printWin.document.write('<html><head><title>Export Report</title>');
                            printWin.document.write('<style>body{font-family:sans-serif;} table{width:100%; border-collapse:collapse; margin-top:20px;} th,td{border:1px solid #ccc; padding:10px; text-align:left;} th{background:#f1f5f9;}</style>');
                            printWin.document.write('</head><body><h2>' + title.replace(/_/g, ' ') + '</h2>');
                            var $clone = $table.clone();
                            $clone.find('tr').show();
                            printWin.document.write($clone.prop('outerHTML'));
                            printWin.document.write('</body></html>');
                            printWin.document.close();
                            printWin.focus();
                            setTimeout(function() { printWin.print(); printWin.close(); }, 500);
                        }
                        $(this).closest('details.ois-export-menu').prop('open', false);
                    });

                    // 4. Export PDF (html2pdf): oculta toolbar, menús Export y paginación; muestra todas las filas visibles.
                    $(document).on('click', '.oiscl-export-pdf', function(e) {
                        e.preventDefault();
                        var btn = $(this);
                        var oText = btn.text();
                        btn.text('⏳ PDF...').prop('disabled', true);
                        var targetId = btn.data('target');
                        var element = document.getElementById(targetId);
                        if (!element) {
                            btn.text(oText).prop('disabled', false);
                            return;
                        }
                        $(btn).closest('details.ois-export-menu').prop('open', false);

                        var toolbar = element.querySelector('.oiscl-toolbar-right') || element.querySelector('.filter-dropdown-container');
                        if (toolbar) {
                            toolbar.style.display = 'none';
                        }

                        var exportMenus = element.querySelectorAll('details.ois-export-menu');
                        var exportMenuDisplay = [];
                        exportMenus.forEach(function(m) {
                            exportMenuDisplay.push(m.style.display);
                            m.style.display = 'none';
                        });

                        var table = element.querySelector('table');
                        if (table) {
                            $(table).find('tbody tr.ois-row-details').hide();
                            $(table).find('tbody tr').filter('.ois-row, .ois-row-accordion').not('.ois-filtered-out').show();
                        }
                        $(element).find('.ois-pag-controls').hide();

                        html2pdf().set({ margin: 0.4, filename: btn.data('filename') + '.pdf', image: { type: 'jpeg', quality: 0.98 }, html2canvas: { scale: 2 }, jsPDF: { orientation: 'landscape' } }).from(element).save().then(function() {
                            btn.text(oText).prop('disabled', false);
                            if (toolbar) {
                                toolbar.style.display = 'flex';
                            }
                            exportMenus.forEach(function(m, i) {
                                m.style.display = exportMenuDisplay[i] || '';
                            });
                            $(element).find('.ois-pag-controls').css('display', 'flex');
                            if (table && $(table).data('drawFn')) {
                                $(table).data('drawFn')();
                            }
                        });
                    });
                });
                </script>
                <?php
                break;
            // --- 2. SELECTOR DE FECHAS (Sincronizado y Robusto) ---
            case 'date_selector':
                // Si no viene slug, detectamos automáticamente en qué página estamos
                $page_slug = isset( $args['page_slug'] ) ? (string) $args['page_slug'] : ( isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : 'oiscl-intro' );
                // Legacy callers embedded "&tab=..." inside page_slug; that corrupts the `page` hidden field and drops params like uct_tab on Apply Range.
                if ( strpos( $page_slug, '&' ) !== false ) {
                    $page_slug = sanitize_text_field( strtok( $page_slug, '&' ) );
                }
                $start_date   = isset($args['start_date']) ? $args['start_date'] : date('Y-m-d');
                $end_date     = isset($args['end_date']) ? $args['end_date'] : date('Y-m-d');
                $preset_label = isset($args['preset']) ? $args['preset'] : 'Today';

                $today = current_time('Y-m-d');
                $display_date = ($start_date === $end_date) ? date('M j, Y', strtotime($start_date)) : date('M j, Y', strtotime($start_date)) . ' - ' . date('M j, Y', strtotime($end_date));
                $diff_days = round((strtotime($end_date) - strtotime($start_date)) / 86400);
                
                $prev_end = date('Y-m-d', strtotime($start_date . ' - 1 days')); 
                $prev_start = date('Y-m-d', strtotime($prev_end . ' - ' . $diff_days . ' days'));
                $next_start = date('Y-m-d', strtotime($end_date . ' + 1 days')); 
                $next_end = date('Y-m-d', strtotime($next_start . ' + ' . $diff_days . ' days'));
                
                $current_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : '';
                $tab_param = !empty($current_tab) ? '&tab=' . esc_attr($current_tab) : '';
                $ct_tab = isset( $_GET['ct_tab'] ) ? sanitize_key( wp_unslash( $_GET['ct_tab'] ) ) : '';
                $uct_tab_raw = isset( $_GET['uct_tab'] ) ? sanitize_key( wp_unslash( $_GET['uct_tab'] ) ) : '';
        $utm_filter_sel = isset( $_GET['utm_filter'] ) ? sanitize_text_field( wp_unslash( $_GET['utm_filter'] ) ) : '';
        $utm_attr_sel   = isset( $_GET['utm_attr'] ) ? sanitize_key( wp_unslash( $_GET['utm_attr'] ) ) : '';
        $ct_tab_param = $ct_tab ? '&ct_tab=' . esc_attr( $ct_tab ) : '';
                // UTM Click Tracker sub-tab: keep overview/clicks/reading on preset arrows / Apply Range even when URL omitted uct_tab (defaults to overview server-side).
                $uct_eff_click = '';
                if ( 'click_tracker' === $current_tab ) {
                    $uct_eff_click = ( $uct_tab_raw && in_array( $uct_tab_raw, array( 'overview', 'clicks', 'reading' ), true ) )
                        ? $uct_tab_raw
                        : 'overview';
                }
                $uct_tab_param = '' !== $uct_eff_click ? '&uct_tab=' . esc_attr( $uct_eff_click ) : '';
        $utm_filter_param = ( $utm_filter_sel && 'all' !== $utm_filter_sel ) ? '&utm_filter=' . esc_attr( $utm_filter_sel ) : '';
        $utm_attr_param   = ( $utm_attr_sel && 'first' !== $utm_attr_sel && in_array( $utm_attr_sel, array( 'last', 'session' ), true ) ) ? '&utm_attr=' . esc_attr( $utm_attr_sel ) : '';
                $tp_page = isset( $_GET['tp_page'] ) ? (int) $_GET['tp_page'] : 0;
                $tp_revision = isset( $_GET['tp_revision'] ) ? (int) $_GET['tp_revision'] : 0;
                $scope_param = '';
                if ( $tp_page > 0 ) {
                    $scope_param .= '&tp_page=' . $tp_page;
                }
                if ( $tp_revision > 0 ) {
                    $scope_param .= '&tp_revision=' . $tp_revision;
                }
                $retention_min = OISCL_Plan::get_metrics_earliest_date( $today );
                $retention_min_attr = $retention_min ? ' min="' . esc_attr( $retention_min ) . '"' : '';

                echo '<div style="display:flex; align-items:center; gap:5px;" class="ois-date-selector-container">';
                echo '<a href="?page='.$page_slug.$tab_param.$ct_tab_param.$uct_tab_param.$utm_filter_param.$utm_attr_param.$scope_param.'&start_date='.$start_date.'&end_date='.$end_date.'" class="button ois-refresh-link" title="Refresh"><span class="dashicons dashicons-image-rotate" style="margin-top:4px;"></span></a>';
                echo '<a href="?page='.$page_slug.$tab_param.$ct_tab_param.$uct_tab_param.$utm_filter_param.$utm_attr_param.$scope_param.'&start_date='.$prev_start.'&end_date='.$prev_end.'" class="button ois-prev-link"><span class="dashicons dashicons-arrow-left-alt2" style="margin-top:4px;"></span></a>';
                
                echo '<div style="position:relative; display:inline-block;" class="oiscl-date-wrap">';
                echo '<button class="button oiscl-date-toggle" style="font-weight:500;" type="button">';
                echo '<span style="color:#666; font-weight:normal;">'.$preset_label.':</span> '.$display_date.' <span class="dashicons dashicons-arrow-down-alt2" style="font-size:14px; margin-top:2px;"></span></button>';
                echo '<div class="oiscl-date-dropdown" style="display:none; position:absolute; right:0; top:35px; background:#fff; border:1px solid #ccc; padding:15px; border-radius:4px; width:280px; z-index:9999; box-shadow:0 4px 12px rgba(0,0,0,0.15);">';
                    echo '<div style="display:grid; grid-template-columns: 1fr 1fr; gap:8px; margin-bottom:12px; border-bottom:1px solid #eee; padding-bottom:12px;">';
                    foreach(['today'=>__('Today','ois-conversion-suite'),'yesterday'=>__('Yesterday','ois-conversion-suite'),'7days'=>__('7 Days','ois-conversion-suite'),'30days'=>__('30 Days','ois-conversion-suite'),'activity'=>__('Activity','ois-conversion-suite')] as $p => $n) {
                        echo '<a href="?page='.$page_slug.$tab_param.$ct_tab_param.$uct_tab_param.$utm_filter_param.$utm_attr_param.$scope_param.'&preset='.$p.'" class="button text-center">'.$n.'</a>';
                    }
                    echo '</div>';
                    echo '<form action="'.admin_url('admin.php').'" method="GET" style="display:flex; flex-direction:column; gap:10px;">';
                    echo '<input type="hidden" name="page" value="' . esc_attr( $page_slug ) . '">';
                    echo '<input type="hidden" name="tab" class="ois-hidden-tab-input" value="'.esc_attr($current_tab).'">';
                    if ( $ct_tab ) {
                        echo '<input type="hidden" name="ct_tab" value="'.esc_attr($ct_tab).'">';
                    }
                    if ( '' !== $uct_eff_click ) {
                        echo '<input type="hidden" name="uct_tab" value="' . esc_attr( $uct_eff_click ) . '">';
                    }
                    if ( $utm_filter_sel && 'all' !== $utm_filter_sel ) {
                        echo '<input type="hidden" name="utm_filter" value="'.esc_attr($utm_filter_sel).'">';
                    }
                    if ( $utm_attr_sel && in_array( $utm_attr_sel, array( 'last', 'session' ), true ) ) {
                        echo '<input type="hidden" name="utm_attr" value="'.esc_attr($utm_attr_sel).'">';
                    }
                    if ( $tp_page > 0 ) {
                        echo '<input type="hidden" name="tp_page" value="'.esc_attr( (string) $tp_page ).'">';
                    }
                    if ( $tp_revision > 0 ) {
                        echo '<input type="hidden" name="tp_revision" value="'.esc_attr( (string) $tp_revision ).'">';
                    }
                    echo '<div><label style="display:block; font-size:11px; color:#666;">Desde:</label><input type="date" name="start_date" max="'.$today.'"'.$retention_min_attr.' value="'.$start_date.'" style="width:100%;"></div>';
                    echo '<div><label style="display:block; font-size:11px; color:#666;">Hasta:</label><input type="date" name="end_date" max="'.$today.'"'.$retention_min_attr.' value="'.$end_date.'" style="width:100%;"></div>';
                    echo '<button type="submit" class="button button-primary">Aplicar Rango</button></form></div></div>';
                
                if (strtotime($next_start) > strtotime($today)) { echo '<button class="button" disabled><span class="dashicons dashicons-arrow-right-alt2" style="margin-top:4px; color:#ccc;"></span></button>'; } 
                else { echo '<a href="?page='.$page_slug.$tab_param.$ct_tab_param.$uct_tab_param.$utm_filter_param.$utm_attr_param.$scope_param.'&start_date='.$next_start.'&end_date='.$next_end.'" class="button ois-next-link"><span class="dashicons dashicons-arrow-right-alt2" style="margin-top:4px;"></span></a>'; }
                echo '</div>';
                break;
                
            // --- 3. HEADER PRINCIPAL (Con Botón FS Mejorado) ---
            case 'header':
                $title      = isset($args['title']) ? $args['title'] : 'OIS Dashboard';
                $start_date = isset($args['start_date']) ? $args['start_date'] : '';
                $end_date   = isset($args['end_date']) ? $args['end_date'] : '';
                $preset     = isset($args['preset']) ? $args['preset'] : '';
                $page_slug  = isset($args['page_slug']) ? $args['page_slug'] : 'oiscl-analytics';
                $live_val   = isset($args['live_val']) ? $args['live_val'] : 0;
                $kpis       = isset($args['kpis']) ? $args['kpis'] : array();
                
                // ==========================================================
                // LECTURA DINÁMICA DE VERSIÓN (Automático desde lab.php)
                if ( ! function_exists( 'get_plugin_data' ) ) {
                    require_once ABSPATH . 'wp-admin/includes/plugin.php';
                }
                $dynamic_version = defined( 'OISCL_VERSION' ) ? OISCL_VERSION : '';
                if ( $dynamic_version === '' && defined( 'OISCL_PLUGIN_FILE' ) && file_exists( OISCL_PLUGIN_FILE ) ) {
                    $plugin_data     = get_plugin_data( OISCL_PLUGIN_FILE );
                    $dynamic_version = isset( $plugin_data['Version'] ) ? $plugin_data['Version'] : '';
                }
                // ==========================================================

                // 1. TÍTULO GLOBAL DE LA SUITE (estilos: .oiscl-admin-page-title en oiscl-admin.css)
                echo '<h1 class="oiscl-admin-page-title">';
                echo 'OIS Conversion Suite';

                // Etiqueta de versión (Ligeramente más grande para acompañar al título)
                if ( $dynamic_version !== '' ) {
                    echo '<span style="font-size: 13px; font-weight: 600; color: #64748b; background: #e2e8f0; padding: 3px 10px; border-radius: 12px;">v' . esc_html( $dynamic_version ) . '</span>';
                }
                
                // Enlace de actualización
                echo '<a href="' . admin_url('update-core.php') . '" style="font-size: 14px; text-decoration: none; color: #2271b1; font-weight: 500;">Check for updates</a>';
                echo '</h1>';
                // 2. Aquí caerán los carteles molestos de Rate/Buy Pro de otros plugins
                echo '<hr class="wp-header-end" style="clear:both; display:none;">';

                // INYECCIÓN GLOBAL: Barra de Inteligencia arriba de todo
                if (method_exists($this, 'render_intelligence_bar')) {
                    $this->render_intelligence_bar();
                }
                
                // VACUNA CSS: Prevenir el scroll horizontal en todo el plugin
                echo '<style>
                 
                    /* Forzar que el padding no sume ancho extra */
                    .oiscl-layout-root * { box-sizing: border-box; }
                    
                    /* Evitar que las URLs largas rompan las tablas */
                    .oiscl-layout-root table.wp-list-table code { 
                        word-wrap: break-word; 
                        word-break: break-all; 
                        white-space: normal; 
                    }
                    
                    /* Evitar que el contenedor principal se desborde */
                    .oiscl-layout-root { 
                        max-width: 100%; 
                        overflow-x: hidden; 
                    }
                </style>';

                // Fila Superior: Título, Reloj y Controles
                echo '<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:25px; flex-wrap:wrap; gap:20px;">';
                    echo '<div style="display:flex; align-items:center; gap:12px; flex:1; min-width:250px;">';
                        echo '<h2 class="ois-block-title ois-block-title--page-context">' . esc_html($title) . '</h2>';
                        if ($live_val > 0) {
                            echo '<div style="display:flex; align-items:center; gap:6px; background:#46b450; color:#fff; padding:4px 10px; border-radius:20px; font-size:11px; font-weight:bold;">';
                                echo '<span style="width:6px; height:6px; background:#fff; color:#fff; border-radius:50%; animation: pulse 2s infinite;"></span> LIVE';
                            echo '</div>';
                        }
                    echo '</div>';

                    echo '<div style="flex:1; display:flex; flex-direction:column; align-items:center; line-height:1.1; min-width:200px;">';
                        echo '<div id="ois-clock-time" style="font-size:28px; font-weight:800; color:#2271b1; letter-spacing:-0.5px;">00:00:00</div>';
                        echo '<div id="ois-clock-date" style="font-size:13px; font-weight:700; color:#64748b; text-transform:uppercase;">Cargando...</div>';
                    echo '</div>';

                    echo '<div style="display:flex; align-items:center; gap:10px; justify-content:flex-end; flex:1; min-width:300px;">';
                        echo '<button id="oiscl-btn-fullscreen" class="button" title="Full Screen" style="background:#2271b1; color:#fff; border:none; display:flex; align-items:center; gap:6px; padding:0 12px; height:30px; border-radius:3px; transition:0.3s; font-weight:600;">🗖 Full Screen</button>';
                        $this->render_ois_component('date_selector', array('page_slug'=>$page_slug, 'start_date'=>$start_date, 'end_date'=>$end_date, 'preset'=>$preset));
                    echo '</div>';
                echo '</div>';

               // Fila Inferior: Grilla de KPIs (Si existen)
                if (!empty($kpis)) {
                    // Restauramos el espaciado y tamaño de las tarjetas clásicas (minmax 220px)
                    echo '<div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap:15px; margin-bottom:20px;">';
                    foreach ($kpis as $kpi) {
                        $icon = isset($kpi['icon']) ? $kpi['icon'] : '';
                        $is_live_card = isset($kpi['is_live']) && $kpi['is_live'];
                        $color = esc_attr($kpi['color']);
                        
                        echo '<div class="ois-kpi-card '.($is_live_card ? 'kpi-live-now' : '').'" style="background:#fff; border:1px solid #ccd0d4; padding:20px; border-radius:4px; text-align:center; border-top:4px solid '.$color.'; box-sizing: border-box; display:flex; flex-direction:column; justify-content:center;">';
                            
                            // Título alineado al centro con icono (y el dot animado dinámico si es la tarjeta Live)
                            echo '<h4 style="margin:0 0 10px 0; color:#1d2327; font-size:14px; display:flex; justify-content:center; align-items:center; gap:6px;">';
                                if($is_live_card) {
                                    echo '<span style="width:8px; height:8px; background:'.$color.'; color:'.$color.'; border-radius:50%; display:inline-block; animation: pulse 2s infinite;"></span>';
                                }
                                echo $icon . ' ' . esc_html($kpi['label']);
                            echo '</h4>';
                            
                            // Valor principal en TAMAÑO GIGANTE (28px). LIVE NOW: ids usados por OIS UTM Manager para refrescar sesiones activas vía AJAX (sin duplicar peticiones en vacío).
                            $live_span_attrs = '';
                            if ( $is_live_card ) {
                                $live_span_attrs = ' id="oiscl-online-users" class="oiscl-online-count"';
                            }
                            echo '<span' . $live_span_attrs . ' style="font-size:28px; font-weight:bold; color:' . ( $is_live_card ? $color : '#1d2327' ) . ';">' . esc_html( $kpi['value'] ) . '</span>';
                            
                            // Delta 
                            if (isset($kpi['delta'])) echo $kpi['delta'];
                            
                            // Etiqueta inferior del LIVE NOW
                            if ($is_live_card) {
                                echo '<div style="font-size:11px; color:#999; margin-top:8px;">Active Sessions</div>';
                            }
                        echo '</div>';
                    }
                    echo '</div>';
                }
                
                // --- RESTAURAMOS EL MOTOR DE ANIMACIÓN (EFECTO GLOW/RADAR) ---
                echo '<style>
                    @keyframes pulse { 
                        0% { box-shadow: 0 0 0 0 currentColor; } 
                        70% { box-shadow: 0 0 0 6px transparent; } 
                        100% { box-shadow: 0 0 0 0 transparent; } 
                    }
                    #ois-clock-time { font-family: "Courier New", Courier, monospace; }
                </style>';
                
                // --- RESTAURAMOS EL SCRIPT DEL RELOJ QUE SE HABÍA BORRADO ---
                ?>
                <script>
                document.addEventListener('DOMContentLoaded', function() {
                    function updateOisClock() {
                        const now = new Date();
                        const optionsDate = { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' };
                        const dateStr = now.toLocaleDateString('en-US', optionsDate);
                        const timeStr = now.toLocaleTimeString('en-US', { hour12: false, hour: '2-digit', minute: '2-digit', second: '2-digit' });
                        const timeEl = document.getElementById('ois-clock-time');
                        const dateEl = document.getElementById('ois-clock-date');
                        if(timeEl) timeEl.textContent = timeStr;
                        if(dateEl) dateEl.textContent = dateStr;
                    }
                    setInterval(updateOisClock, 1000); updateOisClock();

                    const btnFs = document.getElementById('oiscl-btn-fullscreen');
                    if (btnFs) {
                        btnFs.addEventListener('click', function() {
                            document.body.classList.toggle('oiscl-presentation-mode');
                            if(document.body.classList.contains('oiscl-presentation-mode')) {
                                this.innerHTML = '✖ Full Screen'; this.style.background = '#ffffff'; this.style.color = '#2271b1'; this.style.border = '1px solid #2271b1';
                            } else {
                                this.innerHTML = '🗖 Full Screen'; this.style.background = '#2271b1'; this.style.color = '#ffffff'; this.style.border = 'none';
                            }
                        });
                    }
                });
                </script>
                <?php
                break;
                
                case 'server_health_bar':
                global $wpdb;
                $table_name = $wpdb->prefix . 'oiscl_block_metrics';
                $today = current_time('Y-m-d');

                // 1. UPTIME
                $uptime_text = 'Unknown';
                if (is_readable('/proc/uptime')) {
                    $uptime_str = @file_get_contents('/proc/uptime');
                    if ($uptime_str !== false) {
                        $uptime_sec = floatval(explode(' ', $uptime_str)[0]);
                        $uptime_text = floor($uptime_sec / 86400) . 'd ' . floor(($uptime_sec % 86400) / 3600) . 'h';
                    }
                }

                // 2. CPU LOAD (Carga de CPU del servidor)
                // Esto mide qué tan "ocupado" está el procesador del hosting.
                $load = function_exists('sys_getloadavg') ? sys_getloadavg() : [0,0,0];
                $load_val = number_format($load[0], 2);
                // Ahora marcará Crítico solo si la carga real supera 10.00
                $load_color = ($load[0] > 10.00) ? '#d63638' : (($load[0] > 6.00) ? '#f56e28' : '#46b450');
                $load_status = ($load[0] > 10.00) ? "Critical" : "Stable";

                // 3. BROKEN LINKS
                $broken_links_today = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table_name WHERE anchor_text = '[Error 404]' AND DATE(created_at) = %s", $today)) ?: 0;
                $broken_color = $broken_links_today > 0 ? '#d63638' : '#46b450';

                // 4. BACKUPS SIZE
                $backup_dir = WP_CONTENT_DIR . '/ois-backups/';
                $backup_size = 0;
                if(file_exists($backup_dir)) {
                    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($backup_dir, FilesystemIterator::SKIP_DOTS));
                    foreach($iterator as $file) { $backup_size += $file->getSize(); }
                }
                $backup_size_fmt = ($backup_size > 0) ? round($backup_size / 1048576, 2) . ' MB' : '0.00 MB';

                // 5. DISK SPACE (Lógica Dinámica de Semáforo)
                $bytes_free = @disk_free_space(ABSPATH);
                $bytes_total = @disk_total_space(ABSPATH);
                $bytes_used = $bytes_total - $bytes_free;
                
                $free_pct = ($bytes_total > 0) ? round(($bytes_free / $bytes_total) * 100, 1) : 100;
                $used_pct = ($bytes_total > 0) ? round(($bytes_used / $bytes_total) * 100, 1) : 0;
                
                // Color Dinámico: Verde (>40%), Naranja (21-40%), Rojo (<=20% libre)
                $disk_color = ($free_pct <= 20) ? '#d63638' : (($free_pct <= 40) ? '#f56e28' : '#46b450');
                
                $total_gb = ($bytes_total > 0) ? round($bytes_total / 1073741824, 1) . ' GB' : 'N/A';
                $used_gb = ($bytes_total > 0) ? round($bytes_used / 1073741824, 1) . ' GB' : 'N/A';

                echo '<div style="background:#fff; border:1px solid #ccd0d4; border-radius:4px; margin-bottom:25px; padding:15px 20px; display:flex; align-items:center; gap:20px; flex-wrap:wrap; width:100%; box-sizing:border-box;">';
                
                // KPIs Centrados
                $kpi_style = "flex:1; min-width:130px; border-right:1px solid #eee; text-align:center; padding:0 10px;";
                
                echo "<div style='{$kpi_style}'><h4 style='margin:0 0 5px 0; color:#64748b; font-size:11px; text-transform:uppercase;'>⏱️ Server Uptime</h4><span style='font-size:22px; font-weight:bold; color:#2271b1;'>{$uptime_text}</span></div>";
                echo "<div style='{$kpi_style}'><h4 style='margin:0 0 5px 0; color:#64748b; font-size:11px; text-transform:uppercase;'>🚀 CPU Load</h4><span style='font-size:22px; font-weight:bold; color:{$load_color};'>{$load_val} <span style='font-size:12px; font-weight:normal;'>({$load_status})</span></span></div>";
                echo "<div style='{$kpi_style}'><h4 style='margin:0 0 5px 0; color:#64748b; font-size:11px; text-transform:uppercase;'>🚨 Broken Links</h4><span style='font-size:22px; font-weight:bold; color:{$broken_color};'>{$broken_links_today}</span></div>";
                echo "<div style='{$kpi_style}'><h4 style='margin:0 0 5px 0; color:#64748b; font-size:11px; text-transform:uppercase;'>📦 Backups Size</h4><span style='font-size:22px; font-weight:bold; color:#722ed1;'>{$backup_size_fmt}</span></div>";
                
                // Disk Space con Barra Dinámica
                echo '<div style="flex:2; min-width:260px; padding-left:10px;">';
                    echo '<div style="display:flex; justify-content:space-between; align-items:flex-end; margin-bottom:5px;">';
                        echo '<h4 style="margin:0; color:#64748b; font-size:11px; text-transform:uppercase;">💾 Disk Space</h4>';
                        echo '<span style="font-size:11px; color:#64748b; font-weight:bold;">'.$used_gb.' / '.$total_gb.'</span>';
                    echo '</div>';
                    echo '<div style="background:#f0f0f1; height:8px; border-radius:4px; margin:0 0 5px 0; overflow:hidden;">';
                        echo '<div style="background:'.$disk_color.'; width:'.$used_pct.'%; height:100%; transition: width 0.5s;"></div>';
                    echo '</div>';
                    echo '<div style="display:flex; justify-content:space-between; align-items:center;">';
                        echo '<small style="color:'.$disk_color.'; font-weight:bold; font-size:11px;">'.$free_pct.'% Free</small>';
                        echo '<a href="?page=oiscl-settings&tab=backup" class="button button-small" style="font-size:10px; padding:0 8px; line-height:2; height:auto; background:#fff; border:1px solid #ccd0d4;">🧹 Clean Backups</a>';
                    echo '</div>';
                echo '</div>';
                
                echo '</div>';
                break;

            // --- 4. SISTEMA DE GRILLAS (NUEVO) ---
            case 'grid_start':
                // Auto-fit asegura que las tarjetas de KPIs nunca se aplasten
                echo "<div style='display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 15px; margin-bottom: 20px;'>";
                break;

            case 'grid_end':
                echo "</div>";
                break;

            // --- 5. TARJETAS DE KPIs ---
            case 'kpi_card':
                $label = $args['label'];
                $value = $args['value'];
                $delta = isset($args['delta']) ? $args['delta'] : '';
                $color = isset($args['color']) ? $args['color'] : '#1a73e8';
                $is_live = isset($args['is_live']) ? $args['is_live'] : false;
                $icon = isset($args['icon']) ? $args['icon'] : '';
                ?>
                <div class="ois-kpi-card <?php echo $is_live ? 'kpi-live-now' : ''; ?>" style="background:#fff; border:1px solid #ccd0d4; padding:20px; border-radius:4px; text-align:center; border-top:4px solid <?php echo $color; ?>; box-sizing: border-box; display:flex; flex-direction:column; justify-content:center;">
                    <h4 style="margin:0 0 10px 0; color:#1d2327; font-size:14px;"><?php echo $icon . ' ' . esc_html($label); ?></h4>
                    <span style="font-size:28px; font-weight:bold; color:<?php echo $is_live ? $color : '#1d2327'; ?>;"><?php echo $value; ?></span>
                    <?php echo $delta; ?>
                    <?php if($is_live): ?>
                        <div style="font-size:11px; color:#999; margin-top:8px;">Active Sessions</div>
                    <?php endif; ?>
                </div>
                <?php
                break;
                // --- 6. TABLA DE DATOS MAESTRA ---
            case 'data_table':
                $title    = isset($args['title']) ? $args['title'] : 'Datos';
                $icon     = isset($args['icon']) ? $args['icon'] : '';
                $headers  = isset($args['headers']) ? $args['headers'] : array();
                $rows     = isset($args['rows']) ? $args['rows'] : array(); 
                $id       = isset($args['id']) ? $args['id'] : 'tbl-' . uniqid();
                $page_sz  = isset($args['rows_per_page']) ? $args['rows_per_page'] : 6;
                $link     = isset($args['link']) ? $args['link'] : '';
                $totals   = isset($args['totals']) ? $args['totals'] : null;

                echo '<div style="background:#fff; border:1px solid #ccd0d4; padding:20px; border-radius:4px; display:flex; flex-direction:column; height:100%; box-sizing:border-box;">';
                    
                    $toolbar = isset($args['toolbar']) ? $args['toolbar'] : '';
                    
                    // Cabecera: Título, Toolbar inyectado y Botones de Exportación
                    echo '<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px; min-height:30px; flex-wrap:wrap; gap:10px;">';
                        echo '<div style="display:flex; align-items:center; gap:15px; flex-wrap:wrap;">';
                            echo '<h3 class="ois-block-title">' . $icon . ' ' . esc_html($title) . '</h3>';
                            if (!empty($toolbar)) { echo $toolbar; }
                        echo '</div>';
                        if (count($rows) > 0) {
                            $this->render_ois_component(
                                'export_menu',
                                array(
                                    'id'               => 'exp-dt-' . sanitize_html_class( $id ),
                                    'table_csv_target' => $id,
                                    'table_pdf_target' => $id,
                                )
                            );
                        }
                    echo '</div>';

                    // Cuerpo de la tabla
                    echo '<div style="flex-grow:1; overflow-x:auto;">';
                        echo '<table class="ois-table-dashboard" id="'.esc_attr($id).'" data-page-size="'.esc_attr($page_sz).'" data-current-page="1">';
                            echo '<thead><tr>';
                            foreach ($headers as $th) {
                                $align = isset($th['align']) ? $th['align'] : 'left';
                                $width = isset($th['width']) ? 'width:'.esc_attr($th['width']).';' : '';
                                echo '<th style="text-align:'.esc_attr($align).'; '.$width.'">' . esc_html($th['label']) . '</th>';
                            }
                            echo '</tr></thead><tbody>';
                            
                            if (!empty($rows)) {
                                foreach ($rows as $idx => $row) {
                                    $style = ($idx >= $page_sz) ? 'display:none;' : '';
                                    echo '<tr class="ois-pag-row-'.esc_attr($id).'" style="' . $style . '">';
                                    foreach ($row as $cell) {
                                        $align = isset($cell['align']) ? $cell['align'] : 'left';
                                        $color = isset($cell['color']) ? 'color:'.esc_attr($cell['color']).';' : '';
                                        $bold  = isset($cell['bold']) && $cell['bold'] ? 'font-weight:bold;' : '';
                                        echo '<td style="text-align:'.esc_attr($align).'; '.$color.' '.$bold.'">' . wp_kses_post($cell['value']) . '</td>';
                                    }
                                    echo '</tr>';
                                }
                            } else {
                                echo '<tr><td colspan="'.count($headers).'" style="text-align:center; padding:20px; color:#999;">No data yet.</td></tr>';
                            }
                            echo '</tbody>';

                            // Fila de Totales (Aplica a CRO Tab)
                            if (!empty($totals)) {
                                echo '<tfoot><tr class="ois-page-totals-row" style="background:#f8fafc; font-weight:bold; border-top:2px solid #e2e8f0;">';
                                foreach ($totals as $idx => $t_val) {
                                    $align = ($idx == 0) ? 'left' : 'right';
                                    echo '<td style="text-align:'.$align.'; padding:10px 0;">'.wp_kses_post($t_val).'</td>';
                                }
                                echo '</tr></tfoot>';
                            }
                        echo '</table>';
                        
                        // Footer: Paginación y Selector unidos
                        // Solo se renderizan si el total de filas supera el mínimo absoluto (6)
                        if (count($rows) > 6) { 
                            echo "<div style='display:flex; justify-content:flex-end; align-items:center; gap:15px; margin-top:15px; padding-top:10px; border-top:1px solid #eee;'>";
                                
                                // Selector de filas
                                echo "<div style='font-size:11px; color:#666;'>Show: <select class='ois-row-selector' data-target='".esc_attr($id)."' style='font-size:11px; text-align: center; height:24px; padding:0 20px 0 5px; min-height:24px;'>";
                                foreach([6, 20, 50, 100] as $opt) {
                                    $sel = ($opt == $page_sz) ? 'selected' : '';
                                    echo "<option value='{$opt}' {$sel}>{$opt}</option>";
                                }
                                echo "</select></div>";
                                
                                // Paginador
                                $pag_display = (count($rows) > $page_sz) ? 'display:flex;' : 'display:none;';
                                echo "<div class='ois-pagination' id='pag-wrap-".esc_attr($id)."' style='{$pag_display} align-items:center; gap:5px;'>";
                                echo "<button class='pag-prev button button-small' data-target='".esc_attr($id)."' disabled>&lt;</button> ";
                                echo "<span class='pag-num' id='pag-cur-".esc_attr($id)."' style='font-size:11px; font-weight:bold; color:#1a73e8; padding:0 5px;'>1</span> ";
                                echo "<button class='pag-next button button-small' data-target='".esc_attr($id)."'>&gt;</button>";
                                echo "</div>";

                                

                            echo "</div>";
                        }
                    echo '</div>';

                    // Link inferior opcional
                    if (!empty($link)) {
                        echo '<div style="text-align:right; margin-top:15px; border-top:1px solid #eee; padding-top:10px;"><a href="'.esc_url($link).'" style="text-decoration:none; font-weight:bold; color:#1a73e8; font-size:11px;">VER REPORTE ➔</a></div>';
                    }
                echo '</div>';
                break;

            /**
             * Menú Export (CSV / PNG / PDF) para bloques reutilizables.
             * PDF (chart): impresión con imagen del canvas (misma data visual que PNG).
             */
            case 'export_menu':
                $eid        = isset( $args['id'] ) ? sanitize_html_class( $args['id'] ) : 'ois-export-' . uniqid( 'm' );
                $csv_url    = isset( $args['csv_url'] ) ? esc_url( $args['csv_url'] ) : '';
                $csv_full   = isset( $args['csv_full_census_url'] ) ? esc_url( $args['csv_full_census_url'] ) : '';
                $canvas_id  = isset( $args['png_canvas_id'] ) ? sanitize_key( $args['png_canvas_id'] ) : '';
                $png_name   = isset( $args['png_filename'] ) ? sanitize_file_name( $args['png_filename'] ) : 'chart.png';
                $pdf_title  = isset( $args['pdf_chart_title'] ) ? $args['pdf_chart_title'] : __( 'Chart', 'ois-conversion-suite' );
                $show_pdf   = ! empty( $args['show_pdf_chart'] ) && $canvas_id !== '';
                $tbl_csv    = isset( $args['table_csv_target'] ) ? sanitize_key( $args['table_csv_target'] ) : '';
                $tbl_pdf    = isset( $args['table_pdf_target'] ) ? sanitize_key( $args['table_pdf_target'] ) : '';
                $wrap_pdf   = isset( $args['wrap_pdf_id'] ) ? sanitize_html_class( $args['wrap_pdf_id'] ) : '';
                $wrap_fn    = isset( $args['wrap_pdf_filename'] ) ? sanitize_file_name( $args['wrap_pdf_filename'] ) : '';
                $has_csv    = $csv_url !== '';
                $has_csv_f  = $csv_full !== '';
                $has_png    = $canvas_id !== '';
                $has_tbl_c  = $tbl_csv !== '';
                $has_tbl_p  = $tbl_pdf !== '';
                $has_wrap_p = $wrap_pdf !== '' && $wrap_fn !== '';
                if ( ! $has_csv && ! $has_csv_f && ! $has_png && ! $show_pdf && ! $has_tbl_c && ! $has_tbl_p && ! $has_wrap_p ) {
                    break;
                }
                $summary = esc_html__( 'Export', 'ois-conversion-suite' );
                echo '<details class="ois-export-menu" id="' . esc_attr( $eid ) . '">';
                echo '<summary class="button button-small ois-export-menu-summary" type="button">' . $summary . '</summary>';
                echo '<div class="ois-export-menu-panel" role="menu">';
                if ( $has_csv ) {
                    echo '<a class="ois-export-menu-item" role="menuitem" href="' . $csv_url . '">' . esc_html__( 'Download CSV (data)', 'ois-conversion-suite' ) . '</a>';
                }
                if ( $has_csv_f ) {
                    echo '<a class="ois-export-menu-item" role="menuitem" href="' . $csv_full . '">' . esc_html__( 'Download CSV (full census)', 'ois-conversion-suite' ) . '</a>';
                }
                if ( $has_png ) {
                    echo '<button type="button" class="button button-small ois-export-menu-item ois-export-menu-png" role="menuitem" data-canvas="' . esc_attr( $canvas_id ) . '" data-filename="' . esc_attr( $png_name ) . '">' . esc_html__( 'Download PNG (chart)', 'ois-conversion-suite' ) . '</button>';
                }
                if ( $show_pdf ) {
                    echo '<button type="button" class="button button-small ois-export-menu-item ois-export-menu-pdf-chart" role="menuitem" data-canvas="' . esc_attr( $canvas_id ) . '" data-title="' . esc_attr( $pdf_title ) . '">' . esc_html__( 'Download PDF (chart)', 'ois-conversion-suite' ) . '</button>';
                }
                if ( $has_tbl_c ) {
                    echo '<button type="button" class="button button-small ois-export-menu-item ois-export-csv" role="menuitem" data-target="' . esc_attr( $tbl_csv ) . '">' . esc_html__( 'Export table as CSV', 'ois-conversion-suite' ) . '</button>';
                }
                if ( $has_tbl_p ) {
                    echo '<button type="button" class="button button-small ois-export-menu-item ois-export-pdf" role="menuitem" data-target="' . esc_attr( $tbl_pdf ) . '">' . esc_html__( 'Export table as PDF', 'ois-conversion-suite' ) . '</button>';
                }
                if ( $has_wrap_p ) {
                    echo '<button type="button" class="button button-small ois-export-menu-item oiscl-export-pdf" role="menuitem" data-target="' . esc_attr( $wrap_pdf ) . '" data-filename="' . esc_attr( $wrap_fn ) . '">' . esc_html__( 'Download PDF (report)', 'ois-conversion-suite' ) . '</button>';
                }
                echo '</div></details>';
                break;

            // --- 7. SISTEMA DE COLUMNAS FLEXIBLES (El nuevo Grid) ---
            case 'row_start':
                // pattern define las proporciones (ej: '1-1' es 50/50, '2-1' es 66/33)
                $pattern = isset($args['pattern']) ? $args['pattern'] : '1-1'; 
                $grid_map = array(
                    '1-1' => '1fr 1fr', 
                    '2-1' => '2fr 1fr', 
                    '1-2' => '1fr 2fr',
                    '1-1-1' => '1fr 1fr 1fr'
                );
                $style = isset($grid_map[$pattern]) ? $grid_map[$pattern] : '1fr 1fr';
                // Usamos grid con un fallback responsivo para móviles
                echo "<div class='ois-flexible-row' style='display: grid; grid-template-columns: $style; gap: 20px; margin-bottom: 25px;'>";
                echo "<style>@media (max-width: 1024px) { .ois-flexible-row { grid-template-columns: 1fr !important; } }</style>";
                break;

            case 'row_end':
                echo "</div>";
                break;
            
            // --- 8. Table Style ---
            case 'advanced_table':
                $id = isset($args['id']) ? $args['id'] : 'tbl_' . uniqid();
                $title = isset($args['title']) ? $args['title'] : '';
                $subtitle = isset($args['subtitle']) ? $args['subtitle'] : '';
                $icon = isset($args['icon']) ? $args['icon'] : '';
                $headers = isset($args['headers']) ? $args['headers'] : array();
                $rows = isset($args['rows']) ? $args['rows'] : array();
                $toolbar = isset($args['toolbar']) ? $args['toolbar'] : '';
                $csv = isset($args['csv']) ? $args['csv'] : '';
                $pdf = isset($args['pdf']) ? $args['pdf'] : 'Export';
                $csv_full_census = isset( $args['csv_full_census_url'] ) ? $args['csv_full_census_url'] : '';
                $table_csv_target = isset( $args['table_csv_target'] ) ? sanitize_key( $args['table_csv_target'] ) : '';

                echo '<div id="wrap-' . esc_attr($id) . '" class="ois-box" style="background:#fff; border:1px solid #ccd0d4; padding:20px; border-radius:4px; margin-bottom:25px; width:100%; box-sizing:border-box;">';
                
                // Cabecera: misma fila título | controles (toolbar + export); subtítulo debajo a ancho completo.
                echo '<div style="margin-bottom:15px; border-bottom:1px solid #eee; padding-bottom:15px;">';
                echo '<div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">';
                    echo '<div style="flex:1; min-width:0;"><h3 class="ois-block-title" style="margin:0;">' . $icon . ' ' . esc_html($title) . '</h3></div>';
                    echo '<div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap; justify-content:flex-end; flex-shrink:0;">';
                        if($toolbar) echo $toolbar;
                        if($csv || $pdf || $csv_full_census || $table_csv_target) {
                            $this->render_ois_component('export_menu', array(
                                'id' => 'exp-at-' . sanitize_html_class($id),
                                'csv_url' => $csv ? $csv : '',
                                'csv_full_census_url' => $csv_full_census ? $csv_full_census : '',
                                'wrap_pdf_id' => $pdf ? 'wrap-' . $id : '',
                                'wrap_pdf_filename' => $pdf ? $pdf : '',
                                'table_csv_target' => $table_csv_target,
                            ));
                        }
                    echo '</div>';
                echo '</div>';
                if ( $subtitle ) {
                    echo '<p style="margin:8px 0 0 0; font-size:12px; color:#666;">' . esc_html($subtitle) . '</p>';
                }
                echo '</div>';

                // Tabla Nativa Premium
                echo '<div style="overflow-x:auto;">';
                echo '<table class="wp-list-table widefat fixed striped ois-table-hover" id="' . esc_attr($id) . '" style="border:none; width:100%; border-collapse:collapse;">';
                echo '<thead><tr>';
                foreach ($headers as $index => $h) {
                    $w = isset($h['width']) ? 'width:'.$h['width'].';' : '';
                    $a = isset($h['align']) ? 'text-align:'.$h['align'].';' : '';
                    $t = isset($h['type']) ? $h['type'] : 'string';
                    $tip = isset( $h['tooltip'] ) ? trim( (string) $h['tooltip'] ) : '';
                    $title_attr = '' !== $tip ? ' title="' . esc_attr( $tip ) . '"' : '';
                    $tip_style  = '' !== $tip ? 'border-bottom:1px dotted #c3c4c7;' : '';
                    echo '<th class="j-sortable" data-type="' . esc_attr($t) . '" data-col="' . $index . '" style="' . $w . $a . $tip_style . '"' . $title_attr . '>' . esc_html($h['label']) . ' <span class="sort-icon"></span></th>';
                }
                echo '</tr></thead><tbody>';

                if (count($rows) > 0) {
                    foreach ($rows as $r) {
                        $cat = isset($r['category']) ? "data-category='".esc_attr($r['category'])."'" : "";
                        $r_class = isset($r['class']) ? esc_attr($r['class']) : 'ois-row';
                        
                        echo "<tr class='{$r_class}' {$cat}>";
                        foreach ($headers as $index => $h) {
                            $a = isset($h['align']) ? 'text-align:'.$h['align'].';' : '';
                            $val = isset($r['cols'][$index]) ? $r['cols'][$index] : '';
                            echo "<td style='{$a}'>{$val}</td>";
                        }
                        echo "</tr>";
                        
                        // MAGIA: Soporte nativo para Acordeones
                        if (!empty($r['details_html'])) {
                            $cols_cnt = count($headers);
                            echo "<tr class='ois-row-details' style='display:none;'><td colspan='{$cols_cnt}' style='padding:0;'>{$r['details_html']}</td></tr>";
                        }
                    }
                } else {
                    echo "<tr><td colspan='" . count($headers) . "' style='text-align:center; padding:20px; color:#999;'>No data found.</td></tr>";
                }
                echo '</tbody></table></div>';

                // Paginador
                echo '<div class="ois-pag-controls" style="display:flex; justify-content:flex-end; align-items:center; gap:20px; margin-top:20px; padding-top:15px; border-top:1px solid #eee;">';
                    echo '<div style="display:flex; align-items:center; gap:5px;"><span style="font-size:12px; color:#666; font-weight:bold;">Listar:</span><select class="oiscl-row-selector" data-target="' . esc_attr($id) . '" style="border-radius:4px; font-size:12px; padding:2px 24px 2px 8px; min-height:28px;"><option value="20" selected>20</option><option value="50">50</option><option value="100">100</option></select></div>';
                    echo '<div id="pag-wrap-' . esc_attr($id) . '" style="display:flex; gap:5px;"></div>';
                echo '</div></div>';
                break;
        }
    }

}
