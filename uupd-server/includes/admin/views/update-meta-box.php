<?php
/**
 * Update Meta Box View
 *
 * Variables provided by the parent class:
 * $post, $slug, $version, $tested, $homepage, $remote_url,
 * $min_wp, $min_php, $licensed_product, $license_prefix,
 * $loader_menu_location, $loader_menu_slug, $loader_page_title,
 * $loader_menu_title, $loader_parent_slug, $loader_inline_box,
 * $packages, $files,
 * $icon_128_id, $icon_128_url, $icon_256_id, $icon_256_url,
 * $banner_773_id, $banner_773_url, $banner_1544_id, $banner_1544_url,
 * $changelog
 */
?>

<table class="form-table">

    <!-- BASIC FIELDS -->
    <?php foreach ( [ 'slug', 'version', 'tested', 'homepage', 'remote_url' ] as $f ) : ?>
        <tr>
            <th><label for="uupd_<?php echo esc_attr( $f ); ?>"><?php echo esc_html( ucfirst( str_replace( '_', ' ', $f ) ) ); ?></label></th>
            <td>
                <input type="text"
                    id="uupd_<?php echo esc_attr( $f ); ?>"
                    name="uupd_<?php echo esc_attr( $f ); ?>"
                    value="<?php echo esc_attr( $$f ); ?>"
                    class="regular-text">
            </td>
        </tr>
    <?php endforeach; ?>

    <!-- WP & PHP MINIMUM REQUIREMENTS -->
    <tr>
        <th><label for="uupd_min_wp_version"><?php esc_html_e( 'Min WP Version', 'uupd-server' ); ?></label></th>
        <td>
            <input type="text"
                   id="uupd_min_wp_version"
                   name="uupd_min_wp_version"
                   value="<?php echo esc_attr( $min_wp ); ?>"
                   class="regular-text"
                   placeholder="e.g. 5.0">
        </td>
    </tr>

    <tr>
        <th><label for="uupd_min_php_version"><?php esc_html_e( 'Min PHP Version', 'uupd-server' ); ?></label></th>
        <td>
            <input type="text"
                   id="uupd_min_php_version"
                   name="uupd_min_php_version"
                   value="<?php echo esc_attr( $min_php ); ?>"
                   class="regular-text"
                   placeholder="e.g. 7.2">
        </td>
    </tr>

    <!-- LICENSE SETTINGS -->
    <tr>
        <th><?php esc_html_e( 'Licensed product', 'uupd-server' ); ?></th>
        <td>
            <label>
                <input type="checkbox" name="uupd_licensed_product" value="1" <?php checked( $licensed_product ); ?>>
                <?php esc_html_e( 'This update requires a license.', 'uupd-server' ); ?>
            </label>
        </td>
    </tr>

    <tr>
        <th><label for="uupd_license_prefix"><?php esc_html_e( 'License key prefix', 'uupd-server' ); ?></label></th>
        <td>
            <input type="text"
                   id="uupd_license_prefix"
                   name="uupd_license_prefix"
                   value="<?php echo esc_attr( $license_prefix ); ?>"
                   class="regular-text"
                   placeholder="e.g. MYPLUGIN-">
            <p class="description"><?php esc_html_e( 'Used when generating license keys.', 'uupd-server' ); ?></p>
        </td>
    </tr>

    <!-- LOADER OPTIONS -->
    <tr>
        <th colspan="2">
            <h2><?php esc_html_e( 'Loader options', 'uupd-server' ); ?></h2>
            <p class="description">
                <?php esc_html_e( 'These settings only affect the generated loader code.', 'uupd-server' ); ?>
            </p>
        </th>
    </tr>

    <tr>
        <th><label for="uupd_loader_menu_location"><?php esc_html_e( 'License page location', 'uupd-server' ); ?></label></th>
        <td>
            <select name="uupd_loader_menu_location" id="uupd_loader_menu_location">
                <option value="settings"     <?php selected( $loader_menu_location, 'settings' ); ?>    >Settings → General</option>
                <option value="tools"        <?php selected( $loader_menu_location, 'tools' ); ?>       >Tools</option>
                <option value="top_level"    <?php selected( $loader_menu_location, 'top_level' ); ?>   >Top-level menu</option>
                <option value="custom_parent"<?php selected( $loader_menu_location, 'custom_parent' ); ?>>Custom parent</option>
                <option value="none"         <?php selected( $loader_menu_location, 'none' ); ?>        >No menu (inline only)</option>
            </select>
        </td>
    </tr>

    <tr id="uupd_loader_parent_slug_row" style="<?php echo $loader_menu_location === 'custom_parent' ? '' : 'display:none;'; ?>">
        <th><label for="uupd_loader_parent_slug"><?php esc_html_e( 'Parent menu slug', 'uupd-server' ); ?></label></th>
        <td>
            <input type="text"
                   id="uupd_loader_parent_slug"
                   name="uupd_loader_parent_slug"
                   value="<?php echo esc_attr( $loader_parent_slug ); ?>"
                   class="regular-text"
                   placeholder="e.g. plugins.php, woocommerce, options-general.php">
        </td>
    </tr>

    <tr>
        <th><label for="uupd_loader_menu_slug"><?php esc_html_e( 'Menu slug', 'uupd-server' ); ?></label></th>
        <td>
            <input type="text"
                   id="uupd_loader_menu_slug"
                   name="uupd_loader_menu_slug"
                   value="<?php echo esc_attr( $loader_menu_slug ); ?>"
                   class="regular-text"
                   placeholder="<?php echo esc_attr( $slug . '-license' ); ?>">
        </td>
    </tr>

    <tr>
        <th><label for="uupd_loader_page_title"><?php esc_html_e( 'Page title', 'uupd-server' ); ?></label></th>
        <td>
            <input type="text"
                   id="uupd_loader_page_title"
                   name="uupd_loader_page_title"
                   value="<?php echo esc_attr( $loader_page_title ); ?>"
                   class="regular-text"
                   placeholder="<?php echo esc_attr( $post->post_title . ' License' ); ?>">
        </td>
    </tr>

    <tr>
        <th><label for="uupd_loader_menu_title"><?php esc_html_e( 'Menu title', 'uupd-server' ); ?></label></th>
        <td>
            <input type="text"
                   id="uupd_loader_menu_title"
                   name="uupd_loader_menu_title"
                   value="<?php echo esc_attr( $loader_menu_title ); ?>"
                   class="regular-text"
                   placeholder="<?php esc_attr_e( 'License', 'uupd-server' ); ?>">
        </td>
    </tr>

    <tr>
        <th><?php esc_html_e( 'Inline license box', 'uupd-server' ); ?></th>
        <td>
            <label>
                <input type="checkbox"
                       id="uupd_loader_inline_box"
                       name="uupd_loader_inline_box"
                       value="1"
                       <?php checked( $loader_inline_box ); ?>>
                <?php esc_html_e( 'Enable inline box snippet generation.', 'uupd-server' ); ?>
            </label>
        </td>
    </tr>

    <!-- PACKAGES -->
    <tr>
        <th><?php esc_html_e( 'Packages', 'uupd-server' ); ?></th>
        <td>
            <table class="widefat uupd-subtable" id="uupd-packages-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'ID', 'uupd-server' ); ?></th>
                        <th><?php esc_html_e( 'Label', 'uupd-server' ); ?></th>
                        <th><?php esc_html_e( 'Activation limit', 'uupd-server' ); ?></th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $packages as $idx => $p ) : ?>
                    <tr>
                        <td><input type="text" name="uupd_packages[<?php echo $idx; ?>][id]" value="<?php echo esc_attr( $p['id'] ); ?>"></td>
                        <td><input type="text" name="uupd_packages[<?php echo $idx; ?>][label]" value="<?php echo esc_attr( $p['label'] ); ?>"></td>
                        <td><input type="number" min="0" name="uupd_packages[<?php echo $idx; ?>][activation_limit]" value="<?php echo esc_attr( $p['activation_limit'] ); ?>"></td>
                        <td><button type="button" class="button uupd-remove-package">&times;</button></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <button type="button" class="button" id="uupd-add-package">
                <?php esc_html_e( 'Add package', 'uupd-server' ); ?>
            </button>
        </td>
    </tr>

    <!-- ICONS -->
    <tr><th colspan="2"><h2><?php esc_html_e( 'Icons', 'uupd-server' ); ?></h2></th></tr>

    <?php foreach ( [ ['128','icon_128'], ['256','icon_256'] ] as $entry ) :
        list( $size, $field ) = $entry; ?>
        <tr>
            <th><label><?php echo "$size × $size"; ?></label></th>
            <td>
                <input type="url" name="uupd_<?php echo $field; ?>_url" id="uupd_<?php echo $field; ?>_url"
                       value="<?php echo esc_attr( ${$field . '_url'} ); ?>" class="regular-text">
                <input type="hidden" name="uupd_<?php echo $field; ?>_id" id="uupd_<?php echo $field; ?>_id"
                       value="<?php echo esc_attr( ${$field . '_id'} ); ?>">
                <button type="button" class="button" id="uupd_<?php echo $field; ?>_button">
                    <?php esc_html_e( 'Select/Upload', 'uupd-server' ); ?>
                </button>
            </td>
        </tr>
    <?php endforeach; ?>

    <!-- BANNERS -->
    <tr><th colspan="2"><h2><?php esc_html_e( 'Banners', 'uupd-server' ); ?></h2></th></tr>

    <?php foreach ( [ ['773×250','banner_773'], ['1544×500','banner_1544'] ] as $entry ) :
        list( $label, $field ) = $entry; ?>
        <tr>
            <th><label><?php echo $label; ?></label></th>
            <td>
                <input type="url" name="uupd_<?php echo $field; ?>_url" id="uupd_<?php echo $field; ?>_url"
                       value="<?php echo esc_attr( ${$field . '_url'} ); ?>" class="regular-text">
                <input type="hidden" name="uupd_<?php echo $field; ?>_id" id="uupd_<?php echo $field; ?>_id"
                       value="<?php echo esc_attr( ${$field . '_id'} ); ?>">
                <button type="button" class="button" id="uupd_<?php echo $field; ?>_button">
                    <?php esc_html_e( 'Select/Upload', 'uupd-server' ); ?>
                </button>
            </td>
        </tr>
    <?php endforeach; ?>

    <!-- CHANGELOG -->
    <tr>
        <th><label for="uupd_changelog_html"><?php esc_html_e( 'Changelog', 'uupd-server' ); ?></label></th>
        <td>
            <?php wp_editor( $changelog, 'uupd_changelog_html', [
                'textarea_name' => 'uupd_changelog_html',
                'textarea_rows' => 10,
            ] ); ?>
        </td>
    </tr>

    <!-- FILES -->
    <tr>
        <th><?php esc_html_e( 'Files (top is used for update)', 'uupd-server' ); ?></th>
        <td>
            <table class="widefat" id="uupd-files-table">
                <thead>
                    <tr>
                        <th style="width:40px;"><?php esc_html_e( 'Order', 'uupd-server' ); ?></th>
                        <th><?php esc_html_e( 'Name', 'uupd-server' ); ?></th>
                        <th><?php esc_html_e( 'Download file', 'uupd-server' ); ?></th>
                        <th style="width:80px;"></th>
                    </tr>
                </thead>
                <tbody id="uupd-files-list">
                <?php foreach ( $files as $index => $file ) :
                    $att_id = intval( $file['id'] );
                    $url    = wp_get_attachment_url( $att_id );
                    $name   = $url ? wp_basename( $url ) : get_the_title( $att_id );
                ?>
                    <tr class="uupd-file-item">
                        <td class="uupd-file-drag"><span class="dashicons dashicons-move"></span></td>
                        <td><strong><?php echo esc_html( get_the_title( $att_id ) ); ?></strong></td>
                        <td>
                            <?php if ( $url ) : ?>
                                <a href="<?php echo esc_url( $url ); ?>" target="_blank"><?php echo esc_html( $name ); ?></a>
                            <?php else : ?>
                                <em><?php esc_html_e( 'Missing file', 'uupd-server' ); ?></em>
                            <?php endif; ?>
                        </td>
                        <td>
                            <input type="hidden" name="uupd_files[<?php echo $index; ?>][id]" value="<?php echo $att_id; ?>">
                            <button type="button" class="button-link uupd-remove-file">
                                <?php esc_html_e( 'Remove', 'uupd-server' ); ?>
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <button type="button" class="button" id="uupd-add-file">
                <?php esc_html_e( 'Add file', 'uupd-server' ); ?>
            </button>

            <p class="description">
                <?php esc_html_e( 'Drag rows to reorder. Top = update file.', 'uupd-server' ); ?>
            </p>
        </td>
    </tr>

</table>

<style>
    .uupd-subtable td, .uupd-subtable th { padding: 6px; }
    .uupd-file-drag { cursor: move; text-align: center; }
</style>

<script>
(function($){
    function toggleParentSlug(){
        if ($('#uupd_loader_menu_location').val() === 'custom_parent') {
            $('#uupd_loader_parent_slug_row').show();
        } else {
            $('#uupd_loader_parent_slug_row').hide();
        }
    }
    $(document).ready(toggleParentSlug);
    $(document).on('change','#uupd_loader_menu_location',toggleParentSlug);
})(jQuery);
</script>
