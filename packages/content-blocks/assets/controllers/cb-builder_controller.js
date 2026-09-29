import { Controller } from '@hotwired/stimulus';
import { mix } from '../builder/mix.js';
import Requests from '../builder/requests.js';
import Session from '../builder/session.js';
import SaveStatus from '../builder/save-status.js';
import Snackbar from '../builder/snackbar.js';
import Preview from '../builder/preview.js';
import Structure from '../builder/structure.js';
import Publishing from '../builder/publishing.js';
import History from '../builder/history.js';
import Clipboard from '../builder/clipboard.js';
import Keyboard from '../builder/keyboard.js';
import ActionsMenu from '../builder/actions-menu.js';
import Viewport from '../builder/viewport.js';
import Sidebar from '../builder/sidebar.js';
import SidebarResize from '../builder/sidebar-resize.js';
import ReplacePicker from '../builder/replace-picker.js';
import TemplateLibrary from '../builder/template-library.js';
import TemplatePoster from '../builder/template-poster.js';
import Tree from '../builder/tree.js';
import ImportExport from '../builder/import-export.js';
import Strings from '../builder/strings.js';

/**
 * Bridges the parent admin window with the iframe preview and the sidebar, and
 * owns every AJAX call. Each feature lives in `builder/`, mixed in below.
 *
 * @see docs/internals/frontend.md#the-cb-event-contract
 * @see docs/internals/frontend.md#the-builder-controller-is-split-by-feature
 */
class BuilderController extends Controller {
    static targets = [
        'iframe',
        'sidebar',
        'sidebarContent',
        'sidebarResize',
        'sidebarToggle',
        'progress',
        'savedFlash',
        'saveError',
        'sessionExpired',
        'sessionLogin',
        'undoBar',
        'undoLabel',
        'undoButton',
        'undoLink',
        'replacePicker',
        'replacePickerSearch',
        'replacePickerList',
        'replacePickerStatus',
        'templatePicker',
        'templatePickerSearch',
        'templatePickerList',
        'templatePickerStatus',
        'transferDialog',
        'actionsMenu',
        'actionsToggle',
        'actionsList',
        'viewportOrder',
        'viewportOrderLabel',
        'viewportOrderReset',
    ];

    static values = {
        areaId: Number,
        iframeUrl: String,
        /** Idle ms after which the next interaction checks the session. */
        sessionCheckAfter: { type: Number, default: 60000 },
    };

    connect() {
        this._onMessage = this._onMessage.bind(this);
        this._onBlockSaved = this._onBlockSaved.bind(this);
        this._onSectionSaved = this._onSectionSaved.bind(this);
        this._onColumnAddRequested = this._onColumnAddRequested.bind(this);
        this._onColumnDeleteRequested = this._onColumnDeleteRequested.bind(this);
        this._onResizeMove = this._onResizeMove.bind(this);
        this._onResizeEnd = this._onResizeEnd.bind(this);
        this._onWindowResize = this._onWindowResize.bind(this);
        this._onLiveConnect = this._onLiveConnect.bind(this);
        this._onSaveError = this._onSaveError.bind(this);
        this._onAreaChanged = this._onAreaChanged.bind(this);
        this._onNotify = this._onNotify.bind(this);
        this._onDocumentPointerDown = this._onDocumentPointerDown.bind(this);
        this._onDocumentKeydown = this._onDocumentKeydown.bind(this);
        this._onTreeSelect = this._onTreeSelect.bind(this);
        this._onTreeDuplicate = this._onTreeDuplicate.bind(this);
        this._onTreeDelete = this._onTreeDelete.bind(this);
        this._onTreeSectionMove = this._onTreeSectionMove.bind(this);
        this._onTreeBlockMove = this._onTreeBlockMove.bind(this);
        this._onTreeState = this._onTreeState.bind(this);
        this._onActivity = this._onActivity.bind(this);
        this._onVisibilityChange = this._onVisibilityChange.bind(this);

        this._sessionExpired = false;
        this._lastActivityAt = Date.now();
        this._lastSessionCheckAt = 0;
        window.addEventListener('focus', this._onActivity);
        document.addEventListener('visibilitychange', this._onVisibilityChange);

        window.addEventListener('message', this._onMessage);
        window.addEventListener('resize', this._onWindowResize);
        // Both close on outside-click and Escape, and both live outside
        // this controller's own handlers — hence document-level listeners.
        document.addEventListener('pointerdown', this._onDocumentPointerDown);
        document.addEventListener('keydown', this._onDocumentKeydown);
        // BlockComponent.save() and the section-settings form both
        // dispatchBrowserEvent on save; the events bubble up to here.
        this.element.addEventListener('cb:block:saved', this._onBlockSaved);
        this.element.addEventListener('cb:section:saved', this._onSectionSaved);
        // The section sidebar asks; the columns change through the queue.
        this.element.addEventListener('cb:column:add-requested', this._onColumnAddRequested);
        this.element.addEventListener('cb:column:delete-requested', this._onColumnDeleteRequested);
        // live:connect bubbles from every Live Component in the sidebar;
        // cb:save:error from the section form and the hooks below.
        this.element.addEventListener('live:connect', this._onLiveConnect);
        this.element.addEventListener('cb:save:error', this._onSaveError);
        // Inbound: a shell fragment (or the host) changed the area through
        // its own endpoints and asks the builder to catch up.
        this.element.addEventListener('cb:area:changed', this._onAreaChanged);
        this.element.addEventListener('cb:notify', this._onNotify);
        // The tree panel signals; this controller acts, so every mutation
        // still goes through one queue. See docs/internals/frontend.md
        this.element.addEventListener('cb:tree:select', this._onTreeSelect);
        this.element.addEventListener('cb:tree:duplicate', this._onTreeDuplicate);
        this.element.addEventListener('cb:tree:delete', this._onTreeDelete);
        this.element.addEventListener('cb:tree:section:move', this._onTreeSectionMove);
        this.element.addEventListener('cb:tree:block:move', this._onTreeBlockMove);
        this.element.addEventListener('cb:tree:state', this._onTreeState);

        this._restoreSidebarWidth();
        this._restoreSidebarCollapsed();
        this._refreshViewportButtons();
        // Remember the initial empty-state HTML so we can restore it
        // when the user clicks outside any focused element.
        if (this.hasSidebarContentTarget) {
            this._sidebarEmptyHtml = this.sidebarContentTarget.innerHTML;
        }
        // The library is the empty sidebar's whole content when nothing is
        // selected, so it loads with the builder rather than on demand.
        this._showTemplates();
        // Mobile boots unfocused; collapse the sheet to a strip rather
        // than a half-screen pane over the preview.
        this._syncEmptySidebar();
    }

    disconnect() {
        window.removeEventListener('message', this._onMessage);
        window.removeEventListener('resize', this._onWindowResize);
        window.removeEventListener('focus', this._onActivity);
        document.removeEventListener('visibilitychange', this._onVisibilityChange);
        this.element.removeEventListener('cb:block:saved', this._onBlockSaved);
        this.element.removeEventListener('cb:section:saved', this._onSectionSaved);
        this.element.removeEventListener('cb:column:add-requested', this._onColumnAddRequested);
        this.element.removeEventListener('cb:column:delete-requested', this._onColumnDeleteRequested);
        this.element.removeEventListener('live:connect', this._onLiveConnect);
        this.element.removeEventListener('cb:save:error', this._onSaveError);
        this.element.removeEventListener('cb:area:changed', this._onAreaChanged);
        this.element.removeEventListener('cb:notify', this._onNotify);
        this.element.removeEventListener('cb:tree:select', this._onTreeSelect);
        this.element.removeEventListener('cb:tree:duplicate', this._onTreeDuplicate);
        this.element.removeEventListener('cb:tree:delete', this._onTreeDelete);
        this.element.removeEventListener('cb:tree:section:move', this._onTreeSectionMove);
        this.element.removeEventListener('cb:tree:block:move', this._onTreeBlockMove);
        this.element.removeEventListener('cb:tree:state', this._onTreeState);
        document.removeEventListener('mousemove', this._onResizeMove);
        document.removeEventListener('mouseup', this._onResizeEnd);
        document.removeEventListener('pointerdown', this._onDocumentPointerDown);
        document.removeEventListener('keydown', this._onDocumentKeydown);
        clearTimeout(this._reloadTimer);
        clearTimeout(this._undoTimer);
    }

    _onMessage(event) {
        // Origin check: only trust same-origin posts.
        if (event.origin !== window.location.origin) return;

        const data = event.data;
        if (!data || typeof data !== 'object' || typeof data.type !== 'string') return;
        if (!data.type.startsWith('cb:')) return;
        // Clicks in the preview never reach this document.
        this._onActivity();

        switch (data.type) {
            case 'cb:ready':
                // Public event: the iframe's message is out of a host's reach.
                this.element.dispatchEvent(new CustomEvent('cb:ready', {
                    bubbles: true,
                    detail: { areaId: this.areaIdValue },
                }));
                break;
            case 'cb:block:edit':
                this._mountSidebar(data.blockId);
                break;
            case 'cb:block:delete-requested':
                this._deleteBlock(data.blockId);
                break;
            case 'cb:block:add-requested':
                this._addBlock(data.columnId, data.blockType);
                break;
            case 'cb:block:reorder':
                this._moveBlock(data.blockId, data.toColumnId, data.position);
                break;
            case 'cb:section:add-requested':
                this._addSection(data.layout);
                break;
            case 'cb:section:move-requested':
                this._moveSection(data.sectionId, data.direction);
                break;
            case 'cb:section:reorder':
                this._reorderSection(data.sectionId, data.position);
                break;
            case 'cb:section:duplicate-requested':
                this._duplicateSection(data.sectionId);
                break;
            case 'cb:section:save-template-requested':
                this._saveSectionAsTemplate(data.sectionId);
                break;
            case 'cb:template:insert-requested':
                this.openTemplatePicker();
                break;
            // Relayed by the overlay: what gets copied is whatever the
            // sidebar has open, which only this side knows.
            case 'cb:clipboard:copy-requested':
                this.copySelection();
                break;
            case 'cb:clipboard:paste-requested':
                this.pasteClipboard();
                break;
            case 'cb:history:undo-requested':
                this.undoLastAction();
                break;
            case 'cb:history:redo-requested':
                this.redoLastAction();
                break;
            case 'cb:section:delete-requested':
                this._deleteSection(data.sectionId);
                break;
            case 'cb:block:duplicate-requested':
                this._duplicateBlock(data.blockId);
                break;
            case 'cb:section:settings':
                this._mountSectionSettings(data.sectionId);
                break;
            case 'cb:preview:outside-click':
                this._onPreviewOutsideClick();
                break;
            case 'cb:focus:not-found':
                // The focused element no longer exists — a section delete
                // that cascaded to a child block. Clear the stale form.
                this._resetSidebarToEmptyState();
                break;
            case 'cb:viewport:changed':
                this._showViewportOrder(data.viewport);
                break;
            case 'cb:viewport-order:requested':
                this._setViewportOrder(data);
                break;
            case 'cb:viewport-order:refused':
                this._notify(this._t(
                    'cb.builder.viewport_order.column',
                    'On tablet and mobile a block can only be reordered within its column',
                ));
                break;
            case 'cb:reorder:desync':
                // The overlay couldn't find a node it was asked to relocate —
                // its DOM drifted from the server's model. Reload to resync.
                this.reload();
                break;
            default:
                // Unknown cb:* message — silently ignore (forward-compat).
                break;
        }
    }

    /**
     * Here rather than on the launcher, which re-parents the <dialog> to
     * document.body — moving this button out of its Stimulus scope.
     */
    close(event) {
        if (event) event.preventDefault();
        this.element.closest('dialog')?.close();
    }
}

export default mix(
    BuilderController,
    Requests,
    Session,
    SaveStatus,
    Snackbar,
    Preview,
    Structure,
    Publishing,
    History,
    Clipboard,
    Keyboard,
    ActionsMenu,
    Viewport,
    Sidebar,
    SidebarResize,
    ReplacePicker,
    TemplateLibrary,
    TemplatePoster,
    Tree,
    ImportExport,
    Strings,
);
