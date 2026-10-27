<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Customer key management, available as a shortcode and a dynamic block. */
function uupd_key_result_message( $code ) {
    switch ( $code ) {
        case 'success': return __( 'Licence updated.', 'uupd-server' );
        case 'rotated': return __( 'Key rotated. Update installations that use the old key.', 'uupd-server' );
        case 'child_created': return __( 'Child key created. Copy it from the list below.', 'uupd-server' );
        case 'child_updated': return __( 'Child key details saved.', 'uupd-server' );
        case 'child_revoked': return __( 'Child key revoked. Its activations were released; the child slot remains occupied.', 'uupd-server' );
        case 'child_deleted': return __( 'Child key deleted. A child slot is now available.', 'uupd-server' );
        case 'child_deactivated': return __( 'The site was disconnected from this child key.', 'uupd-server' );
        case 'uupd_child_count_limit': return __( 'This licence has reached its child key limit. Delete a child key to free a slot.', 'uupd-server' );
        case 'uupd_invalid_child_limit': return __( 'The child limit must be positive, cover its current activations, and fit within the master limit.', 'uupd-server' );
        case 'uupd_invalid_domain': return __( 'That site is no longer connected to this child key.', 'uupd-server' );
        case 'uupd_children_disabled': return __( 'Child creation is unavailable for this licence.', 'uupd-server' );
        case 'uupd_rotation_disabled': return __( 'Key rotation is unavailable for this licence.', 'uupd-server' );
        default: return __( 'The key action could not be completed.', 'uupd-server' );
    }
}

function uupd_license_key_manager_available( $license_id ) {
    $usable = uupd_license_is_usable( $license_id );
    if ( $usable && uupd_license_feature_enabled( $license_id, 'rotate' ) ) { return true; }
    if ( get_post_meta( $license_id, 'uupd_parent_license_id', true ) ) { return false; }
    return ( $usable && uupd_license_feature_enabled( $license_id, 'children' ) ) || (bool) uupd_license_child_ids( $license_id );
}

function uupd_license_key_manager_label( $license_id ) {
    $can_rotate = uupd_license_is_usable( $license_id ) && uupd_license_feature_enabled( $license_id, 'rotate' );
    $has_children = ! get_post_meta( $license_id, 'uupd_parent_license_id', true ) && ( ( uupd_license_is_usable( $license_id ) && uupd_license_feature_enabled( $license_id, 'children' ) ) || uupd_license_child_ids( $license_id ) );
    if ( $can_rotate && $has_children ) { return __( 'Rotate key or manage child keys', 'uupd-server' ); }
    if ( $has_children ) { return __( 'Manage child keys', 'uupd-server' ); }
    return __( 'Rotate key', 'uupd-server' );
}

function uupd_license_keys_shortcode( $atts = array() ) {
    if ( ! is_user_logged_in() ) { return '<p>' . esc_html__( 'Log in to manage your licence keys.', 'uupd-server' ) . '</p>'; }
    $atts = shortcode_atts( array( 'license_id' => 0 ), $atts, 'uupd_license_keys' );
    $ids = absint( $atts['license_id'] ) ? array( absint( $atts['license_id'] ) ) : get_posts( array(
        'post_type' => 'uupd_license', 'post_status' => 'publish', 'fields' => 'ids', 'posts_per_page' => -1,
        'meta_key' => 'user_id', 'meta_value' => get_current_user_id(),
    ) );
    $html = '<div class="uupd-license-manager">';
    $shown = 0;
    $owned = 0;
    foreach ( $ids as $id ) {
        if ( get_current_user_id() !== absint( get_post_meta( $id, 'user_id', true ) ) || 'uupd_license' !== get_post_type( $id ) ) { continue; }
        $parent_id = absint( get_post_meta( $id, 'uupd_parent_license_id', true ) );
        if ( $parent_id && ! absint( $atts['license_id'] ) ) { continue; }
        $owned++;
        $usable = uupd_license_is_usable( $id );
        $children = $parent_id ? array() : uupd_license_child_ids( $id );
        $can_rotate = $usable && uupd_license_feature_enabled( $id, 'rotate' );
        $can_create_children = ! $parent_id && $usable && uupd_license_feature_enabled( $id, 'children' );
        if ( ! uupd_license_key_manager_available( $id ) ) { continue; }
        $shown++;
        $max_children = $parent_id ? 0 : uupd_license_max_children( $id );
        $master_id = $parent_id ?: $id;
        $master_limit = uupd_license_master_limit( $master_id );
        $usage = uupd_license_family_usage( $master_id );
        $html .= '<article class="uupd-key-card" id="uupd-key-manager-' . esc_attr( $id ) . '">';
        $html .= '<header class="uupd-key-card__header"><div><span class="uupd-key-card__eyebrow">' . esc_html( $parent_id ? __( 'Child key', 'uupd-server' ) : __( 'Master licence', 'uupd-server' ) ) . '</span><h3>' . esc_html( get_the_title( get_post_meta( $master_id, 'update_id', true ) ) ) . '</h3></div><span class="uupd-key-status ' . ( $usable ? 'is-active' : 'is-inactive' ) . '">' . esc_html( $usable ? __( 'Active', 'uupd-server' ) : __( 'Inactive', 'uupd-server' ) ) . '</span></header>';
        if ( isset( $_GET['uupd_key_result'], $_GET['uupd_key_license'] ) && absint( $_GET['uupd_key_license'] ) === $id ) {
            $html .= '<div class="uupd-key-notice" role="status">' . esc_html( uupd_key_result_message( sanitize_key( wp_unslash( $_GET['uupd_key_result'] ) ) ) ) . '</div>';
        }
        $html .= '<div class="uupd-key-card__body"><div class="uupd-key-main"><div class="uupd-key-main__value"><span class="uupd-field-label">' . esc_html__( 'Current key', 'uupd-server' ) . '</span><div class="uupd-key-value"><code>' . esc_html( get_post_meta( $id, 'license_key', true ) ) . '</code><button type="button" class="uupd-key-copy" data-uupd-copy="' . esc_attr( get_post_meta( $id, 'license_key', true ) ) . '">' . esc_html__( 'Copy', 'uupd-server' ) . '</button></div></div>';
        if ( $can_rotate ) { $html .= uupd_license_key_action_form( $id, 'rotate', __( 'Rotate key', 'uupd-server' ) ); }
        $html .= '</div>';
        if ( ! $parent_id && ( $can_create_children || $children ) ) {
            $html .= '<div class="uupd-key-stats"><div><span>' . esc_html__( 'Shared activations', 'uupd-server' ) . '</span><strong>' . esc_html( $usage . ' / ' . ( $master_limit ?: __( 'Unlimited', 'uupd-server' ) ) ) . '</strong></div>';
            $html .= '<div><span>' . esc_html__( 'Child keys', 'uupd-server' ) . '</span><strong>' . esc_html( uupd_child_count_label( $id ) ) . '</strong></div></div>';
        }
        if ( ! $parent_id && ( $can_create_children || $children ) ) {
            $html .= '<div class="uupd-child-section"><div class="uupd-child-section__heading"><div><h4>' . esc_html__( 'Child keys', 'uupd-server' ) . '</h4><p>' . esc_html__( 'Give each child a purpose and an activation cap. All keys share the master total.', 'uupd-server' ) . '</p></div></div>';
            if ( $can_create_children && ! uupd_license_child_limit_reached( $id ) ) {
                $html .= '<details class="uupd-create-child"><summary>' . esc_html__( 'Create a child key', 'uupd-server' ) . '</summary>' . uupd_child_action_form( $id, 0, 'create', __( 'Create child key', 'uupd-server' ) ) . '</details>';
            } elseif ( $can_create_children && uupd_license_child_limit_reached( $id ) ) {
                $html .= '<p class="uupd-key-hint">' . esc_html( $max_children ? __( 'All child key slots are in use. Delete a child to free a slot.', 'uupd-server' ) : __( 'Child key creation is disabled by the limit of 0.', 'uupd-server' ) ) . '</p>';
            }
            if ( ! $children ) { $html .= '<p class="uupd-key-empty">' . esc_html__( 'No child keys yet.', 'uupd-server' ) . '</p>'; }
            if ( $children ) {
                $html .= '<div class="uupd-customer-child-browser" data-uupd-customer-child-browser><div class="uupd-customer-child-browser__tools"><label>' . esc_html__( 'Search child keys', 'uupd-server' ) . '<input type="search" data-uupd-child-search placeholder="' . esc_attr__( 'Key, note or site', 'uupd-server' ) . '"></label><label>' . esc_html__( 'Status', 'uupd-server' ) . '<select data-uupd-child-status><option value="all">' . esc_html__( 'All statuses', 'uupd-server' ) . '</option><option value="active">' . esc_html__( 'Active', 'uupd-server' ) . '</option><option value="inactive">' . esc_html__( 'Inactive', 'uupd-server' ) . '</option><option value="revoked">' . esc_html__( 'Revoked', 'uupd-server' ) . '</option></select></label><label>' . esc_html__( 'Site connection', 'uupd-server' ) . '<select data-uupd-child-sites><option value="all">' . esc_html__( 'All children', 'uupd-server' ) . '</option><option value="connected">' . esc_html__( 'Connected sites', 'uupd-server' ) . '</option><option value="unconnected">' . esc_html__( 'No connected sites', 'uupd-server' ) . '</option></select></label></div><p class="uupd-customer-child-browser__count" data-uupd-child-count aria-live="polite"></p><div class="uupd-customer-child-browser__list">';
            }
            foreach ( $children as $child_id ) {
                $domains = get_post_meta( $child_id, 'used_domains', true );
                $used = is_array( $domains ) ? count( $domains ) : 0;
                $note = get_post_meta( $child_id, 'uupd_child_note', true );
                $child_status = get_post_meta( $child_id, 'status', true );
                $filter_status = 'revoked' === $child_status ? 'revoked' : ( uupd_license_is_usable( $child_id ) ? 'active' : 'inactive' );
                $search_text = implode( ' ', array_merge( array( $child_id, get_post_meta( $child_id, 'license_key', true ), $note ), is_array( $domains ) ? $domains : array() ) );
                $html .= '<details class="uupd-child-card" data-uupd-child-row data-uupd-status="' . esc_attr( $filter_status ) . '" data-uupd-connected="' . ( $used ? '1' : '0' ) . '" data-uupd-search="' . esc_attr( $search_text ) . '"><summary class="uupd-child-card__top"><span class="uupd-child-card__identity"><span class="uupd-child-card__title">' . esc_html( $note ?: sprintf( __( 'Child key #%d', 'uupd-server' ), $child_id ) ) . '</span><code>' . esc_html( get_post_meta( $child_id, 'license_key', true ) ) . '</code></span><span class="uupd-key-status ' . ( 'active' === $filter_status ? 'is-active' : 'is-inactive' ) . '">' . esc_html( 'revoked' === $filter_status ? __( 'Revoked', 'uupd-server' ) : ( 'active' === $filter_status ? __( 'Active', 'uupd-server' ) : __( 'Inactive', 'uupd-server' ) ) ) . '</span></summary><div class="uupd-child-card__body"><div class="uupd-key-value"><code>' . esc_html( get_post_meta( $child_id, 'license_key', true ) ) . '</code><button type="button" class="uupd-key-copy" data-uupd-copy="' . esc_attr( get_post_meta( $child_id, 'license_key', true ) ) . '">' . esc_html__( 'Copy', 'uupd-server' ) . '</button></div>';
                $html .= '<p class="uupd-child-card__usage">' . esc_html( $used . ' / ' . uupd_child_activation_limit( $child_id ) ) . ' ' . esc_html__( 'activations used', 'uupd-server' ) . '</p>';
                if ( is_array( $domains ) && $domains ) {
                    $html .= '<div class="uupd-child-sites"><strong>' . esc_html__( 'Connected sites', 'uupd-server' ) . '</strong><ul>';
                    foreach ( $domains as $domain ) {
                        $html .= '<li><code>' . esc_html( $domain ) . '</code>' . uupd_child_action_form( $id, $child_id, 'deactivate', __( 'Disconnect', 'uupd-server' ), $domain ) . '</li>';
                    }
                    $html .= '</ul></div>';
                }
                if ( 'active' === $child_status ) {
                    $html .= '<details class="uupd-edit-child"><summary>' . esc_html__( 'Edit limit or note', 'uupd-server' ) . '</summary>' . uupd_child_action_form( $id, $child_id, 'update', __( 'Save changes', 'uupd-server' ) ) . '</details>';
                }
                $html .= '<div class="uupd-child-card__actions">';
                if ( 'revoked' !== $child_status ) { $html .= uupd_child_action_form( $id, $child_id, 'revoke', __( 'Revoke', 'uupd-server' ) ); }
                $html .= uupd_child_action_form( $id, $child_id, 'delete', __( 'Delete', 'uupd-server' ) ) . '</div></div></details>';
            }
            if ( $children ) { $html .= '</div><p class="uupd-customer-child-browser__empty" data-uupd-child-empty hidden>' . esc_html__( 'No child keys match these filters.', 'uupd-server' ) . '</p></div>'; }
            $html .= '</div>';
        }
        $html .= '</div></article>';
    }
    if ( ! $shown && $owned ) { return ''; }
    if ( ! $shown ) { $html .= '<p class="uupd-key-empty">' . esc_html__( 'No licences found for your account.', 'uupd-server' ) . '</p>'; }
    return $html . '</div>';
}
add_shortcode( 'uupd_license_keys', 'uupd_license_keys_shortcode' );
add_action( 'wp_enqueue_scripts', function () {
    wp_enqueue_style( 'uupd-license-keys', UUPD_SERVER_URL . 'assets/license-keys.css', array(), filemtime( UUPD_SERVER_DIR . 'assets/license-keys.css' ) );
    wp_enqueue_script( 'uupd-license-keys', UUPD_SERVER_URL . 'assets/license-keys.js', array(), filemtime( UUPD_SERVER_DIR . 'assets/license-keys.js' ), true );
} );

function uupd_child_action_form( $master_id, $child_id, $operation, $label, $domain = '' ) {
    $nonce_action = 'uupd_manage_child_' . $master_id . '_' . $child_id . '_' . $operation;
    $html = '<form class="uupd-key-form uupd-key-form--' . esc_attr( $operation ) . '" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'
        . '<input type="hidden" name="action" value="uupd_manage_child">'
        . '<input type="hidden" name="master_id" value="' . esc_attr( $master_id ) . '">'
        . '<input type="hidden" name="child_id" value="' . esc_attr( $child_id ) . '">'
        . '<input type="hidden" name="operation" value="' . esc_attr( $operation ) . '">'
        . ( 'deactivate' === $operation ? '<input type="hidden" name="domain" value="' . esc_attr( $domain ) . '">' : '' )
        . wp_nonce_field( $nonce_action, 'uupd_child_nonce', true, false );
    if ( in_array( $operation, array( 'create', 'update' ), true ) ) {
        $limit = $child_id ? uupd_child_activation_limit( $child_id ) : 1;
        $note = $child_id ? get_post_meta( $child_id, 'uupd_child_note', true ) : '';
        $html .= '<div class="uupd-key-form__fields"><label>' . esc_html__( 'Activation cap', 'uupd-server' ) . '<input type="number" name="child_limit" min="1" value="' . esc_attr( $limit ) . '" required></label>';
        $html .= '<label>' . esc_html__( 'Purpose or note', 'uupd-server' ) . '<textarea name="child_note" maxlength="500" rows="2" placeholder="' . esc_attr__( 'For example, client staging site', 'uupd-server' ) . '">' . esc_textarea( $note ) . '</textarea></label></div><p class="uupd-key-hint">' . esc_html__( 'The cap can use only activations available under the master licence.', 'uupd-server' ) . '</p>';
    }
    $destructive = in_array( $operation, array( 'revoke', 'delete', 'deactivate' ), true );
    $confirmation = 'delete' === $operation ? __( 'Delete this child key permanently?', 'uupd-server' ) : ( 'deactivate' === $operation ? __( 'Disconnect this site from the child key?', 'uupd-server' ) : __( 'Revoke this child key and remove its activations?', 'uupd-server' ) );
    $html .= '<button class="uupd-key-button ' . ( $destructive ? 'is-danger' : 'is-primary' ) . '" type="submit"' . ( $destructive ? ' data-uupd-confirm="' . esc_attr( $confirmation ) . '"' : '' ) . '>' . esc_html( $label ) . '</button></form>';
    return $html;
}

/** Admin controls live inside WordPress's post editor form, so submit a separate form. */
function uupd_child_admin_button( $master_id, $child_id, $operation, $label, $domain = '' ) {
    $nonce_action = 'uupd_manage_child_' . $master_id . '_' . $child_id . '_' . $operation;
    $url = add_query_arg( array(
        'action' => 'uupd_manage_child', 'master_id' => $master_id, 'child_id' => $child_id,
        'operation' => $operation, 'domain' => $domain, 'uupd_child_nonce' => wp_create_nonce( $nonce_action ),
    ), admin_url( 'admin-post.php' ) );
    return '<button type="button" class="button ' . ( in_array( $operation, array( 'revoke', 'delete', 'deactivate' ), true ) ? 'is-danger' : '' ) . '" data-uupd-child-url="' . esc_url( $url ) . '" data-uupd-child-operation="' . esc_attr( $operation ) . '" onclick="uupdSubmitChildAction(this)">' . esc_html( $label ) . '</button> ';
}

function uupd_child_admin_controls( $master_id ) {
    $master_limit = uupd_license_master_limit( $master_id );
    $children = uupd_license_child_ids( $master_id );
    $max_children = uupd_license_max_children( $master_id );
    $html = '<div class="uupd-child-summary"><strong>' . esc_html( uupd_child_count_label( $master_id ) ) . '</strong><span>' . esc_html__( 'child key slots used', 'uupd-server' ) . '</span><strong>' . esc_html( uupd_license_family_usage( $master_id ) . ' / ' . ( $master_limit ?: __( 'Unlimited', 'uupd-server' ) ) ) . '</strong><span>' . esc_html__( 'shared activations used', 'uupd-server' ) . '</span></div>';
    if ( uupd_license_is_usable( $master_id ) && ! uupd_license_child_limit_reached( $master_id ) ) {
        $html .= '<div class="uupd-child-editor is-create"><div class="uupd-child-editor-header"><strong>' . esc_html__( 'New child key', 'uupd-server' ) . '</strong></div><div class="uupd-child-fields"><label>' . esc_html__( 'Activation cap', 'uupd-server' ) . '<input type="number" name="child_limit" min="1" value="1"></label>'
            . '<label>' . esc_html__( 'What is this key for?', 'uupd-server' ) . '<textarea name="child_note" maxlength="500" rows="2" placeholder="e.g. Client staging site"></textarea></label></div><div class="uupd-child-actions">'
            . uupd_child_admin_button( $master_id, 0, 'create', __( 'Create child key', 'uupd-server' ) ) . '</div></div>';
    } elseif ( uupd_license_feature_enabled( $master_id, 'children' ) && uupd_license_child_limit_reached( $master_id ) ) {
        $html .= '<p class="description">' . esc_html( $max_children ? __( 'The child key limit has been reached. Delete a child key to free a slot, or raise the limit above and save this licence.', 'uupd-server' ) : __( 'Child key creation is disabled by the limit of 0. Raise the limit above and save this licence.', 'uupd-server' ) ) . '</p>';
    }
    if ( $children ) {
        $html .= '<div class="uupd-child-browser" data-uupd-child-browser><div class="uupd-child-browser__tools"><label>' . esc_html__( 'Search keys, notes or sites', 'uupd-server' ) . '<input type="search" data-uupd-child-search placeholder="' . esc_attr__( 'Key, note or site', 'uupd-server' ) . '"></label><label>' . esc_html__( 'Status', 'uupd-server' ) . '<select data-uupd-child-status><option value="all">' . esc_html__( 'All statuses', 'uupd-server' ) . '</option><option value="active">' . esc_html__( 'Active', 'uupd-server' ) . '</option><option value="inactive">' . esc_html__( 'Inactive', 'uupd-server' ) . '</option><option value="revoked">' . esc_html__( 'Revoked', 'uupd-server' ) . '</option></select></label><label>' . esc_html__( 'Site connection', 'uupd-server' ) . '<select data-uupd-child-sites><option value="all">' . esc_html__( 'All children', 'uupd-server' ) . '</option><option value="connected">' . esc_html__( 'Connected sites', 'uupd-server' ) . '</option><option value="unconnected">' . esc_html__( 'No connected sites', 'uupd-server' ) . '</option></select></label></div><p class="uupd-child-browser__count" data-uupd-child-count aria-live="polite"></p><div class="uupd-child-browser__list">';
    }
    foreach ( $children as $child_id ) {
        $domains = get_post_meta( $child_id, 'used_domains', true );
        $used = is_array( $domains ) ? count( $domains ) : 0;
        $note = get_post_meta( $child_id, 'uupd_child_note', true );
        $status = get_post_meta( $child_id, 'status', true );
        $filter_status = 'revoked' === $status ? 'revoked' : ( uupd_license_is_usable( $child_id ) ? 'active' : 'inactive' );
        $search_text = implode( ' ', array_merge( array( $child_id, get_post_meta( $child_id, 'license_key', true ), $note ), is_array( $domains ) ? $domains : array() ) );
        $html .= '<details class="uupd-child-editor" data-uupd-child-row data-uupd-status="' . esc_attr( $filter_status ) . '" data-uupd-connected="' . ( $used ? '1' : '0' ) . '" data-uupd-search="' . esc_attr( strtolower( $search_text ) ) . '"><summary class="uupd-child-editor-header"><span class="uupd-child-editor-header__identity"><strong>' . esc_html( $note ?: sprintf( __( 'Child key #%d', 'uupd-server' ), $child_id ) ) . '</strong><code>' . esc_html( get_post_meta( $child_id, 'license_key', true ) ) . '</code></span><small>'
            . esc_html( $used . ' / ' . uupd_child_activation_limit( $child_id ) ) . ' ' . esc_html__( 'activations', 'uupd-server' ) . ' · '
            . esc_html( ucfirst( $filter_status ) ) . '</small></summary><div class="uupd-child-editor__body"><p><a href="' . esc_url( get_edit_post_link( $child_id ) ) . '">' . esc_html__( 'Open child licence', 'uupd-server' ) . ' #' . esc_html( $child_id ) . '</a></p>';
        if ( 'active' === get_post_meta( $child_id, 'status', true ) ) {
            $html .= '<div class="uupd-child-fields"><label>' . esc_html__( 'Activation cap', 'uupd-server' ) . '<input type="number" name="child_limit" min="1" value="' . esc_attr( uupd_child_activation_limit( $child_id ) ) . '"></label>'
                . '<label>' . esc_html__( 'What is this key for?', 'uupd-server' ) . '<textarea name="child_note" maxlength="500" rows="2">' . esc_textarea( get_post_meta( $child_id, 'uupd_child_note', true ) ) . '</textarea></label></div><div class="uupd-child-actions">'
                . uupd_child_admin_button( $master_id, $child_id, 'update', __( 'Save child details', 'uupd-server' ) )
                . uupd_child_admin_button( $master_id, $child_id, 'revoke', __( 'Revoke child', 'uupd-server' ) );
        } else {
            $html .= '<p>' . esc_html( get_post_meta( $child_id, 'uupd_child_note', true ) ) . '</p>';
            $html .= '<div class="uupd-child-actions">';
            if ( 'revoked' !== get_post_meta( $child_id, 'status', true ) ) { $html .= uupd_child_admin_button( $master_id, $child_id, 'revoke', __( 'Revoke child', 'uupd-server' ) ); }
        }
        $html .= uupd_child_admin_button( $master_id, $child_id, 'delete', __( 'Delete child', 'uupd-server' ) ) . '</div>';
        if ( is_array( $domains ) && $domains ) {
            $html .= '<div class="uupd-child-connected"><strong>' . esc_html__( 'Connected sites', 'uupd-server' ) . '</strong><ul>';
            foreach ( $domains as $domain ) {
                $html .= '<li><code>' . esc_html( $domain ) . '</code>' . uupd_child_admin_button( $master_id, $child_id, 'deactivate', __( 'Disconnect', 'uupd-server' ), $domain ) . '</li>';
            }
            $html .= '</ul></div>';
        }
        $html .= '</div></details>';
    }
    if ( $children ) { $html .= '</div><p class="uupd-child-browser__empty" data-uupd-child-empty hidden>' . esc_html__( 'No child keys match these filters.', 'uupd-server' ) . '</p></div>'; }
    $html .= '<script>function uupdSubmitChildAction(button){var op=button.dataset.uupdChildOperation;if((op==="revoke"||op==="delete"||op==="deactivate")&&!confirm("Continue with this child key action?")){return;}var form=document.createElement("form");form.method="post";form.action=button.dataset.uupdChildUrl;if(op==="create"||op==="update"){button.closest(".uupd-child-editor").querySelectorAll("[name=child_limit],[name=child_note]").forEach(function(input){var field=document.createElement("input");field.type="hidden";field.name=input.name;field.value=input.value;form.appendChild(field);});}document.body.appendChild(form);form.submit();}</script>';
    return $html;
}

function uupd_license_key_action_form( $id, $operation, $label ) {
    return '<form class="uupd-key-rotate-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'
        . '<input type="hidden" name="action" value="uupd_manage_key">'
        . '<input type="hidden" name="license_id" value="' . esc_attr( $id ) . '">'
        . '<input type="hidden" name="operation" value="' . esc_attr( $operation ) . '">'
        . wp_nonce_field( 'uupd_manage_key_' . $id . '_' . $operation, 'uupd_key_nonce', true, false )
        . '<button class="uupd-key-button" type="submit" data-uupd-confirm="' . esc_attr__( 'Rotate this key? The current key will stop working immediately.', 'uupd-server' ) . '">' . esc_html( $label ) . '</button></form>';
}

function uupd_license_key_admin_button( $id, $operation, $label ) {
    $url = add_query_arg( 'uupd_key_nonce', wp_create_nonce( 'uupd_manage_key_' . $id . '_' . $operation ), admin_url( 'admin-post.php?action=uupd_manage_key&license_id=' . absint( $id ) . '&operation=' . rawurlencode( $operation ) ) );
    return '<button type="button" data-uupd-key-url="' . esc_url( $url ) . '" onclick="if(!confirm(\'' . esc_js( __( 'Rotate this key? The current key will stop working immediately.', 'uupd-server' ) ) . '\'))return;var f=document.createElement(\'form\');f.method=\'post\';f.action=this.dataset.uupdKeyUrl;document.body.appendChild(f);f.submit();" class="button">' . esc_html( $label ) . '</button> ';
}

function uupd_handle_manage_key() {
    $id = isset( $_REQUEST['license_id'] ) ? absint( $_REQUEST['license_id'] ) : 0;
    $operation = isset( $_REQUEST['operation'] ) ? sanitize_key( wp_unslash( $_REQUEST['operation'] ) ) : '';
    if ( ! $id || ! in_array( $operation, array( 'rotate', 'child' ), true ) ) { wp_die( esc_html__( 'Invalid request.', 'uupd-server' ), '', array( 'response' => 400 ) ); }
    $nonce = isset( $_REQUEST['uupd_key_nonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['uupd_key_nonce'] ) ) : '';
    if ( ! wp_verify_nonce( $nonce, 'uupd_manage_key_' . $id . '_' . $operation ) ) { wp_die( esc_html__( 'Security check failed.', 'uupd-server' ), '', array( 'response' => 403 ) ); }
    if ( ! current_user_can( 'edit_post', $id ) && get_current_user_id() !== absint( get_post_meta( $id, 'user_id', true ) ) ) {
        wp_die( esc_html__( 'Permission denied.', 'uupd-server' ), '', array( 'response' => 403 ) );
    }
    $result = 'rotate' === $operation ? uupd_rotate_license( $id, current_user_can( 'edit_post', $id ) ) : uupd_create_child_license( $id, 1, '', current_user_can( 'edit_post', $id ) );
    $redirect = wp_get_referer() ?: home_url( '/' );
    wp_safe_redirect( add_query_arg( array( 'uupd_key_result' => is_wp_error( $result ) ? $result->get_error_code() : ( 'rotate' === $operation ? 'rotated' : 'child_created' ), 'uupd_key_license' => $id ), $redirect ) . '#uupd-key-manager-' . $id );
    exit;
}
add_action( 'admin_post_uupd_manage_key', 'uupd_handle_manage_key' );

function uupd_handle_manage_child() {
    $master_id = isset( $_REQUEST['master_id'] ) ? absint( $_REQUEST['master_id'] ) : 0;
    $child_id = isset( $_REQUEST['child_id'] ) ? absint( $_REQUEST['child_id'] ) : 0;
    $operation = isset( $_REQUEST['operation'] ) ? sanitize_key( wp_unslash( $_REQUEST['operation'] ) ) : '';
    if ( ! $master_id || ! in_array( $operation, array( 'create', 'update', 'revoke', 'delete', 'deactivate' ), true ) || ( 'create' !== $operation && ! $child_id ) ) {
        wp_die( esc_html__( 'Invalid request.', 'uupd-server' ), '', array( 'response' => 400 ) );
    }
    $nonce = isset( $_REQUEST['uupd_child_nonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['uupd_child_nonce'] ) ) : '';
    if ( ! wp_verify_nonce( $nonce, 'uupd_manage_child_' . $master_id . '_' . $child_id . '_' . $operation ) ) {
        wp_die( esc_html__( 'Security check failed.', 'uupd-server' ), '', array( 'response' => 403 ) );
    }
    if ( 'uupd_license' !== get_post_type( $master_id ) || get_post_meta( $master_id, 'uupd_parent_license_id', true ) || ( ! current_user_can( 'edit_post', $master_id ) && get_current_user_id() !== absint( get_post_meta( $master_id, 'user_id', true ) ) ) ) {
        wp_die( esc_html__( 'Permission denied.', 'uupd-server' ), '', array( 'response' => 403 ) );
    }
    if ( $child_id && ( 'uupd_license' !== get_post_type( $child_id ) || $master_id !== absint( get_post_meta( $child_id, 'uupd_parent_license_id', true ) ) ) ) {
        wp_die( esc_html__( 'Child key does not belong to this licence.', 'uupd-server' ), '', array( 'response' => 403 ) );
    }
    $limit = isset( $_POST['child_limit'] ) ? absint( $_POST['child_limit'] ) : 0;
    $note = isset( $_POST['child_note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['child_note'] ) ) : '';
    switch ( $operation ) {
        case 'create': $result = uupd_create_child_license( $master_id, $limit, $note, current_user_can( 'edit_post', $master_id ) ); break;
        case 'update': $result = uupd_update_child_license( $child_id, $limit, $note ); break;
        case 'revoke': $result = uupd_revoke_child_license( $child_id ); break;
        case 'deactivate':
            $domain = isset( $_REQUEST['domain'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['domain'] ) ) : '';
            $domains = get_post_meta( $child_id, 'used_domains', true );
            $result = $domain && is_array( $domains ) && in_array( $domain, $domains, true )
                ? uupd_deactivate_license_for_domain( get_post_meta( $child_id, 'license_key', true ), $domain )
                : new WP_Error( 'uupd_invalid_domain', __( 'Site connection not found.', 'uupd-server' ) );
            break;
        default: $result = uupd_delete_child_license( $child_id );
    }
    $redirect = wp_get_referer() ?: home_url( '/' );
    $success_codes = array( 'create' => 'child_created', 'update' => 'child_updated', 'revoke' => 'child_revoked', 'delete' => 'child_deleted', 'deactivate' => 'child_deactivated' );
    wp_safe_redirect( add_query_arg( array( 'uupd_key_result' => is_wp_error( $result ) ? $result->get_error_code() : $success_codes[ $operation ], 'uupd_key_license' => $master_id ), $redirect ) . '#uupd-key-manager-' . $master_id );
    exit;
}
add_action( 'admin_post_uupd_manage_child', 'uupd_handle_manage_child' );

add_action( 'init', function () {
    if ( ! function_exists( 'register_block_type' ) ) { return; }
    wp_register_script( 'uupd-license-keys-block', false, array( 'wp-blocks', 'wp-element', 'wp-server-side-render' ), '1.0', true );
    wp_add_inline_script( 'uupd-license-keys-block', "wp.blocks.registerBlockType('uupd/license-keys',{title:'UUPD Licence Keys',icon:'admin-network',category:'widgets',edit:function(){return wp.element.createElement(wp.serverSideRender,{block:'uupd/license-keys'});},save:function(){return null;}});" );
    register_block_type( 'uupd/license-keys', array( 'editor_script' => 'uupd-license-keys-block', 'render_callback' => 'uupd_license_keys_shortcode' ) );
} );
