/**
 * Order by hand: drag rows by their handle, or focus a handle and press the
 * up or down arrow key. The rows shown are saved in their new order.
 */
(function ($, cfg) {
    if (!cfg) {
        return;
    }
    var $list = $('#the-list');
    if (!$list.length) {
        return;
    }
    // Hierarchical lists show children under their parent: reload after a
    // move so the tree is drawn again in the saved order.
    var tree = $list.children('tr').filter(function () {
        return /(^|\s)level-[1-9]/.test(this.className);
    }).length > 0;

    function ids() {
        return $list.children('tr').map(function () {
            var m = /^(?:post|tag)-(\d+)$/.exec(this.id || '');
            return m ? m[1] : null;
        }).get();
    }

    function speak(text) {
        if (window.wp && wp.a11y) {
            wp.a11y.speak(text);
        }
    }

    function save(focusRow) {
        $list.addClass('seoprostack-order-busy').attr('aria-busy', 'true');
        $.post(cfg.ajaxUrl, { action: cfg.action, nonce: cfg.nonce, kind: cfg.kind, object: cfg.object, ids: ids() })
            .done(function (res) {
                if (!res || !res.success) {
                    speak(cfg.failed);
                    window.alert(cfg.failed); // eslint-disable-line no-alert
                    return;
                }
                speak(cfg.saved);
                if (tree) {
                    window.location.reload();
                }
            })
            .fail(function () {
                speak(cfg.failed);
                window.alert(cfg.failed); // eslint-disable-line no-alert
            })
            .always(function () {
                $list.removeClass('seoprostack-order-busy').removeAttr('aria-busy');
                if (focusRow) {
                    $(focusRow).find('.seoprostack-order-handle').trigger('focus');
                }
            });
    }

    $list.sortable({
        items: '> tr',
        handle: '.seoprostack-order-handle',
        // The handle is a button (for the keyboard); jQuery UI skips buttons by default.
        cancel: 'input, textarea, select, option, a',
        axis: 'y',
        cursor: 'grabbing',
        placeholder: 'seoprostack-order-placeholder',
        helper: function (e, tr) {
            // Keep the cells' widths while the row is dragged.
            var $cells = tr.children();
            var $helper = tr.clone();
            $helper.children().each(function (i) {
                $(this).width($cells.eq(i).width());
            });
            return $helper;
        },
        start: function (e, ui) {
            ui.placeholder.html('<td colspan="' + ui.item.children('td,th').length + '">&nbsp;</td>');
        },
        update: function () {
            $list.children('tr').removeClass('alternate');
            save(null);
        }
    });

    $list.on('keydown', '.seoprostack-order-handle', function (e) {
        var up = e.key === 'ArrowUp', down = e.key === 'ArrowDown';
        if (!up && !down) {
            return;
        }
        e.preventDefault();
        var $row = $(this).closest('tr');
        var $to = up ? $row.prevAll('tr').first() : $row.nextAll('tr').first();
        if (!$to.length) {
            return;
        }
        if (up) {
            $row.insertBefore($to);
        } else {
            $row.insertAfter($to);
        }
        save($row[0]);
    });
})(jQuery, window.seoprostackHandOrder);
