<?php
/**
 * JavaScript loaded inline on the Update post edit screen.
 * Handles:
 * - Media selection for icons / banners
 * - Adding/removing files
 * - Sorting files
 * - Adding/removing packages
 */
?>

jQuery(function($){

    /*----------------------------------------------------------------------
     * MEDIA SELECTORS (all icon/banner buttons)
     *----------------------------------------------------------------------*/

    function bindMediaButton(id){
        var button = $('#uupd_' + id + '_button');
        var urlFld = $('#uupd_' + id + '_url');
        var idFld  = $('#uupd_' + id + '_id');

        if (!button.length) return;

        button.on('click', function(e){
            e.preventDefault();

            var frame = wp.media({
                title: 'Select file',
                button: { text: 'Use this file' },
                multiple: false
            });

            frame.on('select', function(){
                var a = frame.state().get('selection').first().toJSON();
                urlFld.val(a.url);
                idFld.val(a.id);
            });

            frame.open();
        });
    }

    bindMediaButton('icon_128');
    bindMediaButton('icon_256');
    bindMediaButton('banner_773');
    bindMediaButton('banner_1544');


    /*----------------------------------------------------------------------
     * PACKAGES
     *----------------------------------------------------------------------*/

    $('#uupd-add-package').on('click', function(){
        var tbody = $('#uupd-packages-table tbody');
        var idx   = tbody.find('tr').length;

        var row = '<tr>'+
            '<td><input type="text" name="uupd_packages['+idx+'][id]" value=""></td>'+
            '<td><input type="text" name="uupd_packages['+idx+'][label]" value=""></td>'+
            '<td><input type="number" min="0" name="uupd_packages['+idx+'][activation_limit]" value="0"></td>'+
            '<td><button type="button" class="button uupd-remove-package">&times;</button></td>'+
        '</tr>';

        tbody.append(row);
    });

    $(document).on('click', '.uupd-remove-package', function(){
        $(this).closest('tr').remove();

        // reindex
        $('#uupd-packages-table tbody tr').each(function(i){
            $(this).find('input').each(function(){
                var name = $(this).attr('name');
                if (!name) return;
                name = name.replace(/uupd_packages\[\d+\]/, 'uupd_packages['+i+']');
                $(this).attr('name', name);
            });
        });
    });


    /*----------------------------------------------------------------------
     * FILES – ADD
     *----------------------------------------------------------------------*/

    $('#uupd-add-file').on('click', function(e){
        e.preventDefault();

        var frame = wp.media({
            title: 'Select file',
            button: { text: 'Add file' },
            multiple: false
        });

        frame.on('select', function(){
            var a = frame.state().get('selection').first().toJSON();
            addFileRow(a.id, a.url);
        });

        frame.open();
    });

    function addFileRow(id, url){
        var tbody = $('#uupd-files-list');

        var name = url ? url.split('/').pop() : ('File ' + id);

        var row =
            '<tr class="uupd-file-item">'+
                '<td class="uupd-file-drag"><span class="dashicons dashicons-move"></span></td>'+
                '<td><strong>'+name+'</strong></td>'+
                '<td><a href="'+url+'" target="_blank">'+name+'</a></td>'+
                '<td>'+
                    '<input type="hidden" name="uupd_files[0][id]" value="'+id+'">'+
                    '<button type="button" class="button-link uupd-remove-file">Remove</button>'+
                '</td>'+
            '</tr>';

        // Put newest file at the TOP
        tbody.prepend(row);

        // Fix the [index] parts of the names so PHP sees correct order
        reindexFiles();
    }



    /*----------------------------------------------------------------------
     * FILES – REMOVE
     *----------------------------------------------------------------------*/

    $(document).on('click', '.uupd-remove-file', function(){
        $(this).closest('tr').remove();
        reindexFiles();
    });


    /*----------------------------------------------------------------------
     * FILES – SORTING
     *----------------------------------------------------------------------*/

    $('#uupd-files-list').sortable({
        handle: '.uupd-file-drag',
        items: '> tr.uupd-file-item',
        update: reindexFiles
    });

    function reindexFiles(){
        $('#uupd-files-list tr').each(function(i){
            $(this).find('input[type=hidden]').each(function(){
                var name = $(this).attr('name');
                name = name.replace(/uupd_files\[\d+\]/, 'uupd_files['+i+']');
                $(this).attr('name', name);
            });
        });
    }

});
