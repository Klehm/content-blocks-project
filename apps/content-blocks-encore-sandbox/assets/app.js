import './bootstrap.js';
import './styles/app.css';

// The kit's rich-text block is configured here with `cdn: false`, meaning the
// kit loads no editor JavaScript and expects the host to have bundled one.
// This is that host side: CKEditor comes out of the webpack build, and is
// published under the global name its Stimulus controller looks for.
//
// A namespace import is what the kit's controller expects to find — it reads
// `ClassicEditor` and the plugin constructors by name off the global, exactly
// as CKEditor's own CDN build exposes them.
import * as CKEDITOR from 'ckeditor5';
import 'ckeditor5/ckeditor5.css';

window.CKEDITOR = CKEDITOR;
// Lets the controller pick the right factory signature instead of assuming
// the modern one (see usesAttachToSignature).
if (CKEDITOR.version) {
    window.CKEDITOR_VERSION = CKEDITOR.version;
}
