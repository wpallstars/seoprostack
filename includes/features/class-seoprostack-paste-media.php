<?php
/**
 * Paste into the Media Library.
 *
 * Files and pictures pasted from the clipboard are uploaded:
 * - on the Media Library screen, in the media dialog and on Add New Media
 *   File, through core's own uploader (the same path as dropping a file), so
 *   core's file type, size and permission checks and progress display apply;
 * - in the classic editor, through the REST media endpoint, then inserted as
 *   a normal image instead of being dropped or kept as a data: address.
 *
 * The block editor already uploads pasted images and is left alone (its media
 * dialog is covered). Pictures from the clipboard, such as screenshots, have
 * no real file name, so they get a name from a pattern; copied files keep
 * theirs. Pictures can optionally be saved as JPEG or WebP.
 *
 * Replaces "The Paste". Its site settings are imported; its per-user options
 * are not.
 *
 * @package SEOProStack
 * @since 0.4.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOProStack_Paste_Media extends SEOProStack_Feature {

    const KEY = 'paste_media';

    const HANDLE = 'seoprostack-paste-media';

    /**
     * Settings.
     *
     * @return array
     */
    public static function settings() {
        return array(
            self::KEY => array(
                'type'        => 'bool',
                'default'     => false,
                'tab'         => 'media',
                'label'       => __('Paste into the Media Library', 'seoprostack'),
                'description' => __('Paste screenshots, pictures and files from the clipboard on the Media Library screen, in the media dialog and in the classic editor. They upload like dropped files. The block editor already does this.', 'seoprostack'),
                'replaces'    => array('the-paste' => 'The Paste'),
            ),
            'paste_media_editor' => array(
                'type'        => 'bool',
                'default'     => true,
                'parent'      => self::KEY,
                'label'       => __('Classic editor', 'seoprostack'),
                'description' => __('Upload pictures pasted into the classic editor and insert them. Pasted text is not changed.', 'seoprostack'),
            ),
            'paste_media_filename' => array(
                'type'        => 'text',
                'default'     => '%post_title%',
                'parent'      => self::KEY,
                'label'       => __('File name for pasted pictures', 'seoprostack'),
                'description' => __('Pictures from the clipboard, such as screenshots, have no name of their own. Copied files keep theirs. Without a post title, “pasted-image” is used.', 'seoprostack'),
                'tokens'      => array('%post_title%', '%user%', '%date%', '%time%'),
            ),
            'paste_media_format' => array(
                'type'        => 'select',
                'default'     => 'keep',
                'parent'      => self::KEY,
                'label'       => __('Save pasted pictures as', 'seoprostack'),
                'description' => __('Screenshots are usually PNG. JPEG and WebP are much smaller. The original is kept when the converted file is not smaller, and animated GIFs are never converted.', 'seoprostack'),
                'options'     => array(__CLASS__, 'format_options'),
            ),
            'paste_media_quality' => array(
                'type'        => 'int',
                'default'     => 82,
                'min'         => 10,
                'max'         => 100,
                'parent'      => self::KEY,
                'label'       => __('JPEG and WebP quality', 'seoprostack'),
                'description' => __('82 is the WordPress default.', 'seoprostack'),
            ),
        );
    }

    /**
     * Format choices.
     *
     * @return array<string,string>
     */
    public static function format_options() {
        return array(
            'keep' => __('As pasted', 'seoprostack'),
            'jpeg' => __('JPEG', 'seoprostack'),
            'webp' => __('WebP, where the browser supports it', 'seoprostack'),
        );
    }

    /**
     * Import The Paste's site settings. It has no on/off setting, so the
     * feature is switched on when The Paste is active.
     *
     * @param array $options      Stored settings.
     * @param int   $from_version Stored settings version.
     * @return array
     */
    public static function migrate(array $options, $from_version) {
        $file   = 'the-paste/index.php';
        $active = in_array($file, (array) get_option('active_plugins', array()), true);
        if (!$active && is_multisite()) {
            $active = array_key_exists($file, (array) get_site_option('active_sitewide_plugins', array()));
        }
        if ($active) {
            $options = self::import_setting($options, self::KEY, true);
        }

        $theirs = get_option('the_paste');
        if (!is_array($theirs)) {
            return $options;
        }
        if (isset($theirs['tinymce_enabled'])) {
            $options = self::import_setting($options, 'paste_media_editor', (bool) $theirs['tinymce_enabled']);
        }
        if (isset($theirs['image_quality']) && is_numeric($theirs['image_quality'])) {
            $options = self::import_setting($options, 'paste_media_quality', (int) $theirs['image_quality']);
        }
        if (isset($theirs['default_filename'])) {
            $options = self::import_setting($options, 'paste_media_filename', self::convert_filename($theirs['default_filename']));
        }
        return $options;
    }

    /**
     * Convert The Paste's file name template to our tokens. Returns null
     * (skip) when it uses placeholders we have no match for.
     *
     * @param mixed $template The Paste template.
     * @return string|null
     */
    private static function convert_filename($template) {
        $template = trim((string) $template);
        if ('' === $template) {
            return null;
        }
        $template = strtr($template, array(
            '<postname>' => '%post_title%',
            '<username>' => '%user%',
            '%Y-%m-%d'   => '%date%',
            '%H-%M-%S'   => '%time%',
        ));
        $left = preg_replace('/%(post_title|user|date|time)%/', '', $template);
        if (false !== strpos($left, '<') || false !== strpos($left, '%')) {
            return null;
        }
        return $template;
    }

    /**
     * Register hooks.
     */
    public static function boot() {
        if (!self::enabled() || !is_admin()) {
            return;
        }
        add_action('wp_enqueue_media', array(__CLASS__, 'enqueue_media'));
        add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue_upload_screen'));
    }

    /**
     * Pages that load the media dialog or the media grid.
     */
    public static function enqueue_media() {
        self::enqueue(array('jquery', 'media-views', 'wp-api-fetch'));
    }

    /**
     * Media → Add New Media File uses core's plain uploader.
     *
     * @param string $hook_suffix Admin page.
     */
    public static function enqueue_upload_screen($hook_suffix) {
        if ('media-new.php' === $hook_suffix) {
            self::enqueue(array('jquery', 'plupload-handlers'));
        }
    }

    /**
     * Add the script once.
     *
     * @param string[] $deps Script dependencies.
     */
    private static function enqueue(array $deps) {
        if (!current_user_can('upload_files') || wp_script_is(self::HANDLE, 'registered')) {
            return;
        }

        $user     = wp_get_current_user();
        $format   = SEOProStack_Settings::get('paste_media_format');
        $types    = array(
            'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
        );
        $mime     = isset($types[$format]) ? $types[$format] : '';
        $allowed  = get_allowed_mime_types($user);
        if ('' !== $mime && !in_array($mime, $allowed, true)) {
            $mime = 'image/jpeg';
        }

        $data = array(
            // The Paste handles the classic editor itself while it is active.
            'editor'   => (bool) SEOProStack_Settings::get('paste_media_editor') && !class_exists('ThePaste\\Core\\Core', false),
            'filename' => (string) SEOProStack_Settings::get('paste_media_filename'),
            'fallback' => 'pasted-image',
            'user'     => $user->display_name,
            'format'   => $mime,
            'quality'  => (int) SEOProStack_Settings::get('paste_media_quality'),
            'postId'   => self::post_id(),
            'uploading' => __('Uploading pasted image…', 'seoprostack'),
            /* translators: %s: error message */
            'failed'   => __('The pasted image could not be uploaded: %s', 'seoprostack'),
        );

        wp_register_script(self::HANDLE, false, $deps, SEOPROSTACK_VERSION, true);
        wp_enqueue_script(self::HANDLE);
        wp_add_inline_script(self::HANDLE, 'window.seoprostackPaste = ' . wp_json_encode($data) . ';' . "\n" . self::script());
    }

    /**
     * ID of the post being edited, for attaching uploads.
     *
     * @return int
     */
    private static function post_id() {
        $post = get_post();
        return ($post && current_user_can('edit_post', $post->ID)) ? (int) $post->ID : 0;
    }

    /**
     * The client script.
     *
     * @return string
     */
    private static function script() {
        return <<<'JS'
(function ($, cfg) {
    "use strict";

    var generic = /^(image|blob)?(\.(png|jpe?g|gif|webp|bmp|tiff?))?$/i;
    var extensions = { "image/png": "png", "image/jpeg": "jpg", "image/gif": "gif", "image/webp": "webp", "image/bmp": "bmp", "image/tiff": "tif" };
    var pad = function (n) { return (n < 10 ? "0" : "") + n; };

    function postTitle() {
        var title = "";
        try {
            if (window.wp && wp.data && wp.data.select("core/editor")) {
                title = wp.data.select("core/editor").getEditedPostAttribute("title") || "";
            }
        } catch (e) {}
        if (!title) {
            title = $("#title").val() || "";
        }
        return title;
    }

    function baseName() {
        var now = new Date();
        var name = cfg.filename
            .replace(/%post_title%/g, postTitle())
            .replace(/%user%/g, cfg.user || "")
            .replace(/%date%/g, now.getFullYear() + "-" + pad(now.getMonth() + 1) + "-" + pad(now.getDate()))
            .replace(/%time%/g, pad(now.getHours()) + "-" + pad(now.getMinutes()) + "-" + pad(now.getSeconds()));
        name = name.replace(/[\\\/:*?"<>|#%]+/g, " ").replace(/\s+/g, " ").replace(/^[\s.-]+|[\s.-]+$/g, "");
        return name || cfg.fallback;
    }

    // Pictures from the clipboard (screenshots, copied images) have no real name.
    function isPicture(file) {
        return /^image\//.test(file.type) && generic.test(file.name || "");
    }

    function convert(file) {
        var keep = Promise.resolve(file);
        if (!cfg.format || cfg.format === file.type || !/^image\/(png|bmp|tiff|jpeg|webp)$/.test(file.type) || !window.createImageBitmap) {
            return keep;
        }
        return createImageBitmap(file).then(function (bitmap) {
            var canvas = document.createElement("canvas");
            canvas.width = bitmap.width;
            canvas.height = bitmap.height;
            var ctx = canvas.getContext("2d");
            if (cfg.format === "image/jpeg") {
                ctx.fillStyle = "#fff";
                ctx.fillRect(0, 0, canvas.width, canvas.height);
            }
            ctx.drawImage(bitmap, 0, 0);
            return new Promise(function (resolve) {
                canvas.toBlob(function (blob) {
                    // Browsers that cannot write the format return PNG instead.
                    resolve(blob && blob.type === cfg.format && blob.size < file.size ? blob : file);
                }, cfg.format, cfg.quality / 100);
            });
        }).catch(function () {
            return file;
        });
    }

    function prepare(file) {
        if (!isPicture(file)) {
            return Promise.resolve(file);
        }
        return convert(file).then(function (blob) {
            var type = blob.type || file.type;
            return new File([blob], baseName() + "." + (extensions[type] || "png"), { type: type });
        });
    }

    function clipboardFiles(data) {
        var files = [];
        if (!data) {
            return files;
        }
        if (data.items && data.items.length) {
            $.each(data.items, function (i, item) {
                if (item.kind === "file") {
                    var file = item.getAsFile();
                    if (file) {
                        files.push(file);
                    }
                }
            });
        } else if (data.files) {
            files = Array.prototype.slice.call(data.files);
        }
        return files;
    }

    function isField(el) {
        return el && (el.isContentEditable || /^(input|textarea|select)$/i.test(el.nodeName));
    }

    /* Media Library screen, media dialog, Add New Media File --------------- */

    var windows = [];
    if (window.wp && wp.media && wp.media.view && wp.media.view.UploaderWindow) {
        var ready = wp.media.view.UploaderWindow.prototype.ready;
        wp.media.view.UploaderWindow.prototype.ready = function () {
            var result = ready.apply(this, arguments);
            if (windows.indexOf(this) < 0) {
                windows.push(this);
            }
            return result;
        };
    }

    function visibleUploader() {
        var views = windows.slice();
        if (window.wp && wp.media && wp.media.frame && wp.media.frame.uploader && views.indexOf(wp.media.frame.uploader) < 0) {
            views.push(wp.media.frame.uploader);
        }
        for (var i = views.length - 1; i >= 0; i--) {
            var view = views[i];
            var frame = view.controller;
            var $el = frame && (frame.modal ? frame.modal.$el : frame.$el);
            if ($el && $el.is(":visible") && view.uploader && view.uploader.uploader) {
                return view.uploader.uploader;
            }
        }
        if (window.uploader && window.uploader.addFile && $("#plupload-upload-ui").is(":visible")) {
            return window.uploader;
        }
        return null;
    }

    // On window, after other handlers: skip pastes that something else took.
    window.addEventListener("paste", function (event) {
        if (event.defaultPrevented || isField(event.target)) {
            return;
        }
        var files = clipboardFiles(event.clipboardData);
        var uploader = files.length && visibleUploader();
        if (!uploader) {
            return;
        }
        event.preventDefault();
        Promise.all(files.map(prepare)).then(function (ready) {
            ready.forEach(function (file) {
                uploader.addFile(file);
            });
        });
    });

    /* Classic editor -------------------------------------------------------- */

    // Pasting from a web page or an office app also puts HTML or text on the
    // clipboard; that is left to the editor unless it is just the image.
    function onlyPictures(data) {
        if (!data || !data.types) {
            return true;
        }
        var types = Array.prototype.slice.call(data.types);
        if (types.indexOf("text/plain") > -1 && data.getData("text/plain").trim()) {
            return false;
        }
        if (types.indexOf("text/html") > -1) {
            var doc = new DOMParser().parseFromString(data.getData("text/html"), "text/html");
            if (doc.body.textContent.trim() || doc.body.querySelectorAll("img").length > 1) {
                return false;
            }
        }
        return true;
    }

    function imageHtml(media) {
        var sizes = (media.media_details && media.media_details.sizes) || {};
        var size = sizes.large ? "large" : "full";
        var src = sizes.large ? sizes.large.source_url : media.source_url;
        var width = sizes.large ? sizes.large.width : (media.media_details && media.media_details.width);
        var height = sizes.large ? sizes.large.height : (media.media_details && media.media_details.height);
        var $img = $("<img>").attr({
            src: src,
            alt: media.alt_text || "",
            "class": "alignnone size-" + size + " wp-image-" + media.id
        });
        if (width && height) {
            $img.attr({ width: width, height: height });
        }
        return $img[0].outerHTML;
    }

    function uploadToEditor(editor, file, marker) {
        var body = new FormData();
        body.append("file", file, file.name);
        if (cfg.postId) {
            body.append("post", cfg.postId);
        }
        return wp.apiFetch({ path: "/wp/v2/media", method: "POST", body: body }).then(function (media) {
            var el = editor.dom.get(marker);
            if (el) {
                editor.dom.setOuterHTML(el, imageHtml(media));
                editor.undoManager.add();
                editor.nodeChanged();
            }
        }).catch(function (error) {
            editor.dom.remove(marker);
            var text = cfg.failed.replace("%s", (error && error.message) || "");
            if (editor.notificationManager) {
                editor.notificationManager.open({ text: text, type: "error" });
            } else {
                window.alert(text);
            }
        });
    }

    var count = 0;
    function setupEditor(editor) {
        if (!cfg.editor || !window.wp || !wp.apiFetch || editor.seoprostackPaste) {
            return;
        }
        editor.seoprostackPaste = true;
        // Prepended so it runs before the paste plugin, which then only tidies up.
        editor.on("paste", function (event) {
            var data = event.clipboardData;
            var files = clipboardFiles(data).filter(function (file) {
                return /^image\//.test(file.type);
            });
            if (!files.length || !onlyPictures(data)) {
                return;
            }
            event.preventDefault();
            // Wait for the paste plugin to put the cursor back.
            window.setTimeout(function () {
                files.forEach(function (file) {
                    var marker = "sps-paste-" + (++count);
                    editor.insertContent('<span id="' + marker + '" class="sps-paste-uploading" data-mce-bogus="all" contenteditable="false">' + $("<i>").text(cfg.uploading).html() + "</span>&nbsp;");
                    prepare(file).then(function (ready) {
                        return uploadToEditor(editor, ready, marker);
                    });
                });
            }, 0);
        }, true);
    }

    $(document).on("tinymce-editor-setup", function (event, editor) {
        setupEditor(editor);
    });
    if (window.tinymce && tinymce.editors) {
        $.each(tinymce.editors, function (i, editor) {
            setupEditor(editor);
        });
    }
})(jQuery, window.seoprostackPaste);
JS;
    }
}
