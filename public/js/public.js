/* global jQuery, tumData */
/**
 * Table Upload Manager — Public JavaScript
 * Namespaced as TUM to avoid conflicts with other plugins/themes.
 */
(function ($) {
    'use strict';

    if (typeof tumData === 'undefined') return; // shortcode not on page

    /* ══════════════════════════════════════════════════════════
       CORE STATE
    ══════════════════════════════════════════════════════════ */
    var TUM = {
        tables:      {},   // { id: payload }
        activeId:    null,
        sortState:   {},   // { tableId: { col, dir } }
        filterState: {},   // { tableId: { colIdx: value, ... } }
        pageState:   {},   // { tableId: currentPage }
        searchState: {},   // { tableId: searchTerm }
        pendingUpload: {}, // { tableId: { headers, rows, preview_rows } }
        permSearchTimer: null,

        // ── Shortcuts ──────────────────────────────────────────
        cfg: tumData,
        str: tumData.strings,
        schemes: tumData.colorSchemes,

        // ── AJAX ──────────────────────────────────────────────
        ajax: function (action, data, opts) {
            opts = opts || {};
            return $.ajax({
                url:      TUM.cfg.ajaxUrl,
                method:   opts.method || 'POST',
                data:     $.extend({ action: action, nonce: TUM.cfg.nonce }, data),
                dataType: 'json'
            });
        },

        ajaxUpload: function (action, formData) {
            formData.append('action', action);
            formData.append('nonce',  TUM.cfg.nonce);
            return $.ajax({
                url:         TUM.cfg.ajaxUrl,
                method:      'POST',
                data:        formData,
                processData: false,
                contentType: false,
                dataType:    'json',
                xhr: function () {
                    var xhr = new window.XMLHttpRequest();
                    xhr.upload.addEventListener('progress', function (e) {
                        if (e.lengthComputable) {
                            var pct = Math.round((e.loaded / e.total) * 100);
                            TUM.setProgress(TUM.activeId, pct);
                        }
                    });
                    return xhr;
                }
            });
        }
    };

    /* ══════════════════════════════════════════════════════════
       INITIALISE
    ══════════════════════════════════════════════════════════ */
    TUM.init = function () {
        TUM.loadAllTables();
        TUM.bindGlobal();
    };

    TUM.loadAllTables = function () {
        TUM.ajax('tum_get_tables', {}).done(function (resp) {
            $('#tum-initial-loader').fadeOut(200);
            if (!resp.success || !resp.data.length) {
                if (TUM.cfg.isApproved) {
                    $('#tum-tab-nav').show();
                }
                return;
            }
            $('#tum-tab-nav').show();
            $.each(resp.data, function (_, meta) {
                TUM.addTab(meta);
            });
            // Activate first tab
            var firstId = resp.data[0].id;
            TUM.switchTab(firstId);
        }).fail(function () {
            $('#tum-initial-loader').html('<p>' + TUM.str.error + '</p>');
        });
    };

    /* ══════════════════════════════════════════════════════════
       TAB MANAGEMENT
    ══════════════════════════════════════════════════════════ */
    TUM.addTab = function (meta) {
        var $tab = $('<button class="tum-tab-btn" role="tab"></button>')
            .attr('data-table-id', meta.id)
            .attr('aria-selected', 'false')
            .html('<span class="tum-tab-dot"></span><span class="tum-tab-label">' + $('<div>').text(meta.title).html() + '</span>');
        $('#tum-tabs-track').append($tab);

        // Create empty panel placeholder
        var $panel = $('<div class="tum-table-panel" role="tabpanel" style="display:none;"></div>')
            .attr('id', 'tum-panel-' + meta.id)
            .attr('data-table-id', meta.id);
        $('#tum-panels-container').append($panel);
    };

    TUM.switchTab = function (tableId) {
        tableId = parseInt(tableId, 10);
        if (TUM.activeId === tableId) return;

        // UI
        $('.tum-tab-btn').removeClass('active').attr('aria-selected', 'false');
        $('.tum-tab-btn[data-table-id="' + tableId + '"]').addClass('active').attr('aria-selected', 'true');
        $('.tum-table-panel').hide().attr('aria-hidden', 'true');
        $('#tum-panel-' + tableId).show().removeAttr('aria-hidden').addClass('active');

        TUM.activeId = tableId;

        // Load data if not yet loaded
        if (!TUM.tables[tableId]) {
            TUM.loadTable(tableId);
        }
    };

    TUM.loadTable = function (tableId) {
        var $panel = $('#tum-panel-' + tableId);
        $panel.html('<div class="tum-loading"><div class="tum-spinner"></div><span>Loading…</span></div>');

        TUM.ajax('tum_get_table', { table_id: tableId }).done(function (resp) {
            if (!resp.success) {
                $panel.html('<p class="tum-notice tum-notice-error">' + TUM.str.error + '</p>');
                return;
            }
            TUM.tables[tableId] = resp.data;
            TUM.renderPanel(tableId);
        }).fail(function () {
            $panel.html('<p class="tum-notice tum-notice-error">' + TUM.str.error + '</p>');
        });
    };

    /* ══════════════════════════════════════════════════════════
       PANEL RENDERING
    ══════════════════════════════════════════════════════════ */
    TUM.renderPanel = function (tableId) {
        var data    = TUM.tables[tableId];
        var $panel  = $('#tum-panel-' + tableId);
        var tmpl    = document.getElementById('tum-panel-template');
        var $clone  = $(tmpl.content.cloneNode(true));

        // Title / meta
        $clone.find('.tum-panel-title').text(data.title);
        $clone.find('.tum-panel-meta').text(
            TUM.formatMeta(data)
        );

        $panel.empty().append($clone);

        // Apply CSS vars
        TUM.applyCSSVars(tableId, $panel);

        // Edit toolbar
        if (data.can_edit) {
            $panel.find('.tum-edit-toolbar').show();
            $panel.find('.tum-dropzone-text').text(TUM.str.uploadDrop);
            $panel.find('.tum-dropzone-hint').text(TUM.str.uploadHint);

            if (data.is_owner) {
                $panel.find('.tum-manage-perms-btn').show();
                $panel.find('.tum-delete-table-btn').show();
            }
        }

        // Show table or no-data state
        if (data.has_data && data.rows && data.rows.length) {
            $panel.find('.tum-table-area').show();
            TUM.initSort(tableId);
            TUM.initFilterState(tableId);
            TUM.pageState[tableId]   = 1;
            TUM.searchState[tableId] = '';
            TUM.renderTable(tableId);
        } else {
            $panel.find('.tum-no-data').show();
            // Auto-open the upload zone when there is no data yet
            if (data.can_edit) {
                $panel.find('.tum-upload-section').show();
            }
        }

        // Formatting sidebar preload
        TUM.buildFormattingSidebar(tableId);

        // Bind panel events
        TUM.bindPanelEvents($panel, tableId);
    };

    TUM.formatMeta = function (data) {
        var parts = [];
        if (data.row_count) parts.push(data.row_count + ' ' + TUM.str.rowCount);
        if (data.column_count) parts.push(data.column_count + ' ' + TUM.str.colCount);
        if (data.updated_at) parts.push('Updated ' + data.updated_at);
        return parts.join(' · ');
    };

    /* ══════════════════════════════════════════════════════════
       TABLE RENDERING (virtualized via pagination)
    ══════════════════════════════════════════════════════════ */
    TUM.renderTable = function (tableId) {
        var data    = TUM.tables[tableId];
        var fmt     = data.formatting || {};
        var layout  = fmt.layout || {};
        var filters = fmt.filters || {};
        var vis     = fmt.columnVisibility || {};
        var headers = data.headers || [];
        var rows    = data.rows    || [];

        var isPaged     = layout.pagination !== false;
        var rowsPerPage = layout.rowsPerPage || 25;
        var isStriped   = layout.stripedRows !== false;
        var isHover     = layout.hoverEffect !== false;
        var borderClass = 'border-' + (layout.borderStyle || 'full');
        var isCompact   = layout.compactMode || false;

        var $panel = $('#tum-panel-' + tableId);
        var $table = $panel.find('.tum-data-table');
        var $thead = $table.find('thead');
        var $tbody = $table.find('tbody');

        // Table classes
        $table.removeClass('striped hover-effect compact border-full border-horizontal border-none');
        if (isStriped) $table.addClass('striped');
        if (isHover)   $table.addClass('hover-effect');
        if (isCompact) $table.addClass('compact');
        $table.addClass(borderClass);

        // ── Header row ────────────────────────────────────────
        $thead.empty();
        var $hRow = $('<tr></tr>');
        $.each(headers, function (i, h) {
            var hidden  = vis[i] === false;
            var sortable = (fmt.sortable || []).indexOf(i) > -1;
            var $th = $('<th></th>')
                .attr('data-col', i)
                .addClass(hidden ? 'tum-col-hidden' : '')
                .addClass(sortable ? 'tum-sortable' : '')
                .text(h);

            if (sortable) {
                var sort = TUM.sortState[tableId] || {};
                var icon = sort.col === i ? (sort.dir === 'asc' ? '▲' : '▼') : '⇅';
                $th.append('<span class="tum-sort-icon">' + icon + '</span>');
                if (sort.col === i) $th.addClass('sort-' + sort.dir);
            }
            $hRow.append($th);
        });
        $thead.append($hRow);

        // ── Filter row ────────────────────────────────────────
        if (filters.enabled && filters.columns) {
            var $fRow = $('<tr class="tum-filter-row"></tr>');
            $.each(headers, function (i, h) {
                var hidden  = vis[i] === false;
                var colFmt  = filters.columns[i] || {};
                var $fTh    = $('<th></th>').addClass(hidden ? 'tum-col-hidden' : '');

                if (!hidden && colFmt.enabled && colFmt.type && colFmt.type !== 'none') {
                    var $input;
                    if (colFmt.type === 'select') {
                        var vals = TUM.uniqueColValues(rows, i);
                        $input = $('<select class="tum-filter-select"></select>');
                        $input.append('<option value="">— All —</option>');
                        $.each(vals, function (_, v) {
                            $input.append($('<option></option>').val(v).text(v));
                        });
                    } else {
                        $input = $('<input type="text" class="tum-filter-input">')
                            .attr('placeholder', colFmt.placeholder || TUM.str.filterPlaceholder);
                    }
                    $input.attr('data-col', i).attr('data-type', colFmt.type);
                    $fTh.append($input);
                }
                $fRow.append($fTh);
            });
            $thead.append($fRow);
        }

        // ── Filter / search application ───────────────────────
        var colFilters = TUM.filterState[tableId] || {};
        var globalSearch = (TUM.searchState[tableId] || '').toLowerCase();

        var filtered = rows.filter(function (row) {
            // Global search
            if (globalSearch) {
                var rowText = row.join(' ').toLowerCase();
                if (rowText.indexOf(globalSearch) === -1) return false;
            }
            // Per-column filters
            for (var ci in colFilters) {
                if (!colFilters[ci]) continue;
                var cellVal = (row[ci] || '').toLowerCase();
                var fVal    = colFilters[ci].toLowerCase();
                if (cellVal.indexOf(fVal) === -1) return false;
            }
            return true;
        });

        // ── Sort ─────────────────────────────────────────────
        var sort = TUM.sortState[tableId];
        if (sort && sort.col !== null) {
            var col = sort.col, dir = sort.dir;
            filtered = filtered.slice().sort(function (a, b) {
                var av = a[col] || '', bv = b[col] || '';
                var an = parseFloat(av), bn = parseFloat(bv);
                if (!isNaN(an) && !isNaN(bn)) {
                    return dir === 'asc' ? an - bn : bn - an;
                }
                av = av.toLowerCase(); bv = bv.toLowerCase();
                if (av < bv) return dir === 'asc' ? -1 : 1;
                if (av > bv) return dir === 'asc' ? 1 : -1;
                return 0;
            });
        }

        // ── Pagination ────────────────────────────────────────
        var totalRows = filtered.length;
        var page      = TUM.pageState[tableId] || 1;
        var pageRows;

        if (isPaged) {
            var totalPages = Math.max(1, Math.ceil(totalRows / rowsPerPage));
            page = Math.min(page, totalPages);
            TUM.pageState[tableId] = page;
            var start = (page - 1) * rowsPerPage;
            pageRows  = filtered.slice(start, start + rowsPerPage);
        } else {
            pageRows = filtered;
        }

        // ── Body ─────────────────────────────────────────────
        $tbody.empty();
        if (!pageRows.length) {
            var colCount = headers.length;
            $tbody.append('<tr class="tum-no-results-row"><td colspan="' + colCount + '">' + TUM.str.noResults + '</td></tr>');
        } else {
            $.each(pageRows, function (_, row) {
                var $tr = $('<tr></tr>');
                $.each(headers, function (ci) {
                    var hidden = vis[ci] === false;
                    $('<td></td>')
                        .addClass(hidden ? 'tum-col-hidden' : '')
                        .text(row[ci] !== undefined ? row[ci] : '')
                        .appendTo($tr);
                });
                $tbody.append($tr);
            });
        }

        // ── Controls bar ──────────────────────────────────────
        var $ctrl = $panel.find('.tum-table-controls');
        $ctrl.show();

        var infoText = totalRows + ' ' + TUM.str.rowCount;
        if (totalRows !== rows.length) {
            infoText = totalRows + ' of ' + rows.length + ' ' + TUM.str.rowCount;
        }
        $panel.find('.tum-table-info').text(infoText);

        // ── Pagination controls ───────────────────────────────
        var $pag = $panel.find('.tum-pagination');
        $pag.empty();
        if (isPaged && totalRows > rowsPerPage) {
            var totalPages2 = Math.ceil(totalRows / rowsPerPage);
            TUM.renderPagination($pag, page, totalPages2, tableId);
            $pag.show();
        } else {
            $pag.hide();
        }

        $panel.find('.tum-table-area').show();
    };

    TUM.renderPagination = function ($pag, page, totalPages, tableId) {
        var $info  = $('<span class="tum-pagination-info"></span>').text(TUM.str.page + ' ' + page + ' ' + TUM.str.of + ' ' + totalPages);
        var $btns  = $('<div class="tum-pagination-btns"></div>');

        var $prev = $('<button class="tum-page-btn">' + TUM.str.prev + '</button>').prop('disabled', page <= 1);
        $prev.on('click', function () {
            TUM.pageState[tableId] = page - 1;
            TUM.renderTable(tableId);
        });

        // Page number buttons (smart windowed)
        var window_size = 5;
        var start = Math.max(1, page - Math.floor(window_size / 2));
        var end   = Math.min(totalPages, start + window_size - 1);
        if (end - start < window_size - 1) start = Math.max(1, end - window_size + 1);

        if (start > 1) $btns.append('<button class="tum-page-btn" data-page="1">1</button>');
        if (start > 2) $btns.append('<span style="padding:0 4px;color:#94a3b8">…</span>');

        for (var p = start; p <= end; p++) {
            var $pb = $('<button class="tum-page-btn"></button>').text(p).attr('data-page', p);
            if (p === page) $pb.addClass('active');
            $btns.append($pb);
        }

        if (end < totalPages - 1) $btns.append('<span style="padding:0 4px;color:#94a3b8">…</span>');
        if (end < totalPages)     $btns.append('<button class="tum-page-btn" data-page="' + totalPages + '">' + totalPages + '</button>');

        var $next = $('<button class="tum-page-btn">' + TUM.str.next + '</button>').prop('disabled', page >= totalPages);
        $next.on('click', function () {
            TUM.pageState[tableId] = page + 1;
            TUM.renderTable(tableId);
        });

        $btns.find('[data-page]').on('click', function () {
            TUM.pageState[tableId] = parseInt($(this).data('page'), 10);
            TUM.renderTable(tableId);
        });

        $pag.append($info).append($prev).append($btns).append($next);
    };

    TUM.uniqueColValues = function (rows, colIdx) {
        var seen = {}, vals = [];
        $.each(rows, function (_, row) {
            var v = row[colIdx] || '';
            if (!seen[v]) { seen[v] = true; vals.push(v); }
        });
        return vals.sort();
    };

    /* ══════════════════════════════════════════════════════════
       CSS VARIABLE APPLICATION (per-panel theming)
    ══════════════════════════════════════════════════════════ */
    TUM.applyCSSVars = function (tableId, $panel) {
        var fmt = (TUM.tables[tableId] || {}).formatting || {};
        var colors = fmt.colors || {};
        var typo   = fmt.typography || {};
        var scheme = TUM.schemes[fmt.colorScheme] || TUM.schemes['ocean-blue'];

        var c = $.extend({}, scheme, colors);

        var el = $panel[0] || document.getElementById('tum-panel-' + tableId);
        if (!el) return;

        el.style.setProperty('--tum-header-bg',   c.headerBg   || scheme.headerBg);
        el.style.setProperty('--tum-header-text',  c.headerText || scheme.headerText);
        el.style.setProperty('--tum-row-bg',       c.rowBg      || scheme.rowBg);
        el.style.setProperty('--tum-alt-row-bg',   c.altRowBg   || scheme.altRowBg);
        el.style.setProperty('--tum-border',       c.borderColor|| scheme.borderColor);
        el.style.setProperty('--tum-hover-bg',     c.hoverBg    || scheme.hoverBg);
        el.style.setProperty('--tum-accent',       c.accentColor|| scheme.accentColor);
        el.style.setProperty('--tum-font-size',    (typo.fontSize || 14) + 'px');
        el.style.setProperty('--tum-font-weight',  typo.headerFontWeight || '600');
        el.style.setProperty('--tum-text-align',   typo.textAlign || 'left');
    };

    /* ══════════════════════════════════════════════════════════
       SORT STATE
    ══════════════════════════════════════════════════════════ */
    TUM.initSort = function (tableId) {
        TUM.sortState[tableId] = { col: null, dir: 'asc' };
    };

    TUM.toggleSort = function (tableId, colIdx) {
        var s = TUM.sortState[tableId] || { col: null, dir: 'asc' };
        if (s.col === colIdx) {
            s.dir = s.dir === 'asc' ? 'desc' : 'asc';
        } else {
            s.col = colIdx; s.dir = 'asc';
        }
        TUM.sortState[tableId] = s;
        TUM.pageState[tableId] = 1;
        TUM.renderTable(tableId);
    };

    /* ══════════════════════════════════════════════════════════
       FILTER STATE
    ══════════════════════════════════════════════════════════ */
    TUM.initFilterState = function (tableId) {
        TUM.filterState[tableId] = {};
    };

    /* ══════════════════════════════════════════════════════════
       FORMATTING SIDEBAR
    ══════════════════════════════════════════════════════════ */
    TUM.buildFormattingSidebar = function (tableId) {
        var data = TUM.tables[tableId];
        if (!data || !data.can_edit) return;

        var fmt  = data.formatting || {};
        var $panel = $('#tum-panel-' + tableId);

        // Color scheme swatches
        var $swatches = $panel.find('.tum-scheme-swatches');
        $.each(TUM.schemes, function (key, scheme) {
            var $sw = $('<div class="tum-scheme-swatch"></div>').attr('data-scheme', key);
            if ((fmt.colorScheme || 'ocean-blue') === key) $sw.addClass('active');
            $sw.append(
                $('<div class="tum-swatch-preview"></div>')
                    .append($('<div class="tum-swatch-header"></div>').css('background', scheme.headerBg))
                    .append($('<div class="tum-swatch-row"></div>').css('background', scheme.rowBg))
            );
            $sw.append($('<div class="tum-swatch-label"></div>').text(scheme.label));
            $swatches.append($sw);
        });
        // Show custom colors if scheme is 'custom'
        if (fmt.colorScheme === 'custom') {
            $panel.find('.tum-custom-colors-section').show();
        }

        // Pre-fill custom color pickers
        var colors = fmt.colors || {};
        $panel.find('[data-color-key]').each(function () {
            var key = $(this).attr('data-color-key');
            if (colors[key]) $(this).val(colors[key]);
        });

        // Typography
        var typo = fmt.typography || {};
        var $fontSize = $panel.find('[data-format-key="typography.fontSize"]');
        $fontSize.val(typo.fontSize || 14);
        $panel.find('.tum-range-val').text((typo.fontSize || 14) + 'px');
        $panel.find('[data-format-key="typography.headerFontWeight"]').val(typo.headerFontWeight || '600');

        // Text align radio
        var textAlign = typo.textAlign || 'left';
        $panel.find('.tum-radio-group[data-format-key="typography.textAlign"] button').each(function () {
            $(this).toggleClass('active', $(this).data('val') === textAlign);
        });

        // Layout toggles
        var layout = fmt.layout || {};
        $panel.find('[data-format-key="layout.stripedRows"]').prop('checked',  layout.stripedRows  !== false);
        $panel.find('[data-format-key="layout.hoverEffect"]').prop('checked',  layout.hoverEffect  !== false);
        $panel.find('[data-format-key="layout.frozenHeader"]').prop('checked', layout.frozenHeader !== false);
        $panel.find('[data-format-key="layout.compactMode"]').prop('checked',  !!layout.compactMode);
        $panel.find('[data-format-key="layout.borderStyle"]').val(layout.borderStyle || 'full');
        $panel.find('[data-format-key="layout.pagination"]').prop('checked',   layout.pagination   !== false);
        $panel.find('[data-format-key="layout.rowsPerPage"]').val(layout.rowsPerPage || 25);
        if (!layout.pagination) $panel.find('.tum-pagination-opts').hide();

        // Column list
        TUM.buildColumnList(tableId);
    };

    TUM.buildColumnList = function (tableId) {
        var data    = TUM.tables[tableId];
        var headers = data.headers || [];
        var fmt     = data.formatting || {};
        var vis     = fmt.columnVisibility || {};
        var filters = (fmt.filters || {}).columns || {};
        var sortable = fmt.sortable || [];

        var $list = $('#tum-panel-' + tableId).find('.tum-column-list');
        $list.empty();

        var filterTypes = [
            { val: 'none',         label: 'No Filter' },
            { val: 'text',         label: 'Text Search' },
            { val: 'select',       label: 'Dropdown' },
            { val: 'number-range', label: 'Number Range' },
        ];

        $.each(headers, function (i, h) {
            var isVisible  = vis[i] !== false;
            var isSortable = sortable.indexOf(i) > -1;
            var colFmt     = filters[i] || { enabled: false, type: 'none' };
            var filterEnabled = colFmt.enabled || false;
            var filterType    = colFmt.type    || 'none';

            var $item = $('<div class="tum-col-item"></div>');
            var $hdr  = $('<div class="tum-col-item-header"></div>');

            // Visibility checkbox
            $('<input type="checkbox">')
                .attr({ 'data-col-vis': i, 'aria-label': 'Show column ' + h })
                .prop('checked', isVisible)
                .appendTo($hdr);

            $('<span class="tum-col-name"></span>').text(h).appendTo($hdr);

            // Filter expand button
            var $expBtn = $('<button class="tum-col-expand" aria-label="Column options">⚙</button>');
            $hdr.append($expBtn);
            $item.append($hdr);

            // Filter options (hidden by default)
            var $opts = $('<div class="tum-col-filter-opts" style="display:none;"></div>');

            // Sortable toggle
            $('<label class="tum-toggle-label" style="margin-bottom:6px;"></label>')
                .append($('<input type="checkbox">').attr('data-col-sort', i).prop('checked', isSortable))
                .append(' Sortable')
                .appendTo($opts);

            // Filter enable
            $('<label class="tum-toggle-label" style="margin-bottom:6px;"></label>')
                .append($('<input type="checkbox">').attr('data-col-filter-enabled', i).prop('checked', filterEnabled))
                .append(' Enable Filter')
                .appendTo($opts);

            // Filter type
            var $typeSelect = $('<select style="width:100%;padding:4px 8px;border:1px solid #e2e8f0;border-radius:4px;font-size:.8rem;margin-top:4px;"></select>')
                .attr('data-col-filter-type', i);
            $.each(filterTypes, function (_, ft) {
                $('<option></option>').val(ft.val).text(ft.label).appendTo($typeSelect);
            });
            $typeSelect.val(filterType);
            $opts.append($('<label style="font-size:.75rem;color:#64748b;display:block;margin-bottom:4px;">Filter Type</label>')).append($typeSelect);

            $item.append($opts);
            $list.append($item);

            // Expand/collapse
            $expBtn.on('click', function () {
                $opts.slideToggle(150);
            });
        });
    };

    /* ══════════════════════════════════════════════════════════
       COLLECT FORMATTING FROM SIDEBAR
    ══════════════════════════════════════════════════════════ */
    TUM.collectFormatting = function (tableId) {
        var $panel  = $('#tum-panel-' + tableId);
        var data    = TUM.tables[tableId];
        var headers = data.headers || [];
        var existing = data.formatting || {};

        // Color scheme
        var scheme = $panel.find('.tum-scheme-swatch.active').data('scheme') || 'ocean-blue';

        // Custom colors
        var colors = {};
        $panel.find('[data-color-key]').each(function () {
            colors[$(this).attr('data-color-key')] = $(this).val();
        });

        // Typography
        var typo = {
            fontSize:         parseInt($panel.find('[data-format-key="typography.fontSize"]').val(), 10) || 14,
            headerFontWeight: $panel.find('[data-format-key="typography.headerFontWeight"]').val() || '600',
            textAlign:        $panel.find('.tum-radio-group[data-format-key="typography.textAlign"] button.active').data('val') || 'left'
        };

        // Layout
        var layout = {
            stripedRows:  $panel.find('[data-format-key="layout.stripedRows"]').prop('checked'),
            hoverEffect:  $panel.find('[data-format-key="layout.hoverEffect"]').prop('checked'),
            frozenHeader: $panel.find('[data-format-key="layout.frozenHeader"]').prop('checked'),
            compactMode:  $panel.find('[data-format-key="layout.compactMode"]').prop('checked'),
            borderStyle:  $panel.find('[data-format-key="layout.borderStyle"]').val() || 'full',
            pagination:   $panel.find('[data-format-key="layout.pagination"]').prop('checked'),
            rowsPerPage:  parseInt($panel.find('[data-format-key="layout.rowsPerPage"]').val(), 10) || 25
        };

        // Column visibility + sort + filters
        var columnVisibility = {};
        var sortable         = [];
        var filterCols       = {};

        $.each(headers, function (i) {
            var vis = $panel.find('[data-col-vis="' + i + '"]').prop('checked');
            if (vis === false) columnVisibility[i] = false;
            // else omit (true by default)

            if ($panel.find('[data-col-sort="' + i + '"]').prop('checked')) {
                sortable.push(i);
            }

            var fEnabled = $panel.find('[data-col-filter-enabled="' + i + '"]').prop('checked');
            var fType    = $panel.find('[data-col-filter-type="' + i + '"]').val() || 'none';
            filterCols[i] = { enabled: fEnabled, type: fType, placeholder: '' };
        });

        return {
            colorScheme:      scheme,
            colors:           colors,
            typography:       typo,
            layout:           layout,
            columnVisibility: columnVisibility,
            sortable:         sortable,
            filters: {
                enabled: Object.values(filterCols).some(function (f) { return f.enabled; }),
                columns: filterCols
            }
        };
    };

    /* ══════════════════════════════════════════════════════════
       UPLOAD FLOW
    ══════════════════════════════════════════════════════════ */
    TUM.setProgress = function (tableId, pct) {
        var $panel = $('#tum-panel-' + tableId);
        $panel.find('.tum-progress-fill').css('width', pct + '%');
        $panel.find('.tum-progress-label').text(TUM.str.uploading + ' ' + pct + '%');
    };

    TUM.handleFile = function (file, tableId) {
        if (!file) return;

        var ext  = file.name.split('.').pop().toLowerCase();
        var ok   = ['csv','tsv','xlsx'].indexOf(ext) > -1;
        if (!ok) {
            TUM.showUploadNotice(tableId, TUM.str.invalidType, 'error');
            return;
        }
        if (file.size > TUM.cfg.maxFileSize) {
            TUM.showUploadNotice(tableId, TUM.str.fileTooLarge, 'error');
            return;
        }

        var $panel = $('#tum-panel-' + tableId);
        $panel.find('.tum-upload-progress').show();
        $panel.find('.tum-upload-notice').hide();
        TUM.setProgress(tableId, 0);

        var fd = new FormData();
        fd.append('tum_file', file);
        fd.append('table_id', tableId);

        TUM.ajaxUpload('tum_upload_file', fd).done(function (resp) {
            $panel.find('.tum-upload-progress').hide();
            if (!resp.success) {
                TUM.showUploadNotice(tableId, resp.data.message || TUM.str.error, 'error');
                return;
            }
            var d = resp.data;
            // Store pending upload
            TUM.pendingUpload[tableId] = {
                headers:      d.headers,
                preview_rows: d.preview_rows,
                row_count:    d.row_count,
                column_count: d.column_count
            };
            // Update table state temporarily for preview
            var prev = TUM.tables[tableId];
            TUM.tables[tableId] = $.extend({}, prev, {
                headers:      d.headers,
                rows:         d.preview_rows,
                row_count:    d.row_count,
                column_count: d.column_count,
                has_data:     true
            });

            // Show preview banner
            $panel.find('.tum-preview-text').text(
                TUM.str.previewOf + ' — ' + d.row_count + ' ' + TUM.str.rowCount + ', ' + d.column_count + ' ' + TUM.str.colCount
            );
            $panel.find('.tum-preview-banner').show();
            $panel.find('.tum-upload-section').slideUp(200);
            $panel.find('.tum-no-data').hide();

            TUM.initSort(tableId);
            TUM.initFilterState(tableId);
            TUM.pageState[tableId]   = 1;
            TUM.searchState[tableId] = '';
            TUM.renderTable(tableId);
            TUM.buildColumnList(tableId);
        }).fail(function () {
            $panel.find('.tum-upload-progress').hide();
            TUM.showUploadNotice(tableId, TUM.str.error, 'error');
        });
    };

    TUM.showUploadNotice = function (tableId, msg, type) {
        var $notice = $('#tum-panel-' + tableId).find('.tum-upload-notice');
        $notice.removeClass('tum-notice-error tum-notice-success tum-notice-info')
               .addClass('tum-notice tum-notice-' + type)
               .text(msg)
               .show();
    };

    /* ══════════════════════════════════════════════════════════
       SAVE TABLE (after preview)
    ══════════════════════════════════════════════════════════ */
    TUM.saveUpload = function (tableId) {
        // The data was already saved on upload_file; just dismiss preview mode
        TUM.pendingUpload[tableId] = null;

        var $panel = $('#tum-panel-' + tableId);
        $panel.find('.tum-preview-banner').hide();
        TUM.showFormatNotice(tableId, TUM.str.saved, 'success');

        // Reload full data from server
        TUM.tables[tableId] = null;
        TUM.loadTable(tableId);
    };

    TUM.discardUpload = function (tableId) {
        TUM.pendingUpload[tableId] = null;
        var $panel = $('#tum-panel-' + tableId);
        $panel.find('.tum-preview-banner').hide();

        // Reload original data
        TUM.tables[tableId] = null;
        TUM.loadTable(tableId);
    };

    /* ══════════════════════════════════════════════════════════
       SAVE FORMATTING
    ══════════════════════════════════════════════════════════ */
    TUM.saveFormatting = function (tableId) {
        var fmt    = TUM.collectFormatting(tableId);
        var $panel = $('#tum-panel-' + tableId);
        var $btn   = $panel.find('.tum-save-formatting-btn');

        $btn.prop('disabled', true).text(TUM.str.saving);

        TUM.ajax('tum_save_formatting', {
            table_id:   tableId,
            formatting: JSON.stringify(fmt)
        }).done(function (resp) {
            $btn.prop('disabled', false).text('✓ Save Formatting');
            if (resp.success) {
                TUM.tables[tableId].formatting = resp.data.formatting;
                TUM.applyCSSVars(tableId, $panel);
                TUM.renderTable(tableId);
                TUM.showFormatNotice(tableId, TUM.str.saved, 'success');
            } else {
                TUM.showFormatNotice(tableId, resp.data.message || TUM.str.error, 'error');
            }
        }).fail(function () {
            $btn.prop('disabled', false).text('✓ Save Formatting');
            TUM.showFormatNotice(tableId, TUM.str.error, 'error');
        });
    };

    TUM.showFormatNotice = function (tableId, msg, type) {
        var $notice = $('#tum-panel-' + tableId).find('.tum-format-notice');
        $notice.removeClass('tum-notice-error tum-notice-success tum-notice-info')
               .addClass('tum-notice tum-notice-' + type)
               .text(msg).show();
        setTimeout(function () { $notice.fadeOut(400); }, 2500);
    };

    /* ══════════════════════════════════════════════════════════
       PERMISSION MODAL
    ══════════════════════════════════════════════════════════ */
    TUM.openPermissionModal = function (tableId) {
        var $modal = $('#tum-modal-permissions');
        $modal.data('table-id', tableId).show();
        TUM.loadPermissions(tableId);
    };

    TUM.loadPermissions = function (tableId) {
        TUM.ajax('tum_get_permissions', { table_id: tableId }).done(function (resp) {
            if (!resp.success) return;
            TUM.renderPermList(tableId, resp.data);
        });
    };

    TUM.renderPermList = function (tableId, users) {
        var $list = $('#tum-perm-list').empty();
        if (!users.length) {
            $list.append('<li class="tum-perm-empty">No additional editors.</li>');
            return;
        }
        $.each(users, function (_, u) {
            var initials = (u.display_name || u.login || '?').charAt(0).toUpperCase();
            var $li = $('<li class="tum-perm-list-item"></li>');
            $li.append('<div class="tum-user-avatar">' + initials + '</div>');
            var $info = $('<div class="tum-user-info-wrap"></div>')
                .append($('<span class="tum-user-name"></span>').text(u.display_name))
                .append($('<span class="tum-user-email"></span>').text(u.email));
            $li.append($info);
            var $rem = $('<button class="tum-perm-remove">Remove</button>').attr('data-user-id', u.id);
            $rem.on('click', function () {
                if (!confirm(TUM.str.confirmRevoke)) return;
                TUM.ajax('tum_revoke_permission', { table_id: tableId, user_id: u.id }).done(function (r) {
                    if (r.success) $li.fadeOut(200, function () { $li.remove(); });
                });
            });
            $li.append($rem);
            $list.append($li);
        });
    };

    TUM.bindPermSearch = function () {
        var $input   = $('#tum-perm-search');
        var $results = $('#tum-perm-search-results');

        $input.on('input', function () {
            clearTimeout(TUM.permSearchTimer);
            var term = $(this).val().trim();
            if (term.length < 2) { $results.empty().hide(); return; }

            TUM.permSearchTimer = setTimeout(function () {
                TUM.ajax('tum_search_users', { term: term }).done(function (resp) {
                    $results.empty();
                    if (!resp.success || !resp.data.length) { $results.hide(); return; }
                    $.each(resp.data, function (_, u) {
                        var initials = (u.display_name || '?').charAt(0).toUpperCase();
                        var $item = $('<div class="tum-user-dropdown-item"></div>')
                            .attr('data-user-id', u.id);
                        $item.append('<div class="tum-user-avatar">' + initials + '</div>');
                        var $info = $('<div class="tum-user-info-wrap"></div>')
                            .append($('<span class="tum-user-name"></span>').text(u.display_name))
                            .append($('<span class="tum-user-email"></span>').text(u.email));
                        $item.append($info);
                        $item.on('click', function () {
                            var tableId = parseInt($('#tum-modal-permissions').data('table-id'), 10);
                            $results.hide();
                            $input.val('');
                            TUM.ajax('tum_grant_permission', { table_id: tableId, user_id: u.id }).done(function (r) {
                                if (r.success) TUM.loadPermissions(tableId);
                                var noticeType = r.success ? 'success' : 'error';
                                var noticeMsg  = r.success ? r.data.message : (r.data ? r.data.message : TUM.str.error);
                                $('#tum-perm-notice')
                                    .removeClass('tum-notice-error tum-notice-success tum-notice-info')
                                    .addClass('tum-notice tum-notice-' + noticeType)
                                    .text(noticeMsg).show();
                                setTimeout(function () { $('#tum-perm-notice').fadeOut(); }, 2500);
                            });
                        });
                        $results.append($item);
                    });
                    $results.show();
                });
            }, 300);
        });

        // Close dropdown on outside click
        $(document).on('click', function (e) {
            if (!$(e.target).closest('.tum-perm-search-wrap').length) {
                $results.hide();
            }
        });
    };

    /* ══════════════════════════════════════════════════════════
       CREATE TABLE MODAL
    ══════════════════════════════════════════════════════════ */
    TUM.bindCreateModal = function () {
        $('#tum-add-table-btn').on('click', function () {
            $('#tum-new-title').val('');
            $('#tum-new-description').val('');
            $('#tum-create-error').hide();
            $('#tum-modal-create').show();
            setTimeout(function () { $('#tum-new-title').focus(); }, 100);
        });

        $('#tum-create-table-submit').on('click', function () {
            var title = $('#tum-new-title').val().trim();
            var desc  = $('#tum-new-description').val().trim();
            if (!title) {
                $('#tum-create-error').text('Title is required.').show();
                return;
            }
            var $btn = $(this);
            $btn.prop('disabled', true).text(TUM.str.saving);

            TUM.ajax('tum_create_table', { title: title, description: desc }).done(function (resp) {
                $btn.prop('disabled', false).text('Create Table');
                if (!resp.success) {
                    $('#tum-create-error').text(resp.data.message || TUM.str.error).show();
                    return;
                }
                TUM.closeModal('tum-modal-create');
                var newId = resp.data.table_id;
                // Add tab and load
                TUM.addTab({ id: newId, title: title });
                TUM.switchTab(newId);
            }).fail(function () {
                $btn.prop('disabled', false).text('Create Table');
                $('#tum-create-error').text(TUM.str.error).show();
            });
        });
    };

    /* ══════════════════════════════════════════════════════════
       MODAL HELPERS
    ══════════════════════════════════════════════════════════ */
    TUM.closeModal = function (id) {
        $('#' + id).hide();
    };

    TUM.bindModalClose = function () {
        $(document).on('click', '.tum-modal-close, .tum-modal-footer .tum-btn-ghost', function () {
            var mid = $(this).data('modal') || $(this).closest('.tum-modal-overlay').attr('id');
            if (mid) TUM.closeModal(mid);
        });
        $(document).on('click', '.tum-modal-overlay', function (e) {
            if ($(e.target).hasClass('tum-modal-overlay')) {
                TUM.closeModal($(this).attr('id'));
            }
        });
        $(document).on('keydown', function (e) {
            if (e.key === 'Escape') { $('.tum-modal-overlay:visible').hide(); }
        });
    };

    /* ══════════════════════════════════════════════════════════
       PANEL-LEVEL EVENT BINDINGS
    ══════════════════════════════════════════════════════════ */
    TUM.bindPanelEvents = function ($panel, tableId) {
        var $dropzone  = $panel.find('.tum-dropzone');
        var $fileInput = $panel.find('.tum-file-input');

        // ── Drag & drop ───────────────────────────────────────
        $dropzone.on('dragover dragleave drop', function (e) {
            e.preventDefault();
            if (e.type === 'dragover') $dropzone.addClass('drag-over');
            if (e.type === 'dragleave' || e.type === 'drop') $dropzone.removeClass('drag-over');
            if (e.type === 'drop') {
                var file = e.originalEvent.dataTransfer.files[0];
                TUM.handleFile(file, tableId);
            }
        });
        $dropzone.on('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') $fileInput.trigger('click');
        });
        $fileInput.on('change', function () {
            TUM.handleFile(this.files[0], tableId);
            this.value = '';
        });

        // ── Edit toolbar ──────────────────────────────────────
        $panel.on('click', '.tum-toggle-upload-btn', function () {
            $panel.find('.tum-upload-section').slideToggle(200);
        });

        $panel.on('click', '.tum-toggle-format-btn', function () {
            var $sidebar = $panel.find('.tum-format-sidebar');
            if ($sidebar.is(':visible')) {
                $sidebar.hide();
                $panel.find('.tum-content-area').css('flex-direction', '');
            } else {
                $sidebar.show();
            }
        });

        $panel.on('click', '.tum-manage-perms-btn', function () {
            TUM.openPermissionModal(tableId);
        });

        $panel.on('click', '.tum-delete-table-btn', function () {
            if (!confirm(TUM.str.confirmDelete)) return;
            TUM.ajax('tum_delete_table', { table_id: tableId }).done(function (resp) {
                if (resp.success) {
                    $('.tum-tab-btn[data-table-id="' + tableId + '"]').remove();
                    $('#tum-panel-' + tableId).remove();
                    delete TUM.tables[tableId];
                    // Activate first remaining tab
                    var $first = $('.tum-tab-btn').first();
                    if ($first.length) TUM.switchTab($first.data('table-id'));
                }
            });
        });

        // ── Preview actions ───────────────────────────────────
        $panel.on('click', '.tum-save-upload', function () { TUM.saveUpload(tableId); });
        $panel.on('click', '.tum-discard-upload', function () { TUM.discardUpload(tableId); });

        // ── Formatting sidebar events ─────────────────────────
        // Color scheme swatch
        $panel.on('click', '.tum-scheme-swatch', function () {
            $panel.find('.tum-scheme-swatch').removeClass('active');
            $(this).addClass('active');
            var scheme = $(this).data('scheme');
            if (scheme === 'custom') {
                $panel.find('.tum-custom-colors-section').show();
            } else {
                $panel.find('.tum-custom-colors-section').hide();
                // Apply scheme preview immediately
                var s = TUM.schemes[scheme];
                if (s && TUM.tables[tableId]) {
                    TUM.tables[tableId].formatting = TUM.tables[tableId].formatting || {};
                    TUM.tables[tableId].formatting.colorScheme = scheme;
                    TUM.tables[tableId].formatting.colors = {};
                    TUM.applyCSSVars(tableId, $panel);
                }
            }
        });

        // Custom colors live preview
        $panel.on('input', '[data-color-key]', function () {
            TUM.livePreviewColors(tableId);
        });

        // Range live update
        $panel.on('input', '.tum-range', function () {
            $(this).closest('.tum-form-group').find('.tum-range-val').text($(this).val() + 'px');
            TUM.livePreviewTypography(tableId);
        });

        // Align radio
        $panel.on('click', '.tum-radio-group[data-format-key="typography.textAlign"] button', function () {
            $(this).closest('.tum-radio-group').find('button').removeClass('active');
            $(this).addClass('active');
            TUM.livePreviewTypography(tableId);
        });

        // Toggle: re-render
        $panel.on('change', '[data-format-key^="layout"]', function () {
            var key = $(this).attr('data-format-key');
            if (key === 'layout.pagination') {
                if ($(this).prop('checked')) {
                    $panel.find('.tum-pagination-opts').show();
                } else {
                    $panel.find('.tum-pagination-opts').hide();
                }
            }
            TUM.livePreviewLayout(tableId);
        });

        // Save formatting
        $panel.on('click', '.tum-save-formatting-btn', function () { TUM.saveFormatting(tableId); });

        // ── Column visibility live preview ────────────────────
        $panel.on('change', '[data-col-vis]', function () {
            var colIdx  = parseInt($(this).attr('data-col-vis'), 10);
            var visible = $(this).prop('checked');
            var fmt = TUM.tables[tableId].formatting = TUM.tables[tableId].formatting || {};
            fmt.columnVisibility = fmt.columnVisibility || {};
            fmt.columnVisibility[colIdx] = visible;
            TUM.renderTable(tableId);
        });

        // ── Sort toggle in column list ────────────────────────
        $panel.on('change', '[data-col-sort]', function () {
            var colIdx   = parseInt($(this).attr('data-col-sort'), 10);
            var enabled  = $(this).prop('checked');
            var fmt = TUM.tables[tableId].formatting = TUM.tables[tableId].formatting || {};
            fmt.sortable = fmt.sortable || [];
            if (enabled) {
                if (fmt.sortable.indexOf(colIdx) === -1) fmt.sortable.push(colIdx);
            } else {
                fmt.sortable = fmt.sortable.filter(function (i) { return i !== colIdx; });
            }
            TUM.renderTable(tableId);
        });

        // ── Sortable header click ─────────────────────────────
        $panel.on('click', 'thead th.tum-sortable', function () {
            var col = parseInt($(this).attr('data-col'), 10);
            TUM.toggleSort(tableId, col);
        });

        // ── Column filter input ───────────────────────────────
        $panel.on('input change', '.tum-filter-input, .tum-filter-select', function () {
            var col = parseInt($(this).attr('data-col'), 10);
            TUM.filterState[tableId] = TUM.filterState[tableId] || {};
            TUM.filterState[tableId][col] = $(this).val();
            TUM.pageState[tableId] = 1;
            TUM.renderTable(tableId);
        });

        // ── Global search ─────────────────────────────────────
        var searchTimer;
        $panel.on('input', '.tum-global-search', function () {
            clearTimeout(searchTimer);
            var val = $(this).val();
            searchTimer = setTimeout(function () {
                TUM.searchState[tableId] = val;
                TUM.pageState[tableId]   = 1;
                TUM.renderTable(tableId);
            }, 250);
        });
    };

    /* ── Live preview helpers ────────────────────────────────── */
    TUM.livePreviewColors = function (tableId) {
        var $panel = $('#tum-panel-' + tableId);
        var colors = {};
        $panel.find('[data-color-key]').each(function () {
            colors[$(this).attr('data-color-key')] = $(this).val();
        });
        if (!TUM.tables[tableId]) return;
        TUM.tables[tableId].formatting = TUM.tables[tableId].formatting || {};
        TUM.tables[tableId].formatting.colors = colors;
        TUM.tables[tableId].formatting.colorScheme = 'custom';
        TUM.applyCSSVars(tableId, $panel);
    };

    TUM.livePreviewTypography = function (tableId) {
        var $panel = $('#tum-panel-' + tableId);
        var fontSize = parseInt($panel.find('[data-format-key="typography.fontSize"]').val(), 10) || 14;
        var align    = $panel.find('.tum-radio-group[data-format-key="typography.textAlign"] button.active').data('val') || 'left';
        var weight   = $panel.find('[data-format-key="typography.headerFontWeight"]').val() || '600';
        if (!TUM.tables[tableId]) return;
        TUM.tables[tableId].formatting = TUM.tables[tableId].formatting || {};
        TUM.tables[tableId].formatting.typography = { fontSize: fontSize, headerFontWeight: weight, textAlign: align };
        TUM.applyCSSVars(tableId, $panel);
    };

    TUM.livePreviewLayout = function (tableId) {
        if (!TUM.tables[tableId] || !TUM.tables[tableId].has_data) return;
        var fmt = TUM.collectFormatting(tableId);
        TUM.tables[tableId].formatting = $.extend(TUM.tables[tableId].formatting || {}, { layout: fmt.layout });
        TUM.renderTable(tableId);
    };

    /* ══════════════════════════════════════════════════════════
       GLOBAL EVENT BINDINGS
    ══════════════════════════════════════════════════════════ */
    TUM.bindGlobal = function () {
        // Tab click
        $(document).on('click', '.tum-tab-btn', function () {
            TUM.switchTab($(this).data('table-id'));
        });

        // Tab scroll arrows
        $(document).on('click', '.tum-tab-scroll-left', function () {
            var $track = $('#tum-tabs-track');
            $track.scrollLeft($track.scrollLeft() - 200);
        });
        $(document).on('click', '.tum-tab-scroll-right', function () {
            var $track = $('#tum-tabs-track');
            $track.scrollLeft($track.scrollLeft() + 200);
        });

        TUM.bindCreateModal();
        TUM.bindModalClose();
        TUM.bindPermSearch();
    };

    /* ══════════════════════════════════════════════════════════
       BOOT
    ══════════════════════════════════════════════════════════ */
    $(function () {
        if ($('#tum-app').length) {
            TUM.init();
        }
    });

    // Expose for debugging
    window.TUM = TUM;

}(jQuery));
