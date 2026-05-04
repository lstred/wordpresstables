<?php
/**
 * Main shortcode HTML shell.
 * All dynamic content is rendered by public.js via AJAX.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
?>
<div id="tum-app" class="tum-app" role="main">

    <!-- ── Loading state ─────────────────────────────────────── -->
    <div class="tum-loading" id="tum-initial-loader" aria-live="polite">
        <div class="tum-spinner"></div>
        <span><?php esc_html_e( 'Loading tables…', 'table-upload-manager' ); ?></span>
    </div>

    <!-- ── Tab navigation bar ────────────────────────────────── -->
    <nav class="tum-tab-nav" id="tum-tab-nav" style="display:none;" aria-label="<?php esc_attr_e( 'Tables', 'table-upload-manager' ); ?>">
        <button class="tum-tab-scroll-btn tum-tab-scroll-left" aria-label="<?php esc_attr_e( 'Scroll left', 'table-upload-manager' ); ?>">&#8249;</button>
        <div class="tum-tabs-track" id="tum-tabs-track" role="tablist"></div>
        <button class="tum-tab-scroll-btn tum-tab-scroll-right" aria-label="<?php esc_attr_e( 'Scroll right', 'table-upload-manager' ); ?>">&#8250;</button>
        <?php if ( TUM_Security::current_user_is_approved() ) : ?>
            <button class="tum-add-table-btn" id="tum-add-table-btn" aria-label="<?php esc_attr_e( 'Create new table', 'table-upload-manager' ); ?>">
                <span aria-hidden="true">+</span>
                <span><?php esc_html_e( 'New Table', 'table-upload-manager' ); ?></span>
            </button>
        <?php endif; ?>
    </nav>

    <!-- ── Table panels container ────────────────────────────── -->
    <div class="tum-panels-container" id="tum-panels-container"></div>

    <!-- ══════════════════════════════════════════════════════════
         MODAL: Create New Table
    ══════════════════════════════════════════════════════════ -->
    <div class="tum-modal-overlay" id="tum-modal-create" role="dialog" aria-modal="true" aria-labelledby="tum-modal-create-title" style="display:none;">
        <div class="tum-modal">
            <div class="tum-modal-header">
                <h2 id="tum-modal-create-title"><?php esc_html_e( 'Create New Table', 'table-upload-manager' ); ?></h2>
                <button class="tum-modal-close" data-modal="tum-modal-create" aria-label="<?php esc_attr_e( 'Close', 'table-upload-manager' ); ?>">&times;</button>
            </div>
            <div class="tum-modal-body">
                <div class="tum-form-group">
                    <label for="tum-new-title"><?php esc_html_e( 'Table Title', 'table-upload-manager' ); ?> <span class="tum-required">*</span></label>
                    <input type="text" id="tum-new-title" class="tum-input" placeholder="<?php esc_attr_e( 'e.g. Q1 Sales Data', 'table-upload-manager' ); ?>" maxlength="255">
                </div>
                <div class="tum-form-group">
                    <label for="tum-new-description"><?php esc_html_e( 'Description', 'table-upload-manager' ); ?></label>
                    <textarea id="tum-new-description" class="tum-input tum-textarea" rows="3" placeholder="<?php esc_attr_e( 'Optional short description…', 'table-upload-manager' ); ?>"></textarea>
                </div>
                <div id="tum-create-error" class="tum-notice tum-notice-error" style="display:none;"></div>
            </div>
            <div class="tum-modal-footer">
                <button class="tum-btn tum-btn-ghost" data-modal="tum-modal-create"><?php esc_html_e( 'Cancel', 'table-upload-manager' ); ?></button>
                <button class="tum-btn tum-btn-primary" id="tum-create-table-submit">
                    <?php esc_html_e( 'Create Table', 'table-upload-manager' ); ?>
                </button>
            </div>
        </div>
    </div>

    <!-- ══════════════════════════════════════════════════════════
         MODAL: Manage Permissions
    ══════════════════════════════════════════════════════════ -->
    <div class="tum-modal-overlay" id="tum-modal-permissions" role="dialog" aria-modal="true" aria-labelledby="tum-modal-perm-title" style="display:none;">
        <div class="tum-modal tum-modal-medium">
            <div class="tum-modal-header">
                <h2 id="tum-modal-perm-title"><?php esc_html_e( 'Manage Editors', 'table-upload-manager' ); ?></h2>
                <button class="tum-modal-close" data-modal="tum-modal-permissions" aria-label="<?php esc_attr_e( 'Close', 'table-upload-manager' ); ?>">&times;</button>
            </div>
            <div class="tum-modal-body">
                <p class="tum-modal-desc"><?php esc_html_e( 'Grant other users the ability to upload data and change formatting for this table.', 'table-upload-manager' ); ?></p>

                <div class="tum-perm-search-wrap">
                    <input type="text" id="tum-perm-search" class="tum-input" placeholder="<?php esc_attr_e( 'Search users by name or email…', 'table-upload-manager' ); ?>">
                    <div id="tum-perm-search-results" class="tum-user-dropdown"></div>
                </div>

                <h3><?php esc_html_e( 'Current Editors', 'table-upload-manager' ); ?></h3>
                <ul id="tum-perm-list" class="tum-perm-list">
                    <li class="tum-perm-empty"><?php esc_html_e( 'No additional editors.', 'table-upload-manager' ); ?></li>
                </ul>
                <div id="tum-perm-notice" class="tum-notice" style="display:none;"></div>
            </div>
            <div class="tum-modal-footer">
                <button class="tum-btn tum-btn-ghost" data-modal="tum-modal-permissions"><?php esc_html_e( 'Done', 'table-upload-manager' ); ?></button>
            </div>
        </div>
    </div>

    <!-- ══════════════════════════════════════════════════════════
         TEMPLATE: Table Panel (cloned by JS)
    ══════════════════════════════════════════════════════════ -->
    <template id="tum-panel-template">

            <!-- Panel header -->
            <div class="tum-panel-header">
                <div class="tum-panel-title-wrap">
                    <h2 class="tum-panel-title"></h2>
                    <p class="tum-panel-meta"></p>
                </div>
                <div class="tum-panel-actions"></div>
            </div>

            <!-- Upload zone (shown when in edit mode / no data) -->
            <div class="tum-upload-section" style="display:none;">
                <div class="tum-dropzone" tabindex="0" role="button" aria-label="Upload file">
                    <div class="tum-dropzone-icon">&#8679;</div>
                    <p class="tum-dropzone-text"></p>
                    <p class="tum-dropzone-hint"></p>
                    <input type="file" class="tum-file-input" accept=".csv,.tsv,.xlsx" aria-hidden="true">
                </div>
                <div class="tum-upload-progress" style="display:none;">
                    <div class="tum-progress-bar"><div class="tum-progress-fill"></div></div>
                    <span class="tum-progress-label"></span>
                </div>
                <div class="tum-upload-notice" style="display:none;"></div>
            </div>

            <!-- Preview mode banner -->
            <div class="tum-preview-banner" style="display:none;">
                <span class="tum-preview-icon">&#128065;</span>
                <span class="tum-preview-text"></span>
                <div class="tum-preview-actions">
                    <button class="tum-btn tum-btn-sm tum-btn-ghost tum-discard-upload">&#10005; Discard</button>
                    <button class="tum-btn tum-btn-sm tum-btn-success tum-save-upload">&#10003; Save Table</button>
                </div>
            </div>

            <!-- Edit toolbar (visible when can_edit) -->
            <div class="tum-edit-toolbar" style="display:none;">
                <button class="tum-btn tum-btn-sm tum-btn-secondary tum-toggle-upload-btn">
                    &#8679; <?php esc_html_e( 'Upload New Data', 'table-upload-manager' ); ?>
                </button>
                <button class="tum-btn tum-btn-sm tum-btn-secondary tum-toggle-format-btn">
                    &#9881; <?php esc_html_e( 'Formatting', 'table-upload-manager' ); ?>
                </button>
                <button class="tum-btn tum-btn-sm tum-btn-ghost tum-manage-perms-btn" style="display:none;">
                    &#128101; <?php esc_html_e( 'Manage Editors', 'table-upload-manager' ); ?>
                </button>
                <button class="tum-btn tum-btn-sm tum-btn-danger tum-delete-table-btn" style="display:none;">
                    &#128465; <?php esc_html_e( 'Delete Table', 'table-upload-manager' ); ?>
                </button>
            </div>

            <!-- Main content area: sidebar + table -->
            <div class="tum-content-area">

                <!-- Formatting sidebar -->
                <aside class="tum-format-sidebar" style="display:none;" aria-label="Formatting options">
                    <div class="tum-sidebar-inner">

                        <div class="tum-format-section">
                            <h4><?php esc_html_e( 'Color Scheme', 'table-upload-manager' ); ?></h4>
                            <div class="tum-scheme-swatches"></div>
                        </div>

                        <div class="tum-format-section tum-custom-colors-section" style="display:none;">
                            <h4><?php esc_html_e( 'Custom Colors', 'table-upload-manager' ); ?></h4>
                            <div class="tum-color-row">
                                <label><?php esc_html_e( 'Header BG', 'table-upload-manager' ); ?></label>
                                <input type="color" data-color-key="headerBg">
                            </div>
                            <div class="tum-color-row">
                                <label><?php esc_html_e( 'Header Text', 'table-upload-manager' ); ?></label>
                                <input type="color" data-color-key="headerText">
                            </div>
                            <div class="tum-color-row">
                                <label><?php esc_html_e( 'Row BG', 'table-upload-manager' ); ?></label>
                                <input type="color" data-color-key="rowBg">
                            </div>
                            <div class="tum-color-row">
                                <label><?php esc_html_e( 'Alt Row BG', 'table-upload-manager' ); ?></label>
                                <input type="color" data-color-key="altRowBg">
                            </div>
                            <div class="tum-color-row">
                                <label><?php esc_html_e( 'Border', 'table-upload-manager' ); ?></label>
                                <input type="color" data-color-key="borderColor">
                            </div>
                            <div class="tum-color-row">
                                <label><?php esc_html_e( 'Hover BG', 'table-upload-manager' ); ?></label>
                                <input type="color" data-color-key="hoverBg">
                            </div>
                        </div>

                        <div class="tum-format-section">
                            <h4><?php esc_html_e( 'Typography', 'table-upload-manager' ); ?></h4>
                            <div class="tum-form-group">
                                <label><?php esc_html_e( 'Font Size (px)', 'table-upload-manager' ); ?></label>
                                <input type="range" class="tum-range" data-format-key="typography.fontSize" min="10" max="22" step="1">
                                <span class="tum-range-val"></span>
                            </div>
                            <div class="tum-form-group">
                                <label><?php esc_html_e( 'Header Weight', 'table-upload-manager' ); ?></label>
                                <select class="tum-select" data-format-key="typography.headerFontWeight">
                                    <option value="400"><?php esc_html_e( 'Normal', 'table-upload-manager' ); ?></option>
                                    <option value="600"><?php esc_html_e( 'Semi-Bold', 'table-upload-manager' ); ?></option>
                                    <option value="700"><?php esc_html_e( 'Bold', 'table-upload-manager' ); ?></option>
                                </select>
                            </div>
                            <div class="tum-form-group">
                                <label><?php esc_html_e( 'Text Align', 'table-upload-manager' ); ?></label>
                                <div class="tum-radio-group" data-format-key="typography.textAlign">
                                    <button data-val="left">&#8676;</button>
                                    <button data-val="center">&#8677;</button>
                                    <button data-val="right">&#8677;</button>
                                </div>
                            </div>
                        </div>

                        <div class="tum-format-section">
                            <h4><?php esc_html_e( 'Layout', 'table-upload-manager' ); ?></h4>
                            <label class="tum-toggle-label">
                                <input type="checkbox" data-format-key="layout.stripedRows">
                                <?php esc_html_e( 'Striped Rows', 'table-upload-manager' ); ?>
                            </label>
                            <label class="tum-toggle-label">
                                <input type="checkbox" data-format-key="layout.hoverEffect">
                                <?php esc_html_e( 'Hover Highlight', 'table-upload-manager' ); ?>
                            </label>
                            <label class="tum-toggle-label">
                                <input type="checkbox" data-format-key="layout.frozenHeader">
                                <?php esc_html_e( 'Frozen Header', 'table-upload-manager' ); ?>
                            </label>
                            <label class="tum-toggle-label">
                                <input type="checkbox" data-format-key="layout.compactMode">
                                <?php esc_html_e( 'Compact Mode', 'table-upload-manager' ); ?>
                            </label>
                            <div class="tum-form-group">
                                <label><?php esc_html_e( 'Border Style', 'table-upload-manager' ); ?></label>
                                <select class="tum-select" data-format-key="layout.borderStyle">
                                    <option value="full"><?php esc_html_e( 'Full Grid', 'table-upload-manager' ); ?></option>
                                    <option value="horizontal"><?php esc_html_e( 'Horizontal Only', 'table-upload-manager' ); ?></option>
                                    <option value="none"><?php esc_html_e( 'No Borders', 'table-upload-manager' ); ?></option>
                                </select>
                            </div>
                            <label class="tum-toggle-label">
                                <input type="checkbox" data-format-key="layout.pagination">
                                <?php esc_html_e( 'Enable Pagination', 'table-upload-manager' ); ?>
                            </label>
                            <div class="tum-form-group tum-pagination-opts">
                                <label><?php esc_html_e( 'Rows per Page', 'table-upload-manager' ); ?></label>
                                <select class="tum-select" data-format-key="layout.rowsPerPage">
                                    <option value="10">10</option>
                                    <option value="25">25</option>
                                    <option value="50">50</option>
                                    <option value="100">100</option>
                                </select>
                            </div>
                        </div>

                        <div class="tum-format-section tum-column-section">
                            <h4><?php esc_html_e( 'Columns', 'table-upload-manager' ); ?></h4>
                            <p class="tum-section-hint"><?php esc_html_e( 'Toggle column visibility and configure per-column filters.', 'table-upload-manager' ); ?></p>
                            <div class="tum-column-list"></div>
                        </div>

                        <div class="tum-sidebar-footer">
                            <button class="tum-btn tum-btn-primary tum-save-formatting-btn">
                                &#10003; <?php esc_html_e( 'Save Formatting', 'table-upload-manager' ); ?>
                            </button>
                            <div class="tum-format-notice" style="display:none;"></div>
                        </div>
                    </div>
                </aside>

                <!-- Table viewer -->
                <div class="tum-table-area">
                    <!-- No data state -->
                    <div class="tum-no-data" style="display:none;">
                        <div class="tum-no-data-icon">&#128202;</div>
                        <p><?php esc_html_e( 'No data uploaded yet.', 'table-upload-manager' ); ?></p>
                    </div>

                    <!-- Table controls (search/pagination summary) -->
                    <div class="tum-table-controls" style="display:none;">
                        <div class="tum-global-search-wrap">
                            <input type="text" class="tum-global-search tum-input" placeholder="<?php esc_attr_e( 'Search all columns…', 'table-upload-manager' ); ?>">
                        </div>
                        <div class="tum-table-info"></div>
                    </div>

                    <!-- Scrollable table wrapper -->
                    <div class="tum-table-scroll-wrap">
                        <table class="tum-data-table">
                            <thead></thead>
                            <tbody></tbody>
                        </table>
                    </div>

                    <!-- Pagination -->
                    <div class="tum-pagination" style="display:none;"></div>
                </div>
            </div>

    </template>

</div><!-- /#tum-app -->
